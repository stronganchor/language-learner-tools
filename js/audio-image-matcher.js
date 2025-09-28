/* /js/audio-image-matcher.js */
(function ($) {
    const $start = $('#ll-aim-start');
    const $skip = $('#ll-aim-skip');
    const $stage = $('#ll-aim-stage');
    const $images = $('#ll-aim-images');
    const $title = $('#ll-aim-word-title');
    const $audio = $('#ll-aim-audio');
    const $extra = $('#ll-aim-extra');
    const $status = $('#ll-aim-status');
    const $catSel = $('#ll-aim-category');
    const $rematch = $('#ll-aim-rematch');
    const $currentWrap = $('#ll-aim-current-thumb');
    const $currentImg = $('#ll-aim-current-thumb img');
    const $currentCap = $('#ll-aim-current-thumb .ll-aim-cap');

    let termId = 0;
    let excludeIds = [];
    let cachedImages = [];
    let currentWord = null;

    // --- tiny CSS for "Picked" badge (kept here so you don't need to edit PHP) ---
    (function injectBadgeCSS() {
        const css = `
      .ll-aim-card { position: relative; }
      .ll-aim-badge {
        position: absolute; top: 6px; right: 6px;
        padding: 2px 6px; border-radius: 999px;
        font-size: 11px; font-weight: 600; line-height: 1.4;
        background: rgba(16, 185, 129, .12); /* green-ish */
        border: 1px solid rgba(16,185,129,.45); color: #065f46;
        pointer-events: none; user-select: none;
      }
      .ll-aim-card.is-picked { box-shadow: 0 0 0 2px rgba(16,185,129,.25), 0 4px 14px rgba(0,0,0,.08); }
    `;
        const style = document.createElement('style');
        style.type = 'text/css';
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
    })();

    // --- helpers ---
    function getAjaxBase() {
        if (typeof ajaxurl === 'string' && ajaxurl.length) {
            try { return new URL(ajaxurl, window.location.origin).toString(); }
            catch (e) { }
        }
        return new URL('/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function uiIdle() { $skip.prop('disabled', true); $stage.hide(); $status.text(''); currentWord = null; }
    function uiLoading(msg) { $status.text(msg || 'Loading…'); }
    function uiReady() { $stage.show(); $skip.prop('disabled', false); $status.text(''); }

    async function fetchImagesOnce() {
        if (cachedImages.length) return;
        uiLoading('Loading images…');
        const u = new URL(getAjaxBase());
        u.searchParams.set('action', 'll_aim_get_images');
        u.searchParams.set('term_id', termId);
        const res = await fetch(u.toString(), { credentials: 'same-origin' });
        const json = await res.json();
        cachedImages = (json && json.data && json.data.images) ? json.data.images : [];
    }

    async function fetchNext() {
        uiLoading('Loading next audio…');
        const u = new URL(getAjaxBase());
        u.searchParams.set('action', 'll_aim_get_next');
        u.searchParams.set('term_id', termId);
        u.searchParams.set('rematch', $rematch.is(':checked') ? '1' : '0');
        excludeIds.forEach(id => u.searchParams.append('exclude[]', id));

        const res = await fetch(u.toString(), { credentials: 'same-origin' });
        const json = await res.json();
        currentWord = (json && json.data) ? json.data.item : null;

        if (!currentWord) {
            $title.text('All done in this category 🎉');
            $audio.removeAttr('src').hide();
            $extra.text('');
            $images.empty();
            $currentWrap.hide();
            uiReady();
            $skip.prop('disabled', true);
            return;
        }

        // Populate word area
        $title.text(currentWord.title);
        if (currentWord.audio_url) {
            $audio.attr('src', currentWord.audio_url).show();
            try { $audio[0].currentTime = 0; $audio[0].play(); } catch (e) { }
        } else {
            $audio.removeAttr('src').hide();
        }
        $extra.text(currentWord.translation ? ('Translation: ' + currentWord.translation) : '');

        // Show current featured image (if any)
        if (currentWord.current_thumb) {
            $currentImg.attr('src', currentWord.current_thumb);
            $currentCap.text('Current image (will be replaced if you pick a new one)');
            $currentWrap.show();
        } else {
            $currentWrap.hide();
        }

        buildImageGrid();
        uiReady();
    }

    function buildImageGrid() {
        $images.empty();
        if (!cachedImages.length) {
            $images.append($('<div/>', { text: 'No images found in this category.' }));
            return;
        }
        cachedImages.forEach(img => {
            const card = $('<div/>', { 'class': 'll-aim-card', 'data-img-id': img.id, title: img.title });
            const i = $('<img/>', { src: img.thumb || '', alt: img.title });
            const t = $('<div/>', { 'class': 'll-aim-title', text: img.title });
            const s = $('<div/>', { 'class': 'll-aim-small', text: '#' + img.id });

            // Visual indicator for images already "picked" by auto-match or previous choices
            if (img.used_count && img.used_count > 0) {
                card.addClass('is-picked');
                const badge = $('<div/>', { 'class': 'll-aim-badge', text: `Picked${img.used_count > 1 ? ` ×${img.used_count}` : ''}` });
                card.append(badge);
            }

            card.append(i, t, s);
            card.on('click', () => assign(img.id));
            $images.append(card);
        });
    }

    async function assign(imageId) {
        if (!currentWord) return;
        uiLoading('Saving match…');
        const body = new URLSearchParams();
        body.set('action', 'll_aim_assign');
        body.set('word_id', currentWord.id);
        body.set('image_id', imageId);
        const res = await fetch(getAjaxBase(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
        const json = await res.json();
        if (json && json.success) {
            excludeIds.push(currentWord.id);
            await fetchNext();
        } else {
            $status.text('Error saving match.');
        }
    }

    $start.on('click', async function () {
        termId = parseInt($catSel.val(), 10) || 0;
        if (!termId) { alert('Please select a category first.'); return; }
        excludeIds = [];
        cachedImages = []; // refetch images when switching categories
        await fetchImagesOnce();
        await fetchNext();
    });

    $skip.on('click', async function () {
        if (!currentWord) return;
        excludeIds.push(currentWord.id);
        await fetchNext();
    });

    // URL helpers: ?term_id=123&autostart=1&rematch=1
    function getParam(name) {
        const m = new RegExp('[?&]' + name + '=([^&]+)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null;
    }

    $(async function () {
        uiIdle();
        const pTerm = parseInt(getParam('term_id') || '0', 10);
        const pAuto = getParam('autostart');
        const pRem = getParam('rematch');
        if (pTerm) $catSel.val(String(pTerm));
        if (pRem === '1') $rematch.prop('checked', true);
        if (pTerm && pAuto === '1') { $start.trigger('click'); }
    });
})(jQuery);
