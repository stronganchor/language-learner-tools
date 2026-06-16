(function () {
    const config = window.llToolsDictionary || {};
    const ajaxUrl = typeof config.ajaxUrl === 'string' ? config.ajaxUrl : '';
    const nonce = typeof config.nonce === 'string' ? config.nonce : '';
    const minChars = Number.isFinite(Number(config.minChars)) ? Math.max(1, Number(config.minChars)) : 2;
    const debounceMs = Number.isFinite(Number(config.debounceMs)) ? Math.max(300, Number(config.debounceMs)) : 400;
    const loadingCards = Number.isFinite(Number(config.loadingCards)) ? Math.max(1, Math.min(4, Number(config.loadingCards))) : 3;
    const cacheSize = Number.isFinite(Number(config.cacheSize)) ? Math.max(0, Math.min(64, Number(config.cacheSize))) : 24;
    const loadingLabel = typeof config.loadingLabel === 'string' && config.loadingLabel
        ? config.loadingLabel
        : 'Loading dictionary results...';
    const toolbarLoadingLabel = typeof config.toolbarLoadingLabel === 'string' && config.toolbarLoadingLabel
        ? config.toolbarLoadingLabel
        : 'Loading dictionary filters...';
    const entryTitleRequiredLabel = typeof config.entryTitleRequiredLabel === 'string' && config.entryTitleRequiredLabel
        ? config.entryTitleRequiredLabel
        : 'Enter a dictionary entry title.';
    const entryDefinitionRequiredLabel = typeof config.entryDefinitionRequiredLabel === 'string' && config.entryDefinitionRequiredLabel
        ? config.entryDefinitionRequiredLabel
        : 'Enter a definition.';
    const entrySavingLabel = typeof config.entrySavingLabel === 'string' && config.entrySavingLabel
        ? config.entrySavingLabel
        : 'Saving...';
    const entryErrorLabel = typeof config.entryErrorLabel === 'string' && config.entryErrorLabel
        ? config.entryErrorLabel
        : 'Unable to save this dictionary entry right now.';

    if (!ajaxUrl) {
        return;
    }

    const readAjaxMessage = (payload, fallback) => {
        if (payload && payload.data && typeof payload.data.message === 'string' && payload.data.message) {
            return payload.data.message;
        }
        if (payload && typeof payload.message === 'string' && payload.message) {
            return payload.message;
        }
        return fallback;
    };

    const escapeInlineHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => {
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
            return '&#039;';
        default:
            return char;
        }
    });

    const normalizeInlineValue = (value) => String(value || '').replace(/\r\n?/g, '\n').trim();
    const formatInlineText = (value) => escapeInlineHtml(value).replace(/\n/g, '<br>');

    const postUpdateRequest = (params, keepalive) => {
        const payload = new URLSearchParams();
        Object.keys(params || {}).forEach((key) => {
            const value = params[key];
            if (value === null || typeof value === 'undefined') {
                return;
            }
            payload.set(key, String(value));
        });

        return fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: payload.toString(),
            keepalive: !!keepalive,
        }).then((response) => {
            if (!response.ok) {
                throw new Error('request_failed');
            }

            return response.json();
        });
    };

    const updateSummary = (root, summary) => {
        const summaryNode = root.querySelector('[data-ll-dictionary-summary]');
        if (!summaryNode) {
            return;
        }

        const text = typeof summary === 'string' ? summary.trim() : '';
        summaryNode.textContent = text;
        summaryNode.hidden = !text;
    };

    const syncReviewState = (reviewState, payload) => {
        if (!reviewState || !payload || typeof payload !== 'object') {
            return;
        }

        const needsReview = !!payload.needs_review;
        const reviewLabel = (payload.review_label || '').toString();
        const pill = reviewState.querySelector('[data-ll-dictionary-entry-review-pill]');
        const label = reviewState.querySelector('[data-ll-dictionary-entry-review-label]');
        const clearButton = reviewState.querySelector('[data-ll-dictionary-review-clear]');
        const markButton = reviewState.querySelector('[data-ll-dictionary-review-mark]');

        reviewState.classList.toggle('is-needs-review', needsReview);
        reviewState.classList.toggle('is-reviewed', !needsReview);

        if (pill) {
            pill.classList.toggle('is-active', needsReview);
        }
        if (label && reviewLabel) {
            label.textContent = reviewLabel;
        }
        if (clearButton) {
            clearButton.hidden = !needsReview;
        }
        if (markButton) {
            markButton.hidden = needsReview;
        }
    };

    const autoResizeTextarea = (input) => {
        if (!input || input.tagName !== 'TEXTAREA') {
            return;
        }

        input.style.height = 'auto';
        input.style.height = `${Math.max(input.scrollHeight, 72)}px`;
    };

    const initInlineEditors = (root) => {
        root.querySelectorAll('[data-ll-dictionary-inline-editor]').forEach((editor) => {
            if (editor.getAttribute('data-ll-dictionary-inline-editor-ready') === '1') {
                return;
            }
            editor.setAttribute('data-ll-dictionary-inline-editor-ready', '1');

            const trigger = editor.querySelector('[data-ll-dictionary-inline-trigger]');
            const field = editor.querySelector('[data-ll-dictionary-inline-field]');
            const input = editor.querySelector('[data-ll-dictionary-inline-input]');
            const text = editor.querySelector('[data-ll-dictionary-inline-text]');
            const status = editor.querySelector('[data-ll-dictionary-inline-status]');
            const updateType = (editor.getAttribute('data-update-type') || '').toString();
            let savePromise = null;

            if (!trigger || !field || !input || !text || !status || !updateType) {
                return;
            }

            input.dataset.committedValue = normalizeInlineValue(input.value);
            autoResizeTextarea(input);

            const setStatus = (message, type) => {
                const hasMessage = !!message;
                status.textContent = hasMessage ? String(message) : '';
                status.hidden = !hasMessage;
                status.classList.toggle('is-error', type === 'error');
                status.classList.toggle('is-saving', type === 'saving');
            };

            const setSaving = (saving) => {
                const isSaving = !!saving;
                editor.classList.toggle('is-saving', isSaving);
                input.disabled = isSaving;
                trigger.disabled = isSaving;
            };

            const closeEditor = (restoreValue) => {
                if (restoreValue) {
                    input.value = input.dataset.committedValue || '';
                    autoResizeTextarea(input);
                }
                editor.classList.remove('is-editing');
                field.hidden = true;
            };

            const openEditor = () => {
                editor.classList.add('is-editing');
                field.hidden = false;
                setStatus('', '');
                window.requestAnimationFrame(() => {
                    input.focus();
                    if (typeof input.select === 'function' && input.tagName !== 'TEXTAREA') {
                        input.select();
                    }
                    autoResizeTextarea(input);
                });
            };

            const syncEditor = (payload) => {
                const data = (payload && typeof payload === 'object') ? payload : {};
                const nextValue = updateType === 'title'
                    ? (data.title || '').toString()
                    : (typeof data.value === 'string' ? data.value : normalizeInlineValue(input.value));

                if (nextValue !== '') {
                    if (updateType === 'title') {
                        text.textContent = nextValue;
                    } else {
                        text.innerHTML = formatInlineText(nextValue);
                    }
                    input.value = nextValue;
                    input.dataset.committedValue = normalizeInlineValue(nextValue);
                    autoResizeTextarea(input);
                }

                updateSummary(root, data.summary);
                syncReviewState(root.querySelector('[data-ll-dictionary-review-state]'), data);
            };

            const submitEditor = (reason) => {
                if (savePromise) {
                    return savePromise;
                }

                const action = (editor.getAttribute('data-action') || '').toString();
                const entryId = parseInt(editor.getAttribute('data-entry-id') || '0', 10) || 0;
                const editorNonce = (editor.getAttribute('data-nonce') || '').toString();
                const value = normalizeInlineValue(input.value);
                const committedValue = normalizeInlineValue(input.dataset.committedValue || '');
                const emptyMessage = updateType === 'title'
                    ? entryTitleRequiredLabel
                    : entryDefinitionRequiredLabel;

                if (!action || !entryId || !editorNonce) {
                    setStatus(entryErrorLabel, 'error');
                    return Promise.resolve();
                }
                if (!value) {
                    input.value = committedValue;
                    closeEditor(true);
                    setStatus(emptyMessage, 'error');
                    return Promise.resolve();
                }
                if (value === committedValue) {
                    closeEditor(false);
                    setStatus('', '');
                    return Promise.resolve();
                }

                const params = {
                    action: action,
                    entry_id: entryId,
                    nonce: editorNonce,
                    update_type: updateType,
                    wordset_id: root.dataset.wordsetId || '0',
                    gloss_lang: root.dataset.glossLang || '',
                    ll_dictionary_scope: root.dataset.currentScope || 'all',
                };
                if (updateType === 'title') {
                    params.title = value;
                } else {
                    params.value = value;
                    params.sense_index = editor.getAttribute('data-sense-index') || '0';
                    params.language = editor.getAttribute('data-language') || '';
                }

                setSaving(true);
                setStatus(entrySavingLabel, 'saving');

                savePromise = postUpdateRequest(params, reason !== 'manual')
                    .then((payloadResponse) => {
                    if (!payloadResponse || payloadResponse.success !== true || !payloadResponse.data) {
                        throw new Error(readAjaxMessage(payloadResponse, entryErrorLabel));
                    }

                    if (!editor.isConnected) {
                        return;
                    }

                    syncEditor(payloadResponse.data);
                    closeEditor(false);
                    setStatus('', '');
                })
                    .catch((error) => {
                    if (!editor.isConnected) {
                        return;
                    }

                    const message = error && error.message && error.message !== 'request_failed'
                        ? error.message
                        : entryErrorLabel;
                    setStatus(message, 'error');
                })
                    .finally(() => {
                    if (editor.isConnected) {
                        setSaving(false);
                    }
                    savePromise = null;
                });

                return savePromise;
            };

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                if (editor.classList.contains('is-saving') || editor.classList.contains('is-editing')) {
                    return;
                }
                openEditor();
            });

            editor.addEventListener('keydown', (event) => {
                if (event.target !== input) {
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeEditor(true);
                    setStatus('', '');
                    trigger.focus();
                    return;
                }

                if (event.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                    event.preventDefault();
                    input.blur();
                    return;
                }

                if (event.key === 'Enter' && input.tagName === 'TEXTAREA' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    input.blur();
                }
            });

            input.addEventListener('input', () => {
                autoResizeTextarea(input);
            });

            editor.addEventListener('focusout', (event) => {
                if (!editor.classList.contains('is-editing') || editor.classList.contains('is-saving')) {
                    return;
                }

                const nextTarget = event.relatedTarget instanceof Node ? event.relatedTarget : null;
                if (nextTarget && editor.contains(nextTarget)) {
                    return;
                }

                window.setTimeout(() => {
                    if (!editor.isConnected || !editor.classList.contains('is-editing')) {
                        return;
                    }
                    if (editor.contains(document.activeElement)) {
                        return;
                    }

                    submitEditor('blur');
                }, 0);
            });
        });
    };

    const initReviewStates = (root) => {
        root.querySelectorAll('[data-ll-dictionary-review-state]').forEach((reviewState) => {
            if (reviewState.getAttribute('data-ll-dictionary-review-ready') === '1') {
                return;
            }
            reviewState.setAttribute('data-ll-dictionary-review-ready', '1');

            const markButton = reviewState.querySelector('[data-ll-dictionary-review-mark]');
            const clearButton = reviewState.querySelector('[data-ll-dictionary-review-clear]');
            const status = reviewState.querySelector('[data-ll-dictionary-review-status]');

            if (!markButton || !clearButton || !status) {
                return;
            }

            const setStatus = (message, type) => {
                const hasMessage = !!message;
                status.textContent = hasMessage ? String(message) : '';
                status.hidden = !hasMessage;
                status.classList.toggle('is-error', type === 'error');
                status.classList.toggle('is-saving', type === 'saving');
            };

            const setSaving = (saving) => {
                const isSaving = !!saving;
                reviewState.classList.toggle('is-saving', isSaving);
                markButton.disabled = isSaving;
                clearButton.disabled = isSaving;
            };

            const submitReviewState = (needsReview) => {
                const action = (reviewState.getAttribute('data-action') || '').toString();
                const entryId = parseInt(reviewState.getAttribute('data-entry-id') || '0', 10) || 0;
                const editorNonce = (reviewState.getAttribute('data-nonce') || '').toString();
                if (!action || !entryId || !editorNonce) {
                    setStatus(entryErrorLabel, 'error');
                    return;
                }

                setSaving(true);
                setStatus(entrySavingLabel, 'saving');

                postUpdateRequest({
                    action: action,
                    entry_id: entryId,
                    nonce: editorNonce,
                    update_type: 'review',
                    needs_review: needsReview ? '1' : '0',
                    wordset_id: root.dataset.wordsetId || '0',
                    gloss_lang: root.dataset.glossLang || '',
                    ll_dictionary_scope: root.dataset.currentScope || 'all',
                }, true)
                    .then((payloadResponse) => {
                    if (!payloadResponse || payloadResponse.success !== true || !payloadResponse.data) {
                        throw new Error(readAjaxMessage(payloadResponse, entryErrorLabel));
                    }

                    if (!reviewState.isConnected) {
                        return;
                    }

                    syncReviewState(reviewState, payloadResponse.data);
                    updateSummary(root, payloadResponse.data.summary);
                    setStatus('', '');
                })
                    .catch((error) => {
                    if (!reviewState.isConnected) {
                        return;
                    }

                    const message = error && error.message && error.message !== 'request_failed'
                        ? error.message
                        : entryErrorLabel;
                    setStatus(message, 'error');
                })
                    .finally(() => {
                    if (reviewState.isConnected) {
                        setSaving(false);
                    }
                });
            };

            markButton.addEventListener('click', (event) => {
                event.preventDefault();
                submitReviewState(true);
            });

            clearButton.addEventListener('click', (event) => {
                event.preventDefault();
                submitReviewState(false);
            });
        });
    };

    const initInlineControls = (root) => {
        initInlineEditors(root);
        initReviewStates(root);
    };

    document.querySelectorAll('[data-ll-dictionary-root]').forEach((root) => {
        initInlineControls(root);

        const form = root.querySelector('[data-ll-dictionary-form]');
        const results = root.querySelector('[data-ll-dictionary-results]');
        const toolbar = root.querySelector('.ll-dictionary__toolbar');
        const searchInput = form ? form.querySelector('input[name="ll_dictionary_q"]') : null;
        const scopeInputs = form ? Array.from(form.querySelectorAll('input[name="ll_dictionary_scope[]"]')) : [];
        const letterInput = form ? form.querySelector('input[name="ll_dictionary_letter"]') : null;
        const toolbarPanel = root.querySelector('[data-ll-dictionary-toolbar-panel]');
        const toolbarDeferred = root.getAttribute('data-ll-dictionary-toolbar-deferred') === '1';
        const hasExplicitScope = root.getAttribute('data-ll-dictionary-has-explicit-scope') === '1';

        if (!form || !results || !toolbar || !searchInput || !scopeInputs.length || !letterInput) {
            return;
        }

        let debounceTimer = 0;
        let activeController = null;
        let activeRequestId = 0;
        let toolbarBootstrapPromise = null;
        let toolbarReady = !toolbarDeferred;
        const responseCache = new Map();
        const storageKey = `llDictionaryScopePrefs:${root.dataset.wordsetId || '0'}`;
        let scopePreferencesRestored = false;
        let hasScrolledForSearchQuery = false;

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

        const getNamedFields = (name) => Array.from(form.elements || [])
            .filter((field) => field && (field.name === name || field.name === `${name}[]`));

        const escapeRegExp = (value) => String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        const splitFieldValues = (value, fields) => {
            const knownValues = Array.from(fields || [])
                .map((field) => String(field && field.value ? field.value : '').trim())
                .filter((fieldValue, index, values) => fieldValue !== '' && values.indexOf(fieldValue) === index);
            const resolved = [];
            const addValue = (fieldValue) => {
                fieldValue = String(fieldValue || '').trim();
                if (fieldValue !== '' && resolved.indexOf(fieldValue) === -1) {
                    resolved.push(fieldValue);
                }
            };

            String(value || '')
                .split(/[\s,|]+/)
                .map((part) => part.trim())
                .filter((part) => part !== '')
                .forEach((part) => {
                    if (part.indexOf('_') === -1 || !knownValues.length) {
                        addValue(part);
                        return;
                    }

                    let remaining = part;
                    knownValues.forEach((knownValue) => {
                        const pattern = new RegExp(`(?:^|_)${escapeRegExp(knownValue)}(?=_|$)`);
                        if (!pattern.test(part)) {
                            return;
                        }

                        addValue(knownValue);
                        remaining = remaining.replace(pattern, '_').replace(/^_+|_+$/g, '');
                    });

                    if (remaining !== '') {
                        addValue(remaining);
                    }
                });

            return resolved;
        };

        const getFieldValue = (name) => {
            const fields = getNamedFields(name);
            if (!fields.length) {
                return '';
            }

            const values = [];
            fields.forEach((field) => {
                if (!field) {
                    return;
                }
                if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                    return;
                }
                if (field.tagName === 'SELECT' && field.multiple) {
                    Array.from(field.selectedOptions || []).forEach((option) => {
                        values.push(String(option.value || '').trim());
                    });
                    return;
                }
                if ('value' in field) {
                    values.push(String(field.value || '').trim());
                }
            });

            return values.filter((value, index) => value !== '' && values.indexOf(value) === index).join(',');
        };

        const setFieldValue = (name, value) => {
            const fields = getNamedFields(name);
            if (!fields.length) {
                return;
            }

            const values = splitFieldValues(value, fields);
            fields.forEach((field) => {
                if (!field) {
                    return;
                }
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = values.indexOf(String(field.value || '').trim()) !== -1;
                    return;
                }
                if (field.tagName === 'SELECT' && field.multiple) {
                    Array.from(field.options || []).forEach((option) => {
                        option.selected = values.indexOf(String(option.value || '').trim()) !== -1;
                    });
                    return;
                }
                if ('value' in field) {
                    field.value = value;
                }
            });
        };

        const getAllScopeValues = () => scopeInputs
            .map((input) => String(input.value || '').trim())
            .filter((value, index, values) => value !== '' && values.indexOf(value) === index);

        const getSelectedScopeValues = () => {
            const selected = scopeInputs
                .filter((input) => !!input.checked)
                .map((input) => String(input.value || '').trim())
                .filter((value, index, values) => value !== '' && values.indexOf(value) === index);

            return selected.length ? selected : getAllScopeValues();
        };

        const getScopeQueryValue = () => {
            const allScopes = getAllScopeValues();
            const selectedScopes = getSelectedScopeValues();
            if (!allScopes.length || selectedScopes.length >= allScopes.length) {
                return 'all';
            }

            return selectedScopes.join(',');
        };

        const setScopeValuesFromQueryValue = (rawValue) => {
            const allScopes = getAllScopeValues();
            const raw = String(rawValue || '').trim();
            let selectedScopes = [];

            if (!raw || raw === 'all') {
                selectedScopes = allScopes;
            } else {
                selectedScopes = raw
                    .split(/[\s,|]+/)
                    .map((value) => value.trim())
                    .filter((value, index, values) => value !== '' && values.indexOf(value) === index);
            }

            if (!selectedScopes.length) {
                selectedScopes = allScopes;
            }

            scopeInputs.forEach((input) => {
                input.checked = selectedScopes.indexOf(String(input.value || '').trim()) !== -1;
            });
        };

        const getScopeQueryValueFromUrl = (url) => {
            if (!url || !url.searchParams) {
                return 'all';
            }

            const scalarValue = String(url.searchParams.get('ll_dictionary_scope') || '').trim();
            if (scalarValue) {
                return scalarValue;
            }

            const indexedValues = [];
            url.searchParams.forEach((value, key) => {
                if (key === 'll_dictionary_scope[]' || key.indexOf('ll_dictionary_scope[') === 0) {
                    indexedValues.push(String(value || '').trim());
                }
            });

            return indexedValues.length ? indexedValues.join(',') : 'all';
        };

        const updateCurrentScopeState = () => {
            root.dataset.currentScope = getScopeQueryValue();
        };

        const revealScopeOptions = () => {
            toolbar.classList.add('is-scope-visible');
        };

        const persistScopePreferences = () => {
            updateCurrentScopeState();
            if (!window.localStorage) {
                return;
            }

            try {
                if (root.dataset.currentScope && root.dataset.currentScope !== 'all') {
                    window.localStorage.setItem(storageKey, root.dataset.currentScope);
                } else {
                    window.localStorage.removeItem(storageKey);
                }
            } catch (error) {
                // Ignore storage failures and keep the in-memory scope state.
            }
        };

        const restoreStoredScopePreferences = () => {
            updateCurrentScopeState();
            if (hasExplicitScope || !window.localStorage) {
                return false;
            }

            let storedValue = '';
            try {
                storedValue = String(window.localStorage.getItem(storageKey) || '').trim();
            } catch (error) {
                storedValue = '';
            }

            if (!storedValue || storedValue === 'all') {
                return false;
            }

            const currentScope = root.dataset.currentScope || 'all';
            setScopeValuesFromQueryValue(storedValue);
            updateCurrentScopeState();

            return (root.dataset.currentScope || 'all') !== currentScope;
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
            const shouldStayExpanded = active || toolbar.classList.contains('is-scope-visible');
            toolbar.classList.toggle('is-expanded', shouldStayExpanded);
            toolbar.classList.toggle('is-collapsed', !shouldStayExpanded);
        };

        const buildToolbarBootstrapPayload = () => {
            const payload = new FormData();
            payload.set('action', 'll_tools_dictionary_toolbar_bootstrap');
            payload.set('nonce', nonce);
            payload.set('wordset_id', root.dataset.wordsetId || '0');
            payload.set('base_url', root.dataset.baseUrl || window.location.href);
            payload.set('ll_dictionary_scope', getScopeQueryValue());
            payload.set('ll_dictionary_letter', String(letterInput.value || '').trim());
            payload.set('ll_dictionary_pos', getFieldValue('ll_dictionary_pos'));
            payload.set('ll_dictionary_source', getFieldValue('ll_dictionary_source'));
            payload.set('ll_dictionary_dialect', getFieldValue('ll_dictionary_dialect'));
            return payload;
        };

        const setToolbarBootstrapState = (loading) => {
            toolbar.classList.toggle('is-toolbar-loading', !!loading);
            if (toolbarPanel) {
                if (loading) {
                    toolbarPanel.setAttribute('aria-busy', 'true');
                } else {
                    toolbarPanel.removeAttribute('aria-busy');
                }
            }
        };

        const ensureToolbarBootstrap = () => {
            if (!toolbarDeferred || toolbarReady || !toolbarPanel) {
                return Promise.resolve(toolbarReady);
            }

            if (toolbarBootstrapPromise) {
                return toolbarBootstrapPromise;
            }

            setToolbarBootstrapState(true);
            toolbarBootstrapPromise = fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: buildToolbarBootstrapPayload(),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('request_failed');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error('invalid_payload');
                    }

                    toolbarPanel.outerHTML = typeof payload.data.html === 'string' ? payload.data.html : '';
                    toolbarReady = true;
                    root.setAttribute('data-ll-dictionary-toolbar-deferred', '0');
                    toolbar.classList.remove('is-toolbar-loading');
                    return true;
                })
                .catch((error) => {
                    toolbar.classList.remove('is-toolbar-loading');
                    if (toolbarPanel) {
                        toolbarPanel.setAttribute('aria-label', toolbarLoadingLabel);
                    }
                    throw error;
                })
                .finally(() => {
                    setToolbarBootstrapState(false);
                    toolbarBootstrapPromise = null;
                });

            return toolbarBootstrapPromise;
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
            initInlineControls(root);
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

        scopePreferencesRestored = restoreStoredScopePreferences();

        const buildRequestCacheKey = (page) => JSON.stringify({
            wordsetId: root.dataset.wordsetId || '0',
            perPage: root.dataset.perPage || '20',
            senseLimit: root.dataset.senseLimit || '3',
            linkedWordLimit: root.dataset.linkedWordLimit || '4',
            glossLang: root.dataset.glossLang || '',
            query: String(searchInput.value || '').trim(),
            scope: getScopeQueryValue(),
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
            payload.set('ll_dictionary_scope', getScopeQueryValue());
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

        const getCurrentPageFromLocation = () => {
            try {
                return Number(new URL(window.location.href).searchParams.get('ll_dictionary_page') || '1');
            } catch (error) {
                return 1;
            }
        };

        const scrollResultsPreviewIntoView = () => {
            const query = String(searchInput.value || '').trim();
            if (query === '' || hasScrolledForSearchQuery || typeof window.scrollTo !== 'function') {
                if (query === '') {
                    hasScrolledForSearchQuery = false;
                }
                return;
            }

            hasScrolledForSearchQuery = true;
            window.requestAnimationFrame(() => {
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                const searchRect = searchInput.getBoundingClientRect();
                if (!viewportHeight || !searchRect || typeof searchRect.top !== 'number') {
                    return;
                }

                const currentScrollTop = Math.max(0, window.scrollY || window.pageYOffset || 0);
                const desiredSearchTop = Math.max(20, Math.min(96, Math.round(viewportHeight * 0.16)));
                const targetTop = Math.max(currentScrollTop, currentScrollTop + searchRect.top - desiredSearchTop);

                if (Math.abs(targetTop - currentScrollTop) < 8) {
                    return;
                }

                const prefersReducedMotion = typeof window.matchMedia === 'function'
                    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                window.scrollTo({
                    top: targetTop,
                    behavior: prefersReducedMotion ? 'auto' : 'smooth',
                });
            });
        };

        const primeToolbarBootstrap = () => {
            ensureToolbarBootstrap().catch(() => {});
        };

        searchInput.addEventListener('focus', () => {
            revealScopeOptions();
            primeToolbarBootstrap();
        }, { passive: true });
        searchInput.addEventListener('pointerdown', () => {
            revealScopeOptions();
            primeToolbarBootstrap();
        }, { passive: true });
        scopeInputs.forEach((scopeInput) => {
            scopeInput.addEventListener('focus', primeToolbarBootstrap, { passive: true });
            scopeInput.addEventListener('pointerdown', primeToolbarBootstrap, { passive: true });
        });

        searchInput.addEventListener('input', () => {
            revealScopeOptions();
            primeToolbarBootstrap();
            if (String(searchInput.value || '').trim() !== '') {
                letterInput.value = '';
            } else {
                hasScrolledForSearchQuery = false;
            }

            if (!canRunQuery()) {
                clearResults();
                return;
            }

            showLoadingState();
            scrollResultsPreviewIntoView();
            scheduleLiveSearch();
        });

        form.addEventListener('change', (event) => {
            const target = event.target;
            const name = target && target.name ? String(target.name) : '';
            if (['ll_dictionary_scope[]', 'll_dictionary_pos', 'll_dictionary_source', 'll_dictionary_source[]', 'll_dictionary_dialect'].indexOf(name) === -1) {
                return;
            }

            if (name === 'll_dictionary_scope[]') {
                persistScopePreferences();
            } else {
                updateCurrentScopeState();
            }

            if (!canRunQuery()) {
                clearResults();
                return;
            }

            showLoadingState();
            triggerLiveSearch(1);
        });

        form.addEventListener('submit', (event) => {
            primeToolbarBootstrap();
            if (!hasActiveQuery()) {
                return;
            }

            event.preventDefault();
            revealScopeOptions();
            if (String(searchInput.value || '').trim() !== '') {
                scrollResultsPreviewIntoView();
            }
            showLoadingState();
            requestResults(1, true);
        });

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
            setScopeValuesFromQueryValue(getScopeQueryValueFromUrl(url));
            persistScopePreferences();
            letterInput.value = url.searchParams.get('ll_dictionary_letter') || '';
            setFieldValue('ll_dictionary_pos', url.searchParams.get('ll_dictionary_pos') || '');
            setFieldValue('ll_dictionary_source', url.searchParams.get('ll_dictionary_source') || '');
            setFieldValue('ll_dictionary_dialect', url.searchParams.get('ll_dictionary_dialect') || '');

            showLoadingState();
            requestResults(Number(url.searchParams.get('ll_dictionary_page') || '1'), false);
        });

        if (scopePreferencesRestored && hasActiveQuery()) {
            showLoadingState();
            requestResults(getCurrentPageFromLocation(), false);
        } else {
            updateCurrentScopeState();
        }

        if (hasActiveQuery() || hasExplicitScope) {
            revealScopeOptions();
        }
    });
}());
