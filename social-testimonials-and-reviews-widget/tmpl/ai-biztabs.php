<?php
/**
 * Business tabs on the AI Visibility page when the sub-account has several businesses.
 *
 * @var array $sum Repuso_AI_Visibility::summary()
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php if ( count( (array) ( $sum['businesses'] ?? array() ) ) > 1 ) : ?>
		<nav class="rw-subnav rw-ai-biztabs" aria-label="<?php esc_attr_e( 'Businesses', 'social-testimonials-and-reviews-widget' ); ?>">
			<?php foreach ( $sum['businesses'] as $rw_b ) : ?>
				<a class="rw-subnav__tab<?php echo (int) $rw_b['id'] === (int) $sum['id'] ? ' is-active' : ''; ?>" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => 'rw_ai_visibility',
							'biz'  => (int) $rw_b['id'],
						),
						admin_url( 'admin.php' )
					)
				);
				?>
										">
					<i class="rw-ai-dot<?php echo $rw_b['tracked'] ? ' is-on' : ''; ?>" title="<?php echo $rw_b['tracked'] ? esc_attr__( 'Tracked', 'social-testimonials-and-reviews-widget' ) : esc_attr__( 'Not tracked', 'social-testimonials-and-reviews-widget' ); ?>"></i>
					<?php echo esc_html( $rw_b['name'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
