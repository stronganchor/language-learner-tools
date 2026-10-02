(function () {
    'use strict';
    const cfg = window.llToolsAssignmentPlayer;
    const root = document.getElementById('ll-assignment-player');
    if (!cfg || !root) { return; }
    const messages = cfg.messages;
    const status = root.querySelector('.ll-assignment-player__status');
    const content = root.querySelector('.ll-assignment-player__content');
    const title = root.querySelector('.ll-assignment-player__title');
    let state = null;
    let busy = false;

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, (n) => n.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }
    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        content.querySelectorAll('button,input').forEach((el) => { el.disabled = value; });
    }
    async function request(path, method = 'GET', body) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 30000);
        try {
            const options = { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, signal: controller.signal };
            if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
            const response = await fetch(cfg.apiRoot + path, options);
            const data = await response.json();
            if (!response.ok) { const error = new Error(data.message || messages.failed); error.code = data.code || ''; throw error; }
            return data;
        } finally { window.clearTimeout(timer); }
    }
    function button(text, callback) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'll-assignment-player__button';
        el.textContent = text;
        el.addEventListener('click', () => { if (!busy) { callback(); } });
        return el;
    }
    function failure(error, retry) {
        setBusy(false);
        status.textContent = error && error.message ? error.message : messages.failed;
        const recovery = document.createElement('div');
        recovery.className = 'll-assignment-player__recovery';
        recovery.appendChild(button(messages.retry, () => { recovery.remove(); retry(); }));
        content.querySelectorAll('.ll-assignment-player__recovery').forEach((el) => el.remove());
        content.appendChild(recovery);
    }
    function media(presentation, container) {
        if (presentation.text) {
            const text = document.createElement('p');
            text.className = 'll-assignment-player__text';
            text.dir = 'auto';
            text.textContent = presentation.text;
            container.appendChild(text);
        }
        if (presentation.image) {
            const img = document.createElement('img');
            img.src = presentation.image;
            img.alt = '';
            container.appendChild(img);
        }
        if (presentation.audio) {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = presentation.audio;
            audio.setAttribute('aria-label', messages.audio);
            container.appendChild(audio);
        }
    }
    async function load() {
        setBusy(true);
        status.textContent = messages.loading;
        try { state = await request('assignments/' + cfg.assignmentUuid + '/player-state'); setBusy(false); render(); }
        catch (error) { failure(error, load); }
    }
    async function start() {
        setBusy(true);
        status.textContent = messages.loading;
        try { await request('assignments/' + cfg.assignmentUuid + '/attempts', 'POST', {}); await load(); }
        catch (error) { failure(error, load); }
    }
    async function finish() {
        setBusy(true);
        status.textContent = messages.saving;
        try { await request('attempts/' + state.attempt.attempt_uuid + '/finalize', 'POST', {}); await load(); }
        catch (error) { failure(error, finish); }
    }
    function render() {
        content.replaceChildren();
        status.textContent = state.window_message || '';
        title.textContent = state.assignment.title;
        const attempt = state.attempt;
        if (!attempt) { if (state.can_start) { content.appendChild(button(messages.start, start)); } return; }
        if (attempt.status === 'finalized') {
            const result = document.createElement('div');
            result.className = 'll-assignment-player__result';
            const score = document.createElement('p');
            score.textContent = messages.score + ': ' + attempt.score_given + ' / ' + attempt.score_maximum;
            result.appendChild(score);
            if (state.grade) { const grade = document.createElement('p'); grade.textContent = messages.grade + ': ' + Number(state.grade.points_given) + ' / ' + Number(state.grade.points_maximum); result.appendChild(grade); }
            content.appendChild(result);
            if (state.can_start) { content.appendChild(button(messages.retake, start)); }
            return;
        }
        const expires = Date.parse(attempt.expires_at.replace(' ', 'T') + 'Z');
        if (Number.isFinite(expires) && expires <= Date.now()) { status.textContent = messages.expired; if (state.can_start) { content.appendChild(button(messages.retake, start)); } return; }
        const answered = new Set(state.answers.map((answer) => answer.item_key));
        const item = state.manifest.items.find((entry) => !answered.has(entry.key));
        if (!item) { content.appendChild(button(messages.finish, finish)); return; }
        const number = document.createElement('p');
        number.textContent = messages.question + ' ' + (answered.size + 1) + ' ' + messages.of + ' ' + state.manifest.items.length;
        content.appendChild(number);
        const prompt = document.createElement('div');
        prompt.className = 'll-assignment-player__prompt';
        media(item.prompt, prompt);
        content.appendChild(prompt);
        const choices = document.createElement('fieldset');
        choices.className = 'll-assignment-player__choices';
        choices.setAttribute('aria-label', messages.choose);
        item.options.forEach((option) => {
            const label = document.createElement('label');
            label.className = 'll-assignment-player__choice';
            const input = document.createElement('input');
            input.type = 'radio'; input.name = 'll-assignment-answer'; input.value = option.key;
            const presentation = document.createElement('div');
            presentation.className = 'll-assignment-player__choice-media';
            media(option.presentation, presentation);
            label.append(input, presentation); choices.appendChild(label);
        });
        content.appendChild(choices);
        const save = button(messages.submit, () => {
            const selected = choices.querySelector('input:checked');
            if (!selected) { status.textContent = messages.choose; return; }
            const payload = { answer_uuid: uuid(), item_key: item.key, option_key: selected.value };
            async function submit() {
                setBusy(true); status.textContent = messages.saving;
                try {
                    await request('attempts/' + attempt.attempt_uuid + '/answers', 'POST', payload);
                    await load();
                } catch (error) {
                    // Keep the original UUID and selection while an answer response
                    // is ambiguous. A retry cannot change a saved first answer.
                    failure(error, submit);
                    choices.querySelectorAll('input').forEach((el) => { el.disabled = true; });
                    save.disabled = true;
                }
            }
            submit();
        });
        content.appendChild(save);
    }
    load();
})();
