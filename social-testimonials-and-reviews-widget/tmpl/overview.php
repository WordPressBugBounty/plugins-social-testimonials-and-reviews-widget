<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Each video card. start_time only set when we want to deep-link past the
// intro (matches the existing &start=35 on the third clip).
$videos = array(
	array(
		'id'         => 'OyCzfWMwh8I',
		'title'      => __( 'What is Repuso?', 'social-testimonials-and-reviews-widget' ),
		'subtitle'   => __( 'A 90-second overview of how Repuso collects reviews and turns them into widgets for your website.', 'social-testimonials-and-reviews-widget' ),
		'start_time' => 0,
	),
	array(
		'id'         => 'eQ5N3ZQpqD4',
		'title'      => __( 'Product overview', 'social-testimonials-and-reviews-widget' ),
		'subtitle'   => __( 'A walkthrough of the Repuso dashboard - channels, widgets, the Collect page, and review moderation.', 'social-testimonials-and-reviews-widget' ),
		'start_time' => 0,
	),
	array(
		'id'         => 'YGtbuuVRl-Q',
		'title'      => __( 'How to embed a reviews widget on a WordPress website', 'social-testimonials-and-reviews-widget' ),
		'subtitle'   => __( 'Pick a widget, copy the shortcode, paste it into any page or post - reviews appear instantly.', 'social-testimonials-and-reviews-widget' ),
		'start_time' => 35,
	),
);
?>
<div id="rw-wrapper" data-rw-section="<?php echo esc_attr( isset( $this->currentSection ) ? $this->currentSection : '' ); ?>" data-rw-has-apikey="<?php echo $this->apiKey ? '1' : '0'; ?>">

	<?php require dirname( __FILE__ ) . '/topbar.php'; ?>

	<div class="rw-onboard__headline is-left">
		<h2><?php esc_html_e( 'Help', 'social-testimonials-and-reviews-widget' ); ?></h2>
		<p><?php esc_html_e( 'Quick walkthroughs of what Repuso does and how to get the most out of it.', 'social-testimonials-and-reviews-widget' ); ?></p>
	</div>

	<?php
	// FAQ list (declared first so it can be rendered inside the left
	// column below). Pricing-page questions (from repuso.com/pricing)
	// plus plugin-specific ones that come up most often in support.
	// Uses native <details>/<summary> so no JS is needed for the toggle.
	$faqs = array(
		array(
			'q' => __( 'Where do I find my widget shortcode?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Open the Widgets tab in this plugin. Each widget row shows its shortcode (like [rw_grid id="123"]). Click it to copy. Paste into any WordPress page, post, or block to embed.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Why are my reviews not appearing on the page after I added the shortcode?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Most often this is a caching plugin holding the page contents. Clear your site cache and reload. Also check that the widget on Repuso has at least one approved review and a connected channel. Some widget types only render once the underlying script loads in the browser - if your theme strips scripts on certain pages, switch the widget to a different layout.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'How do I connect a new review channel (Google, Facebook, etc.)?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Use the Channels tab inside the plugin to see what is already connected. To add a new one, click "Connect a channel" to open the Repuso dashboard - new channels are added from there. Once added, reviews start flowing back into your widgets automatically.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'How long until reviews appear after I connect a channel?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Usually a matter of minutes, but it can take up to 24 hours depending on the channel and how many reviews are being imported. After the initial fetch, Repuso checks for new reviews automatically on a regular schedule.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Are new reviews pulled in automatically?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Yes. Once a channel is connected, Repuso monitors it for new reviews and pulls them in automatically as soon as they appear publicly on the source. New reviews can take up to 24 hours to populate, depending on when the channel was last checked and how the source surfaces them. If the channel is set to auto-approve 4 and 5 star ratings, new high-rated reviews are also displayed in your widgets straight away with no manual approval needed. Lower-rated reviews stay in the Inbox waiting for your approval.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Why are some reviews not showing in my widget?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Open the Reviews tab in the plugin. Anything in the Inbox is pending approval and will not display in widgets until you approve it. Reviews you dismissed are hidden by design. Approved reviews appear in widgets within a few seconds (your widget may also have its own filters like minimum star rating).', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'How do I customize how my widgets look?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Each widget has a designer in the Repuso dashboard - click Edit on the widget row in the Widgets tab to open it. You can change colors, fonts, layout, density, and more without touching code. On the Standard and Ultimate plans you can also add Custom CSS for fine-grained tweaks.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Can the same widget be used on multiple WordPress sites?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Yes. A widget shortcode can be embedded on as many pages and sites as you want - reviews and widget settings update centrally from Repuso, so a change applies everywhere instantly.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'How do I show the floating widget only on certain pages?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Open the Widgets → Floating widget tab. There you can pick the widget to use, restrict it to specific WordPress content types, exclude individual pages, and add Show/Hide rules by URL pattern.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'How do I switch between sub-accounts?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Use the account dropdown on the right side of the top bar inside the plugin. The dropdown only shows when you have more than one sub-account on Repuso. Switching there updates the data shown on every plugin page (Dashboard, Reviews, Channels, Widgets).', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Do you offer a free trial?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Yes - 10 days, fully functional, no credit card needed. Start it from repuso.com.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Can I cancel my subscription?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Yes, anytime. Your subscription will not renew. If you have paid and changed your mind within 7 days of payment, we will issue a full refund.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( 'Which payment methods do you accept?', 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'All major credit cards via Stripe.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( "I'm an agency or reseller - is this for me?", 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'Yes. Repuso offers a fully white-labeled solution for agencies and resellers with sub-accounts, custom domain, and reselling tools. See repuso.com/white-label-reputation-management for details.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( "What's a property?", 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'A property is a set of all channels, one per kind (1 x Google, 1 x Facebook, 1 x TripAdvisor etc). Usually used for multiple locations, services or rental properties of the same business. Reviews from different properties can be displayed together in widgets.', 'social-testimonials-and-reviews-widget' ),
		),
		array(
			'q' => __( "What's a sub account?", 'social-testimonials-and-reviews-widget' ),
			'a' => __( 'A sub account is a standalone account, usually used for a new business, service or client. It can have multiple properties and has its own Collect page and widgets.', 'social-testimonials-and-reviews-widget' ),
		),
	);
	?>

	<div class="rw-help-grid">
		<div class="rw-help-left">
			<section class="rw-section rw-help-chat">
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Chat with our team', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'Real humans, real fast. Drop us a message and we will reply during business hours.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>
				<div class="rw-section__body">
					<button type="button" class="rw-button rw-button-primary rw-button-inline rw-open-crisp">
						<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
						<?php esc_html_e( 'Start a chat', 'social-testimonials-and-reviews-widget' ); ?>
					</button>
				</div>
			</section>

			<section class="rw-section rw-faq">
				<header class="rw-section__head">
					<div>
						<h2 class="rw-section__title"><?php esc_html_e( 'Frequently asked questions', 'social-testimonials-and-reviews-widget' ); ?></h2>
						<p class="rw-section__lede"><?php esc_html_e( 'Quick answers to the things people ask most. Still stuck? Use the chat above.', 'social-testimonials-and-reviews-widget' ); ?></p>
					</div>
				</header>
				<div class="rw-section__body rw-faq__list">
					<?php foreach ( $faqs as $faq ) : ?>
						<details class="rw-faq__item">
							<summary class="rw-faq__q">
								<span class="rw-faq__q-text"><?php echo esc_html( $faq['q'] ); ?></span>
								<span class="rw-faq__chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
							</summary>
							<div class="rw-faq__a"><?php echo esc_html( $faq['a'] ); ?></div>
						</details>
					<?php endforeach; ?>
				</div>
			</section>
		</div>

		<div class="rw-video-grid">
			<?php foreach ( $videos as $v ) :
				$start = (int) $v['start_time'];
				$src   = 'https://www.youtube.com/embed/' . rawurlencode( $v['id'] ) . '?rel=0' . ( $start > 0 ? '&start=' . $start : '' );
			?>
				<section class="rw-section rw-video-card">
					<header class="rw-section__head">
						<div>
							<h2 class="rw-section__title"><?php echo esc_html( $v['title'] ); ?></h2>
							<p class="rw-section__lede"><?php echo esc_html( $v['subtitle'] ); ?></p>
						</div>
					</header>
					<div class="rw-marketing__video">
						<iframe
							loading="lazy"
							src="<?php echo esc_url( $src ); ?>"
							title="<?php echo esc_attr( $v['title'] ); ?>"
							frameborder="0"
							allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
							allowfullscreen></iframe>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
	</div>

	<p class="rw-overview-footer">
		<?php
		printf(
			/* translators: %s: link to repuso.com */
			esc_html__( 'Find out more at our website: %s', 'social-testimonials-and-reviews-widget' ),
			'<a href="https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=video-guides" target="_blank" rel="noopener">repuso.com</a>'
		);
		?>
	</p>

</div>
