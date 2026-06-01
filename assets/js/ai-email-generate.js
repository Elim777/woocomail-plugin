/**
 * AI Email Generate — collects reaction form data, calls AJAX, fills subject + body.
 */
(function ($) {
    'use strict';

    $(function () {
        var $btn     = $('#oneclick-ai-generate-btn');
        var $spinner = $('#oneclick-ai-spinner');

        if (!$btn.length) return;

        $btn.on('click', function () {
            if (!oneclickAiEmail.disclosureAcknowledged) {
                alert(oneclickAiEmail.i18n.disclosureRequired);
                return;
            }

            // Collect form data
            var reactionName = $('#reaction_name').val() || '';

            // Select2 values (arrays of IDs)
            var productIds  = $('#offer_products').val() || [];
            var categoryIds = $('#offer_categories').val() || [];

            if (!productIds.length && !categoryIds.length) {
                alert(oneclickAiEmail.i18n.noProducts);
                return;
            }

            var discountPercent = parseFloat($('#discount_percent').val()) || 0;
            var discountType    = $('select[name="discount_type"]').val() || '%';
            var instruction     = $('#oneclick-ai-instruction').val() || '';

            // Loading state
            $btn.prop('disabled', true).text(oneclickAiEmail.i18n.generating);
            $spinner.addClass('is-active');

            $.ajax({
                url:  oneclickAiEmail.ajaxUrl,
                type: 'POST',
                data: {
                    action:           'oneclick_ai_generate_email',
                    nonce:            oneclickAiEmail.nonce,
                    reaction_name:    reactionName,
                    product_ids:      productIds,
                    category_ids:     categoryIds,
                    discount_percent: discountPercent,
                    discount_type:    discountType,
                    user_instruction: instruction
                },
                success: function (res) {
                    if (res.success && res.data) {
                        // Fill email subject
                        $('#email_subject').val(res.data.email_subject);

                        // Fill email body (TinyMCE or textarea fallback)
                        var editor = typeof tinyMCE !== 'undefined' ? tinyMCE.get('email_body') : null;
                        if (editor && !editor.isHidden()) {
                            editor.setContent(res.data.email_body);
                        } else {
                            $('#email_body').val(res.data.email_body);
                        }
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : oneclickAiEmail.i18n.error;
                        alert(msg);
                    }
                },
                error: function () {
                    alert(oneclickAiEmail.i18n.error);
                },
                complete: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-admin-generic" style="vertical-align:middle;margin-top:-2px;"></span> ' +
                        oneclickAiEmail.i18n.generate
                    );
                    $spinner.removeClass('is-active');
                }
            });
        });
    });
})(jQuery);
