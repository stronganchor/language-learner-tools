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

    let termId = 0;
    let excludeIds = [];
    let cachedImages = [];
    let currentWord = null;

    // Robust ajax URL helper: works even if ajaxurl is relative or missing
    function getAjaxBase() {
        if (typeof ajaxurl === 'string' && ajaxurl.length) {
            try { return new URL(ajaxurl, window.location.origin).toString(); }
            catch (e) { /* fall through */ }
        }
        // Fallback to standard admin-ajax location
        return new URL('/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function endpoint(action, params) {
        const base = getAjaxBase();
        const u = new URL(base);
        u.searchParams.set('action', action);
        if (params) {
            Object.entries(params).forEach(([k, v]) => {
                if (Array.isArray(v)) {
                    v.forEach(val => u.searchParams.append(k, val));
                } else if (v !== undefined && v !== null) {
                    u.searchParams.set(k, v);
                }
            });
        }
        return u.toString();
    }

    function uiIdle() {
        $skip.prop('disabled', true);
        $stage.hide();
        $status.text('');
        currentWord = null;
    }

    function uiLoading(msg) {
        $status.text(msg || 'Loading…');
    }

    function uiReady() {
        $stage.show();
        $skip.prop('disabled', false);
        $status.text('');
    }

    async function fetchImagesOnce() {
        if (cachedImages.length) return;
        uiLoading('Loading images…');
        const u = endpoint('ll_aim_get_images', { term_id: termId });
        const res = await fetch(u, { credentials: 'same-origin' });
        const json = await res.json();
        cachedImages = (json && json.data && json.data.images) ? json.data.images : [];
    }

    async function fetchNext() {
        uiLoading('Loading next audio…');

        // Append exclude as an array param "exclude[]"
        const params = { term_id: termId };
        excludeIds.forEach(id => {
            // We'll add them as "exclude[]" in the URL
        });

        const base = getAjaxBase();
        const u = new URL(base);
        u.searchParams.set('action', 'll_aim_get_next');
        u.searchParams.set('term_id', termId);
        excludeIds.forEach(id => u.searchParams.append('exclude[]', id));

        const res = await fetch(u.toString(), { credentials: 'same-origin' });
        const json = await res.json();
        currentWord = (json && json.data) ? json.data.item : null;

        if (!currentWord) {
            $title.text('All done in this category 🎉');
            $audio.removeAttr('src').hide();
            $extra.text('');
            $images.empty();
            uiReady();
            $skip.prop('disabled', true);
            return;
        }

        // Populate UI
        $title.text(currentWord.title);
        if (currentWord.audio_url) {
            $audio.attr('src', currentWord.audio_url).show();
            try { $audio[0].currentTime = 0; $audio[0].play(); } catch (e) { /* user gesture may be required */ }
        } else {
            $audio.removeAttr('src').hide();
        }
        $extra.text(currentWord.translation ? ('Translation: ' + currentWord.translation) : '');
        buildImageGrid();
        uiReady();
    }

    function buildImageGrid() {
        $images.empty();
        cachedImages.forEach(img => {
            const card = $('<div/>', { 'class': 'll-aim-card', 'data-img-id': img.id, title: img.title });
            const i = $('<img/>', { src: img.thumb || '', alt: img.title });
            const t = $('<div/>', { 'class': 'll-aim-title', text: img.title });
            const s = $('<div/>', { 'class': 'll-aim-small', text: '#' + img.id });
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
        await fetchImagesOnce();
        await fetchNext();
    });

    $skip.on('click', async function () {
        if (!currentWord) return;
        excludeIds.push(currentWord.id);
        await fetchNext();
    });

    // Auto-preselect from URL (?term_id=123&autostart=1)
    function getParam(name) {
        const m = new RegExp('[?&]' + name + '=([^&]+)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null;
    }

    $(async function () {
        uiIdle();
        const pTerm = parseInt(getParam('term_id') || '0', 10);
        const pAuto = getParam('autostart');
        if (pTerm) {
            $catSel.val(String(pTerm));
            if (pAuto === '1') {
                $start.trigger('click');
            }
        }
    });
})(jQuery);
