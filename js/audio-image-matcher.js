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

    function endpoint(action, params) {
        const url = new URL(ajaxurl); // WP global
        url.searchParams.set('action', action);
        Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
        return url.toString();
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
        const u = endpoint('ll_aim_get_next', { term_id: termId, exclude: excludeIds.join(',') });
        const res = await fetch(u, { credentials: 'same-origin' });
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
            $audio[0].currentTime = 0;
            $audio[0].play().catch(() => { /* autoplay may be blocked; user can click play */ });
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
        const res = await fetch(ajaxurl, {
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

    // initial
    uiIdle();
})(jQuery);
