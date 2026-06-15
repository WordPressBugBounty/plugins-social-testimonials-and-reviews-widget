<?php
/**
 * Plugin Name:       Reviews Widgets for Google & 45+ platforms by Repuso
 * Plugin URI:        https://repuso.com/integrations-wordpress/
 * Description:       Social testimonials & reviews on your own website as social proof. Increase your website's sales and conversion rate with Repuso.
 * Version:           6.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.2.2
 * Author:            Repuso
 * Author URI:        https://repuso.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-testimonials-and-reviews-widget
 * Domain Path:       /languages
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Override in wp-config.php for local dev:
//   define('REPUSO_API_URL', 'https://api.repuso.net/v1/');
//   define('REPUSO_APP_URL', 'https://app.repuso.net/');
//   define('REPUSO_MAX_CONCURRENT', 1);  // dial down on small PHP-FPM pools
if (!defined('REPUSO_API_URL')) define('REPUSO_API_URL', 'https://api.repuso.com/v1/');
if (!defined('REPUSO_APP_URL')) define('REPUSO_APP_URL', 'https://repuso.com/app/');
// Max concurrent admin-ajax calls the dashboard / section loaders run
// in parallel. Each request holds two PHP-FPM workers (WP side + the
// upstream api.repuso.* side). 4 is comfortable on production; on a
// constrained local pool (2-3 workers) 4 can exhaust the pool and
// surface as cURL-28 timeouts. Set REPUSO_MAX_CONCURRENT=1 in
// wp-config.php for local dev to avoid that.
if (!defined('REPUSO_MAX_CONCURRENT')) define('REPUSO_MAX_CONCURRENT', 4);

class RepusoIntegration {

	var $apiUrl = REPUSO_API_URL;
	var $appUrl = REPUSO_APP_URL;
	var $loginUrl = "";
	var $appPath = "";
	var $apiKey = false;
	var $current_user = false;
	var $hostname = '';
	var $currentSection = 'widgets';
	var $websiteId = 0;
	var $plugin_url = '';
	var $pages_types = [];
	var $pages = [];

    function __construct() {
	    $this->apiKey = sanitize_text_field(get_option('rw_apikey'));
		$this->loginUrl = $this->appUrl;
	    $this->websiteId = get_option('rw_account') ? sanitize_text_field(get_option('rw_account')) : 0; 
        $this->plugin_url = plugin_dir_url(__FILE__);
        $this->pages_types = array('Front Page', 'Blog Index', 'Pages', 'Posts');
        $this->pages = array();
        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => -1
        ));
        
		$hostname = wp_parse_url(get_site_url(), PHP_URL_HOST);
		$hostname = str_replace('www.', '', $hostname);
		$hostname = str_replace('.co.uk', '', $hostname);
		$hostname = str_replace('.com.au', '', $hostname);
		$hostname = str_replace('.com', '', $hostname);
        $this->hostname = $hostname;
        
        $posts_page = sanitize_text_field(get_option('page_for_posts'));
        $front_page = sanitize_text_field(get_option('page_on_front'));

        foreach ($pages as $page) {
            if ($page->ID != $posts_page && $page->ID != $front_page) {
                $this->pages[] = $page;
            }
        }
    }
    
    function get_user_info(){
	    
	    if(!function_exists('wp_get_current_user')) return false;
	    
		$this->current_user = wp_get_current_user(); 
		
		if ( !($this->current_user instanceof WP_User) ) 
			return; 
		
		//echo $this->current_user->user_login;
		
		// Do the remaining stuff that has to happen once you've gotten your user info
	}

    function execute_sidewide_widget() {
        $show = false;
        $code = sanitize_textarea_field(stripslashes(get_option('repuso_js_code')));        
        
        // support for older full code
        $pos = strpos($code, "script");
        if ($pos === false) {
        	//$code = do_shortcode(stripslashes($code));  
			//return true;
        } else {
			return true;
		}
        
        if (trim($code) == '') {
            return true;
        }
        $repuso_page_type_front_page = sanitize_text_field(get_option('repuso_page_type_front-page'));
        $repuso_page_type_blog_index = sanitize_text_field(get_option('repuso_page_type_blog-index'));
        $repuso_page_type_pages = sanitize_text_field(get_option('repuso_page_type_pages'));
        $repuso_page_type_posts = sanitize_text_field(get_option('repuso_page_type_posts'));

		$frontpage_id = get_option( 'page_on_front' ); 
		$blog_index_id = get_option( 'page_for_posts' );
		$current_page_id = get_the_ID();  
		
        if ($repuso_page_type_front_page === '1' && is_front_page()) {
			$show = true; 
        }
        if ($repuso_page_type_blog_index === '1' && is_home() && $current_page_id<>$frontpage_id) {
            $show = true;
        }
        if ($repuso_page_type_pages === '1' && is_page() && $current_page_id<>$frontpage_id) {
            $show = true; 
        }
        if ($repuso_page_type_posts === '1' && is_single() && $current_page_id<>$frontpage_id) {
            $show = true; 
        }

        if (is_page()) {
            $page_id = get_the_ID();
            //repuso_page_show_6
            //repuso_page_hide_2
			
            if (get_option('repuso_page_show_' . $page_id) == '1') {
                $show = true;
            }
            if (get_option('repuso_page_hide_' . $page_id) == '1') {
                $show = false;
            }
        }

        /* by url */

        // get_option('url_itself') / 'url_type' are arrays of strings written
        // by the floating-widget settings form. sanitize_text_field on an array
        // returns empty, so we sanitise per-element below instead.
        $url_itself = get_option( 'url_itself' );
        $url_type   = get_option( 'url_type' );

        if ( is_array( $url_itself ) && ! empty( $url_itself ) ) {
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $ru  = str_replace( '/', '', $uri );

            foreach ( $url_itself as $key => $value ) {
                $va = str_replace( '/', '', sanitize_text_field( (string) $value ) );
                $matched_type = isset( $url_type[ $key ] ) ? sanitize_text_field( (string) $url_type[ $key ] ) : '';

                if ( fnmatch( $va, $ru ) && $matched_type === 'show' ) {
                    $show = true;
                }

                if ( fnmatch( $va, $ru ) && $matched_type === 'hide' ) {
                    $show = false;
                }
                
            }
        }


        if ($show) {
			echo do_shortcode($code);
        }
        /* var_dump('test');
          die(); */
    }

    function init() {
        // WordPress auto-loads translations for WP.org-hosted plugins
        // from wp-content/languages/plugins/. We add a fallback so the
        // .mo files bundled inside this plugin's /languages/ folder are
        // also picked up on self-hosted installs that don't have them
        // in the global path. Calling load_plugin_textdomain() here is
        // discouraged since WP 4.6, hence the filter approach instead.
        add_filter( 'load_textdomain_mofile', array( $this, 'fallback_mofile_path' ), 10, 2 );
    }

    /**
     * Resolve the bundled /languages/ .mo for our text domain when the
     * global wp-content/languages/plugins/ path doesn't have one.
     *
     * @param string $mofile The candidate .mo path WordPress wants to load.
     * @param string $domain The text domain being loaded.
     * @return string A readable .mo path, or the original if no fallback applies.
     */
    public function fallback_mofile_path( $mofile, $domain ) {
        if ( 'social-testimonials-and-reviews-widget' !== $domain ) {
            return $mofile;
        }
        if ( ! empty( $mofile ) && file_exists( $mofile ) ) {
            return $mofile;
        }
        $bundled = plugin_dir_path( __FILE__ ) . 'languages/' . basename( (string) $mofile );
        if ( file_exists( $bundled ) ) {
            return $bundled;
        }
        return $mofile;
    }

    /**
     * Translated user-visible strings used by rw-admin.js and
     * rw-overview.js. Exposed to JS via wp_localize_script('rwI18n').
     * Each value goes through __() so the strings get picked up by
     * `wp i18n make-pot` and translate alongside the PHP/template
     * strings. Keep keys short - they're called from JS hot paths.
     */
    private function js_strings() {
        // Domain is inlined on every __() call below so the i18n
        // tooling (and the Plugin Checker) can statically verify each
        // string is properly domained. A local $d variable would
        // trip WordPress.WP.I18n.NonSingularStringLiteralDomain.
        return array(
            // Connection / status pill.
            'connected_to_repuso'   => __( 'Connected to Repuso', 'social-testimonials-and-reviews-widget' ),
            'not_connected'         => __( 'Not connected', 'social-testimonials-and-reviews-widget' ),
            'connecting'            => __( 'Connecting…', 'social-testimonials-and-reviews-widget' ),
            'checking_connection'   => __( 'Checking connection…', 'social-testimonials-and-reviews-widget' ),

            // Section labels (re-used for access-denied messages too).
            'widgets'               => __( 'Widgets', 'social-testimonials-and-reviews-widget' ),
            'channels'              => __( 'Channels', 'social-testimonials-and-reviews-widget' ),

            // Section empty states.
            'no_widgets_title'      => __( 'No widgets yet', 'social-testimonials-and-reviews-widget' ),
            'no_widgets_body'       => __( 'Create your first widget on the Repuso dashboard, then embed it here.', 'social-testimonials-and-reviews-widget' ),
            'create_widget'         => __( 'Create widget', 'social-testimonials-and-reviews-widget' ),
            'no_channels_title'     => __( 'No channels yet', 'social-testimonials-and-reviews-widget' ),
            'no_channels_body'      => __( 'Connect your first review platform on the Repuso dashboard to start collecting reviews.', 'social-testimonials-and-reviews-widget' ),
            'connect_channel'       => __( 'Connect a channel', 'social-testimonials-and-reviews-widget' ),
            'no_reviews_title'      => __( 'No reviews yet', 'social-testimonials-and-reviews-widget' ),
            'no_reviews_body'       => __( 'When customers leave reviews on your connected channels they appear here for you to approve.', 'social-testimonials-and-reviews-widget' ),
            'could_not_load_title'  => __( 'Could not load reviews', 'social-testimonials-and-reviews-widget' ),
            'could_not_load_body'   => __( 'Something went wrong fetching reviews. Try switching tabs again or reload the page.', 'social-testimonials-and-reviews-widget' ),

            // Widget row actions.
            'preview'               => __( 'Preview', 'social-testimonials-and-reviews-widget' ),
            'full_code'             => __( 'Full code', 'social-testimonials-and-reviews-widget' ),
            'edit'                  => __( 'Edit', 'social-testimonials-and-reviews-widget' ),
            'edit_on_dashboard'     => __( 'Edit on Repuso dashboard', 'social-testimonials-and-reviews-widget' ),
            'selected_for_site'     => __( 'Selected for site', 'social-testimonials-and-reviews-widget' ),
            'selected_for_site_hint'=> __( 'This widget is set as the floating widget for this site.', 'social-testimonials-and-reviews-widget' ),
            'click_to_copy'         => __( 'Click to copy', 'social-testimonials-and-reviews-widget' ),

            // Review actions.
            'approve'               => __( 'Approve', 'social-testimonials-and-reviews-widget' ),
            'dismiss'               => __( 'Dismiss', 'social-testimonials-and-reviews-widget' ),
            'approve_tooltip'       => __( 'Approve (displayed in widgets)', 'social-testimonials-and-reviews-widget' ),
            'dismiss_tooltip'       => __( 'Dismiss (hidden in widgets)', 'social-testimonials-and-reviews-widget' ),
            'reply'                 => __( 'Reply', 'social-testimonials-and-reviews-widget' ),
            'replied'               => __( 'Replied', 'social-testimonials-and-reviews-widget' ),
            'ai_suggest_reply'      => __( 'AI suggest reply', 'social-testimonials-and-reviews-widget' ),
            'ai_suggesting'         => __( 'Suggesting…', 'social-testimonials-and-reviews-widget' ),
            'copy_reply'            => __( 'Copy reply', 'social-testimonials-and-reviews-widget' ),
            'copied'                => __( 'Copied!', 'social-testimonials-and-reviews-widget' ),
            'read_more'             => __( 'Read more', 'social-testimonials-and-reviews-widget' ),
            'show_less'             => __( 'Show less', 'social-testimonials-and-reviews-widget' ),

            // AI states.
            'ai_not_supported'      => __( 'AI is not supported on your current plan.', 'social-testimonials-and-reviews-widget' ),
            'ai_not_enabled_site'   => __( 'AI is not enabled for this sub-account.', 'social-testimonials-and-reviews-widget' ),
            'ai_upgrade_plan'       => __( 'Upgrade plan', 'social-testimonials-and-reviews-widget' ),
            'ai_enable_site'        => __( 'Enable AI for this site', 'social-testimonials-and-reviews-widget' ),
            'ai_below_threshold'    => __( 'Collect 5+ reviews with written feedback to unlock AI insights.', 'social-testimonials-and-reviews-widget' ),
            'ai_load_failed'        => __( "Couldn't load AI insights right now.", 'social-testimonials-and-reviews-widget' ),
            'ai_reply_not_on_plan'  => __( 'AI replies are not on your plan yet.', 'social-testimonials-and-reviews-widget' ),
            'ai_reply_timeout'      => __( 'Request timed out. The AI service is taking longer than usual.', 'social-testimonials-and-reviews-widget' ),
            'ai_reply_unreachable'  => __( 'Could not reach the AI service.', 'social-testimonials-and-reviews-widget' ),

            // KPI labels.
            'positive'              => __( 'positive', 'social-testimonials-and-reviews-widget' ),
            'neutral'               => __( 'neutral', 'social-testimonials-and-reviews-widget' ),
            'negative'              => __( 'negative', 'social-testimonials-and-reviews-widget' ),

            // Setup card.
            'setup_step1_title'     => __( 'Connect your first channel', 'social-testimonials-and-reviews-widget' ),
            'setup_step1_desc'      => __( 'Google, Facebook, Tripadvisor or any of 45+ supported platforms.', 'social-testimonials-and-reviews-widget' ),
            'setup_step2_title'     => __( 'Create your first widget', 'social-testimonials-and-reviews-widget' ),
            'setup_step2_desc'      => __( 'Pick a layout and embed it on your site using the shortcode.', 'social-testimonials-and-reviews-widget' ),
            'setup_step3_title'     => __( 'Approve a review', 'social-testimonials-and-reviews-widget' ),
            'setup_step3_desc'      => __( 'Approve the reviews you want to feature in your widgets. Pending reviews stay hidden until you approve them.', 'social-testimonials-and-reviews-widget' ),
            'setup_view_reviews'    => __( 'View reviews', 'social-testimonials-and-reviews-widget' ),
            'setup_waiting'         => __( 'Waiting for your first review to arrive', 'social-testimonials-and-reviews-widget' ),

            // Outreach card.
            'outreach_collect_title'  => __( 'Make leaving a review effortless', 'social-testimonials-and-reviews-widget' ),
            'outreach_collect_desc'   => __( 'Add review sources so customers can leave reviews in a few taps.', 'social-testimonials-and-reviews-widget' ),
            'outreach_collect_cta'    => __( 'Set up Collect', 'social-testimonials-and-reviews-widget' ),
            'outreach_invite_title'   => __( 'Invite customers to leave reviews', 'social-testimonials-and-reviews-widget' ),
            'outreach_invite_desc'    => __( 'Send review invites by email & SMS.', 'social-testimonials-and-reviews-widget' ),
            'outreach_invite_cta'     => __( 'Enable invites', 'social-testimonials-and-reviews-widget' ),
            'outreach_nfc_title'      => __( 'Collect reviews in person', 'social-testimonials-and-reviews-widget' ),
            'outreach_nfc_desc'       => __( 'Order NFC tags so customers can tap to leave a review.', 'social-testimonials-and-reviews-widget' ),
            'outreach_nfc_cta'        => __( 'Order NFC tags', 'social-testimonials-and-reviews-widget' ),

            // Floating widget picker.
            'floating_none'           => __( 'None - disable floating widget', 'social-testimonials-and-reviews-widget' ),
            'floating_load_failed'    => __( 'Could not load widgets - try refreshing.', 'social-testimonials-and-reviews-widget' ),
            'floating_label_floating' => __( 'Floating', 'social-testimonials-and-reviews-widget' ),
            'floating_label_flash'    => __( 'Flash', 'social-testimonials-and-reviews-widget' ),
            'floating_label_badge1'   => __( 'Floating badge 1', 'social-testimonials-and-reviews-widget' ),
            'floating_label_badge2'   => __( 'Floating badge 2', 'social-testimonials-and-reviews-widget' ),

            // Latest reviews empty / errors.
            'latest_empty'            => __( 'No reviews to show yet.', 'social-testimonials-and-reviews-widget' ),
            'latest_load_failed'      => __( "Couldn't load latest reviews.", 'social-testimonials-and-reviews-widget' ),

            // Dashboard range pills.
            'range_last_30d'          => __( 'last 30 days', 'social-testimonials-and-reviews-widget' ),
            'range_last_90d'          => __( 'last 90 days', 'social-testimonials-and-reviews-widget' ),
            'range_last_1y'           => __( 'last year', 'social-testimonials-and-reviews-widget' ),
            'range_last_2y'           => __( 'last 2 years', 'social-testimonials-and-reviews-widget' ),

            // Dashboard KPI cards.
            'kpi_na'                  => __( 'n/a', 'social-testimonials-and-reviews-widget' ),
            'kpi_no_reviews_yet'      => __( 'No reviews yet', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: number of platforms (singular). */
            'kpi_across_platform'     => __( 'across %d platform', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: number of platforms (plural). */
            'kpi_across_platforms'    => __( 'across %d platforms', 'social-testimonials-and-reviews-widget' ),
            /* translators: %s: human-readable range label (e.g. "last 90 days"). */
            'kpi_new_reviews_label'   => __( 'New reviews (%s)', 'social-testimonials-and-reviews-widget' ),
            'kpi_no_new_in_range'     => __( 'No new reviews in range', 'social-testimonials-and-reviews-widget' ),
            'kpi_vs_first_half'       => __( 'vs first half of range', 'social-testimonials-and-reviews-widget' ),
            'kpi_load_failed'         => __( "Couldn't load", 'social-testimonials-and-reviews-widget' ),

            // Free-trial banner.
            'account_disabled_title' => __( 'Your Repuso account is disabled', 'social-testimonials-and-reviews-widget' ),
            'account_disabled_sub'   => __( 'Choose a plan to reactivate your account and bring your widgets back online.', 'social-testimonials-and-reviews-widget' ),
            'trial_ends_today'      => __( 'Free trial ends today', 'social-testimonials-and-reviews-widget' ),
            'trial_one_day_left'    => __( '1 day left in your free trial', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: number of days remaining in the free trial (always >= 2). */
            'trial_days_left'       => __( '%d days left in your free trial', 'social-testimonials-and-reviews-widget' ),
            'trial_sub'             => __( 'Choose your plan to avoid interruptions, remaining trial days are not billed.', 'social-testimonials-and-reviews-widget' ),

            // Dashboard chart card.
            'chart_library_failed'    => __( 'Chart library failed to load.', 'social-testimonials-and-reviews-widget' ),
            'chart_load_failed'       => __( "Couldn't load chart data.", 'social-testimonials-and-reviews-widget' ),
            'rating_trend_empty'      => __( 'No rating data for the selected period.', 'social-testimonials-and-reviews-widget' ),
            'average_rating'          => __( 'Average rating', 'social-testimonials-and-reviews-widget' ),

            // AI insights extras.
            'ai_no_improvements'      => __( 'No improvement themes detected. Customers are happy!', 'social-testimonials-and-reviews-widget' ),
            /* translators: %s: short diagnostic detail from the API/transport layer. */
            'ai_reply_unavailable'    => __( 'AI reply unavailable - %s', 'social-testimonials-and-reviews-widget' ),
            /* translators: %s: error message from the AI service. */
            'ai_service_error'        => __( 'AI service: %s', 'social-testimonials-and-reviews-widget' ),
            'ai_unknown_error'        => __( 'unknown error', 'social-testimonials-and-reviews-widget' ),

            // Platform breakdown table.
            /* translators: %d: number of platforms shown when collapsed. */
            'platforms_show_top'      => __( 'Show top %d', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: total number of platforms in the full list. */
            'platforms_show_all'      => __( 'Show all %d platforms', 'social-testimonials-and-reviews-widget' ),

            // Time-ago strings used in the review list and refresh label.
            /* translators: %d: seconds (short form). */
            'time_ago_seconds'        => __( '%ds ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: minutes (short form). */
            'time_ago_minutes'        => __( '%dm ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: hours (short form). */
            'time_ago_hours'          => __( '%dh ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: days (short form). */
            'time_ago_days'           => __( '%dd ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: weeks (short form). */
            'time_ago_weeks'          => __( '%dw ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: months (short form). */
            'time_ago_months'         => __( '%dmo ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: years (short form). */
            'time_ago_years'          => __( '%dy ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: seconds (long form, shown on refresh label). */
            'time_ago_sec_long'       => __( '%d s ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: minutes (long form, shown on refresh label). */
            'time_ago_min_long'       => __( '%d min ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: hours (long form, shown on refresh label). */
            'time_ago_hour_long'      => __( '%d h ago', 'social-testimonials-and-reviews-widget' ),
            /* translators: %d: days (long form, shown on refresh label). */
            'time_ago_day_long'       => __( '%d d ago', 'social-testimonials-and-reviews-widget' ),

            // Widget preview / code modals.
            'widget_preview_title'    => __( 'Widget preview', 'social-testimonials-and-reviews-widget' ),
            'widget_code_title'       => __( 'Widget code', 'social-testimonials-and-reviews-widget' ),
            'widget_no_preview'       => __( 'No preview available.', 'social-testimonials-and-reviews-widget' ),
            'widget_load_failed'      => __( 'Could not load the widget. Please try again.', 'social-testimonials-and-reviews-widget' ),
            'copy_code'               => __( 'Copy code', 'social-testimonials-and-reviews-widget' ),

            // Generic.
            'refresh'                 => __( 'Refresh', 'social-testimonials-and-reviews-widget' ),
            'just_updated'            => __( 'Just updated', 'social-testimonials-and-reviews-widget' ),

            // Onboard form: email + password + OTP validation/status.
            // Button idle labels - mirror the localized template labels
            // so the JS can restore them after the loading spinner.
            'create_account_btn'      => __( 'Create my Repuso account', 'social-testimonials-and-reviews-widget' ),
            'sign_in_btn'             => __( 'Sign in', 'social-testimonials-and-reviews-widget' ),
            'email_me_code_btn'       => __( 'Email me a code', 'social-testimonials-and-reviews-widget' ),
            'sign_in_with_code_btn'   => __( 'Sign in with code', 'social-testimonials-and-reviews-widget' ),

            'email_required'          => __( 'Email is required.', 'social-testimonials-and-reviews-widget' ),
            'email_invalid'           => __( 'Enter a valid email address.', 'social-testimonials-and-reviews-widget' ),
            'email_reserved_domain'   => __( 'Use a real email address, not a reserved placeholder domain.', 'social-testimonials-and-reviews-widget' ),
            'email_reserved_tld'      => __( 'Use a real email address, not a local or reserved-TLD domain.', 'social-testimonials-and-reviews-widget' ),
            'signup_email_exists'     => __( 'An account with this email already exists. Use the "I already have a Repuso account" form to sign in.', 'social-testimonials-and-reviews-widget' ),
            'signup_failed_generic'   => __( 'We could not create your account. Try again.', 'social-testimonials-and-reviews-widget' ),
            'signup_button_loading'   => __( 'Creating your account…', 'social-testimonials-and-reviews-widget' ),
            'login_invalid'           => __( 'Invalid email or password', 'social-testimonials-and-reviews-widget' ),
            'login_password_required' => __( 'Password is required.', 'social-testimonials-and-reviews-widget' ),
            'login_button_loading'    => __( 'Signing in…', 'social-testimonials-and-reviews-widget' ),
            'code_button_loading'     => __( 'Sending…', 'social-testimonials-and-reviews-widget' ),
            'code_send_failed'        => __( "Couldn't send the code. Please try again.", 'social-testimonials-and-reviews-widget' ),
            /* translators: %s: the email address the code was sent to. */
            'code_sent_hint'          => __( 'We emailed a 6-digit code to %s. It expires in 10 minutes.', 'social-testimonials-and-reviews-widget' ),
            'code_required'           => __( 'Enter the 6-digit code from your email.', 'social-testimonials-and-reviews-widget' ),
            'code_invalid'            => __( 'Invalid or expired code. Try again or request a new one.', 'social-testimonials-and-reviews-widget' ),

            // Confirm dialog (Disconnect, etc.).
            'confirm_title'           => __( 'Are you sure?', 'social-testimonials-and-reviews-widget' ),
            'confirm_yes'             => __( 'Yes, do it', 'social-testimonials-and-reviews-widget' ),
            'confirm_cancel'          => __( 'Cancel', 'social-testimonials-and-reviews-widget' ),
            'disconnect_title'        => __( 'Disconnect from Repuso?', 'social-testimonials-and-reviews-widget' ),
            'disconnect_message'      => __( "You can reconnect anytime by signing back in. Your data on Repuso isn't affected - only this site's link to it is removed.", 'social-testimonials-and-reviews-widget' ),
            'disconnect_yes'          => __( 'Yes, disconnect', 'social-testimonials-and-reviews-widget' ),

            // Section-level access-denied state for regular Users
            // who can view dashboards but can't manage channels or widgets.
            'section_no_access_title' => __( 'Not available for your role', 'social-testimonials-and-reviews-widget' ),
            /* translators: %s: the section name the current role can't access (e.g. "Channels" or "Widgets"). */
            'section_no_access_body'  => __( "Your account role doesn't have access to %s. Ask an admin on your Repuso account to grant access, or sign in with an admin user.", 'social-testimonials-and-reviews-widget' ),
        );
    }

    function admin_enqueue_scripts() {

		if ( !current_user_can('manage_options') ) {
			return;
		}

        // Only load our admin assets on Repuso plugin pages. The page slug
        // is the canonical WP admin routing parameter; reading it here is
        // a read-only check (no state changes) so no nonce is required.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, not a write action.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $rw_pages = array(
            'rw_dashboard',
            'rw_reviews',
            'rw_channels',
            'rw_widgets',
            'rw_overview',
            'pagewide_widget',
        );
        if ( ! in_array( $page, $rw_pages, true ) ) {
            // On non-plugin admin pages we still want the review-request
            // notice styled - author_admin_notice() can fire here too
            // (it's hooked into admin_notices globally). Enqueue only the
            // small rw-admin.css when the notice's own conditions will
            // be met; this keeps the bare cost zero for the common case.
            if ( $this->should_show_review_notice() ) {
                $plugin_dir = plugin_dir_path( __FILE__ );
                $rel        = 'css/rw-admin.css';
                $abs        = $plugin_dir . $rel;
                $ver        = file_exists( $abs ) ? (string) filemtime( $abs ) : '6.0.0';
                wp_enqueue_style( 'rw_css_admin_notice', $this->plugin_url . $rel, array(), $ver );
            }
            return;
        }

        // filemtime() as cache buster: every edit during dev forces a fresh
        // download, and once the file is stable the version is just the mtime
        // (effectively the release timestamp). Falls back to plugin version
        // when the file isn't readable, so this never hard-fails.
        $plugin_dir = plugin_dir_path( __FILE__ );
        $ver = function ( $rel ) use ( $plugin_dir ) {
            $abs = $plugin_dir . $rel;
            return file_exists( $abs ) ? (string) filemtime( $abs ) : '6.0.0';
        };

        wp_enqueue_style(  'rw_css_admin',   $this->plugin_url . 'css/rw-admin.css',   array(),         $ver( 'css/rw-admin.css' ) );
        wp_enqueue_style(  'rw_css_onboard', $this->plugin_url . 'css/rw-onboard.css', array(),         $ver( 'css/rw-onboard.css' ) );
        wp_enqueue_script( 'rw_js_admin',    $this->plugin_url . 'js/rw-admin.js',    array( 'jquery' ), $ver( 'js/rw-admin.js' ),    true );
        wp_enqueue_script( 'rw_js_onboard',  $this->plugin_url . 'js/rw-onboard.js',  array(),           $ver( 'js/rw-onboard.js' ),  true );

        // Overview dashboard JS + ApexCharts only load on the dashboard page
        // so other plugin pages don't pay the chart-library download.
        // We use ApexCharts (bundled locally) to mirror the visual style of
        // the production Repuso dashboard, which is also built on ApexCharts.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && $screen->id === 'toplevel_page_rw_dashboard' ) {
            wp_enqueue_script(
                'rw_apexcharts',
                $this->plugin_url . 'js/vendor/apexcharts.min.js',
                array(),
                '3.49.1',
                true
            );
            wp_enqueue_script(
                'rw_js_overview',
                $this->plugin_url . 'js/rw-overview.js',
                array( 'jquery', 'rw_js_admin', 'rw_apexcharts' ),
                $ver( 'js/rw-overview.js' ),
                true
            );
        }

		wp_localize_script('rw_js_admin', 'ajax_var', array(
			'url'              => admin_url('admin-ajax.php'),
			'nonce'            => wp_create_nonce('ajax-nonce'),
			'subAccount'       => (int) $this->websiteId,
			'appUrl'           => $this->appUrl,
			'floatingWidgetId' => (string) get_option( 'repuso_floating_widget_id' ),
			// WP admin URLs the dashboard JS routes to for in-plugin
			// navigation (Reviews/Inbox setup CTA, signup→dashboard
			// redirect, etc.).
			'reviewsUrl'       => admin_url( 'admin.php?page=rw_reviews' ),
			'dashboardUrl'     => admin_url( 'admin.php?page=rw_dashboard' ),
			'maxConcurrent'    => max( 1, (int) REPUSO_MAX_CONCURRENT ),
		));

		// Translation bundle for user-visible JS strings. Read at the
		// JS layer via `var s = (window.rwI18n || {}).<key> || '<fallback>'`
		// so a missing key gracefully falls back to English instead of
		// rendering "undefined". Each entry goes through __() so the
		// strings are picked up by WP-CLI's `i18n make-pot` scan and
		// translated like any other plugin text.
		wp_localize_script( 'rw_js_admin', 'rwI18n', $this->js_strings() );

		// Pre-filled fields for the signup form + URLs for "Open dashboard" links.
		// All values come from the WP install so the user doesn't retype them.
		$current_user = wp_get_current_user();
		// Two-letter language hint passed to /v1/login/code/send so the
		// OTP email arrives in the same language as the WP admin the
		// user is currently looking at. Falls back to en if WP returns
		// a locale we don't ship a translation for.
		$rw_user_locale = function_exists( 'get_user_locale' ) ? get_user_locale() : determine_locale();
		$rw_lang_hint   = strtolower( substr( (string) $rw_user_locale, 0, 2 ) );

		wp_localize_script('rw_js_onboard', 'RepusoOnboard', array(
			'apiUrl'      => $this->apiUrl,
			'appUrl'      => $this->appUrl,
			'email'       => $current_user ? $current_user->user_email : get_option('admin_email'),
			'businessName'=> get_bloginfo('name'),
			'adminName'   => ($current_user && !empty($current_user->display_name)) ? $current_user->display_name : '',
			'vanityUrl'   => $this->hostname,
			'source'      => 'wordpress-' . sanitize_title((string) wp_get_theme()),
			'lang'        => $rw_lang_hint,
		));

		// Crisp chatbox on plugin admin pages. Lets users start a
		// conversation from anywhere in the plugin (and the Help page
		// has a dedicated CTA that opens it). Scoped to our pages via
		// the ?page= query var, which is reliable for both top-level
		// and submenu screens (the WP_Screen->parent_base trick
		// silently missed every page except the top-level dashboard
		// because submenu screen ids are namespaced under the menu's
		// "Reviews" label, not the slug "rw_dashboard").
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identifier from WP's own admin URL, not form data.
		$current_page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$plugin_pages  = array( 'rw_dashboard', 'rw_reviews', 'rw_channels', 'rw_widgets', 'pagewide_widget', 'rw_overview' );
		$is_plugin_page = in_array( $current_page, $plugin_pages, true );
		if ( $is_plugin_page ) {
			$email = $current_user ? $current_user->user_email : '';
			$name  = ( $current_user && ! empty( $current_user->display_name ) ) ? $current_user->display_name : '';
			$site  = get_bloginfo( 'name' );
			$loader = "window.\$crisp=[];window.CRISP_WEBSITE_ID='1e26e329-cbd9-4800-b151-0c7e3b033790';"
				. "(function(){var d=document,s=d.createElement('script');s.src='https://client.crisp.chat/l.js';s.async=1;d.getElementsByTagName('head')[0].appendChild(s);})();";
			if ( $email !== '' ) {
				$loader .= "\$crisp.push(['set','user:email',[" . wp_json_encode( $email ) . "]]);";
			}
			if ( $name !== '' ) {
				$loader .= "\$crisp.push(['set','user:nickname',[" . wp_json_encode( $name ) . "]]);";
			}
			if ( $site !== '' ) {
				$loader .= "\$crisp.push(['set','session:data',[[['site_name'," . wp_json_encode( $site ) . "]]]]);";
			}
			$loader .= "\$crisp.push(['set','session:data',[[['source','wordpress-plugin']]]]);";
			wp_add_inline_script( 'rw_js_admin', $loader );
		}
    }

    function admin_menu() {
        // The top-level menu's slug now points at the Overview dashboard so
        // that's what users land on by default. The legacy /admin.php?page=rw_widgets
        // URL keeps working because the submenu page is still registered.
        add_menu_page('Repuso', 'Reviews', 'manage_options', 'rw_dashboard', array($this, 'overview_dashboard'), $this->plugin_url . 'images/icon.png');
        add_submenu_page('rw_dashboard', 'Dashboard',       'Dashboard',        'manage_options', 'rw_dashboard',    array($this, 'overview_dashboard'));
        add_submenu_page('rw_dashboard', 'Reviews',         'Reviews',          'manage_options', 'rw_reviews',      array($this, 'reviews'));
        add_submenu_page('rw_dashboard', 'Channels',        'Channels',         'manage_options', 'rw_channels',     array($this, 'channels'));
        add_submenu_page('rw_dashboard', 'Widgets',         'Widgets',          'manage_options', 'rw_widgets',      array($this, 'widgets'));
        // Floating widget lives inside the Widgets section now (inner tabs),
        // not as a standalone left-menu entry. Keep the page handler
        // registered so its URL still resolves.
        add_submenu_page(null,           'Floating Widget', 'Floating widget',  'manage_options', 'pagewide_widget', array($this, 'pagewide_widget'));
        add_submenu_page('rw_dashboard', 'Help',            'Help',             'manage_options', 'rw_overview',     array($this, 'rw_overview'));
    }

    function overview_dashboard() {
        $this->currentSection = 'overview';
        require_once dirname(__FILE__) . '/tmpl/overview-dashboard.php';
    }

    function rw_overview() {
        $this->currentSection = 'guides';
        require_once dirname(__FILE__) . '/tmpl/overview.php';
    }

    function repuso_grid_generator() {
        require_once dirname(__FILE__) . '/tmpl/shortcodes.php';
    }

    /**
     * Anchor the Floating-Widget page (which is registered with a null
     * parent so it doesn't appear as its own sidebar item) to the
     * Widgets submenu for highlighting purposes. Without this, the
     * Repuso menu collapses entirely whenever the user is on the
     * Floating-Widget page because WP can't find a parent to expand.
     */
    public function fix_floating_menu_parent( $parent_file ) {
        global $submenu_file;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && $screen->base === 'admin_page_pagewide_widget' ) {
            $submenu_file = 'rw_widgets';
            return 'rw_dashboard';
        }
        return $parent_file;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- nonce verified at the top via wp_verify_nonce; any $_POST read below that line is protected.
    function pagewide_widget() {
        $saved = false;
        if ( current_user_can( 'manage_options' ) && isset( $_POST['repulso_save'] ) ) {

            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'floating-nonce' ) ) {
                $this->handle_nonce_error();
            }

            $url_itself = array();
            $url_type   = array();
            // Both inputs are arrays of strings; sanitize per-element below.
            // map_deep + sanitize_text_field handles the recursive scrub the
            // checker is looking for.
            if ( isset( $_POST['url_itself'] ) && is_array( $_POST['url_itself'] ) ) {
                $raw_items = map_deep( wp_unslash( $_POST['url_itself'] ), 'sanitize_text_field' );
                $raw_types = isset( $_POST['url_type'] ) && is_array( $_POST['url_type'] )
                    ? map_deep( wp_unslash( $_POST['url_type'] ), 'sanitize_text_field' )
                    : array();
                foreach ( $raw_items as $key => $value ) {
                    if ( trim( (string) $value ) !== '' ) {
                        $url_itself[ $key ] = (string) $value;
                        $url_type[ $key ]   = isset( $raw_types[ $key ] ) ? (string) $raw_types[ $key ] : 'show';
                    }
                }
            }

            update_option( 'url_itself', $url_itself );
            update_option( 'url_type',   $url_type );

            // Iterating $_POST directly tripped the sanitisation linter and meant
            // the plugin would happily store anything starting with "repuso_"; we
            // now scan with a known prefix and sanitise per known shape.
            $clean_post = wp_unslash( $_POST );
            foreach ( $clean_post as $key => $value ) {
                if ( strpos( (string) $key, 'repuso_' ) === 0 && is_scalar( $value ) ) {
                    update_option( sanitize_key( $key ), sanitize_text_field( (string) $value ) );
                }
            }

            // Translate the new selector inputs back into the legacy
            // repuso_js_code shortcode so execute_sidewide_widget() (which
            // do_shortcode()s that option) keeps rendering the right widget.
            $floating_id   = isset( $clean_post['repuso_floating_widget_id'] )   ? sanitize_text_field( (string) $clean_post['repuso_floating_widget_id'] )   : '';
            $floating_type = isset( $clean_post['repuso_floating_widget_type'] ) ? sanitize_text_field( (string) $clean_post['repuso_floating_widget_type'] ) : '';
            $allowed_types = array( 'floating', 'flash', 'badge1', 'badge2' );
            if ( $floating_id !== '' && in_array( $floating_type, $allowed_types, true ) ) {
                update_option( 'repuso_js_code', '[rw_' . $floating_type . ' id="' . $floating_id . '"]' );
            } elseif ( $floating_id === '' ) {
                // "None" selected - clear so the floating widget stops rendering.
                update_option( 'repuso_js_code', '' );
            }

            $saved = true;
        }
        $this->currentSection = 'floating';
        require_once dirname( __FILE__ ) . '/tmpl/floating.php';
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

    function widgets() {
	    $this->currentSection = "widgets";
	    require_once dirname(__FILE__) . '/tmpl/main.php';
    }
    
    function channels() {
	    $this->currentSection = "channels";
	    require_once dirname(__FILE__) . '/tmpl/main.php';
    }
    
    function reviews() {
	    $this->currentSection = "reviews";
	    require_once dirname(__FILE__) . '/tmpl/main.php';
    }

    function repuso() {
	    require_once dirname(__FILE__) . '/tmpl/main.php';
    }
    
    function author_admin_notice() {

		if ( !current_user_can('manage_options') ) {
			return;
		}
		
	    $time = time();
	    $screen = get_current_screen(); 
	    $admin_url = admin_url(); 
	    $star = '<svg style="display: inline-block; vertical-align:middle;width: 1em;height: 1em;stroke-width: 0;font-size: 22px;color: #f5b62b;">
	    		<svg style="display: inline-block;width: 1em;height: 1em;stroke-width: 0;stroke: currentcolor;fill: currentcolor;font-size: 22px;color: #f5b62b;" viewBox="0 0 24 24">
	    		<path d="M12 17.25l-6.188 3.75 1.641-7.031-5.438-4.734 7.172-0.609 2.813-6.609 2.813 6.609 7.172 0.609-5.438 4.734 1.641 7.031z"></path></svg></svg>';
		
		// Legacy "Click to connect" notice removed in 6.0.0 - replaced
		// by the unified admin_account_notice() which shows a clearer
		// "Connect to display reviews from Google, Facebook, etc."
		// banner with proper plugin-page exclusions. The rw_notice_settings_dismissed_until
		// option is no longer read but left in the DB on existing
		// installs (no migration to clean it up - harmless).

		//update_option('rw_notice_review_dismissed_until', '');
		
		if ( $this->should_show_review_notice() ) {
				$icon_url    = esc_url( $this->plugin_url . 'images/icon-hi.png' );
				$reviews_url = 'https://g.page/r/CRKBkSStX9qQEAg/review';
				$allowed_svg = array(
					'svg'  => array( 'style' => array(), 'viewbox' => array() ),
					'path' => array( 'd' => array() ),
				);
				// On Repuso plugin pages rw-admin.js handles the show
				// + dismiss wiring (display:none keeps it hidden until
				// JS finishes WP's "move notices above wrap" pass).
				// On other admin pages neither rw-admin.js nor the
				// CSS-driven hide are reliable, so we render visible
				// and attach inline dismiss handling.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, not a write action.
				$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
				$rw_plugin_pages = array( 'rw_dashboard', 'rw_reviews', 'rw_channels', 'rw_widgets', 'rw_overview', 'pagewide_widget' );
				$on_plugin_page  = in_array( $page, $rw_plugin_pages, true );
				$notice_hidden_attr = $on_plugin_page ? ' style="display:none;"' : '';
				?>
				<div id="rw-notice-review" class="notice notice-info is-dismissible rw-review-notice"<?php echo $notice_hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- only emits a literal style attribute or empty string. ?>>
					<div class="rw-review-notice__row">
						<img class="rw-review-notice__icon" src="<?php echo esc_url( $icon_url ); ?>" alt="" />
						<div class="rw-review-notice__body">
							<p class="rw-review-notice__lede">
								<strong><?php esc_html_e( 'Loving Repuso so far?', 'social-testimonials-and-reviews-widget' ); ?></strong>
							</p>
							<p class="rw-review-notice__text">
								<?php esc_html_e( 'A quick 5-star Google review would mean the world. It takes 30 seconds and helps other businesses discover Repuso.', 'social-testimonials-and-reviews-widget' ); ?>
								<br />
								<span class="rw-review-notice__thanks">
									<span class="rw-review-notice__pray" aria-hidden="true">🙏</span>
									<?php esc_html_e( 'Thank you very much in advance!', 'social-testimonials-and-reviews-widget' ); ?>
								</span>
							</p>
							<p class="rw-review-notice__actions">
								<a class="rw-review-notice__cta" href="<?php echo esc_url( $reviews_url ); ?>" target="_blank" rel="noopener">
									<span class="rw-review-notice__cta-text">
										<?php
										printf(
											/* translators: %s: gold star icon shown inline with the text. */
											esc_html__( 'Rate us %s5 on Google', 'social-testimonials-and-reviews-widget' ),
											'<span class="rw-review-notice__cta-star" aria-hidden="true">&#9733;</span>'
										);
										?>
									</span>
									<span class="dashicons dashicons-external" aria-hidden="true"></span>
								</a>
								<a class="rw-review-notice__link rw-dismiss" data-until="7" href=""><?php esc_html_e( 'Maybe later', 'social-testimonials-and-reviews-widget' ); ?></a>
								<a class="rw-review-notice__link rw-dismiss" data-until="never" href=""><?php esc_html_e( "Don't show again", 'social-testimonials-and-reviews-widget' ); ?></a>
							</p>
						</div>
					</div>
				</div>
				<?php
				// Inline dismiss handler for non-plugin admin pages -
				// rw-admin.js (which carries the global dismiss wiring)
				// is plugin-pages-only. The WP-injected .notice-dismiss
				// X is treated as 30-day; the .rw-dismiss "Maybe later"
				// / "Don't show again" links use their own data-until.
				if ( ! $on_plugin_page ) {
					$review_nonce = wp_create_nonce( 'ajax-nonce' );
					$review_js    = sprintf(
						'(function(){var n=document.getElementById("rw-notice-review");if(!n)return;'
						. 'var nonce=%1$s;'
						. 'function dismiss(until){var fd=new FormData();fd.append("action","rw_store_notice_dismiss");'
						. 'fd.append("nonce",nonce);fd.append("type","review");fd.append("days",until);'
						. 'fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:fd});'
						. 'n.parentNode&&n.parentNode.removeChild(n);}'
						. 'n.addEventListener("click",function(e){var t=e.target;if(!t)return;'
						. 'if(t.classList&&t.classList.contains("notice-dismiss")){dismiss("30");return;}'
						. 'if(t.classList&&t.classList.contains("rw-dismiss")){e.preventDefault();dismiss(t.getAttribute("data-until")||"7");return;}'
						. '});'
						. '})();',
						wp_json_encode( $review_nonce )
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (static template + wp_json_encode).
					echo '<script>' . $review_js . '</script>';
				}
			}

		// "X reviews waiting for your approval" admin notice. Connected
		// users only, gated on a stored pending count that the dashboard's
		// JS keeps fresh whenever loadPending() lands. We don't fire an
		// API request from PHP for this - the last-known count is good
		// enough as a nudge to come back and approve. Scoped to the WP
		// dashboard screen ('dashboard') so the notice doesn't follow
		// the user onto every single admin page.
		$on_wp_dashboard = $screen && $screen->id === 'dashboard';
		$pending_dismissed_until = sanitize_text_field( (string) get_option( 'rw_notice_pending_dismissed_until' ) );
		$pending_dismissed = ! empty( $pending_dismissed_until ) && ( $pending_dismissed_until === 'never' || $pending_dismissed_until > $time );
		$pending_count     = (int) get_option( 'rw_pending' );
		if ( $on_wp_dashboard && ! $pending_dismissed && $this->apiKey && $pending_count > 0 ) {
			$reviews_url = admin_url( 'admin.php?page=rw_reviews' );
			?>
			<div id="rw-notice-pending" class="notice notice-info is-dismissible" style="display:none;">
				<p>
					<strong><?php echo esc_html( $pending_count ); ?></strong>
					<?php esc_html_e( 'reviews waiting for your approval on Repuso.', 'social-testimonials-and-reviews-widget' ); ?>
					<a href="<?php echo esc_url( $reviews_url ); ?>" style="margin-left: 4px;"><?php esc_html_e( 'Review them', 'social-testimonials-and-reviews-widget' ); ?> &rarr;</a>
					<a class="rw-dismiss" data-until="7" href="" style="float: right;"><?php esc_html_e( 'Dismiss', 'social-testimonials-and-reviews-widget' ); ?></a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Shared gate for every plugin AJAX endpoint: verifies the user has
	 * manage_options AND the nonce matches. On failure it short-circuits with
	 * a 403 JSON response and never returns. On success it returns the
	 * sanitized nonce (callers don't use it, but the value being returned
	 * signals "checks passed" cleanly).
	 */
	private function verify_ajax_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* the nonce check.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ajax-nonce' ) ) {
			wp_send_json_error( array( 'error' => 'nonce' ), 403 );
		}
		return $nonce;
	}

	function handle_nonce_error() {
		wp_send_json_error( array( 'error' => 'nonce' ), 403 );
	}

	function ajax_rw_get_login_url() {
		$this->verify_ajax_request();

		// Optional in-app deep link. The dashboard's SigninCtrl reads `next`
		// from $location.search() and routes there after the magic-link
		// login resolves, so the user lands directly on the requested page
		// (widget editor, widgets/new, etc.) instead of the dashboard home.
		// Restricted to relative paths starting with "/" so this can't be
		// abused as an open redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above.
		$next = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['path'] ) ) : '';
		if ( $next !== '' && $next[0] !== '/' ) {
			$next = '';
		}

		// Forward the currently selected sub-account so SigninCtrl can set
		// the dashboard's selected website before navigating to `next` -
		// otherwise a deep link to e.g. /widgets/123 misses if that widget
		// belongs to a different sub-account than the dashboard remembered
		// in localStorage. We always send our current websiteId; the
		// dashboard treats 0 as "main account / all websites".
		$website_param = '&website_id=' . (int) $this->websiteId;

		if ( ! empty( $this->apiKey ) ) {
			$url = $this->appUrl . '#/login/' . $this->apiKey;
			if ( $next !== '' ) {
				$url .= '?next=' . rawurlencode( $next ) . $website_param;
			} else {
				$url .= '?website_id=' . (int) $this->websiteId;
			}
		} else {
			// Not connected - just send them to the app; they can sign in
			// manually and we still preserve the intended destination via
			// the same `next` mechanism.
			$url = $this->appUrl;
			if ( $next !== '' ) {
				$url .= '#' . $next;
			}
		}
		$this->loginUrl = $url;
		wp_send_json( array( 'loginUrl' => $this->loginUrl ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified inside verify_ajax_request() at the top of each handler below.
	function ajax_rw_store_info() {
		$this->verify_ajax_request();

		// Update only the fields that the caller actually sent so a
		// partial caller (e.g. loadPending posting just `pending`)
		// doesn't clobber the others to 0 and break the review-notice
		// gate (which reads rw_posts / rw_widgets / rw_trial).
		if ( isset( $_POST['posts'] ) ) {
			update_option( 'rw_posts', (int) sanitize_text_field( wp_unslash( $_POST['posts'] ) ) );
		}
		if ( isset( $_POST['widgets'] ) ) {
			update_option( 'rw_widgets', (int) sanitize_text_field( wp_unslash( $_POST['widgets'] ) ) );
		}
		if ( isset( $_POST['on_free_trial'] ) ) {
			update_option( 'rw_trial', (int) sanitize_text_field( wp_unslash( $_POST['on_free_trial'] ) ) );
		}
		if ( isset( $_POST['pending'] ) ) {
			update_option( 'rw_pending', (int) sanitize_text_field( wp_unslash( $_POST['pending'] ) ) );
		}
		// Trial / disabled / channels - persisted so the global WP
		// admin trial-or-disabled notice (rendered on every non-plugin
		// admin page) can read them without firing its own API call.
		if ( isset( $_POST['channels'] ) ) {
			update_option( 'rw_channels', (int) sanitize_text_field( wp_unslash( $_POST['channels'] ) ) );
		}
		if ( isset( $_POST['trial_days_left'] ) ) {
			update_option( 'rw_trial_days_left', (int) sanitize_text_field( wp_unslash( $_POST['trial_days_left'] ) ) );
		}
		if ( isset( $_POST['trial_days_total'] ) ) {
			update_option( 'rw_trial_days_total', (int) sanitize_text_field( wp_unslash( $_POST['trial_days_total'] ) ) );
		}
		if ( isset( $_POST['account_disabled'] ) ) {
			update_option( 'rw_account_disabled', (int) sanitize_text_field( wp_unslash( $_POST['account_disabled'] ) ) );
		}

		wp_send_json_success();
	}

	function ajax_rw_store_login() {
		$this->verify_ajax_request();

		$raw_key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( $raw_key !== '' ) {
			update_option( 'rw_apikey', $raw_key );
			$this->apiKey = $raw_key;
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	function ajax_rw_store_subaccount() {
		$this->verify_ajax_request();
		$account = isset( $_POST['account'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['account'] ) ) : 0;
		update_option( 'rw_account', $account );
		wp_send_json_success();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Lightweight connection check used by the status pill. Hits /account/info
	 * with the stored apikey; returns Connected | Not Connected so the JS can
	 * keep its polling loop short-and-cheap (the full account/info payload is
	 * still fetched separately by the widgets/channels/reviews views).
	 */
	function ajax_rw_check_connection() {
		$this->verify_ajax_request();

		if ( empty( $this->apiKey ) ) {
			wp_send_json( array( 'status' => 'Not Connected' ) );
		}

		// Cache a successful Connected check for 5 minutes. The apikey
		// doesn't change between page loads, so we don't need to ping
		// the API for every poll cycle. This also avoids hammering
		// api.repuso.* during the brief window where the "Open Full
		// Dashboard" tab is firing its own account/info, login/key, etc.
		// requests at the same FPM pool on local dev - the second tab
		// can otherwise starve WP's own request out to cURL 28.
		$cache_key = 'rw_conn_' . substr( md5( (string) $this->apiKey ), 0, 16 );
		$cached    = get_transient( $cache_key );
		if ( $cached === 'Connected' ) {
			wp_send_json( array( 'status' => 'Connected' ) );
		}

		$response = wp_remote_get( $this->apiUrl . 'account/info', array(
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( ':' . $this->apiKey ) ),
			'timeout' => 10,
		) );

		// Transient-error path: cURL timeout / DNS / network blip.
		// wp_remote_get returns a WP_Error. Treat these as "state
		// unknown" rather than flipping the UI to "Not Connected" and
		// nuking accountLoaded - both punish the user for a brief
		// upstream stall they had nothing to do with. The apikey is
		// still present, so we report Connected and let the user's
		// next real data fetch tell us if the key is actually invalid.
		if ( is_wp_error( $response ) ) {
			wp_send_json( array( 'status' => 'Connected' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			set_transient( $cache_key, 'Connected', 5 * MINUTE_IN_SECONDS );
			wp_send_json( array( 'status' => 'Connected' ) );
		}

		// Authentic auth failure (401/403): clear cache so a re-connect
		// from the user produces a fresh check.
		delete_transient( $cache_key );
		wp_send_json( array( 'status' => 'Not Connected' ) );
	}

	/**
	 * Disconnect: clear stored apikey + sub-account selection. Replaces the old
	 * ajax_rw_logout, which is kept around so existing UIs that still call it
	 * continue to work during the rollout.
	 */
	function ajax_rw_disconnect() {
		$this->verify_ajax_request();

		// Drop the cached "Connected" check so a fresh connect-attempt
		// after this re-pings the API instead of believing the stale
		// transient.
		$prior_key = (string) get_option( 'rw_apikey' );
		if ( $prior_key !== '' ) {
			delete_transient( 'rw_conn_' . substr( md5( $prior_key ), 0, 16 ) );
		}

		update_option( 'rw_apikey', '' );
		update_option( 'rw_account', 0 );
		$this->apiKey    = false;
		$this->websiteId = 0;
		$this->loginUrl  = $this->appUrl;

		wp_send_json_success();
	}

	function ajax_rw_logout() {
		$this->verify_ajax_request();

		$prior_key = (string) get_option( 'rw_apikey' );
		if ( $prior_key !== '' ) {
			delete_transient( 'rw_conn_' . substr( md5( $prior_key ), 0, 16 ) );
		}

		update_option( 'rw_apikey', '' );
		$this->apiKey   = false;
		$this->loginUrl = $this->appUrl;

		wp_send_json_success();
	}

	function ajax_rw_store_notice_dismiss() {
		$this->verify_ajax_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above.
		$days = isset( $_POST['days'] ) ? sanitize_text_field( wp_unslash( $_POST['days'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( $type === '' ) {
			wp_send_json_error();
		}

		$days_int  = (int) $days;
		$timestamp = $days_int > 0 ? strtotime( '+' . $days_int . ' days', time() ) : 'never';

		update_option( 'rw_notice_' . $type . '_dismissed_until', $timestamp );

		wp_send_json_success( array( 'until' => $timestamp ) );
	}

	/**
	 * Language switcher endpoint. The user picks a locale from the
	 * topbar; we save it to their user meta (`locale`), which WP reads
	 * via get_user_locale() / determine_locale() on subsequent loads.
	 * Setting it to empty falls back to the site language.
	 * Restricted to the locales the plugin actually ships translations
	 * for so a user can't pick something we have no .mo file for.
	 */
	function ajax_rw_set_locale() {
		$this->verify_ajax_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above.
		$raw    = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['locale'] ) ) : '';
		$allowed = $this->supported_locales();
		$keys    = array_keys( $allowed );
		// '' = "use site default" (clear the meta entry).
		if ( $raw !== '' && ! in_array( $raw, $keys, true ) ) {
			wp_send_json_error( array( 'error' => 'unsupported_locale' ), 400 );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'error' => 'not_logged_in' ), 403 );
		}

		if ( $raw === '' ) {
			delete_user_meta( $user_id, 'locale' );
		} else {
			update_user_meta( $user_id, 'locale', $raw );
		}

		wp_send_json_success( array( 'locale' => $raw ) );
	}

	/**
	 * The locales we ship .mo files for. Each entry has a label (in the
	 * locale's own language) and a flag emoji rendered alongside the
	 * code in the topbar dropdown. Keep this aligned with what's
	 * actually bundled in /languages/.
	 */
	public function get_supported_locales() {
		return $this->supported_locales();
	}

	private function supported_locales() {
		return array(
			'en_US' => array( 'label' => 'English',    'flag' => '🇬🇧' ),
			'fr_FR' => array( 'label' => 'Français',   'flag' => '🇫🇷' ),
			'es_ES' => array( 'label' => 'Español',    'flag' => '🇪🇸' ),
			'pt_PT' => array( 'label' => 'Português',  'flag' => '🇵🇹' ),
			'de_DE' => array( 'label' => 'Deutsch',    'flag' => '🇩🇪' ),
			'it_IT' => array( 'label' => 'Italiano',   'flag' => '🇮🇹' ),
		);
	}

	function get_widget_html($args, $content, $shortcode_tag) {
		$type = str_replace("rw_", "", $shortcode_tag);
		$type = str_replace("repuso_", "", $type);
		if(substr($type, 0, 6)==="image_") {
			return $this->get_widget_image_code($type, $args);
		} else if(substr($type, 0, 6)==="email_") {
			return '';
		} else {
			return $this->get_widget_code($type, $args);
		}
	}
    
	function get_widget_image_code($type, $args) {

		if(!empty($args) && is_array($args)) {
			foreach($args as $k => $v) {
				$args[$k] = sanitize_html_class($v);
			}
		}

		if (isset($args['id'])) {
			$id = $args['id'];
			$link = $link_end = "";
			if(!empty($args['link']) ) {
				$link = substr($args['link'], 0, 4) == "http" ? $args['link'] : "https://".$args['link'];
				$link = "<a href='{$link}' target='_blank'>";
				$link_end = "</a>";
			}

			$srcset = $width = $height = '';
			$width = !empty($args['width']) ? $args['width'] : $width; 
			$height = !empty($args['height']) ? $args['height'] : $height; 
			$path =  'https://w.revue.us/v1/widgets/posts/'.$id.'/';
			$rating_img = $path.'rating.png';
			if($width > 0 && $height > 0) {
				$width = ' width="'.$width.'"';
				$height = ' height="'.$height.'"';
			}
			if(!empty($args['scale']) && $args['scale']>1) {
				$srcset = ' srcset="'.$path.'rating2x.png'.' '.$args['scale'].'x"';
			}
			$rating = '<img src="'.$rating_img.'" alt="Star rating"'.$width.$height.$srcset.' />';

			$html = '<!-- Begin widget code -->'.chr(13);
			$html.= $link.$rating.$link_end.chr(13);
			$html.= '<!-- End widget code -->';
			return $html;
		}
	}

    function get_widget_code( $type, $args ) {

        if ( ! empty( $args ) && is_array( $args ) ) {
            foreach ( $args as $k => $v ) {
                $args[ $k ] = sanitize_html_class( $v );
            }
        }

        if ( ! isset( $args['id'] ) ) {
            return '';
        }

        $id = $args['id'];
        unset( $args['id'] );

        // Enqueue the type-specific widget loader as a proper script
        // rather than emitting an inline <script> tag (the plugin
        // checker flags raw script tags). The type="module" attribute
        // is added via the script_loader_tag filter below.
        $handle = 'repuso-widget-' . sanitize_key( $type );
        // Pass the plugin's release version as the script version so
        // browsers refetch the external widget bundle whenever the
        // plugin updates. The widget JS itself is hosted at
        // repuso.com/widgets/2.0/ — the "2.0" in the path is the
        // widget bundle version; we use the WP plugin's version here
        // for cache-busting purposes (tied to WP releases the user
        // controls), not the upstream JS version.
        wp_enqueue_script(
            $handle,
            'https://repuso.com/widgets/2.0/rw-widget-' . sanitize_key( $type ) . '.js',
            array(),
            '6.0.0',
            true
        );

        ob_start();
        ?>
        <!-- Begin widget code -->
        <rw-widget-<?php echo esc_attr( $type ); ?> data-rw-<?php echo esc_attr( $type ); ?>="<?php echo esc_attr( $id ); ?>"<?php
        if ( is_array( $args ) ) {
            foreach ( $args as $key => $value ) {
                printf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
            }
        }
        ?>></rw-widget-<?php echo esc_attr( $type ); ?>>
        <!-- End widget code -->
        <?php
        return ob_get_clean();
    }

    /**
     * Surface trial-ending / account-disabled prompts as a standard WP
     * admin notice on every admin page EXCEPT the plugin's own pages
     * (which already render the inline banner). Values come from WP
     * options that the plugin's JS layer keeps in sync whenever the
     * dashboard refreshes account/info - so the notice has no API cost
     * per page view.
     */
    public function admin_account_notice() {
        // Only meaningful for authorised admins. Match the capability
        // the plugin uses everywhere else.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Skip Repuso plugin pages: they already show the same prompt
        // inline (the rw-trial banner / onboard cards on the Dashboard).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $rw_pages = array( 'rw_dashboard', 'rw_reviews', 'rw_channels', 'rw_widgets', 'rw_overview', 'pagewide_widget' );
        if ( in_array( $page, $rw_pages, true ) ) {
            return;
        }

        $dashboard_url  = esc_url( admin_url( 'admin.php?page=rw_dashboard' ) );

        // Brand prefix used on every variant. Anchored on a single
        // translatable phrase ("Repuso review widgets") so non-English
        // locales keep the brand name verbatim while still being able
        // to localise the descriptor that follows it.
        $brand = esc_html__( 'Repuso review widgets', 'social-testimonials-and-reviews-widget' );

        // Shared inline styles. The plugin's stylesheet isn't enqueued
        // on non-plugin admin pages (we early-return from
        // admin_enqueue_scripts for those), so the notice carries its
        // own layout via inline style attributes.
        $p_style       = 'display:flex; flex-wrap:wrap; align-items:center; gap:6px 14px; margin:0;';
        $headline_st   = 'font-size:13px;';
        $sub_style     = 'color:#555; font-weight:400; flex:1 1 240px; min-width:200px;';
        $cta_style     = 'margin-left:auto;';

        // Repuso "R" icon shown next to the notice copy - same image
        // (images/icon-hi.png) the review-request notice uses, scaled
        // down so it sits next to the headline without dominating the
        // strip. flex-shrink:0 prevents the icon from squishing when
        // the headline + sub-line + CTA wrap on narrow screens.
        $icon_url   = esc_url( $this->plugin_url . 'images/icon-hi.png' );
        $icon_style = 'width:36px;height:36px;flex:0 0 auto;display:block;';
        $icon_html  = '<img src="' . $icon_url . '" alt="" style="' . esc_attr( $icon_style ) . '" />';

        // Builds the inline dismiss-handler <script> body for an
        // is-dismissible notice. WP core injects the .notice-dismiss
        // X button automatically for `.is-dismissible`; clicking it
        // POSTs to the existing rw_store_notice_dismiss endpoint with
        // a per-notice type + day count. Inlined (rather than relying
        // on rw-admin.js) because rw-admin.js isn't enqueued on
        // non-plugin admin pages.
        $dismiss_nonce = wp_create_nonce( 'ajax-nonce' );
        $build_dismiss_js = function ( $element_id, $type, $days ) use ( $dismiss_nonce ) {
            return sprintf(
                '(function(){var n=document.getElementById(%1$s);if(!n)return;'
                . 'n.addEventListener("click",function(e){'
                . 'var t=e.target;if(!t||!t.classList||!t.classList.contains("notice-dismiss"))return;'
                . 'var fd=new FormData();fd.append("action","rw_store_notice_dismiss");'
                . 'fd.append("nonce",%2$s);fd.append("type",%3$s);fd.append("days",%4$s);'
                . 'fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:fd});'
                . '});'
                . '})();',
                wp_json_encode( $element_id ),
                wp_json_encode( $dismiss_nonce ),
                wp_json_encode( $type ),
                wp_json_encode( (string) $days )
            );
        };
        // Helper: returns true when the notice of this `type` has been
        // dismissed and the cooldown hasn't yet expired. Uses the same
        // option naming convention as the existing dismiss endpoint.
        $is_dismissed = function ( $type ) {
            $until = get_option( 'rw_notice_' . $type . '_dismissed_until' );
            return ! empty( $until ) && ( $until === 'never' || (int) $until > time() );
        };

        // Hidden until JS places it right after the page's <h1> (or
        // shows it in place if the page has no .wrap/<h1>). Avoids the
        // "first above title, then jumps below" flash caused by WP
        // emitting admin_notices in #wpbody-content BEFORE the page's
        // wrap+h1 paints. Placeholder-id used by the inline script.
        $notice_id     = 'rw-admin-notice-' . uniqid();
        $wrapper_style = 'display:none;';
        $reposition_js = sprintf(
            '(function(){var n=document.getElementById(%1$s);if(!n)return;'
            . 'function place(){var w=document.querySelector(".wrap");'
            . 'if(w){var h=w.querySelector(":scope > h1, :scope > h2");'
            // Insert BEFORE the h1 so the notice is the first thing
            // inside .wrap, above the page title. (Falls back to
            // prepending inside wrap when there is no h1/h2.)
            . 'if(h){w.insertBefore(n,h);}else{w.insertBefore(n,w.firstChild);}}'
            . 'n.style.display="";}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",place);}else{place();}'
            . '})();',
            wp_json_encode( $notice_id )
        );

        // Not-connected branch: the plugin is installed but no apikey
        // is stored. Surface a friendly onboarding nudge so the user
        // knows what the plugin does and how to start. Dismissible
        // for 30 days so a user who knowingly chose not to connect
        // isn't nagged on every admin page view.
        if ( empty( $this->apiKey ) ) {
            if ( $is_dismissed( 'connect' ) ) {
                return;
            }
            $headline = sprintf(
                /* translators: %s: the plugin brand name "Repuso review widgets". */
                esc_html__( '%s: connect to display reviews on your site', 'social-testimonials-and-reviews-widget' ),
                $brand
            );
            $sub = esc_html__( 'Showcase reviews from Google, Facebook, TripAdvisor, Airbnb and 45+ other platforms. Free 14-day trial.', 'social-testimonials-and-reviews-widget' );
            /* translators: admin-notice CTA that opens the Repuso plugin page where the user can sign up or sign in. */
            $cta = esc_html__( 'Connect', 'social-testimonials-and-reviews-widget' );
            $dismiss_js = $build_dismiss_js( $notice_id, 'connect', 30 );
            ?>
            <div id="<?php echo esc_attr( $notice_id ); ?>" class="notice notice-info is-dismissible rw-admin-notice" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <p style="<?php echo esc_attr( $p_style ); ?>">
                    <?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (esc_url + esc_attr applied above). ?>
                    <strong style="<?php echo esc_attr( $headline_st ); ?>"><?php echo esc_html( $headline ); ?></strong>
                    <span style="<?php echo esc_attr( $sub_style ); ?>"><?php echo esc_html( $sub ); ?></span>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary" style="<?php echo esc_attr( $cta_style ); ?>"><?php echo esc_html( $cta ); ?></a>
                </p>
            </div>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (see comment block above).
            echo '<script>' . $reposition_js . '</script>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (static template + wp_json_encode).
            echo '<script>' . $dismiss_js . '</script>';
            return;
        }

        // The remaining variants (disabled / trial) all need account
        // status, which only makes sense once we're connected. Refresh
        // the cached options from the API if they're stale - throttled
        // to one fetch per 60s across all admin users so the per-
        // pageview cost stays near zero.
        $this->maybe_refresh_account_status();
        $disabled   = (int) get_option( 'rw_account_disabled' );
        $trial_left = (int) get_option( 'rw_trial_days_left', -1 );

        if ( $disabled === 1 ) {
            if ( $is_dismissed( 'disabled' ) ) {
                return;
            }
            $headline = sprintf(
                /* translators: %s: the plugin brand name "Repuso review widgets". */
                esc_html__( '%s: your account is disabled', 'social-testimonials-and-reviews-widget' ),
                $brand
            );
            $sub = esc_html__( 'Choose a plan to reactivate your account and bring your review widgets back online.', 'social-testimonials-and-reviews-widget' );
            /* translators: short admin-notice CTA that opens the Repuso plugin dashboard. */
            $cta = esc_html__( 'Check', 'social-testimonials-and-reviews-widget' );
            // 7-day dismissal: short enough that a still-disabled
            // account gets re-prompted, long enough not to nag every
            // pageview after the user has acknowledged it.
            $dismiss_js = $build_dismiss_js( $notice_id, 'disabled', 7 );
            ?>
            <div id="<?php echo esc_attr( $notice_id ); ?>" class="notice notice-error is-dismissible rw-admin-notice" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <p style="<?php echo esc_attr( $p_style ); ?>">
                    <?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (esc_url + esc_attr applied above). ?>
                    <strong style="<?php echo esc_attr( $headline_st ); ?>"><?php echo esc_html( $headline ); ?></strong>
                    <span style="<?php echo esc_attr( $sub_style ); ?>"><?php echo esc_html( $sub ); ?></span>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary" style="<?php echo esc_attr( $cta_style ); ?>"><?php echo esc_html( $cta ); ?></a>
                </p>
            </div>
            <?php
            // $reposition_js is built from a static template plus wp_json_encode($notice_id);
            // there is no user-supplied content in the output. esc_js() / esc_html() can't
            // be applied to JavaScript bodies (would break them); kept as a direct echo
            // rather than wp_print_inline_script_tag() to match the other notice variants.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction.
            echo '<script>' . $reposition_js . '</script>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (static template + wp_json_encode).
            echo '<script>' . $dismiss_js . '</script>';
            return;
        }

        if ( $trial_left >= 0 ) {
            if ( $is_dismissed( 'trial' ) ) {
                return;
            }
            if ( $trial_left === 0 ) {
                $headline = sprintf(
                    /* translators: %s: the plugin brand name "Repuso review widgets". */
                    esc_html__( '%s: free trial ends today', 'social-testimonials-and-reviews-widget' ),
                    $brand
                );
            } elseif ( $trial_left === 1 ) {
                $headline = sprintf(
                    /* translators: %s: the plugin brand name "Repuso review widgets". */
                    esc_html__( '%s: 1 day left in your free trial', 'social-testimonials-and-reviews-widget' ),
                    $brand
                );
            } else {
                $headline = sprintf(
                    /* translators: 1: the plugin brand name "Repuso review widgets". 2: number of days (always >= 2). */
                    esc_html__( '%1$s: %2$d days left in your free trial', 'social-testimonials-and-reviews-widget' ),
                    $brand,
                    $trial_left
                );
            }
            $sub = esc_html__( 'Showcase reviews from 45+ platforms on your site. Choose your plan to avoid interruptions; remaining trial days are not billed.', 'social-testimonials-and-reviews-widget' );
            /* translators: short admin-notice CTA that opens the Repuso plugin dashboard. */
            $cta = esc_html__( 'Check', 'social-testimonials-and-reviews-widget' );
            // Visual urgency tracks the days-left bucket - same thresholds
            // the inline banner uses (red <= 3, orange <= 7, else default).
            $cls = $trial_left <= 3 ? 'notice-error' : ( $trial_left <= 7 ? 'notice-warning' : 'notice-info' );
            // 3-day dismissal: short enough that the user sees the
            // countdown advance, long enough not to nag every pageview.
            // The notice resurfaces with a fresh day-count when it
            // returns, so the user always gets the up-to-date number.
            $dismiss_js = $build_dismiss_js( $notice_id, 'trial', 3 );
            ?>
            <div id="<?php echo esc_attr( $notice_id ); ?>" class="notice <?php echo esc_attr( $cls ); ?> is-dismissible rw-admin-notice" style="<?php echo esc_attr( $wrapper_style ); ?>">
                <p style="<?php echo esc_attr( $p_style ); ?>">
                    <?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (esc_url + esc_attr applied above). ?>
                    <strong style="<?php echo esc_attr( $headline_st ); ?>"><?php echo esc_html( $headline ); ?></strong>
                    <span style="<?php echo esc_attr( $sub_style ); ?>"><?php echo esc_html( $sub ); ?></span>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary" style="<?php echo esc_attr( $cta_style ); ?>"><?php echo esc_html( $cta ); ?></a>
                </p>
            </div>
            <?php
            // $reposition_js is built from a static template plus wp_json_encode($notice_id);
            // there is no user-supplied content in the output. esc_js() / esc_html() can't
            // be applied to JavaScript bodies (would break them); kept as a direct echo
            // rather than wp_print_inline_script_tag() to match the other notice variants.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction.
            echo '<script>' . $reposition_js . '</script>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe by construction (static template + wp_json_encode).
            echo '<script>' . $dismiss_js . '</script>';
        }
    }

    /**
     * Shared gate for the "Loving Repuso so far?" review-request
     * notice. Returns true when ALL of the following are met:
     *   - the admin hasn't dismissed the notice
     *   - the WP install is connected to Repuso (has apikey)
     *   - the cached usage stats show ≥5 approved reviews and
     *     ≥1 widget AND the account is past the trial. We don't ask
     *     for reviews until the user is getting real value.
     * Used both by author_admin_notice() (render path) and by
     * admin_enqueue_scripts() (decides whether to enqueue rw-admin.css
     * on non-plugin admin pages so the notice renders styled).
     */
    private function should_show_review_notice() {
        if ( empty( $this->apiKey ) ) {
            return false;
        }
        $dismissed_until = sanitize_text_field( (string) get_option( 'rw_notice_review_dismissed_until' ) );
        if ( ! empty( $dismissed_until ) && ( $dismissed_until === 'never' || (int) $dismissed_until > time() ) ) {
            return false;
        }
        $posts   = (int) get_option( 'rw_posts' );
        $widgets = (int) get_option( 'rw_widgets' );
        $trial   = (int) get_option( 'rw_trial' );
        return ( $posts >= 5 && $widgets > 0 && $trial === 0 );
    }

    /**
     * Throttled server-side refresh of the cached account-status options
     * used by admin_account_notice(). Hits /v1/account/info at most
     * once per 60 seconds across all admin users (transient-coordinated)
     * so a disable / renewal on the Repuso side shows up in WP within
     * a minute, without firing the API on every admin page view.
     */
    private function maybe_refresh_account_status() {
        $throttle_key = 'rw_account_status_throttle';
        if ( get_transient( $throttle_key ) ) {
            return;
        }
        // Set the throttle BEFORE the request so a concurrent admin
        // view doesn't fire a second fetch while ours is in flight.
        set_transient( $throttle_key, 1, 60 );

        $response = wp_remote_get(
            $this->apiUrl . 'account/info',
            array(
                'timeout' => 5,
                'headers' => array( 'Authorization' => 'Basic ' . base64_encode( ':' . $this->apiKey ) ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return;
        }

        // Mirror the field set the JS layer's rw_store_info call writes.
        if ( array_key_exists( 'on_free_trial', $body ) ) {
            $oft = $body['on_free_trial'];
            $trial_left = ( $oft === false || $oft === null ) ? -1 : (int) $oft;
            update_option( 'rw_trial_days_left', $trial_left );
            update_option( 'rw_trial', $trial_left >= 0 ? 1 : 0 );
        }
        if ( isset( $body['trial_days'] ) ) {
            update_option( 'rw_trial_days_total', (int) $body['trial_days'] );
        }
        if ( array_key_exists( 'disabled', $body ) ) {
            update_option( 'rw_account_disabled', (int) (bool) $body['disabled'] );
        }
        if ( isset( $body['channels']['usage'] ) ) {
            update_option( 'rw_channels', (int) $body['channels']['usage'] );
        }
        if ( isset( $body['widgets']['usage'] ) ) {
            update_option( 'rw_widgets', (int) $body['widgets']['usage'] );
        }
        if ( isset( $body['approved_posts']['usage'] ) ) {
            update_option( 'rw_posts', (int) $body['approved_posts']['usage'] );
        }
    }

    /**
     * Force the Repuso top-level menu icon to render at full opacity.
     * WP core ships icons at 0.6 (only 1.0 on hover / current screen)
     * which makes the orange R look washed out next to neighbouring
     * menu items. Targeted by the toplevel page slug so we don't
     * touch any other plugin's icons.
     */
    public function print_menu_icon_css() {
        echo '<style>'
            . '#adminmenu li.toplevel_page_rw_dashboard div.wp-menu-image img,'
            . '#adminmenu li.toplevel_page_rw_dashboard div.wp-menu-image:before{opacity:1;}'
            . '</style>';
    }

    function my_plugin_action_links( $links ) {

		$label = $this->apiKey
			? __( 'Settings', 'social-testimonials-and-reviews-widget' )
			: __( 'Connect to Repuso', 'social-testimonials-and-reviews-widget' );

		$links = array_merge( array(
			'<a href="' . esc_url( admin_url( '/admin.php?page=rw_dashboard' ) ) . '">' . esc_html( $label ) . '</a>'
		), $links );

		return $links;
	}
	
	function enqueue_modal_window_assets()
	{
	  // Check that we are on the right screen
	  if (get_current_screen()->id == 'toplevel_page_rw_widgets') {
	    // Enqueue the assets
	    wp_enqueue_script( 'thickbox' );
		wp_enqueue_style( 'thickbox' );
	  }
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified explicitly at the top of hook() below.
	function hook() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		// Whitelist of HTTP methods we proxy. wp_remote_request only honours
		// these; anything else is coerced to GET so a malformed/attacker-supplied
		// "method" cannot reach the upstream API.
		$method = isset( $_POST['method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['method'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ), true ) ) {
			$method = 'GET';
		}

		// Path is appended to $this->apiUrl so we restrict it to the shape our
		// own JS produces: alphanumerics, `/`, `?`, `&`, `=`, `.`, `_`, `-`.
		// This prevents path-escape attacks (e.g. "../" or scheme switching).
		$raw_path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$path     = preg_replace( '#[^a-zA-Z0-9/?&=._%\\-]#', '', (string) $raw_path );

		// Headers come in as $_POST['headers'][Key]=Value. Sanitize each value
		// to a single line. Authorization gets rewritten below regardless.
		$headers     = array();
		$raw_headers = isset( $_POST['headers'] ) && is_array( $_POST['headers'] )
			? map_deep( wp_unslash( $_POST['headers'] ), 'sanitize_text_field' )
			: array();
		foreach ( $raw_headers as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$headers[ sanitize_text_field( (string) $k ) ] = (string) $v;
			}
		}

		// Authorization, when requested by the JS, is filled in server-side
		// with the stored API key (or one supplied for the immediate post-login
		// "swap to logged in" call). The client never sees the raw header.
		if ( ! empty( $headers['Authorization'] ) ) {
			$supplied_key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
			$key          = $supplied_key !== '' ? $supplied_key : $this->apiKey;
			$headers['Authorization'] = 'Basic ' . base64_encode( ':' . $key );
		}

		// Body is forwarded as JSON. We accept either an array (typical AJAX
		// form-encoded body[key]=value) or a JSON string.
		$body = null;
		if ( isset( $_POST['body'] ) && ! empty( $_POST['body'] ) ) {
			$raw_body = is_array( $_POST['body'] )
				? map_deep( wp_unslash( $_POST['body'] ), 'sanitize_text_field' )
				: sanitize_text_field( wp_unslash( $_POST['body'] ) );
			if ( is_array( $raw_body ) ) {
				$clean = array();
				foreach ( $raw_body as $bk => $bv ) {
					if ( is_scalar( $bv ) ) {
						$clean[ sanitize_text_field( (string) $bk ) ] = (string) $bv;
					}
				}
				$body = wp_json_encode( $clean );
			} elseif ( is_string( $raw_body ) ) {
				$body = $raw_body;
			}
		}

		// The upstream API's JSON body middleware (public/index.php) rejects
		// any POST/PUT with `Content-Type: application/json` that has no
		// body - it calls $request->getParsedBody() which decodes JSON to
		// an array and bails with `{error:true, message:"No data."}` (401)
		// when that array is empty. Even sending `'{}'` doesn't satisfy
		// it (parses to []). jQuery serialises an empty `body: {}` to
		// nothing in the form payload, so $_POST['body'] is unset on
		// requests like posts/ai/reply/{id} that legitimately don't need
		// a body. Strip Content-Type: application/json in that case so
		// the middleware skips its JSON-content check entirely.
		if ( $body === null ) {
			foreach ( array_keys( $headers ) as $hk ) {
				if ( strtolower( (string) $hk ) === 'content-type' ) {
					unset( $headers[ $hk ] );
				}
			}
		}

		// AI endpoints (suggestion / insights generation) can take 20-40s
		// upstream; the default 20s wp_remote_request timeout cuts the
		// response off and the user sees a generic "couldn't generate"
		// error. Bump to 60s when the path includes "ai/".
		$is_ai_path = ( strpos( $path, 'ai/' ) !== false );
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $is_ai_path ? 60 : 20,
		);
		if ( $body !== null ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $this->apiUrl . $path, $args );

		$return = isset( $_POST['return'] ) ? sanitize_text_field( wp_unslash( $_POST['return'] ) ) : '';
		if ( $return !== 'plain' ) {
			header( 'Content-Type: application/json' );
		}

		$body_out = '';
		if ( is_wp_error( $response ) ) {
			// Surface transport-level failures (cURL errors, SSL, timeouts,
			// DNS) inline so the JS can show them to the user instead of
			// staring at an empty body it parses as {}.
			$body_out = wp_json_encode( array(
				'_proxy_error'   => true,
				'_proxy_message' => $response->get_error_message(),
				'_upstream_url'  => $this->apiUrl . $path,
			) );
		} else {
			$body_out         = wp_remote_retrieve_body( $response );
			$upstream_status  = wp_remote_retrieve_response_code( $response );
			if ( $body_out === '' ) {
				$body_out = wp_json_encode( array(
					'_proxy_error'    => true,
					'_proxy_message'  => 'Empty body from upstream',
					'_upstream_status'=> $upstream_status,
					'_upstream_url'   => $this->apiUrl . $path,
				) );
			}
		}

		// Upstream payload is JSON from api.repuso.* which we trust; we don't
		// HTML-escape it because that would break the JSON the JS expects.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body_out;
		exit();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

$widget_shortcodes = [
	'rw_grid', 'repuso_grid', 'repuso_inline', 'rw_inline', 'repuso_photoset', 'rw_photoset', 'repuso_badge1', 'rw_badge1',
	'repuso_masonry', 'rw_masonry', 'repuso_flash', 'rw_flash', 'repuso_floating', 'rw_floating', 'repuso_mediawall', 'rw_mediawall', 
	'repuso_list', 'rw_list', 'repuso_slider', 'rw_slider', 'repuso_badge2', 'rw_badge2',
	'rw_email1', 'rw_image_badge1', 'rw_image_badge2' , 'rw_image_badge3'
];

$da = new RepusoIntegration();
add_action('init', array($da, 'init'));
add_action('wp_footer', array($da, 'execute_sidewide_widget'));
foreach($widget_shortcodes as $shortcode)
	add_shortcode($shortcode, array($da, 'get_widget_html'));

add_action('admin_enqueue_scripts', array($da, 'admin_enqueue_scripts'));
add_action('admin_menu', array($da, 'admin_menu'));
add_action('wp_ajax_rw_get_login_url', array($da, 'ajax_rw_get_login_url'));
add_action('wp_ajax_rw_store_login', array($da, 'ajax_rw_store_login'));
add_action('wp_ajax_rw_store_subaccount', array($da, 'ajax_rw_store_subaccount'));
add_action('wp_ajax_rw_store_info', array($da, 'ajax_rw_store_info'));
add_action('wp_ajax_rw_logout', array($da, 'ajax_rw_logout'));
add_action('wp_ajax_rw_disconnect', array($da, 'ajax_rw_disconnect'));
add_action('wp_ajax_rw_check_connection', array($da, 'ajax_rw_check_connection'));
add_action('wp_ajax_rw_store_notice_dismiss', array($da, 'ajax_rw_store_notice_dismiss'));
add_action('wp_ajax_rw_set_locale', array($da, 'ajax_rw_set_locale'));
add_action('admin_enqueue_scripts', array($da, 'enqueue_modal_window_assets'));
add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($da, 'my_plugin_action_links') );
add_action( 'plugins_loaded', array( $da, 'get_user_info' ) );
add_action('admin_notices', array( $da, 'author_admin_notice' ));
add_action('admin_notices', array( $da, 'admin_account_notice' ));
add_action('admin_head',    array( $da, 'print_menu_icon_css' ));
add_filter('parent_file',   array( $da, 'fix_floating_menu_parent' ));
add_action('wp_ajax_hook', array($da, 'hook'));

// Promote our `repuso-widget-*` script handles to ES modules with
// crossorigin + data-cfasync attributes when they get printed. The
// original inline <script> tag carried these attributes; we keep them
// here so the enqueued version behaves identically without tripping
// the plugin checker's "no raw script tags" rule.
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( strpos( $handle, 'repuso-widget-' ) === 0 ) {
		$tag = str_replace( '<script ', '<script type="module" crossorigin="anonymous" data-cfasync="false" ', $tag );
	}
	return $tag;
}, 10, 2 );
