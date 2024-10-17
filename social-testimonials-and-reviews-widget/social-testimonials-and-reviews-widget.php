<?php
if (!defined('ABSPATH'))
    exit;
/*
  Plugin Name: Social proof testimonials and reviews by Repuso
  Description: Social testimonials & reviews on your own website as social proof. Increase your website's sales and conversion rate with Repuso.
  Version: 5.11
 */

class RepusoIntegration {
	
	var $apiUrl = "https://api.repuso.com/v1/";
	var $appUrl = "https://repuso.com/app/";
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
        
		$hostname = parse_url(get_site_url(), PHP_URL_HOST);
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

        $url_itself = sanitize_text_field(get_option('url_itself'));
        $url_type = sanitize_text_field(get_option('url_type'));
        
        if (is_array($url_itself) && !empty($url_itself)) {
            foreach ($url_itself as $key => $value) {
                
				$uri = sanitize_text_field($_SERVER['REQUEST_URI']);
                $ru = str_replace('/','',$uri);
                $va = str_replace('/','',$value);
               
                /*var_dump(fnmatch($value, '/sample-page/'));*/
                if(fnmatch($va, $ru) && $url_type[$key]=='show')
                {
                    $show = true;
                }
                
                if(fnmatch($va, $ru) && $url_type[$key]=='hide')
                {
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
        //wp_enqueue_style('repuso_css', plugin_dir_url(__FILE__) . 'css/rw-front.css');
    }

    function admin_enqueue_scripts() {
        wp_enqueue_style('rw_css_admin', $this->plugin_url . 'css/rw-admin.css');
        wp_enqueue_script('rw_js_admin', $this->plugin_url . 'js/rw-admin.js');
		wp_localize_script('rw_js_admin', 'ajax_var', array(
			'url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('ajax-nonce')
		));
    }

    function admin_menu() {
        add_menu_page('Repuso', 'Reviews', 'manage_options', 'rw_widgets', array($this, 'widgets'), $this->plugin_url . '/images/icon.png');
        add_submenu_page('rw_widgets', 'Widgets', 'Widgets', 'manage_options', 'rw_widgets', array($this, 'widgets'));
        add_submenu_page('rw_widgets', 'Channels', 'Channels', 'manage_options', 'rw_channels', array($this, 'channels'));
        add_submenu_page('rw_widgets', 'Reviews', 'Reviews', 'manage_options', 'rw_reviews', array($this, 'reviews'));
        add_submenu_page('rw_widgets', 'Floating Widget', 'Floating widget', 'manage_options', 'pagewide_widget', array($this, 'pagewide_widget'));
        //add_submenu_page('rw_widgets', 'Shortcodes', 'Shortcodes', 'manage_options', 'grid_shortcode', array($this, 'repuso_grid_generator'));
        add_submenu_page('rw_widgets', 'Overview', 'Video guides', 'manage_options', 'rw_overview', array($this, 'rw_overview'));
    }
    
    function rw_overview() {
        require_once dirname(__FILE__) . '/tmpl/overview.php';
    }

    function repuso_grid_generator() {
        require_once dirname(__FILE__) . '/tmpl/shortcodes.php';
    }

    function pagewide_widget() {
        $saved = false;
        if (!empty($_POST) && isset($_POST['repulso_save']) && current_user_can('manage_options')) {

			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'floating-nonce' ) ) {
				$this->handle_nonce_error();
			}

	        $url_itself = [];
	        $url_type = [];
            if (!empty($_POST['url_itself'])) {
                foreach ($_POST['url_itself'] as $key => $value) {
	                
	                if(!empty(trim($value))) {
		                $url_itself[$key] = sanitize_text_field($_POST['url_itself'][$key]);
		                $url_type[$key] = sanitize_text_field($_POST['url_type'][$key]);
	                }
                }
            }
            
            update_option('url_itself', $url_itself);
            update_option('url_type', $url_type);
            
            foreach ($_POST as $key => $value) {
	            if(substr( $key, 0, 7 ) === "repuso_") {
		            $value = sanitize_text_field($value);
		            update_option($key, $value);
	            }
            }
            $saved = true;
        }
        require_once dirname(__FILE__) . '/tmpl/floating.php';
    }
    
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
    
    function author_admin_notice(){
	    $time = time();
	    $screen = get_current_screen(); 
	    $admin_url = admin_url(); 
	    $star = '<svg style="display: inline-block; vertical-align:middle;width: 1em;height: 1em;stroke-width: 0;font-size: 22px;color: #f5b62b;">
	    		<svg style="display: inline-block;width: 1em;height: 1em;stroke-width: 0;stroke: currentcolor;fill: currentcolor;font-size: 22px;color: #f5b62b;" viewBox="0 0 24 24">
	    		<path d="M12 17.25l-6.188 3.75 1.641-7.031-5.438-4.734 7.172-0.609 2.813-6.609 2.813 6.609 7.172 0.609-5.438 4.734 1.641 7.031z"></path></svg></svg>';
		
		//update_option('rw_notice_settings_dismissed_until', '');
		
		$settings_notice_dismissed = false;
		$dismissed_until = get_option('rw_notice_settings_dismissed_until'); 
		if(!empty($dismissed_until) && ($dismissed_until=="never" || $dismissed_until>$time))
			$settings_notice_dismissed = true;
		
		if ( !$settings_notice_dismissed && !$this->apiKey && $screen->parent_base !== 'rw_widgets' ) {
		    
		    $code = sanitize_textarea_field(get_option('repuso_js_code')); 
		    
			if(empty($code)) {
			    //$user = wp_get_current_user();
		    	echo '<div id="rw-notice-settings" class="notice notice-info is-dismissible">
		          <p>'.$star.$star.$star.$star.$star.'  <a href="'.$admin_url.'admin.php?page=rw_widgets">Click to connect</a> and beautifully showcase <b>social proof reviews</b> on your website.
		          <a class="rw-dismiss" data-until="7" href="" style="float: right">Dismiss</a></p>
		         </div>';
			}
		}
		
		//update_option('rw_notice_review_dismissed_until', '');
		
		$review_notice_dismissed = false;
		$dismissed_until = sanitize_text_field(get_option('rw_notice_review_dismissed_until')); 
		if(!empty($dismissed_until) && ($dismissed_until=="never" || $dismissed_until>$time))
			$review_notice_dismissed = true;
		
		if(!$review_notice_dismissed && $this->apiKey) {
			$posts = sanitize_text_field(get_option("rw_posts"));
			$widgets = sanitize_text_field(get_option("rw_widgets"));
			$trial = sanitize_text_field(get_option("rw_trial"));
			
			if($posts>=5 && $widgets>0 && $trial==0) {
				echo '<div id="rw-notice-review" class="notice notice-info is-dismissible">
		          <p><img src="'.esc_attr($this->plugin_url) . '/images/icon.png"> Hey, great job on connecting your social proof reviews to your website!<br/> 
		          Could you please do me a BIG favor and give it a '.$star.$star.$star.$star.$star.' 5-star rating on WordPress? Just to help us spread the word and boost our motivation.<br/>
		          - Thank you! Neran from Repuso</p>
		          <ul style="list-style:disc;margin-left: 40px;font-size: 14px;">
		          	  <li><a href="https://login.wordpress.org/?redirect_to=https%3A%2F%2Fwordpress.org%2Fsupport%2Fplugin%2Fsocial-testimonials-and-reviews-widget%2Freviews%2F" target="_blank">Ok, you deserve it</a></li>
			          <li><a class="rw-dismiss" data-until="7" href="">Nope, maybe later</a></li>
			          <li><a class="rw-dismiss" data-until="never" href="">Don\'t show this again</a></li>
		          </ul>
		         </div>';
			}
		}
	}

	function handle_nonce_error() {
		header( "Content-Type: application/json" );
	    echo json_encode(['success'=>false, 'error'=>'nonce']);
		exit();
	}

	function ajax_rw_get_login_url(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		$this->loginUrl = !empty($this->apiKey) ? $this->appUrl."#/login/".$this->apiKey : $this->appUrl;
		$response = ['loginUrl' => $this->loginUrl];
		
	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	
	    //Don't forget to always exit in the ajax function.
	    exit();
	
	}
	
	function ajax_rw_store_info(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		$posts = sanitize_text_field($_POST['posts']);
		$widgets = sanitize_text_field($_POST['widgets']);
		$on_free_trial = sanitize_text_field($_POST['on_free_trial']);
		
		update_option('rw_posts', (int)$posts);
		update_option('rw_widgets', (int)$widgets);
		update_option('rw_trial', (int)$on_free_trial);
		
	    header( "Content-Type: application/json" );
	    echo json_encode(['success'=>true]);
	    
	    exit();
	
	}

	function ajax_rw_store_login(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		if(!empty($_POST['key'])) {
			$apikey = sanitize_text_field($_POST['key']);
		    update_option('rw_apikey', $apikey);
		    $this->apiKey = $apikey;
		    
		    $response = ['success'=>true];
		} else {
			$response = ['success'=>false];
		}
		
	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	
	    //Don't forget to always exit in the ajax function.
	    exit();
	
	}
	
	function ajax_rw_store_subaccount(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		$account = sanitize_text_field($_POST['account']);
		update_option('rw_account', (int)$account);
		
	    header( "Content-Type: application/json" );

		$response = ['success'=>true];
	    echo json_encode($response);
	
	    //Don't forget to always exit in the ajax function.
	    exit();
	
	}
	
		
	function ajax_rw_logout(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		update_option('rw_apikey', "");
		$this->apiKey = false;
		$this->loginUrl = $this->appUrl;
		
		$response = ['success'=>true];
		
	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	
	    //Don't forget to always exit in the ajax function.
	    exit();
	
	}
	
	function ajax_rw_store_notice_dismiss(){
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}
		
		$days = sanitize_text_field($_POST['days']);
		$type = sanitize_text_field($_POST['type']);
		if(!empty($type)) {
			$days = (int)$days;
			if($days>0) {
				$time = time();
				$timestamp = strtotime('+'.$days.' days', $time);
			} else {
				$timestamp = "never";
			}
			
			$option_name = "rw_notice_{$type}_dismissed_until";
			update_option($option_name, $timestamp);
			
			$response = ['success'=>true, 'until'=>$timestamp];
		} else {
			$response = ['success'=>false];
		}
		
	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	
	    //Don't forget to always exit in the ajax function.
	    exit();
	
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

    function get_widget_code($type, $args) {
		ob_start();
        if (isset($args['id'])) {
            $id = $args['id'];
			$code = '';
            unset($args['id']);
            if(is_array($args)) {
	            foreach($args as $key => $value)
	            	$code.= ' '.esc_attr($key).'="'.esc_attr($value).'"';
            }
            ?>
            <!-- Begin widget code -->
            <rw-widget-<?php echo esc_attr($type)?> data-rw-<?php echo esc_attr($type)?>="<?php echo esc_attr($id) ?>"<?php echo $code; ?>></rw-widget-<?php echo esc_attr($type)?>>
            <script data-cfasync="false" crossorigin="anonymous" type="module" src="https://repuso.com/widgets/2.0/rw-widget-<?php echo esc_attr($type)?>.js"></script>
            <!-- End widget code -->
            <?php
        }
        return ob_get_clean();
    }
    
    function my_plugin_action_links( $links ) {

		$links = array_merge( array(
			'<a href="' . esc_url( admin_url( '/admin.php?page=rw_widgets' ) ) . '">' . __( 'Settings', 'textdomain' ) . '</a>'
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

	function hook() {

		if ( ! wp_verify_nonce( $_POST['nonce'], 'ajax-nonce' ) ) {
			$this->handle_nonce_error();
		}

		if(!empty($_POST['headers']['Authorization'])) {
			$key = !empty($_POST['key']) ? $_POST['key'] : $this->apiKey;
			$_POST['headers']['Authorization'] = "Basic ".base64_encode(":".$key);
		}
		
		$data =  [
			'method' => $_POST['method'],
			'headers' => $_POST['headers']
		];
		if(!empty($_POST['body'])) {
			$data['body'] = json_encode($_POST['body']);
		}
		$response = wp_remote_request($this->apiUrl.$_POST['path'], $data);

		if($_POST['return']!=='plain')
			header( "Content-Type: application/json" );

		$response = !empty($response['body']) ? $response['body'] : json_encode([]);
		echo $response;
		exit();
	}
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
add_action('wp_ajax_rw_store_notice_dismiss', array($da, 'ajax_rw_store_notice_dismiss'));
add_action('admin_enqueue_scripts', array($da, 'enqueue_modal_window_assets'));
add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($da, 'my_plugin_action_links') );
add_action( 'plugins_loaded', array( $da, 'get_user_info' ) );
add_action('admin_notices', array( $da, 'author_admin_notice' ));
add_action('wp_ajax_hook', array($da, 'hook'));
