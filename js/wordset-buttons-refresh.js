(function () {
    'use strict';

    var selector = '[data-ll-wordset-buttons-refresh]';
    var states = new WeakMap();

    function positiveInteger(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
    }

    function rootConfig(root) {
        var localized = window.llToolsWordsetButtonsRefresh || {};
        return {
            ajaxUrl: String(root.getAttribute('data-ajax-url') || localized.ajaxUrl || ''),
            action: String(root.getAttribute('data-ajax-action') || localized.action || 'll_tools_wordset_buttons_refresh'),
            nonce: String(root.getAttribute('data-nonce') || localized.nonce || ''),
            statusToken: String(root.getAttribute('data-status-token') || ''),
            retryMs: positiveInteger(localized.retryMs, 1500),
            requestTimeoutMs: positiveInteger(localized.requestTimeoutMs, 20000),
            maxFailures: positiveInteger(localized.maxFailures, 5),
            maxWaitMs: positiveInteger(localized.maxWaitMs, 10 * 60 * 1000),
            errorMessage: String(root.getAttribute('data-error-message') || localized.errorMessage || ''),
            retryLabel: String(root.getAttribute('data-retry-label') || localized.retryLabel || '')
        };
    }

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

    function parseCompleteRoot(html) {
        var normalized = typeof html === 'string' ? html.trim() : '';
        if (normalized === '') {
            return null;
        }
        var template = document.createElement('template');
        template.innerHTML = normalized;
        return template.content.firstElementChild;
    }

    function copyAttributes(source, target) {
        Array.from(target.attributes).forEach(function (attribute) {
            target.removeAttribute(attribute.name);
        });
        Array.from(source.attributes).forEach(function (attribute) {
            target.setAttribute(attribute.name, attribute.value);
        });
    }

    function focusWithoutScroll(element) {
        if (!element || typeof element.focus !== 'function') {
            return;
        }
        try {
            element.focus({ preventScroll: true });
        } catch (error) {
            element.focus();
        }
    }

    function focusTargetOutside(root) {
        if (!document.activeElement || !root.contains(document.activeElement)) {
            return null;
        }
        var candidates = Array.from(document.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function (candidate) {
            return candidate !== root && !root.contains(candidate);
        });
        var following = candidates.find(function (candidate) {
            return (root.compareDocumentPosition(candidate) & 4) !== 0;
        });
        return following || candidates[candidates.length - 1] || null;
    }

    function replaceCardContents(currentLink, completeLink) {
        var currentMedia = Array.from(currentLink.children).find(function (child) {
            return child.classList.contains('ll-wordset-buttons-shortcode__media');
        });
        var completeMedia = Array.from(completeLink.children).find(function (child) {
            return child.classList.contains('ll-wordset-buttons-shortcode__media');
        });
        var currentImage = currentMedia ? currentMedia.querySelector('.ll-wordset-buttons-shortcode__image') : null;
        var completeImage = completeMedia ? completeMedia.querySelector('.ll-wordset-buttons-shortcode__image') : null;
        var preserveMedia = currentMedia
            && completeMedia
            && currentImage
            && completeImage
            && String(currentImage.getAttribute('src') || '') === String(completeImage.getAttribute('src') || '');
        var fragment = document.createDocumentFragment();
        Array.from(completeLink.childNodes).forEach(function (node) {
            fragment.appendChild(preserveMedia && node === completeMedia ? currentMedia : node.cloneNode(true));
        });
        currentLink.replaceChildren(fragment);
    }

    function reconcileCompleteNavigation(root, completeRoot) {
        var navigation = root.querySelector('[data-ll-wordset-buttons-navigation]');
        var currentList = navigation ? navigation.querySelector('.ll-wordset-buttons-shortcode__list') : null;
        var completeList = completeRoot ? completeRoot.querySelector('.ll-wordset-buttons-shortcode__list') : null;
        if (!navigation || !currentList || !completeList) {
            return false;
        }

        var focusedWordsetId = '';
        if (document.activeElement && navigation.contains(document.activeElement)) {
            var focusedCard = document.activeElement.closest
                ? document.activeElement.closest('.ll-wordset-buttons-shortcode__button[data-ll-wordset-id]')
                : null;
            focusedWordsetId = focusedCard
                ? String(focusedCard.getAttribute('data-ll-wordset-id') || '')
                : '';
        }

        var completeItems = Array.from(completeList.children).filter(function (item) {
            return item.hasAttribute('data-ll-wordset-id');
        });
        var completeById = new Map();
        completeItems.forEach(function (item) {
            var id = String(item.getAttribute('data-ll-wordset-id') || '');
            if (id) {
                completeById.set(id, item);
            }
        });
        var currentItems = Array.from(currentList.children).filter(function (item) {
            return item.hasAttribute('data-ll-wordset-id');
        });
        if (!currentItems.length || !completeItems.length || completeById.size !== completeItems.length) {
            return false;
        }
        var focusedCardIndex = currentItems.findIndex(function (item) {
            return String(item.getAttribute('data-ll-wordset-id') || '') === focusedWordsetId;
        });

        var retainedIds = new Set();
        currentItems.forEach(function (currentItem) {
            var id = String(currentItem.getAttribute('data-ll-wordset-id') || '');
            var completeItem = completeById.get(id);
            if (!id || !completeItem) {
                currentItem.remove();
                return;
            }

            var currentLink = currentItem.querySelector('.ll-wordset-buttons-shortcode__button[data-ll-wordset-id]');
            var completeLink = completeItem.querySelector('.ll-wordset-buttons-shortcode__button[data-ll-wordset-id]');
            if (!currentLink || !completeLink) {
                currentItem.replaceWith(completeItem.cloneNode(true));
                retainedIds.add(id);
                return;
            }

            copyAttributes(completeItem, currentItem);
            copyAttributes(completeLink, currentLink);
            replaceCardContents(currentLink, completeLink);
            retainedIds.add(id);
        });

        completeItems.forEach(function (completeItem) {
            var id = String(completeItem.getAttribute('data-ll-wordset-id') || '');
            if (id && !retainedIds.has(id)) {
                currentList.appendChild(completeItem.cloneNode(true));
            }
        });

        copyAttributes(completeRoot, navigation);
        Array.from(navigation.children).forEach(function (child) {
            if (child.classList && child.classList.contains('screen-reader-text')) {
                child.remove();
            }
        });
        var parent = root.parentNode;
        if (!parent) {
            return false;
        }
        parent.insertBefore(navigation, root);
        root.remove();
        if (focusedWordsetId) {
            var retainedFocus = Array.from(navigation.querySelectorAll(
                '.ll-wordset-buttons-shortcode__button[data-ll-wordset-id]'
            )).find(function (link) {
                return String(link.getAttribute('data-ll-wordset-id') || '') === focusedWordsetId;
            });
            if (!retainedFocus && focusedCardIndex >= 0) {
                var availableLinks = Array.from(navigation.querySelectorAll(
                    '.ll-wordset-buttons-shortcode__button[href]'
                ));
                retainedFocus = availableLinks[Math.min(focusedCardIndex, availableLinks.length - 1)] || null;
            }
            if (retainedFocus) {
                focusWithoutScroll(retainedFocus);
            }
        }
        return true;
    }

    function replaceCompleteRoot(root, html, state) {
        clearState(state);
        var normalized = typeof html === 'string' ? html.trim() : '';
        if (normalized === '') {
            var emptyFocusTarget = focusTargetOutside(root);
            root.remove();
            focusWithoutScroll(emptyFocusTarget);
            return;
        }

        var completeRoot = parseCompleteRoot(normalized);
        if (completeRoot && reconcileCompleteNavigation(root, completeRoot)) {
            return;
        }

        var template = document.createElement('template');
        template.innerHTML = normalized;
        root.replaceWith(template.content);
    }

    function showRetry(root, state) {
        if (!root.isConnected || state.showingRetry) {
            return;
        }

        clearState(state);
        state.showingRetry = true;
        root.setAttribute('aria-busy', 'false');

        var config = rootConfig(root);
        var panel = document.createElement('div');
        panel.className = 'll-wordset-buttons-refresh__error';
        panel.setAttribute('role', 'alert');

        var message = document.createElement('p');
        message.className = 'll-wordset-buttons-refresh__error-message';
        message.textContent = config.errorMessage || '⚠';

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'll-study-btn ll-wordset-buttons-refresh__retry';
        retry.textContent = config.retryLabel || '↻';
        retry.setAttribute('aria-label', config.retryLabel || '↻');
        retry.addEventListener('click', function () {
            if (state.reloadOnRetry) {
                window.location.reload();
                return;
            }
            state.showingRetry = false;
            state.failures = 0;
            state.nonceRefreshes = 0;
            state.configWaits = 0;
            state.startedAt = Date.now();
            root.setAttribute('aria-busy', 'true');
            root.innerHTML = state.loadingHtml;
            requestNextBatch(root, state);
        });

        panel.appendChild(message);
        panel.appendChild(retry);
        var usableNavigation = root.querySelector('.ll-wordset-buttons-shortcode__button[href]');
        if (usableNavigation) {
            var navigationShell = usableNavigation.closest('[data-ll-wordset-buttons-navigation]');
            if (navigationShell) {
                navigationShell.setAttribute('aria-busy', 'false');
                Array.from(navigationShell.children).forEach(function (child) {
                    if (child.classList && child.classList.contains('screen-reader-text')) {
                        child.remove();
                    }
                });
                navigationShell.querySelectorAll(
                    '.ll-wordset-buttons-shortcode__button[data-ll-wordset-card-state="hydrating"]'
                ).forEach(function (link) {
                    link.setAttribute('aria-busy', 'false');
                    link.setAttribute('data-ll-wordset-card-state', 'stalled');
                    link.classList.remove('ll-wordset-buttons-shortcode__button--hydrating');
                    link.classList.add('ll-wordset-buttons-shortcode__button--stalled');
                });
                navigationShell.querySelectorAll(
                    '.ll-wordset-buttons-shortcode__count--loading'
                ).forEach(function (count) {
                    count.classList.add('ll-wordset-buttons-shortcode__count--stalled');
                });
            }
        } else {
            root.textContent = '';
        }
        root.appendChild(panel);
    }

    function schedule(root, state, delay) {
        clearState(state);
        if (!root.isConnected || state.showingRetry) {
            return;
        }

        var config = rootConfig(root);
        var waitMs = positiveInteger(delay, config.retryMs);
        if (Date.now() + waitMs - state.startedAt > config.maxWaitMs) {
            showRetry(root, state);
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
        }, waitMs);
    }

    function transientFailureDelay(config, failures) {
        return Math.min(30000, config.retryMs * Math.pow(2, Math.max(0, failures - 1)));
    }

    function refreshExpiredNonce(root, state, response, payload, config) {
        var data = payload && payload.data ? payload.data : {};
        if (
            config.statusToken
            ||
            response.status !== 403
            || data.code !== 'invalid_nonce'
            || typeof data.nonce !== 'string'
            || data.nonce === ''
            || state.nonceRefreshes >= 2
        ) {
            return false;
        }

        root.setAttribute('data-nonce', data.nonce);
        state.nonceRefreshes += 1;
        state.failures = 0;
        schedule(root, state, 1);
        return true;
    }

    function requestNextBatch(root, state) {
        if (!root.isConnected || state.pending || state.showingRetry) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            schedule(root, state, rootConfig(root).retryMs);
            return;
        }

        var config = rootConfig(root);
        if (!config.ajaxUrl || (!config.nonce && !config.statusToken) || typeof window.fetch !== 'function') {
            state.configWaits += 1;
            if (state.configWaits >= 20) {
                showRetry(root, state);
                return;
            }
            schedule(root, state, 250);
            return;
        }

        state.configWaits = 0;
        state.pending = true;

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = controller
            ? window.setTimeout(function () { controller.abort(); }, config.requestTimeoutMs)
            : 0;
        var body = new URLSearchParams();
        body.set('action', config.action);
        if (config.statusToken) {
            body.set('token', config.statusToken);
        } else {
            body.set('nonce', config.nonce);
            body.set('tag', String(root.getAttribute('data-shortcode-tag') || 'll_wordset_buttons'));
            body.set('class', String(root.getAttribute('data-shortcode-class') || ''));
            body.set('hide_empty', root.getAttribute('data-hide-empty') === '1' ? '1' : '0');
        }

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { response: response, payload: payload };
            });
        }).then(function (result) {
            if (!result.response.ok) {
                if (refreshExpiredNonce(root, state, result.response, result.payload, config)) {
                    return;
                }
                var errorData = result.payload && result.payload.data ? result.payload.data : {};
                if (result.response.status === 429 && config.statusToken) {
                    var retryHeader = parseInt(result.response.headers.get('Retry-After') || '', 10);
                    var retryAfterMs = positiveInteger(
                        errorData.retryAfterMs,
                        Number.isFinite(retryHeader) && retryHeader > 0 ? retryHeader * 1000 : config.retryMs
                    );
                    state.failures = 0;
                    schedule(root, state, retryAfterMs);
                    return;
                }
                if (
                    config.statusToken
                    && [403, 409, 410].indexOf(result.response.status) !== -1
                    && ['invalid_status_token', 'expired_status_token', 'stale_status_token'].indexOf(String(errorData.code || '')) !== -1
                ) {
                    state.reloadOnRetry = true;
                    showRetry(root, state);
                    return;
                }
                throw new Error('wordset-buttons-refresh-http');
            }

            var payload = result.payload;
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error('wordset-buttons-refresh-response');
            }

            var data = payload.data;
            state.failures = 0;
            state.nonceRefreshes = 0;
            if (data.complete === true) {
                replaceCompleteRoot(root, data.html, state);
                return;
            }

            var currentNavigation = root.querySelector('[data-ll-wordset-buttons-navigation]');
            var incomingNavigation = typeof data.html === 'string'
                && data.html.indexOf('data-ll-wordset-buttons-navigation') !== -1;
            if (
                typeof data.html === 'string'
                && data.html.trim() !== ''
                && root.innerHTML !== data.html
                && !(currentNavigation && incomingNavigation)
            ) {
                root.innerHTML = data.html;
                // A bounded refresh can return a newer navigation shell before
                // exact counts are ready. Manual Retry must preserve that latest
                // usable shell instead of reverting to initialization markup.
                state.loadingHtml = root.innerHTML;
            }
            schedule(root, state, data.retryAfterMs);
        }).catch(function () {
            state.failures += 1;
            var currentConfig = rootConfig(root);
            if (state.failures >= currentConfig.maxFailures) {
                showRetry(root, state);
                return;
            }
            schedule(root, state, transientFailureDelay(currentConfig, state.failures));
        }).finally(function () {
            if (timeout) {
                window.clearTimeout(timeout);
            }
            state.pending = false;
        });
    }

    function initializeRoot(root) {
        if (states.has(root)) {
            return;
        }

        var state = {
            failures: 0,
            nonceRefreshes: 0,
            configWaits: 0,
            pending: false,
            timer: 0,
            visibilityHandler: null,
            showingRetry: false,
            reloadOnRetry: false,
            startedAt: Date.now(),
            loadingHtml: root.innerHTML
        };
        states.set(root, state);
        requestNextBatch(root, state);
    }

    function initialize(scope) {
        var root = scope && scope.matches && scope.matches(selector) ? scope : null;
        if (root) {
            initializeRoot(root);
        }

        if (scope && typeof scope.querySelectorAll === 'function') {
            scope.querySelectorAll(selector).forEach(initializeRoot);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initialize(document); }, { once: true });
    } else {
        initialize(document);
    }

    window.addEventListener('pageshow', function () { initialize(document); });

    if (typeof MutationObserver === 'function' && document.documentElement) {
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === 1) {
                        initialize(node);
                    }
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
}());
