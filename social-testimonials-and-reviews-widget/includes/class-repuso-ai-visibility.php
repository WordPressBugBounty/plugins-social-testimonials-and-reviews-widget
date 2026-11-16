<?php
/**
 * AI Visibility in WordPress (admin) and "Let AI read your reviews" (public pages).
 *
 * Admin: Reviews > AI Visibility page, WordPress Dashboard widget, plugin Dashboard card,
 * the "AI and search engines" tab on Reviews > Widgets, the public review page card.
 * Public pages: the widget's latest reviews as plain HTML inside the widget tag (hidden by
 * the widget itself) and its review data (JSON-LD) printed server-side.
 *
 * Every Repuso call is server-side with the stored key, cached, and never blocks a visitor.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repuso_AI_Visibility {

	/** @var RepusoIntegration */
	private $rw;

	/** Widget types that show a list of reviews get the hidden list; the rest only the review data. */
	const LIST_TYPES = array( 'grid', 'list', 'slider', 'masonry', 'mediawall', 'inline', 'photoset' );
	const DATA_ONLY  = array( 'floating', 'badge1', 'badge2', 'flash' );
	/** Platforms the widgets don't name. */
	const UNNAMED = array( 'trustpilot', 'yelp', 'findlaw' );

	/** One review data block per page, even with several widgets. */
	private $schema_printed = false;

	public function __construct( $rw ) {
		$this->rw = $rw;

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 20 );
		add_action( 'admin_head', array( $this, 'menu_badge_css' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'rw_overview_after_kpis', array( $this, 'plugin_dashboard_card' ) );
		add_action( 'rw_widgets_before_list', array( $this, 'public_page_card' ) );
		add_action( 'admin_post_rw_ai_save', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_rw_ai_card', array( $this, 'ajax_card' ) );
		add_action( 'wp_ajax_rw_ai_dismiss', array( $this, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_rw_ai_app_url', array( $this, 'ajax_app_url' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
		add_action( 'rw_ai_refresh', array( $this, 'cron_refresh' ) );
		add_action( 'rw_ai_refresh_widget', array( $this, 'refresh_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_style' ) );
		add_shortcode( 'rw_review_page_link', array( $this, 'review_page_link' ) );
	}

	// ------------------------------------------------------------------ helpers

	private function connected() {
		return ! empty( $this->rw->apiKey );
	}

	private function website() {
		return (int) $this->rw->websiteId;
	}

	/** HTTP Basic auth with the stored key (blank user), as the rest of the plugin. */
	private function auth() {
		return 'Basic ' . base64_encode( ':' . $this->rw->apiKey ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header, not obfuscation.
	}

	public function rw_website() {
		return $this->website();
	}

	/** GET a Repuso API path with the stored key. Decoded array, or null on any failure. */
	private function api_get( $path, $timeout = 5 ) {
		if ( ! $this->connected() ) {
			return null;
		}
		$res = wp_remote_get(
			$this->rw->apiUrl . ltrim( $path, '/' ),
			array(
				'timeout' => $timeout,
				'headers' => array( 'Authorization' => $this->auth() ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) ? $body : null;
	}

	/** The site's domain, without www. */
	public static function domain() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	/** The site title when it looks like a business name, else ''. */
	public static function site_name() {
		$name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return '';
		}
		$low      = strtolower( $name );
		$defaults = array( 'wordpress', 'my wordpress site', 'my wordpress blog', 'my blog', 'my site', 'my website', 'just another wordpress site', 'site title', 'test', 'demo' );
		if ( in_array( $low, $defaults, true ) || preg_replace( '/^www\./', '', $low ) === self::domain() ) {
			return '';
		}
		return $name;
	}

	/** AI Visibility app link (Repuso app URL /app/ -> /ai-visibility/app/), with UTM before the hash. */
	private function app_link( $path, $campaign ) {
		$base = preg_replace( '#/app/?$#', '/ai-visibility/app/', rtrim( (string) $this->rw->appUrl, '/' ) . '/' );
		$utm  = 'utm_source=wordpress&utm_medium=plugin&utm_campaign=' . rawurlencode( $campaign ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- UTM value, lowercase on purpose.
		return $base . '?' . $utm . ( $path ? '#/' . $path : '' );
	}

	/** Free check on repuso.com for a site that isn't connected (name and/or website). */
	public static function free_check_link( $campaign ) {
		$args = array(
			'site'         => self::domain(),
			'utm_source'   => 'wordpress',
			'utm_medium'   => 'plugin',
			'utm_campaign' => $campaign,
		);
		$name = self::site_name();
		if ( '' !== $name ) {
			$args['q'] = $name;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://repuso.com/ai-visibility/' );
	}

	/** Who sees AI Visibility: admins of accounts with it on. Cached an hour. */
	private function account() {
		$key = 'rw_ai_account_' . $this->website();
		$acc = get_transient( $key );
		if ( false === $acc ) {
			$info = $this->api_get( 'account/info' );
			$acc  = array(
				'ok'    => is_array( $info ),
				'admin' => ! empty( $info['is_admin'] ),
				'on'    => ! empty( $info['visibility'] ),
				'white' => ! empty( $info['white_label'] ),
			);
			set_transient( $key, $acc, $acc['ok'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}
		return $acc;
	}

	private function eligible() {
		if ( ! $this->connected() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$acc = $this->account();
		return $acc['ok'] && $acc['admin'] && $acc['on'];
	}

	public function readable_on() {
		return '1' === (string) get_option( 'rw_ai_readable', '0' );
	}

	private function tab_url() {
		return admin_url( 'admin.php?page=rw_widgets&tab=ai' );
	}

	// ------------------------------------------------------------------ summary data

	/**
	 * The AI Visibility summary for the current sub-account, as the Repuso dashboard home card picks it:
	 * state tracked | untracked | offer | none. Cached an hour; refreshed by cron.
	 */
	public function summary( $fetch = false, $biz = 0 ) {
		$biz = (int) $biz; // 0 = the default business (as the Repuso dashboard home card picks it)
		$key = 'rw_ai_sum4_' . $this->website() . ( $biz ? '_' . $biz : '' );
		$sum = get_transient( $key );
		if ( false !== $sum && ! $fetch ) {
			return $sum;
		}
		if ( ! $fetch ) {
			return null;
		}
		$w = $this->website();
		$d = $this->api_get( 'visibility/dashboard?website_id=' . $w );
		if ( ! is_array( $d ) ) {
			return false === $sum ? null : $sum; // keep the last copy on failure
		}
		$acc  = $this->account();
		$bizs = isset( $d['businesses'] ) && is_array( $d['businesses'] ) ? $d['businesses'] : array();
		$b    = null;
		foreach ( $bizs as $x ) {
			if ( $biz && (int) $x['id'] === $biz ) {
				$b = $x; // a tab on the AI Visibility page
				break;
			}
		}
		foreach ( $b ? array() : $bizs as $x ) {
			if ( ! empty( $x['location_id'] ) ) {
				$b = $x;
				break;
			}
		}
		if ( ! $b && $bizs ) {
			$b = $bizs[0];
		}
		$out      = array(
			'state'      => 'offer',
			'name'       => '',
			'subscribed' => ! empty( $d['subscribed'] ),
			'free_left'  => isset( $d['free']['left'] ) ? (int) $d['free']['left'] : 0,
			'at'         => time(),
			// every business in this sub-account, for the page's tabs
			'businesses' => array_map(
				function ( $x ) use ( $d ) {
					return array(
						'id'      => (int) $x['id'],
						'name'    => (string) $x['name'],
						'tracked' => ! empty( $d['subscribed'] ) && ! empty( $x['enabled'] ),
					);
				},
				$bizs
			),
		);
		$listings = isset( $d['listings'] ) && is_array( $d['listings'] ) ? $d['listings'] : array();

		if ( $b && ! empty( $b['last_check'] ) ) {
			$q   = ( ! empty( $b['locations'] ) && (int) $b['locations'] > 1 && ! empty( $b['location_id'] ) ) ? '?location=' . (int) $b['location_id'] : '';
			$res = $this->api_get( 'visibility/businesses/' . (int) $b['id'] . '/results' . $q, 8 );
			if ( is_array( $res ) && ! empty( $res['trend'] ) ) {
				$trend = $res['trend'];
				$last  = end( $trend );
				$prev  = count( $trend ) > 1 ? $trend[ count( $trend ) - 2 ] : null;
				$n     = 0;
				$m     = 0;
				$ai    = array();
				$names = array(
					'chatgpt'    => 'ChatGPT',
					'gemini'     => 'Gemini',
					'maps'       => 'Google Maps',
					'claude'     => 'Claude',
					'perplexity' => 'Perplexity',
				);
				foreach ( (array) ( $res['surfaces'] ?? array() ) as $k => $s ) {
					$n   += (int) ( $s['answers'] ?? 0 );
					$m   += (int) ( $s['mentioned'] ?? 0 );
					$ai[] = array(
						'name' => $names[ $k ] ?? ucfirst( (string) $k ),
						'pct'  => ! empty( $s['answers'] ) ? (int) round( 100 * (int) $s['mentioned'] / (int) $s['answers'] ) : 0,
					);
				}
				usort(
					$ai,
					function ( $a, $z ) {
						return $z['pct'] <=> $a['pct'];
					}
				);
				$silent = 0;
				$qs     = (array) ( $res['questions'] ?? array() );
				foreach ( $qs as $qq ) {
					$named = 0;
					foreach ( (array) ( $qq['surfaces'] ?? array() ) as $c ) {
						$named += (int) ( $c['mentioned'] ?? 0 );
					}
					if ( 0 === $named ) {
						++$silent;
					}
				}
				$said = (array) ( $res['said'] ?? array() );
				// said: {key: {label, times, answers}} (or a list), top 3 labels
				$labels = function ( $items ) {
					$out = array();
					foreach ( (array) $items as $p ) {
						$l = is_array( $p ) ? (string) ( $p['label'] ?? ( $p[0] ?? '' ) ) : (string) $p;
						if ( '' !== trim( $l ) ) {
							$out[] = trim( $l );
						}
					}
					return array_slice( $out, 0, 3 );
				};
				$out    = array_merge(
					$out,
					array(
						'state'       => ( ! empty( $res['subscribed'] ) && ! empty( $b['enabled'] ) ) ? 'tracked' : 'untracked', // tracked = subscribed and tracking on for this business
						'id'          => (int) $b['id'],
						'name'        => (string) $b['name'],
						'rate'        => (int) ( $last['pct'] ?? 0 ),
						'delta'       => $prev ? (int) $last['pct'] - (int) $prev['pct'] : null,
						'answers'     => $n,
						'mentioned'   => $m,
						'ai'          => array_slice( $ai, 0, 5 ),
						'rival'       => isset( $res['competitors'][0]['name'] ) ? (string) $res['competitors'][0]['name'] : '',
						'rival_times' => isset( $res['competitors'][0]['times'] ) ? (int) $res['competitors'][0]['times'] : 0,
						'competitors' => (int) ( $res['counts']['competitors'] ?? 0 ),
						'questions'   => count( $qs ),
						'silent'      => $silent,
						'tone'        => (array) ( $res['tone'] ?? array() ),
						'praise'      => $labels( $said['praise'] ?? array() ),
						'complaint'   => $labels( $said['complaint'] ?? array() ),
						'checked'     => substr( (string) $b['last_check'], 0, 10 ),
						'frequency'   => (string) ( $b['frequency'] ?? '' ),
					)
				);
			}
		} elseif ( $b ) {
			// added, first check not done yet
			$out = array_merge(
				$out,
				array(
					'state' => 'pending',
					'id'    => (int) $b['id'],
					'name'  => (string) $b['name'],
				)
			);
		} elseif ( 1 === count( $listings ) && ! empty( $listings[0]['name'] ) ) {
			$out['name']     = (string) $listings[0]['name'];
			$out['place_id'] = (string) ( $listings[0]['place_id'] ?? '' );
		}
		// Nothing checked yet: a subscribed account adds the business to tracking (no free pitch, as the Repuso
		// dashboard); others get the free check while free checks are left, else nothing.
		if ( 'offer' === $out['state'] ) {
			if ( $out['subscribed'] ) {
				$out['state'] = 'add';
			} elseif ( $out['free_left'] <= 0 ) {
				$out['state'] = 'none';
			}
		}
		// Agency client sites (a sub-account of a white label account): never the free check pitch. Tracking a business
		// stays available: only account admins see AI Visibility here, and on a white label account those are the agency.
		if ( $acc['white'] && $w > 0 && 'offer' === $out['state'] ) {
			$out['state'] = 'none';
		}
		set_transient( $key, $out, DAY_IN_SECONDS ); // the cron refreshes it hourly; a day so a failed run keeps the last copy
		return $out;
	}

	public function schedule() {
		if ( $this->connected() && ! wp_next_scheduled( 'rw_ai_refresh' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'rw_ai_refresh' );
		}
	}

	public function cron_refresh() {
		if ( ! $this->connected() ) {
			wp_clear_scheduled_hook( 'rw_ai_refresh' );
			return;
		}
		delete_transient( 'rw_ai_account_' . $this->website() );
		$this->summary( true );
	}

	// ------------------------------------------------------------------ admin: menu, assets

	public function admin_menu() {
		$label = esc_html__( 'AI Visibility', 'social-testimonials-and-reviews-widget' );
		if ( $this->show_new_badge() ) {
			$label .= ' <span class="rw-new-badge">' . esc_html__( 'New', 'social-testimonials-and-reviews-widget' ) . '</span>';
		}
		add_submenu_page( 'rw_dashboard', __( 'AI Visibility', 'social-testimonials-and-reviews-widget' ), $label, 'manage_options', 'rw_ai_visibility', array( $this, 'page' ), 4 );
	}

	/** Until this admin opens the page once, or 30 days after the update. */
	private function show_new_badge() {
		$since = (int) get_option( 'rw_ai_since' );
		if ( ! $since ) {
			update_option( 'rw_ai_since', time() );
			$since = time();
		}
		return ( time() - $since ) < 30 * DAY_IN_SECONDS && ! get_user_meta( get_current_user_id(), 'rw_ai_seen', true );
	}

	public function menu_badge_css() {
		echo '<style>#adminmenu .rw-new-badge{display:inline-block;margin-left:4px;padding:0 6px;border-radius:9px;background:#d63638;color:#fff;font-size:9px;font-weight:700;line-height:17px;text-transform:uppercase;vertical-align:1px}</style>';
	}

	public function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$plugin = in_array( $page, array( 'rw_dashboard', 'rw_widgets', 'rw_ai_visibility' ), true );
		if ( 'index.php' !== $hook && ! $plugin ) {
			return;
		}
		$dir = plugin_dir_path( __DIR__ );
		$url = plugin_dir_url( __DIR__ );
		$v   = function ( $rel ) use ( $dir ) {
			return file_exists( $dir . $rel ) ? (string) filemtime( $dir . $rel ) : RW_PLUGIN_VERSION;
		};
		wp_enqueue_style( 'rw_css_ai', $url . 'css/rw-ai.css', array(), $v( 'css/rw-ai.css' ) );
		wp_enqueue_script( 'rw_js_ai', $url . 'js/rw-ai.js', array(), $v( 'js/rw-ai.js' ), true );
		wp_localize_script(
			'rw_js_ai',
			'rwAI',
			array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'rw_ai' ),
				'copied' => __( 'Copied', 'social-testimonials-and-reviews-widget' ),
			)
		);
	}

	// ------------------------------------------------------------------ cards

	/** "Tracked weekly" or "Not tracked", as the Repuso dashboard labels it. */
	public static function status_label( $sum ) {
		if ( 'tracked' !== $sum['state'] ) {
			return __( 'Not tracked', 'social-testimonials-and-reviews-widget' );
		}
		$freq = array(
			'daily'   => __( 'Tracked daily', 'social-testimonials-and-reviews-widget' ),
			'twice'   => __( 'Tracked twice a week', 'social-testimonials-and-reviews-widget' ),
			'weekly'  => __( 'Tracked weekly', 'social-testimonials-and-reviews-widget' ),
			'monthly' => __( 'Tracked monthly', 'social-testimonials-and-reviews-widget' ),
		);
		return $freq[ $sum['frequency'] ] ?? __( 'Tracked', 'social-testimonials-and-reviews-widget' );
	}

	/** Ring with the rate. */
	private function ring( $rate, $delta = null, $muted = false ) {
		$h = '<div class="rw-ai-ring' . ( $muted ? ' is-muted' : '' ) . '" style="--p:' . (int) $rate . '" role="img" aria-label="' . esc_attr(
			/* translators: %d: percent of AI answers that name the business. */
			sprintf( __( '%d%% of AI answers recommend you', 'social-testimonials-and-reviews-widget' ), (int) $rate )
		) . '"><div><b>' . (int) $rate . '%</b>';
		if ( null !== $delta && 0 !== $delta ) {
			$h .= '<span class="' . ( $delta > 0 ? 'up' : 'down' ) . '">' . esc_html(
				/* translators: %s: signed change in percentage points, like +8 or -3. */
				sprintf( __( '%s pts', 'social-testimonials-and-reviews-widget' ), ( $delta > 0 ? '+' : '' ) . (int) $delta )
			) . '</span>';
		}
		return $h . '</div></div>';
	}

	/**
	 * The card for the WordPress Dashboard widget ($wp) and the plugin's Dashboard page.
	 * Returns '' when there is nothing to show.
	 */
	public function card_html( $sum ) {
		if ( ! $sum || in_array( $sum['state'], array( 'none', 'add', 'pending' ), true ) ) {
			return ''; // the dashboards don't sell tracking to a subscribed account; the AI Visibility page does
		}
		$uid  = get_current_user_id();
		$name = '' !== $sum['name'] ? $sum['name'] : self::domain();
		$h    = '';

		if ( 'offer' === $sum['state'] ) {
			if ( get_user_meta( $uid, 'rw_ai_hide_pitch', true ) ) {
				return '';
			}
			$add = $this->app_link( 'add?' . ( ! empty( $sum['place_id'] ) ? 'place=' . rawurlencode( $sum['place_id'] ) . '&' : '' ) . 'website=' . $this->website(), 'dashboard-offer' );
			$h  .= '<div class="rw-ai-card is-offer">';
			$h  .= '<strong class="rw-ai-h">' . esc_html__( 'Does AI recommend you, or your competitors?', 'social-testimonials-and-reviews-widget' ) . '</strong>';
			$h  .= '<p>' . esc_html(
				/* translators: %s: business name or website. */
				sprintf( __( 'See how often ChatGPT, Gemini and Google Maps name %s when customers ask for a business like yours, and who they name instead.', 'social-testimonials-and-reviews-widget' ), $name )
			) . '</p>';
			$h .= '<div class="rw-ai-actions"><a class="rw-ai-btn" target="_blank" rel="noopener" href="' . esc_url( $add ) . '">' . esc_html(
				/* translators: %s: business name or website. */
				sprintf( __( 'Check %s free', 'social-testimonials-and-reviews-widget' ), $name )
			) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
			$h .= '<button type="button" class="rw-ai-link rw-ai-dismiss" data-what="pitch">' . esc_html__( "Don't show again", 'social-testimonials-and-reviews-widget' ) . '</button></div></div>';
			return $h;
		}

		$full = $this->app_link( 'check/' . (int) $sum['id'], 'dashboard-report' );
		$page = admin_url( 'admin.php?page=rw_ai_visibility' );
		$h   .= '<div class="rw-ai-card">';
		$h   .= '<div class="rw-ai-top">' . $this->ring( $sum['rate'], $sum['delta'], 'untracked' === $sum['state'] ) . '<div class="rw-ai-text">';
		$h   .= '<strong>' . esc_html(
			/* translators: 1: answers naming the business, 2: all answers. */
			sprintf( __( 'AI recommends you in %1$d of %2$d answers', 'social-testimonials-and-reviews-widget' ), $sum['mentioned'], $sum['answers'] )
		) . ( 'untracked' === $sum['state'] ? ' <span class="rw-ai-tag">' . esc_html__( 'Not tracked', 'social-testimonials-and-reviews-widget' ) . '</span>' : '' ) . '</strong>';
		if ( $sum['ai'] ) {
			$h .= '<span>' . esc_html( implode( ', ', wp_list_pluck( $sum['ai'], 'name' ) ) ) . ' &middot; ' . esc_html(
				/* translators: %s: date of the last check. */
				sprintf( __( 'checked %s', 'social-testimonials-and-reviews-widget' ), date_i18n( get_option( 'date_format' ), strtotime( $sum['checked'] ) ) )
			) . ( 'tracked' === $sum['state'] ? ' &middot; ' . esc_html( self::status_label( $sum ) ) : '' ) . '</span>';
		}
		if ( '' !== $sum['rival'] ) {
			$h .= '<span>' . sprintf(
				/* translators: 1: competitor name, 2: times named. */
				esc_html__( '%1$s is named %2$d&times; instead of you', 'social-testimonials-and-reviews-widget' ),
				'<b class="rw-ai-rival">' . esc_html( $sum['rival'] ) . '</b>',
				(int) $sum['rival_times']
			) . '</span>';
		}
		$h .= '</div></div>';

		if ( 'untracked' === $sum['state'] ) {
			$ended = $sum['free_left'] <= 0;
			$h    .= '<div class="rw-ai-nudge"><div><strong>' . ( $ended ? esc_html__( 'Your free checks have ended', 'social-testimonials-and-reviews-widget' ) : esc_html__( 'AI answers change every week', 'social-testimonials-and-reviews-widget' ) ) . '</strong><span>';
			$h    .= '' !== $sum['rival']
				? esc_html(
					/* translators: 1: business name, 2: competitor name. */
					sprintf( __( "Track %1\$s weekly to see if you're gaining on %2\$s, and get the fixes that move your score.", 'social-testimonials-and-reviews-widget' ), $name, $sum['rival'] )
				)
				: esc_html(
					/* translators: %s: business name. */
					sprintf( __( 'Track %s weekly to see where you stand, and get the fixes that move your score.', 'social-testimonials-and-reviews-widget' ), $name )
				);
			$h .= '</span></div><a class="rw-ai-btn" target="_blank" rel="noopener" href="' . esc_url( $this->app_link( 'billing', 'dashboard-track' ) ) . '">' . esc_html__( 'Start tracking', 'social-testimonials-and-reviews-widget' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>';
			$h .= '<div class="rw-ai-foot"><a href="' . esc_url( $page ) . '">' . esc_html__( 'See the result', 'social-testimonials-and-reviews-widget' ) . '</a>';
			if ( ! $ended ) {
				$h .= '<a target="_blank" rel="noopener" href="' . esc_url( $full ) . '">' . esc_html__( 'Run your next free check', 'social-testimonials-and-reviews-widget' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
			}
			$h .= '</div></div>';
			return $h;
		}

		if ( ! $this->readable_on() ) {
			$h .= '<div class="rw-ai-fix"><div><strong>' . esc_html__( 'First fix: let AI read your reviews', 'social-testimonials-and-reviews-widget' ) . '</strong><span>' . esc_html__( 'Your reviews load with JavaScript, so ChatGPT and other AI assistants can\'t see them.', 'social-testimonials-and-reviews-widget' ) . '</span></div><a href="' . esc_url( $this->tab_url() ) . '">' . esc_html__( 'Turn it on', 'social-testimonials-and-reviews-widget' ) . '</a></div>';
		}
		$h .= '<div class="rw-ai-foot"><a href="' . esc_url( $page ) . '">' . esc_html__( 'See the summary', 'social-testimonials-and-reviews-widget' ) . '</a><a target="_blank" rel="noopener" href="' . esc_url( $full ) . '">' . esc_html__( 'Full report', 'social-testimonials-and-reviews-widget' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a></div></div>';
		return $h;
	}

	/** Saved copy, or a loading placeholder the JS fills once (first view after the update). */
	private function card_or_loader( $wp ) {
		$sum = $this->summary();
		if ( null === $sum ) {
			return '<div class="rw-ai-loading" data-rw-ai-load="' . ( $wp ? 'wp' : 'plugin' ) . '">' . esc_html__( 'Loading…', 'social-testimonials-and-reviews-widget' ) . '</div>';
		}
		return $this->card_html( $sum );
	}

	public function dashboard_widget() {
		if ( ! $this->eligible() ) {
			return;
		}
		$sum = $this->summary();
		if ( $sum && '' === $this->card_html( $sum ) ) {
			return; // hidden pitch or agency account without tracking
		}
		wp_add_dashboard_widget(
			'rw_ai_visibility',
			esc_html__( 'AI Visibility', 'social-testimonials-and-reviews-widget' ),
			array( $this, 'dashboard_widget_body' )
		);
	}

	public function dashboard_widget_body() {
		echo '<div class="rw-ai rw-ai-wp">' . $this->card_or_loader( true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in card_html().
	}

	public function plugin_dashboard_card() {
		if ( ! $this->eligible() || get_user_meta( get_current_user_id(), 'rw_ai_hide_card', true ) ) {
			return;
		}
		$body = $this->card_or_loader( false );
		if ( '' === $body ) {
			return;
		}
		echo '<section class="rw-section rw-ai rw-ai-plugin rw-ai-wait"><header class="rw-section__head"><div><h2 class="rw-section__title">' . esc_html__( 'AI Visibility', 'social-testimonials-and-reviews-widget' ) . '</h2><p class="rw-section__lede">' . esc_html__( 'How often AI assistants recommend your business.', 'social-testimonials-and-reviews-widget' ) . '</p></div><button type="button" class="rw-ai-link rw-ai-dismiss" data-what="card">' . esc_html__( 'Hide', 'social-testimonials-and-reviews-widget' ) . '</button></header>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in card_html().
		echo '</section>';
	}

	public function ajax_card() {
		check_ajax_referer( 'rw_ai', 'nonce' );
		if ( ! $this->eligible() ) {
			wp_send_json_success( array( 'html' => '' ) );
		}
		$sum = $this->summary( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$wp = isset( $_POST['where'] ) && 'wp' === sanitize_key( wp_unslash( $_POST['where'] ) );
		wp_send_json_success( array( 'html' => $this->card_html( $sum ) ) );
	}

	/**
	 * An AI Visibility app link signed in as this site's Repuso user: the key goes after the # (never sent to a server,
	 * the app drops it from the address bar). Asked for on click, so the key is never in the page HTML. Otherwise the
	 * app opens as whoever is signed in to it in this browser.
	 */
	public function ajax_app_url() {
		check_ajax_referer( 'rw_ai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! $this->connected() ) {
			wp_send_json_error();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$base = preg_replace( '#/app/?$#', '/ai-visibility/app/', rtrim( (string) $this->rw->appUrl, '/' ) . '/' );
		if ( 0 !== strpos( $url, $base ) ) {
			wp_send_json_error(); // only our own app links
		}
		$parts = explode( '#', $url, 2 );
		$hash  = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : '/';
		$hash .= ( false === strpos( $hash, '?' ) ? '?' : '&' ) . 'akey=' . rawurlencode( $this->rw->apiKey );
		wp_send_json_success( array( 'url' => $parts[0] . '#' . $hash ) );
	}

	public function ajax_dismiss() {
		check_ajax_referer( 'rw_ai', 'nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		if ( in_array( $what, array( 'pitch', 'card' ), true ) && current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), 'rw_ai_hide_' . $what, 1 );
		}
		wp_send_json_success();
	}

	// ------------------------------------------------------------------ Reviews > AI Visibility

	/** Reviews > AI Visibility. Connected sites get the plugin chrome (RepusoIntegration::ai_page() -> tmpl/main.php -> page_body()). */
	public function page() {
		update_user_meta( get_current_user_id(), 'rw_ai_seen', 1 );
		if ( ! $this->connected() ) {
			$ai = $this;
			require plugin_dir_path( __DIR__ ) . 'tmpl/ai-unconnected.php';
			return;
		}
		$this->rw->ai_page();
	}

	/** Body of the AI Visibility page inside the plugin chrome (called from tmpl/main.php). */
	public function page_body() {
		$ai = $this;
		$ok = $this->eligible();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$biz = isset( $_GET['biz'] ) ? absint( $_GET['biz'] ) : 0;
		$sum = $ok ? $this->summary( false, $biz ) : null;
		if ( $ok && null === $sum ) {
			$sum = $this->summary( true, $biz ); // first visit (after the update, or of this tab)
		}
		require plugin_dir_path( __DIR__ ) . 'tmpl/ai-visibility.php';
	}

	public function link( $path, $campaign ) {
		return $this->app_link( $path, $campaign );
	}

	// ------------------------------------------------------------------ Widgets > AI and search engines

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'social-testimonials-and-reviews-widget' ) );
		}
		check_admin_referer( 'rw_ai_save' );
		$on = isset( $_POST['rw_ai_readable'] ) ? '1' : '0';
		update_option( 'rw_ai_readable', $on, true );
		$this->report( '1' === $on );
		wp_safe_redirect( add_query_arg( 'saved', '1', $this->tab_url() ) );
		exit;
	}

	/** Tell Repuso the setting and its date (the AI Visibility trend line marker). */
	private function report( $on ) {
		if ( ! $this->connected() ) {
			return;
		}
		wp_remote_post(
			$this->rw->apiUrl . 'wordpress/settings',
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(
					'Authorization' => $this->auth(),
					'Content-Type'  => 'application/json',
				),
				'body'     => wp_json_encode(
					array(
						'website'  => $this->website(),
						'domain'   => home_url(),
						'readable' => $on,
						'version'  => RW_PLUGIN_VERSION,
					)
				),
			)
		);
	}

	/** The account's widgets (for the preview picker), cached 10 minutes. */
	public function widgets() {
		$key = 'rw_ai_widgets_' . $this->website();
		$wl  = get_transient( $key );
		if ( false === $wl ) {
			$r  = $this->api_get( 'widgets?website=' . $this->website() );
			$wl = array();
			foreach ( is_array( $r ) ? $r : array() as $w ) {
				if ( ! empty( $w['id'] ) && ! empty( $w['type'] ) && 'email1' !== $w['type'] && 0 !== strpos( (string) $w['type'], 'image_badge' ) ) {
					$wl[] = array(
						'id'   => (string) $w['id'],
						'type' => (string) $w['type'],
						'name' => ( ! empty( $w['description'] ) ? (string) $w['description'] : ucfirst( (string) $w['type'] ) ) . ' #' . $w['id'],
					);
				}
			}
			set_transient( $key, $wl, 10 * MINUTE_IN_SECONDS );
		}
		return $wl;
	}

	public function tab_body() {
		$ai      = $this;
		$widgets = $this->widgets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview picker.
		$pick = isset( $_GET['w'] ) ? sanitize_text_field( wp_unslash( $_GET['w'] ) ) : '';
		$sel  = null;
		foreach ( $widgets as $w ) {
			if ( $w['id'] === $pick || ( '' === $pick && ! $sel ) ) {
				$sel = $w;
			}
		}
		$data = $sel ? $this->widget_data( $sel['id'], true ) : null;
		require plugin_dir_path( __DIR__ ) . 'tmpl/ai-readable.php';
	}

	// ------------------------------------------------------------------ public review page

	/** Public review page (address, on/off, stats), cached; null when unavailable. */
	public function public_page( $ttl = 600 ) {
		$key = 'rw_ai_pp_' . $this->website();
		$pp  = get_transient( $key );
		if ( false === $pp ) {
			$r  = $this->api_get( 'widgets/public-page?website=' . $this->website() );
			$pp = is_array( $r ) && ! empty( $r['success'] ) && ! empty( $r['address'] ) ? array(
				'address' => (string) $r['address'],
				'enabled' => ! empty( $r['enabled'] ),
				'views'   => (int) ( $r['stats']['totals']['person'] ?? 0 ),
				'crawls'  => (int) ( $r['stats']['totals']['ai_live'] ?? 0 ) + (int) ( $r['stats']['totals']['ai_index'] ?? 0 ) + (int) ( $r['stats']['totals']['search'] ?? 0 ),
				'reviews' => (int) ( $r['ratings']['count'] ?? 0 ),
			) : array();
			set_transient( $key, $pp, $pp ? $ttl : 5 * MINUTE_IN_SECONDS );
		}
		return $pp ? $pp : null;
	}

	public function public_page_card() {
		if ( ! $this->connected() ) {
			return;
		}
		$pp = $this->public_page();
		if ( ! $pp ) {
			return;
		}
		require plugin_dir_path( __DIR__ ) . 'tmpl/ai-public-page.php';
	}

	/** [rw_review_page_link text="Read our reviews"]: a plain link to the public review page; nothing when it's off. */
	public function review_page_link( $atts ) {
		$atts = shortcode_atts( array( 'text' => __( 'Read our reviews', 'social-testimonials-and-reviews-widget' ) ), $atts, 'rw_review_page_link' );
		$pp   = $this->public_page( HOUR_IN_SECONDS );
		if ( ! $pp || ! $pp['enabled'] ) {
			return '';
		}
		return '<a class="rw-review-page-link" href="' . esc_url( $pp['address'] ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}

	// ------------------------------------------------------------------ public pages: Let AI read your reviews

	/** Front-end requests where the plugin may add the reviews (not admin, editors, REST, feeds). */
	private function front_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for page builder previews.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['et_fb'] ) || isset( $_GET['fl_builder'] ) || is_customize_preview() ) {
			return false;
		}
		return true;
	}

	/** Same rule Stencil adds once the widget script loads, printed early so nothing inside a widget tag shows first. */
	public function front_style() {
		if ( ! $this->readable_on() || ! $this->front_request() ) {
			return;
		}
		$tags = array();
		foreach ( array_merge( self::LIST_TYPES, self::DATA_ONLY ) as $t ) {
			$tags[] = 'rw-widget-' . $t . ':not(.hydrated)';
		}
		wp_register_style( 'rw-ai-readable', false, array(), RW_PLUGIN_VERSION );
		wp_enqueue_style( 'rw-ai-readable' );
		wp_add_inline_style( 'rw-ai-readable', implode( ',', $tags ) . '{visibility:hidden}' );
	}

	/**
	 * A widget's public data, slimmed: latest 10 reviews + the decoded review data. Cached an hour per widget;
	 * an expired copy is served and refreshed in the background, so a visitor never waits on Repuso
	 * (only the very first fetch, capped at 2 seconds).
	 */
	public function widget_data( $id, $admin = false ) {
		$id = preg_replace( '/[^0-9a-z\-]/i', '', (string) $id );
		if ( '' === $id ) {
			return null;
		}
		$copy = get_option( 'rw_ai_w_' . $id );
		if ( is_array( $copy ) ) {
			if ( time() - (int) $copy['at'] > HOUR_IN_SECONDS && ! wp_next_scheduled( 'rw_ai_refresh_widget', array( $id ) ) ) {
				wp_schedule_single_event( time(), 'rw_ai_refresh_widget', array( $id ) );
			}
			return $copy['ok'] ? $copy : null;
		}
		$copy = $this->refresh_widget( $id, $admin ? 5 : 2 );
		return $copy && $copy['ok'] ? $copy : null;
	}

	public function refresh_widget( $id, $timeout = 5 ) {
		$id  = preg_replace( '/[^0-9a-z\-]/i', '', (string) $id );
		$res = wp_remote_get( $this->rw->apiUrl . 'widgets/posts/' . rawurlencode( $id ), array( 'timeout' => $timeout ) );
		$old = get_option( 'rw_ai_w_' . $id );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			if ( is_array( $old ) ) { // keep serving the last copy; try again in an hour
				$old['at'] = time();
				update_option( 'rw_ai_w_' . $id, $old, false );
				return $old;
			}
			return null;
		}
		$d    = json_decode( wp_remote_retrieve_body( $res ), true );
		$copy = array(
			'ok'     => is_array( $d ) && ! empty( $d['items'] ),
			'at'     => time(),
			'items'  => array(),
			'schema' => null,
		);
		if ( $copy['ok'] ) {
			$first = ! empty( $d['settings']['first_name_only'] );
			foreach ( array_slice( (array) $d['items'], 0, 10 ) as $it ) {
				$author = trim( wp_strip_all_tags( (string) ( $it['from_name'] ?? '' ) ) );
				if ( $first && '' !== $author ) {
					$author = strtok( $author, ' ' );
				}
				$type            = strtolower( (string) ( $it['type'] ?? '' ) );
				$copy['items'][] = array(
					'author'   => $author,
					'text'     => trim( wp_strip_all_tags( (string) ( $it['text'] ?? '' ) ) ),
					'rating'   => ! empty( $it['rating_scale'] ) ? round( 5 * (float) $it['rating_value'] / (float) $it['rating_scale'], 1 ) : 0,
					'date'     => substr( (string) ( $it['posted_on'] ?? '' ), 0, 10 ),
					'platform' => in_array( $type, self::UNNAMED, true ) ? '' : (string) ( $it['label'] ?? '' ),
				);
			}
			// The API sends the review data as a finished <script> tag: keep only valid JSON, re-encoded on output.
			if ( ! empty( $d['schema'] ) && preg_match( '#<script[^>]*>(.*)</script>#is', (string) $d['schema'], $m ) ) {
				$json = json_decode( trim( $m[1] ), true );
				if ( is_array( $json ) ) {
					$copy['schema'] = $json;
				}
			}
		}
		update_option( 'rw_ai_w_' . $id, $copy, false );
		$list   = (array) get_option( 'rw_ai_w_ids', array() );
		$list[] = $id;
		update_option( 'rw_ai_w_ids', array_values( array_unique( $list ) ), false );
		return $copy;
	}

	/** The hidden list for inside the widget tag ('' for data-only types). */
	public function plain_list( $type, $data ) {
		if ( ! $data || ! in_array( $type, self::LIST_TYPES, true ) || empty( $data['items'] ) ) {
			return '';
		}
		$h = '<ul class="rw-plain">';
		foreach ( $data['items'] as $it ) {
			if ( '' === $it['text'] && ! $it['rating'] ) {
				continue;
			}
			$meta = array_filter( array( $it['author'], $it['platform'], $it['date'] ? date_i18n( get_option( 'date_format' ), strtotime( $it['date'] ) ) : '' ) );
			$h   .= '<li>';
			if ( $it['rating'] ) {
				$h .= esc_html(
					/* translators: %s: star rating out of 5, like 4.5. */
					sprintf( __( '%s out of 5 stars', 'social-testimonials-and-reviews-widget' ), $it['rating'] )
				) . '. ';
			}
			$h .= esc_html( implode( ', ', $meta ) );
			if ( '' !== $it['text'] ) {
				$h .= ': ' . esc_html( $it['text'] );
			}
			$h .= '</li>';
		}
		return $h . '</ul>';
	}

	/** The review data block, once per page. */
	public function schema_tag( $data ) {
		if ( $this->schema_printed || ! $data || empty( $data['schema'] ) ) {
			return '';
		}
		$this->schema_printed = true;
		return wp_get_inline_script_tag(
			wp_json_encode( $data['schema'] ),
			array(
				'type' => 'application/ld+json',
				'id'   => 'rw-schema',
			)
		);
	}

	/** Called from get_widget_code(): [inside the tag, after the tag]. */
	public function for_widget( $type, $id ) {
		if ( ! $this->readable_on() || ! $this->front_request() ) {
			return array( '', '' );
		}
		$data = $this->widget_data( $id );
		return array( $this->plain_list( $type, $data ), $this->schema_tag( $data ) );
	}

	// ------------------------------------------------------------------ install / uninstall

	/** Activation: switch "Let AI read your reviews" on only for a site that never had the plugin. */
	public static function activate() {
		if ( false === get_option( 'rw_apikey', false ) && false === get_option( 'repuso_js_code', false ) && false === get_option( 'rw_ai_readable', false ) ) {
			add_option( 'rw_installed_new', 1 );
			add_option( 'rw_ai_readable', '1' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'rw_ai_refresh' );
	}
}
