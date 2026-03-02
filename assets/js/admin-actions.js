/**
 * OneClick Admin JS
 *
 * Handles Select2 product/category pickers and form interactions.
 *
 * @package WooOneClick
 * @since 1.1.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initProductPickers();
        initCategoryPickers();
        initActionTypeToggle();
    });

    /**
     * Initialize Select2 product pickers
     * Uses WooCommerce's built-in AJAX search
     */
    function initProductPickers() {
        $('.oneclick-product-select').each(function() {
            if ($(this).hasClass('select2-hidden-accessible')) {
                return; // Already initialized
            }

            $(this).select2({
                ajax: {
                    url: oneclick_admin.ajax_url,
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'woocommerce_json_search_products',
                            security: oneclick_admin.nonce,
                        };
                    },
                    processResults: function(data) {
                        var results = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                results.push({ id: id, text: text });
                            });
                        }
                        return { results: results };
                    },
                    cache: true,
                },
                minimumInputLength: 2,
                placeholder: $(this).data('placeholder') || 'Search products...',
                allowClear: true,
                width: '400px',
            });
        });
    }

    /**
     * Initialize Select2 category pickers
     * Uses WooCommerce's built-in category search
     */
    function initCategoryPickers() {
        $('.oneclick-category-select').each(function() {
            if ($(this).hasClass('select2-hidden-accessible')) {
                return;
            }

            $(this).select2({
                ajax: {
                    url: oneclick_admin.ajax_url,
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'oneclick_search_categories',
                            security: oneclick_admin.category_nonce,
                        };
                    },
                    processResults: function(data) {
                        var results = [];
                        if (data) {
                            $.each(data, function(id, name) {
                                results.push({ id: id, text: name });
                            });
                        }
                        return { results: results };
                    },
                    cache: true,
                },
                minimumInputLength: 2,
                placeholder: $(this).data('placeholder') || 'Search categories...',
                allowClear: true,
                width: '400px',
            });
        });
    }

    /**
     * Toggle periodic interval field based on action type
     */
    function initActionTypeToggle() {
        var $typeSelect = $('#action_type');
        if (!$typeSelect.length) {
            return;
        }

        $typeSelect.on('change', function() {
            if ($(this).val() === 'periodic') {
                $('.periodic-field').show();
            } else {
                $('.periodic-field').hide();
            }
        });
    }

})(jQuery);
