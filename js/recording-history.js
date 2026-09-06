(function () {
    'use strict';

    var config = window.llRecordingHistory || {};
    var strings = config.strings || {};

    function safeUrl(value) {
        if (!value) { return ''; }
        try {
            var url = new URL(value, window.location.href);
            return /^(https?:)$/.test(url.protocol) ? url.href : '';
        } catch (error) { return ''; }
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) { node.textContent = String(text); }
        return node;
    }

    function init(panel) {
        if (panel.dataset.historyReady) { return; }
        panel.dataset.historyReady = '1';
        var body = panel.querySelector('[data-history-body]');
        var list = panel.querySelector('[data-history-list]');
        var message = panel.querySelector('[data-history-message]');
        var retry = panel.querySelector('[data-history-retry]');
        var nav = panel.querySelector('[data-history-nav]');
        var previous = panel.querySelector('[data-history-previous]');
        var next = panel.querySelector('[data-history-next]');
        var refresh = panel.querySelector('[data-history-refresh]');
        var wordset = panel.dataset.wordsetId;
        var currentCursor = '';
        var nextCursor = '';
        var history = [];
        var pending = null;
        var lastRequest = { cursor: '', history: [] };
        var serial = 0;
        var loaded = false;

        function pausePlayers(except) {
            panel.querySelectorAll('audio').forEach(function (player) {
                if (player !== except) { player.pause(); }
            });
        }

        function controls(busy) {
            body.setAttribute('aria-busy', busy ? 'true' : 'false');
            previous.disabled = busy || !history.length;
            next.disabled = busy || !nextCursor;
            refresh.disabled = busy;
            retry.disabled = busy;
            nav.hidden = !loaded;
        }

        function categoryUrl(category) {
            var base = safeUrl(config.recorderUrl);
            if (!base || !category.slug) { return ''; }
            var url = new URL(base);
            url.searchParams.delete('ll_record_word');
            url.searchParams.set('ll_record_wordset', wordset);
            url.searchParams.set('ll_record_category', category.slug);
            return url.href;
        }

        function render(items) {
            pausePlayers();
            list.replaceChildren();
            items.forEach(function (item) {
                var row = element('li', 'll-recording-history__item');
                var information = element('div', 'll-recording-history__information');
                var wordUrl = safeUrl(item.word_url);
                var title = element(wordUrl ? 'a' : 'span', 'll-recording-history__title', item.title || '');
                if (wordUrl) { title.href = wordUrl; }
                information.appendChild(title);
                var metadata = element('div', 'll-recording-history__metadata');
                if (item.recording_type) {
                    metadata.appendChild(element('span', 'll-recording-history__type', item.recording_type));
                }
                if (item.date) {
                    var date = element('time', 'll-recording-history__date', item.date);
                    var timestamp = Number(item.timestamp);
                    if (timestamp > 0 && Number.isFinite(timestamp) && timestamp < 8640000000000) {
                        date.dateTime = new Date(timestamp * 1000).toISOString();
                    }
                    metadata.appendChild(date);
                }
                metadata.appendChild(element('span', 'll-recording-history__status', item.status_label || ''));
                information.appendChild(metadata);
                var categories = element('div', 'll-recording-history__categories');
                (Array.isArray(item.categories) ? item.categories.slice(0, 5) : []).forEach(function (category) {
                    var href = categoryUrl(category);
                    var label = element(href ? 'a' : 'span', 'll-recording-history__category', category.name || '');
                    if (href) { label.href = href; }
                    categories.appendChild(label);
                });
                information.appendChild(categories);
                row.appendChild(information);
                var playback = element('div', 'll-recording-history__playback');
                var audioUrl = safeUrl(item.audio_url);
                if (audioUrl) {
                    var audio = element('audio', 'll-recording-history__audio');
                    audio.controls = true;
                    audio.preload = 'none';
                    audio.src = audioUrl;
                    audio.setAttribute('aria-label', String(strings.playback || '') + ': ' + String(item.title || ''));
                    audio.addEventListener('play', function () { pausePlayers(audio); });
                    var error = element('span', 'll-recording-history__audio-error', strings.playbackError || '');
                    error.hidden = true;
                    error.setAttribute('role', 'status');
                    audio.addEventListener('error', function () { error.hidden = false; });
                    playback.appendChild(audio);
                    playback.appendChild(error);
                } else {
                    playback.appendChild(element('span', 'll-recording-history__unavailable', strings.unavailable || ''));
                }
                row.appendChild(playback);
                list.appendChild(row);
            });
        }

        function cancel() {
            serial += 1;
            if (pending) {
                window.clearTimeout(pending.timer);
                pending.controller.abort();
                pending = null;
            }
            controls(false);
        }

        async function request(cursor, targetHistory) {
            cancel();
            pausePlayers();
            var requestSerial = serial;
            var controller = new AbortController();
            lastRequest = { cursor: cursor, history: targetHistory.slice() };
            message.textContent = strings.loading || '';
            retry.hidden = true;
            controls(true);
            var timer;
            var deadline = new Promise(function (_resolve, reject) {
                timer = window.setTimeout(function () {
                    controller.abort();
                    reject(new Error('timeout'));
                }, Math.max(100, Math.min(30000, Number(config.requestTimeoutMs) || 15000)));
            });
            pending = { controller: controller, timer: timer };
            var form = new URLSearchParams({
                action: 'll_tools_recording_history', nonce: config.nonce || '',
                wordset_id: wordset, cursor: cursor, locale: config.locale || ''
            });
            try {
                // The deadline covers a stalled response body as well as fetch.
                var data = await Promise.race([deadline, (async function () {
                    var response = await window.fetch(config.ajaxUrl, {
                        method: 'POST', credentials: 'same-origin', cache: 'no-store',
                        body: form, signal: controller.signal
                    });
                    var result = await response.json();
                    if (!response.ok || !result || result.success !== true || !result.data
                        || !Array.isArray(result.data.items) || result.data.items.length > 20
                        || typeof result.data.next_cursor !== 'string'
                        || (result.data.has_more && !result.data.next_cursor)) {
                        throw new Error('request');
                    }
                    return result.data;
                })()]);
                if (requestSerial !== serial || !panel.open) { return; }
                currentCursor = cursor;
                history = targetHistory.slice();
                nextCursor = data.has_more ? data.next_cursor : '';
                loaded = true;
                render(data.items);
                message.textContent = data.items.length ? '' : (strings.empty || '');
            } catch (error) {
                if (requestSerial !== serial || !panel.open) { return; }
                // A failed authorization refresh must not leave old private rows visible.
                pausePlayers();
                list.replaceChildren();
                message.textContent = strings.error || '';
                retry.hidden = false;
            } finally {
                window.clearTimeout(timer);
                if (requestSerial === serial) {
                    pending = null;
                    controls(false);
                }
            }
        }

        previous.addEventListener('click', function () {
            if (history.length) { request(history[history.length - 1], history.slice(0, -1)); }
        });
        next.addEventListener('click', function () {
            if (nextCursor) { request(nextCursor, history.concat([currentCursor])); }
        });
        refresh.addEventListener('click', function () { request('', []); });
        retry.addEventListener('click', function () { request(lastRequest.cursor, lastRequest.history); });
        panel.addEventListener('toggle', function () {
            if (panel.open) {
                // Reopening reflects new uploads and revalidates recorder access.
                list.replaceChildren();
                request('', []);
            } else {
                cancel();
                pausePlayers();
                list.replaceChildren();
                loaded = false;
                controls(false);
            }
        });
        if (panel.open) { request('', []); }
    }

    function start() { document.querySelectorAll('[data-ll-recording-history]').forEach(init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); }
    else { start(); }
})();
