(function () {
    'use strict';
    function init() {
        var input = document.getElementById('ll-content-lesson-cloze-source');
        var kind = document.getElementById('ll-content-lesson-kind');
        if (!input || !kind) { return; }
        var config = window.llToolsClozeAdmin || {};
        var strings = config.i18n || {};
        var status = document.querySelector('[data-ll-cloze-save-status]');
        var retry = document.querySelector('[data-ll-cloze-save-retry]');
        var hashInput = document.querySelector('[name="ll_content_lesson_cloze_hash"]');
        var form = input.closest('form');
        var acknowledged = input.value;
        var hash = input.dataset.sourceHash;
        var timer;
        var saving = false;
        var blocked = false;
        var revision = 0;
        var unknown = null;
        var pendingSubmit = null;
        var submitReady = false;
        function message(value) { status.textContent = String(value || ''); }
        function valid() {
            var value = input.value;
            var found = value.match(/\[\[([^\[\]\r\n]+)\]\]/g) || [];
            var rest = value.replace(/\[\[([^\[\]\r\n]+)\]\]/g, '');
            return found.length > 0 && found.length <= 60 && !rest.includes('[[') && !rest.includes(']]')
                && !found.some(function (match) { var answer = match.slice(2, -2); return !answer.trim() || answer.trim() !== answer || new TextEncoder().encode(answer).length > 300; })
                && new TextEncoder().encode(value).length <= 20000;
        }
        function canSave() {
            var wordset = document.getElementById('ll-content-lesson-wordset');
            return kind.value === 'cloze' && input.dataset.savedKind === 'cloze' && wordset && Number(wordset.value) > 0;
        }
        function resumeSubmit() {
            if (!pendingSubmit || saving || unknown || blocked) { return; }
            if (canSave() && acknowledged !== input.value) {
                if (valid()) { save(false); } else { message(strings.invalid); }
                return;
            }
            var requested = pendingSubmit;
            pendingSubmit = null;
            submitReady = true;
            form.requestSubmit(requested.button || undefined);
            submitReady = false;
        }
        function save(explicitRetry) {
            if (saving || blocked || (unknown && !explicitRetry)) { return; }
            if (!unknown && (acknowledged === input.value || !canSave() || !valid())) { resumeSubmit(); return; }
            // An ambiguous request is retried verbatim to recover its hash before saving a newer draft.
            var attempt = unknown || { source: input.value, hash: hash };
            var sentRevision = revision;
            var body = new URLSearchParams();
            body.set('action', 'll_tools_cloze_save'); body.set('nonce', config.nonce || '');
            body.set('lesson_id', input.dataset.lessonId); body.set('source', attempt.source); body.set('previous_hash', attempt.hash);
            saving = true; retry.hidden = true; message(strings.saving);
            var controller = new AbortController();
            var deadline = window.setTimeout(function () { controller.abort(); }, 15000);
            var succeeded = false;
            var knownResponse = false;
            fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal })
                .then(function (response) {
                    if (response.status === 409) { blocked = true; }
                    return response.json();
                }).then(function (response) {
                    if (response && response.success === false) { knownResponse = true; }
                    if (!response || !response.success || !response.data || !response.data.hash) {
                        var error = new Error(); error.userMessage = response && response.data && response.data.message; throw error;
                    }
                    succeeded = true; unknown = null;
                    acknowledged = attempt.source; hash = response.data.hash; input.dataset.sourceHash = hash;
                    if (hashInput) { hashInput.value = hash; }
                    if (revision === sentRevision && acknowledged === input.value) { message(strings.saved + ' ' + String(strings.valid || '').replace('%d', response.data.count)); }
                }).catch(function (error) {
                    unknown = knownResponse ? null : attempt;
                    message(error.userMessage || strings.error); retry.hidden = blocked;
                }).finally(function () {
                    window.clearTimeout(deadline); saving = false;
                    if (!succeeded || blocked) { return; }
                    if (pendingSubmit) { resumeSubmit(); }
                    else if (acknowledged !== input.value) {
                        window.clearTimeout(timer); timer = window.setTimeout(function () { save(false); }, 700);
                    }
                });
        }
        input.addEventListener('input', function () {
            revision += 1; window.clearTimeout(timer); retry.hidden = true;
            if (unknown || blocked) { message(strings.error); retry.hidden = blocked; return; }
            if (!valid()) { message(strings.invalid); return; }
            if (!canSave()) { message(strings.pending); return; }
            message(strings.saving); timer = window.setTimeout(function () { save(false); }, 700);
        });
        retry.addEventListener('click', function () { save(true); });
        window.addEventListener('beforeunload', function (event) {
            if (unknown || saving || (canSave() && input.value !== acknowledged)) { event.preventDefault(); event.returnValue = ''; }
        });
        if (form) {
            form.addEventListener('submit', function (event) {
                window.clearTimeout(timer);
                if (submitReady) { return; }
                if (!saving && !unknown && (!canSave() || input.value === acknowledged)) { return; }
                event.preventDefault();
                pendingSubmit = { button: event.submitter || null };
                if (!valid() && canSave()) { message(strings.invalid); return; }
                save(true);
            });
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
