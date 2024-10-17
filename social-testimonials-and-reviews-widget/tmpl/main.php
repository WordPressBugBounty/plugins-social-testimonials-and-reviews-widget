<script>
jQuery(document).ready(function($){
	
	var apiKey = "<?php echo esc_js($this->apiKey); ?>";
	var appUrl = "<?php echo esc_js($this->appUrl); ?>";
	var account = false;
	var subAccount = <?php echo (int)$this->websiteId?>;
	var section = "<?php echo esc_js($this->currentSection)?>";
	
	function showLoader(parent) {
		$(parent+" .rw-loading").show();
	}
	
	function hideLoader() {
		$(".rw-loading").hide();
	}
	
	$('.open-dashboard').click(function(event) {
	    event.preventDefault();
		$.ajax({
			url : ajax_var.url, 
			type: "POST", 
			data : {action: "rw_get_login_url", nonce: ajax_var.nonce}, 
			success: function(response, textStatus, jqXHR) {
				window.open(response.loginUrl);
			},
			error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR.responseJSON);
			}
		});
	});
	
	if(apiKey.length) {
		//showLogged();
		showLoader();
		getAccountInfo();
		hideLoader();
	} else {
		hideLogged();
	}
	
    $( "#rw-login form" ).submit(function( event ) {
	    event.preventDefault();
	    
	    showLoader("#rw-login");
	    hideError();
	    
	    var data = {
		  email: $(this).find("#email").val(),
		  password: $(this).find("#password").val()
	    };
	    
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "login",
				method: 'POST',
				body: data,
				headers: {
					'Content-Type': 'application/json'
				}
			},
		    success: function(response, textStatus, jqXHR) {
				console.log("login");

		    	if(response.apikey) {
			    	apiKey = response.apikey;
			    	
			    	$.ajax({
					    url : ajaxurl, 
					    type: "POST", 
					    data : {action: "rw_store_login", key: apiKey, nonce: ajax_var.nonce}, 
					  	success: function(response, textStatus, jqXHR) {
					    	//console.log(response);
							getAccountInfo(apiKey);
			    			hideLoader();
					    },
					    error: function (jqXHR, textStatus, errorThrown) {
							showError("Problem logging in 2");
							console.log(jqXHR);
					    }
					});
		    	} else {
					console.log(response);
			    	showError("Problem logging in");
		    	}
		    	
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
			    showError("Problem logging in");
				console.log(jqXHR);
		    }
		});
    });
    
    $( "#rw-register form" ).submit(function( event ) {
	    event.preventDefault();
	    showLoader("#rw-register");
	    hideError();
	    
	    var data = {
		  email: $(this).find("#remail").val(),
		  password: $(this).find("#rpassword").val(),
		  vanity_url: $(this).find("#vanity").val(),
		  source: "wordpress-<?php echo esc_js(wp_get_theme());?>"
	    };
	    
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "logregisterin",
				method: 'POST',
				body: data,
				headers: {
					'Content-Type': 'application/json'
				}
			},
		    success: function(response, textStatus, jqXHR) {
		    	
		    	if (response.api_key) {
			    	apiKey = response.api_key;
					
			    	$.ajax({
					    url : ajaxurl, 
					    type: "POST", 
					    data : {action: "rw_store_login", key: apiKey, nonce: ajax_var.nonce}, 
					  	success: function(response, textStatus, jqXHR) {
					    	getAccountInfo(apiKey);
			    			hideLoader();
					    },
					    error: function (jqXHR, textStatus, errorThrown) {
							console.log(jqXHR);
							showError("Problem getting account information");
							hideLoader();
						}
					});
				} else {
					showError("Problem resigtering");
				}
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				response = jqXHR.responseJSON;
				if(response.msg) {
					showError(response.msg);
				} else if(response.exists) {
					showError("Information already exist, please change");
				} else {
					showError("Problem resigtering");
				}
		    }
		});
    });
    
    function showError(err) {
	    hideLoader();
	    $('#rw-error p').html(err);
	    $('#rw-error').show();
    }
    
    function hideError(err) {
	    $('#rw-error p').html('');
	    $('#rw-error').hide();
    }
    
    $('.show-register').click(function(event) {
	    event.preventDefault();
	    $('#rw-login').hide();
	    $('#rw-register').show();
	});
	
	$('.show-login').click(function(event) {
	    event.preventDefault();
	    $('#rw-login').show();
	    $('#rw-register').hide();
	});
    
    $(".rw-logout").click(function(event) {
	    event.preventDefault();
		$.ajax({
		    url : ajaxurl, 
		    type: "POST", 
		    data : {action: "rw_logout", nonce: ajax_var.nonce}, 
		  	//contentType: "application/json",
		    success: function(response, textStatus, jqXHR) {
		    	apiKey = "";
		    	hideLogged();
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR.responseJSON);
		    }
		});
	});
    
    function getAccountInfo(key = "") {
		let data = {
			action: 'hook',
			nonce: ajax_var.nonce,
			path: "account/info",
			method: 'GET',
			body: {},
			headers: {
				'Authorization': "Yes"
			}
		};

		if(key) {
			data.key = key;
		}

	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: data,
		    success: function(response, textStatus, jqXHR) {
			    console.log("got account info");
		    	account = response;
		    	
		    	let posts = account.approved_posts.usage ? account.approved_posts.usage : 0;
		    	let widgets = account.widgets.usage ? account.widgets.usage : 0;
		    	let on_free_trial = account.on_free_trial ? 1 : 0;
		    	
				
		    	$.ajax({
				    url : ajaxurl, 
				    type: "POST", 
				    data : {
					    action: "rw_store_info", 
					    posts: posts,
					    widgets: widgets,
					    on_free_trial: on_free_trial,
						nonce: ajax_var.nonce,
					}, 
				  	success: function(response, textStatus, jqXHR) {
					  	
				    },
				    error: function (jqXHR, textStatus, errorThrown) {
					    console.log(jqXHR);
				    }
				});				
		    	
		    	$("#rw-subaccounts").find("select#accounts").html('');
		    	
		    	var uw = account.user_websites;
		    	var is_admin = account.is_admin;
		    	if(account.websites.length > 0) {
			    	if (is_admin || uw.includes(0)) {
				    	let name = account.company_name ? account.company_name : 'Main';
				    	$("#rw-subaccounts").find("select#accounts").append("<option value='0'>"+name);
				    }
			    	
			    	$.each(account.websites, function( index, account ) {				    	
				    	if (is_admin || uw.includes(account.id)) {
					    	let selected = account.id == subAccount ? "selected" : "";
							$("#rw-subaccounts").find("select#accounts").append("<option "+selected+" value='"+account.id+"'>"+account.name+"</option>");
						}						
					});
					
					$("#rw-subaccounts #doaction").click(function() {
						subAccount = $("#rw-subaccounts select#accounts").val();
						initAccount(subAccount);
						
						$.ajax({
						    url : ajaxurl, 
						    type: "POST", 
						    data : {action: "rw_store_subaccount", account: subAccount, nonce: ajax_var.nonce}, 
						  	success: function(response, textStatus, jqXHR) {
								console.log(response);
						    },
						    error: function (jqXHR, textStatus, errorThrown) {
							    console.log('error', jqXHR.responseJSON);
						    }
						});
						
					});
					
					$("#rw-subaccounts").show();
					
				} else {
					$("#rw-subaccounts").hide();
				}
				subAccount = $("#rw-subaccounts select#accounts").val();
				initAccount(subAccount);
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR);
			    showError("Problem getting account information");
				hideLogged();
		    }
		});
    }
    
    function initAccount() {
	    hideError();
	    $( ".rw-logged" ).show();
	    $( ".rw-not-logged" ).hide();
	    if(section=="channels") {
		    getAllChannels();
			getChannels(subAccount);
	    } else if (section=="widgets") {
		    getWidgets(subAccount);
	    } else if (section=="reviews") {
		    $("#rw-reviews .subsubsub a").removeClass("current");
		    $("#rw-reviews .subsubsub a:first").addClass("current");
		    getReviews(subAccount);
	    }	
    }
    
    function hideLogged() {
	    $( ".rw-logged" ).hide();
	    $( ".rw-not-logged" ).show();
    }
    
    function getAllChannels() {
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "channels/all",
				method: 'POST',
				body: {},
				headers: {
					'Authorization': "Yes"
				}
			},
		    success: function(response, textStatus, jqXHR) {
		    	
		    	$("#rw-channels #all").html('');
		    	$.each(response, function( index, channel ) {
			    	if(channel.key!==15) {
				    	let image  = '<img style="margin-right:5px" title="'+channel.label+'" height="20" src="'+channel.logo+'">';
						$("#rw-channels #all").append(image);
					}
			    });
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR.responseJSON);
		    }
		});
    }
    
    function getChannels(subAccount) {
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "channels?website="+subAccount,
				method: 'GET',
				body: {},
				headers: {
					'Authorization': "Yes"
				}
			},
		    success: function(response, textStatus, jqXHR) {
			    
		    	$("#rw-channels").find("table tbody").html('');
		    	if(response.count > 0 && response.items) {
			    	$.each(response.items, function( index, channel ) { 
				    	//if(channel.key!==15) {
					    	let score = channel.official_score >0 ? '<span class="dashicons dashicons-star-filled"></span>' + channel.official_score + ' (' + channel.official_num_reviews + ')' : '';
					    	let row  = '<tr><td><img height="35" src="'+channel.logo+'"></td><td>' + channel.name + '</td><td>' + score + '</td></tr>';
							$("#rw-channels").find("table tbody").append(row);
						//}
				    });
		    	} else {
			    	$("#rw-channels").find("table tbody").html('<tr><td colspan="2">No channels yet, connect your first one</td></tr>');
		    	}
		    	$("#rw-channels").show();
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR.responseJSON);
		    }
		});
    }
    
    function getWidgets(subAccount) {
		$.ajax({
		    url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "widgets?website="+subAccount,
				method: 'GET',
				//return: 'plain',
				body: {},
				headers: {
					'Authorization': "Yes"
				}
			},
			success: function(response, textStatus, jqXHR) {
			    //console.log('response', response);
		    	$("#rw-widgets").find("table tbody").html('');
		    	
		    	if(response.length > 0) {
			    	$.each(response, function( index, widget ) {
						let shortcode = widget.type!== "email1" ? '[rw_'+widget.type+' id="'+widget.id+'"]' : '';
				    	let row  = '<tr><td><img width="81" src="https://app.thereviewsplace.com/images/icon-'+widget.type+'.png"></td><td>' + widget.description + '</td><td>' + shortcode + '</td><td><a href="#TB_inline?height=600%&width=900&inlineId=rw_preview_wrapper" class="preview-widget-code thickbox" data-widget-id="'+widget.id+'"><nobr>Full code</nobr></a>&nbsp;&nbsp;|&nbsp;&nbsp;<a href="#TB_inline?height=600%&width=900&inlineId=rw_preview_wrapper" class="preview-widget thickbox" data-widget-id="'+widget.id+'">Preview</a></td></tr>';
						$("#rw-widgets").find("table tbody").append(row);
				    });
				} else {
					
					$("#rw-widgets").find("table tbody").html('<tr><td colspan="4">No widgets yet, create your first one</td></tr>');
					//$("#rw-widgets").find("table").hide();
				}
			    
			    $("#rw-widgets .preview-widget").click(function() {
					previewWidget($(this).data('widget-id'), false);
				});
				
				$("#rw-widgets .preview-widget-code").click(function() {
					previewWidget($(this).data('widget-id'), true);
				});
				
				$("#rw-widgets").show();
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log('error', errorThrown);
		    }
		});
    }
    
    function previewWidget(id, showCode) {
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "widgets/"+id+"/html",
				method: 'GET',
				body: {},
				headers: {
					'Authorization': "Yes"
				}
			},
		    success: function(response, textStatus, jqXHR) {
			    if(showCode)
		    		response.html = "<textarea style='width:100%;height:100%'>"+response.html+"</textarea>";
		    	var contents = response.html;
		    	
		    	var iframe = document.getElementById('rw_preview');
                var doc = iframe.contentDocument ? iframe.contentDocument : (iframe.contentWindow ? iframe.contentWindow.document : iframe.document);
                doc.open();
                doc.write(contents);
                doc.close();
                
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR.responseJSON);
		    }
		});
    }
    
    function getReviews(subAccount, path="/inbox") {
	    $.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "posts"+path+"?limit=100&website="+subAccount,
				method: 'GET',
				body: {},
				headers: {
					'Authorization': "Yes"
				}
			},
		    success: function(response, textStatus, jqXHR) {
			    
		    	$("#rw-reviews").find("table tbody").html('');
		    	if(response.count > 0 && response.items) {
			    	$.each(response.items, function( index, post ) { 
				    	//if(post.type_key!==15) {
					    	let date = new Date(post.posted_on);
					    	date = ((date.getMonth() > 8) ? (date.getMonth() + 1) : ('0' + (date.getMonth() + 1))) + '/' + ((date.getDate() > 9) ? date.getDate() : ('0' + date.getDate())) + '/' + date.getFullYear();
					    	let source = 'https://widgets.thereviewsplace.com/2.0/images/60x60/logo-'+post.type+'.png';
					    	let score = post.rating_scale >0 ? '<span class="dashicons dashicons-star-filled"></span>' + post.rating_value + '/' + post.rating_scale : '';
					    	let actions = '<span data-post-id="'+post.id+'" class="dashicons dashicons-yes-alt status'+post.status+'"></span>&nbsp;&nbsp;<span data-post-id="'+post.id+'" class="dashicons dashicons-remove status'+post.status+'"></span>';
					    	let row  = '<tr><td style="vertical-align:top"><img height="35" src="'+source+'"></td><td><b>' + post.from_name + '</b><br/>' + post.text + '</td><td><img height="35" src="' + post.from_image + '"></td><td>' + score + '</td><td>' + date + '</td><td>' + actions + '</td></tr>';
							$("#rw-reviews").find("table tbody").append(row);
						//}
				    });
		    	} else {
			    	$("#rw-reviews").find("table tbody").html('<tr><td colspan="2">No reviews yet</td></tr>');
		    	}
		    	$("#rw-reviews").show();
		    	
		    	$("#rw-reviews .dashicons-yes-alt").click(function(event) {
			    	event.preventDefault();
			    	updateReviewStatus($(this).data('post-id'), 1);
			    	$(this).removeClass("status0");
			    	$(this).removeClass("status2");
			    	$(this).addClass("status1");
			    	
			    	$(this).parent().find('.dashicons-remove').removeClass("status0");
			    	$(this).parent().find('.dashicons-remove').removeClass("status2");
			    });
			    
			    $("#rw-reviews .dashicons-remove").click(function(event) {
			    	event.preventDefault();
			    	updateReviewStatus($(this).data('post-id'), 2);
			    	$(this).removeClass("status0");
			    	$(this).removeClass("status1");
			    	$(this).addClass("status2");
			    	
			    	$(this).parent().find('.dashicons-yes-alt').removeClass("status0");
			    	$(this).parent().find('.dashicons-yes-alt').removeClass("status1");
			    });
		    	
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(jqXHR);
		    }
		});
    }
    
    function updateReviewStatus(id, status) {
	    event.preventDefault();
		$.ajax({
			url: ajax_var.url,
		    type: "POST", 
			data: {
				action: 'hook',
				nonce: ajax_var.nonce,
				path: "posts/"+id,
				method: 'PUT',
				body: {status: status},
				headers: {
					'Content-Type': 'application/json',
					'Authorization': "Yes"
				}
			},
		    success: function(response, textStatus, jqXHR) {
				//console.log(response);
		    },
		    error: function (jqXHR, textStatus, errorThrown) {
				console.log(errorThrown);
		    }
		});
    }
    
    
    
    $("#rw-reviews .subsubsub a").click(function(event) {
    	event.preventDefault();
    	getReviews(subAccount, $(this).data('path'));
    	$("#rw-reviews .subsubsub a").removeClass("current");
    	$(this).addClass("current");
    	
    });
});
</script>

<div id="rw-wrapper">

	<ul class="subsubsub" style="float:right">
		<li class="rw-logged"><a class="rw-logout" href=''>Disconnect</a> | </li>
		<li><a class="open-dashboard" href="">Open full dashboard <span class="dashicons dashicons-external"></span></a> | </li>
		<li><a href='https://repuso.com/help?utm_source=plugin&utm_medium=wordpress&utm_campaign=<?php echo esc_attr(wp_get_theme());?>' target='_blank'>Video guides <span class="dashicons dashicons-external"></span></a> | </li>
		<li><a href='https://repuso.com?utm_source=plugin&utm_medium=wordpress&utm_campaign=<?php echo esc_attr(wp_get_theme());?>' target='_blank'>repuso.com <span class="dashicons dashicons-external"></span></a></li>
	</ul>

	<h1>Social proof reviews by Repuso</h1>

	<div id="rw-error" class="error notice" style="display:none">
		<p></p>
	</div>

	<div style="display:none" class="rw-not-logged">
		<div class="login" id="rw-login" style="display: none">
			<form action="" method="post">
				<div class="rep-row">
					<h2>Login</h2>
					<div class="col-xs-12">
						<label>Email address</label>
						<input type="email" id="email" value="<?php echo esc_attr($this->current_user->user_email)?>" placeholder="" />
					</div>
					<div class="col-xs-6">
						<label>Password</label>
						<input type="password" id="password" value="" placeholder="password" />
					</div>
					<div class="col-xs-6">
						<span>No account? <a href='' class='show-register'>Register here</a></span>
						
						<span class="rw-loading" style="display: none"><img src="<?php echo esc_attr(get_admin_url())."images/spinner.gif";?>"></span>
						<input type="submit" value="Login" id="login_submit" class="button button-primary" />
					</div>
				</div>
			</form>
		</div>
		
		<div class="login" id="rw-register">
			<form action="" method="post">
				<div class="rep-row">
					<h2>Start by creating an account</h2>
					<div class="col-xs-12">
						<label>Email address</label>
						<input type="email" id="remail" value="<?php echo esc_attr($this->current_user->user_email)?>" placeholder="" />
					</div>
					<div class="col-xs-6">
						<label>Password</label>
						<input type="password" id="rpassword" value="" placeholder="password" />
					</div>
					<div class="col-xs-6">
						<label>Website name</label>
						<input type="text" id="vanity" value="<?php echo esc_attr($this->hostname)?>" placeholder="" />
					</div>
					<div class="col-xs-6">
						<span>Have an account? <a href='' class='show-login'>Login here</a></span>
						
						<span class="rw-loading" style="display: none"><img src="<?php echo get_admin_url()."images/spinner.gif";?>"></span>
						<input type="submit" value="Register" id="register_submit" class="button button-primary" />
					</div>
				</div>
			</form>
		</div>
		
		<br class="clear"><br class="clear">
		
		<h1>Example of review widgets that you can install on your website:</h1>
		<h3>These are real reviews about Repuso</h3>
		
		<!-- Begin widget code -->
		<div data-rw-flash="19859"></div>
		<script>var script = document.createElement("script");script.type = "module";script.src = "https://widgets.thereviewsplace.com/2.0/rw-widget-flash.js";document.getElementsByTagName("head")[0].appendChild(script);</script>
		<!-- End widget code -->
		
		<!-- Begin widget code -->
		<div data-rw-masonry="19857" data-disable-custom-posts="true"></div>
		<script>var script = document.createElement("script");script.type = "module";script.src = "https://widgets.thereviewsplace.com/2.0/rw-widget-masonry.js";document.getElementsByTagName("head")[0].appendChild(script);</script>
		<!-- End widget code -->
		
		<br class="clear">
	</div>

	<div style="display:none" class="rw-logged">

		<div style="display:none" id="rw-subaccounts" class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label for="accounts" class="screen-reader-text">Select account</label>
				<select name="action" id="accounts">
					<option value="-1">Select account</option>
				</select>
				<input type="submit" id="doaction" class="button action" value="Select account">
			</div>		
			<br class="clear">
		</div>
		
		
		<div style="display:none" id="rw-channels">
			<h2>Social proof channels</h2>
			<table class="wp-list-table widefat fixed striped">
			<thead>
				<th class="manage-column " style="width: 50px;">Type</th>
				<th class="manage-column column-title column-primary">Name</th>
				<th class="manage-column column-title">Rating</th>
			</thead>
			<tbody></tbody>
			</table>
			
			<br class="clear">
			
			<div id="all"></div>
			<a href="" class="open-dashboard button action">Connect a channel <span class="dashicons dashicons-external"></span></a>
			
		</div>
		
		<div style="display:none" id="rw-widgets">
			<h2>Widgets</h2>
			<table class="wp-list-table widefat fixed striped">
			<thead>
				<th class="manage-column column-date">Type</th>
				<th class="manage-column column-title column-primary">Name</th>
				<th class="manage-column column-title">Shortcode</th>
				<th class="manage-column column-title"></th>
			</thead>
			<tbody></tbody>
			</table>
			
			<br class="clear">
			
			<a href="" class="open-dashboard button action">Create new widget <span class="dashicons dashicons-external"></span></a>
			
			<div id="rw_preview_wrapper" style="display: none">
				<iframe id="rw_preview" width="100%" height="100%" frameborder="0" style="vertical-align: text-bottom; position: relative; margin: 0px; overflow: hidden; background-color: transparent;"></iframe>
			</div>
		</div>
		
		<div style="display:none" id="rw-reviews">
			<h2>Reviews</h2>
			
			<ul class="subsubsub" style="">
				<li><a data-path="/inbox" class="current" href="">Inbox</a> | </li>
				<li><a data-path="" class="" href="">Approved</a> | </li>
				<li><a data-path="/all" href="">All</a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
			<thead>
				<th class="manage-column " style="width: 50px;">Source</th>
				<th class="manage-column column-title column-primary">Text</th>
				<th class="manage-column column-date" style="width: 50px;"></th>
				<th class="manage-column column-date">Rating</th>
				<th class="manage-column column-date">Date</th>
				<th class="manage-column column-date">Actions</th>
			</thead>
			<tbody></tbody>
			</table>
			
			<br class="clear">
			
			<div id="all"></div>
			<a href="" class="open-dashboard button action">Manage all reviews <span class="dashicons dashicons-external"></span></a>
			
		</div>
		
	</div>
</div>