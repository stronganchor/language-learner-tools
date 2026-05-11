(function () {
    'use strict';

    const config = window.llToolsTextDocumentReviewNotes || {};
    const ajaxUrl = config.ajaxUrl || '';
    const action = config.action || 'll_tools_save_text_document_review_note';
    const nonce = config.nonce || '';
    const messages = config.i18n || {};
    const saveDelayMs = 700;

    if (!ajaxUrl || !nonce) {
        return;
    }

    function message(key, fallback) {
        return messages[key] || fallback;
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
        return message('error', 'Unable to save the review note.');
    }

    function saveNote(wrapper) {
        const input = wrapper.querySelector('[data-ll-text-document-review-note-input]');
        if (!input) {
            return;
        }

        const lessonId = parseInt(wrapper.getAttribute('data-lesson-id') || '0', 10);
        const noteKey = wrapper.getAttribute('data-note-key') || 'document';
        const note = input.value || '';
        const pendingValue = note;
        if (!lessonId || input.dataset.originalValue === pendingValue) {
            return;
        }

        wrapper.classList.add('is-saving');
        setStatus(wrapper, message('saving', 'Saving review note...'), 'saving');

        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        body.set('lesson_id', String(lessonId));
        body.set('note_key', noteKey);
        body.set('note', pendingValue);

        window.fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                return null;
            });
        }).then(function (data) {
            if (!data || data.success !== true) {
                setStatus(wrapper, responseError(data), 'error');
                return;
            }

            const savedNote = data.data && typeof data.data.note === 'string'
                ? data.data.note
                : pendingValue;
            if ((input.value || '') === pendingValue) {
                input.dataset.originalValue = savedNote;
            }
            setStatus(wrapper, message('saved', 'Review note saved.'), 'success');
            window.setTimeout(function () {
                setStatus(wrapper, '', '');
            }, 1800);
        }).catch(function () {
            setStatus(wrapper, message('error', 'Unable to save the review note.'), 'error');
        }).finally(function () {
            wrapper.classList.remove('is-saving');
        });
    }

    function initNote(wrapper) {
        const input = wrapper.querySelector('[data-ll-text-document-review-note-input]');
        if (!input) {
            return;
        }
        let timer = null;
        input.dataset.originalValue = input.value || '';

        function scheduleSave() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                saveNote(wrapper);
            }, saveDelayMs);
        }

        input.addEventListener('input', scheduleSave);
        input.addEventListener('change', function () {
            window.clearTimeout(timer);
            saveNote(wrapper);
        });
        input.addEventListener('blur', function () {
            window.clearTimeout(timer);
            saveNote(wrapper);
        });
    }

    document.querySelectorAll('[data-ll-text-document-review-note]').forEach(initNote);
}());
