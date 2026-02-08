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
    const $wsSel = $('#ll-aim-wordset');
    const $rematch = $('#ll-aim-rematch');
    const $currentWrap = $('#ll-aim-current-thumb');
    const $currentImg = $('#ll-aim-current-thumb img');
    const $currentCap = $('#ll-aim-current-thumb .ll-aim-cap');
    const $hideUsed = $('#ll-aim-hide-used');

    let termId = 0;
    let wordsetId = 0;
    let excludeIds = [];
    let cachedImages = [];
    let currentWord = null;

    function getAjaxBase() {
        if (window.llAimData && typeof window.llAimData.ajaxurl === 'string' && window.llAimData.ajaxurl.length) {
            try { return new URL(window.llAimData.ajaxurl, window.location.origin).toString(); } catch (e) { }
        }
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
        if (window.llAimData && window.llAimData.nonce) {
            u.searchParams.set('nonce', window.llAimData.nonce);
        }
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
        if (window.llAimData && window.llAimData.nonce) {
            u.searchParams.set('nonce', window.llAimData.nonce);
        }
        if (wordsetId > 0) u.searchParams.set('wordset_id', String(wordsetId));
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

        const list = $hideUsed.is(':checked')
            ? cachedImages.filter(img => !(img.used_count && img.used_count > 0))
            : cachedImages.slice();

        list.sort((a, b) => {
            const av = a.used_count && a.used_count > 0 ? 1 : 0;
            const bv = b.used_count && b.used_count > 0 ? 1 : 0;
            return av - bv;
        });

        const imageSize = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.imageSize) || 'small';

        list.forEach(img => {
            const card = $('<div/>', { 'class': 'll-aim-card', 'data-img-id': img.id, title: img.title });
            const imageWrapper = $('<div/>', { 'class': `ll-aim-image-wrapper flashcard-container flashcard-size-${imageSize}` });
            const i = $('<img/>', { src: img.thumb || '', alt: img.title, class: 'quiz-image' });
            imageWrapper.append(i);

            const t = $('<div/>', { 'class': 'll-aim-title', text: img.title });
            const s = $('<div/>', { 'class': 'll-aim-small', text: '#' + img.id });

            if (img.used_count && img.used_count > 0) {
                card.addClass('is-picked');
                const badge = $('<div/>', { 'class': 'll-aim-badge', text: `Picked${img.used_count > 1 ? ` ×${img.used_count}` : ''}` });
                imageWrapper.append(badge);
            }

            card.append(imageWrapper, t, s);
            card.on('click', () => assign(img.id, card));
            $images.append(card);
        });
    }

    async function assign(imageId, $card) {
        if (!currentWord) return;

        let removed = false;
        let addedBadge = null;

        if ($hideUsed.is(':checked')) {
            removed = true;
            $card.stop(true, true).fadeOut(120, () => $card.remove());
        } else {
            if (!$card.hasClass('is-picked')) {
                $card.addClass('is-picked');
                addedBadge = $('<div/>', { 'class': 'll-aim-badge', text: 'Picked' });
                $card.append(addedBadge);
            }
        }

        cachedImages = cachedImages.map(img =>
            img.id === imageId ? { ...img, used_count: (img.used_count || 0) + 1 } : img
        );

        uiLoading('Saving match…');

        const body = new URLSearchParams();
        body.set('action', 'll_aim_assign');
        body.set('word_id', currentWord.id);
        body.set('image_id', imageId);
        if (window.llAimData && window.llAimData.nonce) {
            body.set('nonce', window.llAimData.nonce);
        }

        try {
            const res = await fetch(getAjaxBase(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await res.json();

            if (json && json.success) {
                if (!excludeIds.includes(currentWord.id)) excludeIds.push(currentWord.id);
                await fetchNext();
            } else {
                if (removed) {
                    buildImageGrid();
                } else {
                    $card.removeClass('is-picked');
                    if (addedBadge) addedBadge.remove();
                }
                cachedImages = cachedImages.map(img =>
                    img.id === imageId ? { ...img, used_count: Math.max(0, (img.used_count || 1) - 1) } : img
                );
                $status.text('Error saving match.');
                uiReady();
            }
        } catch (e) {
            if (removed) {
                buildImageGrid();
            } else {
                $card.removeClass('is-picked');
                if (addedBadge) addedBadge.remove();
            }
            cachedImages = cachedImages.map(img =>
                img.id === imageId ? { ...img, used_count: Math.max(0, (img.used_count || 1) - 1) } : img
            );
            $status.text('Error saving match.');
            uiReady();
        }
    }

    // Start button wiring — captures both category and wordset, (re)loads data
    $start.on('click', async () => {
        termId = parseInt(($catSel.val() || '0'), 10) || 0;
        wordsetId = parseInt((($wsSel.val() || '0')), 10) || 0;

        excludeIds = [];
        cachedImages = [];
        uiIdle();

        if (!termId) {
            $status.text('Please select a category.');
            return;
        }

        await fetchImagesOnce();
        await fetchNext();
    });

    $skip.on('click', async () => {
        if (currentWord && currentWord.id) {
            if (!excludeIds.includes(currentWord.id)) excludeIds.push(currentWord.id);
        }
        await fetchNext();
    });

    $catSel.on('change', () => {
        cachedImages = [];
        excludeIds = [];
        uiIdle();
    });

    // When rematch is checked, uncheck and disable "hide used"
    $rematch.on('change', function () {
        if ($(this).is(':checked')) {
            $hideUsed.prop('checked', false).prop('disabled', true);
        } else {
            $hideUsed.prop('disabled', false);
        }
    });

    $hideUsed.on('change', async () => {
        cachedImages = [];
        await fetchImagesOnce();
        buildImageGrid();
    });

    (function preselectFromURL() {
        const q = new URLSearchParams(location.search);
        const id = q.get('term_id') || q.get('category') || q.get('cat') || q.get('word_category');
        const slug = q.get('category_slug') || q.get('slug');
        if (!id && !slug) return;

        let val = null;

        if (id) {
            const v = String(parseInt(id, 10));
            if ($catSel.find(`option[value="${v}"]`).length) val = v;
        } else if (slug) {
            const s = String(slug).toLowerCase();
            const byData = $catSel.find(`option[data-slug="${s}"]`);
            if (byData.length) val = byData.val();
            else {
                const byText = $catSel.find('option').filter(function () {
                    return $(this).text().trim().toLowerCase() === s;
                });
                if (byText.length) val = byText.first().val();
            }
        }

        if (!val) return;
        $catSel.val(val).trigger('change');
        // Autostart disabled; user must click "Start Matching."
    })();

})(jQuery);
