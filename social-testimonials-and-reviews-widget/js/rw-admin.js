/**
 * Repuso plugin admin: status polling, connected-state UI, AJAX wiring.
 *
 * Behavior contract:
 *   - On load: check connection once, then poll on an adaptive cadence
 *     (30s normal, 5s "fast" after a user action, 10s while Connecting).
 *   - When status flips to Connected: show .rw-logged, fetch account/info,
 *     render the section the user is on (widgets / channels / reviews).
 *   - When status flips to Not Connected: show .rw-not-logged so onboard.js
 *     can run its forms.
 *   - Disconnect uses an inline "Are you sure?" confirm rather than a popup.
 *
 * The rw_js_onboard script (loaded alongside) handles signup + login form
 * submission and exposes nothing back here; coordination happens through
 * window.rwStartPoll / window.rwCheckConnection.
 */
(function ($) {
	'use strict';

	// Shared concurrency-limited request queue. Both rw-admin.js and
	// rw-overview.js route their admin-ajax POSTs through here. Each
	// hook-proxy call holds two PHP-FPM workers (one on the WP side, one
	// on api.repuso.*); firing 8+ in parallel against a local ServBay
	// deadlocks both pools and times out at cURL error 28. A cap of 2
	// keeps things moving without exhausting workers.
	if (!window.rwQueue) {
		window.rwQueue = (function () {
			// Dashboard fires ~11 admin-ajax calls per cold mount; each
			// holds two PHP-FPM workers (WP side plus api.repuso.* side).
			// MAX comes from ajax_var.maxConcurrent which is set in PHP
			// from the REPUSO_MAX_CONCURRENT constant (default 4 - fine
			// for production). Local dev with a small worker pool can
			// set REPUSO_MAX_CONCURRENT=1 in wp-config.php to avoid
			// cURL-28 timeouts caused by pool exhaustion. We fall back
			// to 4 if ajax_var isn't wired yet (e.g. on the very first
			// IIFE evaluation before localize-script lands - shouldn't
			// happen in practice since both come from the same enqueue).
			var MAX = (typeof ajax_var !== 'undefined' && ajax_var.maxConcurrent) ? parseInt(ajax_var.maxConcurrent, 10) : 4;
			if (!(MAX >= 1)) MAX = 4;
			var inflight = []; // xhr objects currently firing
			var queue = [];    // {opts, dfd} jobs waiting for a slot
			function drain() {
				while (inflight.length < MAX && queue.length) {
					(function (job) {
						var xhr = $.ajax(job.opts);
						inflight.push(xhr);
						xhr.always(function () {
							var i = inflight.indexOf(xhr);
							if (i !== -1) inflight.splice(i, 1);
							drain();
						}).done(function () {
							job.dfd.resolve.apply(job.dfd, arguments);
						}).fail(function () {
							job.dfd.reject.apply(job.dfd, arguments);
						});
					}(queue.shift()));
				}
			}
			return {
				enqueue: function (opts) {
					return new $.Deferred(function (dfd) {
						queue.push({ opts: opts, dfd: dfd });
						drain();
					}).promise();
				},
				// Cancel everything in flight + drop everything queued. Used
				// on sub-account / range switches so the new batch doesn't
				// pile on top of the still-running old one (which was the
				// trigger for 503s from the local api.repuso.* PHP-FPM pool).
				abort: function () {
					queue.length = 0;
					inflight.slice().forEach(function (xhr) {
						try { xhr.abort(); } catch (e) {}
					});
					inflight.length = 0;
				}
			};
		}());
	}

	// Translation lookup. PHP exposes a `rwI18n` object via
	// wp_localize_script; each key maps to a translated string. Falls
	// back to the English fallback we pass when the key is missing
	// (older builds, JS shipping new keys before PHP catches up, etc.)
	// so a missing entry never renders as "undefined".
	if (!window.rwT) {
		window.rwT = function (key, fallback) {
			var dict = window.rwI18n || {};
			if (dict[key] != null && dict[key] !== '') return String(dict[key]);
			return fallback != null ? String(fallback) : key;
		};
	}
	// Format variant: substitutes the first %s/%d sequentially with the
	// supplied args. Used for strings like "across %d platforms" where the
	// translator may move the placeholder anywhere in the sentence
	// (e.g. French puts the number after the noun, German before).
	if (!window.rwTf) {
		window.rwTf = function (key, fallback) {
			var args = Array.prototype.slice.call(arguments, 2);
			var tpl  = window.rwT(key, fallback);
			var i    = 0;
			return tpl.replace(/%[sd]/g, function () {
				var v = args[i++];
				return v == null ? '' : String(v);
			});
		};
	}

	// Shared HTML escape used by both rw-admin.js and rw-overview.js.
	// Escapes all five "dangerous in HTML" characters including the
	// single quote, so the same helper is safe for both element text
	// and attributes wrapped in either quote style. Defined once on
	// window so both IIFEs can call it without duplicating the body.
	if (!window.rwEscAttr) {
		var RW_ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
		window.rwEscAttr = function (v) {
			if (v == null) return '';
			return String(v).replace(/[&<>"']/g, function (c) { return RW_ESC_MAP[c]; });
		};
	}

	// Shared response cache used by rw-overview.js (Dashboard probes)
	// and the section loaders in this file (Channels / Widgets / Reviews).
	// Each entry is keyed by caller prefix + sub-account + range so
	// switching either does NOT serve a different context's data. TTL
	// is generous (30 min); we always bg-refresh, so staleness within
	// that window is invisible to the user. Force=true on the
	// staleWhileRevalidate helper bypasses the read but still writes
	// on success, so the next visit benefits.
	if (!window.rwCache) {
		var CACHE_PREFIX = 'rw_resp_';
		var CACHE_TTL    = 30 * 60 * 1000; // 30 min

		window.rwCache = {
			cacheKey: function (prefix, subAccount, range) {
				return CACHE_PREFIX + prefix + '_' + (subAccount || 0) + '_' + (range || '');
			},
			read: function (key) {
				try {
					var raw = window.localStorage.getItem(key);
					if (!raw) return null;
					var parsed = JSON.parse(raw);
					if (!parsed || typeof parsed.ts !== 'number') return null;
					if (Date.now() - parsed.ts > CACHE_TTL) return null;
					return parsed.data;
				} catch (e) { return null; }
			},
			write: function (key, data) {
				try {
					window.localStorage.setItem(key, JSON.stringify({ ts: Date.now(), data: data }));
				} catch (e) { /* quota / private mode */ }
			},
			invalidate: function (prefixOrPredicate) {
				try {
					var i = window.localStorage.length;
					while (i--) {
						var k = window.localStorage.key(i);
						if (!k || k.indexOf(CACHE_PREFIX) !== 0) continue;
						if (typeof prefixOrPredicate === 'function') {
							if (prefixOrPredicate(k)) window.localStorage.removeItem(k);
						} else if (typeof prefixOrPredicate === 'string') {
							if (k.indexOf(CACHE_PREFIX + prefixOrPredicate) === 0) window.localStorage.removeItem(k);
						} else {
							window.localStorage.removeItem(k);
						}
					}
				} catch (e) {}
			},
			staleWhileRevalidate: function (key, probe, render, opts) {
				opts = opts || {};
				var cached = opts.force ? null : window.rwCache.read(key);
				var sawCache = false;
				if (cached != null) {
					try { render(cached, true /* fromCache */); } catch (e) {}
					sawCache = true;
				}
				return probe()
					.done(function (response) {
						window.rwCache.write(key, response);
						try { render(response, false); } catch (e) {}
					})
					.fail(function () { return sawCache; });
			}
		};
	}

	// Shared role helpers. Mirror the web dashboard's $rootScope.isAdmin /
	// isEditor / isAdminOrEditor. account/info exposes:
	//   account.is_admin === true when role_id == 1 (Admin)
	//   account.role === 3 (Editor), === 2 (User)
	// `whenResolved(cb)` defers a callback until window.rwAccount is
	// populated by loadAccount(), via the `rw:account-loaded` event.
	if (!window.rwRoles) {
		window.rwRoles = {
			resolved: function () { return !!window.rwAccount; },
			isAdmin: function () {
				var acc = window.rwAccount;
				return !!(acc && acc.is_admin);
			},
			isEditor: function () {
				var acc = window.rwAccount;
				if (!acc) return false;
				return acc.role == 3 || acc.role === '3';
			},
			isAdminOrEditor: function () {
				return window.rwRoles.isAdmin() || window.rwRoles.isEditor();
			},
			whenResolved: function (cb) {
				if (window.rwRoles.resolved()) { cb(); return; }
				$(document).one('rw:account-loaded', function () { cb(); });
			}
		};
	}

	// Shared image lightbox. Exposed on window so rw-overview.js (loaded
	// in its own IIFE) can reuse the same overlay/escape/click-out
	// behavior. Built lazily on first call. Closes on overlay click or
	// Escape; the image itself is centered with object-fit:contain so
	// portrait, landscape, and panoramic photos all read well.
	if (!window.rwOpenImageLightbox) {
		window.rwOpenImageLightbox = function (url) {
			if (!url) return;
			$('.rw-imgbox-overlay').remove(); // never stack
			var $overlay = $(
				'<div class="rw-imgbox-overlay" role="dialog" aria-modal="true">' +
					'<button type="button" class="rw-imgbox-close" aria-label="Close">' +
						'<span class="dashicons dashicons-no-alt"></span>' +
					'</button>' +
					'<img class="rw-imgbox-img" alt="" />' +
				'</div>'
			).appendTo('body');
			$overlay.find('.rw-imgbox-img').attr('src', url);
			function close() {
				$overlay.remove();
				$(document).off('keydown.rwImgbox');
			}
			$overlay.on('click', function (e) {
				if (e.target === $overlay[0] || $(e.target).hasClass('rw-imgbox-close') || $(e.target).closest('.rw-imgbox-close').length) {
					close();
				}
			});
			$(document).on('keydown.rwImgbox', function (e) {
				if (e.key === 'Escape') close();
			});
		};
	}

	// Shared confirmation dialog. Use this instead of window.confirm()
	// for destructive actions (Disconnect, etc.) so the prompt sits
	// over the page in a centered modal that's actually visible.
	// opts: { title, message, confirmLabel, cancelLabel, danger, onConfirm, onCancel }
	if (!window.rwConfirm) {
		window.rwConfirm = function (opts) {
			opts = opts || {};
			$('.rw-confirm-overlay').remove();
			var title        = opts.title        || (window.rwT ? window.rwT('confirm_title',   'Are you sure?')        : 'Are you sure?');
			var message      = opts.message      || '';
			var confirmLabel = opts.confirmLabel || (window.rwT ? window.rwT('confirm_yes',     'Yes, do it')           : 'Yes, do it');
			var cancelLabel  = opts.cancelLabel  || (window.rwT ? window.rwT('confirm_cancel',  'Cancel')               : 'Cancel');
			var danger       = !!opts.danger;
			var $overlay = $(
				'<div class="rw-confirm-overlay" role="dialog" aria-modal="true" aria-labelledby="rw-confirm-title">' +
					'<div class="rw-confirm">' +
						'<div class="rw-confirm__icon' + (danger ? ' is-danger' : '') + '">' +
							'<span class="dashicons ' + (danger ? 'dashicons-warning' : 'dashicons-info') + '"></span>' +
						'</div>' +
						'<h3 class="rw-confirm__title" id="rw-confirm-title"></h3>' +
						(message ? '<p class="rw-confirm__message"></p>' : '') +
						'<div class="rw-confirm__actions">' +
							'<button type="button" class="rw-button rw-button-outline rw-button-inline rw-confirm__cancel"></button>' +
							'<button type="button" class="rw-button rw-button-inline rw-confirm__yes' + (danger ? ' is-danger' : '') + '"></button>' +
						'</div>' +
					'</div>' +
				'</div>'
			).appendTo('body');
			$overlay.find('.rw-confirm__title').text(title);
			$overlay.find('.rw-confirm__message').text(message);
			$overlay.find('.rw-confirm__yes').text(confirmLabel);
			$overlay.find('.rw-confirm__cancel').text(cancelLabel);
			function close() {
				$overlay.remove();
				$(document).off('keydown.rwConfirm');
			}
			$overlay.find('.rw-confirm__yes').on('click', function () {
				close();
				if (typeof opts.onConfirm === 'function') opts.onConfirm();
			});
			$overlay.find('.rw-confirm__cancel').on('click', function () {
				close();
				if (typeof opts.onCancel === 'function') opts.onCancel();
			});
			$overlay.on('click', function (e) {
				if (e.target === $overlay[0]) {
					close();
					if (typeof opts.onCancel === 'function') opts.onCancel();
				}
			});
			$(document).on('keydown.rwConfirm', function (e) {
				if (e.key === 'Escape') {
					close();
					if (typeof opts.onCancel === 'function') opts.onCancel();
				} else if (e.key === 'Enter') {
					$overlay.find('.rw-confirm__yes').trigger('click');
				}
			});
			// Focus the cancel button by default - safer for destructive
			// actions (an accidental Enter doesn't trigger the danger
			// path; we explicitly bind Enter above for users who know
			// they want it).
			setTimeout(function () { $overlay.find('.rw-confirm__cancel').trigger('focus'); }, 0);
		};
	}

	// ---------------------------------------------------------------------
	// Polling
	// ---------------------------------------------------------------------
	var pollHandle = null;
	var pollMode = 'normal';
	var pollCount = 0;
	var currentStatus = null;
	var disconnecting = false;
	var accountLoaded = false;
	var account = null;
	var subAccount = (typeof ajax_var !== 'undefined' && ajax_var.subAccount) ? ajax_var.subAccount : 0;

	var POLL = {
		normal:  { interval: 30000, max: 10 },
		fast:    { interval:  5000, max: 36 },
		pending: { interval: 10000, max: 30 }
	};

	function startPoll(mode) {
		if (pollHandle) clearInterval(pollHandle);
		var cfg = POLL[mode] || POLL.normal;
		pollMode = mode;
		pollCount = 0;
		pollHandle = setInterval(function () {
			checkConnection();
			pollCount++;
			if (pollCount >= cfg.max) {
				clearInterval(pollHandle);
				pollHandle = null;
			}
		}, cfg.interval);
	}

	function checkConnection() {
		return $.post(ajax_var.url, {
			action: 'rw_check_connection',
			nonce: ajax_var.nonce
		})
			.done(function (response) {
				if (disconnecting) return;
				var status = (response && response.status) ? response.status : 'Not Connected';
				renderStatus(status);
			})
			.fail(function () {
				if (disconnecting) return;
				renderStatus('Not Connected');
			});
	}

	// Expose for rw-onboard.js to trigger after signup/login.
	window.rwStartPoll = startPoll;
	window.rwCheckConnection = checkConnection;
	// Force-refresh account/info from rw-overview.js (e.g. the setup
	// card's manual + auto refresh path). Resets the loaded flag so
	// loadAccount fires the network call, then invokes the optional
	// callback once `rw:account-loaded` fires. Failures fall through
	// silently - the next tick of the auto-refresh poller will retry.
	window.rwReloadAccount = function (cb) {
		accountLoaded = false;
		var resolved  = false;
		if (cb) {
			$(document).one('rw:account-loaded', function () {
				if (resolved) return;
				resolved = true;
				cb();
			});
			// Safety net: if account/info fails, fire the callback
			// after a short timeout so the caller can clean up
			// (e.g. clear the refresh-button spinner).
			setTimeout(function () {
				if (resolved) return;
				resolved = true;
				cb();
			}, 8000);
		}
		loadAccount();
	};

	// Trial / account-status banner. Lives above the topbar so the
	// billing status is the first thing a connected user sees on every
	// plugin page. Defined here (rather than in rw-overview.js) because
	// the topbar is rendered on Channels / Widgets / Reviews / Floating
	// too - not just the Dashboard - and the banner markup is in
	// topbar.php.
	//
	// account/info exposes `on_free_trial`:
	//   false -> not on a trial (hide banner)
	//   0    -> last day of trial
	//   N>0  -> N days remaining
	// Progress bar fills as the trial *progresses* (fresh signup ~0%,
	// last day ~100%). Denominator is account.trial_days when
	// account/info exposes it; otherwise falls back to a 14-day
	// reference so the bar still renders with a reasonable ratio.
	var RW_TRIAL_REFERENCE_DAYS = 14;
	function renderTrialBanner($banner) {
		var acc = window.rwAccount;
		if (!acc) { $banner.hide(); return; }

		// Reset state classes so the variant tracks the current state.
		$banner.removeClass('is-prominent is-disabled is-urgent is-warning is-ok');

		// Disabled overrides trial - highest priority surface.
		var isDisabled = acc.disabled == 1 || acc.disabled === '1' || acc.disabled === true;
		if (isDisabled) {
			$banner.addClass('is-prominent is-disabled is-urgent');
			$banner.find('[data-trial-headline]').text(window.rwT('account_disabled_title', 'Your Repuso account is disabled'));
			$banner.find('[data-trial-sub]').text(window.rwT('account_disabled_sub', 'Choose a plan to reactivate your account and bring your widgets back online.'));
			$banner.find('[data-trial-fill]').css('width', '0%');
			$banner.show();
			return;
		}

		// on_free_trial === false means paid plan or expired - hide.
		if (acc.on_free_trial === false || acc.on_free_trial == null) {
			$banner.hide();
			return;
		}
		var daysLeft = parseInt(acc.on_free_trial, 10);
		if (!isFinite(daysLeft) || daysLeft < 0) daysLeft = 0;

		// Setup-complete check (same heuristic as the Outreach gate).
		// When everything is set up, switch to the prominent variant
		// so the user can't miss the prompt to pick a plan.
		var setupDone = false;
		if (acc.channels && acc.widgets && acc.approved_posts) {
			var chU = parseInt(acc.channels.usage,       10) || 0;
			var wgU = parseInt(acc.widgets.usage,        10) || 0;
			var psU = parseInt(acc.approved_posts.usage, 10) || 0;
			setupDone = chU > 0 && wgU > 0 && psU > 0;
		}
		if (setupDone) $banner.addClass('is-prominent');

		// Urgency colour: green > 7d, orange 4-7d, red 0-3d.
		var urgency = daysLeft <= 3 ? 'is-urgent' : (daysLeft <= 7 ? 'is-warning' : 'is-ok');
		$banner.addClass(urgency);

		var headline = daysLeft === 0
			? window.rwT('trial_ends_today', 'Free trial ends today')
			: (daysLeft === 1
				? window.rwT('trial_one_day_left', '1 day left in your free trial')
				: window.rwTf('trial_days_left',  '%d days left in your free trial', daysLeft));
		$banner.find('[data-trial-headline]').text(headline);
		$banner.find('[data-trial-sub]').text(window.rwT(
			'trial_sub',
			'Choose your plan to avoid interruptions, remaining trial days are not billed.'
		));

		var trialDays = parseInt(acc.trial_days, 10);
		if (!isFinite(trialDays) || trialDays <= 0) trialDays = RW_TRIAL_REFERENCE_DAYS;
		var elapsed   = Math.max(0, trialDays - daysLeft);
		var pct       = Math.max(0, Math.min(100, (elapsed / trialDays) * 100));
		$banner.find('[data-trial-fill]').css('width', pct + '%');
		$banner.show();
	}
	// Exposed so refreshSetupOnly / refreshAll in rw-overview.js can
	// re-render after a manual or post-CTA refresh.
	window.rwRenderTrialBanner = function () {
		var $banner = $('[data-trial-banner]');
		if (!$banner.length) return;
		// Defer to account/info landing if it hasn't yet - calling
		// renderTrialBanner with no rwAccount just hides the banner.
		if (window.rwAccount) {
			renderTrialBanner($banner);
		} else {
			$(document).one('rw:account-loaded', function () {
				renderTrialBanner($banner);
			});
		}
	};
	// Auto-render on every fresh rw:account-loaded fire so the banner
	// reflects the live account state on every plugin page mount
	// without each section having to call into it explicitly.
	$(document).on('rw:account-loaded', function () {
		var $banner = $('[data-trial-banner]');
		if ($banner.length) renderTrialBanner($banner);
	});

	// ---------------------------------------------------------------------
	// Status pill
	// ---------------------------------------------------------------------
	function renderStatus(status) {
		var colors = {
			'Connected':     '#56a274',
			'Not Connected': '#c0392b',
			'Connecting':    '#f39c12',
			'Checking':      '#bbb'
		};
		var labels = {
			'Connected':     rwT('connected_to_repuso', 'Connected to Repuso'),
			'Not Connected': rwT('not_connected',      'Not connected'),
			'Connecting':    rwT('connecting',         'Connecting…'),
			'Checking':      rwT('checking_connection','Checking connection…')
		};

		$('#rw-status-dot').css('background', colors[status] || '#bbb');
		$('#rw-status-text').text(labels[status] || 'Status unknown');

		// First real status: drop the initial "Checking…" placeholder.
		$('#rw-checking').hide();

		// Show the status pill only on transitional states (Connecting /
		// Checking). On Not Connected the onboard cards already speak for
		// themselves; on Connected the entire view does. Showing "Connected
		// to Repuso" at the top of every section was redundant noise.
		// Disconnect click re-shows the pill so its inline confirm UI lands
		// somewhere visible.
		var $statusLine = $('#rw-status-line');
		if (status === 'Connected' || status === 'Not Connected') {
			$statusLine.hide();
		} else {
			$statusLine.show();
		}

		if (status === 'Connected') {
			$('.rw-not-logged').hide();
			$('.rw-logged').show();
			$('.rw-topnav .rw-logged').show();
			// Restore the connected-state chrome: full-width wrapper
			// (data-rw-has-apikey="1" disables the 800px reading cap)
			// and the navigation topbar with its tabs + lang + account
			// picker + disconnect action.
			$('#rw-wrapper').attr('data-rw-has-apikey', '1');
			$('.rw-topbar').show();
			if (!accountLoaded) loadAccount();
			// Runtime-login bootstrap: on a fresh page load the section
			// loaders ran at the bottom of this file's IIFE based on
			// data-rw-section. But when the user logs in WITHOUT a
			// reload (clicked through the connect screen, OTP code,
			// etc.) those loaders haven't fired yet because
			// data-rw-has-apikey was "0" at boot. Trigger them now so
			// the dashboard / sections paint without a manual refresh.
			if (!window.rwSectionBooted) {
				var bootSection = $('#rw-wrapper').data('rw-section');
				if (bootSection === 'overview' || bootSection === '' || typeof bootSection === 'undefined') {
					if (typeof window.rwLoadOverview === 'function') window.rwLoadOverview();
				} else if (bootSection === 'channels' && typeof loadChannels === 'function') {
					loadAllChannels();
					loadChannels();
				} else if (bootSection === 'reviews' && typeof loadReviews === 'function') {
					$('#rw-reviews .rw-tab').removeClass('is-current').first().addClass('is-current');
					loadReviews('/inbox');
				} else if (bootSection === 'widgets' && typeof loadWidgets === 'function') {
					loadWidgets();
				} else if (bootSection === 'floating' && typeof loadFloatingSelector === 'function') {
					loadFloatingSelector();
				}
				window.rwSectionBooted = true;
			}
		} else if (status === 'Not Connected') {
			$('.rw-logged').hide();
			$('.rw-not-logged').show();
			$('.rw-topnav .rw-logged').hide();
			// Match the fresh-install onboard chrome: re-cap the wrapper
			// to 800px via data-rw-has-apikey="0" and hide the topbar so
			// the post-disconnect view is visually identical to the view
			// a brand-new user sees on first install. (topbar.php only
			// PHP-skips rendering when apiKey was empty at page load, so
			// after a runtime disconnect the markup is still present and
			// must be hidden in JS.)
			$('#rw-wrapper').attr('data-rw-has-apikey', '0');
			$('.rw-topbar').hide();
			$('[data-trial-banner]').hide();
			accountLoaded = false;
			account = null;
			window.rwAccount = null;
			// Reset the section-boot flag so the runtime-login branch
			// in renderStatus('Connected') re-fires the section
			// loaders for the new account. Without this, signing into
			// account B after a disconnect would leave the DOM showing
			// account A's previously-rendered widgets / channels /
			// dashboard until a manual page refresh.
			window.rwSectionBooted = false;
			// Same idea for the Dashboard's internal hasBooted flag in
			// rw-overview.js: reset so the next refreshAll uses
			// fresh-boot semantics (keeps the setup placeholder
			// visible during loading) instead of sub-account-switch
			// semantics (which would hide it and leave the page blank
			// until probes return).
			if (typeof window.rwResetOverviewBoot === 'function') window.rwResetOverviewBoot();
			// Drop the per-account response cache so a future reconnect
			// (possibly with a different API key) doesn't briefly
			// render this account's numbers.
			if (window.rwCache) window.rwCache.invalidate();
			try {
				var li = window.localStorage.length;
				while (li--) {
					var lk = window.localStorage.key(li);
					if (lk && lk.indexOf('rw_setup_state_') === 0) {
						window.localStorage.removeItem(lk);
					}
				}
			} catch (e) { /* private mode / quota */ }
			// Stop pending poll cycles. We'll restart fast mode on user action.
			if (pollHandle && currentStatus === 'Connected') {
				clearInterval(pollHandle);
				pollHandle = null;
			}
		}

		if (status === 'Connected' && pollMode === 'fast') {
			// Terminal success; stop the fast poll.
			if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
		}

		currentStatus = status;
	}

	// ---------------------------------------------------------------------
	// Connected view bootstrap
	// ---------------------------------------------------------------------
	function loadAccount() {
		accountLoaded = true;
		hookGet('account/info').done(function (response, status, xhr) {
			if (!response || !response.widgets) {
				accountLoaded = false;
				var diag = '';
				if (response == null) {
					diag = '(empty response)';
				} else if (typeof response === 'string') {
					diag = 'String response (first 200 chars): ' + response.substring(0, 200);
				} else if (response._proxy_error) {
					// Surfaced by the PHP hook proxy when wp_remote_request
					// errored or returned an empty body. Includes the
					// upstream URL + transport message so we can tell where
					// the round-trip actually died.
					diag = 'Proxy: ' + response._proxy_message
					     + ' / status: ' + (response._upstream_status || 'n/a')
					     + ' / url: ' + (response._upstream_url || 'n/a');
				} else {
					diag = 'Response keys: ' + Object.keys(response).slice(0, 30).join(', ');
				}
				showError('Problem getting account information. ' + diag);
				return;
			}
			account = response;
			// Share with rw-overview.js (and any other module) so they
			// don't have to re-fetch account/info just to read the
			// invite-enabled / plan flags or websites array.
			window.rwAccount = account;
			$(document).trigger('rw:account-loaded', [account]);

			// Forward usage stats so the 5-star review nag gates correctly
			// AND the global WP admin trial/disabled notice has fresh
			// numbers to render (the notice fires on every admin page
			// view but reads only from these stored WP options - no
			// API round-trip - so we update them here whenever the
			// plugin page itself loads account/info).
			var posts        = (account.approved_posts && account.approved_posts.usage) || 0;
			var channels     = (account.channels && account.channels.usage) || 0;
			var widgetsUsage = (account.widgets && account.widgets.usage) || 0;
			// Actual days left, not a boolean. -1 when not on trial
			// (account.on_free_trial === false). PHP keeps the boolean
			// rw_trial for the 5-star nag's existing logic and reads
			// the new rw_trial_days_left for the admin notice.
			var trialDaysLeft = (account.on_free_trial === false || account.on_free_trial == null)
				? -1
				: parseInt(account.on_free_trial, 10);
			if (!isFinite(trialDaysLeft)) trialDaysLeft = -1;
			var trialDaysTotal = parseInt(account.trial_days, 10) || 0;
			var disabled       = (account.disabled == 1 || account.disabled === '1' || account.disabled === true) ? 1 : 0;
			$.post(ajax_var.url, {
				action: 'rw_store_info',
				posts:             posts,
				widgets:           widgetsUsage,
				channels:          channels,
				on_free_trial:     account.on_free_trial ? 1 : 0,
				trial_days_left:   trialDaysLeft,
				trial_days_total:  trialDaysTotal,
				account_disabled:  disabled,
				nonce: ajax_var.nonce
			});

			renderSubaccounts();
			// Skip the section reload if boot already fired it directly
			// (decoupled-loading path). It runs here only on subsequent
			// loadAccount cycles where we still need to refresh the
			// current section after a context change. Reset for next time.
			if (window.rwSectionBooted) {
				window.rwSectionBooted = false;
			} else {
				loadCurrentSection();
			}
		}).fail(function (xhr, status, errText) {
			accountLoaded = false;
			// Aborts are benign - rwQueue.abort() is called by
			// rw-overview.js's refreshAll() to cancel boot-time requests
			// before re-issuing them, so the client-side cancel is
			// expected. Don't surface a scary error for it; the new batch
			// will re-fetch account/info shortly.
			if (status === 'abort' || (xhr && xhr.status === 0 && status !== 'timeout')) {
				return;
			}
			// Surface the actual HTTP status + response so we can tell
			// nonce failures (403) from CORS / 5xx / timeout / etc.
			var body = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 200) : '(no body)';
			showError('Problem getting account information. Status: ' + (xhr && xhr.status) + ' / ' + status + ' / body: ' + body);
		});
	}

	function renderSubaccounts() {
		var $select = $('#rw-subaccounts #accounts').empty();
		var uw      = account.user_websites || [];
		var isAdmin = !!account.is_admin;
		var name    = account.company_name || '';

		// Main account ("All / Company-wide" position 0) when the user
		// has access to it. company_name is required; we don't fall back
		// to the WP site name because that's not a real account.
		if ((isAdmin || uw.indexOf(0) !== -1) && name) {
			$select.append($('<option>').val('0').text(name));
		}
		(account.websites || []).forEach(function (w) {
			if (isAdmin || uw.indexOf(w.id) !== -1) {
				$select.append($('<option>').val(w.id).text(w.name));
			}
		});

		// Hide the picker when there's nothing meaningful to switch
		// between (zero or one accessible accounts).
		if ($select.find('option').length < 2) {
			$('#rw-subaccounts').hide();
			return;
		}

		$select.val(String(subAccount));
		subAccount = $select.val();
		$('#rw-subaccounts').show();

		// Auto-apply on change (no submit button in the new design).
		$select.off('change').on('change', function () {
			subAccount = $select.val();
			$.post(ajax_var.url, {
				action: 'rw_store_subaccount',
				account: subAccount,
				nonce: ajax_var.nonce
			});
			loadCurrentSection();
		});
	}

	function loadCurrentSection() {
		var section = $('#rw-wrapper').data('rw-section');
		if (section === 'overview') {
			// Overview page is owned by js/rw-overview.js; tell it to
			// refresh with the current sub-account. No-ops when the
			// overview script isn't loaded (other pages).
			if (typeof window.rwLoadOverview === 'function') {
				window.rwLoadOverview(subAccount);
			}
		} else if (section === 'channels') {
			loadAllChannels();
			loadChannels();
		} else if (section === 'reviews') {
			$('#rw-reviews .rw-tab').removeClass('is-current').first().addClass('is-current');
			loadReviews('/inbox');
		} else if (section === 'floating') {
			loadFloatingSelector();
		} else {
			loadWidgets();
		}
	}

	// Populate the floating-widget selector on tmpl/floating.php with the
	// account's floating-style widgets only (floating / flash / badge1 / badge2).
	// On change we mirror the id+type into hidden inputs that the PHP save
	// handler turns into the legacy repuso_js_code shortcode.
	function loadFloatingSelector() {
		var $select = $('#rw-floating-select');
		if (!$select.length) return;
		var $loading = $('#rw-floating-loading');
		var $empty   = $('#rw-floating-empty');
		var floatingTypes = { floating: 1, flash: 1, badge1: 1, badge2: 1 };
		var typeLabels = {
			floating: 'Floating',
			flash:    'Flash',
			badge1:   'Floating badge 1',
			badge2:   'Floating badge 2'
		};

		$loading.show();
		$select.hide();
		$empty.hide();

		hookGet('widgets?website=' + encodeURIComponent(subAccount))
			// Always-fires path: clear the spinner even if .done crashes
			// (non-array response, etc.) so the user is never stuck on it.
			.always(function () { $loading.hide(); })
			.done(function (response) {
				var saved = String($select.data('saved-id') || '');
				// API may return [] for "no widgets" but can also return a
				// proxy-error object or null when something upstream
				// hiccuped. Treat anything non-array as "no widgets" so the
				// empty state renders instead of throwing on .filter.
				var list = Array.isArray(response) ? response : [];
				var matches = list.filter(function (w) { return w && floatingTypes[w.type]; });

				if (matches.length === 0) {
					$empty.show();
					// Clear any stale hidden values so save doesn't keep
					// pointing at a widget that no longer exists.
					$('#rw-floating-widget-id').val('');
					$('#rw-floating-widget-type').val('');
					return;
				}

				$select.empty();
				$select.append('<option value="">' + esc('None - disable floating widget') + '</option>');
				matches.forEach(function (w) {
					var label = (w.description ? w.description + ' · ' : '') + (typeLabels[w.type] || w.type);
					var $opt = $('<option></option>')
						.attr('value', String(w.id))
						.attr('data-widget-type', w.type)
						.text(label);
					if (String(w.id) === saved) $opt.attr('selected', 'selected');
					$select.append($opt);
				});
				$select.prop('disabled', false).show();
				syncFloatingHidden();
			})
			.fail(function () {
				$select.empty()
					.append('<option value="">' + esc('Could not load widgets - try refreshing.') + '</option>')
					.show();
			});
	}

	function syncFloatingHidden() {
		var $select = $('#rw-floating-select');
		var $opt = $select.find('option:selected');
		$('#rw-floating-widget-id').val($select.val() || '');
		$('#rw-floating-widget-type').val($opt.attr('data-widget-type') || '');
	}

	// ---------------------------------------------------------------------
	// Section loaders
	// ---------------------------------------------------------------------
	// Spin the per-section Refresh button while its loader is in flight.
	// Each section has a `.rw-section__refresh` icon in its header with
	// a section data-attribute (widgets/channels/reviews).
	function setSectionRefreshing(section, busy) {
		$('.rw-section__refresh[data-section="' + section + '"]').toggleClass('is-spinning', !!busy);
	}

	// Loading placeholder swapped into a section's [data-list] while its
	// async fetch is in flight. Used on tab switches and initial loads so
	// the user sees activity instead of a frozen list.
	function sectionLoading() {
		return '<div class="rw-section__loading" aria-live="polite"><span class="rw-section__spinner"></span></div>';
	}

	// Card-style empty-state markup, reused by all three sections.
	// Access-denied state for sections the current role can't use.
	// Regular Users (role_id=2) can view dashboards and reviews but
	// can't connect channels or create widgets - matches the web
	// dashboard, which simply doesn't expose those routes to them.
	// We keep the menu items visible (consistent navigation) and
	// surface this card in place of the section content.
	function accessDeniedState(sectionLabel) {
		var title = rwT('section_no_access_title', 'Not available for your role');
		var body  = (rwT('section_no_access_body',
				'Your account role doesn\'t have access to %s. Ask an admin on your Repuso account to grant access, or sign in with an admin user.'))
				.replace('%s', sectionLabel);
		return (
			'<div class="rw-empty">' +
				'<svg class="rw-empty__icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">' +
					'<rect x="10" y="20" width="28" height="20" rx="2" stroke="#d4d4d8" stroke-width="2"/>' +
					'<path d="M16 20v-5a8 8 0 0 1 16 0v5" stroke="#d4d4d8" stroke-width="2" stroke-linecap="round"/>' +
					'<circle cx="24" cy="30" r="2" fill="#d4d4d8"/>' +
				'</svg>' +
				'<h3 class="rw-empty__title">' + esc(title) + '</h3>' +
				'<p class="rw-empty__body">' + esc(body) + '</p>' +
			'</div>'
		);
	}

	function emptyState(title, body, ctaText) {
		var ctaHtml = ctaText
			? '<a href="#" class="rw-button rw-button-primary rw-button-inline rw-open-dashboard">' + esc(ctaText) + ' <span class="dashicons dashicons-external"></span></a>'
			: '';
		return (
			'<div class="rw-empty">' +
				'<svg class="rw-empty__icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">' +
					'<rect x="8" y="10" width="32" height="28" rx="4" stroke="#d4d4d8" stroke-width="2"/>' +
					'<path d="M14 20h20M14 26h20M14 32h12" stroke="#d4d4d8" stroke-width="2" stroke-linecap="round"/>' +
				'</svg>' +
				'<h3 class="rw-empty__title">' + esc(title) + '</h3>' +
				'<p class="rw-empty__body">' + esc(body) + '</p>' +
				ctaHtml +
			'</div>'
		);
	}

	function loadWidgets(opts) {
		opts = opts || {};
		// Role gate: regular Users can't create or edit widgets on the
		// web dashboard, so the listing is pointless for them. Defer
		// the decision until rwAccount is known; rendering the
		// access-denied card optimistically would flash for admins
		// during the initial account/info round-trip.
		window.rwRoles.whenResolved(function () {
			if (!window.rwRoles.isAdminOrEditor()) {
				$('#rw-widgets [data-list]').html(accessDeniedState(rwT('widgets', 'Widgets')));
				$('#rw-widgets').show();
				setSectionRefreshing('widgets', false);
				return;
			}
			loadWidgetsInner(opts);
		});
	}
	function loadWidgetsInner(opts) {
		setSectionRefreshing('widgets', true);
		window.rwCache.staleWhileRevalidate(
			window.rwCache.cacheKey('widgets', subAccount, ''),
			function () { return hookGet('widgets?website=' + encodeURIComponent(subAccount)); },
			function (response, fromCache) {
			var $list = $('#rw-widgets [data-list]').empty();
			var selectedFloatingId = String(ajax_var.floatingWidgetId || '');
			if (response && response.length > 0) {
				response.forEach(function (w) {
					var shortcode = w.type !== 'email1' ? '[rw_' + w.type + ' id="' + w.id + '"]' : '';
					var isSelected = selectedFloatingId && String(w.id) === selectedFloatingId;
					var selectedBadge = isSelected
						? '<span class="rw-row__badge" title="' + escAttr(rwT('selected_for_site_hint', 'This widget is set as the floating widget for this site.')) + '">' +
							'<span class="dashicons dashicons-yes" aria-hidden="true"></span>' +
							esc(rwT('selected_for_site', 'Selected for site')) +
						  '</span>'
						: '';
					$list.append(
						'<div class="rw-row rw-row-widget' + (isSelected ? ' is-selected' : '') + '">' +
							'<img class="rw-row__icon" src="https://app.thereviewsplace.com/images/icon-' + escAttr(w.type) + '.png" alt="">' +
							'<div class="rw-row__main">' +
								'<div class="rw-row__name">' +
									esc(w.description || w.type) +
									selectedBadge +
								'</div>' +
								(shortcode
									? '<button type="button" class="rw-row__shortcode rw-copy" data-copy="' + escAttr(shortcode) + '" title="' + escAttr(rwT('click_to_copy', 'Click to copy')) + '">' +
											'<span class="rw-copy__text">' + esc(shortcode) + '</span>' +
											'<span class="rw-copy__icon dashicons dashicons-admin-page" aria-hidden="true"></span>' +
										'</button>'
									: '') +
							'</div>' +
							'<div class="rw-row__actions">' +
								'<a href="#" class="rw-action rw-preview" data-widget-id="' + escAttr(w.id) + '"><span class="rw-link-text">' + esc(rwT('preview', 'Preview')) + '</span></a>' +
								'<a href="#" class="rw-action rw-preview-code" data-widget-id="' + escAttr(w.id) + '"><span class="rw-link-text">' + esc(rwT('full_code', 'Full code')) + '</span></a>' +
								'<a href="' + escAttr(ajax_var.appUrl + '#/widgets/' + w.id) + '" target="_blank" rel="noopener" class="rw-action rw-edit-widget rw-open-dashboard" data-rw-path="' + escAttr('/widgets/' + w.id) + '" title="' + escAttr(rwT('edit_on_dashboard', 'Edit on Repuso dashboard')) + '">' +
									'<span class="rw-link-text">' + esc(rwT('edit', 'Edit')) + '</span>' +
									'<span class="dashicons dashicons-external" aria-hidden="true"></span>' +
								'</a>' +
							'</div>' +
						'</div>'
					);
				});
			} else {
				$list.append(emptyState(
					rwT('no_widgets_title', 'No widgets yet'),
					rwT('no_widgets_body',  'Create your first widget on the Repuso dashboard, then embed it here.'),
					rwT('create_widget',    'Create widget')
				));
			}
			$('#rw-widgets').show();
			},
			{ force: !!opts.force }
		).always(function () { setSectionRefreshing('widgets', false); });
	}

	function loadAllChannels() {
		// Same role gate as loadChannels - this is the "all supported
		// platforms" footer probe, which is just supporting data for
		// the Channels page and serves no purpose for regular Users.
		window.rwRoles.whenResolved(function () {
			if (!window.rwRoles.isAdminOrEditor()) return;
			loadAllChannelsInner();
		});
	}
	function loadAllChannelsInner() {
		hookPost('channels/all').done(function (response) {
			var $box = $('#rw-channels #all').empty();
			// /v1/channels/all returns Variables::$channels keyed by type
			// ({facebook: {...}, google: {...}}) which JSON-encodes as an
			// object, not an array. Accept that shape too, alongside the
			// historical bare-array / wrapped variants.
			var list = [];
			if (Array.isArray(response)) {
				list = response;
			} else if (response && Array.isArray(response.items)) {
				list = response.items;
			} else if (response && Array.isArray(response.data)) {
				list = response.data;
			} else if (response && typeof response === 'object') {
				list = Object.keys(response).map(function (k) { return response[k]; });
			}
			list.forEach(function (c) {
				if (c && c.key !== 15 && c.logo) {
					$box.append('<img class="rw-channel-pill" title="' + escAttr(c.label || c.name || '') + '" src="' + escAttr(c.logo) + '" alt="">');
				}
			});
		});
	}

	function loadChannels(opts) {
		opts = opts || {};
		window.rwRoles.whenResolved(function () {
			if (!window.rwRoles.isAdminOrEditor()) {
				$('#rw-channels [data-list]').html(accessDeniedState(rwT('channels', 'Channels')));
				$('#rw-channels').show();
				setSectionRefreshing('channels', false);
				return;
			}
			loadChannelsInner(opts);
		});
	}
	function loadChannelsInner(opts) {
		setSectionRefreshing('channels', true);
		window.rwCache.staleWhileRevalidate(
			window.rwCache.cacheKey('channels', subAccount, ''),
			function () { return hookGet('channels?website=' + encodeURIComponent(subAccount)); },
			function (response, fromCache) {
			var $list = $('#rw-channels [data-list]').empty();
			if (response && response.count > 0 && response.items) {
				// Group channels by property_id so the list mirrors the
				// web dashboard's channels.list.html structure. Property
				// 0 / null is the "Ungrouped" bucket and renders without
				// a header at the top. If the account has no properties
				// at all, every channel ends up in that bucket and the
				// list reads exactly as it did before grouping.
				var groups = {};
				var order  = [];
				response.items.forEach(function (c) {
					var pid = c.property_id ? parseInt(c.property_id, 10) : 0;
					if (!groups[pid]) {
						groups[pid] = {
							id: pid,
							name: c.propertyName || c.property_name || '',
							channels: []
						};
						order.push(pid);
					}
					groups[pid].channels.push(c);
				});
				// Ungrouped bucket first, then named properties.
				order.sort(function (a, b) {
					if (a === 0) return -1;
					if (b === 0) return 1;
					return a - b;
				});

				var hasNamedGroup = order.some(function (pid) { return pid > 0 && groups[pid].name; });

				order.forEach(function (pid) {
					var group = groups[pid];
					if (pid > 0 && group.name) {
						$list.append(
							'<div class="rw-channel-group__head">' +
								'<span class="rw-channel-group__name">' + esc(group.name) + '</span>' +
							'</div>'
						);
					} else if (hasNamedGroup) {
						$list.append(
							'<div class="rw-channel-group__head rw-channel-group__head--default">' +
								'<span class="rw-channel-group__name">' + esc('Ungrouped') + '</span>' +
							'</div>'
						);
					}
					group.channels.forEach(function (c) {
						var rating = c.official_score > 0
							? '<div class="rw-row__rating">' +
									'<span class="rw-stars" data-score="' + escAttr(c.official_score) + '">' +
										renderStars(c.official_score) +
									'</span>' +
									'<span class="rw-row__score">' + esc(c.official_score) + '</span>' +
									'<span class="rw-row__count">(' + esc(c.official_num_reviews) + ')</span>' +
								'</div>'
							: '<div class="rw-row__rating rw-row__rating-empty">No rating yet</div>';
						var editPath = '/channels/' + c.id;
						$list.append(
							'<div class="rw-row rw-row-channel">' +
								'<img class="rw-row__icon" src="' + escAttr(c.logo) + '" alt="">' +
								'<div class="rw-row__main">' +
									'<div class="rw-row__name">' + esc(c.name) + '</div>' +
								'</div>' +
								rating +
								'<div class="rw-row__actions">' +
									'<a href="' + escAttr(ajax_var.appUrl + '#/channels/' + c.id) + '" target="_blank" rel="noopener" class="rw-action rw-edit-channel rw-open-dashboard" data-rw-path="' + escAttr(editPath) + '" title="Edit on Repuso dashboard">' +
										'<span class="rw-link-text">Edit</span>' +
										'<span class="dashicons dashicons-external" aria-hidden="true"></span>' +
									'</a>' +
								'</div>' +
							'</div>'
						);
					});
				});
			} else {
				$list.append(emptyState(
					rwT('no_channels_title', 'No channels yet'),
					rwT('no_channels_body',  'Connect your first review platform on the Repuso dashboard to start collecting reviews.'),
					rwT('connect_channel',   'Connect a channel')
				));
			}
			$('#rw-channels').show();
			},
			{ force: !!opts.force }
		).always(function () { setSectionRefreshing('channels', false); });
	}

	function loadReviews(path, opts) {
		opts = opts || {};
		if (path == null) path = '/inbox';
		var $list = $('#rw-reviews [data-list]');
		// Only show the inline spinner on a true cold load. Cache hits
		// repaint instantly so the spinner is visual noise.
		var cacheRangeKey = path.replace(/^\//, '') || 'approved';
		if (!window.rwCache.read(window.rwCache.cacheKey('reviews', subAccount, cacheRangeKey))) {
			$list.html(sectionLoading());
		}
		$('#rw-reviews').show();
		setSectionRefreshing('reviews', true);
		window.rwCache.staleWhileRevalidate(
			window.rwCache.cacheKey('reviews', subAccount, cacheRangeKey),
			function () { return hookGet('posts' + path + '?limit=100&website=' + encodeURIComponent(subAccount)); },
			function (response, fromCache) {
				$list = $('#rw-reviews [data-list]');
			$list.empty();
			if (response && response.count > 0 && response.items) {
				response.items.forEach(function (p) {
					var d = new Date(p.posted_on);
					var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
					var date = d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
					var source = 'https://widgets.thereviewsplace.com/2.0/images/60x60/logo-' + p.type + '.png';
					var rating = p.rating_scale > 0
						? '<span class="rw-stars">' + renderStars(p.rating_value, p.rating_scale) + '</span>'
						: '';

					// Tags / property chips (same pattern as latest reviews).
					var tags = Array.isArray(p.tags) ? p.tags : [];
					var tagsHtml = '';
					tags.forEach(function (t) {
						if (t && t.name) tagsHtml += '<span class="rw-tag-item">' + escAttr(t.name) + '</span>';
					});
					if (p.property_name || p.propertyName) {
						tagsHtml += '<span class="rw-tag-property">' + escAttr(p.property_name || p.propertyName) + '</span>';
					}

					// Reply / AI suggest. Replied badge replaces the action
					// row when the user has already replied; otherwise show
					// the reply link (if reply_url provided) and the AI
					// suggest reply button (gated on AI plan).
					var replyUrl = p.reply_url || '';
					// API canonical field is `reply` (the reply text). Keep
					// the legacy `has_reply` / `replied` fallbacks in case
					// older endpoints surface them, but the truthy
					// non-empty `p.reply` is what actually shows up today.
					var hasReply = !!(
						(p.reply && String(p.reply).trim().length) ||
						p.has_reply ||
						p.replied
					);
					var replyActions = '';
					if (hasReply) {
						replyActions =
							'<span class="rw-latest__replied-badge">' +
								'<span class="dashicons dashicons-yes-alt"></span> Replied' +
							'</span>';
					} else {
						if (replyUrl) {
							replyActions +=
								'<a class="rw-latest__reply-link" href="' + escAttr(replyUrl) + '" target="_blank" rel="noopener">' +
									'<span class="dashicons dashicons-format-chat"></span> <span class="rw-link-text">Reply</span>' +
								'</a>';
						}
						// AI suggest reply: show when AI is available, OR
					// when the user is an admin (admins see the upgrade
					// prompt). Matches the web's `(aiEnabled() || isAdmin())`.
					// When account/info hasn't landed yet (boot race -
					// loadReviews fires in parallel with loadAccount),
					// render optimistically; an rw:account-loaded
					// post-pass below removes the buttons if the user
					// turns out to be a regular User on a non-AI plan.
					var aiAcc        = window.rwAccount;
					var rolesUnknown = !aiAcc;
					var isAdminUser  = !!(aiAcc && aiAcc.is_admin);
					if (rolesUnknown || aiPlanEnabledForReviews() || isAdminUser) {
							replyActions +=
								'<a class="rw-latest__ai-suggest ai-link" data-ai-suggest href="#">' +
									'<span class="rw-ai-icon"></span> <span class="rw-link-text">AI suggest reply</span>' +
								'</a>' +
								'<span class="rw-latest__ai-loading" data-ai-loading style="display:none;">' +
									'<span class="dashicons dashicons-update"></span> Suggesting…' +
								'</span>';
						}
					}

					$list.append(
						'<div class="rw-review" data-review-id="' + escAttr(p.id) + '" data-status="' + esc(p.status) + '">' +
							'<div class="rw-review__head">' +
								'<img class="rw-review__avatar" src="' + escAttr(p.from_image || source) + '" onerror="this.src=\'' + escAttr(source) + '\'" alt="">' +
								'<div class="rw-review__who">' +
									'<div class="rw-review__name">' + esc(p.from_name || '') + '</div>' +
									'<div class="rw-review__meta">' +
										'<img class="rw-review__source" src="' + escAttr(source) + '" alt=""> ' +
										'<span>' + esc(date) + '</span>' +
									'</div>' +
								'</div>' +
								(rating ? '<div class="rw-review__rating">' + rating + '</div>' : '') +
							'</div>' +
							'<div class="rw-review__text">' + safeReviewText(p.text || '') + '</div>' +
							renderReviewMedia(p) +
							// Inline AI reply panel (hidden until suggestAiReply
							// populates it). Matches the latest-reviews layout
							// so the same suggestAiReply helper works here.
							'<div class="rw-latest__ai-reply" data-ai-reply style="display:none;">' +
								'<div class="rw-latest__ai-reply-text" data-ai-reply-text contenteditable></div>' +
								'<a class="rw-latest__ai-reply-copy" href="#" data-copy-reply>' +
									'<span class="dashicons dashicons-clipboard"></span> <span class="rw-link-text">Copy reply</span>' +
								'</a>' +
							'</div>' +
							'<div class="rw-latest__ai-reply rw-latest__ai-reply-error" data-ai-reply-error style="display:none;">' +
								'<span class="dashicons dashicons-warning"></span> <span data-ai-reply-error-text></span>' +
							'</div>' +
							(tagsHtml ? '<div class="rw-review__tags">' + tagsHtml + '</div>' : '') +
							'<div class="rw-review__actions">' +
								'<div class="rw-review__reply-actions">' + replyActions + '</div>' +
								'<div class="rw-review__mod-actions">' +
									'<span class="rw-tooltiper">' +
										'<button type="button" class="rw-action-pill rw-reject status' + esc(p.status) + '" data-post-id="' + escAttr(p.id) + '" data-status="2" aria-label="' + escAttr(rwT('dismiss_tooltip', 'Dismiss (hidden in widgets)')) + '">' +
											'<svg class="rw-mod-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="8" y1="12" x2="16" y2="12"/></svg> <span class="rw-link-text">' + esc(rwT('dismiss', 'Dismiss')) + '</span>' +
										'</button>' +
										'<span class="rw-tooltipertext">' + esc(rwT('dismiss_tooltip', 'Dismiss (hidden in widgets)')) + '</span>' +
									'</span>' +
									'<span class="rw-tooltiper">' +
										'<button type="button" class="rw-action-pill rw-approve status' + esc(p.status) + '" data-post-id="' + escAttr(p.id) + '" data-status="1" aria-label="' + escAttr(rwT('approve_tooltip', 'Approve (displayed in widgets)')) + '">' +
											'<svg class="rw-mod-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="8 12 11 15 16 9"/></svg> <span class="rw-link-text">' + esc(rwT('approve', 'Approve')) + '</span>' +
										'</button>' +
										'<span class="rw-tooltipertext">' + esc(rwT('approve_tooltip', 'Approve (displayed in widgets)')) + '</span>' +
									'</span>' +
								'</div>' +
							'</div>' +
						'</div>'
					);
				});
			} else {
				$list.append(emptyState(
					rwT('no_reviews_title', 'No reviews yet'),
					rwT('no_reviews_body',  'When customers leave reviews on your connected channels they appear here for you to approve.'),
					''
				));
			}
			},
			{ force: !!opts.force }
		).fail(function () {
			$list = $('#rw-reviews [data-list]');
			$list.html(emptyState(
				rwT('could_not_load_title', 'Could not load reviews'),
				rwT('could_not_load_body',  'Something went wrong fetching reviews. Try switching tabs again or reload the page.'),
				''
			));
		}).always(function () { setSectionRefreshing('reviews', false); });
	}

	// Render a 0..5 rating using a "fill-over-base" technique: a gray row of
	// 5 stars sits underneath, and an orange row clipped to the rating
	// percentage sits on top. Both layers use the same ★ glyph so the half-
	// filled state is visually consistent with the full and empty states
	// (the WP `dashicons-star-half` glyph rendered noticeably lighter than
	// its sibling icons).
	function renderStars(score, scale) {
		var normalized = (scale && scale !== 5) ? (Number(score) / Number(scale)) * 5 : Number(score);
		if (!isFinite(normalized) || normalized < 0) normalized = 0;
		if (normalized > 5) normalized = 5;
		var pct = (normalized / 5) * 100;
		return (
			'<span class="rw-stars-base">★★★★★</span>' +
			'<span class="rw-stars-fill" style="width:' + pct + '%">★★★★★</span>'
		);
	}

	function updateReviewStatus(id, status) {
		hookRequest({
			path: 'posts/' + id,
			method: 'PUT',
			body: { status: status },
			useAuth: true,
			extraHeaders: { 'Content-Type': 'application/json' }
		});
	}

	// Lightweight modal we own end-to-end. Thickbox binds its handlers on
	// DOMContentLoaded which means our dynamically-appended links never
	// triggered it (the modal opened empty). Building our own also matches
	// the rest of the admin's visual language.
	function rwModal(title, bodyHtml, opts) {
		opts = opts || {};
		$('.rw-modal-overlay').remove(); // close any existing modal first
		var $overlay = $(
			'<div class="rw-modal-overlay" role="dialog" aria-modal="true">' +
				'<div class="rw-modal' + (opts.wide ? ' rw-modal-wide' : '') + '">' +
					'<div class="rw-modal__head">' +
						'<h3 class="rw-modal__title"></h3>' +
						'<button type="button" class="rw-modal__close" aria-label="Close">' +
							'<span class="dashicons dashicons-no-alt"></span>' +
						'</button>' +
					'</div>' +
					'<div class="rw-modal__body"></div>' +
				'</div>' +
			'</div>'
		).appendTo('body');

		$overlay.find('.rw-modal__title').text(title);
		$overlay.find('.rw-modal__body').html(bodyHtml);

		function close() {
			$overlay.remove();
			$(document).off('keydown.rwModal');
		}
		$overlay.find('.rw-modal__close').on('click', close);
		$overlay.on('click', function (e) {
			if (e.target === $overlay[0]) close();
		});
		$(document).on('keydown.rwModal', function (e) {
			if (e.key === 'Escape') close();
		});

		return $overlay;
	}

	function previewWidget(id, showCode) {
		// Show the modal immediately with a spinner so the UI feels responsive.
		var $overlay = rwModal(
			showCode ? rwT('widget_code_title', 'Widget code') : rwT('widget_preview_title', 'Widget preview'),
			'<div class="rw-modal__loading"><span class="rw-onboard__spinner"></span></div>',
			{ wide: !showCode }
		);

		hookGet('widgets/' + id + '/html').done(function (response) {
			var html = (response && response.html) ? response.html : '';
			if (!html) {
				$overlay.find('.rw-modal__body').html(
					'<div class="rw-modal__empty">' + esc(rwT('widget_no_preview', 'No preview available.')) + '</div>'
				);
				return;
			}

			if (showCode) {
				// Show the raw embed code in a read-only textarea + copy button.
				$overlay.find('.rw-modal__body').html(
					'<textarea class="rw-modal__code" readonly></textarea>' +
					'<button type="button" class="rw-button rw-button-primary rw-button-inline rw-copy" data-copy-textarea>' +
						'<span class="dashicons dashicons-admin-page" aria-hidden="true"></span> ' + esc(rwT('copy_code', 'Copy code')) +
					'</button>'
				);
				$overlay.find('.rw-modal__code').val(html);
			} else {
				// Live preview: write the widget HTML into a sandboxed iframe.
				$overlay.find('.rw-modal__body').html('<iframe class="rw-modal__iframe" frameborder="0"></iframe>');
				var iframe = $overlay.find('.rw-modal__iframe')[0];
				if (iframe) {
					var doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
					if (doc) {
						doc.open();
						doc.write(html);
						doc.close();
					}
				}
			}
		}).fail(function () {
			$overlay.find('.rw-modal__body').html(
				'<div class="rw-modal__empty">' + esc(rwT('widget_load_failed', 'Could not load the widget. Please try again.')) + '</div>'
			);
		});
	}

	// Copy text to the clipboard with a fallback for older browsers, and
	// flash a "Copied!" pill on the source element so the user sees feedback.
	function rwCopyText(text, $source) {
		var done = function () {
			if (!$source || !$source.length) return;
			$source.addClass('is-copied');
			var $pill = $('<span class="rw-copied-pill">Copied!</span>').appendTo($source);
			setTimeout(function () {
				$source.removeClass('is-copied');
				$pill.remove();
			}, 1400);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () { /* swallow */ });
			return;
		}
		// Fallback: hidden textarea + execCommand
		var $ta = $('<textarea>').css({ position: 'fixed', top: '-9999px' }).val(text).appendTo('body');
		$ta[0].select();
		try { document.execCommand('copy'); done(); } catch (e) { /* swallow */ }
		$ta.remove();
	}

	// Open a dashboard URL in a new tab, magic-link logging the user in
	// first. The optional `path` is forwarded to the server, which appends
	// it as ?next= on the /login/{apiKey} URL; SigninCtrl reads that and
	// routes to it post-login. So a plugin link can deep-link straight to
	// e.g. /widgets/123 even if the user has no active session cookie.
	//
	// Popup-safety: we have to call window.open() *synchronously* in the
	// click handler, before the AJAX round-trip. Otherwise Chrome/Safari
	// flag the post-AJAX open as a popup and block it (the user gesture
	// has lapsed by then). We open a placeholder tab immediately and
	// rewrite its location when the magic URL comes back.
	function openDashboard(e) {
		if (e) e.preventDefault();
		var path = '';
		var target = (e && e.currentTarget) || this;
		if (target) {
			path = $(target).attr('data-rw-path') || '';
		}
		var newWin = window.open('about:blank', '_blank');
		$.post(ajax_var.url, { action: 'rw_get_login_url', nonce: ajax_var.nonce, path: path })
			.done(function (response) {
				if (newWin) {
					newWin.location = response.loginUrl;
				} else {
					// Popup was blocked despite the sync open - fall back
					// to navigating the current tab so the user still
					// gets where they wanted to go.
					window.location.href = response.loginUrl;
				}
			})
			.fail(function () { if (newWin) newWin.close(); });
	}

	// ---------------------------------------------------------------------
	// Disconnect (inline "Are you sure?" confirm)
	// ---------------------------------------------------------------------
	function doDisconnect() {
		disconnecting = true;
		$('#rw-disconnect-confirm').hide();
		if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }

		$.post(ajax_var.url, { action: 'rw_disconnect', nonce: ajax_var.nonce })
			.always(function () {
				disconnecting = false;
				renderStatus('Not Connected');
			});
	}

	// ---------------------------------------------------------------------
	// hook proxy helpers
	// ---------------------------------------------------------------------
	function hookRequest(opts) {
		var headers = $.extend({}, opts.extraHeaders || {});
		if (opts.useAuth !== false) headers.Authorization = 'Yes';
		var data = {
			action:  'hook',
			nonce:   ajax_var.nonce,
			path:    opts.path,
			method:  opts.method || 'GET',
			body:    opts.body || {},
			headers: headers
		};
		if (opts.returnPlain) data['return'] = 'plain';
		// Routes through the global rwQueue so concurrency stays bounded.
		// $.ajax (not $.post) + explicit timeout so a stalled admin-ajax
		// surfaces as a .fail at 25s instead of hanging forever.
		return window.rwQueue.enqueue({
			url:     ajax_var.url,
			type:    'POST',
			timeout: 25000,
			data:    data
		});
	}
	function hookGet(path)  { return hookRequest({ path: path, method: 'GET'  }); }
	function hookPost(path, body) { return hookRequest({ path: path, method: 'POST', body: body }); }

	// Mirrors rw-overview.js's aiPlanEnabled(): the API's two-tier check
	// requires both account.plan_ai and (for sub-accounts) the per-website
	// `ai` flag. We hide the AI suggest reply affordance on the reviews
	// section when either is off so the user doesn't click a button that
	// just returns "AI replies are not on your plan yet."
	function aiPlanEnabledForReviews() {
		var acc = window.rwAccount;
		if (!acc) return false;
		var planOn = acc.plan_ai == 1 || acc.plan_ai === '1' || acc.plan_ai === true;
		if (!planOn) return false;
		var wid = parseInt(subAccount, 10) || 0;
		if (wid > 0) {
			var sub = (acc.websites || []).filter(function (w) { return Number(w.id) === wid; })[0];
			if (!sub) return false;
			return sub.ai == 1 || sub.ai === '1' || sub.ai === true;
		}
		return true;
	}

	// Port of rw-overview.js's suggestAiReply so the AI suggest button
	// works on the Reviews section (where rw-overview.js isn't loaded).
	// Uses the same data-* anchors as latest reviews so the same CSS
	// states (data-ai-loading, data-ai-reply, data-ai-reply-error) light up.
	function suggestAiReplyAdmin($btn) {
		var $row = $btn.closest('[data-review-id]');
		var id   = $row.data('review-id');
		if (!id) return;
		$row.find('[data-ai-reply]').hide();
		$row.find('[data-ai-reply-error]').hide();
		$row.find('[data-ai-suggest]').hide();
		$row.find('[data-ai-loading]').show();

		hookRequest({
			path:    'posts/ai/reply/' + id,
			method:  'POST',
			body:    {},
			extraHeaders: { 'Content-Type': 'application/json' }
		}).done(function (result) {
			$row.find('[data-ai-loading]').hide();
			if (typeof result === 'string') {
				try { result = JSON.parse(result); } catch (e) {}
			}
			if (result && result.success && result.msg) {
				$row.find('[data-ai-reply-text]').text(result.msg);
				$row.find('[data-ai-reply]').show();
				return;
			}
			$row.find('[data-ai-suggest]').show();
			var errMsg;
			if (result && result.upgrade) {
				errMsg = 'AI replies are not on your plan yet.';
			} else if (result && result._proxy_error) {
				errMsg = 'AI service: ' + (result._proxy_message || 'unknown error');
			} else if (result && result.msg) {
				errMsg = result.msg;
			} else {
				errMsg = 'Could not generate a reply right now.';
			}
			$row.find('[data-ai-reply-error-text]').text(errMsg);
			$row.find('[data-ai-reply-error]').show();
		}).fail(function (xhr, textStatus) {
			if (textStatus === 'abort') return;
			$row.find('[data-ai-loading]').hide();
			$row.find('[data-ai-suggest]').show();
			var reason = (textStatus === 'timeout')
				? 'Request timed out. The AI service is taking longer than usual.'
				: 'Could not reach the AI service.';
			$row.find('[data-ai-reply-error-text]').text(reason);
			$row.find('[data-ai-reply-error]').show();
		});
	}

	function copyAiReplyAdmin($link) {
		var $row  = $link.closest('[data-review-id]');
		var $text = $row.find('[data-ai-reply-text]');
		var text  = ($text[0] && ($text[0].innerText || $text[0].textContent)) || '';
		var done  = function () {
			var original = $link.html();
			$link.html('<span class="dashicons dashicons-yes-alt"></span> <span class="rw-link-text">Copied!</span>');
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

	function showError(msg) {
		$('#rw-error p').text(msg);
		$('#rw-error').show();
	}

	// Cheap-and-safe HTML escape for dynamic strings we drop into the DOM.
	// Delegates to the shared window.rwEscAttr defined at the top of
	// this file so rw-overview.js uses the same implementation.
	function esc(v)     { return window.rwEscAttr(v); }
	function escAttr(v) { return window.rwEscAttr(v); }

	// Render the small media gallery a review can carry. Mirrors the
	// web dashboard's posts.list.html `media_arr` foreach. Each item
	// is an {url, type: image|video|audio} thumbnail; video items get
	// a play-icon overlay so they're distinguishable from photos.
	// Falls back to the legacy single `p.media` string for older posts
	// that predate media_arr.
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

	// Allow <br> (the only tag the API actually emits in review text)
	// through escaped output so multi-line reviews format correctly. All
	// other tags stay escaped so review content can't smuggle script/img.
	function safeReviewText(s) {
		var escaped = String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
		return escaped.replace(/&lt;br\s*\/?&gt;/gi, '<br>');
	}

	// ---------------------------------------------------------------------
	// Legacy utilities preserved from the old rw-admin.js
	// ---------------------------------------------------------------------
	function generateShortcode() {
		var grid_id = ($('#grid_id').val() || '').trim();
		var grid_tags = ($('#grid_tags').val() || '').trim();
		var grid_disable = $('#grid_disable').is(':checked');
		if (grid_id === '') { $('#generate-shortcode').val(''); return; }
		var addon = '';
		if (grid_disable) addon += ' disable-custom-posts="true"';
		if (grid_tags !== '') addon += ' tags="' + grid_tags + '"';
		$('#generate-shortcode').val('[repuso_grid id="' + grid_id + '" ' + addon + ']');
	}

	function setIframeSize() {
		var $iframe = $('#iframe');
		if ($iframe.length > 0) {
			var offset = $iframe.offset().top;
			$iframe.css('height', $(window).height() - offset - 70);
		}
	}

	// ---------------------------------------------------------------------
	// Wiring
	// ---------------------------------------------------------------------
	$(window).on('resize', setIframeSize);

	$(document).ready(function () {

		// Floating widget settings: "Add one more URL" row clone.
		$('#add-new-url').on('click', function (e) {
			e.preventDefault();
			var $row = $('.new-url').first().clone();
			$row.find('input').val('');
			$row.find('select').val('show');
			$('.urls-wrapper').append($row);
		});

		// Shortcode generator (kept for the dormant /shortcodes page).
		$('#grid_tags,#grid_id').on('keyup', generateShortcode);
		$('#grid_disable').on('change', generateShortcode);

		// Admin notice dismiss handlers. Each path fires exactly one
		// AJAX call to rw_store_notice_dismiss; the `days` value is
		// either a positive int (snooze N days) or "never" (permanent).
		function dismissNotice($notice, type, days) {
			$notice.hide();
			$.post(ajax_var.url, {
				action: 'rw_store_notice_dismiss',
				type:   type,
				days:   days,
				nonce:  ajax_var.nonce
			});
		}

		// Review notice ("Loving Repuso so far?"). Has TWO inline links
		// (Maybe later / Don't show again) plus the WP X button. Each
		// must fire its own value once - the old code triggered both
		// inline links via the X, double-AJAX-ing and silently making
		// X act as "Don't show again" forever.
		$('#rw-notice-review').on('click', '.notice-dismiss', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-review'), 'review', 30);
		});
		$('#rw-notice-review .rw-dismiss').on('click', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-review'), 'review', $(this).data('until'));
		});

		// Connect notice. Single inline link + X. Both = 7-day snooze.
		$('#rw-notice-settings').on('click', '.notice-dismiss', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-settings'), 'settings', 7);
		});
		$('#rw-notice-settings .rw-dismiss').on('click', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-settings'), 'settings', 7);
		});

		// "X reviews waiting" notice on the WP main dashboard. Single
		// inline link + X. Both = 7-day snooze.
		$('#rw-notice-pending').on('click', '.notice-dismiss', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-pending'), 'pending', 7);
		});
		$('#rw-notice-pending .rw-dismiss').on('click', function (e) {
			e.preventDefault();
			dismissNotice($('#rw-notice-pending'), 'pending', 7);
		});

		// Reveal admin notices after WP's core common.js has moved them
		// to their final spot (".wrap > h1, h2" insertAfter pass). The
		// initial display:none stops the user from seeing the notice
		// flash above the title and then jump below it. setTimeout(0)
		// queues this after WP's $(document).ready handlers run.
		setTimeout(function () {
			$('#rw-notice-review, #rw-notice-settings, #rw-notice-pending').show();
		}, 0);

		// Everything below is plugin-page-only.
		var $wrapper = $('#rw-wrapper');
		if (!$wrapper.length) return;

		// When account/info eventually lands, reconcile the AI suggest
		// buttons in the Reviews section that we rendered optimistically
		// before knowing the user's role. If the user turns out to be a
		// non-admin on a plan without AI, the button would just error
		// on click, so we yank it.
		$(document).on('rw:account-loaded', function () {
			var acc = window.rwAccount;
			var isAdminUser = !!(acc && acc.is_admin);
			if (!aiPlanEnabledForReviews() && !isAdminUser) {
				$('#rw-reviews .rw-latest__ai-suggest, #rw-reviews .rw-latest__ai-loading').remove();
			}

			// Hide section-head actions (Create new widget / Connect a
			// channel / refresh icons) in sections where the current
			// role has no access. Keeps the access-denied card the
			// only thing visible inside the section, no useless CTAs.
			if (!window.rwRoles.isAdminOrEditor()) {
				$('#rw-widgets .rw-section__head-actions, #rw-channels .rw-section__head-actions').hide();
				// Floating-widget tab in the Widgets sub-nav is also
				// off-limits since it depends on widgets.
				$('.rw-subnav__tab[href*="pagewide_widget"], .rw-subnav__tab[href*="rw_widgets"]').each(function () {
					var $t = $(this);
					if (!$t.hasClass('is-active')) $t.css('pointer-events', 'none').css('opacity', 0.4);
				});
			}
		});

		// Top-nav + table action handlers (delegated where rows are dynamic).
		// Disconnect's confirm UI lives inside the (otherwise-hidden) status
		// pill, so we surface the pill when the user clicks Disconnect and
		// hide it again on "No". On "Yes", the flip to Not Connected hides
		// it as part of renderStatus.
		$(document).on('click', '.rw-disconnect', function (e) {
			e.preventDefault();
			window.rwConfirm({
				title:        rwT('disconnect_title',   'Disconnect from Repuso?'),
				message:      rwT('disconnect_message', "You can reconnect anytime by signing back in. Your data on Repuso isn't affected - only this site's link to it is removed."),
				confirmLabel: rwT('disconnect_yes',     'Yes, disconnect'),
				cancelLabel:  rwT('confirm_cancel',     'Cancel'),
				danger:       true,
				onConfirm:    function () { doDisconnect(); }
			});
		});

		$(document).on('click', '.rw-open-dashboard', openDashboard);

		// Language switcher: toggle dropdown, pick a locale, POST to
		// rw_set_locale (writes user meta), reload so WP's
		// get_user_locale() picks up the new value and reloads the .mo.
		// Class-based selectors (not IDs) because the same switcher
		// partial is rendered twice in the DOM - once in topbar.php for
		// the connected view and once in onboard.php for the
		// disconnected view - so per-instance scope is required.
		$(document).on('click', '.rw-lang-toggle', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $btn  = $(this);
			var $menu = $btn.closest('.rw-lang-switcher').find('.rw-lang__menu');
			var open  = $menu.is(':visible');
			// Close any other open menus first.
			$('.rw-lang__menu').not($menu).hide();
			$('.rw-lang-toggle').not($btn).attr('aria-expanded', 'false');
			$menu.toggle();
			$btn.attr('aria-expanded', open ? 'false' : 'true');
		});
		// Close on outside click or Escape.
		$(document).on('click', function (e) {
			var $switchers = $('.rw-lang-switcher');
			if (!$switchers.length) return;
			var insideAny = false;
			$switchers.each(function () {
				if ($.contains(this, e.target) || e.target === this) insideAny = true;
			});
			if (!insideAny) {
				$('.rw-lang__menu').hide();
				$('.rw-lang-toggle').attr('aria-expanded', 'false');
			}
		});
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				$('.rw-lang__menu').hide();
				$('.rw-lang-toggle').attr('aria-expanded', 'false');
			}
		});
		$(document).on('click', '.rw-lang__item', function (e) {
			e.preventDefault();
			var $a = $(this);
			if ($a.hasClass('is-active')) {
				$a.closest('.rw-lang-switcher').find('.rw-lang__menu').hide();
				return;
			}
			var locale = $a.data('locale') || '';
			$a.addClass('is-busy');
			$.post(ajax_var.url, {
				action: 'rw_set_locale',
				locale: locale,
				nonce:  ajax_var.nonce
			}).done(function () {
				// Reload so WP picks up the new user locale on the
				// next request. Cache buster keeps any browser-cached
				// admin URL from short-circuiting the language change.
				window.location.href = window.location.pathname +
					(window.location.search ? window.location.search + '&' : '?') +
					'_rwlang=' + encodeURIComponent(locale);
			}).fail(function () {
				$a.removeClass('is-busy');
			});
		});

		// "Start a chat" CTA on the Help page opens the Crisp chatbox.
		// Crisp's queue ($crisp) buffers commands until the loader script
		// finishes downloading, so this works whether the user clicks
		// before or after the bubble has fully initialised.
		$(document).on('click', '.rw-open-crisp', function (e) {
			e.preventDefault();
			if (window.$crisp && typeof window.$crisp.push === 'function') {
				window.$crisp.push(['do', 'chat:show']);
				window.$crisp.push(['do', 'chat:open']);
			}
		});

		// Floating widget selector → keep hidden id/type inputs in sync.
		$(document).on('change', '#rw-floating-select', syncFloatingHidden);

		// Reviews tabs (Inbox / Approved / All).
		$(document).on('click', '#rw-reviews .rw-tab', function (e) {
			e.preventDefault();
			var $a = $(this);
			$('#rw-reviews .rw-tab').removeClass('is-current');
			$a.addClass('is-current');
			loadReviews($a.data('path'));
		});

		// Per-section refresh buttons (Widgets / Channels / Reviews).
		// Force=true bypasses the response cache for a true cold pull
		// from the API. Spinner state is handled by setSectionRefreshing
		// inside each loader.
		$(document).on('click', '.rw-section__refresh', function (e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.hasClass('is-spinning')) return;
			var section = $btn.data('section');
			if (section === 'widgets') {
				loadWidgets({ force: true });
			} else if (section === 'channels') {
				loadAllChannels();
				loadChannels({ force: true });
			} else if (section === 'reviews') {
				var path = $('#rw-reviews .rw-tab.is-current').data('path');
				loadReviews(path, { force: true });
			}
		});

		// Approve / dismiss a review. Buttons toggle exclusive: clicking
		// Approve sets status1 and clears status2 on the sibling, and v.v.
		$(document).on('click', '#rw-reviews .rw-action-pill', function () {
			var $btn  = $(this);
			var $card = $btn.closest('.rw-review');
			var status = parseInt($btn.data('status'), 10);
			var postId = $btn.data('post-id');
			updateReviewStatus(postId, status);

			// Drop the row from the list if its new status no longer
			// matches the currently-viewed tab. Inbox shows status=0
			// (pending) - approving or dismissing means the post is
			// no longer pending, so it shouldn't sit there as a
			// confusing "already done" row. Same logic for Approved:
			// dismissing moves it out of that tab's filter. The "All"
			// tab keeps everything visible, so we just flip the button
			// state and leave the row in place.
			var path = $('#rw-reviews .rw-tab.is-current').data('path');
			var stayVisible = true;
			if (path === '/inbox' && status !== 0) {
				stayVisible = false;
			} else if (path === '' && status !== 1) {
				// Approved tab (data-path="")
				stayVisible = false;
			}

			if (!stayVisible) {
				$card.fadeOut(180, function () { $(this).remove(); });
			} else {
				$card.find('.rw-action-pill').removeClass('status0 status1 status2');
				$btn.addClass('status' + status);
			}
		});

		// AI suggest reply + Copy reply on the reviews section (rw-overview.js
		// owns the same handlers on the dashboard, but isn't loaded here).
		$(document).on('click', '#rw-reviews [data-ai-suggest]', function (e) {
			e.preventDefault();
			suggestAiReplyAdmin($(this));
		});
		$(document).on('click', '#rw-reviews [data-copy-reply]', function (e) {
			e.preventDefault();
			copyAiReplyAdmin($(this));
		});

		// Open review-media images in a lightbox. Video items skip the
		// preventDefault so the browser still opens the source URL in a
		// new tab (video playback in a modal needs source-specific
		// embedding we'd rather not maintain).
		$(document).on('click', '.rw-review-media__item', function (e) {
			var $a = $(this);
			if ($a.hasClass('is-video') || $a.hasClass('is-audio')) return;
			e.preventDefault();
			if (typeof window.rwOpenImageLightbox === 'function') {
				window.rwOpenImageLightbox($a.attr('href'));
			}
		});

		// Widget preview / full code modals. preventDefault because the
		// anchors no longer rely on Thickbox to swallow the navigation.
		$(document).on('click', '#rw-widgets .rw-preview', function (e) {
			e.preventDefault();
			previewWidget($(this).data('widget-id'), false);
		});
		$(document).on('click', '#rw-widgets .rw-preview-code', function (e) {
			e.preventDefault();
			previewWidget($(this).data('widget-id'), true);
		});

		// Click-to-copy on the shortcode pill (loaded by loadWidgets).
		$(document).on('click', '#rw-widgets .rw-copy[data-copy]', function (e) {
			e.preventDefault();
			rwCopyText($(this).attr('data-copy'), $(this));
		});

		// Copy button inside the "Full code" modal.
		$(document).on('click', '.rw-modal .rw-copy[data-copy-textarea]', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var text = $btn.closest('.rw-modal__body').find('.rw-modal__code').val();
			rwCopyText(text, $btn);
		});

		// Decide the initial view from the server-rendered hint, then poll.
		var hasApiKey = String($wrapper.attr('data-rw-has-apikey')) === '1';
		if (hasApiKey) {
			// Optimistic render: WP already has a stored apikey, so the
			// overwhelming common case is "still connected". Render the
			// logged-in view immediately and let loadAccount kick off the
			// real data fetch. checkConnection() still runs in the
			// background to catch a server-side revocation, which flips
			// the UI to Not Connected if it actually happened - but the
			// user no longer pays a full API round-trip on every page
			// browse just to confirm what we already know.
			renderStatus('Connected');
			checkConnection();
			startPoll('normal');

			// Decouple section loading from loadAccount so a slow / hung
			// account/info round-trip doesn't leave the page on a blank
			// .rw-logged area forever. Each section's data fetch only
			// needs the closure's `subAccount` (initialized from
			// ajax_var.subAccount on page load), not the websites array
			// returned by account/info. loadAccount still runs in the
			// background to populate the sub-account picker - but the
			// channels/widgets/reviews lists no longer wait for it.
			var bootSection = $wrapper.data('rw-section');
			if (bootSection === 'floating' && typeof loadFloatingSelector === 'function') {
				loadFloatingSelector();
				window.rwSectionBooted = true;
			} else if (bootSection === 'channels') {
				loadAllChannels();
				loadChannels();
				window.rwSectionBooted = true;
			} else if (bootSection === 'reviews') {
				$('#rw-reviews .rw-tab').removeClass('is-current').first().addClass('is-current');
				loadReviews('/inbox');
				window.rwSectionBooted = true;
			} else if (bootSection === 'widgets' || bootSection === '' || typeof bootSection === 'undefined') {
				loadWidgets();
				window.rwSectionBooted = true;
			}
			// Overview/dashboard owns its own bootstrap path via
			// rw-overview.js's refreshAll() - skipped here.
			// Marked booted so the runtime-login branch in renderStatus
			// doesn't re-trigger rwLoadOverview() on the next status
			// confirm (which would double-fire the loaders).
			if (bootSection === 'overview') window.rwSectionBooted = true;
		} else {
			renderStatus('Not Connected');
		}
	});

}(jQuery));
