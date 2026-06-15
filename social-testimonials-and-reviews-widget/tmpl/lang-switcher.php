<?php
/**
 * Shared language switcher dropdown. Included by both tmpl/topbar.php
 * (connected view) and tmpl/onboard.php (disconnected view) so the
 * language picker is always available regardless of connection state.
 *
 * Renders a flag-emoji button + popover list. Click handling lives in
 * js/rw-admin.js (which is enqueued on all Repuso plugin pages, even
 * when disconnected, so the onboard view can use the same selector).
 *
 * Expects $this to be the plugin object so we can call
 * get_supported_locales().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rw_locales        = $this->get_supported_locales();
$rw_current_locale = function_exists( 'get_user_locale' ) ? get_user_locale() : determine_locale();
// Resolve a flag fallback when the user is on a locale we don't
// ship (e.g. nl_NL): show the globe glyph instead.
$rw_current_flag   = isset( $rw_locales[ $rw_current_locale ] ) ? $rw_locales[ $rw_current_locale ]['flag'] : '🌐';
?>
<div class="rw-topbar__lang rw-lang-switcher">
	<button type="button" class="rw-lang__button rw-lang-toggle" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'Change language', 'social-testimonials-and-reviews-widget' ); ?>" title="<?php esc_attr_e( 'Change language', 'social-testimonials-and-reviews-widget' ); ?>">
		<span class="rw-lang__flag" aria-hidden="true"><?php echo esc_html( $rw_current_flag ); ?></span>
		<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
	</button>
	<ul class="rw-lang__menu" role="menu" style="display:none;">
		<?php foreach ( $rw_locales as $code => $info ) :
			$is_active = ( $code === $rw_current_locale );
		?>
			<li role="menuitem">
				<a href="#" class="rw-lang__item<?php echo $is_active ? ' is-active' : ''; ?>" data-locale="<?php echo esc_attr( $code ); ?>">
					<span class="rw-lang__flag" aria-hidden="true"><?php echo esc_html( $info['flag'] ); ?></span>
					<span class="rw-lang__label"><?php echo esc_html( $info['label'] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
