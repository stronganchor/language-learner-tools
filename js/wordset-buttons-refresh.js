(function () {
    'use strict';

    var config = window.llToolsWordsetButtonsRefresh || {};
    var selector = '[data-ll-wordset-buttons-refresh]';
    var states = new WeakMap();

    function positiveInteger(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
    }

    var retryMs = positiveInteger(config.retryMs, 1500);
    var requestTimeoutMs = positiveInteger(config.requestTimeoutMs, 20000);
    var maxAttempts = positiveInteger(config.maxAttempts, 120);

    function clearState(state) {
        if (state.timer) {
            window.clearTimeout(state.timer);
            state.timer = 0;
        }
        if (state.visibilityHandler) {
            document.removeEventListener('visibilitychange', state.visibilityHandler);
            state.visibilityHandler = null;
        }
    }

    function replaceCompleteRoot(root, html, state) {
        clearState(state);
        var normalized = typeof html === 'string' ? html.trim() : '';
        if (normalized === '') {
            root.remove();
            return;
        }

        var template = document.createElement('template');
        template.innerHTML = normalized;
        root.replaceWith(template.content);
    }

    function schedule(root, state, delay) {
        clearState(state);
        if (!root.isConnected || state.attempts >= maxAttempts) {
            return;
        }

        if (document.visibilityState === 'hidden') {
            state.visibilityHandler = function () {
                if (document.visibilityState !== 'hidden') {
                    clearState(state);
                    requestNextBatch(root, state);
                }
            };
            document.addEventListener('visibilitychange', state.visibilityHandler);
            return;
        }

        state.timer = window.setTimeout(function () {
            state.timer = 0;
            requestNextBatch(root, state);
        }, positiveInteger(delay, retryMs));
    }

    function requestNextBatch(root, state) {
        if (!root.isConnected || state.pending || state.attempts >= maxAttempts) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            schedule(root, state, retryMs);
            return;
        }

        state.pending = true;
        state.attempts += 1;

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = controller
            ? window.setTimeout(function () { controller.abort(); }, requestTimeoutMs)
            : 0;
        var body = new URLSearchParams();
        body.set('action', String(config.action || 'll_tools_wordset_buttons_refresh'));
        body.set('nonce', String(config.nonce || ''));
        body.set('tag', String(root.getAttribute('data-shortcode-tag') || 'll_wordset_buttons'));
        body.set('class', String(root.getAttribute('data-shortcode-class') || ''));
        body.set('hide_empty', root.getAttribute('data-hide-empty') === '1' ? '1' : '0');

        window.fetch(String(config.ajaxUrl || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('wordset-buttons-refresh-http');
            }
            return response.json();
        }).then(function (payload) {
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error('wordset-buttons-refresh-response');
            }

            var data = payload.data;
            if (data.complete === true) {
                replaceCompleteRoot(root, data.html, state);
                return;
            }

            if (typeof data.html === 'string' && data.html.trim() !== '' && root.innerHTML !== data.html) {
                root.innerHTML = data.html;
            }
            schedule(root, state, data.retryAfterMs);
        }).catch(function () {
            schedule(root, state, retryMs);
        }).finally(function () {
            if (timeout) {
                window.clearTimeout(timeout);
            }
            state.pending = false;
        });
    }

    function initialize() {
        if (!config.ajaxUrl || !config.nonce || typeof window.fetch !== 'function') {
            return;
        }

        document.querySelectorAll(selector).forEach(function (root) {
            if (states.has(root)) {
                return;
            }
            var state = {
                attempts: 0,
                pending: false,
                timer: 0,
                visibilityHandler: null
            };
            states.set(root, state);
            requestNextBatch(root, state);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
