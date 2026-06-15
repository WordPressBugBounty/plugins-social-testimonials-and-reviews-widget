<?php
/**
 * Shared "not-connected" onboard view (brand block + signup/login cards +
 * marketing strip + video). Included by tmpl/main.php and
 * tmpl/overview-dashboard.php so unconnected users see the same shell no
 * matter which Repuso menu item they click first.
 *
 * The includer may set $rw_onboard_visible = true to render the view
 * directly. When unset, the wrapper is hidden; js/rw-admin.js then
 * toggles it on/off based on the connection check.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$rw_onboard_visible = isset( $rw_onboard_visible ) ? (bool) $rw_onboard_visible : false;
?>
<div class="rw-not-logged"<?php echo $rw_onboard_visible ? '' : ' style="display:none;"'; ?>>

	<!-- Language picker for users who haven't connected yet. Pinned to the
	     top-right of the onboard surface so users on a non-English WordPress
	     can switch the plugin UI to their language before signing up or
	     signing in (the email-OTP login email also localises off this). -->
	<div class="rw-onboard__lang">
		<?php require dirname( __FILE__ ) . '/lang-switcher.php'; ?>
	</div>

	<div class="rw-brand">
		<a href="https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=brand" target="_blank" rel="noopener">
			<img src="<?php echo esc_url( $this->plugin_url . 'images/repuso-logo.svg' ); ?>" alt="<?php esc_attr_e( 'Repuso', 'social-testimonials-and-reviews-widget' ); ?>" class="rw-brand__logo" />
		</a>
	</div>

	<div class="rw-onboard__headline">
		<h2><?php esc_html_e( 'Connect Repuso to start showing social proof', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p><?php esc_html_e( 'Google, Facebook, TripAdvisor, Airbnb and 45+ more, embedded on your site in a few clicks.', 'social-testimonials-and-reviews-widget' ); ?></p>
	</div>

	<div class="rw-cards">

		<!-- Signup card -->
		<div class="rw-card">
			<div class="rw-card__inner" id="rw-signup-form-wrap">
				<h3><?php esc_html_e( "I'm new to Repuso", 'social-testimonials-and-reviews-widget' ); ?></h3>
				<p class="rw-card__subtitle"><?php esc_html_e( "One-click signup. We'll email you your login details.", 'social-testimonials-and-reviews-widget' ); ?></p>

				<form id="rw-signup-form" novalidate>
					<div data-status class="rw-onboard__status"></div>

					<div class="rw-field">
						<label class="rw-field__label" for="rw-signup-email"><?php esc_html_e( 'Email', 'social-testimonials-and-reviews-widget' ); ?></label>
						<input id="rw-signup-email" class="rw-field__input" type="email" name="email" required autocomplete="email" />
						<div class="rw-field__error" data-error-for="email"></div>
					</div>

					<button type="submit" class="rw-button rw-button-primary" data-action="submit-signup">
						<span data-label><?php esc_html_e( 'Create my Repuso account', 'social-testimonials-and-reviews-widget' ); ?></span>
					</button>

					<p class="rw-onboard__trust"><?php esc_html_e( 'Free trial. No credit card. Cancel anytime.', 'social-testimonials-and-reviews-widget' ); ?></p>

					<div class="rw-signup__learnmore">
						<a class="rw-learnmore-link" href="https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=learn-more" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more about Repuso →', 'social-testimonials-and-reviews-widget' ); ?></a>
					</div>
				</form>
			</div>

			<div class="rw-card__inner" id="rw-signup-success" style="display:none;">
				<div class="rw-onboard__success">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
					</svg>
					<h3><?php esc_html_e( 'Account created', 'social-testimonials-and-reviews-widget' ); ?></h3>
					<p><?php esc_html_e( "We've emailed you your login details.", 'social-testimonials-and-reviews-widget' ); ?></p>
					<p class="rw-onboard__redirecting">
						<span class="rw-onboard__spinner" aria-hidden="true"></span>
						<?php esc_html_e( 'Redirecting to your dashboard…', 'social-testimonials-and-reviews-widget' ); ?>
					</p>
				</div>
			</div>
		</div>

		<!-- Login card -->
		<div class="rw-card">
			<div class="rw-card__inner">
				<h3><?php esc_html_e( "I already have a Repuso account", 'social-testimonials-and-reviews-widget' ); ?></h3>
				<p class="rw-card__subtitle"><?php esc_html_e( 'Sign in to connect your existing account.', 'social-testimonials-and-reviews-widget' ); ?></p>

				<form id="rw-login-form" novalidate>
					<div data-status class="rw-onboard__status"></div>

					<div class="rw-field">
						<label class="rw-field__label" for="rw-login-email"><?php esc_html_e( 'Email', 'social-testimonials-and-reviews-widget' ); ?></label>
						<input id="rw-login-email" class="rw-field__input" type="email" name="email" required autocomplete="email" />
					</div>

					<!-- All three steps live inside this grid wrapper so they
					     stack in the same cell. The card's height stays equal
					     to the tallest step regardless of which is visible -
					     no shrink/grow when switching to OTP mode, no
					     mismatched height vs the signup card next to it. -->
					<div class="rw-login__steps">

					<!-- Default step: email + password. Bottom link swaps the
					     bottom of the form to the OTP request step. -->
					<div class="rw-login__step is-current" data-login-step="password">
						<div class="rw-field">
							<label class="rw-field__label" for="rw-login-password"><?php esc_html_e( 'Password', 'social-testimonials-and-reviews-widget' ); ?></label>
							<input id="rw-login-password" class="rw-field__input" type="password" name="password" autocomplete="current-password" />
							<div class="rw-field__error" data-error-for="password"></div>
						</div>

						<button type="submit" class="rw-button rw-button-outline" data-action="submit-login">
							<span data-label><?php esc_html_e( 'Sign in', 'social-testimonials-and-reviews-widget' ); ?></span>
						</button>

						<p class="rw-card__hint">
							<a href="#" data-action="switch-to-code"><?php esc_html_e( 'Email me a login code instead', 'social-testimonials-and-reviews-widget' ); ?></a>
						</p>
					</div>

					<!-- OTP step 1: send the code. Bottom link goes back to
					     the password form. -->
					<div class="rw-login__step" data-login-step="code-request">
						<p class="rw-step__intro"><?php esc_html_e( "We'll email you a 6-digit code. Enter it here to sign in - no password needed.", 'social-testimonials-and-reviews-widget' ); ?></p>

						<button type="button" class="rw-button rw-button-outline" data-action="send-login-code">
							<span data-label><?php esc_html_e( 'Email me a code', 'social-testimonials-and-reviews-widget' ); ?></span>
						</button>

						<p class="rw-card__hint">
							<a href="#" data-action="switch-to-password"><?php esc_html_e( 'Use password instead', 'social-testimonials-and-reviews-widget' ); ?></a>
						</p>
					</div>

					<!-- OTP step 2: enter the code we just emailed. Same
					     back-to-password escape hatch here too. -->
					<div class="rw-login__step" data-login-step="code-verify">
						<p class="rw-step__intro" data-code-sent-hint></p>

						<div class="rw-field">
							<label class="rw-field__label" for="rw-login-code"><?php esc_html_e( '6-digit code', 'social-testimonials-and-reviews-widget' ); ?></label>
							<input id="rw-login-code" class="rw-field__input rw-field__input-code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" />
							<div class="rw-field__error" data-error-for="code"></div>
						</div>

						<button type="submit" class="rw-button rw-button-outline" data-action="verify-login-code">
							<span data-label><?php esc_html_e( 'Sign in with code', 'social-testimonials-and-reviews-widget' ); ?></span>
						</button>

						<p class="rw-card__hint">
							<a href="#" data-action="resend-login-code"><?php esc_html_e( 'Resend code', 'social-testimonials-and-reviews-widget' ); ?></a>
							<span aria-hidden="true"> · </span>
							<a href="#" data-action="switch-to-password"><?php esc_html_e( 'Use password instead', 'social-testimonials-and-reviews-widget' ); ?></a>
						</p>
					</div>

					</div><!-- /.rw-login__steps -->
				</form>
			</div>
		</div>

	</div><!-- /.rw-cards -->

	<br class="clear" />

	<!-- Marketing card: logo (centered, top), bullets, and video stacked vertically. -->
	<div class="rw-marketing">

		<div class="rw-marketing__logo">
			<a href="https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=marketing" target="_blank" rel="noopener">
				<img src="<?php echo esc_url( $this->plugin_url . 'images/repuso-logo.svg' ); ?>" alt="<?php esc_attr_e( 'Repuso', 'social-testimonials-and-reviews-widget' ); ?>" />
			</a>
		</div>

		<div class="rw-marketing__copy">
			<ul class="rw-marketing__features">
				<li><strong><?php esc_html_e( '50+ review platforms:', 'social-testimonials-and-reviews-widget' ); ?></strong> <?php esc_html_e( 'Google, Facebook, Airbnb, TripAdvisor, Booking.com and many more.', 'social-testimonials-and-reviews-widget' ); ?></li>
				<li><strong><?php esc_html_e( '12+ widget styles:', 'social-testimonials-and-reviews-widget' ); ?></strong> <?php esc_html_e( 'grid, slider, badges, floating widget. Drop them anywhere with a shortcode.', 'social-testimonials-and-reviews-widget' ); ?></li>
				<li><strong><?php esc_html_e( 'Built-in collection:', 'social-testimonials-and-reviews-widget' ); ?></strong> <?php esc_html_e( 'request reviews by email, SMS, QR or NFC card.', 'social-testimonials-and-reviews-widget' ); ?></li>
				<li><strong><?php esc_html_e( 'White-label ready:', 'social-testimonials-and-reviews-widget' ); ?></strong> <?php esc_html_e( 'resell to your clients under your own brand.', 'social-testimonials-and-reviews-widget' ); ?></li>
			</ul>
		</div>

		<div class="rw-marketing__video">
			<iframe
				loading="lazy"
				src="https://www.youtube.com/embed/OyCzfWMwh8I?rel=0"
				title="<?php esc_attr_e( 'Repuso product overview', 'social-testimonials-and-reviews-widget' ); ?>"
				frameborder="0"
				allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
				allowfullscreen></iframe>
		</div>

	</div>

	<br class="clear" />
</div>
