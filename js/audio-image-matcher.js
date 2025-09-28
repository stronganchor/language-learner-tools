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

    // Lightweight CSS for picked badges + grid
    (function injectCSS() {
        if (document.getElementById('ll-aim-css')) return;
        const css = `
      #ll-aim-images{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
      .ll-aim-card{position:relative;border:1px solid #ccd0d4;border-radius:6px;padding:8px;background:#fff;cursor:pointer}
      .ll-aim-card img{width:100%;height:120px;object-fit:cover;border-radius:4px}
      .ll-aim-title{margin-top:6px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .ll-aim-small{font-size:11px;color:#666}
      .ll-aim-card.is-picked{box-shadow:0 0 0 2px #2271b1 inset}
      .ll-aim-badge{position:absolute;top:8px;left:8px;background:#2271b1;color:#fff;font-size:11px;padding:2px 6px;border-radius:10px}
      #ll-aim-current-thumb{display:flex;align-items:center;gap:10px;margin:10px 0}
      #ll-aim-current-thumb img{width:72px;height:72px;object-fit:cover;border-radius:6px;border:1px solid #ccd0d4}
      #ll-aim-current-thumb .ll-aim-cap{font-size:12px;color:#555}
    `;
        const el = document.createElement('style');
        el.id = 'll-aim-css';
        el.appendChild(document.createTextNode(css));
        document.head.appendChild(el);
    })();

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

        $title.text(currentWord.title);
        if (currentWord.audio_url) {
            $audio.attr('src', currentWord.audio_url).show();
            try { $audio[0].currentTime = 0; $audio[0].play(); } catch (e) { }
        } else {
            $audio.removeAttr('src').hide();
        }
        $extra.text(currentWord.translation ? ('Translation: ' + currentWord.translation) : '');

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

        // Unused first, then already-used images
        cachedImages.sort((a, b) => {
            const av = a.used_count && a.used_count > 0 ? 1 : 0;
            const bv = b.used_count && b.used_count > 0 ? 1 : 0;
            return av - bv;
        });

        cachedImages.forEach(img => {
            const card = $('<div/>', { 'class': 'll-aim-card', 'data-img-id': img.id, title: img.title });
            const i = $('<img/>', { src: img.thumb || '', alt: img.title });
            const t = $('<div/>', { 'class': 'll-aim-title', text: img.title });
            const s = $('<div/>', { 'class': 'll-aim-small', text: '#' + img.id });

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
        cachedImages = [];
        await fetchImagesOnce();
        await fetchNext();
    });

    $skip.on('click', async function () {
        if (!currentWord) return;
        excludeIds.push(currentWord.id);
        await fetchNext();
    });

    // ?term_id=123&autostart=1&rematch=1
    function getParam(name) {
        const m = new RegExp('[?&]' + name + '=([^&]+)').exec(location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    // Autostart if told via querystring
    $(async function () {
        const qTerm = parseInt(getParam('term_id') || '0', 10);
        const auto = getParam('autostart') === '1';
        const rm = getParam('rematch') === '1';
        if (qTerm) $catSel.val(String(qTerm));
        if (rm) $rematch.prop('checked', true);
        if (qTerm && auto) {
            termId = qTerm;
            excludeIds = [];
            cachedImages = [];
            await fetchImagesOnce();
            await fetchNext();
        } else {
            uiIdle();
        }
    });
})(jQuery);
