<?php
/**
 * Reviews > AI Visibility for a site that isn't connected to Repuso: the free check pitch.
 * No request to Repuso from this page; "Check free" opens repuso.com in a new tab.
 *
 * @var Repuso_AI_Visibility $ai
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rw_name = Repuso_AI_Visibility::site_name();
$rw_val  = '' !== $rw_name ? $rw_name : Repuso_AI_Visibility::domain();
?>
<div id="rw-wrapper" class="rw-ai rw-ai-unconnected" data-rw-section="ai">
	<section class="rw-section rw-ai-pitch">
		<span class="rw-ai-tag is-new"><?php esc_html_e( 'New', 'social-testimonials-and-reviews-widget' ); ?></span>
		<h1 class="rw-section__title"><?php esc_html_e( 'Does AI recommend you, or your competitors?', 'social-testimonials-and-reviews-widget' ); ?></h1>
		<p class="rw-section__lede"><?php esc_html_e( 'Customers ask ChatGPT, Gemini and Google Maps for a business like yours. See how often they name you, who they name instead, and what to fix first. Free, no account needed.', 'social-testimonials-and-reviews-widget' ); ?></p>
		<form class="rw-ai-free" method="get" action="https://repuso.com/ai-visibility/" target="_blank">
			<label for="rw-ai-q"><?php esc_html_e( 'Business name or website', 'social-testimonials-and-reviews-widget' ); ?></label>
			<div>
				<input id="rw-ai-q" type="text" name="q" value="<?php echo esc_attr( $rw_val ); ?>" maxlength="100">
				<input type="hidden" name="site" value="<?php echo esc_attr( Repuso_AI_Visibility::domain() ); ?>">
				<input type="hidden" name="utm_source" value="wordpress">
				<input type="hidden" name="utm_medium" value="plugin">
				<input type="hidden" name="utm_campaign" value="ai-page">
				<button type="submit" class="rw-button rw-button-primary rw-button-inline"><?php esc_html_e( 'Check free', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></button>
			</div>
			<span class="rw-ai-muted"><?php esc_html_e( 'Opens repuso.com in a new tab. About 2 minutes.', 'social-testimonials-and-reviews-widget' ); ?></span>
		</form>
	</section>
	<section class="rw-section rw-ai-connectbox">
		<div>
			<strong><?php esc_html_e( 'Already use Repuso?', 'social-testimonials-and-reviews-widget' ); ?></strong>
			<span><?php esc_html_e( 'Connect this site to see your AI Visibility score here, track it every week, and show your reviews with widgets.', 'social-testimonials-and-reviews-widget' ); ?></span>
		</div>
		<a class="rw-button rw-button-outline rw-button-inline" href="<?php echo esc_url( admin_url( 'admin.php?page=rw_dashboard' ) ); ?>"><?php esc_html_e( 'Connect Repuso', 'social-testimonials-and-reviews-widget' ); ?></a>
	</section>
</div>
