<?php
/**
 * Reviews > AI Visibility, connected sites (inside tmpl/main.php's .rw-logged).
 * Read-only: the same summary as the Repuso dashboard's AI Visibility page; every action opens the app.
 *
 * @var Repuso_AI_Visibility $ai
 * @var bool                 $ok   account admin with AI Visibility on
 * @var array|null           $sum  Repuso_AI_Visibility::summary()
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rw_name = ( $sum && '' !== $sum['name'] ) ? $sum['name'] : Repuso_AI_Visibility::domain();
?>
<div class="rw-ai rw-ai-page">
<?php if ( ! $ok ) : ?>
	<section class="rw-section">
		<h2 class="rw-section__title"><?php esc_html_e( 'AI Visibility', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p class="rw-section__lede"><?php esc_html_e( 'AI Visibility is managed by your Repuso account admin. Ask them to share the results with you.', 'social-testimonials-and-reviews-widget' ); ?></p>
	</section>

<?php elseif ( ! $sum || 'none' === $sum['state'] ) : ?>
	<section class="rw-section">
		<h2 class="rw-section__title"><?php esc_html_e( 'AI Visibility', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p class="rw-section__lede"><?php esc_html_e( 'No AI Visibility results for this site yet.', 'social-testimonials-and-reviews-widget' ); ?></p>
	</section>

<?php elseif ( 'pending' === $sum['state'] ) : ?>
	<?php require __DIR__ . '/ai-biztabs.php'; ?>
	<section class="rw-section">
		<h2 class="rw-section__title"><?php echo esc_html( $sum['name'] ); ?></h2>
		<p class="rw-section__lede"><?php esc_html_e( "The first check hasn't finished yet. Results show here once it's done.", 'social-testimonials-and-reviews-widget' ); ?></p>
		<div class="rw-ai-actions"><a class="rw-button rw-button-outline rw-button-inline" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'check/' . (int) $sum['id'], 'page-pending' ) ); ?>"><?php esc_html_e( 'Open in AI Visibility', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>
	</section>

<?php elseif ( 'add' === $sum['state'] ) : ?>
	<section class="rw-section rw-ai-pitch">
		<h2 class="rw-section__title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: business name or website. */
					__( 'Track %s in AI Visibility', 'social-testimonials-and-reviews-widget' ),
					$rw_name
				)
			);
			?>
		</h2>
		<p class="rw-section__lede"><?php esc_html_e( 'See how often ChatGPT, Gemini, Claude, Perplexity and Google Maps recommend this business, who they name instead, and what to fix first. Results show here once the first check runs.', 'social-testimonials-and-reviews-widget' ); ?></p>
		<div class="rw-ai-actions">
			<a class="rw-button rw-button-primary rw-button-inline" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'add?' . ( ! empty( $sum['place_id'] ) ? 'place=' . rawurlencode( $sum['place_id'] ) . '&' : '' ) . 'website=' . (int) $ai->rw_website(), 'page-add' ) ); ?>">
				<?php esc_html_e( 'Add to AI Visibility', 'social-testimonials-and-reviews-widget' ); ?>
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
			</a>
		</div>
	</section>

<?php elseif ( 'offer' === $sum['state'] ) : ?>
	<section class="rw-section rw-ai-pitch">
		<span class="rw-ai-tag is-new"><?php esc_html_e( 'New', 'social-testimonials-and-reviews-widget' ); ?></span>
		<h2 class="rw-section__title"><?php esc_html_e( 'Does AI recommend you, or your competitors?', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p class="rw-section__lede">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: business name or website. */
					__( 'Customers ask ChatGPT, Gemini and Google Maps for a business like yours. See how often they name %s, who they name instead, which sites they read, and what to fix first.', 'social-testimonials-and-reviews-widget' ),
					$rw_name
				)
			);
			?>
		</p>
		<div class="rw-ai-actions">
			<a class="rw-button rw-button-primary rw-button-inline" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'add?' . ( ! empty( $sum['place_id'] ) ? 'place=' . rawurlencode( $sum['place_id'] ) . '&' : '' ) . 'website=' . (int) $ai->rw_website(), 'page-offer' ) ); ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: business name or website. */
						__( 'Check %s free', 'social-testimonials-and-reviews-widget' ),
						$rw_name
					)
				);
				?>
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
			</a>
			<span class="rw-ai-muted"><?php esc_html_e( 'About 2 minutes. No card needed.', 'social-testimonials-and-reviews-widget' ); ?></span>
		</div>
	</section>

<?php else : ?>
	<?php
	$rw_full    = $ai->link( 'check/' . (int) $sum['id'], 'page-report' );
	$rw_tracked = ( 'tracked' === $sum['state'] );
	?>
	<div class="rw-ai-head">
		<div>
			<h1 class="rw-ai-title">
				<?php esc_html_e( 'AI Visibility', 'social-testimonials-and-reviews-widget' ); ?>
				<span class="rw-ai-tag<?php echo $rw_tracked ? ' is-on' : ''; ?>"><?php echo esc_html( Repuso_AI_Visibility::status_label( $sum ) ); ?></span>
			</h1>
			<p class="rw-ai-muted">
				<?php
				echo esc_html(
					$sum['name'] . ' · ' . sprintf(
						/* translators: %s: date of the last check. */
						__( 'checked %s', 'social-testimonials-and-reviews-widget' ),
						date_i18n( get_option( 'date_format' ), strtotime( $sum['checked'] ) )
					)
				);
				?>
			</p>
		</div>
	</div>

	<?php require __DIR__ . '/ai-biztabs.php'; ?>

	<section class="rw-section rw-ai-overview">
		<?php
		$rw_rate = (int) $sum['rate'];
		?>
		<div class="rw-ai-ring rw-ai-ring--lg<?php echo $rw_tracked ? '' : ' is-muted'; ?>" style="--p:<?php echo (int) $rw_rate; ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent. */ __( '%d%% of AI answers recommend you', 'social-testimonials-and-reviews-widget' ), $rw_rate ) ); ?>">
			<div><b><?php echo (int) $rw_rate; ?>%</b><span><?php esc_html_e( 'of answers', 'social-testimonials-and-reviews-widget' ); ?></span></div>
		</div>
		<div class="rw-ai-overview__text">
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: answers naming the business, 2: all answers. */
						__( 'AI recommends you in %1$d of %2$d answers', 'social-testimonials-and-reviews-widget' ),
						$sum['mentioned'],
						$sum['answers']
					)
				);
				?>
				<?php if ( null !== $sum['delta'] && 0 !== $sum['delta'] ) : ?>
					<span class="rw-ai-pill <?php echo $sum['delta'] > 0 ? 'up' : 'down'; ?>"><?php echo esc_html( sprintf( /* translators: %s: signed change in points. */ __( '%s pts', 'social-testimonials-and-reviews-widget' ), ( $sum['delta'] > 0 ? '+' : '' ) . (int) $sum['delta'] ) ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( $sum['silent'] ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of questions. */
							_n( '%d question gets no mention of you at all.', '%d questions get no mention of you at all.', $sum['silent'], 'social-testimonials-and-reviews-widget' ),
							$sum['silent']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<div class="rw-ai-bars">
				<?php foreach ( $sum['ai'] as $rw_a ) : ?>
					<div class="rw-ai-bar"><span><?php echo esc_html( $rw_a['name'] ); ?></span><i><b style="width:<?php echo (int) $rw_a['pct']; ?>%"></b></i><em><?php echo (int) $rw_a['pct']; ?>%</em></div>
				<?php endforeach; ?>
			</div>
		</div>
		<a class="rw-button rw-button-inline <?php echo $rw_tracked ? 'rw-button-primary' : 'rw-button-outline'; ?> rw-ai-overview__btn" target="_blank" rel="noopener" href="<?php echo esc_url( $rw_full ); ?>">
			<?php esc_html_e( 'Full report', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
	</section>

	<?php if ( ! $rw_tracked ) : ?>
		<section class="rw-section rw-ai-nudge rw-ai-nudge--page">
			<div>
				<strong><?php echo $sum['free_left'] > 0 ? esc_html__( 'AI answers change every week', 'social-testimonials-and-reviews-widget' ) : esc_html__( 'Your free checks have ended', 'social-testimonials-and-reviews-widget' ); ?></strong>
				<span>
					<?php
					echo '' !== $sum['rival']
						? esc_html( sprintf( /* translators: 1: business, 2: competitor. */ __( "Track %1\$s weekly to see if you're gaining on %2\$s, and get the fixes that move your score.", 'social-testimonials-and-reviews-widget' ), $sum['name'], $sum['rival'] ) )
						: esc_html( sprintf( /* translators: %s: business. */ __( 'Track %s weekly to see where you stand, and get the fixes that move your score.', 'social-testimonials-and-reviews-widget' ), $sum['name'] ) );
					?>
				</span>
			</div>
			<a class="rw-button rw-button-primary rw-button-inline" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'billing', 'page-track' ) ); ?>"><?php esc_html_e( 'Start tracking', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
		</section>
	<?php endif; ?>

	<div class="rw-ai-cards">
		<a class="rw-section rw-ai-kpi" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'questions/' . (int) $sum['id'], 'page-questions' ) ); ?>">
			<span class="rw-ai-kpi__label"><?php esc_html_e( 'Questions', 'social-testimonials-and-reviews-widget' ); ?></span>
			<span class="rw-ai-kpi__value"><?php echo (int) $sum['silent']; ?> <small><?php echo esc_html( sprintf( /* translators: %d: questions asked. */ __( 'of %d', 'social-testimonials-and-reviews-widget' ), $sum['questions'] ) ); ?></small></span>
			<span><?php esc_html_e( 'questions never name you', 'social-testimonials-and-reviews-widget' ); ?></span>
			<span class="rw-ai-more"><?php esc_html_e( 'Review the questions', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></span>
		</a>
		<a class="rw-section rw-ai-kpi" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'competitors/' . (int) $sum['id'], 'page-competitors' ) ); ?>">
			<span class="rw-ai-kpi__label"><?php esc_html_e( 'Competitors', 'social-testimonials-and-reviews-widget' ); ?></span>
			<span class="rw-ai-kpi__value"><?php echo (int) $sum['competitors']; ?></span>
			<span>
				<?php
				if ( '' !== $sum['rival'] ) {
					printf(
						/* translators: 1: competitor name, 2: times named. */
						esc_html__( '%1$s named %2$d&times; in your answers', 'social-testimonials-and-reviews-widget' ),
						'<b>' . esc_html( $sum['rival'] ) . '</b>',
						(int) $sum['rival_times']
					);
				}
				?>
			</span>
			<span class="rw-ai-more"><?php esc_html_e( 'See why AI prefers them', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></span>
		</a>
		<?php if ( ! $ai->readable_on() ) : ?>
			<div class="rw-section rw-ai-kpi is-fix">
				<span class="rw-ai-kpi__label"><?php esc_html_e( 'Improve', 'social-testimonials-and-reviews-widget' ); ?></span>
				<span><?php esc_html_e( 'Start with: let AI read your reviews. This plugin can do it for you.', 'social-testimonials-and-reviews-widget' ); ?></span>
				<a class="rw-button rw-button-primary rw-button-inline" href="<?php echo esc_url( admin_url( 'admin.php?page=rw_widgets&tab=ai' ) ); ?>"><?php esc_html_e( 'Turn it on', 'social-testimonials-and-reviews-widget' ); ?></a>
			</div>
		<?php else : ?>
			<a class="rw-section rw-ai-kpi" target="_blank" rel="noopener" href="<?php echo esc_url( $ai->link( 'improve/' . (int) $sum['id'], 'page-improve' ) ); ?>">
				<span class="rw-ai-kpi__label"><?php esc_html_e( 'Improve', 'social-testimonials-and-reviews-widget' ); ?></span>
				<span><?php esc_html_e( 'Your reviews are readable by AI. See what else moves your score.', 'social-testimonials-and-reviews-widget' ); ?></span>
				<span class="rw-ai-more"><?php esc_html_e( 'See what to fix', 'social-testimonials-and-reviews-widget' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></span>
			</a>
		<?php endif; ?>
	</div>

	<?php
	$rw_tone  = (array) $sum['tone'];
	$rw_total = max( 1, array_sum( array_map( 'intval', $rw_tone ) ) );
	if ( array_sum( array_map( 'intval', $rw_tone ) ) > 0 ) :
		$rw_pos = (int) round( 100 * (int) ( $rw_tone['positive'] ?? 0 ) / $rw_total );
		$rw_neg = (int) round( 100 * (int) ( $rw_tone['negative'] ?? 0 ) / $rw_total );
		$rw_neu = max( 0, 100 - $rw_pos - $rw_neg );
		?>
		<section class="rw-section rw-ai-tone">
			<h3><?php esc_html_e( 'How AI talks about you', 'social-testimonials-and-reviews-widget' ); ?></h3>
			<div class="rw-ai-tonebar" aria-hidden="true"><b class="pos" style="width:<?php echo (int) $rw_pos; ?>%"></b><b class="neu" style="width:<?php echo (int) $rw_neu; ?>%"></b><b class="neg" style="width:<?php echo (int) $rw_neg; ?>%"></b></div>
			<p class="rw-ai-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: positive percent, 2: neutral percent, 3: negative percent. */
						__( '%1$d%% positive, %2$d%% neutral, %3$d%% negative', 'social-testimonials-and-reviews-widget' ),
						$rw_pos,
						$rw_neu,
						$rw_neg
					)
				);
				?>
			</p>
			<div class="rw-ai-said">
				<?php if ( $sum['praise'] ) : ?>
					<div><h4 class="pos"><?php esc_html_e( 'Praised', 'social-testimonials-and-reviews-widget' ); ?></h4><ul>
					<?php
					foreach ( $sum['praise'] as $rw_p ) :
						?>
						<li><?php echo esc_html( $rw_p ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<?php if ( $sum['complaint'] ) : ?>
					<div><h4 class="neg"><?php esc_html_e( 'Criticized', 'social-testimonials-and-reviews-widget' ); ?></h4><ul>
					<?php
					foreach ( $sum['complaint'] as $rw_p ) :
						?>
						<li><?php echo esc_html( $rw_p ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>
<?php endif; ?>
</div>
