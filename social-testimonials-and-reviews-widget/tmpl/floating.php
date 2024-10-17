<?php $nonce_url = wp_nonce_url( "./admin.php?page=pagewide_widget", 'floating-nonce' ); ?>
<div id="rw-wrapper">
	<h1>Floating Widget</h1>
	<form action="<?php echo $nonce_url; ?>" method="post">
		<p>
			<h2>Copy the Floating Widget <u>shortcode</u> and paste here:</h2>
			<textarea cols="70" rows="5" id="repuso_js_code" name="repuso_js_code"><?php echo esc_textarea(stripslashes(get_option('repuso_js_code'))) ?></textarea>
		</p>
		
		<p>
			<h3>Show on following content types:</h3>
			<div class="rep-row">
				<?php foreach ($this->pages_types as $pt): ?>
					<div class="col-xs-3">
						<label>
							<input type="hidden" name="repuso_page_type_<?php echo esc_attr(sanitize_title($pt)) ?>" value="0" />
							<input <?php if (get_option('repuso_page_type_' . sanitize_title($pt)) === '1'): ?>checked="checked"<?php endif ?> type="checkbox" name="repuso_page_type_<?php echo esc_attr(sanitize_title($pt)) ?>" value="1" /> <?php echo wp_kses($pt, []) ?>
						</label>
					</div>
				<?php endforeach ?>
			</div>
		</p>

		<p>
			<h3>Show/hide under following urls:</h3>
			<i>Use * for wildcard. Erase URL field to remove</i>
			<?php /**/ ?>
			<div class="urls-wrapper">
				<?php
				$url_itself = sanitize_text_field(get_option('url_itself'));
			
				$url_type = sanitize_text_field(get_option('url_type'));
				if(is_array($url_itself) && !empty($url_itself))
				{
					foreach($url_itself as $key=>$value)
					{
						?>
						<div class="new-url">
						
								<select name="url_type[]">
									<option <?php if($url_type[$key]=='show'):?>selected="selected"<?php endif ?> value="show">Show</option>
									<option <?php if($url_type[$key]=='hide'):?>selected="selected"<?php endif ?>  value="hide">Hide</option>
								</select>
								<input type="text" name="url_itself[]" value="<?php echo esc_attr($url_itself[$key]) ?>" placeholder="Page url, example /the-post-*" />
							
							<div class="clearfix"></div>
						</div>
						<?php
					}
				}
				?>
				<div class="new-url">
				
						<select name="url_type[]">
							<option value="show">Show</option>
							<option value="hide">Hide</option>
						</select>
						<input type="text" name="url_itself[]" value="" placeholder="Page url, example /post-id-*" />
					
					<div class="clearfix"></div>
				</div>
			</div>
			<input type="button" id="add-new-url" class="button" value="Add one more" />
		</p>

		<p>
			<h3>Hide on following pages:</h3>
			<div class="rep-row">
				<?php foreach ($this->pages as $pt): ?>
					<div class="col-xs-3">
						<label>
							<input type="hidden" name="repuso_page_hide_<?php echo esc_attr($pt->ID) ?>" value="0" />
							<input type="checkbox"  <?php if (get_option('repuso_page_hide_' . esc_attr($pt->ID)) == '1'): ?>checked="checked"<?php endif ?>  name="repuso_page_hide_<?php echo esc_attr($pt->ID) ?>"  value="1"  /> <?php echo wp_kses($pt->post_title, []) ?>
						</label>
					</div>
				<?php endforeach ?>
			</div>
		</p>
		
		<p><input type="submit" value="Save" name="repulso_save" class="button button-primary" /></p>
	</form>
</div>