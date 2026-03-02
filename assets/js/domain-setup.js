/**
 * Domain Setup — Subdomain & Custom Domain provisioning + status polling
 */
(function ($) {
    'use strict';

    var pollInterval = null;

    $(document).ready(function () {
        // Setup Subdomain button
        $('#btn-setup-subdomain').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#subdomain-spinner').addClass('is-active');

            $.post(oneclickDomain.ajaxUrl, {
                action: 'oneclick_domain_provision',
                nonce: oneclickDomain.nonce
            }, function (resp) {
                $('#subdomain-spinner').removeClass('is-active');
                if (resp.success && resp.data) {
                    var d = resp.data;
                    $('#subdomain-preview').text(d.domain_name || '');
                    renderStatus('#subdomain-status', d.verified);
                    $btn.remove();
                    // Add re-verify button if not verified
                    if (!d.verified && d.id) {
                        $('#domain-card-subdomain').append(
                            '<button type="button" class="button" id="btn-reverify" data-domain-id="' + d.id + '">Re-verify</button>'
                        );
                        bindReverify();
                        startPolling();
                    }
                } else {
                    alert('Error: ' + (resp.data || 'Unknown error'));
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $('#subdomain-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                alert('Request failed');
            });
        });

        // Setup Custom Domain button
        $('#btn-setup-custom').on('click', function () {
            var customDomain = $('#custom-domain-input').val().trim();
            if (!customDomain) {
                alert('Please enter a domain name');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#custom-spinner').addClass('is-active');

            $.post(oneclickDomain.ajaxUrl, {
                action: 'oneclick_domain_provision',
                nonce: oneclickDomain.nonce,
                custom_domain: customDomain
            }, function (resp) {
                $('#custom-spinner').removeClass('is-active');
                if (resp.success && resp.data) {
                    var d = resp.data;
                    // Replace input with domain name
                    $('#domain-card-custom .oneclick-domain-card-body').html(
                        '<code>' + escHtml(d.domain_name || '') + '</code>' +
                        '<div id="custom-status"></div>'
                    );
                    renderStatus('#custom-status', d.verified);
                    $btn.remove();
                    // Show DNS records
                    if (d.dns_records) {
                        renderDnsRecords(d.dns_records);
                    }
                    // Add re-verify button
                    if (!d.verified && d.id) {
                        $('#domain-card-custom').append(
                            '<button type="button" class="button" id="btn-reverify-custom" data-domain-id="' + d.id + '">Re-verify</button>'
                        );
                        bindReverifyCustom();
                        startPolling();
                    }
                } else {
                    alert('Error: ' + (resp.data || 'Unknown error'));
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $('#custom-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                alert('Request failed');
            });
        });

        // Bind re-verify buttons
        bindReverify();
        bindReverifyCustom();

        // Auto-poll if domain is pending
        if ($('.badge-pending').length > 0) {
            startPolling();
        }
    });

    function bindReverify() {
        $(document).off('click', '#btn-reverify').on('click', '#btn-reverify', function () {
            var domainId = $(this).data('domain-id');
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#subdomain-spinner').addClass('is-active');

            $.post(oneclickDomain.ajaxUrl, {
                action: 'oneclick_domain_verify',
                nonce: oneclickDomain.nonce,
                domain_id: domainId
            }, function (resp) {
                $('#subdomain-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                if (resp.success && resp.data) {
                    if (resp.data.verified) {
                        renderStatus('#subdomain-status', true);
                        $btn.remove();
                        stopPolling();
                    } else {
                        alert(resp.data.message || 'DNS not yet propagated. Try again later.');
                    }
                }
            }).fail(function () {
                $('#subdomain-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
            });
        });
    }

    function bindReverifyCustom() {
        $(document).off('click', '#btn-reverify-custom').on('click', '#btn-reverify-custom', function () {
            var domainId = $(this).data('domain-id');
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#custom-spinner').addClass('is-active');

            $.post(oneclickDomain.ajaxUrl, {
                action: 'oneclick_domain_verify',
                nonce: oneclickDomain.nonce,
                domain_id: domainId
            }, function (resp) {
                $('#custom-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
                if (resp.success && resp.data) {
                    if (resp.data.verified) {
                        renderStatus('#custom-status', true);
                        $btn.remove();
                        stopPolling();
                    } else {
                        alert(resp.data.message || 'DNS not yet propagated. Try again later.');
                    }
                }
            }).fail(function () {
                $('#custom-spinner').removeClass('is-active');
                $btn.prop('disabled', false);
            });
        });
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(function () {
            $.post(oneclickDomain.ajaxUrl, {
                action: 'oneclick_domain_status',
                nonce: oneclickDomain.nonce
            }, function (resp) {
                if (resp.success && resp.data && resp.data.verified) {
                    var type = resp.data.domain_type;
                    if (type === 'subdomain') {
                        renderStatus('#subdomain-status', true);
                        $('#btn-reverify').remove();
                    } else if (type === 'custom') {
                        renderStatus('#custom-status', true);
                        $('#btn-reverify-custom').remove();
                    }
                    stopPolling();
                }
            });
        }, 30000); // Poll every 30 seconds
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    function renderStatus(selector, verified) {
        if (verified) {
            $(selector).html('<span class="oneclick-badge badge-verified">Verified</span>');
        } else {
            $(selector).html('<span class="oneclick-badge badge-pending">Pending verification...</span>');
        }
    }

    function renderDnsRecords(records) {
        var $tbody = $('#dns-records-table tbody');
        $tbody.empty();
        $.each(records, function (key, record) {
            if (!record.host || !record.data) return;
            $tbody.append(
                '<tr>' +
                '<td><code>' + escHtml((record.type || 'CNAME').toUpperCase()) + '</code></td>' +
                '<td><code>' + escHtml(record.host) + '</code></td>' +
                '<td><code>' + escHtml(record.data) + '</code></td>' +
                '</tr>'
            );
        });
        $('#dns-records-dynamic').show();
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
