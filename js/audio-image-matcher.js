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
    const $hideUsed = $('#ll-aim-hide-used');

    let termId = 0;
    let excludeIds = [];
    let cachedImages = [];
    let currentWord = null;

    // Minimal CSS (kept) — harmless if you also have a stylesheet
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
            try { return new URL(ajaxurl, window.location.origin).toString(); } catch (e) { }
        }
        return new URL('/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function uiIdle() { $skip.prop('disabled', true); $stage.hide(); $status.text(''); currentWord = null; }
    function uiLoading(m) { $status.text(m || 'Loading…'); }
    function uiReady() { $stage.show(); $skip.prop('disabled', false); $status.text(''); }

    async function fetchImagesOnce() {
        if (cachedImages.length) return;
        uiLoading('Loading images…');
        const u = new URL(getAjaxBase());
        u.searchParams.set('action', 'll_aim_get_images');
        u.searchParams.set('term_id', termId);
        u.searchParams.set('hide_used', $hideUsed.is(':checked') ? '1' : '0');
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

        // Defensive: filter out used images on the client too (in case server didn't)
        const list = $hideUsed.is(':checked')
            ? cachedImages.filter(img => !(img.used_count && img.used_count > 0))
            : cachedImages.slice();

        list.sort((a, b) => {
            const av = a.used_count && a.used_count > 0 ? 1 : 0;
            const bv = b.used_count && b.used_count > 0 ? 1 : 0;
            return av - bv;
        });

        list.forEach(img => {
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
            // Mark used locally so we can hide it immediately (no extra fetch needed)
            cachedImages = cachedImages.map(img => img.id === imageId ? { ...img, used_count: (img.used_count || 0) + 1 } : img);
            excludeIds.push(currentWord.id);
            await fetchNext();
        } else {
            $status.text('Error saving match.');
        }
    }

    // Wire up controls
    $start.on('click', async () => {
        termId = parseInt(String($catSel.val() || '0'), 10) || 0;
        if (!termId) { $status.text('Please choose a category.'); return; }
        excludeIds = [];
        cachedImages = [];
        uiIdle();
        await fetchImagesOnce();
        await fetchNext();
    });

    $skip.on('click', fetchNext);

    $catSel.on('change', () => {
        // Changing category invalidates cache
        cachedImages = [];
        excludeIds = [];
        uiIdle();
    });

    $hideUsed.on('change', async () => {
        // Re-fetch from server for efficiency (but we also filter client-side)
        cachedImages = [];
        await fetchImagesOnce();
        buildImageGrid();
    });
})(jQuery);
