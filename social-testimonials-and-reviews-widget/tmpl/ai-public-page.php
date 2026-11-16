<?php
/**
 * Public review page card, pinned above the widget list (Reviews > Widgets), as on the Repuso dashboard.
 *
 * @var array $pp address, enabled, views, crawls, reviews
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rw_link = '[rw_review_page_link text="' . __( 'Read our reviews', 'social-testimonials-and-reviews-widget' ) . '"]';
?>
<section class="rw-section rw-ai rw-ai-pp">
	<div class="rw-ai-pp__thumb" aria-hidden="true"><i></i><span>&#9733;&#9733;&#9733;&#9733;&#9733;</span><i></i><i></i></div>
	<div class="rw-ai-pp__body">
		<div class="rw-ai-pp__title">
			<h2 class="rw-section__title"><?php esc_html_e( 'Your public review page', 'social-testimonials-and-reviews-widget' ); ?></h2>
			<span class="rw-ai-state<?php echo $pp['enabled'] ? '' : ' is-off'; ?>"><?php echo $pp['enabled'] ? esc_html__( 'On', 'social-testimonials-and-reviews-widget' ) : esc_html__( 'Off', 'social-testimonials-and-reviews-widget' ); ?></span>
		</div>
		<p class="rw-section__lede"><?php esc_html_e( 'Your reviews on one page that customers, Google and AI assistants can read. Always up to date, nothing to install.', 'social-testimonials-and-reviews-widget' ); ?></p>
		<code><?php echo esc_html( $pp['address'] ); ?></code>
		<?php if ( $pp['enabled'] ) : ?>
			<div class="rw-ai-pp__stats">
				<span><?php echo esc_html( sprintf( /* translators: %s: page views. */ __( '%s views, last 30 days', 'social-testimonials-and-reviews-widget' ), number_format_i18n( $pp['views'] ) ) ); ?></span>
				<span><?php echo esc_html( sprintf( /* translators: %s: crawler visits. */ __( '%s visits from Google and AI crawlers', 'social-testimonials-and-reviews-widget' ), number_format_i18n( $pp['crawls'] ) ) ); ?></span>
				<span><?php echo esc_html( sprintf( /* translators: %s: number of reviews. */ __( '%s reviews', 'social-testimonials-and-reviews-widget' ), number_format_i18n( $pp['reviews'] ) ) ); ?></span>
			</div>
			<div class="rw-ai-pp__link">
				<strong><?php esc_html_e( 'Link it from your site', 'social-testimonials-and-reviews-widget' ); ?></strong>
				<span><?php esc_html_e( 'AI assistants find your review page through your website. Add this shortcode to your footer or About page:', 'social-testimonials-and-reviews-widget' ); ?></span>
				<button type="button" class="rw-ai-copy" data-copy="<?php echo esc_attr( $rw_link ); ?>"><code><?php echo esc_html( $rw_link ); ?></code> <span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
			</div>
		<?php endif; ?>
	</div>
	<div class="rw-ai-pp__actions">
		<a href="#" class="rw-button rw-button-primary rw-button-inline rw-open-dashboard" data-rw-path="/widgets/public-page">
			<?php echo $pp['enabled'] ? esc_html__( 'Edit page', 'social-testimonials-and-reviews-widget' ) : esc_html__( 'Turn it on', 'social-testimonials-and-reviews-widget' ); ?>
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
		<?php if ( $pp['enabled'] ) : ?>
			<a class="rw-button rw-button-outline rw-button-inline" href="<?php echo esc_url( $pp['address'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
			<button type="button" class="rw-button rw-button-outline rw-button-inline rw-ai-copy" data-copy="<?php echo esc_attr( $pp['address'] ); ?>"><?php esc_html_e( 'Copy link', 'social-testimonials-and-reviews-widget' ); ?></button>
		<?php endif; ?>
	</div>
</section>
