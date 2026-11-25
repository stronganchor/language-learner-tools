(function () {
    'use strict';
    const root = document.getElementById('ll-category-video');
    const config = window.llCategoryVideo;
    if (!root || !config) return;
    const strings = config.strings;
    const el = (id) => document.getElementById('ll-video-' + id);
    const maxItems = Math.min(30, Number(config.maxItems) || 30);
    let items = [], skippedCount = 0, selected = new Set(), cursor = 0;
    const wordRows = new Map();
    let renderer = null, prepared = null, operation = null, outputUrl = '';
    let sourcesFailed = false;
    const format = (text, ...values) => String(text || '').replace(/%(?:(\d+)\$)?[ds]/g, (_, index) => String(values[index ? Number(index) - 1 : 0] ?? ''));
    const durationText = (seconds) => Math.floor(Math.ceil(seconds) / 60) + ':' + String(Math.ceil(seconds) % 60).padStart(2, '0');
    const selectedItems = () => items.filter((item) => selected.has(item.id));

    function status(text, error = false) {
        el('status').textContent = text;
        el('status').classList.toggle('is-error', error);
    }
    function errorText(error) {
        let message = strings.errors[error.code] || error.message || strings.requestError;
        if (error.name === 'AbortError' || error.code === 'canceled') message = strings.canceled;
        return error.itemText ? error.itemText + ': ' + message : message;
    }
    function refreshControls() {
        const busy = !!operation;
        el('wordset').disabled = busy || !el('wordset').options.length;
        el('category').disabled = busy || !el('wordset').value || el('category').options.length < 2;
        el('speaker').disabled = busy || !el('category').value || !el('speaker').options.length;
        el('load').disabled = busy || !el('category').value;
        el('more').disabled = busy;
        el('settings').disabled = busy;
        el('select-all').disabled = busy;
        el('select-none').disabled = busy;
        el('prepare').disabled = busy || !selected.size;
        el('play').disabled = busy || !prepared;
        el('record').disabled = busy || !prepared;
        el('stop').hidden = !busy;
        el('retry-sources').hidden = !sourcesFailed;
        el('retry-sources').disabled = busy;
        el('word-controls').disabled = busy;
        el('count').textContent = format(strings.selection, selected.size, items.length);
    }
    function clearResult() {
        const video = el('result').querySelector('video');
        video.pause();
        video.removeAttribute('src');
        video.load();
        el('download').removeAttribute('href');
        if (outputUrl) URL.revokeObjectURL(outputUrl);
        outputUrl = '';
        el('result').hidden = true;
    }
    function invalidate() {
        if (renderer) renderer.dispose();
        renderer = null;
        prepared = null;
        const canvas = el('canvas');
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        refreshControls();
    }
    function resetWords() {
        invalidate();
        items = []; skippedCount = 0; selected.clear(); cursor = 0;
        wordRows.clear();
        el('words').replaceChildren();
        el('skipped').querySelector('ul').replaceChildren();
        el('word-section').hidden = true;
        refreshControls();
    }
    async function run(work) {
        if (operation) return;
        const owner = new AbortController();
        operation = owner;
        refreshControls();
        try { await work(owner.signal); }
        catch (error) { status(errorText(error), error.name !== 'AbortError' && error.code !== 'canceled'); }
        finally {
            if (operation === owner) {
                operation = null;
                el('progress').hidden = true;
                refreshControls();
            }
        }
    }
    async function request(action, fields, signal) {
        const timeout = new AbortController();
        const abort = () => timeout.abort();
        signal.addEventListener('abort', abort, { once: true });
        const timer = setTimeout(abort, 30000);
        let response, result;
        try {
            if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
            response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', signal: timeout.signal,
                body: new URLSearchParams(Object.assign({ action, nonce: config.nonce }, fields))
            });
            result = await response.json();
        } catch (error) {
            if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
            throw new Error(strings.requestError);
        } finally {
            clearTimeout(timer);
            signal.removeEventListener('abort', abort);
        }
        if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
        if (!response.ok || !result?.success || !result.data || typeof result.data !== 'object') {
            const message = result?.data?.message;
            throw new Error(typeof message === 'string' && message ? message : strings.requestError);
        }
        return result.data;
    }
    async function sourceRows(key, fields, signal) {
        let after = 0;
        const rows = [];
        for (let batch = 0; batch < 50; batch++) {
            const result = await request('ll_tools_category_video_sources', Object.assign({ cursor: after }, fields), signal);
            if (!Array.isArray(result[key])) throw new Error(strings.requestError);
            rows.push(...result[key]);
            if (!result.has_more) return rows;
            if (!(Number(result.next_cursor) > after)) throw new Error(strings.requestError);
            after = Number(result.next_cursor);
        }
        throw new Error(strings.sourceLimit);
    }
    function choices(target, rows, placeholder) {
        const select = el(target);
        select.replaceChildren(new Option(placeholder, ''));
        const names = new Map();
        rows.forEach((row) => names.set(row.name, (names.get(row.name) || 0) + 1));
        rows.forEach((row) => select.add(new Option(names.get(row.name) > 1 && row.slug ? row.name + ' — ' + row.slug : row.name, String(row.id))));
    }
    async function loadWordsets(signal) {
        sourcesFailed = false;
        status(strings.loading);
        try {
            choices('wordset', await sourceRows('wordsets', {}, signal), strings.chooseWordset);
            choices('category', [], strings.chooseCategory);
            choices('speaker', [], strings.allSpeakers);
            status(strings.chooseWords);
        } catch (error) { sourcesFailed = true; throw error; }
    }
    async function loadCategories(signal) {
        sourcesFailed = false;
        status(strings.loading);
        try {
            const rows = el('wordset').value ? await sourceRows('categories', { wordset_id: el('wordset').value }, signal) : [];
            choices('category', rows, strings.chooseCategory);
            status(strings.chooseWords);
        } catch (error) { sourcesFailed = true; throw error; }
    }
    async function loadSpeakers(signal) {
        sourcesFailed = false;
        status(strings.loading);
        try {
            const rows = el('category').value ? await sourceRows('speakers', { wordset_id: el('wordset').value, category_id: el('category').value }, signal) : [];
            choices('speaker', rows, strings.allSpeakers);
            // One available speaker is a useful, explicit default.
            if (rows.length === 1) el('speaker').value = String(rows[0].id);
            status(strings.chooseWords);
        } catch (error) { sourcesFailed = true; throw error; }
    }
    function appendWord(item, fragment) {
        const row = document.createElement('li');
        row.className = 'll-video-word';
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = selected.has(item.id);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked && selected.size >= maxItems) {
                checkbox.checked = false; status(strings.limit, true); return;
            }
            if (checkbox.checked) selected.add(item.id); else selected.delete(item.id);
            invalidate(); status(strings.changed);
        });
        const image = document.createElement('img');
        image.src = item.image_url; image.alt = ''; image.loading = 'lazy';
        const text = document.createElement('span'); text.className = 'll-video-word-text';
        const strong = document.createElement('strong'); strong.textContent = item.text;
        text.append(strong);
        if (item.translation && item.translation !== item.text) {
            const translation = document.createElement('small'); translation.textContent = item.translation; text.append(translation);
        }
        label.append(checkbox, image, text);
        const controls = document.createElement('div'); controls.className = 'll-video-word-controls';
        [[-1, '↑', strings.moveUp], [1, '↓', strings.moveDown]].forEach(([direction, icon, title]) => {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'button'; button.textContent = icon;
            button.title = title; button.setAttribute('aria-label', title + ': ' + item.text);
            button.addEventListener('click', () => {
                const index = items.indexOf(item);
                const next = index + direction;
                if (index < 0 || next < 0 || next >= items.length) return;
                [items[index], items[next]] = [items[next], items[index]];
                if (direction < 0) row.previousElementSibling.before(row);
                else row.nextElementSibling.after(row);
                invalidate(); status(strings.changed);
                button.focus();
            });
            controls.append(button);
        });
        row.append(label, controls);
        fragment.append(row);
        wordRows.set(item.id, checkbox);
    }
    function changeSelection(next) {
        for (const id of selected) {
            if (!next.has(id)) wordRows.get(id).checked = false;
        }
        for (const id of next) {
            if (!selected.has(id)) wordRows.get(id).checked = true;
        }
        selected = next;
        invalidate();
        status(strings.changed);
    }
    async function loadWords(signal, append) {
        status(strings.loading);
        const after = append ? cursor : 0;
        const result = await request('ll_tools_category_video_items', {
            wordset_id: el('wordset').value, category_id: el('category').value,
            speaker_id: el('speaker').value || 0, cursor: after
        }, signal);
        const next = Number(result.next_cursor);
        if (!Array.isArray(result.items) || !Array.isArray(result.skipped) || !Number.isSafeInteger(next)
            || next < after || (result.has_more && next === after)) {
            throw new Error(strings.requestError);
        }

        if (!append) resetWords();
        else invalidate();
        const fragment = document.createDocumentFragment();
        for (const item of result.items) {
            if (wordRows.has(item.id)) continue;
            items.push(item);
            if (selected.size < maxItems) selected.add(item.id);
            appendWord(item, fragment);
        }
        el('words').append(fragment);
        const skipBox = el('skipped');
        const skipped = document.createDocumentFragment();
        for (const item of result.skipped) {
            const li = document.createElement('li');
            li.textContent = (item.text || String(item.id)) + ' — ' + (strings.skipReasons[item.reason] || strings.skipReasons.unavailable);
            skipped.append(li);
        }
        skipBox.querySelector('ul').append(skipped);
        skippedCount += result.skipped.length;
        skipBox.hidden = !skippedCount;
        skipBox.querySelector('summary').textContent = format(strings.skipped, skippedCount);
        cursor = next;
        el('more').hidden = !result.has_more;
        el('page-status').textContent = result.has_more ? strings.more : strings.complete;
        el('word-section').hidden = false;
        refreshControls();
        status(items.length ? strings.chooseWords : strings.empty);
    }
    function options() {
        return {
            layout: el('layout').value, repeats: Number(el('repeats').value),
            gap: Number(el('gap').value), recall: Number(el('recall').value),
            showText: el('show-text').checked, title: el('title').value.trim()
        };
    }
    function onProgress(progress) {
        el('progress').hidden = false;
        el('progress').value = Number(progress.progress) || 0;
        if (progress.phase === 'loading') status(format(strings.preparing, progress.completed, progress.total));
        else if (progress.phase === 'playing' || progress.phase === 'recording') {
            status(format(strings[progress.phase], durationText(progress.elapsed), durationText(progress.duration)));
        }
    }
    el('wordset').addEventListener('change', () => {
        resetWords(); choices('category', [], strings.chooseCategory); choices('speaker', [], strings.allSpeakers);
        run(loadCategories);
    });
    el('category').addEventListener('change', () => {
        resetWords(); choices('speaker', [], strings.allSpeakers);
        el('title').value = el('category').value ? el('category').selectedOptions[0].textContent : '';
        run(loadSpeakers);
    });
    el('speaker').addEventListener('change', () => { resetWords(); status(strings.chooseWords); });
    el('retry-sources').addEventListener('click', () => run(el('category').value ? loadSpeakers : el('wordset').value ? loadCategories : loadWordsets));
    el('load').addEventListener('click', () => run((signal) => loadWords(signal, false)));
    el('more').addEventListener('click', () => run((signal) => loadWords(signal, true)));
    el('select-all').addEventListener('click', () => changeSelection(new Set(items.slice(0, maxItems).map((item) => item.id))));
    el('select-none').addEventListener('click', () => changeSelection(new Set()));
    el('settings').addEventListener('input', () => { invalidate(); status(strings.changed); });
    el('prepare').addEventListener('click', () => run(async (signal) => {
        invalidate();
        renderer = window.LLToolsCategoryVideoRenderer.create({ canvas: el('canvas'), onProgress });
        prepared = await renderer.prepare(selectedItems(), options(), { signal });
        status(format(strings.ready, prepared.itemCount, durationText(prepared.duration)));
    }));
    async function play(signal, record) {
        if (!renderer || !prepared) throw Object.assign(new Error(), { code: 'not_prepared' });
        const result = await renderer.play({ record, signal });
        if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
        if (record) {
            const nextUrl = URL.createObjectURL(result.blob);
            clearResult();
            outputUrl = nextUrl;
            el('result').querySelector('video').src = outputUrl;
            const download = el('download');
            download.href = outputUrl;
            download.download = 'category-' + el('category').value + '-video.' + result.extension;
            download.textContent = format(strings.download, result.extension.toUpperCase());
            el('result').hidden = false;
            status(strings.finished);
        } else status(format(strings.ready, prepared.itemCount, durationText(prepared.duration)));
    }
    el('play').addEventListener('click', () => run((signal) => play(signal, false)));
    el('record').addEventListener('click', () => run((signal) => play(signal, true)));
    el('stop').addEventListener('click', () => {
        if (operation) operation.abort();
        if (renderer) renderer.cancel();
    });
    window.addEventListener('beforeunload', (event) => {
        if (operation) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pagehide', () => {
        if (operation) operation.abort();
        if (renderer) renderer.dispose();
        if (outputUrl) clearResult();
    });
    run(loadWordsets);
}());
