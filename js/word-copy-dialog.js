(function () {
    'use strict';
    const cfg = window.llWordCopy;
    if (!cfg) return;
    let dialog, body, message, submit, cancel, more, titleInput, list, opener;
    let sourceId = 0, requestId = '', afterId = 0, busy = false, generation = 0, controller;
    let mode = 'preview', intent = null;
    const node = (tag, text, className) => {
        const el = document.createElement(tag);
        if (text) el.textContent = text;
        if (className) el.className = className;
        return el;
    };
    const key = () => 'll-word-copy:' + cfg.userId + ':' + cfg.wordsetId + ':' + sourceId;
    function remember(value) {
        try {
            if (!value) { sessionStorage.removeItem(key()); return true; }
            const encoded = JSON.stringify(value);
            sessionStorage.setItem(key(), encoded);
            return sessionStorage.getItem(key()) === encoded;
        } catch (_) { return false; }
    }
    function setBusy(value, mutation) {
        busy = value;
        dialog.setAttribute('aria-busy', String(value));
        submit.disabled = value;
        more.disabled = value;
        cancel.disabled = value && mutation;
        titleInput.disabled = value || mode !== 'preview';
        list.querySelectorAll('input').forEach(el => { el.disabled = value || mode !== 'preview'; });
    }
    function showMessage(text) { message.textContent = text || ''; }
    async function send(action, values = {}) {
        const ownedController = new AbortController(); controller = ownedController;
        const timeout = window.setTimeout(() => ownedController.abort(), Number(cfg.timeoutMs) || 30000);
        const data = new URLSearchParams({ action, nonce: cfg.nonce, wordset_id: cfg.wordsetId, word_id: sourceId, request_id: requestId });
        Object.keys(values).forEach(name => {
            const value = values[name];
            if (Array.isArray(value)) value.forEach(item => data.append(name + '[]', item));
            else data.set(name, value);
        });
        try {
            const response = await fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', signal: ownedController.signal });
            const payload = JSON.parse(await response.text());
            if (!response.ok || !payload.success) throw new Error(payload.data?.message || cfg.failed);
            return payload.data;
        } finally { window.clearTimeout(timeout); }
    }
    function build() {
        dialog = node('dialog', '', 'll-word-copy-dialog');
        dialog.setAttribute('aria-labelledby', 'll-word-copy-heading');
        const heading = node('h2', cfg.heading); heading.id = 'll-word-copy-heading';
        body = node('div', '', 'll-word-copy-body');
        const label = node('label', cfg.title); label.htmlFor = 'll-word-copy-title';
        titleInput = node('input'); titleInput.id = 'll-word-copy-title'; titleInput.type = 'text'; titleInput.maxLength = 250;
        const hint = node('p', cfg.hint, 'll-word-copy-hint');
        list = node('div', '', 'll-word-copy-recordings');
        list.setAttribute('role', 'group'); list.setAttribute('aria-label', cfg.recordings);
        more = node('button', cfg.more, 'll-word-copy-button'); more.type = 'button'; more.hidden = true;
        more.addEventListener('click', () => loadPreview(true));
        body.append(label, titleInput, hint, node('h3', cfg.recordings), list, more);
        message = node('p', '', 'll-word-copy-message'); message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite');
        const actions = node('div', '', 'll-word-copy-actions');
        cancel = node('button', cfg.cancel, 'll-word-copy-button'); cancel.type = 'button';
        submit = node('button', cfg.submit, 'll-word-copy-button ll-word-copy-button--primary'); submit.type = 'button';
        cancel.addEventListener('click', close);
        submit.addEventListener('click', () => {
            if (mode === 'uncertain') checkResult();
            else if (mode === 'load_failed') loadPreview(false);
            else if (mode === 'completed') close();
            else apply();
        });
        dialog.addEventListener('cancel', event => { event.preventDefault(); if (!cancel.disabled) close(); });
        dialog.addEventListener('click', event => { if (event.target === dialog && !cancel.disabled) close(); });
        actions.append(cancel, submit); dialog.append(heading, body, message, actions); document.body.append(dialog);
    }
    function close() {
        if (cancel.disabled) return;
        generation++;
        if (controller) controller.abort();
        dialog.querySelectorAll('audio').forEach(el => el.pause());
        dialog.close(); opener?.focus();
    }
    async function loadPreview(append) {
        if (busy) return;
        const own = generation;
        setBusy(true, false); showMessage(cfg.loading);
        try {
            const data = await send('ll_tools_word_copy_preview', { after_id: append ? afterId : 0 });
            if (own !== generation) return;
            if (!append) { titleInput.value = data.title; list.replaceChildren(); afterId = 0; }
            data.recordings.forEach(recording => {
                const row = node('div', '', 'll-word-copy-recording');
                const label = node('label'); const input = node('input'); input.type = 'checkbox'; input.value = recording.id;
                input.addEventListener('change', () => {
                    if (list.querySelectorAll('input:checked').length > 50) { input.checked = false; showMessage(cfg.limit); }
                });
                label.append(input, node('span', recording.title + ' · ' + recording.status)); row.append(label);
                if (recording.url) { const audio = node('audio'); audio.controls = true; audio.preload = 'none'; audio.src = recording.url; row.append(audio); }
                list.append(row);
            });
            afterId = data.after_id; more.hidden = !data.has_more;
            mode = 'preview'; submit.textContent = cfg.submit; showMessage(list.children.length ? '' : cfg.empty);
        } catch (error) {
            if (own !== generation) return;
            showMessage(error.message || cfg.failed);
            if (!append) { mode = 'load_failed'; submit.textContent = cfg.retry; }
        } finally { if (own === generation) { setBusy(false, false); if (!append && mode === 'preview') titleInput.focus(); } }
    }
    function finished(data) {
        more.hidden = true;
        if (data.state !== 'completed') {
            mode = 'uncertain'; submit.textContent = cfg.check;
            showMessage(data.message || cfg.uncertain);
        } else {
            mode = 'completed'; remember(null); submit.textContent = cfg.close; showMessage(data.message);
            const sourceRow = opener.closest('[data-ll-wordset-editor-row]');
            if (sourceRow) {
                data.moved_ids.forEach(id => sourceRow.querySelector('[data-recording-id="' + Number(id) + '"]')?.remove());
                const status = sourceRow.querySelector('.ll-wordset-editor-cell--state .ll-wordset-editor-state');
                if (status) { status.textContent = data.source_status_label; status.className = 'll-wordset-editor-state ll-wordset-editor-state--' + data.source_status; }
                const audioCount = sourceRow.querySelector('[data-ll-word-copy-audio-count]');
                if (audioCount) {
                    audioCount.textContent = String(data.source_audio_count);
                    audioCount.parentElement.title = data.source_audio_label;
                    audioCount.parentElement.classList.toggle('is-ready', data.source_audio_count > 0);
                    audioCount.parentElement.classList.toggle('is-missing', data.source_audio_count === 0);
                }
                const resultLink = node('a', cfg.open + ': ' + data.title, 'll-word-copy-result'); resultLink.href = data.url;
                opener.after(resultLink);
            }
        }
        dialog.querySelector('.ll-word-copy-outcome')?.remove();
        if (data.new_word_id && data.url) {
            const outcome = node('div', '', 'll-word-copy-outcome');
            if (data.image?.url) { const img = node('img'); img.src = data.image.url; img.alt = ''; outcome.append(img); }
            const link = node('a', cfg.open + ': ' + data.title + ' · ' + data.status_label); link.href = data.url;
            outcome.append(link); message.after(outcome);
        }
        cancel.textContent = cfg.close;
    }
    async function checkResult() {
        if (busy) return;
        const own = generation; setBusy(true, false); showMessage(cfg.loading);
        try {
            const data = await send('ll_tools_word_copy_status');
            if (own !== generation) return;
            if (data.state === 'not_started') { mode = 'retry_apply'; submit.textContent = cfg.retry; showMessage(cfg.uncertain); }
            else finished(data);
        } catch (_) { if (own === generation) showMessage(cfg.uncertain); }
        finally { if (own === generation) setBusy(false, false); }
    }
    async function apply() {
        if (busy) return;
        if (mode === 'preview') intent = { title: titleInput.value, move_ids: Array.from(list.querySelectorAll('input:checked'), el => Number(el.value)) };
        if (!intent) return;
        // Do not mutate unless a reload/reopen can recover the exact request.
        if (!remember({ requestId, intent })) { showMessage(cfg.storageUnavailable || cfg.failed); return; }
        mode = 'uncertain'; const own = generation; setBusy(true, true); showMessage(cfg.saving);
        try {
            const data = await send('ll_tools_word_copy_apply', intent);
            if (own === generation) finished(data);
        } catch (error) {
            if (own === generation) { showMessage((error.message || cfg.failed) + ' ' + cfg.uncertain); submit.textContent = cfg.check; }
        } finally { if (own === generation) setBusy(false, false); }
    }
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-ll-word-copy]');
        if (!trigger) return;
        event.preventDefault();
        if (dialog?.open) return;
        if (!dialog) build();
        opener = trigger; sourceId = Number(trigger.dataset.wordId); generation++; busy = false; mode = 'preview'; afterId = 0; intent = null;
        requestId = crypto.randomUUID();
        list.replaceChildren(); titleInput.value = ''; more.hidden = true; submit.textContent = cfg.submit; cancel.textContent = cfg.cancel;
        dialog.querySelector('.ll-word-copy-outcome')?.remove();
        let saved;
        try { saved = JSON.parse(sessionStorage.getItem(key()) || 'null'); } catch (_) { mode = 'storage_failed'; }
        if (saved?.requestId) { requestId = saved.requestId; intent = saved.intent; titleInput.value = intent?.title || ''; mode = 'uncertain'; submit.textContent = cfg.check; }
        dialog.showModal();
        if (mode === 'storage_failed') { setBusy(false, false); submit.disabled = true; showMessage(cfg.storageUnavailable || cfg.failed); return; }
        if (mode === 'uncertain') checkResult(); else loadPreview(false);
    });
})();
