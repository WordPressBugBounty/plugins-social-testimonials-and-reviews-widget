(function ($)
{
    function setIframeSize()
    {
        if ($('#iframe').length > 0)
        {
            var offset = $('#iframe').offset().top;
            var height = $(window).height() - offset - 70;
            $('#iframe').css('height', height);
        }
    }
    
    function generateShortcode()
    {
        var grid_id = $('#grid_id').val().trim();
        var grid_tags = $('#grid_tags').val().trim();
        var grid_disable = $('#grid_disable').is(':checked');
        var addon = '';
        if(grid_id==='')
        {
            $('#generate-shortcode').val('');
            return true;
        }
        
        //data-disable-custom-posts="true" data-content-tags="sdfsdf, sdfsdf , sdfsdf"
        
        if(grid_disable)
        {
            addon+=' disable-custom-posts="true"';
        }
        if(grid_tags!=='')
        {
            addon+=' tags="'+grid_tags+'"';
        }
        
        var code = '[repuso_grid id="'+grid_id+'" '+addon+']';
        
        //var code = '<!-- Begin Repuso widget code --><div data-repuso-grid="'+grid_id+'" '+addon+'></div><script type="text/javascript" src="https://repuso.com/widgets/grid.js" async></script><!-- End Repuso widget code -->'
        $('#generate-shortcode').val(code);
    }

    $(window).resize(function ()
    {
        setIframeSize();
    });

    $(document).ready(function ()
    {
        
        $('#add-new-url').click(function(e)
        {
            e.preventDefault();
            var new_url = $('.new-url').first().clone();
            new_url.find('input').val('');
            new_url.find('select').val('show');
            $('.urls-wrapper').append(new_url);
        });
        
        $('#grid_tags,#grid_id').keyup(function(e)
        {
            generateShortcode();
        });
        
        $('#grid_disable').change(function()
        {
            generateShortcode();
        });
        //setIframeSize();

        $("#rw-notice-review").on("click", ".notice-dismiss", function (e, el) {
          e.preventDefault();
          $(this).data("until", 30);
          $("#rw-notice-review .rw-dismiss").trigger("click");
        });
        
        $('#rw-notice-review .rw-dismiss').click(function(e) {
	        e.preventDefault();
	        
	        $('#rw-notice-review').hide();
	        
	        let days = $(this).data("until");
	        
	        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
              action: "rw_store_notice_dismiss",
              type: "review",
              days: days,
              nonce: ajax_var.nonce,
            },
            success: function (response, textStatus, jqXHR) {
              console.log(response);
            },
            error: function (jqXHR, textStatus, errorThrown) {
              console.log(jqXHR.responseJSON);
            },
          });			
        });
        
        $('#rw-notice-settings .rw-dismiss').click(function(e) {
		    e.preventDefault();
	        
	        $('#rw-notice-settings .notice-dismiss').trigger( "click" );
	        
	        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
              action: "rw_store_notice_dismiss",
              type: "settings",
              days: 7,
              nonce: ajax_var.nonce,
            },
            success: function (response, textStatus, jqXHR) {
              console.log(response);
            },
            error: function (jqXHR, textStatus, errorThrown) {
              console.log(jqXHR.responseJSON);
            },
          });			
        });
    });
})(jQuery);