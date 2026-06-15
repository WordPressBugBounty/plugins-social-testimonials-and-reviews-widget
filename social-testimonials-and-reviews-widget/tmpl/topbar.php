<?php
/**
 * Repuso plugin top bar.
 *
 * Renders only when an API key is stored (i.e. the user is "connected", or
 * was at some point). Provides primary navigation across the plugin's
 * sections plus the account switcher and disconnect/dashboard actions.
 *
 * Expects $this->currentSection to be one of:
 *   overview | reviews | channels | widgets | floating | guides
 *
 * The "Floating widget" page (currentSection = 'floating') no longer has its
 * own top-bar tab - it lives under Widgets and is reached via an inner-nav
 * inside the widgets template. We still highlight the Widgets tab when the
 * user is on the floating page.
 *
 * The "account switcher" (#accounts <select>) is populated by rw-admin.js
 * after /v1/account/info resolves. While the response is in flight (or if
 * the user is on floating/overview where the picker isn't strictly needed)
 * the select stays empty + hidden.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Always emit the topbar markup so JS can show it after a runtime
// login. When the WP option has no apikey at page-load (fresh install
// or post-disconnect refresh) we start it hidden; rw-admin.js's
// renderStatus('Connected') reveals it as soon as the API confirms.
$rw_topbar_hidden = empty( $this->apiKey );

$current = isset( $this->currentSection ) ? $this->currentSection : '';
// Floating-widget pages should highlight the "Widgets" tab.
$active_tab_key = ( $current === 'floating' ) ? 'widgets' : $current;
$tabs           = array(
	'overview' => array( 'label' => __( 'Dashboard', 'social-testimonials-and-reviews-widget' ), 'page' => 'rw_dashboard' ),
	'reviews'  => array( 'label' => __( 'Reviews',   'social-testimonials-and-reviews-widget' ), 'page' => 'rw_reviews' ),
	'channels' => array( 'label' => __( 'Channels',  'social-testimonials-and-reviews-widget' ), 'page' => 'rw_channels' ),
	'widgets'  => array( 'label' => __( 'Widgets',   'social-testimonials-and-reviews-widget' ), 'page' => 'rw_widgets' ),
	'guides'   => array( 'label' => __( 'Help',      'social-testimonials-and-reviews-widget' ), 'page' => 'rw_overview' ),
);
?>
<!-- Free-trial / account-status banner. Sits above the topbar so the
     billing status is the first thing a connected user sees on every
     plugin page. Hidden by default - filled and revealed by JS
     (renderTrialBanner in rw-admin.js) once window.rwAccount lands. -->
<section class="rw-trial" data-trial-banner style="display:none;">
	<div class="rw-trial__body">
		<div class="rw-trial__text">
			<strong data-trial-headline></strong>
			<span class="rw-trial__sub" data-trial-sub></span>
		</div>
		<a class="rw-trial__cta rw-button rw-button-primary rw-button-inline rw-open-dashboard" data-rw-path="/account/plan" href="#">
			<?php esc_html_e( 'Choose a plan', 'social-testimonials-and-reviews-widget' ); ?>
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
	</div>
	<div class="rw-trial__progress">
		<div class="rw-trial__progress-fill" data-trial-fill style="width:0%;"></div>
	</div>
</section>
<nav class="rw-topbar" aria-label="<?php esc_attr_e( 'Repuso plugin navigation', 'social-testimonials-and-reviews-widget' ); ?>"<?php echo $rw_topbar_hidden ? ' style="display:none;"' : ''; ?>>
	<a href="https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=topbar" target="_blank" rel="noopener" class="rw-topbar__logo" aria-label="<?php esc_attr_e( 'Repuso', 'social-testimonials-and-reviews-widget' ); ?>">
		<img src="<?php echo esc_url( $this->plugin_url . 'images/repuso-logo.svg' ); ?>" alt="<?php esc_attr_e( 'Repuso', 'social-testimonials-and-reviews-widget' ); ?>" />
	</a>
	<div class="rw-topbar__tabs">
		<?php foreach ( $tabs as $key => $tab ) :
			$is_active = ( $active_tab_key === $key );
			$href      = admin_url( 'admin.php?page=' . $tab['page'] );
		?>
			<a class="rw-topbar__tab<?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $href ); ?>">
				<?php echo esc_html( $tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="rw-topbar__right">

		<?php require dirname( __FILE__ ) . '/lang-switcher.php'; ?>

		<div class="rw-topbar__account" id="rw-subaccounts" style="display:none;">
			<label for="accounts" class="screen-reader-text"><?php esc_html_e( 'Active website', 'social-testimonials-and-reviews-widget' ); ?></label>
			<select id="accounts" class="rw-account-select"></select>
		</div>

		<a href="#" class="rw-topbar__action rw-open-dashboard" title="<?php esc_attr_e( 'Open the full Repuso dashboard', 'social-testimonials-and-reviews-widget' ); ?>">
			<span class="dashicons dashicons-external"></span>
			<span class="rw-topbar__action-text"><?php esc_html_e( 'Full Dashboard', 'social-testimonials-and-reviews-widget' ); ?></span>
		</a>

		<a href="#" class="rw-topbar__action rw-topbar__action-danger rw-disconnect" title="<?php esc_attr_e( 'Disconnect this site from Repuso', 'social-testimonials-and-reviews-widget' ); ?>">
			<span class="dashicons dashicons-exit"></span>
			<span class="rw-topbar__action-text"><?php esc_html_e( 'Disconnect', 'social-testimonials-and-reviews-widget' ); ?></span>
		</a>

	</div>
</nav>
