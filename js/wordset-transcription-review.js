(function () {
    'use strict';
    const config = window.llWordsetTranscriptionReview;
    const root = document.querySelector('[data-ll-transcription-review]');
    if (!config || !root) return;
    const messages = config.messages || {};
    const form = root.querySelector('[data-review-search]');
    const list = root.querySelector('[data-review-rows]');
    const status = root.querySelector('[data-review-status]');
    const previous = root.querySelector('[data-review-previous]');
    const next = root.querySelector('[data-review-next]');
    const states = new Map();
    let cursors = [0];
    let pageIndex = 0;
    let nextCursor = 0;
    let hasMore = false;
    let loading = false;
    let generation = 0;
    let search = { query: '', review_only: '' };

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    }
    function button(text) {
        const node = element('button', 'll-transcription-review__button', text);
        node.type = 'button';
        return node;
    }
    async function request(action, data, fallback) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), Number(config.requestTimeoutMs) || 25000);
        try {
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams(Object.assign({ action, nonce: config.nonce, wordset_id: config.wordsetId, locale: config.locale || '' }, data))
            });
            const result = await response.json();
            if (!response.ok || !result || result.success !== true || !result.data) {
                throw new Error(result && result.data && result.data.message || fallback);
            }
            return result.data;
        } catch (error) {
            throw new Error(error.name === 'AbortError' ? fallback : (error.message || fallback));
        } finally { window.clearTimeout(timer); }
    }
    function dirty() {
        return Array.from(states.values()).some(state => state.running || state.failed || state.queue.size > 0);
    }
    function updatePages() {
        previous.disabled = loading || pageIndex === 0;
        next.disabled = loading || !hasMore;
    }
    function updateReview(state, row) {
        state.badge.hidden = !row.needs_review;
        state.reviewButton.hidden = !row.needs_review;
    }
    async function saveNext(state) {
        if (state.running || state.failed || !state.queue.size) return;
        window.clearTimeout(state.timer);
        const field = state.queue.keys().next().value;
        const value = state.queue.get(field);
        state.queue.delete(field);
        state.running = true;
        state.message.textContent = messages.saving;
        state.card.setAttribute('aria-busy', 'true');
        try {
            const data = await request('ll_tools_save_wordset_transcription_review', {
                recording_id: state.row.recording_id, field, value, expected: state.row.revisions[field]
            }, messages.saveError);
            const row = data.recording;
            if (!row || !row.revisions || Number(row.recording_id) !== Number(state.row.recording_id)) throw new Error(messages.saveError);
            state.row.revisions[field] = row.revisions[field];
            if (field === 'review_note') state.row.revisions.review_fields = row.revisions.review_fields;
            state.row[field] = row[field];
            if (state.inputs[field] && !state.queue.has(field)) state.inputs[field].value = row[field];
            if (field === 'review_fields') {
                state.row.review_note = row.review_note;
                state.row.revisions.review_note = row.revisions.review_note;
                if (!state.queue.has('review_note')) state.inputs.review_note.value = row.review_note;
            }
            updateReview(state, row);
            state.message.textContent = messages.saved;
        } catch (error) {
            state.failed = true;
            state.message.textContent = error.message || messages.saveError;
            state.reload.hidden = false;
        } finally {
            state.running = false;
            state.card.setAttribute('aria-busy', 'false');
            if (!state.failed && state.queue.size) saveNext(state);
        }
    }
    function queue(state, field, value) {
        state.queue.set(field, value);
        if (state.failed) return;
        state.message.textContent = messages.saving;
        window.clearTimeout(state.timer);
        state.timer = window.setTimeout(() => saveNext(state), Number(config.saveDelayMs) || 650);
    }
    function renderRow(row) {
        const card = element('article', 'll-transcription-review__card');
        card.dataset.recordingId = row.recording_id;
        const heading = element('div', 'll-transcription-review__heading');
        heading.append(element('h3', '', row.word_text), element('span', '', row.word_translation));
        card.append(heading, element('p', 'll-transcription-review__type', row.recording_type));
        if (row.audio_url) {
            const audio = element('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = row.audio_url;
            audio.setAttribute('aria-label', messages.audio + ': ' + row.word_text);
            audio.addEventListener('play', () => root.querySelectorAll('audio').forEach(other => { if (other !== audio) other.pause(); }));
            card.append(audio);
        }
        const state = { row, card, inputs: {}, queue: new Map(), timer: 0, running: false, failed: false };
        [['recording_text', messages.recordingText], ['recording_ipa', config.ipaLabel], ['review_note', messages.note]].forEach(([field, title]) => {
            const label = element('label', 'll-transcription-review__field', title);
            const input = element('textarea');
            input.rows = field === 'review_note' ? 2 : 1;
            input.maxLength = 2000;
            input.dir = 'auto';
            input.value = row[field] || '';
            input.dataset.reviewField = field;
            input.addEventListener('input', () => queue(state, field, input.value));
            input.addEventListener('blur', () => saveNext(state));
            state.inputs[field] = input;
            label.append(input);
            card.append(label);
        });
        const symbols = Array.isArray(config.symbols) ? config.symbols.filter(symbol => typeof symbol === 'string' && symbol.length <= 16).slice(0, 80) : [];
        if (symbols.length) {
            const keyboard = element('div', 'll-transcription-review__keyboard');
            symbols.forEach(symbol => {
                const key = button(symbol);
                key.addEventListener('click', () => {
                    const input = state.inputs.recording_ipa;
                    input.setRangeText(symbol, input.selectionStart, input.selectionEnd, 'end');
                    input.focus();
                    queue(state, 'recording_ipa', input.value);
                });
                keyboard.append(key);
            });
            card.append(keyboard);
        }
        const footer = element('div', 'll-transcription-review__footer');
        state.badge = element('span', 'll-transcription-review__badge', messages.review);
        state.reviewButton = button(messages.reviewed);
        state.reviewButton.addEventListener('click', () => {
            if (state.failed || state.running || state.queue.size) { status.textContent = messages.pending; return; }
            queue(state, 'review_fields', 'reviewed');
            saveNext(state);
        });
        state.message = element('span', 'll-transcription-review__save-status');
        state.message.setAttribute('role', 'status');
        state.reload = button(messages.reload);
        state.reload.hidden = true;
        state.reload.addEventListener('click', async () => {
            if (state.running) return;
            state.reload.disabled = true;
            try {
                const data = await request('ll_tools_get_wordset_transcription_review', { recording_id: row.recording_id }, messages.error);
                if (!data.recording || Number(data.recording.recording_id) !== Number(row.recording_id)) throw new Error(messages.error);
                window.clearTimeout(state.timer);
                card.replaceWith(renderRow(data.recording));
            } catch (error) { state.message.textContent = error.message; }
            finally { state.reload.disabled = false; }
        });
        updateReview(state, row);
        footer.append(state.badge, state.reviewButton, state.message, state.reload);
        card.append(footer);
        states.set(Number(row.recording_id), state);
        return card;
    }
    async function load(index, criteria, reset) {
        if (dirty()) { status.textContent = messages.pending; return; }
        const token = ++generation;
        loading = true;
        updatePages();
        status.textContent = messages.loading;
        root.setAttribute('aria-busy', 'true');
        list.querySelectorAll('textarea, button').forEach(control => { control.disabled = true; });
        try {
            const data = await request('ll_tools_get_wordset_transcription_review', Object.assign({}, criteria, { cursor: reset ? 0 : (cursors[index] || 0) }), messages.error);
            if (token !== generation) return;
            if (!Array.isArray(data.recordings) || !Number.isFinite(Number(data.next_cursor))) throw new Error(messages.error);
            states.clear();
            list.replaceChildren(...data.recordings.map(renderRow));
            if (reset) cursors = [0];
            pageIndex = index;
            search = criteria;
            nextCursor = Number(data.next_cursor);
            hasMore = Boolean(data.has_more);
            status.textContent = data.recordings.length ? '' : (hasMore ? messages.more : messages.empty);
        } catch (error) {
            if (token === generation) {
                list.querySelectorAll('audio').forEach(audio => audio.pause());
                list.replaceChildren();
                states.clear();
                hasMore = false;
                status.textContent = error.message || messages.error;
            }
        } finally {
            if (token === generation) {
                loading = false;
                list.querySelectorAll('textarea, button').forEach(control => { control.disabled = false; });
                updatePages();
                root.setAttribute('aria-busy', 'false');
            }
        }
    }
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (dirty()) { status.textContent = messages.pending; return; }
        load(0, { query: form.elements.query.value.trim(), review_only: form.elements.review_only.checked ? '1' : '' }, true);
    });
    previous.addEventListener('click', () => load(pageIndex - 1, search));
    next.addEventListener('click', () => { cursors[pageIndex + 1] = nextCursor; load(pageIndex + 1, search); });
    window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
    load(0, search);
}());
