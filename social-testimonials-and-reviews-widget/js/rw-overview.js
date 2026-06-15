/**
 * Overview dashboard loader.
 *
 * Fires read-only report endpoints, renders the KPI cards and the
 * "reviews waiting for approval" action banner. Exposes
 * window.rwLoadOverview(subAccount) so rw-admin.js can re-fire all loaders
 * when the sub-account picker changes.
 *
 * A monotonically-increasing `loadGen` token gates each request: any
 * response that arrives after a newer request was kicked off is dropped
 * on the floor so a slow stale response can't repaint over fresh data.
 */
(function ($) {
	'use strict';

	var loadGen = 0;
	var lastUpdatedAt = null;
	var timeAgoTimer  = null;
	var RANGE_KEY = 'rw_dashboard_range';

	// Maps a UI range key to the months value the backend's time-bucketed
	// endpoints (/reports/time/{months}) accept. Mirrors the production
	// dashboard's rangeToMonths so the same data appears for the same pill.
	function rangeToMonths(key) {
		switch (key) {
			case '30d': return 1;
			case '90d': return 3;
			case '1y':  return 12;
			case '2y':  return 24;
			default:    return 3;
		}
	}

	function currentRange() {
		var stored = null;
		try { stored = window.localStorage.getItem(RANGE_KEY); } catch (e) {}
		if (['30d', '90d', '1y', '2y'].indexOf(stored) === -1) return '90d';
		return stored;
	}

	function persistRange(key) {
		try { window.localStorage.setItem(RANGE_KEY, key); } catch (e) {}
	}

	function rangeLabelText(key) {
		switch (key) {
			case '30d': return window.rwT('range_last_30d', 'last 30 days');
			case '90d': return window.rwT('range_last_90d', 'last 90 days');
			case '1y':  return window.rwT('range_last_1y',  'last year');
			case '2y':  return window.rwT('range_last_2y',  'last 2 years');
			default:    return window.rwT('range_last_90d', 'last 90 days');
		}
	}
	// Persistent chart instances. Destroyed before each re-render so we
	// don't pile up DOM nodes or leak listeners on sub-account switches.
	var overTimeChart       = null;
	var avgRatingChart      = null;
	var sparklineRating     = null;
	var sparklineTotal      = null;
	var sparklineNew        = null;

	// Buffers for the total-reviews running sparkline. /reports/all and
	// /reports/time/12 land independently; we render the sparkline once
	// both legs are present (lifetime total + per-month Total bucket).
	var lifetimeTotal       = null;
	var lastTotalSeries     = null;
	var lastTotalLabels     = null;

	// Exact palette the production Repuso dashboard uses for its
	// reviews-over-time stacked bar chart, copied from
	// repuso-app/js/app/controllers/dashboard.js. Keeping the order
	// identical means brand-colored platforms get the same hue here and
	// there, so the two surfaces feel like one product.
	var DASHBOARD_PALETTE = [
		'#5b6cff', '#8b96ff', '#46c8b6', '#f59e0b', '#dc2626',
		'#a78bfa', '#64748b', '#10b981'
	];

	// All AJAX goes through the shared window.rwQueue defined in rw-admin.js.
	// That cap prevents the dashboard's parallel loaders from starving the
	// PHP-FPM pools (each request holds two workers, one on the WP side and
	// one on api.repuso.*).
	function enqueueAjax(opts) {
		return window.rwQueue.enqueue(opts);
	}

	function hookPost(path, body) {
		return enqueueAjax({
			url:      ajax_var.url,
			type:     'POST',
			timeout:  30000,
			data: {
				action:  'hook',
				nonce:   ajax_var.nonce,
				path:    path,
				method:  'POST',
				body:    body || {},
				headers: { 'Authorization': 'Yes', 'Content-Type': 'application/json' }
			}
		});
	}

	// Local shortcuts to the shared response-cache helpers exposed
	// by rw-admin.js (which is always loaded first). Keeps the
	// existing per-loader call sites readable while letting the
	// helpers live alongside the other section loaders in rw-admin.js.
	function cacheKey(prefix, subAccount, range) {
		return window.rwCache.cacheKey(prefix, subAccount, range);
	}
	function staleWhileRevalidate(key, probe, render, opts) {
		return window.rwCache.staleWhileRevalidate(key, probe, render, opts);
	}

	function hookGet(path) {
		// Routed through enqueueAjax so this counts against the global
		// MAX_CONCURRENT cap; otherwise rw-overview's loaders can starve
		// the api.repuso.* PHP-FPM pool (see enqueueAjax comment above).
		// dataType dropped so jQuery auto-parses from Content-Type
		// (forcing JSON had been routing non-JSON error bodies into
		// .fail and hiding the real error message).
		return enqueueAjax({
			url:      ajax_var.url,
			type:     'POST',
			timeout:  30000,
			data: {
				action:  'hook',
				nonce:   ajax_var.nonce,
				path:    path,
				method:  'GET',
				body:    {},
				headers: { 'Authorization': 'Yes' }
			}
		}).fail(function (xhr, textStatus) {
			// Aborts (textStatus === 'abort') aren't real failures; they
			// happen on every sub-account / range switch when we cancel
			// the previous batch. Surfacing them as user-visible errors
			// would make every switch look broken.
			if (textStatus === 'abort') return;
			var body = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 200) : '';
			var msg  = 'API call failed: ' + path + ' (HTTP ' + (xhr && xhr.status) + ')' + (body ? ' body: ' + body : '');
			var $err = $('#rw-error');
			if ($err.find('p').text().length < 1000) {
				$err.find('p').append((($err.find('p').text() ? '<br>' : '') + msg));
				$err.show();
			}
		});
	}

	// Reset every section to its loading state. Called on initial load and
	// whenever rwLoadOverview is invoked with a fresh sub-account.
	function showLoading() {
		$('[data-kpi-value]').html('<span class="rw-kpi__skeleton"></span>');
		$('[data-kpi-meta]').empty();
		$('#rw-pending-banner').hide();

		// Sparklines: blow away the previous Apex instances and clear the
		// host divs so the new render starts from a clean slate.
		[sparklineRating, sparklineTotal, sparklineNew].forEach(function (c) {
			if (c) { try { c.destroy(); } catch (e) {} }
		});
		sparklineRating = sparklineTotal = sparklineNew = null;
		$('.rw-kpi__spark').empty();

		// Reset the cross-request buffers for the running total sparkline.
		lifetimeTotal = null;
		lastTotalSeries = null;
		lastTotalLabels = null;

		// Main chart: spinner shown, canvas + empty state hidden until the
		// reports/time/12 response decides which is appropriate.
		$('[data-chart-loading]').show();
		$('[data-chart-empty]').hide();
		$('#rw-chart-over-time').hide();

		// Average rating trend: same loading silhouette as the main chart.
		// Tear down any prior instance before clearing the host so the
		// new render starts from a clean slate on sub-account / range
		// switches (otherwise Apex's internal state cache leaks across).
		if (avgRatingChart) {
			try { avgRatingChart.destroy(); } catch (e) { /* ignore */ }
			avgRatingChart = null;
		}
		$('[data-rating-trend-loading]').show();
		$('[data-rating-trend-empty]').hide();
		$('#rw-chart-avg-rating').hide();

		// AI insights + Rating distribution cards back to loading.
		$('[data-ai-loading]').show();
		$('[data-ai-placeholder]').hide().empty();
		$('[data-ai-data]').hide();
		$('[data-ai-sentiment]').hide();
		$('[data-ai-strengths-wrap]').hide();
		$('[data-ai-improvements-wrap]').hide();
		$('[data-dist-loading]').show();
		$('[data-dist-empty]').hide();
		$('[data-dist-rows]').hide().empty();

		// Platform breakdown, Latest reviews, Setup card reset.
		$('[data-platforms-loading]').show();
		$('[data-platforms-empty]').hide();
		$('[data-platforms-table]').hide();
		$('[data-platforms-rows]').empty();
		$('[data-platforms-toggle]').hide().empty();
		$('[data-latest-loading]').show();
		$('[data-latest-empty]').hide();
		$('[data-latest-list]').empty();
		$('[data-setup-card]').hide();
	}

	// ---- "Reviews waiting for approval" action banner ----
	function loadPending(subAccount, gen, done) {
		staleWhileRevalidate(cacheKey('pending', subAccount, ''), function () {
			return hookGet('posts/inbox?limit=1&website=' + encodeURIComponent(subAccount));
		}, function (response, fromCache) {
			if (gen !== loadGen) return;
			var count = (response && typeof response.count === 'number') ? response.count : 0;
			var $banner = $('#rw-pending-banner');
			if (count > 0) {
				$banner.find('[data-pending-count]').text(count);
				$banner.show();
			} else {
				$banner.hide();
			}
			// Only sync the WP option from the real probe response;
			// the cached render is purely for paint speed, persisting
			// it again would be a no-op write.
			if (!fromCache) {
				$.post(ajax_var.url, {
					action: 'rw_store_info',
					pending: count,
					nonce: ajax_var.nonce
				});
			}
		}, { force: !!refreshForced })
			.always(function () { if (done) done(); });
	}

	// ---- KPI cards: average rating, total reviews, new this month ----
	//
	// API response shapes (confirmed against classes/reports.php):
	//
	//   GET /reports/all?website_id=…
	//     { success, types: { <type>: { official_num_reviews, rating_value, ... } },
	//       totals: { official_num_reviews, rating_value } }
	//
	//   GET /reports/time/{range}?website_id=…
	//     { success, labels: ['Jan 25', 'Feb 25', …],
	//       data: { <typeLabel>: [n_jan, n_feb, …, n_thisMonth] },
	//       distribution: { … }, avg_rating: { … } }
	//
	// reports/* uses snake_case `website_id`; posts/channels use plain
	// `website`. Inconsistent API surface, mirrored here.
	function loadKpis(subAccount, gen, done) {
		// loadKpis fires two requests in parallel. We only call `done` once
		// both have settled so the global refreshing indicator stays on
		// until the slowest one resolves.
		var pending = 2;
		var finish = function () {
			pending--;
			if (pending <= 0 && done) done();
		};

		var months    = rangeToMonths(currentRange());
		var forceCold = !!refreshForced;
		var rangeKey  = currentRange();

		// reports/all -> rating KPI, total KPI, platforms breakdown.
		staleWhileRevalidate(cacheKey('reports_all', subAccount, ''), function () {
			return hookGet('reports/all?website_id=' + encodeURIComponent(subAccount));
		}, function (response, fromCache) {
			if (gen !== loadGen) return;

			var totals = (response && response.totals) ? response.totals : {};
			var types  = (response && response.types)  ? response.types  : {};

			var totalReviews = parseInt(totals.official_num_reviews || 0, 10);
			var avgRating    = parseFloat(totals.rating_value || 0);
			var platformCount = Object.keys(types).length;

			renderRatingKpi(avgRating, totalReviews);
			renderTotalKpi(totalReviews, platformCount);

			lifetimeTotal = totalReviews;
			maybeRenderTotalSparkline(gen);

			renderPlatformBreakdown(types);
		}, { force: forceCold })
			.fail(function () {
				if (gen !== loadGen) return;
				renderKpiError('rating');
				renderKpiError('total');
			})
			.always(finish);

		staleWhileRevalidate(cacheKey('reports_time', subAccount, rangeKey), function () {
			return hookGet('reports/time/' + months + '?website_id=' + encodeURIComponent(subAccount));
		}, function (response, fromCache) {
				if (gen !== loadGen) return;

				var labels = (response && response.labels) ? response.labels : [];
				var data   = (response && response.data)   ? response.data   : {};

				if (!labels.length) {
					renderNewKpi(0, null);
					renderOverTimeChart([], []);
					return;
				}

				// The dashboard's data.Total bucket holds the per-month
				// grand total; using it directly avoids double-counting
				// when individual platforms are summed alongside.
				var totalSeries = (data.Total || data.total || []).map(function (v) {
					return parseInt(v, 10) || 0;
				});

				// "New" KPI = sum over the entire selected range (matches the
				// web dashboard's `kpi.newReviews`). Delta = back-half of the
				// range vs front-half, so each pill produces a sensible
				// trend signal regardless of granularity.
				var newSum = totalSeries.reduce(function (a, b) { return a + b; }, 0);
				var newDelta = null;
				if (totalSeries.length >= 2) {
					var mid  = Math.floor(totalSeries.length / 2);
					var prev = totalSeries.slice(0, mid).reduce(function (a, b) { return a + b; }, 0);
					var curr = totalSeries.slice(mid).reduce(function (a, b) { return a + b; }, 0);
					newDelta = curr - prev;
				}
				renderNewKpi(newSum, newDelta);

				// Per-platform stacked series for the chart (excluding the
				// Total bucket, which would double-count when stacked).
				var series = [];
				Object.keys(data).forEach(function (key) {
					if (key === 'Total' || key === 'total') return;
					series.push({ name: key, data: (data[key] || []).map(function (v) { return parseInt(v, 10) || 0; }) });
				});
				renderOverTimeChart(labels, series);

				// Sparklines on the KPI cards.
				var deltaClassNew = newDelta == null ? '' : (newDelta > 0 ? 'up' : (newDelta < 0 ? 'down' : 'flat'));
				sparklineNew = renderSparkline(
					'rw-sparkline-new',
					totalSeries.map(function (v) { return parseInt(v, 10) || 0; }),
					labels,
					gen,
					sparklineNew,
					deltaClassNew
				);

				var avgSeries = (response && response.avg_rating) ? response.avg_rating : [];
				var avgDeltaClass = computeAvgDeltaClass(avgSeries);
				sparklineRating = renderSparkline(
					'rw-sparkline-rating',
					avgSeries,
					labels,
					gen,
					sparklineRating,
					avgDeltaClass
				);

				// Full-size Average rating trend chart - reads from the
				// same avg_rating series the KPI sparkline uses; the
				// large chart sits in its own card under "Reviews over time".
				renderAvgRatingTrend(labels, avgSeries);

				// Cache the time-series leg so renderTotalSparkline can fire
				// once /reports/all also lands (order-independent).
				lastTotalSeries = totalSeries;
				lastTotalLabels = labels;
				maybeRenderTotalSparkline(gen);

				// Rating distribution piggybacks on this same response.
				renderRatingDistribution(response && response.distribution);
			}, { force: forceCold })
			.fail(function () {
				if (gen !== loadGen) return;
				renderKpiError('new');
				renderRatingTrendError();
				renderChartError();
			})
			.always(finish);
	}

	// ---- Reviews over time ----
	// Mirrors the production Repuso dashboard's stacked-bar chart, using
	// the exact ApexCharts config from repuso-app/.../dashboard.js so the
	// WP plugin and the web dashboard read as the same product.
	function renderOverTimeChart(labels, series) {
		// Always clear the spinner first; whatever the outcome (empty data,
		// missing library, full render), we never want the chart card
		// stuck in its loading state.
		$('[data-chart-loading]').hide();

		// Tear down any previous instance so we don't pile up DOM nodes
		// or leak listeners on sub-account switches.
		if (overTimeChart) {
			try { overTimeChart.destroy(); } catch (e) { /* ignore */ }
			overTimeChart = null;
		}

		var el = document.getElementById('rw-chart-over-time');
		if (!el) return;
		if (typeof window.ApexCharts === 'undefined') {
			$('[data-chart-empty]').text(window.rwT('chart_library_failed', 'Chart library failed to load.')).show();
			return;
		}

		// Empty-state: no labels, no series, or every value is zero.
		var hasAny = labels.length > 0 && series.length > 0 && series.some(function (s) {
			return (s.data || []).some(function (v) { return parseFloat(v) > 0; });
		});
		if (!hasAny) {
			$(el).hide();
			$('[data-chart-empty]').show();
			return;
		}

		$('[data-chart-empty]').hide();
		$(el).show();
		el.innerHTML = '';

		var options = {
			chart: {
				type: 'bar',
				height: 280,
				stacked: true,
				toolbar: { show: false },
				animations: { speed: 350 },
				fontFamily: 'Arial, sans-serif'
			},
			plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
			dataLabels: { enabled: false },
			xaxis: { categories: labels, labels: { style: { fontSize: '11px' } } },
			yaxis: {
				min: 0,
				forceNiceScale: true,
				decimalsInFloat: 0,
				labels: {
					formatter: function (v) { return Math.round(v); },
					style: { fontSize: '11px' }
				}
			},
			legend: { position: 'bottom', fontSize: '12px' },
			grid: { borderColor: '#f1f3f5', strokeDashArray: 4 },
			series: series,
			colors: DASHBOARD_PALETTE
		};

		overTimeChart = new window.ApexCharts(el, options);
		overTimeChart.render();
	}

	function renderChartError() {
		$('[data-chart-loading]').hide();
		$('#rw-chart-over-time').hide();
		$('[data-chart-empty]').text(window.rwT('chart_load_failed', "Couldn't load chart data.")).show();
	}

	// ---- Average rating trend ----
	// Direct port of repuso-app/.../dashboard.js#renderAvgRatingTrend.
	// Same monthly buckets as the Reviews-over-time chart (from
	// /reports/time/{months}.avg_rating) but rendered as a smooth-area
	// chart with y fixed to 0-5 so the visual conveys "rating level over
	// time" instead of relative change. Skips empty buckets (null in
	// the series) so months with no reviews don't drop the line to 0.
	function renderAvgRatingTrend(labels, values) {
		$('[data-rating-trend-loading]').hide();

		if (avgRatingChart) {
			try { avgRatingChart.destroy(); } catch (e) { /* ignore */ }
			avgRatingChart = null;
		}

		var el = document.getElementById('rw-chart-avg-rating');
		if (!el) return;
		if (typeof window.ApexCharts === 'undefined') {
			$('[data-rating-trend-empty]')
				.text(window.rwT('chart_library_failed', 'Chart library failed to load.'))
				.show();
			return;
		}

		// Convert to a numeric series, preserving null gaps. A bucket
		// with no reviews comes back as 0 (or falsy) - we map it to null
		// so Apex renders a gap rather than a misleading "rating = 0".
		var data = (values || []).map(function (v) { return v ? Number(v) : null; });
		var hasAny = data.some(function (v) { return v != null && isFinite(v) && v > 0; });
		if (!hasAny || !labels.length) {
			$(el).hide();
			$('[data-rating-trend-empty]')
				.text(window.rwT('rating_trend_empty', 'No rating data for the selected period.'))
				.show();
			return;
		}

		$('[data-rating-trend-empty]').hide();
		$(el).show();
		el.innerHTML = '';

		avgRatingChart = new window.ApexCharts(el, {
			chart:       { type: 'area', height: 280, toolbar: { show: false }, animations: { speed: 350 }, fontFamily: 'Arial, sans-serif' },
			stroke:      { curve: 'smooth', width: 3 },
			dataLabels:  { enabled: false },
			fill:        { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
			xaxis:       { categories: labels, labels: { style: { fontSize: '11px' } } },
			yaxis:       {
				min: 0,
				max: 5,
				labels: {
					formatter: function (v) { return v ? v.toFixed(1) : v; },
					style:     { fontSize: '11px' }
				}
			},
			grid:        { borderColor: '#f1f3f5', strokeDashArray: 4 },
			colors:      ['#5b6cff'],
			series:      [{ name: window.rwT('average_rating', 'Average rating'), data: data }]
		});
		avgRatingChart.render();
	}

	function renderRatingTrendError() {
		$('[data-rating-trend-loading]').hide();
		$('#rw-chart-avg-rating').hide();
		$('[data-rating-trend-empty]')
			.text(window.rwT('chart_load_failed', "Couldn't load chart data."))
			.show();
	}

	// ---- AI insights ----
	// POST /v1/widgets/ai/insights returns:
	//   { success, strengths: [...], improvements: [{text, severity}],
	//     sentiment: { positive, neutral, negative, total },
	//     belowThreshold, upgrade, refreshing, generatedAt, msg }
	// Mirrors the dashboard's loadAiInsights state machine: belowThreshold,
	// upgrade (plan_ai not on), HTTP error, and the data-state.
	function loadAiInsights(subAccount, gen, done) {
		// Defer the role + plan checks until account/info has landed.
		// Both aiPlanEnabled() and isAdmin() read from window.rwAccount,
		// and account/info is fetched in parallel with this probe at
		// boot - without the wait, an admin would be treated as a
		// regular User on the very first dashboard load and the entire
		// AI card would be hidden until a manual refresh.
		whenRolesResolved(function () {
			if (gen !== loadGen) return;
			loadAiInsightsInner(subAccount, gen, done);
		});
	}

	function loadAiInsightsInner(subAccount, gen, done) {
		// Short-circuit when AI isn't available for this sub-account -
		// either the plan doesn't support it, or it's off for this
		// specific website. Don't fire the probe; show the appropriate
		// upgrade / settings prompt instead. Without this the user sees
		// a populated AI insights card on an account where AI suggest
		// reply also fails (the API's two-tier check trips suggest reply
		// at the per-website level even when plan_ai is on).
		if (!aiPlanEnabled()) {
			// Regular Users (not admin, not editor) on a plan without
			// AI shouldn't even see the upgrade prompt - they can't
			// act on it. Mirror the web's `(aiEnabled() || isAdmin())`
			// gate by hiding the whole card. Admins still see the
			// upgrade CTA because they're the audience for it.
			if (!isAdmin()) {
				$('[data-ai-card]').hide();
				if (done) done();
				return;
			}
			$('[data-ai-loading]').hide();
			setAiPlaceholder(aiUpgradeHtml(aiUpgradeMessage()));
			if (done) done();
			return;
		}

		staleWhileRevalidate(cacheKey('ai_insights', subAccount, ''), function () {
			return hookPost('widgets/ai/insights', { website_id: parseInt(subAccount, 10) || 0, language: 'en' });
		}, function (response, fromCache) {
			if (gen !== loadGen) return;
			$('[data-ai-loading]').hide();

			if (!response || response.success === false) {
				if (response && response.upgrade) {
					setAiPlaceholder(aiUpgradeHtml(window.rwT('ai_not_supported', 'AI is not supported on your current plan.')));
				} else {
					setAiPlaceholder((response && response.msg) || window.rwT('ai_load_failed', "Couldn't load AI insights right now."));
				}
				$('[data-ai-data]').hide();
				return;
			}

			if (response.belowThreshold) {
				setAiPlaceholder(window.rwT('ai_below_threshold', 'Collect 5+ reviews with written feedback to unlock AI insights.'));
				$('[data-ai-data]').hide();
				return;
			}

			setAiPlaceholder(''); // clear stale message between renders
			$('[data-ai-placeholder]').hide();
			$('[data-ai-data]').show();
			renderSentiment(response.sentiment);
			renderAiBullets(response.strengths || [], response.improvements || []);
		}, { force: !!refreshForced })
			.fail(function () {
				if (gen !== loadGen) return;
				$('[data-ai-loading]').hide();
				setAiPlaceholder(window.rwT('ai_load_failed', "Couldn't load AI insights right now."));
			})
			.always(function () { if (done) done(); });
	}

	// True when AI is available for the current sub-account. Matches the
	// API's two-tier gate in Posts::aiSuggestReply: the account plan must
	// have `plan_ai == 1`, AND if the user is scoped to a sub-account
	// (website_id > 0) that website must also have its own `ai == 1`
	// flag. So a plan with AI enabled but a specific website's AI toggle
	// off still hides AI affordances on that sub-account.
	function aiPlanEnabled() {
		var acc = window.rwAccount;
		if (!acc) return false;
		var planOn = acc.plan_ai == 1 || acc.plan_ai === '1' || acc.plan_ai === true;
		if (!planOn) return false;

		var wid = parseInt(currentSubAccount(), 10) || 0;
		if (wid > 0) {
			var sub = (acc.websites || []).filter(function (w) { return Number(w.id) === wid; })[0];
			if (!sub) return false;
			return sub.ai == 1 || sub.ai === '1' || sub.ai === true;
		}
		return true;
	}

	// Role helpers - mirror the web dashboard's $rootScope.isAdmin /
	// isEditor / isAdminOrEditor. The API exposes:
	//   account.is_admin === true when role_id == 1 (Admin)
	//   account.role === 3                            (Editor)
	//   account.role === 2                            (User, regular)
	// Roles drive what setup CTAs and admin-only cards we render.
	// A regular User can browse data but can't connect channels,
	// create widgets, approve reviews, or change account settings -
	// so we suppress the matching prompts entirely.
	function rolesResolved() {
		// account/info is fetched in parallel with the dashboard probes;
		// callers can hit role helpers before window.rwAccount is set.
		// Treat that window as "unknown" and let the caller decide what
		// to do (typically: defer the gate decision until the account
		// info event fires).
		return !!window.rwAccount;
	}
	function isAdmin() {
		var acc = window.rwAccount;
		return !!(acc && acc.is_admin);
	}
	function isEditor() {
		var acc = window.rwAccount;
		if (!acc) return false;
		return acc.role == 3 || acc.role === '3';
	}
	function isAdminOrEditor() {
		return isAdmin() || isEditor();
	}

	// Defer a callback until window.rwAccount is populated by
	// rw-admin.js's loadAccount(). Fires immediately if account info
	// is already known; otherwise listens for the rw:account-loaded
	// event triggered after the account/info probe resolves. This is
	// the canonical way to gate role-sensitive UI without flashing
	// the wrong state during the parallel-probe boot window.
	function whenRolesResolved(cb) {
		if (rolesResolved()) { cb(); return; }
		$(document).one('rw:account-loaded', function () { cb(); });
	}

	function currentSubAccount() {
		// Best signal is the live sub-account picker (set by rw-admin.js);
		// fall back to the localized initial value otherwise.
		var live = $('#rw-subaccounts #accounts').val();
		if (live != null && live !== '') return live;
		return (typeof ajax_var !== 'undefined' && ajax_var.subAccount != null)
			? ajax_var.subAccount
			: 0;
	}

	// Picks the right copy for the upgrade panel. The plan-off case
	// nudges the user to upgrade; the per-website case nudges them to
	// flip the AI toggle in the sub-account's settings.
	function aiUpgradeMessage() {
		var acc = window.rwAccount;
		var notSupported = window.rwT('ai_not_supported', 'AI is not supported on your current plan.');
		if (!acc) return notSupported;
		var planOn = acc.plan_ai == 1 || acc.plan_ai === '1' || acc.plan_ai === true;
		if (!planOn) return notSupported;
		return window.rwT('ai_not_enabled_site', 'AI is not enabled for this sub-account.');
	}

	// Upgrade placeholder markup shared between the not-supported and
	// the upgrade response paths. Strict two-tier routing:
	//   - plan_ai off (regardless of sub-account) -> /account/plan,
	//     because upgrading the plan is the only way forward.
	//   - plan_ai on + per-website AI off -> /account/tab/subaccounts,
	//     where the user toggles the per-website AI flag on the
	//     sub-account row.
	function aiUpgradeHtml(message) {
		var acc = window.rwAccount;
		var planOn = !!(acc && (acc.plan_ai == 1 || acc.plan_ai === '1' || acc.plan_ai === true));
		var ctaLabel, ctaPath;
		if (!planOn) {
			ctaLabel = window.rwT('ai_upgrade_plan', 'Upgrade plan');
			ctaPath  = '/account/plan';
		} else {
			ctaLabel = window.rwT('ai_enable_site', 'Enable AI for this site');
			ctaPath  = '/account/tab/subaccounts';
		}
		return (
			'<div class="rw-ai__upgrade">' +
				'<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
				'<span class="rw-ai__upgrade-text">' + escAttr(message) + '</span>' +
				'<a class="rw-ai__upgrade-cta rw-open-dashboard" href="#" target="_blank" rel="noopener" data-rw-path="' + escAttr(ctaPath) + '">' +
					escAttr(ctaLabel) +
					' <span class="dashicons dashicons-external" aria-hidden="true"></span>' +
				'</a>' +
			'</div>'
		);
	}

	function setAiPlaceholder(html) {
		$('[data-ai-placeholder]').html(html).show();
	}

	// Delegates to the shared escape helper defined at the top of
	// rw-admin.js (always loaded before this file). Keeps the two
	// IIFEs from drifting on a security-sensitive helper.
	function escAttr(s) { return window.rwEscAttr(s); }

	function repusoAppUrl() {
		return (window.RepusoOnboard && RepusoOnboard.appUrl) ? RepusoOnboard.appUrl : 'https://repuso.com/app/';
	}

	// Direct port of the dashboard's computeSentimentDial geometry:
	//   viewBox 160x110, arc center (80,90), needle radius 55, full
	//   sweep π radians from left (0%) to right (100%).
	function renderSentiment(sent) {
		if (!sent || !sent.total) return;
		var score = (Number(sent.positive || 0) + 0.5 * Number(sent.neutral || 0)) / sent.total;
		score = Math.max(0, Math.min(1, score));
		var angle = Math.PI * (1 - score);
		var x = 80 + 55 * Math.cos(angle);
		var y = 90 - 55 * Math.sin(angle);
		var percent = Math.round(score * 100);

		$('[data-dial-needle]').attr('x2', x).attr('y2', y);
		$('[data-dial-percent]').text(percent + '%');
		$('[data-sent-positive]').text(sent.positive || 0);
		$('[data-sent-neutral]').text(sent.neutral  || 0);
		$('[data-sent-negative]').text(sent.negative || 0);
		$('[data-ai-sentiment]').show();
	}

	// Item markup mirrors the production dashboard:
	//   .rw-ai__item (white rounded card inside the colored gradient panel)
	//     > .rw-ai__icon (28x28 circular badge with tinted background)
	//     > .rw-ai__text
	function renderAiBullets(strengths, improvements) {
		if (strengths.length) {
			var $s = $('[data-ai-strengths]').empty();
			strengths.forEach(function (text) {
				$s.append(
					'<li class="rw-ai__item">' +
						'<span class="rw-ai__icon"><span class="dashicons dashicons-yes"></span></span>' +
						'<span class="rw-ai__text">' + escAttr(text) + '</span>' +
					'</li>'
				);
			});
			$('[data-ai-strengths-wrap]').show();
		}

		var $i = $('[data-ai-improvements]').empty();
		if (improvements.length) {
			improvements.forEach(function (item) {
				// AI insights API: improvements come back as either plain
				// strings or { text, severity } objects. Handle both.
				var text     = (typeof item === 'string') ? item : (item && item.text) || '';
				var severity = (typeof item === 'object' && item) ? item.severity : null;
				var iconCls  = severity === 'recurring' ? 'dashicons-warning' : 'dashicons-arrow-up-alt';
				var rowCls   = severity === 'recurring' ? ' rw-ai__item-recurring' : '';
				$i.append(
					'<li class="rw-ai__item' + rowCls + '">' +
						'<span class="rw-ai__icon"><span class="dashicons ' + iconCls + '"></span></span>' +
						'<span class="rw-ai__text">' + escAttr(text) + '</span>' +
					'</li>'
				);
			});
		} else {
			$i.append(
				'<li class="rw-ai__item rw-ai__all-clear">' +
					'<span class="rw-ai__icon"><span class="dashicons dashicons-smiley"></span></span>' +
					'<span class="rw-ai__text">' + escAttr(window.rwT('ai_no_improvements', 'No improvement themes detected. Customers are happy!')) + '</span>' +
				'</li>'
			);
		}
		$('[data-ai-improvements-wrap]').show();
	}

	// ---- Rating distribution (5★ → 1★ bars) ----
	function renderRatingDistribution(distribution) {
		var dist = (distribution && (distribution.Total || distribution.total)) || {};
		var counts = [1, 2, 3, 4, 5].map(function (s) { return Number(dist[s] || 0); });
		var totalCount = counts.reduce(function (a, b) { return a + b; }, 0);

		$('[data-dist-loading]').hide();

		if (totalCount === 0) {
			$('[data-dist-empty]').show();
			return;
		}

		var $rows = $('[data-dist-rows]').empty();
		[5, 4, 3, 2, 1].forEach(function (stars) {
			var count = Number(dist[stars] || 0);
			var pct   = totalCount > 0 ? (count / totalCount * 100) : 0;
			$rows.append(
				'<div class="rw-dist__row">' +
					'<span class="rw-dist__label">' + stars + ' <span class="dashicons dashicons-star-filled"></span></span>' +
					'<div class="rw-dist__bar"><div class="rw-dist__bar-fill" style="width:' + pct + '%"></div></div>' +
					'<span class="rw-dist__count">' + numberFmt(count) + '</span>' +
				'</div>'
			);
		});
		$rows.show();
	}

	// ---- KPI sparklines ----
	// Direct port of repuso-app/.../dashboard.js#renderSparkline so the WP
	// dashboard's KPI cards visually match the web dashboard's. Same Apex
	// area-with-sparkline-mode config, same colour rules.
	function renderSparkline(elementId, values, categories, gen, priorChart, deltaClass) {
		if (gen !== loadGen) return null;
		if (typeof window.ApexCharts === 'undefined') return null;
		var el = document.getElementById(elementId);
		if (!el) return null;

		values     = values || [];
		categories = categories || [];

		// Short ranges leave the sparkline with 1-2 points, which Apex
		// draws as a fat vertical bar. Pad to 3 points (repeat last) so
		// the line draws as a horizontal trend instead.
		if (values.length < 3) {
			var pad = values.length === 0 ? 0 : values[values.length - 1];
			while (values.length < 3) {
				values = [pad].concat(values);
				if (categories.length) categories = [''].concat(categories);
			}
		}

		// Destroy the prior Apex instance before clearing the DOM so its
		// internal series cache doesn't leak across renders.
		if (priorChart && typeof priorChart.destroy === 'function') {
			try { priorChart.destroy(); } catch (e) {}
		}
		el.innerHTML = '';

		// Flat-value series get a symmetric y-axis so a "0,0,0" line and a
		// "5,5,5" line both render at the vertical midpoint.
		var nums = values.filter(function (v) { return v != null && isFinite(v); }).map(Number);
		var yaxisCfg;
		if (nums.length > 0) {
			var minV = Math.min.apply(null, nums);
			var maxV = Math.max.apply(null, nums);
			if (minV === maxV) {
				var p = Math.max(1, Math.abs(minV) * 0.1);
				yaxisCfg = { min: minV - p, max: maxV + p };
			}
		}

		var color = '#5b6cff';
		if (deltaClass === 'up')   color = '#16a34a';
		if (deltaClass === 'down') color = '#dc2626';

		var chart = new window.ApexCharts(el, {
			chart: { type: 'area', height: 50, sparkline: { enabled: true }, animations: { enabled: false } },
			stroke: { curve: 'straight', width: 2 },
			fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02 } },
			colors: [color],
			series: [{ name: 'Value', data: values }],
			xaxis: { categories: categories },
			yaxis: yaxisCfg,
			tooltip: { x: { show: !!(categories && categories.length) } }
		});
		chart.render();
		return chart;
	}

	// Total-reviews sparkline shows a running cumulative line ending at
	// the lifetime total. Both legs (lifetime from /reports/all,
	// per-month from /reports/time/12) can land in either order, so we
	// gate on both being present before rendering.
	function maybeRenderTotalSparkline(gen) {
		if (gen !== loadGen) return;
		if (lifetimeTotal == null || !lastTotalSeries) return;
		var cumulative = new Array(lastTotalSeries.length);
		var runningEnd = lifetimeTotal;
		for (var i = lastTotalSeries.length - 1; i >= 0; i--) {
			cumulative[i] = runningEnd;
			runningEnd -= Number(lastTotalSeries[i] || 0);
		}
		sparklineTotal = renderSparkline(
			'rw-sparkline-total',
			cumulative,
			lastTotalLabels,
			gen,
			sparklineTotal
		);
	}

	// Compare the average of the first half of the avg-rating series to
	// the second half. Used to colour the rating sparkline up/down/flat.
	function computeAvgDeltaClass(series) {
		var valid = (series || []).filter(function (v) { return v != null && isFinite(v); }).map(Number);
		if (valid.length < 2) return '';
		var mid  = Math.floor(valid.length / 2);
		var prev = valid.slice(0, mid).reduce(function (a, b) { return a + b; }, 0) / mid;
		var curr = valid.slice(mid).reduce(function (a, b) { return a + b; }, 0) / (valid.length - mid);
		var d = curr - prev;
		if (d > 0.01) return 'up';
		if (d < -0.01) return 'down';
		return 'flat';
	}

	function renderRatingKpi(avg, total) {
		var $card = $('[data-kpi="rating"]');
		if (total === 0) {
			$card.find('[data-kpi-value]').text(window.rwT('kpi_na', 'n/a'));
			$card.find('[data-kpi-meta]').text(window.rwT('kpi_no_reviews_yet', 'No reviews yet'));
			return;
		}
		var rounded = avg.toFixed(1);
		$card.find('[data-kpi-value]').html(
			'<span class="rw-kpi__number">' + rounded + '</span>' +
			'<span class="rw-kpi__suffix"> / 5</span>'
		);
		// .rw-stars wrapper is required so the absolutely-positioned orange
		// fill layer anchors against the grey base instead of an arbitrary
		// ancestor with position:relative.
		$card.find('[data-kpi-meta]').html(
			'<span class="rw-stars">' +
				'<span class="rw-stars-base">★★★★★</span>' +
				'<span class="rw-stars-fill" style="width:' + ((avg / 5) * 100) + '%">★★★★★</span>' +
			'</span>'
		);
	}

	function renderTotalKpi(count, platformCount) {
		var $card = $('[data-kpi="total"]');
		$card.find('[data-kpi-value]').text(numberFmt(count));
		$card.find('[data-kpi-meta]').text(
			platformCount === 1
				? window.rwTf('kpi_across_platform',  'across %d platform',  platformCount)
				: window.rwTf('kpi_across_platforms', 'across %d platforms', platformCount)
		);
	}

	function renderNewKpi(total, delta) {
		var $card = $('[data-kpi="new"]');
		$card.find('[data-kpi-value]').text(numberFmt(total));
		// Refresh the card's label to mention the active range so the
		// number ("142") and its scope ("in last 90 days") read together.
		$card.find('.rw-kpi__label').text(window.rwTf('kpi_new_reviews_label', 'New reviews (%s)', rangeLabelText(currentRange())));

		var $meta = $card.find('[data-kpi-meta]');
		var noNewLabel = window.rwT('kpi_no_new_in_range', 'No new reviews in range');
		if (delta == null) {
			$meta.text(total === 0 ? noNewLabel : '');
			return;
		}
		if (delta === 0 && total === 0) {
			$meta.text(noNewLabel);
			return;
		}
		var sign = delta > 0 ? '+' : '';
		var cls  = delta > 0 ? 'rw-kpi__delta-up'
		         : delta < 0 ? 'rw-kpi__delta-down'
		         : 'rw-kpi__delta-flat';
		$meta.html(
			'<span class="rw-kpi__delta ' + cls + '">' + sign + delta + '</span> ' +
			escAttr(window.rwT('kpi_vs_first_half', 'vs first half of range'))
		);
	}

	function renderKpiError(which) {
		var $card = $('[data-kpi="' + which + '"]');
		$card.find('[data-kpi-value]').text(window.rwT('kpi_na', 'n/a'));
		$card.find('[data-kpi-meta]').text(window.rwT('kpi_load_failed', "Couldn't load"));
		// Also clear the sparkline host so the previous run's chart
		// doesn't sit underneath a stale "n/a" value.
		$card.find('.rw-kpi__spark').empty();
	}

	function numberFmt(n) {
		n = parseInt(n, 10) || 0;
		return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	// ---- Platform breakdown table ----
	// Mirrors the dashboard's loadRatingsAndBreakdowns transform: turn the
	// keyed `types` object into a sorted-by-count array with share %.
	// Collapsed to PLATFORM_BREAKDOWN_COLLAPSED_LIMIT (5) with a Show All
	// toggle when there are more.
	var PLATFORM_BREAKDOWN_COLLAPSED_LIMIT = 5;
	var platformExpanded = false;

	function renderPlatformBreakdown(typesObj) {
		$('[data-platforms-loading]').hide();

		var arr = [];
		Object.keys(typesObj || {}).forEach(function (k) {
			var t = typesObj[k];
			if (!t) return;
			arr.push({
				type:         k,
				label:        t.label || String(k),
				logo:         t.logo || '',
				count:        parseInt(t.official_num_reviews || 0, 10),
				rating:       parseFloat(t.rating_value || 0),
				channelCount: parseInt(t.channel_count || (t.channels && t.channels.length) || 0, 10)
			});
		});
		var totalPosts = arr.reduce(function (a, p) { return a + p.count; }, 0);
		arr.forEach(function (p) {
			p.share = totalPosts > 0 ? (p.count / totalPosts * 100) : 0;
		});
		arr.sort(function (a, b) { return b.count - a.count; });

		if (arr.length === 0) {
			$('[data-platforms-empty]').show();
			return;
		}

		platformExpanded = false;
		paintPlatformRows(arr);

		var $toggle = $('[data-platforms-toggle]').empty();
		if (arr.length > PLATFORM_BREAKDOWN_COLLAPSED_LIMIT) {
			var $tbody = $('[data-platforms-rows]');
			var renderToggle = function () {
				$toggle.html(
					'<button type="button" class="rw-platforms__toggle-btn">' +
						escAttr(platformExpanded
							? window.rwTf('platforms_show_top',  'Show top %d',          PLATFORM_BREAKDOWN_COLLAPSED_LIMIT)
							: window.rwTf('platforms_show_all', 'Show all %d platforms', arr.length)) +
					'</button>'
				);
			};
			renderToggle();
			$toggle.show();
			$toggle.off('click').on('click', '.rw-platforms__toggle-btn', function (e) {
				e.preventDefault();
				// Toggle a single class instead of re-rendering the
				// tbody. Rows beyond the limit are marked .is-overflow
				// at first paint; CSS hides them when the table has
				// .is-collapsed. Cheap and lets row state (selection,
				// hover) survive expand/collapse if we ever add it.
				platformExpanded = !platformExpanded;
				$tbody.toggleClass('is-collapsed', !platformExpanded);
				renderToggle();
			});
			// Initial state: collapsed.
			$tbody.addClass('is-collapsed');
		}
		$('[data-platforms-table]').show();
	}

	function paintPlatformRows(arr) {
		// Render every platform row up-front; rows beyond the collapsed
		// limit get .is-overflow so the toggle can show/hide them via
		// a single class flip on the tbody (see renderPlatformBreakdown).
		var $rows = $('[data-platforms-rows]').empty();
		arr.forEach(function (row, idx) {
			var rowCls = idx >= PLATFORM_BREAKDOWN_COLLAPSED_LIMIT ? ' class="is-overflow"' : '';
			$rows.append(
				'<tr' + rowCls + '>' +
					'<td>' +
						(row.logo ? '<img class="rw-platforms__logo" src="' + escAttr(row.logo) + '" alt="">' : '') +
						'<span>' + escAttr(row.label) + '</span>' +
					'</td>' +
					'<td class="rw-platforms__num">' + Math.round(row.share) + '%</td>' +
					'<td class="rw-platforms__num">' + (row.rating ? row.rating.toFixed(1) : '–') + '</td>' +
					'<td class="rw-platforms__num">' + row.channelCount + '</td>' +
				'</tr>'
			);
		});
	}

	// ---- Latest reviews list ----
	// Mirrors the production dashboard's latest-review-row:
	//   avatar + platform badge -> meta (rating + time-ago) -> HTML-safe
	//   text + author -> optional AI reply panel -> tag/property footer ->
	//   actions row (replied badge / Reply link / AI suggest, then
	//   dismiss / approve icons).
	// Most data comes back from /posts/all; we preserve <br/> from the
	// review body via a safe-html pass instead of escaping it to text.
	function loadLatestReviews(subAccount, gen, done) {
		staleWhileRevalidate(cacheKey('latest', subAccount, ''), function () {
			return hookGet('posts/all?website=' + encodeURIComponent(subAccount) + '&limit=5');
		}, function (response, fromCache) {
			if (gen !== loadGen) return;
			$('[data-latest-loading]').hide();
			var items = (response && response.items) ? response.items.slice(0, 5) : [];
			if (items.length === 0) {
				$('[data-latest-empty]').show();
				return;
			}
			$('[data-latest-empty]').hide();
			var $list = $('[data-latest-list]').empty();
			items.forEach(function (p) {
				$list.append(renderLatestRow(p));
			});
			setTimeout(detectTruncatedLatest, 0);
		}, { force: !!refreshForced })
			.fail(function () {
				if (gen !== loadGen) return;
				$('[data-latest-loading]').hide();
				$('[data-latest-empty]').text(window.rwT('latest_load_failed', "Couldn't load latest reviews.")).show();
			})
			.always(function () { if (done) done(); });
	}

	function renderLatestRow(p) {
		var id        = parseInt(p.id, 10) || 0;
		var status    = parseInt(p.status || 0, 10);
		var sourceLogo= 'https://widgets.thereviewsplace.com/2.0/images/60x60/logo-' + escAttr(p.type) + '.png';
		var avatarUrl = p.from_image || '';
		var rv        = (p.rating_scale > 0 && p.rating_value > 0)
		              ? (Number(p.rating_value) / Number(p.rating_scale)) * 5
		              : 0;
		var hasRating = rv > 0;
		var hasReply  = !!(p.reply && String(p.reply).trim().length);
		var replyUrl  = p.reply_url || '';
		var tags      = Array.isArray(p.tags) ? p.tags : [];

		// Avatar block: placeholder underneath, real image overlays via
		// `position:absolute`. onerror collapses the broken image so the
		// placeholder stays visible (mirrors dashboard behaviour).
		var avatarHtml =
			'<div class="rw-latest__avatar-wrap">' +
				'<div class="rw-latest__avatar-placeholder">' +
					'<span class="dashicons dashicons-admin-users"></span>' +
				'</div>' +
				(avatarUrl
					? '<img class="rw-latest__avatar" src="' + escAttr(avatarUrl) + '" alt="" onerror="this.style.display=\'none\'">'
					: '') +
				'<img class="rw-latest__platform-badge" src="' + escAttr(sourceLogo) + '" alt="">' +
			'</div>';

		var ratingHtml = hasRating
			? '<span class="rw-latest__rating">' + starsForRating(rv) + '</span>'
			: '';

		// Tag/property footer (only rendered when there's content to show).
		var footerInner = '';
		tags.forEach(function (t) {
			if (t && t.name) footerInner += '<span class="rw-tag-item">' + escAttr(t.name) + '</span>';
		});
		if (p.property_name || p.propertyName) {
			footerInner += '<span class="rw-tag-property">' + escAttr(p.property_name || p.propertyName) + '</span>';
		}
		var footerHtml = footerInner
			? '<div class="rw-latest__footer">' + footerInner + '</div>'
			: '';

		// Actions row: left side shows reply/AI-suggest/replied badge based
		// on state; right side has dismiss + approve icon buttons.
		var replyActions = '';
		if (hasReply) {
			replyActions +=
				'<span class="rw-latest__replied-badge">' +
					'<span class="dashicons dashicons-yes-alt"></span> ' + escAttr(window.rwT('replied', 'Replied')) +
				'</span>';
		} else {
			if (replyUrl) {
				replyActions +=
					'<a class="rw-latest__reply-link" href="' + escAttr(replyUrl) + '" target="_blank" rel="noopener">' +
						'<span class="dashicons dashicons-format-chat"></span> <span class="rw-link-text">' + escAttr(window.rwT('reply', 'Reply')) + '</span>' +
					'</a>';
			}
			// AI suggest reply shows when AI is enabled for this site,
			// OR when the user is an admin (admins are the audience
			// for the "upgrade your plan" CTA, even on plans where AI
			// is currently off). Mirrors the web dashboard's
			// `(aiEnabled() || isAdmin())` gate. Regular Users on a
			// non-AI plan don't see it - the click would just error.
			// If roles haven't resolved yet (account/info still in
			// flight), render the button optimistically; an
			// rw:account-loaded post-pass below will hide it when the
			// user turns out to be a regular User on a non-AI plan.
			if (!rolesResolved() || aiPlanEnabled() || isAdmin()) {
				replyActions +=
					'<a class="rw-latest__ai-suggest ai-link" data-ai-suggest href="#">' +
						'<span class="rw-ai-icon"></span> <span class="rw-link-text">' + escAttr(window.rwT('ai_suggest_reply', 'AI suggest reply')) + '</span>' +
					'</a>' +
					'<span class="rw-latest__ai-loading" data-ai-loading style="display:none;">' +
						'<span class="dashicons dashicons-update"></span> ' + escAttr(window.rwT('ai_suggesting', 'Suggesting…')) +
					'</span>';
			}
		}

		return (
			'<div class="rw-latest__row" data-review-id="' + id + '" data-status="' + status + '">' +
				avatarHtml +
				'<div class="rw-latest__body">' +
					'<div class="rw-latest__meta">' +
						ratingHtml +
						'<span>' + escAttr(timeAgo(p.posted_on)) + '</span>' +
					'</div>' +
					'<div class="rw-latest__text" data-review-text>' + safeHtmlText(p.text || '') + '</div>' +
					'<button type="button" class="rw-latest__readmore" data-readmore style="display:none;">' + escAttr(window.rwT('read_more', 'Read more')) + '</button>' +
					renderReviewMedia(p) +
					(p.from_name ? '<div class="rw-latest__author">- ' + escAttr(p.from_name) + '</div>' : '') +
					'<div class="rw-latest__ai-reply" data-ai-reply style="display:none;">' +
						'<div class="rw-latest__ai-reply-text" data-ai-reply-text contenteditable></div>' +
						'<a class="rw-latest__ai-reply-copy" href="#" data-copy-reply>' +
							'<span class="dashicons dashicons-clipboard"></span> <span class="rw-link-text">' + escAttr(window.rwT('copy_reply', 'Copy reply')) + '</span>' +
						'</a>' +
					'</div>' +
					'<div class="rw-latest__ai-reply rw-latest__ai-reply-error" data-ai-reply-error style="display:none;">' +
						'<span class="dashicons dashicons-warning"></span> <span data-ai-reply-error-text></span>' +
					'</div>' +
					footerHtml +
					'<div class="rw-latest__actions">' +
						'<div class="rw-latest__reply-actions">' + replyActions + '</div>' +
						'<div class="rw-latest__mod-actions">' +
							'<span class="rw-tooltiper">' +
								'<button type="button" class="rw-latest__mod-btn rw-latest__dismiss' + (status === 2 ? ' is-active' : '') + '" data-mod-status="2" aria-label="' + escAttr(window.rwT('dismiss_tooltip', 'Dismiss (hidden in widgets)')) + '">' +
									'<svg class="rw-mod-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="8" y1="12" x2="16" y2="12"/></svg>' +
								'</button>' +
								'<span class="rw-tooltipertext">' + escAttr(window.rwT('dismiss_tooltip', 'Dismiss (hidden in widgets)')) + '</span>' +
							'</span>' +
							'<span class="rw-tooltiper">' +
								'<button type="button" class="rw-latest__mod-btn rw-latest__approve' + (status === 1 ? ' is-active' : '') + '" data-mod-status="1" aria-label="' + escAttr(window.rwT('approve_tooltip', 'Approve (displayed in widgets)')) + '">' +
									'<svg class="rw-mod-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="8 12 11 15 16 9"/></svg>' +
								'</button>' +
								'<span class="rw-tooltipertext">' + escAttr(window.rwT('approve_tooltip', 'Approve (displayed in widgets)')) + '</span>' +
							'</span>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	// Format a rating value (0-5) as 5 unicode stars: full / half / empty.
	function starsForRating(rv) {
		var out = '';
		for (var i = 1; i <= 5; i++) {
			if (rv >= i) out += '★';
			else if (rv >= i - 0.5) out += '⯨';
			else out += '☆';
		}
		return out;
	}

	// Human-readable relative time. Matches the dashboard's am-time-ago
	// granularity closely enough that the two views read the same.
	function timeAgo(iso) {
		if (!iso) return '';
		var d = new Date(iso);
		if (isNaN(d.getTime())) return '';
		var sec = Math.floor((Date.now() - d.getTime()) / 1000);
		var tf  = window.rwTf;
		if (sec < 60)        return tf('time_ago_seconds', '%ds ago',  sec);
		if (sec < 3600)      return tf('time_ago_minutes', '%dm ago',  Math.floor(sec / 60));
		if (sec < 86400)     return tf('time_ago_hours',   '%dh ago',  Math.floor(sec / 3600));
		if (sec < 604800)    return tf('time_ago_days',    '%dd ago',  Math.floor(sec / 86400));
		if (sec < 2592000)   return tf('time_ago_weeks',   '%dw ago',  Math.floor(sec / 604800));
		if (sec < 31536000)  return tf('time_ago_months',  '%dmo ago', Math.floor(sec / 2592000));
		return                      tf('time_ago_years',   '%dy ago',  Math.floor(sec / 31536000));
	}

	// After paint, reveal the "Read more" button on rows whose text
	// actually overflows the clamp. Avoids showing it on short reviews.
	function detectTruncatedLatest() {
		$('[data-review-text]').each(function () {
			var el = this;
			var truncated = el.scrollHeight > el.clientHeight + 1;
			var $btn = $(el).closest('.rw-latest__row').find('[data-readmore]');
			$btn.toggle(truncated);
		});
	}

	// Render the small media gallery a review can carry. Mirrors the
	// web dashboard's posts.list.html `media_arr` foreach: each item is
	// an {url, type: image|video|audio} thumbnail. Video items overlay
	// a play badge so the user can tell them apart from photos. Falls
	// back to the legacy single `p.media` string when media_arr is
	// missing/empty (older posts predate the array shape).
	function renderReviewMedia(p) {
		if (!p) return '';
		var arr = Array.isArray(p.media_arr) ? p.media_arr : [];
		if (arr.length === 0 && p.media) {
			arr = [{ url: p.media, type: 'image' }];
		}
		if (arr.length === 0) return '';
		var items = '';
		arr.forEach(function (m) {
			if (!m || !m.url) return;
			var isVideo = (m.type === 'video');
			var isAudio = (m.type === 'audio');
			items +=
				'<a class="rw-review-media__item' + (isVideo ? ' is-video' : isAudio ? ' is-audio' : '') + '" ' +
					'href="' + escAttr(m.url) + '" target="_blank" rel="noopener">' +
					(isAudio
						? '<span class="rw-review-media__audio"><span class="dashicons dashicons-format-audio"></span></span>'
						: '<img loading="lazy" src="' + escAttr(m.url) + '" alt="" onerror="this.style.display=\'none\';" />') +
					(isVideo ? '<span class="rw-review-media__play"><span class="dashicons dashicons-controls-play"></span></span>' : '') +
				'</a>';
		});
		if (!items) return '';
		return '<div class="rw-review-media">' + items + '</div>';
	}

	// Render review body HTML safely. We allow only <br> (the only tag
	// the upstream API actually emits in review text); every other tag is
	// escaped so a malicious review can't inject script/style/img/iframe.
	function safeHtmlText(s) {
		var escaped = String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
		return escaped.replace(/&lt;br\s*\/?&gt;/gi, '<br>');
	}

	// ---- Complete-setup checklist ----
	// Three steps: connect a channel, create a widget, approve a review.
	// Hidden once all three are done. Mirrors the web dashboard's logic:
	// step 3 has a "waiting" state when there are no posts at all yet
	// (we're literally waiting for the channels to surface reviews), so
	// the CTA is replaced with a passive "Waiting for your first review
	// to arrive" line instead of nudging the user to a page with nothing
	// on it. Step 3's CTA links to the WP Reviews → Inbox page (in-plugin
	// navigation) rather than the web dashboard's /posts.
	function loadSetup(subAccount, gen, done) {
		// Defer role gate until window.rwAccount lands; account/info
		// is fetched in parallel and may not be available at boot. If
		// we made the role decision here-and-now we'd treat every
		// admin as a regular User and hide the setup card forever.
		whenRolesResolved(function () {
			if (gen !== loadGen) return;
			if (!isAdminOrEditor()) {
				$('[data-setup-placeholder]').hide();
				$('[data-setup-card]').hide();
				if (done) done();
				return;
			}

			// Fast path: account/info already includes account-wide
			// usage counters for channels, widgets, and approved posts.
			// When all three are zero - the fresh-signup case, exactly
			// when the dashboard is most likely to feel slow - we
			// already know steps 1/2/3 are all incomplete and step 3
			// is "waiting" (no channels means no incoming reviews).
			// Render directly and skip the 4-probe round-trip. Saves
			// ~1-2s on cold mount and avoids the timeout window on
			// constrained local pools.
			var acc = window.rwAccount;
			if (acc && acc.channels && acc.widgets && acc.approved_posts) {
				var chU = parseInt(acc.channels.usage,        10) || 0;
				var wgU = parseInt(acc.widgets.usage,         10) || 0;
				var psU = parseInt(acc.approved_posts.usage,  10) || 0;
				if (chU === 0 && wgU === 0 && psU === 0) {
					renderFreshSetupFromAccount(subAccount);
					if (done) done();
					return;
				}
			}

			loadSetupInner(subAccount, gen, done);
		});
	}

	// Render the setup card directly from account/info totals (no
	// per-sub-account probes). Used on fresh accounts where every
	// counter is zero so the per-website breakdown is moot.
	function renderFreshSetupFromAccount(subAccount) {
		var appUrl     = repusoAppUrl();
		var reviewsUrl = (typeof ajax_var !== 'undefined' && ajax_var.reviewsUrl)
			? ajax_var.reviewsUrl
			: '?page=rw_reviews';
		var T = window.rwT;
		var steps = [
			{ done: false, waiting: false, title: T('setup_step1_title', 'Connect your first channel'), desc: T('setup_step1_desc', 'Google, Facebook, Tripadvisor or any of 45+ supported platforms.'),                                                  ctaLabel: T('connect_channel',     'Connect a channel'), ctaHref: appUrl + '#/channels/new', ctaPath: '/channels/new', external: true  },
			{ done: false, waiting: false, title: T('setup_step2_title', 'Create your first widget'),   desc: T('setup_step2_desc', 'Pick a layout and embed it on your site using the shortcode.'),                                                       ctaLabel: T('create_widget',       'Create widget'),     ctaHref: appUrl + '#/widgets/new',  ctaPath: '/widgets/new',  external: true  },
			// Step 3 is in the "waiting" state because no channels means
			// no incoming reviews to approve yet. Matches the web
			// dashboard's behaviour for empty accounts.
			{ done: false, waiting: true,  title: T('setup_step3_title', 'Approve a review'),           desc: T('setup_step3_desc', 'Approve the reviews you want to feature in your widgets. Pending reviews stay hidden until you approve them.'),       ctaLabel: T('setup_view_reviews',  'View reviews'),      ctaHref: reviewsUrl,                ctaPath: '',              external: false }
		];
		$('[data-setup-placeholder]').hide();
		// No channels yet means every data section would be empty;
		// collapse them so the user sees the setup card front-and-
		// centre (matches the web dashboard's empty-account state).
		$('[data-dashboard-data]').hide();
		renderSetup(steps);
		writeSetupCache(subAccount, steps);
	}

	function loadSetupInner(subAccount, gen, done) {
		var appUrl      = repusoAppUrl();
		var reviewsUrl  = (typeof ajax_var !== 'undefined' && ajax_var.reviewsUrl)
			? ajax_var.reviewsUrl
			: '?page=rw_reviews';
		// Start with everything marked done so renderSetup short-circuits
		// (no card) if every probe confirms done. The card is rendered
		// exactly once - after all three probes have landed - so the user
		// never sees a step flicker from one state into another.
		// (Reviews can exist without channels - added via the collect
		// form or manually - so we can't infer step 3's state from the
		// channels count; we must wait on the posts/inbox probes.)
		var T = window.rwT;
		var steps = [
			{ done: true, waiting: false, title: T('setup_step1_title', 'Connect your first channel'), desc: T('setup_step1_desc', 'Google, Facebook, Tripadvisor or any of 45+ supported platforms.'),                                                  ctaLabel: T('connect_channel',     'Connect a channel'), ctaHref: appUrl + '#/channels/new', ctaPath: '/channels/new', external: true  },
			{ done: true, waiting: false, title: T('setup_step2_title', 'Create your first widget'),   desc: T('setup_step2_desc', 'Pick a layout and embed it on your site using the shortcode.'),                                                       ctaLabel: T('create_widget',       'Create widget'),     ctaHref: appUrl + '#/widgets/new',  ctaPath: '/widgets/new',  external: true  },
			{ done: true, waiting: false, title: T('setup_step3_title', 'Approve a review'),           desc: T('setup_step3_desc', 'Approve the reviews you want to feature in your widgets. Pending reviews stay hidden until you approve them.'),       ctaLabel: T('setup_view_reviews',  'View reviews'),      ctaHref: reviewsUrl,                ctaPath: '',              external: false }
		];

		// Hydrate from cached state if we've probed this sub-account
		// before. Lets returning visits paint the real card instantly
		// instead of waiting on 3-4 queued round-trips. The fresh probes
		// still run and reconcile the cache if anything changed.
		var cached = readSetupCache(subAccount);
		if (cached) {
			steps[0].done    = cached[0].done;
			steps[0].waiting = !!cached[0].waiting;
			steps[1].done    = cached[1].done;
			steps[1].waiting = !!cached[1].waiting;
			steps[2].done    = cached[2].done;
			steps[2].waiting = !!cached[2].waiting;
			$('[data-setup-placeholder]').hide();
			$('[data-dashboard-data]').toggle(cached[0].done);
			renderSetup(steps);
		}

		var pending = 3;
		function landed() {
			pending--;
			if (pending > 0) return;
			if (gen === loadGen) {
				$('[data-setup-placeholder]').hide();
				renderSetup(steps);
				writeSetupCache(subAccount, steps);
			}
			if (done) done();
		}

		hookGet('channels?website=' + encodeURIComponent(subAccount))
			.done(function (response) {
				if (gen !== loadGen) return;
				var channelCount = countItems(response);
				steps[0].done = channelCount > 0;
				// Mirror the web dashboard: with zero channels the data
				// blocks fed by reports endpoints (KPIs, charts,
				// breakdowns) are all empty by definition, so collapse
				// them and surface only the Setup card. (Manually-added
				// or collect-form reviews don't change this - those
				// don't populate the reports/* aggregates used by the
				// dashboard cards.) Toggle as soon as channels lands so
				// we never paint the data sections on empty installs.
				$('[data-dashboard-data]').toggle(channelCount > 0);
				landed();
			})
			.fail(landed);

		hookGet('widgets?website=' + encodeURIComponent(subAccount))
			.done(function (response) {
				if (gen !== loadGen) return;
				steps[1].done = countItems(response) > 0;
				landed();
			})
			.fail(landed);

		// Step 3 is two-stage, mirroring the web dashboard:
		//   1. approved > 0      -> done = true (step disappears).
		//   2. approved == 0 + pending > 0  -> not done, not waiting,
		//      show the "View reviews" CTA so the user can approve.
		//   3. approved == 0 + pending == 0 -> waiting = true, passive
		//      "Waiting for your first review to arrive" label.
		// GET posts (no path) forces status=1 in the API, so it counts
		// approved-only. GET posts/inbox forces status=0 (pending).
		// landed() for step 3 only fires after BOTH stages resolve so
		// the card render is held until step 3's final state is known.
		hookGet('posts?website=' + encodeURIComponent(subAccount) + '&limit=1')
			.done(function (response) {
				if (gen !== loadGen) return;
				var approved = countItems(response);
				steps[2].done = approved > 0;
				if (approved > 0) {
					landed();
					return;
				}
				hookGet('posts/inbox?website=' + encodeURIComponent(subAccount) + '&limit=1')
					.done(function (inboxResponse) {
						if (gen !== loadGen) return;
						steps[2].waiting = countItems(inboxResponse) === 0;
						landed();
					})
					.fail(landed);
			})
			.fail(landed);
	}

	// ---- Customer outreach card ----
	// Mirrors the web dashboard's `dash-row-outreach`. Three nudges:
	//   1. Collect: set up review sources on the Collect page.
	//   2. Invite: enable email + SMS invite flow.
	//   3. NFC: order tap-to-review NFC tags.
	// Each step is shown only when the corresponding action hasn't been
	// taken yet, so completed accounts see nothing. The card itself is
	// hidden when no step is visible. All CTAs route through the
	// auto-login deep-link flow into the web dashboard.
	function loadOutreach(subAccount, gen, done) {
		whenRolesResolved(function () {
			if (gen !== loadGen) return;
			if (!isAdmin()) {
				$('[data-outreach-card]').hide();
				if (done) done();
				return;
			}

			// Don't surface Customer outreach until the basic Complete-
			// your-setup checklist is done. Nudging users to "send
			// invites" or "order NFC tags" before they've even connected
			// a channel is noise - the setup card is the right prompt
			// at that stage. Setup-complete heuristic: account/info
			// reports at least one channel, widget, and approved post
			// account-wide. Conservative: if account isn't loaded yet
			// we wait (no flash, no race).
			var acc = window.rwAccount;
			if (!acc || !acc.channels || !acc.widgets || !acc.approved_posts) {
				$('[data-outreach-card]').hide();
				if (done) done();
				return;
			}
			var chU = parseInt(acc.channels.usage,       10) || 0;
			var wgU = parseInt(acc.widgets.usage,        10) || 0;
			var psU = parseInt(acc.approved_posts.usage, 10) || 0;
			if (chU === 0 || wgU === 0 || psU === 0) {
				$('[data-outreach-card]').hide();
				if (done) done();
				return;
			}

			loadOutreachInner(subAccount, gen, done);
		});
	}

	function loadOutreachInner(subAccount, gen, done) {
		var appUrl = repusoAppUrl();
		var T = window.rwT;
		var steps = {
			collect: { visible: false, title: T('outreach_collect_title', 'Make leaving a review effortless'),  desc: T('outreach_collect_desc', 'Add review sources so customers can leave reviews in a few taps.'), ctaLabel: T('outreach_collect_cta', 'Set up Collect'),  ctaPath: '/collect/sources',      icon: 'dashicons-star-filled'  },
			invite:  { visible: false, title: T('outreach_invite_title',  'Invite customers to leave reviews'), desc: T('outreach_invite_desc',  'Send review invites by email & SMS.'),                              ctaLabel: T('outreach_invite_cta',  'Enable invites'),   ctaPath: '/invite/requests',      icon: 'dashicons-email-alt'    },
			nfc:     { visible: false, title: T('outreach_nfc_title',     'Collect reviews in person'),         desc: T('outreach_nfc_desc',     'Order NFC tags so customers can tap to leave a review.'),           ctaLabel: T('outreach_nfc_cta',     'Order NFC tags'),   ctaPath: '/collect/overview/nfc', icon: 'dashicons-smartphone'   }
		};

		// Invite enabled flag comes from account/info (already loaded by
		// rw-admin.js). Sub-account websites carry their own `invite`
		// flag; the main account (id=0) falls back to plan_invite.
		function isInviteActive() {
			var acc = window.rwAccount;
			if (!acc) return false;
			var wid = parseInt(subAccount, 10) || 0;
			if (wid > 0) {
				var sub = (acc.websites || []).filter(function (w) { return w.id === wid; })[0];
				return !!(sub && (sub.invite == 1 || sub.invite === '1'));
			}
			return acc.plan_invite == 1 || acc.plan_invite === '1';
		}

		var pending = 2; // collect + nfc; invite is sync from account
		function probeLanded() {
			pending--;
			if (pending > 0) return;
			// Invite check is cheap and synchronous - fold it in here so
			// we render the card once with the final state.
			steps.invite.visible = !isInviteActive();
			if (gen !== loadGen) return;
			renderOutreach(steps);
			if (done) done();
		}

		hookGet('collect?website=' + encodeURIComponent(subAccount))
			.done(function (response) {
				if (gen !== loadGen) return;
				// Collect::initSettings seeds sources.channels with one
				// placeholder row (type: -1) for every account. CollectCtrl
				// excludes it via enabled === true && type !== -1.
				var raw = (response && response.settings && response.settings.sources && response.settings.sources.channels) || [];
				var sources = raw.filter(function (el) {
					return el != null && el.enabled === true && el.type !== -1;
				});
				steps.collect.visible = sources.length === 0;
				probeLanded();
			})
			.fail(probeLanded);

		hookGet('collect/nfc/all?website_id=' + encodeURIComponent(subAccount))
			.done(function (response) {
				if (gen !== loadGen) return;
				var nfcs = (response && response.nfcs) || [];
				steps.nfc.visible = nfcs.length === 0;
				probeLanded();
			})
			.fail(probeLanded);
	}

	function renderOutreach(steps) {
		var visible = steps.collect.visible || steps.invite.visible || steps.nfc.visible;
		var $card = $('[data-outreach-card]');
		if (!visible) {
			$card.hide();
			return;
		}

		var $list = $('[data-outreach-steps]').empty();
		['collect', 'invite', 'nfc'].forEach(function (key) {
			var step = steps[key];
			if (!step.visible) return;
			$list.append(
				'<div class="rw-outreach__step">' +
					'<span class="rw-outreach__step-icon"><span class="dashicons ' + escAttr(step.icon) + '"></span></span>' +
					'<div class="rw-outreach__step-body">' +
						'<div class="rw-outreach__step-title">' + escAttr(step.title) + '</div>' +
						'<div class="rw-outreach__step-desc">' + escAttr(step.desc) + '</div>' +
					'</div>' +
					'<a class="rw-outreach__step-cta rw-open-dashboard" href="#" data-rw-path="' + escAttr(step.ctaPath) + '" target="_blank" rel="noopener">' +
						escAttr(step.ctaLabel) +
						' <span class="dashicons dashicons-external"></span>' +
					'</a>' +
				'</div>'
			);
		});
		$card.show();
	}

	function countItems(response) {
		if (!response) return 0;
		if (typeof response.count === 'number') return response.count;
		if (Array.isArray(response)) return response.length;
		if (Array.isArray(response.items)) return response.items.length;
		return 0;
	}

	// localStorage-backed cache for the setup-card state, keyed by
	// sub-account so each website remembers its own progress. Lets the
	// dashboard render the setup card instantly on subsequent visits
	// instead of waiting for 3-4 round-trips through the rwQueue. The
	// fresh probes always run after a cached hydrate and overwrite the
	// cache, so the card reconciles to the truth as soon as the API
	// responds.
	function setupCacheKey(subAccount) {
		return 'rw_setup_state_' + (subAccount || 0);
	}
	function readSetupCache(subAccount) {
		try {
			var raw = window.localStorage.getItem(setupCacheKey(subAccount));
			if (!raw) return null;
			var parsed = JSON.parse(raw);
			if (!parsed || !Array.isArray(parsed.steps) || parsed.steps.length !== 3) return null;
			return parsed.steps;
		} catch (e) {
			return null;
		}
	}
	function writeSetupCache(subAccount, steps) {
		try {
			window.localStorage.setItem(setupCacheKey(subAccount), JSON.stringify({
				cachedAt: Date.now(),
				steps: steps.map(function (s) {
					return { done: !!s.done, waiting: !!s.waiting };
				})
			}));
		} catch (e) { /* quota or private mode - ignore */ }
	}

	function renderSetup(steps) {
		var done = steps.reduce(function (a, s) { return a + (s.done ? 1 : 0); }, 0);
		if (done >= 3) {
			// Fully set up - hide whichever variant might still be
			// visible (the skeleton placeholder, or the previously
			// rendered card from a stale cache).
			$('[data-setup-card]').hide();
			$('[data-setup-placeholder]').hide();
			return;
		}
		var $card = $('[data-setup-card]');
		$card.find('[data-setup-done]').text(done);
		$card.find('[data-setup-progress]').css('width', (done / 3 * 100) + '%');

		var $steps = $card.find('[data-setup-steps]').empty();
		steps.forEach(function (step, idx) {
			var stepClass = 'rw-setup__step';
			if (step.done)    stepClass += ' is-done';
			if (step.waiting) stepClass += ' is-waiting';

			var ctaHtml = '';
			if (step.done) {
				ctaHtml = '';
			} else if (step.waiting) {
				// Passive label that mirrors the web's "Waiting for your
				// first review to arrive" - no CTA, because the user has
				// nothing to act on yet.
				ctaHtml = '<span class="rw-setup__step-waiting">' +
					escAttr(window.rwT('setup_waiting', 'Waiting for your first review to arrive')) +
				'</span>';
			} else if (step.external) {
				// External (web dashboard) link - route through the
				// magic-link auto-login flow so the user lands signed
				// in on the target page.
				ctaHtml = '<a class="rw-setup__step-cta rw-open-dashboard" href="' + escAttr(step.ctaHref) +
					'" target="_blank" rel="noopener" data-rw-path="' + escAttr(step.ctaPath) + '">' +
					escAttr(step.ctaLabel) +
					' <span class="dashicons dashicons-external"></span>' +
				'</a>';
			} else {
				// In-plugin link (e.g. WP Reviews → Inbox). Plain anchor;
				// no external icon, no auto-login flow.
				ctaHtml = '<a class="rw-setup__step-cta" href="' + escAttr(step.ctaHref) + '">' +
					escAttr(step.ctaLabel) +
				'</a>';
			}

			$steps.append(
				'<div class="' + stepClass + '">' +
					'<span class="rw-setup__step-icon">' +
						(step.done
							? '<span class="dashicons dashicons-yes"></span>'
							: (idx + 1)) +
					'</span>' +
					'<div class="rw-setup__step-body">' +
						'<div class="rw-setup__step-title">' + escAttr(step.title) + '</div>' +
						'<div class="rw-setup__step-desc">' + escAttr(step.desc) + '</div>' +
					'</div>' +
					ctaHtml +
				'</div>'
			);
		});
		$card.show();
	}

	// Public entry point. Called once on DOMReady (with the server-side
	// subAccount) and again by rw-admin.js whenever the user changes the
	// sub-account picker.
	var hasBooted      = false;
	var refreshForced  = false; // true while a user-initiated Refresh is in flight; bypasses the response cache.

	// Exposed so rw-admin.js's disconnect handler can flip the
	// dashboard back to "fresh boot" semantics for the NEXT refreshAll
	// call. Without this, signing into account B after a disconnect
	// hits the sub-account-switch branch of refreshAll, which hides
	// the setup placeholder + data sections immediately - and on a
	// slow account/info the user sees only the topbar with empty
	// content for several seconds.
	window.rwResetOverviewBoot = function () { hasBooted = false; };

	function refreshAll(subAccount, opts) {
		if (!$('[data-overview]').length) return;
		// Skip when the page is on the disconnected onboard view - the
		// dashboard markup is always emitted so a runtime login can flip
		// to it without a refresh, but firing the loaders against an
		// empty apikey would just paint API errors. rw-admin.js calls
		// rwLoadOverview() again after renderStatus('Connected'), which
		// is the right time to populate the dashboard.
		if (String($('#rw-wrapper').attr('data-rw-has-apikey')) !== '1') return;
		opts = opts || {};
		refreshForced = !!opts.force;
		if (subAccount == null) {
			subAccount = (typeof ajax_var !== 'undefined' && ajax_var.subAccount != null)
				? ajax_var.subAccount
				: 0;
		}
		// Cancel any still-firing requests from the previous batch so the
		// upstream PHP-FPM pool doesn't get hammered with two batches in
		// flight at once (that's what produced the 503s on rapid
		// sub-account switches). Skip on the very first call though: at
		// boot time, rw-admin.js has already enqueued an account/info
		// request (the optimistic-render path that populates the
		// sub-account dropdown), and aborting it leaves the dropdown
		// empty until the user manually triggers a refresh.
		if (hasBooted && window.rwQueue && typeof window.rwQueue.abort === 'function') {
			window.rwQueue.abort();
		}
		// On non-first calls (e.g. sub-account switch) reset the dashboard
		// chrome to the same "loading" silhouette the page first paints
		// with: data sections collapsed, setup-card placeholder visible,
		// real setup card hidden. Without this, the user briefly sees the
		// outgoing account's cards, then a blank gap, then the incoming
		// account's state lands - a jarring three-stage transition.
		//
		// On a sub-account switch, suppress the "Complete your setup"
		// placeholder entirely. The data sections still hide-then-show
		// (so we don't carry over the outgoing account's numbers), but
		// the placeholder is misleading for accounts that are actually
		// fully set up - it would flash "Complete your setup" briefly
		// then vanish once the probes confirmed all done. If the new
		// account really does need setup, the real setup card will
		// appear after probes land (~1-2s through the rwQueue), which
		// is fine; the data-section skeletons already give the user
		// feedback that something is loading.
		if (hasBooted) {
			$('[data-dashboard-data]').hide();
			$('[data-setup-card]').hide();
			$('[data-outreach-card]').hide();
		}
		// Decide whether to show the "Complete your setup" skeleton
		// placeholder during this load. Two cases hide it immediately:
		//   1. Sub-account switch (hasBooted): we already painted once,
		//      another skeleton flash would be jarring.
		//   2. Cold mount but we already know setup is done - either
		//      from the localStorage setup cache or from a fresh
		//      account/info that already landed (rw-admin.js fetches
		//      it in parallel with refreshAll). Returning users would
		//      otherwise see the skeleton + a step-row count up briefly
		//      before everything vanishes.
		// Default (fresh user / unknown state): keep the placeholder
		// visible so the page isn't empty while probes run.
		var setupKnownDone = false;
		var cachedSetup = readSetupCache(subAccount);
		if (cachedSetup && cachedSetup[0].done && cachedSetup[1].done && cachedSetup[2].done) {
			setupKnownDone = true;
		} else if (window.rwAccount && window.rwAccount.channels && window.rwAccount.widgets && window.rwAccount.approved_posts) {
			var chU = parseInt(window.rwAccount.channels.usage,       10) || 0;
			var wgU = parseInt(window.rwAccount.widgets.usage,        10) || 0;
			var psU = parseInt(window.rwAccount.approved_posts.usage, 10) || 0;
			setupKnownDone = chU > 0 && wgU > 0 && psU > 0;
		}
		if (hasBooted || setupKnownDone) $('[data-setup-placeholder]').hide();
		hasBooted = true;
		loadGen++;
		var gen = loadGen;
		showLoading();
		setRefreshing(true);
		// Stamp "now" optimistically and start the time-ago ticker. If any
		// of the loaders error we still see a reasonable timestamp.
		lastUpdatedAt = new Date();
		startTimeAgoTimer();
		// Bundle all loaders so we can flip refreshing back off when the
		// slowest one finishes. jQuery's deferred `$.when` doesn't help us
		// here because each loader is fire-and-forget, so use a counter.
		var pending = 6;
		var done = function () {
			pending--;
			if (pending <= 0) setRefreshing(false);
		};
		// loadSetup runs first so its channels probe is the first request
		// through the rwQueue (MAX=1). On accounts with zero channels we
		// reveal nothing else - the data sections were never even shown,
		// so there's no "cards then collapse" flash to clean up. On
		// accounts with channels we reveal the data sections and the
		// other loaders' responses paint into them as they arrive.
		loadSetup(subAccount, gen, done);
		loadPending(subAccount, gen, done);
		loadKpis(subAccount, gen, done);
		loadAiInsights(subAccount, gen, done);
		loadLatestReviews(subAccount, gen, done);
		loadOutreach(subAccount, gen, done);
		// Trial banner reads window.rwAccount directly (no probes) -
		// safe to fire here without consuming a slot in the done counter.
		if (typeof window.rwRenderTrialBanner === "function") window.rwRenderTrialBanner();
	}

	// renderTrialBannerWhenReady is now defined in rw-admin.js as
	// window.rwRenderTrialBanner so the banner can live above the
	// topbar on every plugin page (not just the Dashboard).

	// Toggle the refresh button between idle and spinning states.
	function setRefreshing(busy) {
		$('#rw-refresh-btn').toggleClass('is-spinning', !!busy);
	}

	// Re-render the "Just updated / X minutes ago" label every 30s.
	function startTimeAgoTimer() {
		updateTimeAgoLabel();
		if (timeAgoTimer) clearInterval(timeAgoTimer);
		timeAgoTimer = setInterval(updateTimeAgoLabel, 30000);
	}
	function updateTimeAgoLabel() {
		if (!lastUpdatedAt) return;
		var sec = Math.floor((Date.now() - lastUpdatedAt.getTime()) / 1000);
		var tf  = window.rwTf;
		var text;
		if      (sec < 15)   text = window.rwT('just_updated', 'Just updated');
		else if (sec < 60)   text = tf('time_ago_sec_long',  '%d s ago',   sec);
		else if (sec < 3600) text = tf('time_ago_min_long',  '%d min ago', Math.floor(sec / 60));
		else if (sec < 86400)text = tf('time_ago_hour_long', '%d h ago',   Math.floor(sec / 3600));
		else                 text = tf('time_ago_day_long',  '%d d ago',   Math.floor(sec / 86400));
		$('[data-refresh-label]').text(text);
	}
	window.rwLoadOverview = refreshAll;

	// Reflect the stored range on the pill row + wire click handlers.
	function initRangePills() {
		var active = currentRange();
		$('.rw-range__pill').each(function () {
			var $pill = $(this);
			$pill.toggleClass('is-active', $pill.data('range') === active);
		});
		$(document).off('click.rwRange').on('click.rwRange', '.rw-range__pill', function (e) {
			e.preventDefault();
			var $pill = $(this);
			var key = $pill.data('range');
			if (!key || key === currentRange()) return;
			persistRange(key);
			$('.rw-range__pill').removeClass('is-active');
			$pill.addClass('is-active');
			refreshAll();
		});
	}

	// ---- Latest-review action handlers (approve / dismiss / AI reply) ----
	function setReviewStatus($btn, requested) {
		var $row    = $btn.closest('.rw-latest__row');
		var id      = $row.data('review-id');
		var prev    = parseInt($row.data('status'), 10) || 0;
		// Toggle behaviour: clicking the already-active state moves the
		// post back to the inbox (status 0). Matches the dashboard.
		var next    = (prev === requested) ? 0 : requested;
		if (prev === next || !id) return;
		var $mods = $row.find('.rw-latest__mod-btn').addClass('is-busy');
		hookRequest({
			path:    'posts/' + id,
			method:  'PUT',
			body:    { status: next },
			headers: { 'Content-Type': 'application/json' }
		}).done(function () {
			$row.data('status', next).attr('data-status', next);
			$row.find('.rw-latest__approve').toggleClass('is-active', next === 1);
			$row.find('.rw-latest__dismiss').toggleClass('is-active', next === 2);
		}).always(function () {
			$mods.removeClass('is-busy');
		});
	}

	// Bridge for PUT / POST through the shared proxy. AI endpoints get a
	// longer timeout because upstream model calls routinely take 20-40s.
	function hookRequest(opts) {
		var isAi = opts.path && opts.path.indexOf('ai/') !== -1;
		return enqueueAjax({
			url:      ajax_var.url,
			type:     'POST',
			timeout:  isAi ? 75000 : 30000,
			data: {
				action:  'hook',
				nonce:   ajax_var.nonce,
				path:    opts.path,
				method:  opts.method || 'GET',
				body:    opts.body  || {},
				headers: $.extend({ 'Authorization': 'Yes' }, opts.headers || {})
			}
		});
	}

	function suggestAiReply($btn) {
		var $row = $btn.closest('.rw-latest__row');
		var id   = $row.data('review-id');
		if (!id) return;
		// Reset state, show loading.
		$row.find('[data-ai-reply]').hide();
		$row.find('[data-ai-reply-error]').hide();
		$row.find('[data-ai-suggest]').hide();
		$row.find('[data-ai-loading]').show();

		hookRequest({
			path:    'posts/ai/reply/' + id,
			method:  'POST',
			body:    {},
			headers: { 'Content-Type': 'application/json' }
		}).done(function (result, textStatus, xhr) {
			$row.find('[data-ai-loading]').hide();
			// jQuery's auto-parser falls back to the raw string when the
			// response Content-Type is JSON but the body isn't valid JSON
			// (e.g. upstream returned an HTML error page). Parse defensively
			// so .msg / .success checks still work in that path.
			if (typeof result === 'string') {
				try { result = JSON.parse(result); } catch (e) { /* keep as string */ }
			}
			if (result && result.success && result.msg) {
				$row.find('[data-ai-reply-text]').html(safeHtmlText(result.msg));
				$row.find('[data-ai-reply]').show();
				return;
			}
			$row.find('[data-ai-suggest]').show();
			var errMsg;
			if (result && result.upgrade) {
				errMsg = window.rwT('ai_reply_not_on_plan', 'AI replies are not on your plan yet.');
			} else if (result && result._proxy_error) {
				// Surface the actual transport-layer reason (timeout / SSL /
				// upstream error code) instead of a generic fallback.
				errMsg = window.rwTf('ai_service_error', 'AI service: %s', result._proxy_message || window.rwT('ai_unknown_error', 'unknown error'));
			} else if (result && result.msg) {
				errMsg = result.msg;
			} else {
				// Diagnostic stays English on purpose; it's developer-facing
				// detail for the support log, not user copy. The visible
				// prefix ("AI reply unavailable - …") is translated.
				var diag;
				if (result == null) {
					diag = 'empty response';
				} else if (typeof result === 'string') {
					diag = 'non-JSON: ' + result.substring(0, 80);
				} else {
					var keys = Object.keys(result || {}).slice(0, 8).join(', ');
					diag = keys ? ('keys: ' + keys) : 'empty object';
				}
				var httpStatus = xhr && xhr.status ? (' [' + xhr.status + ']') : '';
				errMsg = window.rwTf('ai_reply_unavailable', 'AI reply unavailable - %s', httpStatus + (httpStatus ? ' ' : '') + diag);
				try { console.warn('[rw-overview] AI reply unexpected response:', { result: result, status: xhr && xhr.status, body: xhr && xhr.responseText && xhr.responseText.substring(0, 500) }); } catch (e) {}
			}
			$row.find('[data-ai-reply-error-text]').text(errMsg);
			$row.find('[data-ai-reply-error]').show();
		}).fail(function (xhr, textStatus, errText) {
			if (textStatus === 'abort') return;
			$row.find('[data-ai-loading]').hide();
			$row.find('[data-ai-suggest]').show();
			var reason = (textStatus === 'timeout')
				? window.rwT('ai_reply_timeout',     'Request timed out. The AI service is taking longer than usual.')
				: window.rwT('ai_reply_unreachable', 'Could not reach the AI service.') + (errText ? ' (' + errText + ')' : '');
			$row.find('[data-ai-reply-error-text]').text(reason);
			$row.find('[data-ai-reply-error]').show();
		});
	}

	function copyAiReply($link) {
		var $row  = $link.closest('.rw-latest__row');
		var $text = $row.find('[data-ai-reply-text]');
		var text  = ($text[0] && ($text[0].innerText || $text[0].textContent)) || '';
		var done  = function () {
			var original = $link.html();
			$link.html('<span class="dashicons dashicons-yes-alt"></span> <span class="rw-link-text">' + escAttr(window.rwT('copied', 'Copied!')) + '</span>');
			setTimeout(function () { $link.html(original); }, 1800);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {});
			return;
		}
		var $ta = $('<textarea>').css({ position: 'fixed', top: '-9999px' }).val(text).appendTo('body');
		$ta[0].select();
		try { document.execCommand('copy'); done(); } catch (e) {}
		$ta.remove();
	}

	// Once account/info lands, reconcile any role-dependent UI that
	// was rendered optimistically before window.rwAccount was known.
	// Latest reviews is the main case: each row's AI-suggest link is
	// rendered with the optimistic gate (`!rolesResolved() || aiPlanEnabled() || isAdmin()`),
	// so a regular User on a non-AI plan would briefly see the button
	// until this pass yanks it. Setup / Outreach / AI Insights all
	// gate themselves via whenRolesResolved already, no extra work
	// needed for those.
	$(document).on('rw:account-loaded', function () {
		if (!aiPlanEnabled() && !isAdmin()) {
			$('.rw-latest__ai-suggest, .rw-latest__ai-loading').remove();
		}
	});

	$(document).ready(function () {
		if (!$('[data-overview]').length) return;
		initRangePills();
		// Manual refresh button. Ignored while a refresh is already in
		// flight (the spinning state disables the click).
		$(document).off('click.rwRefresh').on('click.rwRefresh', '#rw-refresh-btn', function (e) {
			e.preventDefault();
			if ($(this).hasClass('is-spinning')) return;
			// User-initiated refresh bypasses the response cache so a
			// click on the refresh button always re-pulls from the
			// API. Background refreshes (sub-account switch, range
			// pill, automatic load) still use the cache for speed.
			refreshAll(undefined, { force: true });
		});
		// Approve / Dismiss icon buttons.
		$(document).on('click', '.rw-latest__mod-btn', function (e) {
			e.preventDefault();
			var status = parseInt($(this).data('mod-status'), 10);
			setReviewStatus($(this), status);
		});
		// AI suggest reply link.
		$(document).on('click', '[data-ai-suggest]', function (e) {
			e.preventDefault();
			suggestAiReply($(this));
		});
		// Copy AI reply.
		$(document).on('click', '[data-copy-reply]', function (e) {
			e.preventDefault();
			copyAiReply($(this));
		});
		// Read more / Show less on truncated review bodies.
		$(document).on('click', '[data-readmore]', function (e) {
			e.preventDefault();
			var $btn  = $(this);
			var $text = $btn.closest('.rw-latest__row').find('[data-review-text]');
			var expanded = $text.toggleClass('is-expanded').hasClass('is-expanded');
			$btn.text(expanded ? window.rwT('show_less', 'Show less') : window.rwT('read_more', 'Read more'));
		});

		// Setup card refresh button: re-fetch account/info + re-run
		// loadSetup so step states reconcile after the user finished
		// something on the web dashboard.
		$(document).on('click', '.rw-setup__refresh', function (e) {
			e.preventDefault();
			refreshSetupOnly();
		});

		// Setup card CTA (Connect channel / Create widget) opens the
		// web dashboard in a new tab; start polling setup state every
		// 10s so the WP card flips to "done" as soon as the user
		// finishes the action there - no manual refresh needed.
		// In-plugin "View reviews" link (step 3) navigates away from
		// this page so there's nothing to auto-refresh; we only listen
		// on .rw-open-dashboard CTAs.
		$(document).on('click', '.rw-setup__step-cta.rw-open-dashboard', function () {
			startSetupAutoRefresh();
		});

		refreshAll();
	});

	// Re-runs account/info + setup-status probes without re-firing
	// every other dashboard loader. Used both for the manual refresh
	// button and the post-CTA 10s auto-poller.
	function refreshSetupOnly() {
		var $btn = $('.rw-setup__refresh');
		$btn.addClass('is-spinning');
		var subAccount = currentSubAccount();
		var gen        = loadGen;
		function done() {
			$btn.removeClass('is-spinning');
		}
		var reload = function () {
			if (gen !== loadGen) return done();
			loadSetup(subAccount, gen, done);
			// Outreach gating depends on setup-complete; re-check.
			loadOutreach(subAccount, gen, function () {});
			// Trial banner reads account totals too.
			if (typeof window.rwRenderTrialBanner === "function") window.rwRenderTrialBanner();
		};
		if (typeof window.rwReloadAccount === 'function') {
			window.rwReloadAccount(reload);
		} else {
			reload();
		}
	}

	// Auto-refresh after a "go to dashboard" CTA. Polls every 10s
	// until either (a) setup is fully done, (b) the dashboard page
	// is hidden and the setup card no longer exists, or (c) the
	// 5-minute safety cap expires - so a user who clicks the CTA
	// and never comes back doesn't have us polling forever.
	var setupAutoRefreshTimer  = null;
	var setupAutoRefreshStarted = 0;
	var SETUP_AUTO_POLL_MS      = 10 * 1000;
	var SETUP_AUTO_POLL_CAP_MS  =  5 * 60 * 1000;
	function startSetupAutoRefresh() {
		if (setupAutoRefreshTimer) return; // already polling
		setupAutoRefreshStarted = Date.now();
		setupAutoRefreshTimer = setInterval(function () {
			// Bail if we've been polling longer than the safety cap.
			if (Date.now() - setupAutoRefreshStarted > SETUP_AUTO_POLL_CAP_MS) {
				stopSetupAutoRefresh();
				return;
			}
			// Bail if the setup card is no longer visible: either
			// every step is done (renderSetup hides it once done >= 3)
			// or the user navigated to a different sub-account.
			if (!$('[data-setup-card]').is(':visible') && !$('[data-setup-placeholder]').is(':visible')) {
				stopSetupAutoRefresh();
				return;
			}
			refreshSetupOnly();
		}, SETUP_AUTO_POLL_MS);
	}
	function stopSetupAutoRefresh() {
		if (setupAutoRefreshTimer) {
			clearInterval(setupAutoRefreshTimer);
			setupAutoRefreshTimer = null;
		}
	}

}(jQuery));
