(function (window, document) {
    'use strict';

    var wpElement = window.wp && window.wp.element;
    var config = window.oneclickAdminUI || {};

    if (!wpElement) {
        return;
    }

    var createElement = wpElement.createElement;
    var render = wpElement.render;

    var iconPaths = {
        activity: ['M22 12h-4l-3 9L9 3l-3 9H2'],
        chart: ['M3 3v18h18', 'M18 17V9', 'M13 17V5', 'M8 17v-3'],
        link: ['M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71', 'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'],
        palette: ['M12 22a10 10 0 1 1 10-10c0 2.76-2.24 5-5 5h-1.8a2 2 0 0 0-1.6 3.2l.1.13A1.66 1.66 0 0 1 12 22Z', 'M7.5 10.5h.01', 'M10.5 7.5h.01', 'M14.5 7.5h.01', 'M17.5 10.5h.01'],
        send: ['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'],
        settings: ['M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.74v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
        sparkles: ['m12 3-1.9 5.1L5 10l5.1 1.9L12 17l1.9-5.1L19 10l-5.1-1.9Z', 'M5 3v4', 'M3 5h4', 'M19 17v4', 'M17 19h4'],
        workflow: ['M3 6h18', 'M6 3v6', 'M3 18h18', 'M18 15v6', 'M12 6v12'],
        zap: ['M4 14a1 1 0 0 1-.78-1.63l9-11a.5.5 0 0 1 .87.45l-1.52 6.1A1 1 0 0 0 12.54 9H20a1 1 0 0 1 .78 1.63l-9 11a.5.5 0 0 1-.87-.45l1.52-6.1A1 1 0 0 0 11.46 14Z']
    };

    function Icon(props) {
        var paths = iconPaths[props.name] || iconPaths.activity;
        return createElement(
            'svg',
            {
                className: 'ocliby-icon' + (props.className ? ' ' + props.className : ''),
                width: props.size || 18,
                height: props.size || 18,
                viewBox: '0 0 24 24',
                fill: 'none',
                stroke: 'currentColor',
                strokeWidth: 2,
                strokeLinecap: 'round',
                strokeLinejoin: 'round',
                'aria-hidden': 'true'
            },
            paths.map(function (path, index) {
                return createElement('path', { d: path, key: index });
            })
        );
    }

    function Nav() {
        return createElement(
            'nav',
            { className: 'ocliby-admin__nav', 'aria-label': 'OneClick' },
            (config.nav || []).map(function (item) {
                var active = item.slug === config.page ||
                    (config.page === 'oneclick-jwt-test' && item.slug === 'oneclick-compat-test');
                return createElement(
                    'a',
                    {
                        href: config.adminUrl + '?page=' + item.slug,
                        className: 'ocliby-admin__nav-link' + (active ? ' is-active' : ''),
                        key: item.slug,
                        'aria-current': active ? 'page' : undefined
                    },
                    createElement(Icon, { name: item.icon, size: 16 }),
                    createElement('span', null, item.label)
                );
            })
        );
    }

    function App(props) {
        return createElement(
            'div',
            { className: 'ocliby-admin__frame' },
            createElement(
                'header',
                { className: 'ocliby-admin__header' },
                createElement(
                    'div',
                    { className: 'ocliby-admin__identity' },
                    createElement('span', { className: 'ocliby-admin__mark', 'aria-hidden': 'true' }, 'O'),
                    createElement(
                        'div',
                        null,
                        createElement('div', { className: 'ocliby-admin__eyebrow' }, 'OCLIBY / ONECLICK'),
                        createElement('h1', null, config.title || 'OneClick')
                    )
                ),
                createElement('p', { className: 'ocliby-admin__context' }, (config.i18n && config.i18n.workspace) || 'Commerce orchestration workspace')
            ),
            createElement(Nav),
            createElement('main', {
                className: 'ocliby-admin__content',
                dangerouslySetInnerHTML: { __html: props.content }
            })
        );
    }

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = value || '';
        return node.innerHTML;
    }

    function postAjax(data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function bindCompatibilityPage(root) {
        var runButton = root.querySelector('#oneclick-run-checks');
        var emailButton = root.querySelector('#oneclick-test-email-btn');
        var tableBody = root.querySelector('#oneclick-compat-table tbody');

        if (runButton && tableBody) {
            runButton.addEventListener('click', function () {
                runButton.disabled = true;
                runButton.textContent = config.i18n.running;
                postAjax({
                    action: 'oneclick_compat_check',
                    nonce: runButton.getAttribute('data-nonce')
                }).then(function (response) {
                    if (!response.success) {
                        throw new Error(response.data && response.data.message ? response.data.message : 'Checks failed.');
                    }
                    var checks = (response.data && response.data.checks) || [];
                    tableBody.innerHTML = checks.map(function (check) {
                        return '<tr><td><span class="ocliby-status-dot ' + (check.pass ? 'is-success' : 'is-error') + '"></span></td>' +
                            '<td><strong>' + escapeHtml(check.name) + '</strong></td>' +
                            '<td>' + escapeHtml(check.detail) + '</td></tr>';
                    }).join('');
                }).catch(function (error) {
                    tableBody.innerHTML = '<tr><td colspan="3" class="ocliby-error-text">' + escapeHtml(error.message) + '</td></tr>';
                }).finally(function () {
                    runButton.disabled = false;
                    runButton.textContent = config.i18n.runChecks;
                });
            });
        }

        if (emailButton) {
            emailButton.addEventListener('click', function () {
                var input = root.querySelector('#oneclick-test-email-input');
                var status = root.querySelector('#oneclick-test-email-status');
                emailButton.disabled = true;
                emailButton.textContent = config.i18n.sending;
                status.textContent = '';
                postAjax({
                    action: 'oneclick_test_email',
                    nonce: emailButton.getAttribute('data-nonce'),
                    email: input ? input.value : ''
                }).then(function (response) {
                    var message = response.data && response.data.message ? response.data.message : (response.success ? 'Sent.' : 'Failed.');
                    status.className = response.success ? 'ocliby-inline-status is-success' : 'ocliby-inline-status is-error';
                    status.textContent = message;
                }).catch(function (error) {
                    status.className = 'ocliby-inline-status is-error';
                    status.textContent = error.message;
                }).finally(function () {
                    emailButton.disabled = false;
                    emailButton.textContent = config.i18n.sendTest;
                });
            });
        }
    }

    function bindCopyFields(root) {
        root.querySelectorAll('input[readonly]').forEach(function (input) {
            if (!input.value || input.nextElementSibling && input.nextElementSibling.classList.contains('ocliby-copy-button')) {
                return;
            }
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ocliby-copy-button';
            button.textContent = config.i18n.copy;
            button.addEventListener('click', function () {
                window.navigator.clipboard.writeText(input.value).then(function () {
                    button.textContent = config.i18n.copied;
                    window.setTimeout(function () {
                        button.textContent = config.i18n.copy;
                    }, 1400);
                });
            });
            input.insertAdjacentElement('afterend', button);
        });
    }

    function enhance(root) {
        root.querySelectorAll('.notice').forEach(function (notice) {
            notice.setAttribute('role', 'status');
        });
        bindCopyFields(root);
        if (config.page === 'oneclick-compat-test') {
            bindCompatibilityPage(root);
        }
    }

    function mount() {
        var root = document.getElementById('oneclick-admin-root');
        var template = document.getElementById('oneclick-admin-content');
        if (!root || !template) {
            return;
        }

        var app = createElement(App, { content: template.innerHTML });
        if (typeof render === 'function') {
            render(app, root);
        } else if (typeof wpElement.createRoot === 'function') {
            var reactRoot = wpElement.createRoot(root);
            if (typeof wpElement.flushSync === 'function') {
                wpElement.flushSync(function () {
                    reactRoot.render(app);
                });
            } else {
                reactRoot.render(app);
            }
        } else {
            return;
        }
        template.remove();
        enhance(root);
        document.body.classList.add('ocliby-admin-page');
    }

    // The bundle is printed in the WordPress footer after the mount/template
    // markup. Mount synchronously so legacy jQuery-ready UI integrations find
    // their fields when WordPress dispatches DOMContentLoaded.
    mount();
})(window, document);
