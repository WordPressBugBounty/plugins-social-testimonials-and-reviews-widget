<?php
/**
 * Reviews > Widgets > "AI and search engines" tab: the "Let AI read your reviews" setting and a preview.
 *
 * @var Repuso_AI_Visibility $ai
 * @var array                $widgets  [id, type, name]
 * @var array|null           $sel      picked widget
 * @var array|null           $data     its saved data (items, schema)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rw_on     = $ai->readable_on();
$rw_schema = ( $data && ! empty( $data['schema'] ) ) ? $data['schema'] : null;
$rw_type   = $rw_schema ? (string) ( $rw_schema['@type'] ?? '' ) : '';
$rw_rating = $rw_schema && ! empty( $rw_schema['aggregateRating'] ) ? $rw_schema['aggregateRating'] : null;
?>
<div class="rw-ai rw-ai-tab">
	<div class="rw-ai-tab__main">
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only "saved" flag after our own redirect. ?>
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Saved.', 'social-testimonials-and-reviews-widget' ); ?></p></div>
		<?php endif; ?>
		<section class="rw-section">
			<h2 class="rw-section__title"><?php esc_html_e( 'AI and search engines', 'social-testimonials-and-reviews-widget' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rw-ai-form">
				<input type="hidden" name="action" value="rw_ai_save">
				<?php wp_nonce_field( 'rw_ai_save' ); ?>
				<label class="rw-ai-check">
					<input type="checkbox" name="rw_ai_readable" value="1" <?php checked( $rw_on ); ?>>
					<span>
						<strong><?php esc_html_e( 'Let AI read your reviews', 'social-testimonials-and-reviews-widget' ); ?></strong>
						<span><?php esc_html_e( "Your widgets load reviews with JavaScript, which ChatGPT, Claude and Perplexity don't run. With this on, each widget also carries its latest 10 reviews as plain text in the page code, hidden from visitors. Visitors see the same widget as today.", 'social-testimonials-and-reviews-widget' ); ?></span>
					</span>
				</label>

				<div class="rw-ai-box">
					<div class="rw-ai-box__head">
						<strong><?php esc_html_e( 'Review data for search engines', 'social-testimonials-and-reviews-widget' ); ?></strong>
						<?php if ( $sel ) : ?>
							<a href="#" class="rw-open-dashboard" data-rw-path="<?php echo esc_attr( '/widgets/' . $sel['id'] ); ?>"><?php esc_html_e( 'Edit in Repuso', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
						<?php endif; ?>
					</div>
					<?php if ( $rw_schema ) : ?>
						<dl class="rw-ai-dl">
							<dt><?php esc_html_e( 'Business', 'social-testimonials-and-reviews-widget' ); ?></dt><dd><?php echo esc_html( (string) ( $rw_schema['name'] ?? '' ) ); ?></dd>
							<dt><?php esc_html_e( 'Type', 'social-testimonials-and-reviews-widget' ); ?></dt><dd><?php echo esc_html( $rw_type ); ?></dd>
							<?php if ( $rw_rating ) : ?>
								<dt><?php esc_html_e( 'Rating', 'social-testimonials-and-reviews-widget' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: average rating, 2: number of reviews. */ __( '%1$s from %2$s reviews', 'social-testimonials-and-reviews-widget' ), (string) ( $rw_rating['ratingValue'] ?? '' ), (string) ( $rw_rating['reviewCount'] ?? '' ) ) ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Review items', 'social-testimonials-and-reviews-widget' ); ?></dt><dd><?php echo ! empty( $rw_schema['review'] ) ? esc_html__( 'On', 'social-testimonials-and-reviews-widget' ) : esc_html__( 'Off', 'social-testimonials-and-reviews-widget' ); ?></dd>
						</dl>
						<p class="rw-ai-muted"><?php esc_html_e( "From the widget's schema settings in Repuso. With the setting on, it is added to the page code, so crawlers read it without JavaScript.", 'social-testimonials-and-reviews-widget' ); ?></p>
					<?php else : ?>
						<p class="rw-ai-muted"><?php esc_html_e( "Review data: Off. Turn it on in the widget's settings in Repuso.", 'social-testimonials-and-reviews-widget' ); ?></p>
					<?php endif; ?>
				</div>

				<p class="rw-ai-info"><?php esc_html_e( "Google doesn't show review stars for reviews collected from other sites. The plain-text list is for AI assistants and other crawlers reading them. Use the widget shortcodes: widgets pasted as embed code keep loading with JavaScript only.", 'social-testimonials-and-reviews-widget' ); ?></p>
				<p><button type="submit" class="rw-button rw-button-primary rw-button-inline"><?php esc_html_e( 'Save changes', 'social-testimonials-and-reviews-widget' ); ?></button></p>
			</form>
		</section>
	</div>

	<section class="rw-section rw-ai-preview" id="rw-ai-preview">
		<header class="rw-ai-preview__head">
			<strong><?php esc_html_e( 'Preview', 'social-testimonials-and-reviews-widget' ); ?></strong>
			<?php if ( count( $widgets ) > 1 ) : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="rw_widgets"><input type="hidden" name="tab" value="ai">
					<label class="screen-reader-text" for="rw-ai-w"><?php esc_html_e( 'Widget', 'social-testimonials-and-reviews-widget' ); ?></label>
					<select id="rw-ai-w" name="w" class="rw-ai-autosubmit">
						<?php foreach ( $widgets as $rw_w ) : ?>
							<option value="<?php echo esc_attr( $rw_w['id'] ); ?>" <?php selected( $sel && $sel['id'] === $rw_w['id'] ); ?>><?php echo esc_html( $rw_w['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			<?php elseif ( $sel ) : ?>
				<span><?php echo esc_html( $sel['name'] ); ?></span>
			<?php endif; ?>
			<span class="rw-ai-muted"><?php esc_html_e( 'for AI and search engines only', 'social-testimonials-and-reviews-widget' ); ?></span>
		</header>
		<?php if ( ! $sel ) : ?>
			<p class="rw-ai-muted"><?php esc_html_e( 'Create a widget first. The preview shows what is added for it.', 'social-testimonials-and-reviews-widget' ); ?></p>
		<?php else : ?>
			<div class="rw-ai-preview__today">
				<b><?php esc_html_e( 'Today: what AI and search engines read', 'social-testimonials-and-reviews-widget' ); ?></b>
				<code>&lt;rw-widget-<?php echo esc_html( $sel['type'] ); ?> data-rw-<?php echo esc_html( $sel['type'] ); ?>="<?php echo esc_html( $sel['id'] ); ?>"&gt;&lt;/rw-widget-<?php echo esc_html( $sel['type'] ); ?>&gt;</code>
				<span><?php esc_html_e( 'No reviews in the page. AI reads nothing here.', 'social-testimonials-and-reviews-widget' ); ?></span>
			</div>
			<div class="rw-ai-preview__on">
				<b><?php esc_html_e( 'With the setting on: what AI and search engines read', 'social-testimonials-and-reviews-widget' ); ?></b>
				<span class="rw-ai-muted"><?php esc_html_e( 'Hidden from visitors. People still see your widget exactly as today.', 'social-testimonials-and-reviews-widget' ); ?></span>
				<?php
				$rw_list = $ai->plain_list( $sel['type'], $data );
				if ( '' !== $rw_list ) {
					echo '<div class="rw-ai-preview__list">' . $rw_list . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every field escaped in plain_list().
				} elseif ( in_array( $sel['type'], Repuso_AI_Visibility::DATA_ONLY, true ) ) {
					echo '<p class="rw-ai-muted">' . esc_html__( 'This widget type shows a rating, not a list of reviews: only the review data is added.', 'social-testimonials-and-reviews-widget' ) . '</p>';
				}
				if ( $rw_schema ) {
					echo '<pre class="rw-ai-code">' . esc_html( wp_json_encode( $rw_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
				}
				?>
			</div>
		<?php endif; ?>
	</section>
</div>
