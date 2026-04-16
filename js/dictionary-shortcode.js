(function () {
    const config = window.llToolsDictionary || {};
    const ajaxUrl = typeof config.ajaxUrl === 'string' ? config.ajaxUrl : '';
    const nonce = typeof config.nonce === 'string' ? config.nonce : '';
    const minChars = Number.isFinite(Number(config.minChars)) ? Math.max(1, Number(config.minChars)) : 2;
    const debounceMs = Number.isFinite(Number(config.debounceMs)) ? Math.max(80, Number(config.debounceMs)) : 160;
    const loadingCards = Number.isFinite(Number(config.loadingCards)) ? Math.max(1, Math.min(4, Number(config.loadingCards))) : 3;
    const cacheSize = Number.isFinite(Number(config.cacheSize)) ? Math.max(0, Math.min(64, Number(config.cacheSize))) : 24;
    const loadingLabel = typeof config.loadingLabel === 'string' && config.loadingLabel
        ? config.loadingLabel
        : 'Loading dictionary results...';

    if (!ajaxUrl) {
        return;
    }

    document.querySelectorAll('[data-ll-dictionary-root]').forEach((root) => {
        const form = root.querySelector('[data-ll-dictionary-form]');
        const results = root.querySelector('[data-ll-dictionary-results]');
        const toolbar = root.querySelector('.ll-dictionary__toolbar');
        const resetLink = root.querySelector('[data-ll-dictionary-reset]');
        const searchInput = form ? form.querySelector('input[name="ll_dictionary_q"]') : null;
        const letterInput = form ? form.querySelector('input[name="ll_dictionary_letter"]') : null;

        if (!form || !results || !toolbar || !searchInput || !letterInput) {
            return;
        }

        let debounceTimer = 0;
        let activeController = null;
        let activeRequestId = 0;
        const responseCache = new Map();

        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => {
            switch (char) {
            case '&':
                return '&amp;';
            case '<':
                return '&lt;';
            case '>':
                return '&gt;';
            case '"':
                return '&quot;';
            case '\'':
                return '&#39;';
            default:
                return char;
            }
        });

        const getFieldValue = (name) => {
            const field = form.elements.namedItem(name);
            return field && 'value' in field ? String(field.value || '').trim() : '';
        };

        const setFieldValue = (name, value) => {
            const field = form.elements.namedItem(name);
            if (field && 'value' in field) {
                field.value = value;
            }
        };

        const hasActiveQuery = () => {
            return [
                String(searchInput.value || '').trim(),
                String(letterInput.value || '').trim(),
                getFieldValue('ll_dictionary_pos'),
                getFieldValue('ll_dictionary_source'),
                getFieldValue('ll_dictionary_dialect'),
            ].some((value) => value !== '');
        };

        const hasNonSearchQuery = () => {
            return [
                String(letterInput.value || '').trim(),
                getFieldValue('ll_dictionary_pos'),
                getFieldValue('ll_dictionary_source'),
                getFieldValue('ll_dictionary_dialect'),
            ].some((value) => value !== '');
        };

        const setActiveState = (active) => {
            toolbar.classList.toggle('is-expanded', active);
            toolbar.classList.toggle('is-collapsed', !active);
            if (resetLink) {
                resetLink.hidden = !active;
            }
        };

        const cancelActiveRequest = () => {
            window.clearTimeout(debounceTimer);
            if (activeController) {
                activeController.abort();
                activeController = null;
            }

            activeRequestId += 1;
            results.removeAttribute('aria-busy');
        };

        const renderResponsePayload = (payload) => {
            if (!payload || !payload.success || !payload.data) {
                throw new Error('invalid_payload');
            }

            results.innerHTML = typeof payload.data.html === 'string' ? payload.data.html : '';
            setActiveState(Boolean(payload.data.has_active_query));

            if (payload.data.url && window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', String(payload.data.url));
            }
        };

        const buildLoadingMarkup = () => {
            const markup = [
                '<div class="ll-dictionary__loading" role="status" aria-live="polite" aria-label="',
                escapeHtml(loadingLabel),
                '">',
                '<span class="screen-reader-text">',
                escapeHtml(loadingLabel),
                '</span>',
                '<div class="ll-dictionary__loading-meta" aria-hidden="true"></div>',
            ];

            for (let index = 0; index < loadingCards; index += 1) {
                const titleClass = index % 3 === 1
                    ? ' ll-dictionary__loading-line--title-short'
                    : (index % 3 === 2 ? ' ll-dictionary__loading-line--title-medium' : '');
                const lineClass = index % 2 === 0
                    ? ' ll-dictionary__loading-line--body-short'
                    : '';
                const pillClass = index % 2 === 0
                    ? ' ll-dictionary__loading-pill--wide'
                    : '';

                markup.push(
                    '<article class="ll-dictionary__loading-card" aria-hidden="true">',
                    '<div class="ll-dictionary__loading-head">',
                    '<div class="ll-dictionary__loading-stack">',
                    '<span class="ll-dictionary__loading-line ll-dictionary__loading-line--title', titleClass, '"></span>',
                    '<span class="ll-dictionary__loading-line ll-dictionary__loading-line--body', lineClass, '"></span>',
                    '</div>',
                    '<div class="ll-dictionary__loading-pills">',
                    '<span class="ll-dictionary__loading-pill', pillClass, '"></span>',
                    '<span class="ll-dictionary__loading-pill"></span>',
                    '</div>',
                    '</div>',
                    '<div class="ll-dictionary__loading-stack">',
                    '<span class="ll-dictionary__loading-line ll-dictionary__loading-line--body"></span>',
                    '<span class="ll-dictionary__loading-line ll-dictionary__loading-line--body ll-dictionary__loading-line--body-short"></span>',
                    '</div>',
                    '</article>'
                );
            }

            markup.push('</div>');
            return markup.join('');
        };

        const showLoadingState = () => {
            cancelActiveRequest();
            results.innerHTML = buildLoadingMarkup();
            results.setAttribute('aria-busy', 'true');
            setActiveState(true);
        };

        const clearResults = () => {
            cancelActiveRequest();
            results.innerHTML = '';
            setActiveState(false);

            const baseUrl = root.dataset.baseUrl || window.location.href;
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', baseUrl);
            }
        };

        const canRunQuery = () => {
            const search = String(searchInput.value || '').trim();
            if (search !== '' && search.length < minChars && !hasNonSearchQuery()) {
                return false;
            }

            return !(search === '' && !hasActiveQuery());
        };

        const buildRequestCacheKey = (page) => JSON.stringify({
            wordsetId: root.dataset.wordsetId || '0',
            perPage: root.dataset.perPage || '20',
            senseLimit: root.dataset.senseLimit || '3',
            linkedWordLimit: root.dataset.linkedWordLimit || '4',
            glossLang: root.dataset.glossLang || '',
            query: String(searchInput.value || '').trim(),
            letter: String(letterInput.value || '').trim(),
            pos: getFieldValue('ll_dictionary_pos'),
            source: getFieldValue('ll_dictionary_source'),
            dialect: getFieldValue('ll_dictionary_dialect'),
            page: String(Math.max(1, page || 1)),
        });

        const storeCachedResponse = (key, payload) => {
            if (!cacheSize || !key) {
                return;
            }

            if (responseCache.has(key)) {
                responseCache.delete(key);
            }
            responseCache.set(key, payload);

            while (responseCache.size > cacheSize) {
                const oldestKey = responseCache.keys().next().value;
                if (!oldestKey) {
                    break;
                }
                responseCache.delete(oldestKey);
            }
        };

        const buildPayload = (page) => {
            const payload = new FormData();
            payload.set('action', 'll_tools_dictionary_live_search');
            payload.set('nonce', nonce);
            payload.set('wordset_id', root.dataset.wordsetId || '0');
            payload.set('per_page', root.dataset.perPage || '20');
            payload.set('sense_limit', root.dataset.senseLimit || '3');
            payload.set('linked_word_limit', root.dataset.linkedWordLimit || '4');
            payload.set('gloss_lang', root.dataset.glossLang || '');
            payload.set('base_url', root.dataset.baseUrl || window.location.href);
            payload.set('ll_dictionary_q', String(searchInput.value || '').trim());
            payload.set('ll_dictionary_letter', String(letterInput.value || '').trim());
            payload.set('ll_dictionary_page', String(Math.max(1, page || 1)));
            payload.set('ll_dictionary_pos', getFieldValue('ll_dictionary_pos'));
            payload.set('ll_dictionary_source', getFieldValue('ll_dictionary_source'));
            payload.set('ll_dictionary_dialect', getFieldValue('ll_dictionary_dialect'));

            return payload;
        };

        const requestResults = (page, fallbackToFullSubmit) => {
            const requestPage = Math.max(1, page || 1);
            const cacheKey = buildRequestCacheKey(requestPage);
            if (cacheSize > 0 && responseCache.has(cacheKey)) {
                renderResponsePayload(responseCache.get(cacheKey));
                results.removeAttribute('aria-busy');
                return;
            }

            if (activeController) {
                activeController.abort();
            }

            const requestId = ++activeRequestId;
            activeController = new AbortController();
            results.setAttribute('aria-busy', 'true');

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: buildPayload(requestPage),
                signal: activeController.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('request_failed');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (requestId !== activeRequestId) {
                        throw new Error('invalid_payload');
                    }

                    storeCachedResponse(cacheKey, payload);
                    renderResponsePayload(payload);
                })
                .catch((error) => {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    if (fallbackToFullSubmit) {
                        form.submit();
                    }
                })
                .finally(() => {
                    if (requestId === activeRequestId) {
                        activeController = null;
                        results.removeAttribute('aria-busy');
                    }
                });
        };

        const triggerLiveSearch = (page) => {
            if (!canRunQuery()) {
                clearResults();
                return;
            }

            if (!results.hasAttribute('aria-busy')) {
                showLoadingState();
            }

            requestResults(page || 1, false);
        };

        const scheduleLiveSearch = () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => {
                triggerLiveSearch(1);
            }, debounceMs);
        };

        searchInput.addEventListener('input', () => {
            if (String(searchInput.value || '').trim() !== '') {
                letterInput.value = '';
            }

            if (!canRunQuery()) {
                clearResults();
                return;
            }

            showLoadingState();
            scheduleLiveSearch();
        });

        ['ll_dictionary_pos', 'll_dictionary_source', 'll_dictionary_dialect'].forEach((name) => {
            const field = form.elements.namedItem(name);
            if (field) {
                field.addEventListener('change', () => {
                    if (!canRunQuery()) {
                        clearResults();
                        return;
                    }

                    showLoadingState();
                    triggerLiveSearch(1);
                });
            }
        });

        form.addEventListener('submit', (event) => {
            if (!hasActiveQuery()) {
                return;
            }

            event.preventDefault();
            showLoadingState();
            requestResults(1, true);
        });

        if (resetLink) {
            resetLink.addEventListener('click', (event) => {
                event.preventDefault();
                form.reset();
                letterInput.value = '';
                clearResults();
            });
        }

        root.addEventListener('click', (event) => {
            const textToggle = event.target.closest('[data-ll-dictionary-toggle]');
            if (textToggle) {
                const textBlock = textToggle.closest('[data-ll-dictionary-text-block]');
                if (!textBlock) {
                    return;
                }

                event.preventDefault();
                const willExpand = textBlock.classList.contains('is-collapsed');
                textBlock.classList.toggle('is-collapsed', !willExpand);
                textToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
                textToggle.textContent = willExpand
                    ? (textToggle.getAttribute('data-collapse-label') || 'Show less')
                    : (textToggle.getAttribute('data-expand-label') || 'Show more');
                return;
            }

            const link = event.target.closest('.ll-dictionary__pagination a, .ll-dictionary__letters a');
            if (!link || !link.href || link.getAttribute('href') === '#') {
                return;
            }

            let url;
            try {
                url = new URL(link.href, window.location.href);
            } catch (error) {
                return;
            }

            if (!url.searchParams.has('ll_dictionary_page') && !url.searchParams.has('ll_dictionary_letter')) {
                return;
            }

            event.preventDefault();

            searchInput.value = url.searchParams.get('ll_dictionary_q') || '';
            letterInput.value = url.searchParams.get('ll_dictionary_letter') || '';
            setFieldValue('ll_dictionary_pos', url.searchParams.get('ll_dictionary_pos') || '');
            setFieldValue('ll_dictionary_source', url.searchParams.get('ll_dictionary_source') || '');
            setFieldValue('ll_dictionary_dialect', url.searchParams.get('ll_dictionary_dialect') || '');

            showLoadingState();
            requestResults(Number(url.searchParams.get('ll_dictionary_page') || '1'), false);
        });
    });
}());
