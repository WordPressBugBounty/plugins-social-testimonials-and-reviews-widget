<?php
/**
 * Plugin admin page. Pure markup. All behavior lives in js/rw-admin.js and
 * js/rw-onboard.js, which read RepusoOnboard + ajax_var localized by
 * admin_enqueue_scripts().
 *
 * Two top-level views toggled by JS based on the status check:
 *   .rw-not-logged → status pill + signup/login cards + example widgets
 *   .rw-logged     → status pill + sub-account picker + widgets/channels/reviews
 *
 * The current section ($this->currentSection) decides which of widgets /
 * channels / reviews is rendered inside .rw-logged.
 */
if (!defined('ABSPATH')) exit;
?>
<div id="rw-wrapper" data-rw-section="<?php echo esc_attr($this->currentSection); ?>" data-rw-has-apikey="<?php echo $this->apiKey ? '1' : '0'; ?>">

	<?php require dirname( __FILE__ ) . '/topbar.php'; ?>

	<p id="rw-status-line" class="rw-status-line" style="display:none;">
		<span id="rw-status-dot" class="rw-status-dot"></span>
		<span id="rw-status-text"><?php esc_html_e( 'Checking connection…', 'social-testimonials-and-reviews-widget' ); ?></span>
	</p>

	<div id="rw-error" class="error notice" style="display:none;">
		<p></p>
	</div>

	<!-- Initial-load placeholder. Only rendered when WP doesn't have a
	     stored apikey yet, so users on already-connected installs don't
	     see a "Checking connection…" flash on every page navigation.
	     JS still calls $('#rw-checking').hide() on first renderStatus
	     so the element doesn't linger if it does render. -->
	<?php if ( empty( $this->apiKey ) ) : ?>
	<div id="rw-checking" class="rw-checking">
		<span class="rw-checking__spinner" aria-hidden="true"></span>
		<span class="rw-checking__label"><?php esc_html_e( 'Checking connection…', 'social-testimonials-and-reviews-widget' ); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============================== NOT LOGGED ============================== -->
	<?php require dirname( __FILE__ ) . '/onboard.php'; // toggled by js/rw-admin.js ?>

	<!-- ============================== LOGGED ============================== -->
	<div class="rw-logged" style="display:none;">

		<!-- Sub-account picker lives in topbar.php now. -->

		<?php
		// Sub-nav for the Widgets section (Widgets / Floating widget). Sits
		// outside the section card so it reads as page-level navigation,
		// matching the placement on tmpl/floating.php.
		if ( $this->currentSection === 'widgets' ) :
			$widgets_url  = admin_url( 'admin.php?page=rw_widgets' );
			$floating_url = admin_url( 'admin.php?page=pagewide_widget' );
		?>
		<nav class="rw-subnav" aria-label="<?php esc_attr_e( 'Widget types', 'social-testimonials-and-reviews-widget' ); ?>">
			<a href="<?php echo esc_url( $widgets_url ); ?>" class="rw-subnav__tab is-active"><?php esc_html_e( 'Widgets', 'social-testimonials-and-reviews-widget' ); ?></a>
			<a href="<?php echo esc_url( $floating_url ); ?>" class="rw-subnav__tab"><?php esc_html_e( 'Floating widget', 'social-testimonials-and-reviews-widget' ); ?></a>
		</nav>
		<?php endif; ?>

		<!-- ===== Widgets ===== -->
		<section id="rw-widgets" class="rw-section" style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Widgets', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Embed any of your widgets on your site using the shortcode.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
				<div class="rw-section__head-actions">
					<button type="button" class="rw-section__refresh" data-section="widgets" title="<?php esc_attr_e( 'Refresh', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh widgets', 'social-testimonials-and-reviews-widget' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<a href="#" class="rw-button rw-button-outline rw-button-inline rw-open-dashboard" data-rw-path="/widgets/new">
						<?php esc_html_e( 'Create new widget', 'social-testimonials-and-reviews-widget' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
			</header>
			<div class="rw-section__body" data-list></div>

			<div id="rw_preview_wrapper" style="display:none;">
				<iframe id="rw_preview" width="100%" height="100%" frameborder="0" style="vertical-align: text-bottom; position: relative; margin: 0; overflow: hidden; background-color: transparent;"></iframe>
			</div>
		</section>

		<!-- ===== Channels ===== -->
		<section id="rw-channels" class="rw-section" style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Channels', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Review platforms connected to your account.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
				<div class="rw-section__head-actions">
					<button type="button" class="rw-section__refresh" data-section="channels" title="<?php esc_attr_e( 'Refresh', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh channels', 'social-testimonials-and-reviews-widget' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<a href="#" class="rw-button rw-button-outline rw-button-inline rw-open-dashboard" data-rw-path="/channels/new">
						<?php esc_html_e( 'Connect a channel', 'social-testimonials-and-reviews-widget' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
			</header>
			<div class="rw-section__body" data-list></div>

			<footer class="rw-section__foot">
				<div class="rw-section__foot-label"><?php esc_html_e( 'All supported platforms', 'social-testimonials-and-reviews-widget' ); ?></div>
				<div class="rw-channels-all" id="all"></div>
			</footer>
		</section>

		<!-- ===== Reviews ===== -->
		<section id="rw-reviews" class="rw-section" style="display:none;">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Reviews', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Approve the reviews you want to feature in your widgets.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
				<div class="rw-section__head-actions">
					<button type="button" class="rw-section__refresh" data-section="reviews" title="<?php esc_attr_e( 'Refresh', 'social-testimonials-and-reviews-widget' ); ?>" aria-label="<?php esc_attr_e( 'Refresh reviews', 'social-testimonials-and-reviews-widget' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<a href="#" class="rw-button rw-button-outline rw-button-inline rw-open-dashboard" data-rw-path="/posts">
						<?php esc_html_e( 'Manage all reviews', 'social-testimonials-and-reviews-widget' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
			</header>

			<div class="rw-tabs">
				<a data-path="/inbox" class="rw-tab is-current" href="#"><?php esc_html_e( 'Inbox', 'social-testimonials-and-reviews-widget' ); ?></a>
				<a data-path=""       class="rw-tab"            href="#"><?php esc_html_e( 'Approved', 'social-testimonials-and-reviews-widget' ); ?></a>
				<a data-path="/all"   class="rw-tab"            href="#"><?php esc_html_e( 'All', 'social-testimonials-and-reviews-widget' ); ?></a>
			</div>

			<div class="rw-section__body" data-list></div>
		</section>

	</div><!-- /.rw-logged -->

</div>
