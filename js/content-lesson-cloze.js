(function () {
    'use strict';
    var config = window.llToolsCloze || {};
    var strings = config.i18n || {};
    function text(name) { return String(strings[name] || ''); }
    function element(tag, className, value) {
        var node = document.createElement(tag);
        node.className = className;
        if (value !== undefined) { node.textContent = value; }
        return node;
    }
    function button(className, value) {
        var node = element('button', className, value);
        node.type = 'button';
        return node;
    }
    function init(root) {
        if (root.__llClozeBound) { return; }
        var source = root.querySelector('[data-ll-cloze-payload]');
        var payload;
        try { payload = JSON.parse(source.textContent); } catch (error) { return; }
        if (!Array.isArray(payload.bank) || !Array.isArray(payload.segments) || !payload.bank.length || payload.bank.length > 60) { return; }
        root.__llClozeBound = true;
        var selected = '';
        var busy = false;
        var completed = false;
        var choices = payload.bank.map(function () { return ''; });
        var tokens = {};
        var tokenButtons = {};
        var slots = [];
        var results = [];
        var instructions = element('p', 'll-cloze__instructions', text('instructions'));
        var passage = element('div', 'll-cloze__passage');
        passage.dir = 'auto';
        var bank = element('div', 'll-cloze__bank');
        bank.dir = 'auto';
        bank.setAttribute('aria-label', text('bank'));
        var controls = element('div', 'll-cloze__controls');
        var check = button('ll-cloze__action ll-cloze__action--primary', text('check'));
        check.setAttribute('data-ll-cloze-check', '');
        var reset = button('ll-cloze__action', text('reset'));
        reset.setAttribute('data-ll-cloze-reset', '');
        var status = element('p', 'll-cloze__status');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('data-ll-cloze-status', '');
        function say(message) { status.textContent = message; }
        function blankLabel(index) { return text('blank').replace('%d', String(index + 1)); }
        function redraw() {
            var used = {};
            choices.forEach(function (id) { if (id) { used[id] = true; } });
            Object.keys(tokenButtons).forEach(function (id) {
                var node = tokenButtons[id];
                node.hidden = !!used[id];
                node.disabled = busy;
                node.classList.toggle('is-selected', id === selected);
                node.setAttribute('aria-pressed', id === selected ? 'true' : 'false');
            });
            slots.forEach(function (node, index) {
                var id = choices[index];
                var label = id ? tokens[id] : '\u00a0\u00a0\u00a0';
                node.textContent = label;
                node.disabled = busy;
                node.draggable = !!id;
                node.classList.toggle('is-filled', !!id);
                node.classList.toggle('is-correct', results[index] === true);
                node.classList.toggle('is-incorrect', results[index] === false);
                var accessible = id ? text('filled').replace('%1$d', String(index + 1)).replace('%2$s', tokens[id]) : blankLabel(index);
                if (typeof results[index] === 'boolean') {
                    var mark = element('span', 'll-cloze__mark', results[index] ? '\u2713' : '\u00d7');
                    mark.setAttribute('aria-hidden', 'true');
                    node.appendChild(mark);
                    accessible += ' ' + text(results[index] ? 'correctBlank' : 'incorrectBlank');
                }
                node.setAttribute('aria-label', accessible);
            });
            check.disabled = busy || completed;
            reset.disabled = busy;
            check.textContent = text(busy ? 'checking' : 'check');
            root.setAttribute('aria-busy', busy ? 'true' : 'false');
            root.classList.toggle('is-complete', completed);
        }
        function place(id, index) {
            if (busy || !tokens[id] || !slots[index]) { return; }
            choices = choices.map(function (old) { return old === id ? '' : old; });
            choices[index] = id;
            selected = '';
            results = [];
            completed = false;
            redraw();
            say(text('placed'));
            slots[index].focus();
        }
        function returnWord(index) {
            var id = choices[index];
            if (!id || busy) { return; }
            choices[index] = '';
            selected = '';
            completed = false;
            results = [];
            redraw();
            say(text('returned'));
            tokenButtons[id].focus();
        }
        function dragEvents(node, getId) {
            node.addEventListener('dragstart', function (event) {
                var id = getId();
                if (busy || !id) { event.preventDefault(); return; }
                event.dataTransfer.setData('text/plain', id);
                event.dataTransfer.effectAllowed = 'move';
            });
            // HTML drag events are absent on many touch devices. Tap selection remains available.
            var touch = null;
            node.addEventListener('pointerdown', function (event) {
                if (event.pointerType === 'mouse' || busy || !getId()) { return; }
                touch = { id: getId(), x: event.clientX, y: event.clientY, moved: false };
                node.setPointerCapture(event.pointerId);
            });
            node.addEventListener('pointermove', function (event) {
                if (!touch) { return; }
                if (Math.abs(event.clientX - touch.x) + Math.abs(event.clientY - touch.y) > 10) { touch.moved = true; }
                root.querySelectorAll('.is-drop-target').forEach(function (target) { target.classList.remove('is-drop-target'); });
                var under = document.elementFromPoint(event.clientX, event.clientY);
                var target = under && under.closest('[data-ll-cloze-slot]');
                if (touch.moved && target && root.contains(target)) { target.classList.add('is-drop-target'); }
            });
            node.addEventListener('pointerup', function (event) {
                if (!touch) { return; }
                var drag = touch;
                touch = null;
                root.querySelectorAll('.is-drop-target').forEach(function (target) { target.classList.remove('is-drop-target'); });
                if (!drag.moved) { return; }
                node.__ignoreClickUntil = Date.now() + 400;
                var under = document.elementFromPoint(event.clientX, event.clientY);
                var target = under && under.closest('[data-ll-cloze-slot]');
                if (target && root.contains(target)) { place(drag.id, Number(target.dataset.llClozeSlot)); }
            });
            node.addEventListener('pointercancel', function () {
                touch = null;
                root.querySelectorAll('.is-drop-target').forEach(function (target) { target.classList.remove('is-drop-target'); });
            });
        }
        payload.segments.forEach(function (segment) {
            if (typeof segment.text === 'string') {
                passage.appendChild(document.createTextNode(segment.text));
            } else if (Number.isInteger(segment.blank) && segment.blank >= 0 && segment.blank < choices.length) {
                var index = segment.blank;
                var slot = button('ll-cloze__slot');
                slot.dir = 'auto';
                slot.dataset.llClozeSlot = String(index);
                slot.addEventListener('click', function () {
                    if (Date.now() < (slot.__ignoreClickUntil || 0)) { return; }
                    if (selected) { place(selected, index); } else { returnWord(index); }
                });
                slot.addEventListener('keydown', function (event) {
                    if (event.key === 'Backspace' || event.key === 'Delete') { event.preventDefault(); returnWord(index); }
                });
                slot.addEventListener('dragover', function (event) { if (!busy) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; } });
                slot.addEventListener('drop', function (event) { event.preventDefault(); place(event.dataTransfer.getData('text/plain'), index); });
                dragEvents(slot, function () { return choices[index]; });
                slots[index] = slot;
                passage.appendChild(slot);
            }
        });
        payload.bank.forEach(function (item) {
            tokens[item.id] = String(item.text);
            var node = button('ll-cloze__word', String(item.text));
            node.dir = 'auto';
            node.draggable = true;
            node.dataset.llClozeToken = item.id;
            node.addEventListener('click', function () {
                if (busy || Date.now() < (node.__ignoreClickUntil || 0)) { return; }
                selected = selected === item.id ? '' : item.id;
                redraw();
                say(selected ? text('selected') : '');
            });
            dragEvents(node, function () { return item.id; });
            tokenButtons[item.id] = node;
            bank.appendChild(node);
        });
        bank.addEventListener('dragover', function (event) { if (!busy) { event.preventDefault(); } });
        bank.addEventListener('drop', function (event) {
            event.preventDefault();
            var index = choices.indexOf(event.dataTransfer.getData('text/plain'));
            if (index !== -1) { returnWord(index); }
        });
        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { selected = ''; redraw(); say(''); }
        });
        reset.addEventListener('click', function () {
            choices = choices.map(function () { return ''; });
            selected = '';
            results = [];
            completed = false;
            // Shuffle the same reusable tokens for a new attempt.
            var nodes = Object.keys(tokenButtons).map(function (id) { return tokenButtons[id]; });
            for (var i = nodes.length - 1; i > 0; i -= 1) {
                var j = Math.floor(Math.random() * (i + 1));
                var temp = nodes[i]; nodes[i] = nodes[j]; nodes[j] = temp;
            }
            nodes.forEach(function (node) { bank.appendChild(node); });
            redraw(); say('');
        });
        check.addEventListener('click', function () {
            if (busy) { return; }
            if (choices.some(function (id) { return !id; })) { say(text('incomplete')); return; }
            var attempt = {
                lessonId: payload.lessonId, version: payload.version, salt: payload.salt,
                signature: payload.signature, choices: choices.slice()
            };
            var body = new URLSearchParams();
            body.set('action', 'll_tools_cloze_check');
            body.set('nonce', String(config.nonce || ''));
            body.set('attempt', JSON.stringify(attempt));
            busy = true; redraw(); say(text('checking'));
            var controller = new AbortController();
            var timer;
            var request = fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal })
                .then(function (response) { return response.json(); });
            var deadline = new Promise(function (resolve, reject) {
                timer = window.setTimeout(function () { controller.abort(); reject(new Error()); }, Math.max(25, Math.min(60000, Number(config.requestTimeoutMs) || 15000)));
            });
            Promise.race([request, deadline]).then(function (response) {
                if (!response || !response.success || !response.data) {
                    var error = new Error();
                    error.userMessage = response && response.data && response.data.message;
                    throw error;
                }
                var data = response.data;
                if (!Array.isArray(data.correct) || data.correct.length !== choices.length || data.correct.some(function (v) { return typeof v !== 'boolean'; })) { throw new Error(); }
                results = data.correct;
                var allCorrect = results.every(function (v) { return v; });
                completed = allCorrect && !data.saveError;
                root.classList.toggle('is-complete', completed);
                say(allCorrect ? text('correct') + ' ' + (data.saveError ? text('saveError') : data.saved ? text('saved') : '') : text('retry'));
            }).catch(function (error) {
                say(error.userMessage || text('error'));
            }).finally(function () {
                window.clearTimeout(timer);
                busy = false;
                redraw();
            });
        });
        controls.appendChild(check); controls.appendChild(reset);
        root.appendChild(instructions); root.appendChild(passage); root.appendChild(bank); root.appendChild(controls); root.appendChild(status);
        redraw();
    }
    function start() { document.querySelectorAll('[data-ll-cloze]').forEach(init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
