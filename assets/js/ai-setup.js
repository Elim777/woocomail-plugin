/**
 * OneClick AI Setup
 *
 * Handles AI suggestion generation, display, and apply.
 *
 * @package WooOneClick
 * @since 1.1.0
 */
(function ($) {
    'use strict';

    var suggestions = {};

    // Generate button
    $('#oneclick-ai-generate-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#oneclick-ai-loading').show();
        $('#oneclick-ai-results').hide();
        showStatus('', '');

        $.ajax({
            url: oneclickAI.ajaxUrl,
            type: 'POST',
            data: {
                action: 'oneclick_ai_generate',
                nonce: oneclickAI.nonce
            },
            success: function (response) {
                $btn.prop('disabled', false);
                $('#oneclick-ai-loading').hide();

                if (!response.success) {
                    showStatus(response.data.message || oneclickAI.i18n.error, 'error');
                    return;
                }

                suggestions = response.data;
                renderSuggestions(suggestions);
                $('#oneclick-ai-results').show();
            },
            error: function () {
                $btn.prop('disabled', false);
                $('#oneclick-ai-loading').hide();
                showStatus(oneclickAI.i18n.error, 'error');
            }
        });
    });

    // Apply button
    $('#oneclick-ai-apply-btn').on('click', function () {
        if (!confirm(oneclickAI.i18n.confirmApply)) {
            return;
        }

        var selected = getSelectedSuggestions();
        if (!selected.actions.length && !selected.reactions.length && !selected.rules.length) {
            showStatus(oneclickAI.i18n.noSuggestions, 'warning');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(oneclickAI.i18n.applying);

        $.ajax({
            url: oneclickAI.ajaxUrl,
            type: 'POST',
            data: {
                action: 'oneclick_ai_apply',
                nonce: oneclickAI.nonce,
                suggestions: JSON.stringify(selected)
            },
            success: function (response) {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Apply Selected Suggestions');

                if (!response.success) {
                    showStatus(response.data.message || oneclickAI.i18n.error, 'error');
                    return;
                }

                var msg = oneclickAI.i18n.applied +
                    ' (' + (response.data.created_actions || 0) + ' triggers, ' +
                    (response.data.created_reactions || 0) + ' actions, ' +
                    (response.data.created_rules || 0) + ' scenarios)';
                showStatus(msg, 'success');
            },
            error: function () {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Apply Selected Suggestions');
                showStatus(oneclickAI.i18n.error, 'error');
            }
        });
    });

    // Select all checkboxes
    $(document).on('change', '.oneclick-ai-select-all', function () {
        var target = $(this).data('target');
        $('#oneclick-ai-' + target + '-table tbody input[type="checkbox"]').prop('checked', $(this).prop('checked'));
    });

    // Remove row button
    $(document).on('click', '.oneclick-ai-remove-row', function () {
        $(this).closest('tr').remove();
    });

    /**
     * Render suggestions in tables
     */
    function renderSuggestions(data) {
        // Actions
        var $actionsBody = $('#oneclick-ai-actions-table tbody').empty();
        (data.actions || []).forEach(function (action, i) {
            var products = (action.trigger_product_names || action.trigger_products || []).join(', ');
            $actionsBody.append(
                '<tr data-index="' + i + '">' +
                '<th class="check-column"><input type="checkbox" checked></th>' +
                '<td>' + escHtml(action.name || '') + '</td>' +
                '<td>' + escHtml(action.type || 'purchase') + '</td>' +
                '<td>' + escHtml(products) + '</td>' +
                '</tr>'
            );
        });

        // Reactions
        var $reactionsBody = $('#oneclick-ai-reactions-table tbody').empty();
        (data.reactions || []).forEach(function (reaction, i) {
            $reactionsBody.append(
                '<tr data-index="' + i + '">' +
                '<th class="check-column"><input type="checkbox" checked></th>' +
                '<td>' + escHtml(reaction.name || '') + '</td>' +
                '<td>' + (reaction.discount_percent || 0) + '%</td>' +
                '<td>' + (reaction.delay_minutes || 0) + ' min</td>' +
                '<td>' + escHtml(reaction.email_subject || '') + '</td>' +
                '</tr>'
            );
        });

        // Rules
        var $rulesBody = $('#oneclick-ai-rules-table tbody').empty();
        (data.rules || []).forEach(function (rule, i) {
            var triggerName = rule.action_name
                || (data.actions[rule.action_index] && data.actions[rule.action_index].name)
                || 'Trigger ' + (rule.action_index + 1);
            var actionName = rule.reaction_name
                || (data.reactions[rule.reaction_index] && data.reactions[rule.reaction_index].name)
                || 'Action ' + (rule.reaction_index + 1);
            $rulesBody.append(
                '<tr data-index="' + i + '">' +
                '<th class="check-column"><input type="checkbox" checked></th>' +
                '<td>' + escHtml(triggerName) + '</td>' +
                '<td>' + escHtml(actionName) + '</td>' +
                '</tr>'
            );
        });

        if (!data.actions.length && !data.reactions.length && !data.rules.length) {
            showStatus(oneclickAI.i18n.noSuggestions, 'warning');
        }
    }

    /**
     * Get selected suggestions based on checked checkboxes
     */
    function getSelectedSuggestions() {
        var selected = { actions: [], reactions: [], rules: [] };

        $('#oneclick-ai-actions-table tbody tr').each(function () {
            if ($(this).find('input[type="checkbox"]').prop('checked')) {
                var idx = $(this).data('index');
                if (suggestions.actions && suggestions.actions[idx]) {
                    selected.actions.push(suggestions.actions[idx]);
                }
            }
        });

        $('#oneclick-ai-reactions-table tbody tr').each(function () {
            if ($(this).find('input[type="checkbox"]').prop('checked')) {
                var idx = $(this).data('index');
                if (suggestions.reactions && suggestions.reactions[idx]) {
                    selected.reactions.push(suggestions.reactions[idx]);
                }
            }
        });

        $('#oneclick-ai-rules-table tbody tr').each(function () {
            if ($(this).find('input[type="checkbox"]').prop('checked')) {
                var idx = $(this).data('index');
                if (suggestions.rules && suggestions.rules[idx]) {
                    selected.rules.push(suggestions.rules[idx]);
                }
            }
        });

        return selected;
    }

    /**
     * Show status message
     */
    function showStatus(message, type) {
        var $status = $('#oneclick-ai-status');
        if (!message) {
            $status.hide();
            return;
        }

        var cls = 'notice notice-' + (type || 'info');
        $status.html('<div class="' + cls + '"><p>' + escHtml(message) + '</p></div>').show();
    }

    /**
     * Escape HTML
     */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
