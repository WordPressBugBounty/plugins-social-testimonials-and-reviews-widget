<?php
/**
 * Overview dashboard. Read-only mirror of the most useful repuso.com/app
 * surfaces: KPI cards, AI insights, distributions, latest reviews, and a
 * "complete setup" checklist whose action items deep-link to the dashboard.
 *
 * Unconnected users see the same onboard view as on the other plugin pages
 * (extracted to tmpl/onboard.php).
 *
 * Sections are rendered as placeholders here; js/rw-overview.js fills them
 * in once the API calls resolve.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="rw-wrapper" data-rw-section="<?php echo esc_attr( $this->currentSection ); ?>" data-rw-has-apikey="<?php echo $this->apiKey ? '1' : '0'; ?>">

	<?php require dirname( __FILE__ ) . '/topbar.php'; ?>

	<p id="rw-status-line" class="rw-status-line" style="display:none;">
		<span id="rw-status-dot" class="rw-status-dot"></span>
		<span id="rw-status-text"><?php esc_html_e( 'Checking connection…', 'social-testimonials-and-reviews-widget' ); ?></span>
	</p>

	<div id="rw-error" class="error notice" style="display:none;"><p></p></div>

	<!-- ============================== NOT LOGGED ==============================
	     Always include the onboard partial so a runtime disconnect (without
	     a page refresh) has a connect screen to fall back to. Mirrors the
	     pattern in tmpl/main.php. The onboard div carries its own
	     style="display:none" until $rw_onboard_visible is true OR JS calls
	     renderStatus('Not Connected'). -->
	<?php
	$rw_onboard_visible = empty( $this->apiKey );
	require dirname( __FILE__ ) . '/onboard.php';
	?>

	<!-- ============================== LOGGED ==============================
	     Always emit the dashboard markup so a runtime login (without a
	     page refresh) lands on a real Dashboard. Initial display tracks
	     server-rendered apiKey; rw-admin.js toggles after status check. -->
	<div class="rw-logged"<?php echo empty( $this->apiKey ) ? ' style="display:none;"' : ''; ?>>
	<div class="rw-overview" data-overview>

		<!-- Complete setup card. A skeleton placeholder is rendered first so
		     the user sees the section land immediately instead of staring at
		     blank space while the channels/widgets/posts probes complete.
		     rw-overview.js swaps it for the real card (or hides everything
		     if all three steps are done) once all probes land. -->
		<section class="rw-section rw-setup" data-setup-placeholder>
			<header class="rw-setup__head">
				<h3><?php esc_html_e( 'Complete your setup', 'social-testimonials-and-reviews-widget' ); ?></h3>
				<span class="rw-setup__head-actions">
					<button type="button" class="rw-section__refresh rw-setup__refresh" data-section="setup" title="<?php esc_attr_e( 'Refresh setup status', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh setup status', 'social-testimonials-and-reviews-widget' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<span class="rw-setup__badge is-loading"><?php esc_html_e( 'Checking…', 'social-testimonials-and-reviews-widget' ); ?></span>
				</span>
			</header>
			<div class="rw-setup__progress">
				<div class="rw-setup__progress-fill" style="width:0%;"></div>
			</div>
			<div class="rw-setup__steps">
				<div class="rw-setup__step is-skeleton">
					<span class="rw-setup__step-icon"><span class="rw-kpi__skeleton"></span></span>
					<div class="rw-setup__step-body">
						<div class="rw-setup__step-title"><span class="rw-kpi__skeleton"></span></div>
						<div class="rw-setup__step-desc"><span class="rw-kpi__skeleton"></span></div>
					</div>
				</div>
				<div class="rw-setup__step is-skeleton">
					<span class="rw-setup__step-icon"><span class="rw-kpi__skeleton"></span></span>
					<div class="rw-setup__step-body">
						<div class="rw-setup__step-title"><span class="rw-kpi__skeleton"></span></div>
						<div class="rw-setup__step-desc"><span class="rw-kpi__skeleton"></span></div>
					</div>
				</div>
				<div class="rw-setup__step is-skeleton">
					<span class="rw-setup__step-icon"><span class="rw-kpi__skeleton"></span></span>
					<div class="rw-setup__step-body">
						<div class="rw-setup__step-title"><span class="rw-kpi__skeleton"></span></div>
						<div class="rw-setup__step-desc"><span class="rw-kpi__skeleton"></span></div>
					</div>
				</div>
			</div>
		</section>

		<section class="rw-section rw-setup" data-setup-card style="display:none;">
			<header class="rw-setup__head">
				<h3><?php esc_html_e( 'Complete your setup', 'social-testimonials-and-reviews-widget' ); ?></h3>
				<span class="rw-setup__head-actions">
					<button type="button" class="rw-section__refresh rw-setup__refresh" data-section="setup" title="<?php esc_attr_e( 'Refresh setup status', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh setup status', 'social-testimonials-and-reviews-widget' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<span class="rw-setup__badge"><span data-setup-done>0</span> / 3 <?php esc_html_e( 'done', 'social-testimonials-and-reviews-widget' ); ?></span>
				</span>
			</header>
			<div class="rw-setup__progress">
				<div class="rw-setup__progress-fill" data-setup-progress style="width:0%;"></div>
			</div>
			<div class="rw-setup__steps" data-setup-steps></div>
		</section>

		<!-- Range pills + manual refresh button. Match the dashboard:
		     range options + default in localStorage, refresh icon with a
		     time-ago tooltip that updates without reloading.
		     Every "data" block from here down is tagged with
		     data-dashboard-data so rw-overview.js can collapse them as a
		     group when the account has no channels yet - matches the web
		     dashboard's "Complete your setup only" empty state. -->
		<div class="rw-overview-toolbar" data-dashboard-data style="display:none;">
			<div class="rw-range" role="tablist" aria-label="<?php esc_attr_e( 'Time range', 'social-testimonials-and-reviews-widget' ); ?>">
				<button type="button" class="rw-range__pill" data-range="30d" role="tab"><?php esc_html_e( '30d', 'social-testimonials-and-reviews-widget' ); ?></button>
				<button type="button" class="rw-range__pill is-active" data-range="90d" role="tab"><?php esc_html_e( '90d', 'social-testimonials-and-reviews-widget' ); ?></button>
				<button type="button" class="rw-range__pill" data-range="1y" role="tab"><?php esc_html_e( '1y', 'social-testimonials-and-reviews-widget' ); ?></button>
				<button type="button" class="rw-range__pill" data-range="2y" role="tab"><?php esc_html_e( '2y', 'social-testimonials-and-reviews-widget' ); ?></button>
			</div>
			<button type="button" id="rw-refresh-btn" class="rw-refresh" title="<?php esc_attr_e( 'Refresh', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh dashboard', 'social-testimonials-and-reviews-widget' ); ?>">
				<span class="dashicons dashicons-update"></span>
				<span class="rw-refresh__label" data-refresh-label><?php esc_html_e( 'Just updated', 'social-testimonials-and-reviews-widget' ); ?></span>
			</button>
		</div>

		<!-- KPI cards row: average rating, total reviews, new reviews this month. -->
		<div class="rw-kpis" data-dashboard-data style="display:none;">

			<div class="rw-kpi" data-kpi="rating">
				<div class="rw-kpi__label"><?php esc_html_e( 'Average rating', 'social-testimonials-and-reviews-widget' ); ?></div>
				<div class="rw-kpi__value" data-kpi-value>
					<span class="rw-kpi__skeleton"></span>
				</div>
				<div class="rw-kpi__meta" data-kpi-meta></div>
				<div class="rw-kpi__spark" id="rw-sparkline-rating"></div>
			</div>

			<div class="rw-kpi" data-kpi="total">
				<div class="rw-kpi__label"><?php esc_html_e( 'Total reviews', 'social-testimonials-and-reviews-widget' ); ?></div>
				<div class="rw-kpi__value" data-kpi-value>
					<span class="rw-kpi__skeleton"></span>
				</div>
				<div class="rw-kpi__meta" data-kpi-meta></div>
				<div class="rw-kpi__spark" id="rw-sparkline-total"></div>
			</div>

			<div class="rw-kpi" data-kpi="new">
				<div class="rw-kpi__label"><?php esc_html_e( 'New this month', 'social-testimonials-and-reviews-widget' ); ?></div>
				<div class="rw-kpi__value" data-kpi-value>
					<span class="rw-kpi__skeleton"></span>
				</div>
				<div class="rw-kpi__meta" data-kpi-meta></div>
				<div class="rw-kpi__spark" id="rw-sparkline-new"></div>
			</div>

		</div>

		<!-- "Reviews waiting for approval" action banner. Hidden until JS
		     finds at least one pending review; populated by rw-overview.js.
		     Sits between KPIs and the Latest reviews row so the call-out
		     reads as a direct prompt to act on the queue. -->
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=rw_reviews' ) ); ?>" class="rw-action-banner" id="rw-pending-banner" style="display:none;">
			<span class="rw-action-banner__icon dashicons dashicons-bell"></span>
			<span class="rw-action-banner__text">
				<strong data-pending-count>0</strong>
				<?php esc_html_e( 'reviews waiting for your approval', 'social-testimonials-and-reviews-widget' ); ?>
			</span>
			<span class="rw-action-banner__cta">
				<?php esc_html_e( 'Review them', 'social-testimonials-and-reviews-widget' ); ?>
				<span class="dashicons dashicons-arrow-right-alt"></span>
			</span>
		</a>

		<!-- Row: Latest reviews (2/3) + AI insights (1/3). Mirrors the
		     production dashboard's Row 2 (latest + AI side-by-side). -->
		<div class="rw-row-2-1" data-dashboard-data style="display:none;">

			<section class="rw-section rw-latest" data-latest-card>
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Latest reviews', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'Most recent reviews across every connected platform.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=rw_reviews' ) ); ?>" class="rw-button rw-button-outline rw-button-inline">
						<?php esc_html_e( 'See all reviews', 'social-testimonials-and-reviews-widget' ); ?>
						<span class="dashicons dashicons-arrow-right-alt"></span>
					</a>
				</header>

				<div class="rw-latest__loading" data-latest-loading>
					<span class="rw-section__spinner"></span>
				</div>

				<div class="rw-latest__empty" data-latest-empty style="display:none;">
					<?php esc_html_e( 'No reviews yet.', 'social-testimonials-and-reviews-widget' ); ?>
				</div>

				<div class="rw-latest__list" data-latest-list></div>
			</section>

			<section class="rw-section rw-ai-card" data-ai-card>
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'AI insights', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'Strengths and suggested improvements, summarised from your reviews.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>

				<div class="rw-ai__loading" data-ai-loading>
					<span class="rw-section__spinner"></span>
				</div>

				<div class="rw-ai__placeholder" data-ai-placeholder style="display:none;"></div>

				<div class="rw-ai__data" data-ai-data style="display:none;">

					<div class="rw-ai__sentiment" data-ai-sentiment style="display:none;">
						<svg viewBox="0 0 160 110" class="rw-dial">
							<defs>
								<linearGradient id="rw-dial-grad" x1="0%" y1="0%" x2="100%" y2="0%">
									<stop offset="0%"  stop-color="#dc2626" />
									<stop offset="50%" stop-color="#f59e0b" />
									<stop offset="100%" stop-color="#16a34a" />
								</linearGradient>
							</defs>
							<path d="M 20 90 A 60 60 0 0 1 140 90" stroke="#eef0f3" stroke-width="14" fill="none" stroke-linecap="round" />
							<path d="M 20 90 A 60 60 0 0 1 140 90" stroke="url(#rw-dial-grad)" stroke-width="14" fill="none" stroke-linecap="round" />
							<line class="rw-dial__needle" x1="80" y1="90" data-dial-needle x2="80" y2="35" />
							<circle cx="80" cy="90" r="8" class="rw-dial__hub" />
							<circle cx="80" cy="90" r="3" class="rw-dial__hub-inner" />
							<text x="80" y="76" text-anchor="middle" class="rw-dial__value" stroke="#fff" stroke-width="4" data-dial-percent>0%</text>
						</svg>
						<div class="rw-ai__counts">
							<span><span class="rw-ai__dot rw-ai__dot-pos"></span><b data-sent-positive>0</b> <?php esc_html_e( 'positive', 'social-testimonials-and-reviews-widget' ); ?></span>
							<span><span class="rw-ai__dot rw-ai__dot-neu"></span><b data-sent-neutral>0</b> <?php esc_html_e( 'neutral', 'social-testimonials-and-reviews-widget' ); ?></span>
							<span><span class="rw-ai__dot rw-ai__dot-neg"></span><b data-sent-negative>0</b> <?php esc_html_e( 'negative', 'social-testimonials-and-reviews-widget' ); ?></span>
						</div>
					</div>

					<div class="rw-ai__bullets">
						<div class="rw-ai__section rw-ai__strengths" data-ai-strengths-wrap style="display:none;">
							<h4>
								<span class="dashicons dashicons-thumbs-up"></span>
								<?php esc_html_e( 'Strengths', 'social-testimonials-and-reviews-widget' ); ?>
							</h4>
							<ul data-ai-strengths></ul>
						</div>

						<div class="rw-ai__section rw-ai__improvements" data-ai-improvements-wrap style="display:none;">
							<h4>
								<span class="dashicons dashicons-lightbulb"></span>
								<?php esc_html_e( 'Improvements', 'social-testimonials-and-reviews-widget' ); ?>
							</h4>
							<ul data-ai-improvements></ul>
						</div>
					</div>

				</div>
			</section>

		</div>

		<!-- Customer outreach card. Mirrors the web dashboard's
		     `dash-row-outreach`: shown only when admin && hasChannels &&
		     at least one of the three nudges is still pending (collect
		     sources not configured / invites disabled / no NFC tags
		     ordered). Each row is its own deep link into the web
		     dashboard, with the auto-login flow handling the magic-link
		     redirect. NOT tagged with data-dashboard-data: visibility
		     is owned entirely by loadOutreach so we don't briefly flash
		     the card when data sections un-collapse, only to have
		     renderOutreach hide it again a moment later. -->
		<section class="rw-section rw-outreach" data-outreach-card style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Customer outreach', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Nudge customers to leave reviews - one less reason to wait.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>
			<div class="rw-outreach__steps" data-outreach-steps></div>
		</section>

		<!-- Row: Rating distribution + Platform breakdown side-by-side.
		     Mirrors the dashboard's Row 3 (breakdowns). -->
		<div class="rw-row-half" data-dashboard-data style="display:none;">

			<section class="rw-section rw-dist-card" data-dist-card>
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Rating distribution', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'How customer ratings break down by stars.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>

				<div class="rw-dist__loading" data-dist-loading>
					<span class="rw-section__spinner"></span>
				</div>

				<div class="rw-dist__empty" data-dist-empty style="display:none;">
					<?php esc_html_e( 'No reviews in this range yet.', 'social-testimonials-and-reviews-widget' ); ?>
				</div>

				<div class="rw-dist__rows" data-dist-rows style="display:none;"></div>
			</section>

			<section class="rw-section rw-platforms" data-platforms-card>
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Platform breakdown', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'Share of reviews, rating and connected channels per platform.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>

				<div class="rw-platforms__loading" data-platforms-loading>
					<span class="rw-section__spinner"></span>
				</div>

				<div class="rw-platforms__empty" data-platforms-empty style="display:none;">
					<?php esc_html_e( 'No platforms with reviews yet.', 'social-testimonials-and-reviews-widget' ); ?>
				</div>

				<table class="rw-platforms__table" data-platforms-table style="display:none;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Platform', 'social-testimonials-and-reviews-widget' ); ?></th>
							<th class="rw-platforms__num"><?php esc_html_e( 'Share', 'social-testimonials-and-reviews-widget' ); ?></th>
							<th class="rw-platforms__num"><?php esc_html_e( 'Rating', 'social-testimonials-and-reviews-widget' ); ?></th>
							<th class="rw-platforms__num"><?php esc_html_e( 'Channels', 'social-testimonials-and-reviews-widget' ); ?></th>
						</tr>
					</thead>
					<tbody data-platforms-rows></tbody>
				</table>

				<div class="rw-platforms__toggle" data-platforms-toggle style="display:none;"></div>
			</section>

		</div>

		<!-- Reviews-over-time chart. Mirrors the dashboard's trend row
		     at the bottom. -->
		<section class="rw-chart-card" data-chart-card data-dashboard-data style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Reviews over time', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Last 12 months, by platform.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>

			<div class="rw-chart" data-chart-host>
				<div class="rw-chart__loading" data-chart-loading>
					<span class="rw-section__spinner"></span>
				</div>
				<div id="rw-chart-over-time" class="rw-chart__canvas" style="display:none;"></div>
				<div class="rw-chart__empty" data-chart-empty style="display:none;">
					<?php esc_html_e( 'No reviews collected yet. Once reviews start coming in this chart will show monthly volume per platform.', 'social-testimonials-and-reviews-widget' ); ?>
				</div>
			</div>
		</section>

		<!-- Average rating trend chart. Same monthly buckets as
		     Reviews-over-time (sourced from /reports/time/{months}.avg_rating)
		     but rendered as a smooth-area chart with the y-axis fixed to
		     0-5 so the visual conveys "rating level over time" rather
		     than relative change. Mirrors repuso-app/.../dashboard.js#
		     renderAvgRatingTrend. -->
		<section class="rw-chart-card" data-rating-trend-card data-dashboard-data style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Average rating trend', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'How your average rating has moved over the selected period.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>

			<div class="rw-chart" data-rating-trend-host>
				<div class="rw-chart__loading" data-rating-trend-loading>
					<span class="rw-section__spinner"></span>
				</div>
				<div id="rw-chart-avg-rating" class="rw-chart__canvas" style="display:none;"></div>
				<div class="rw-chart__empty" data-rating-trend-empty style="display:none;">
					<?php esc_html_e( 'No rating data for the selected period.', 'social-testimonials-and-reviews-widget' ); ?>
				</div>
			</div>
		</section>

	</div><!-- /.rw-overview -->
	</div><!-- /.rw-logged -->

</div>
