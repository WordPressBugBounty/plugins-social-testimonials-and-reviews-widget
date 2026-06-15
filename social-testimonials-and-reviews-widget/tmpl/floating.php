<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$nonce_url        = wp_nonce_url( admin_url( 'admin.php?page=pagewide_widget' ), 'floating-nonce' );
$saved_widget_id  = (string) get_option( 'repuso_floating_widget_id' );
$url_itself       = get_option( 'url_itself' );
$url_type         = get_option( 'url_type' );
$has_url_rules    = is_array( $url_itself ) && ! empty( $url_itself );
?>
<div id="rw-wrapper" data-rw-section="<?php echo esc_attr( isset( $this->currentSection ) ? $this->currentSection : '' ); ?>" data-rw-has-apikey="<?php echo $this->apiKey ? '1' : '0'; ?>">

	<?php require dirname( __FILE__ ) . '/topbar.php'; ?>

	<?php
	// Inner navigation for the Widgets section. Highlights "Floating widget".
	$widgets_url  = admin_url( 'admin.php?page=rw_widgets' );
	$floating_url = admin_url( 'admin.php?page=pagewide_widget' );
	?>
	<nav class="rw-subnav" aria-label="<?php esc_attr_e( 'Widget types', 'social-testimonials-and-reviews-widget' ); ?>">
		<a href="<?php echo esc_url( $widgets_url ); ?>" class="rw-subnav__tab"><?php esc_html_e( 'Widgets', 'social-testimonials-and-reviews-widget' ); ?></a>
		<a href="<?php echo esc_url( $floating_url ); ?>" class="rw-subnav__tab is-active"><?php esc_html_e( 'Floating widget', 'social-testimonials-and-reviews-widget' ); ?></a>
	</nav>

	<div class="rw-onboard__headline is-left">
		<h2><?php esc_html_e( 'Floating widget', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p><?php esc_html_e( 'Show a floating reviews badge on your site. Choose which pages it appears on below.', 'social-testimonials-and-reviews-widget' ); ?></p>
	</div>

	<?php
	// Success flash after the form save. The actual save handler in
	// pagewide_widget() already validates the nonce via wp_verify_nonce;
	// this template is rendered immediately after that runs, so the
	// presence of `repulso_save` in $_POST is just a "was this a save
	// request" check, not a security gate.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in pagewide_widget() before this template is included.
	if ( isset( $_POST['repulso_save'] ) && current_user_can( 'manage_options' ) ) : ?>
		<div class="rw-flash rw-flash-success" role="status">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Settings saved.', 'social-testimonials-and-reviews-widget' ); ?>
		</div>
	<?php endif; ?>

	<form action="<?php echo esc_url( $nonce_url ); ?>" method="post" class="rw-floating-form">

		<!-- ===== Widget selector ===== -->
		<section class="rw-section">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Pick a widget', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Choose which of your Repuso widgets should appear as the floating widget on your site.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>
			<div class="rw-section__body">
				<div id="rw-floating-loading" class="rw-section__loading" aria-live="polite">
					<span class="rw-section__spinner"></span>
				</div>

				<select id="rw-floating-select" class="rw-select" data-saved-id="<?php echo esc_attr( $saved_widget_id ); ?>" style="display:none;">
					<option value=""></option>
				</select>
				<input type="hidden" id="rw-floating-widget-id"   name="repuso_floating_widget_id"   value="<?php echo esc_attr( $saved_widget_id ); ?>" />
				<input type="hidden" id="rw-floating-widget-type" name="repuso_floating_widget_type" value="<?php echo esc_attr( (string) get_option( 'repuso_floating_widget_type' ) ); ?>" />

				<div id="rw-floating-empty" class="rw-empty" style="display:none;">
					<svg class="rw-empty__icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
						<rect x="8" y="10" width="32" height="28" rx="4" stroke="#d4d4d8" stroke-width="2"/>
						<path d="M14 20h20M14 26h20M14 32h12" stroke="#d4d4d8" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<h3 class="rw-empty__title"><?php esc_html_e( 'No floating widgets yet', 'social-testimonials-and-reviews-widget' ); ?></h3>
					<p class="rw-empty__body"><?php esc_html_e( 'Create one on the Repuso dashboard (Floating, Flash, or Floating badge), then pick it here.', 'social-testimonials-and-reviews-widget' ); ?></p>
					<a href="<?php echo esc_url( $this->appUrl . '#/widgets/new' ); ?>" target="_blank" rel="noopener" class="rw-button rw-button-primary rw-button-inline rw-open-dashboard" data-rw-path="/widgets/new">
						<?php esc_html_e( 'Create floating widget', 'social-testimonials-and-reviews-widget' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
			</div>
		</section>

		<!-- ===== Content types ===== -->
		<section class="rw-section">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Show on these content types', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Tick the WordPress page types where the floating widget should appear.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>
			<div class="rw-section__body">
				<div class="rw-check-grid">
					<?php foreach ( $this->pages_types as $pt ) :
						$slug    = sanitize_title( $pt );
						$checked = ( get_option( 'repuso_page_type_' . $slug ) === '1' );
					?>
						<label class="rw-check">
							<input type="hidden" name="repuso_page_type_<?php echo esc_attr( $slug ); ?>" value="0" />
							<input type="checkbox" name="repuso_page_type_<?php echo esc_attr( $slug ); ?>" value="1" <?php checked( $checked, true ); ?> />
							<span><?php echo esc_html( $pt ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<!-- ===== URL rules ===== -->
		<section class="rw-section">
			<header class="rw-section__head">
				<div>
					<h2 class="rw-section__title"><?php esc_html_e( 'Show or hide by URL', 'social-testimonials-and-reviews-widget' ); ?></h2>
					<p class="rw-section__lede"><?php esc_html_e( 'Use * as a wildcard. Leave a URL blank to remove that rule.', 'social-testimonials-and-reviews-widget' ); ?></p>
				</div>
			</header>
			<div class="rw-section__body">
				<div class="urls-wrapper">
					<?php
					if ( $has_url_rules ) {
						foreach ( $url_itself as $key => $value ) {
							$row_type = isset( $url_type[ $key ] ) ? sanitize_text_field( (string) $url_type[ $key ] ) : 'show';
							?>
							<div class="rw-url-row new-url">
								<select name="url_type[]" class="rw-url-row__select">
									<option <?php selected( $row_type, 'show' ); ?> value="show"><?php esc_html_e( 'Show', 'social-testimonials-and-reviews-widget' ); ?></option>
									<option <?php selected( $row_type, 'hide' ); ?> value="hide"><?php esc_html_e( 'Hide', 'social-testimonials-and-reviews-widget' ); ?></option>
								</select>
								<input type="text" name="url_itself[]" class="rw-url-row__input" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="/the-post-*" />
							</div>
							<?php
						}
					}
					?>
					<div class="rw-url-row new-url">
						<select name="url_type[]" class="rw-url-row__select">
							<option value="show"><?php esc_html_e( 'Show', 'social-testimonials-and-reviews-widget' ); ?></option>
							<option value="hide"><?php esc_html_e( 'Hide', 'social-testimonials-and-reviews-widget' ); ?></option>
						</select>
						<input type="text" name="url_itself[]" class="rw-url-row__input" value="" placeholder="/post-id-*" />
					</div>
				</div>
				<button type="button" id="add-new-url" class="rw-button rw-button-outline rw-button-inline">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add another rule', 'social-testimonials-and-reviews-widget' ); ?>
				</button>
			</div>
		</section>

		<!-- ===== Hide on specific pages ===== -->
		<?php if ( ! empty( $this->pages ) ) : ?>
			<section class="rw-section">
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Hide on these pages', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'The floating widget will not appear on any page you check here.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>
				<div class="rw-section__body">
					<div class="rw-check-grid">
						<?php foreach ( $this->pages as $pt ) :
							$checked = ( get_option( 'repuso_page_hide_' . esc_attr( $pt->ID ) ) == '1' );
						?>
							<label class="rw-check">
								<input type="hidden" name="repuso_page_hide_<?php echo esc_attr( $pt->ID ); ?>" value="0" />
								<input type="checkbox" name="repuso_page_hide_<?php echo esc_attr( $pt->ID ); ?>" value="1" <?php checked( $checked, true ); ?> />
								<span><?php echo esc_html( $pt->post_title ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<div class="rw-form-footer">
			<button type="submit" name="repulso_save" class="rw-button rw-button-primary rw-button-inline">
				<?php esc_html_e( 'Save settings', 'social-testimonials-and-reviews-widget' ); ?>
			</button>
		</div>

	</form>

</div>
