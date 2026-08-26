(function () {
    'use strict';

    const config = window.llToolsTextDocumentReviewNotes || {};
    const ajaxUrl = config.ajaxUrl || '';
    const action = config.action || 'll_tools_save_text_document_review_note';
    const nonce = config.nonce || '';
    const messages = config.i18n || {};
    const saveDelayMs = 700;
    const configuredTimeoutMs = parseInt(config.requestTimeoutMs || '12000', 10);
    const requestTimeoutMs = Math.max(50, Math.min(60000, Number.isFinite(configuredTimeoutMs) ? configuredTimeoutMs : 12000));
    const noteStates = [];

    if (!ajaxUrl || !nonce) {
        return;
    }

    function message(key) {
        if (!messages || typeof messages[key] !== 'string') {
            return '';
        }
        return messages[key];
    }

    function setStatus(wrapper, text, state) {
        const status = wrapper.querySelector('[data-ll-text-document-review-note-status]');
        if (!status) {
            return;
        }
        status.textContent = text || '';
        status.classList.remove('is-saving', 'is-success', 'is-error');
        if (state) {
            status.classList.add('is-' + state);
        }
    }

    function responseError(response) {
        if (response && response.data && typeof response.data.message === 'string') {
            return response.data.message;
        }
        return message('error');
    }

    function saveNote(wrapper, state) {
        const input = wrapper.querySelector('[data-ll-text-document-review-note-input]');
        if (!input) {
            return;
        }
        if (state.inFlight) {
            state.queued = true;
            return;
        }

        const lessonId = parseInt(wrapper.getAttribute('data-lesson-id') || '0', 10);
        const noteKey = wrapper.getAttribute('data-note-key') || 'document';
        const pendingValue = input.value || '';
        const baseValue = input.dataset.originalValue || '';
        if (!lessonId || baseValue === pendingValue) {
            return;
        }

        state.inFlight = true;
        state.queued = false;
        state.blockedByConflict = false;
        state.generation += 1;
        const generation = state.generation;
        wrapper.classList.add('is-saving');
        setStatus(wrapper, message('saving'), 'saving');

        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        body.set('lesson_id', String(lessonId));
        body.set('note_key', noteKey);
        body.set('note', pendingValue);
        body.set('base_note', baseValue);

        const controller = typeof window.AbortController === 'function'
            ? new window.AbortController()
            : null;
        let timeoutId = null;
        const timeoutPromise = new Promise(function (_resolve, reject) {
            timeoutId = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
                const error = new Error('ll_tools_review_note_timeout');
                error.code = 'll_tools_review_note_timeout';
                reject(error);
            }, requestTimeoutMs);
        });
        const requestPromise = window.fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            return res.json().catch(function () {
                return null;
            });
        });

        Promise.race([requestPromise, timeoutPromise]).then(function (data) {
            if (state.generation !== generation) {
                return;
            }
            if (!data || data.success !== true) {
                const errorCode = data && data.data && typeof data.data.code === 'string'
                    ? data.data.code
                    : '';
                if (errorCode === 'll_tools_text_document_review_note_conflict') {
                    state.blockedByConflict = true;
                }
                setStatus(wrapper, responseError(data), 'error');
                return;
            }

            const savedNote = data.data && typeof data.data.note === 'string'
                ? data.data.note
                : pendingValue;
            const currentValue = input.value || '';
            input.dataset.originalValue = savedNote;
            if (currentValue === pendingValue || currentValue === savedNote) {
                // The server sanitizes review notes (including trimming outer
                // whitespace). When the editor has not changed since this
                // request was sent, reconcile it to the authoritative value
                // instead of mistaking normalization for a newer local edit
                // and resubmitting forever.
                if (currentValue === pendingValue && currentValue !== savedNote) {
                    input.value = savedNote;
                }
                setStatus(wrapper, message('saved'), 'success');
                window.clearTimeout(state.statusTimer);
                state.statusTimer = window.setTimeout(function () {
                    if (state.generation === generation && !state.inFlight && (input.value || '') === (input.dataset.originalValue || '')) {
                        setStatus(wrapper, '', '');
                    }
                }, 1800);
            } else {
                state.queued = true;
                setStatus(wrapper, message('saving'), 'saving');
            }
        }).catch(function (error) {
            if (state.generation !== generation) {
                return;
            }
            const timedOut = error && error.code === 'll_tools_review_note_timeout';
            setStatus(wrapper, timedOut ? (message('timeout') || message('error')) : message('error'), 'error');
            if ((input.value || '') !== pendingValue) {
                state.queued = true;
            }
        }).finally(function () {
            window.clearTimeout(timeoutId);
            if (state.generation !== generation) {
                return;
            }
            state.inFlight = false;
            wrapper.classList.remove('is-saving');
            if (state.queued && !state.blockedByConflict && (input.value || '') !== (input.dataset.originalValue || '')) {
                saveNote(wrapper, state);
            }
        });
    }

    function initNote(wrapper) {
        const input = wrapper.querySelector('[data-ll-text-document-review-note-input]');
        if (!input) {
            return;
        }
        let timer = null;
        const state = {
            input: input,
            inFlight: false,
            queued: false,
            blockedByConflict: false,
            generation: 0,
            statusTimer: null
        };
        noteStates.push(state);
        input.dataset.originalValue = input.value || '';

        function requestSave() {
            if (state.blockedByConflict) {
                return;
            }
            if (state.inFlight) {
                state.queued = true;
                return;
            }
            saveNote(wrapper, state);
        }

        function scheduleSave() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                requestSave();
            }, saveDelayMs);
        }

        input.addEventListener('input', scheduleSave);
        input.addEventListener('change', function () {
            window.clearTimeout(timer);
            requestSave();
        });
        input.addEventListener('blur', function () {
            window.clearTimeout(timer);
            requestSave();
        });
    }

    document.querySelectorAll('[data-ll-text-document-review-note]').forEach(initNote);

    window.addEventListener('beforeunload', function (event) {
        const hasUnsavedNote = noteStates.some(function (state) {
            return state.inFlight
                || (state.input.value || '') !== (state.input.dataset.originalValue || '');
        });
        if (!hasUnsavedNote) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
}());
