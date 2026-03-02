/**
 * OneClick Branding JS
 *
 * Color pickers, media uploader, preview refresh.
 *
 * @package WooOneClick
 * @since 1.1.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initColorPickers();
        initMediaUploader();
        initPreviewRefresh();
    });

    function initColorPickers() {
        $('.oneclick-color-picker').wpColorPicker();
    }

    function initMediaUploader() {
        $('.oneclick-upload-logo').on('click', function(e) {
            e.preventDefault();

            var frame = wp.media({
                title: 'Select Logo',
                button: { text: 'Use as Logo' },
                multiple: false,
                library: { type: 'image' },
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#logo_url').val(attachment.url);
            });

            frame.open();
        });
    }

    function initPreviewRefresh() {
        $('.oneclick-refresh-preview').on('click', function() {
            var iframe = document.getElementById('oneclick-preview-frame');
            if (iframe) {
                iframe.src = iframe.src;
            }
        });
    }

})(jQuery);
