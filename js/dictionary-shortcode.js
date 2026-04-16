(function () {
    const config = window.llToolsDictionary || {};
    const ajaxUrl = typeof config.ajaxUrl === 'string' ? config.ajaxUrl : '';
    const nonce = typeof config.nonce === 'string' ? config.nonce : '';
    const minChars = Number.isFinite(Number(config.minChars)) ? Math.max(1, Number(config.minChars)) : 2;
    const debounceMs = Number.isFinite(Number(config.debounceMs)) ? Math.max(80, Number(config.debounceMs)) : 220;

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
            if (activeController) {
                activeController.abort();
                activeController = null;
            }

            activeRequestId += 1;
            results.removeAttribute('aria-busy');
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
            if (activeController) {
                activeController.abort();
            }

            const requestId = ++activeRequestId;
            activeController = new AbortController();
            results.setAttribute('aria-busy', 'true');

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: buildPayload(page),
                signal: activeController.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('request_failed');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (requestId !== activeRequestId || !payload || !payload.success || !payload.data) {
                        throw new Error('invalid_payload');
                    }

                    results.innerHTML = typeof payload.data.html === 'string' ? payload.data.html : '';
                    setActiveState(Boolean(payload.data.has_active_query));

                    if (payload.data.url && window.history && typeof window.history.replaceState === 'function') {
                        window.history.replaceState(null, '', String(payload.data.url));
                    }
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
                        results.removeAttribute('aria-busy');
                    }
                });
        };

        const triggerLiveSearch = (page) => {
            const search = String(searchInput.value || '').trim();
            if (search !== '' && search.length < minChars && !hasNonSearchQuery()) {
                clearResults();
                return;
            }
            if (search === '' && !hasActiveQuery()) {
                clearResults();
                return;
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

            const search = String(searchInput.value || '').trim();
            if (search === '' && !hasActiveQuery()) {
                clearResults();
                return;
            }

            if (search === '' || search.length >= minChars) {
                scheduleLiveSearch();
            }
        });

        ['ll_dictionary_pos', 'll_dictionary_source', 'll_dictionary_dialect'].forEach((name) => {
            const field = form.elements.namedItem(name);
            if (field) {
                field.addEventListener('change', () => {
                    triggerLiveSearch(1);
                });
            }
        });

        form.addEventListener('submit', (event) => {
            if (!hasActiveQuery()) {
                return;
            }

            event.preventDefault();
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

            requestResults(Number(url.searchParams.get('ll_dictionary_page') || '1'), false);
        });
    });
}());
