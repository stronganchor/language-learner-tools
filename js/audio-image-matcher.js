/* /js/audio-image-matcher.js */
(function ($) {
    const i18n = (window.llAimData && window.llAimData.i18n) || {};
    const initialWordsetId = parseInt((window.llAimData && window.llAimData.initialWordsetId) || '0', 10) || 0;
    const initialCategoryId = parseInt((window.llAimData && window.llAimData.initialCategoryId) || '0', 10) || 0;
    const initialCategoryRows = Array.isArray(window.llAimData && window.llAimData.initialCategoryRows)
        ? window.llAimData.initialCategoryRows
        : [];
    const configuredTimeout = parseInt((window.llAimData && window.llAimData.requestTimeoutMs) || '15000', 10) || 15000;
    const requestTimeoutMs = Math.max(25, Math.min(60000, configuredTimeout));

    const $start = $('#ll-aim-start');
    const $skip = $('#ll-aim-skip');
    const $stage = $('#ll-aim-stage');
    const $images = $('#ll-aim-images');
    const $title = $('#ll-aim-word-title');
    const $audio = $('#ll-aim-audio');
    const $extra = $('#ll-aim-extra');
    const $status = $('#ll-aim-status');
    const $retry = $('#ll-aim-retry');
    const $catSel = $('#ll-aim-category');
    const $wsSel = $('#ll-aim-wordset');
    const $rematch = $('#ll-aim-rematch');
    const $currentWrap = $('#ll-aim-current-thumb');
    const $currentImg = $('#ll-aim-current-thumb img');
    const $currentCap = $('#ll-aim-current-thumb .ll-aim-cap');
    const $hideUsed = $('#ll-aim-hide-used');
    const $loadMoreImages = $('#ll-aim-load-more-images');
    const $imagePageStatus = $('#ll-aim-image-page-status');

    let termId = 0;
    let wordsetId = 0;
    let excludeIds = [];
    let cachedImages = [];
    let imageOffset = 0;
    let imagesHaveMore = false;
    let currentWord = null;
    let contextGeneration = 0;
    let categoryOptionsLoading = false;
    let startInFlight = false;
    let nextInFlight = false;
    let imagePageLoading = false;
    let assignInFlight = false;
    let retryAction = null;
    let retryFocusTarget = null;

    const categoryOptionsCache = {};
    const pendingCategoryOptionsRequests = {};
    const activeRequests = new Map();

    categoryOptionsCache[String(initialWordsetId)] = initialCategoryRows.slice();

    function t(key, fallback) {
        const value = i18n[key];
        return (typeof value === 'string' && value.length) ? value : fallback;
    }

    function format(template, value) {
        return String(template || '').replace('%s', String(value || ''));
    }

    function getAjaxBase() {
        if (window.llAimData && typeof window.llAimData.ajaxurl === 'string' && window.llAimData.ajaxurl.length) {
            try {
                return new URL(window.llAimData.ajaxurl, window.location.origin).toString();
            } catch (e) {
                // Continue to the WordPress global or the standard admin URL.
            }
        }
        if (typeof ajaxurl === 'string' && ajaxurl.length) {
            try {
                return new URL(ajaxurl, window.location.origin).toString();
            } catch (e) {
                // Continue to the standard admin URL.
            }
        }
        return new URL('/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function requestError(code) {
        const error = new Error(code);
        error.llAimCode = code;
        return error;
    }

    async function requestJson(url, options) {
        const requestOptions = Object.assign({}, options || {});
        const cancellable = requestOptions.cancellable !== false;
        const timeoutEnabled = requestOptions.timeout !== false;
        delete requestOptions.cancellable;
        delete requestOptions.timeout;

        const controller = new AbortController();
        requestOptions.signal = controller.signal;
        activeRequests.set(controller, cancellable);

        let timedOut = false;
        let timeoutId = null;
        const abortPromise = new Promise((resolve, reject) => {
            controller.signal.addEventListener('abort', function () {
                reject(requestError(timedOut ? 'timeout' : 'cancelled'));
            }, { once: true });
        });

        if (timeoutEnabled) {
            timeoutId = window.setTimeout(function () {
                timedOut = true;
                controller.abort();
            }, requestTimeoutMs);
        }

        try {
            const response = await Promise.race([
                window.fetch(url, requestOptions),
                abortPromise
            ]);
            if (!response || response.ok === false) {
                throw requestError('http');
            }

            const json = await response.json();
            if (!json || json.success !== true) {
                throw requestError('server');
            }
            return json;
        } finally {
            if (timeoutId !== null) {
                window.clearTimeout(timeoutId);
            }
            activeRequests.delete(controller);
        }
    }

    function isCancelled(error) {
        return !!(error && error.llAimCode === 'cancelled');
    }

    function errorMessage(error, fallback) {
        if (error && error.llAimCode === 'timeout') {
            return t('requestTimedOut', 'The request timed out. Please try again.');
        }
        return fallback;
    }

    function clearFeedback() {
        retryAction = null;
        retryFocusTarget = null;
        $status.removeClass('is-error').text('');
        $retry.prop('hidden', true).hide().prop('disabled', false);
    }

    function showLoading(message) {
        retryAction = null;
        retryFocusTarget = null;
        $status.removeClass('is-error').text(message || t('loadingDefault', 'Loading...'));
        $retry.prop('hidden', true).hide().prop('disabled', false);
    }

    function showError(error, fallback, onRetry, $focusTarget) {
        $status.addClass('is-error').text(errorMessage(error, fallback));
        retryAction = (typeof onRetry === 'function') ? onRetry : null;
        retryFocusTarget = ($focusTarget && $focusTarget.length) ? $focusTarget : null;
        $retry
            .text(t('retryButton', 'Retry'))
            .prop('hidden', !retryAction)
            .toggle(!!retryAction)
            .prop('disabled', false);
    }

    function isBusy() {
        return categoryOptionsLoading || startInFlight || nextInFlight || imagePageLoading || assignInFlight;
    }

    function updateImagePageControls() {
        $loadMoreImages.prop('hidden', !imagesHaveMore).toggle(imagesHaveMore);
        $loadMoreImages
            .text(imagePageLoading
                ? t('loadingMoreImages', 'Loading more...')
                : t('loadMoreImages', 'Load more'))
            .prop('disabled', isBusy() || !imagesHaveMore);
    }

    function refreshControls() {
        const busy = isBusy();
        $start.prop('disabled', busy);
        $skip.prop('disabled', busy || !currentWord);
        $wsSel.prop('disabled', busy);
        $catSel.prop('disabled', busy);
        $rematch.prop('disabled', busy);
        $hideUsed.prop('disabled', busy || $rematch.is(':checked'));
        $images.find('.ll-aim-card').prop('disabled', busy || !currentWord);
        $retry.prop('disabled', busy || typeof retryAction !== 'function');
        updateImagePageControls();
    }

    function focusImage(imageId) {
        window.setTimeout(function () {
            let $target = $();
            if (imageId) {
                $target = $images.find('.ll-aim-card[data-img-id="' + String(imageId) + '"]');
            }
            if (!$target.length) {
                $target = $images.find('.ll-aim-card:not(:disabled)').first();
            }
            if ($target.length && $target.is(':visible') && !$target.prop('disabled')) {
                $target.trigger('focus');
            }
        }, 0);
    }

    function resetImagePages() {
        cachedImages = [];
        imageOffset = 0;
        imagesHaveMore = false;
        imagePageLoading = false;
        $imagePageStatus.text('');
        updateImagePageControls();
    }

    function resetCurrentDisplay() {
        currentWord = null;
        $title.html('&nbsp;');
        $audio.removeAttr('src').hide();
        $extra.text('');
        $currentImg.attr({ src: '', alt: '' });
        $currentWrap.hide();
        $images.empty();
    }

    function uiIdle() {
        resetCurrentDisplay();
        $stage.hide().attr('aria-busy', 'false');
        refreshControls();
    }

    function cancelContextRequests() {
        contextGeneration += 1;
        activeRequests.forEach(function (cancellable, controller) {
            if (cancellable) {
                controller.abort();
            }
        });
        Object.keys(pendingCategoryOptionsRequests).forEach(function (key) {
            delete pendingCategoryOptionsRequests[key];
        });
        categoryOptionsLoading = false;
        startInFlight = false;
        nextInFlight = false;
        imagePageLoading = false;
        clearFeedback();
        $imagePageStatus.text('');
        return contextGeneration;
    }

    async function fetchCategoryOptions(wordsetIdValue) {
        const wordsetKey = String(parseInt(wordsetIdValue || '0', 10) || 0);
        if (Array.isArray(categoryOptionsCache[wordsetKey])) {
            return categoryOptionsCache[wordsetKey];
        }

        if (pendingCategoryOptionsRequests[wordsetKey]) {
            return pendingCategoryOptionsRequests[wordsetKey];
        }

        let request = null;
        request = (async function () {
            const url = new URL(getAjaxBase());
            url.searchParams.set('action', 'll_aim_get_category_options');
            url.searchParams.set('wordset_id', wordsetKey);
            if (window.llAimData && window.llAimData.nonce) {
                url.searchParams.set('nonce', window.llAimData.nonce);
            }

            try {
                const json = await requestJson(url.toString(), {
                    credentials: 'same-origin'
                });
                if (!json.data || !Array.isArray(json.data.rows)) {
                    throw requestError('invalid');
                }
                categoryOptionsCache[wordsetKey] = json.data.rows;
                return json.data.rows;
            } finally {
                if (pendingCategoryOptionsRequests[wordsetKey] === request) {
                    delete pendingCategoryOptionsRequests[wordsetKey];
                }
            }
        })();

        pendingCategoryOptionsRequests[wordsetKey] = request;
        return request;
    }

    function replaceCategoryOptions(rows, preferredValue) {
        const currentValue = (preferredValue !== undefined && preferredValue !== null)
            ? String(preferredValue)
            : String($catSel.val() || '');

        $catSel.empty().append($('<option/>', {
            value: '',
            text: t('selectOption', '— Select —')
        }));

        rows.forEach(function (row) {
            const id = parseInt(row && row.id ? row.id : 0, 10) || 0;
            if (!id) {
                return;
            }
            $catSel.append($('<option/>', {
                value: String(id),
                text: (row && row.label ? row.label : '').toString()
            }).attr('data-slug', (row && row.slug ? row.slug : '').toString()));
        });

        if (currentValue && $catSel.find('option[value="' + currentValue + '"]').length) {
            $catSel.val(currentValue);
        } else {
            $catSel.val('');
        }
    }

    async function renderCategoryOptions(preferredValue, expectedGeneration) {
        if (!$catSel.length) {
            return true;
        }

        const generation = (expectedGeneration === undefined) ? contextGeneration : expectedGeneration;
        const selectedWordsetId = parseInt(($wsSel.val() || '0'), 10) || 0;
        const cacheKey = String(selectedWordsetId);
        const needsRequest = !Array.isArray(categoryOptionsCache[cacheKey]);
        categoryOptionsLoading = needsRequest;
        if (needsRequest) {
            showLoading(t('loadingCategories', 'Loading categories…'));
        }
        refreshControls();

        try {
            const rows = await fetchCategoryOptions(selectedWordsetId);
            if (generation !== contextGeneration) {
                return false;
            }
            replaceCategoryOptions(rows, preferredValue);
            clearFeedback();
            return true;
        } catch (error) {
            if (generation !== contextGeneration || isCancelled(error)) {
                return false;
            }
            showError(
                error,
                t('categoryLoadError', 'Could not load categories.'),
                function () {
                    return renderCategoryOptions(preferredValue, contextGeneration);
                },
                $wsSel
            );
            return false;
        } finally {
            if (generation === contextGeneration) {
                categoryOptionsLoading = false;
                refreshControls();
            }
        }
    }

    async function fetchImagePage(reset, onRetry) {
        if (imagePageLoading || (!reset && !imagesHaveMore)) {
            return false;
        }

        if (reset) {
            resetImagePages();
            showLoading(t('loadingImages', 'Loading images...'));
        } else {
            $imagePageStatus.text(t('loadingMoreImages', 'Loading more...'));
        }

        const generation = contextGeneration;
        const requestedOffset = reset ? 0 : imageOffset;
        imagePageLoading = true;
        $stage.attr('aria-busy', 'true');
        refreshControls();

        const url = new URL(getAjaxBase());
        url.searchParams.set('action', 'll_aim_get_images');
        url.searchParams.set('term_id', String(termId));
        url.searchParams.set('hide_used', $hideUsed.is(':checked') ? '1' : '0');
        url.searchParams.set('offset', String(requestedOffset));
        if (wordsetId > 0) {
            url.searchParams.set('wordset_id', String(wordsetId));
        }
        if (window.llAimData && window.llAimData.nonce) {
            url.searchParams.set('nonce', window.llAimData.nonce);
        }

        try {
            const json = await requestJson(url.toString(), {
                credentials: 'same-origin'
            });
            if (generation !== contextGeneration) {
                return false;
            }
            if (!json.data || !Array.isArray(json.data.images)) {
                throw requestError('invalid');
            }

            const page = json.data.images;
            const seen = new Set(cachedImages.map(function (image) {
                return parseInt(image && image.id ? image.id : 0, 10) || 0;
            }));
            page.forEach(function (image) {
                const imageId = parseInt(image && image.id ? image.id : 0, 10) || 0;
                if (imageId && !seen.has(imageId)) {
                    cachedImages.push(image);
                    seen.add(imageId);
                }
            });

            const nextOffset = parseInt(json.data.next_offset, 10);
            imagesHaveMore = !!json.data.has_more;
            if (imagesHaveMore && (!Number.isFinite(nextOffset) || nextOffset <= requestedOffset)) {
                throw requestError('invalid');
            }
            imageOffset = Number.isFinite(nextOffset)
                ? Math.max(requestedOffset, nextOffset)
                : requestedOffset + page.length;
            $imagePageStatus.text('');
            clearFeedback();
            return true;
        } catch (error) {
            if (generation !== contextGeneration || isCancelled(error)) {
                return false;
            }
            $imagePageStatus.text('');
            showError(
                error,
                t('imageLoadError', 'Could not load images.'),
                onRetry,
                reset ? $start : $loadMoreImages
            );
            return false;
        } finally {
            if (generation === contextGeneration) {
                imagePageLoading = false;
                $stage.attr('aria-busy', 'false');
                refreshControls();
            }
        }
    }

    function renderCurrentWord() {
        $stage.show();
        if (!currentWord) {
            $title.text(t('allDoneCategory', 'All done in this category.'));
            $audio.removeAttr('src').hide();
            $extra.text('');
            $images.empty();
            $currentImg.attr({ src: '', alt: '' });
            $currentWrap.hide();
            return;
        }

        $title.text(currentWord.title || '');
        if (currentWord.audio_url) {
            $audio.attr('src', currentWord.audio_url).show();
            try {
                $audio[0].currentTime = 0;
                const playResult = $audio[0].play();
                if (playResult && typeof playResult.catch === 'function') {
                    playResult.catch(function () {});
                }
            } catch (e) {
                // Browser autoplay policies may require the user to press play.
            }
        } else {
            $audio.removeAttr('src').hide();
        }

        if (currentWord.translation) {
            const translationPrefix = t('translationPrefix', 'Translation:');
            $extra.text(translationPrefix
                ? translationPrefix + ' ' + currentWord.translation
                : currentWord.translation);
        } else {
            $extra.text('');
        }

        if (currentWord.current_thumb) {
            $currentImg.attr({
                src: currentWord.current_thumb,
                alt: currentWord.title || ''
            });
            $currentCap.text(t('currentImageCaption', 'Current image (will be replaced if you pick a new one)'));
            $currentWrap.show();
        } else {
            $currentImg.attr({ src: '', alt: '' });
            $currentWrap.hide();
        }

        buildImageGrid();
    }

    async function fetchNext(options) {
        if (nextInFlight) {
            return false;
        }

        const settings = Object.assign({ focusFirst: false }, options || {});
        const generation = contextGeneration;
        nextInFlight = true;
        currentWord = null;
        $stage.attr('aria-busy', 'true');
        showLoading(t('loadingNextAudio', 'Loading next audio...'));
        refreshControls();

        const url = new URL(getAjaxBase());
        url.searchParams.set('action', 'll_aim_get_next');
        url.searchParams.set('term_id', String(termId));
        url.searchParams.set('rematch', $rematch.is(':checked') ? '1' : '0');
        if (wordsetId > 0) {
            url.searchParams.set('wordset_id', String(wordsetId));
        }
        if (window.llAimData && window.llAimData.nonce) {
            url.searchParams.set('nonce', window.llAimData.nonce);
        }
        excludeIds.forEach(function (id) {
            url.searchParams.append('exclude[]', String(id));
        });

        let succeeded = false;
        try {
            const json = await requestJson(url.toString(), {
                credentials: 'same-origin'
            });
            if (generation !== contextGeneration) {
                return false;
            }
            if (!json.data || !Object.prototype.hasOwnProperty.call(json.data, 'item')) {
                throw requestError('invalid');
            }

            currentWord = json.data.item || null;
            renderCurrentWord();
            clearFeedback();
            succeeded = true;
            return true;
        } catch (error) {
            if (generation !== contextGeneration || isCancelled(error)) {
                return false;
            }
            showError(
                error,
                t('nextLoadError', 'Could not load the next audio.'),
                function () {
                    return fetchNext({ focusFirst: true });
                },
                $skip
            );
            return false;
        } finally {
            if (generation === contextGeneration) {
                nextInFlight = false;
                $stage.attr('aria-busy', 'false');
                refreshControls();
                if (succeeded && settings.focusFirst && !isBusy()) {
                    if (currentWord) {
                        focusImage();
                    } else {
                        $start.trigger('focus');
                    }
                }
            }
        }
    }

    function buildImageGrid() {
        $images.empty();

        const list = ($hideUsed.is(':checked')
            ? cachedImages.filter(function (image) {
                return !(image.used_count && image.used_count > 0);
            })
            : cachedImages.slice()
        ).sort(function (a, b) {
            const aUsed = a.used_count && a.used_count > 0 ? 1 : 0;
            const bUsed = b.used_count && b.used_count > 0 ? 1 : 0;
            return aUsed - bUsed;
        });

        if (!list.length) {
            $images.append($('<p/>', {
                class: 'll-aim-empty',
                text: t('noImagesFound', 'No images found in this category.')
            }));
            return;
        }

        const imageSize = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.imageSize) || 'small';
        list.forEach(function (image) {
            const imageId = parseInt(image && image.id ? image.id : 0, 10) || 0;
            if (!imageId) {
                return;
            }

            const imageTitle = (image.title || '').toString();
            const usedCount = Math.max(0, parseInt(image.used_count || '0', 10) || 0);
            let accessibleLabel = format(t('imageChoiceLabel', 'Choose image: %s'), imageTitle);
            if (usedCount > 0) {
                accessibleLabel += '. ' + t('alreadyPickedLabel', 'Already picked');
            }

            const $card = $('<button/>', {
                type: 'button',
                class: 'll-aim-card',
                title: imageTitle,
                'aria-label': accessibleLabel
            }).attr('data-img-id', String(imageId));
            const $imageWrapper = $('<span/>', {
                class: 'll-aim-image-wrapper flashcard-container flashcard-size-' + imageSize
            });
            const $image = $('<img/>', {
                src: image.thumb || '',
                alt: '',
                class: 'quiz-image'
            });
            $imageWrapper.append($image);

            const $titleElement = $('<span/>', {
                class: 'll-aim-title',
                text: imageTitle
            });
            const $idElement = $('<span/>', {
                class: 'll-aim-small',
                text: '#' + String(imageId)
            });

            if (usedCount > 0) {
                $card.addClass('is-picked');
                const badgeLabel = t('pickedBadge', 'Picked');
                const badgeText = badgeLabel + (usedCount > 1 ? ' ×' + String(usedCount) : '');
                $imageWrapper.append($('<span/>', {
                    class: 'll-aim-badge',
                    text: badgeText,
                    'aria-hidden': 'true'
                }));
            }

            $card.append($imageWrapper, $titleElement, $idElement);
            $card.on('click', function () {
                assign(imageId, $card);
            });
            $images.append($card);
        });

        refreshControls();
    }

    async function assign(imageId, $card) {
        if (isBusy() || !currentWord || !$card || !$card.length) {
            return false;
        }

        const generation = contextGeneration;
        const assignedWord = currentWord;
        const previousImageId = Math.max(
            0,
            parseInt(assignedWord.current_image_id || '0', 10) || 0
        );
        const cachedImage = cachedImages.find(function (image) {
            return parseInt(image && image.id ? image.id : 0, 10) === imageId;
        });
        const previousUsedCount = cachedImage
            ? Math.max(0, parseInt(cachedImage.used_count || '0', 10) || 0)
            : 0;

        assignInFlight = true;
        $card
            .addClass('is-saving')
            .attr('aria-busy', 'true');
        showLoading(t('savingMatch', 'Saving match...'));
        refreshControls();

        const body = new URLSearchParams();
        body.set('action', 'll_aim_assign');
        body.set('word_id', String(assignedWord.id));
        body.set('image_id', String(imageId));
        if (window.llAimData && window.llAimData.nonce) {
            body.set('nonce', window.llAimData.nonce);
        }

        let saved = false;
        let nextLoaded = false;
        try {
            await requestJson(getAjaxBase(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                cancellable: false,
                // A timed-out mutating fetch may still complete in PHP. Keep
                // this request serialized and visibly saving until its
                // authoritative response arrives instead of enabling an
                // overlapping second assignment.
                timeout: false
            });
            if (generation !== contextGeneration) {
                return false;
            }

            saved = true;
            cachedImages = cachedImages.map(function (image) {
                const cachedImageId = parseInt(image && image.id ? image.id : 0, 10) || 0;
                const currentCount = Math.max(0, parseInt(image.used_count || '0', 10) || 0);
                if (previousImageId !== imageId && cachedImageId === previousImageId) {
                    return Object.assign({}, image, { used_count: Math.max(0, currentCount - 1) });
                }
                if (previousImageId !== imageId && cachedImageId === imageId) {
                    return Object.assign({}, image, { used_count: previousUsedCount + 1 });
                }
                return image;
            });
            if (!excludeIds.includes(assignedWord.id)) {
                excludeIds.push(assignedWord.id);
            }
            currentWord = null;
            nextLoaded = await fetchNext({ focusFirst: false });
            return true;
        } catch (error) {
            if (generation !== contextGeneration || isCancelled(error)) {
                return false;
            }
            currentWord = assignedWord;
            showError(
                error,
                t('saveError', 'Error saving match.'),
                function () {
                    const $retryCard = $images.find('.ll-aim-card[data-img-id="' + String(imageId) + '"]');
                    return assign(imageId, $retryCard);
                },
                $card
            );
            return false;
        } finally {
            if (generation === contextGeneration) {
                assignInFlight = false;
                $card.removeClass('is-saving').removeAttr('aria-busy');
                refreshControls();
                if (!saved) {
                    focusImage(imageId);
                } else if (nextLoaded) {
                    if (currentWord) {
                        focusImage();
                    } else {
                        $start.trigger('focus');
                    }
                }
            }
        }
    }

    async function startMatching() {
        if (isBusy()) {
            return false;
        }

        const selectedTermId = parseInt(($catSel.val() || '0'), 10) || 0;
        const selectedWordsetId = parseInt(($wsSel.val() || '0'), 10) || 0;
        const generation = cancelContextRequests();
        termId = selectedTermId;
        wordsetId = selectedWordsetId;
        excludeIds = [];
        resetImagePages();
        uiIdle();

        if (!termId) {
            showError(null, t('selectCategoryPrompt', 'Please select a category.'), null, $catSel);
            $catSel.trigger('focus');
            return false;
        }

        startInFlight = true;
        refreshControls();
        let nextLoaded = false;
        try {
            const imagesLoaded = await fetchImagePage(true, startMatching);
            if (!imagesLoaded || generation !== contextGeneration) {
                return false;
            }
            nextLoaded = await fetchNext({ focusFirst: false });
            return nextLoaded;
        } finally {
            if (generation === contextGeneration) {
                startInFlight = false;
                refreshControls();
                if (nextLoaded) {
                    if (currentWord) {
                        focusImage();
                    } else {
                        $start.trigger('focus');
                    }
                }
            }
        }
    }

    async function loadMoreImages() {
        if (isBusy() || !imagesHaveMore) {
            return false;
        }

        const previousIds = new Set(cachedImages.map(function (image) {
            return parseInt(image && image.id ? image.id : 0, 10) || 0;
        }));
        const loaded = await fetchImagePage(false, loadMoreImages);
        if (!loaded) {
            return false;
        }

        buildImageGrid();
        if (!imagesHaveMore) {
            const newlyLoaded = cachedImages.find(function (image) {
                const imageId = parseInt(image && image.id ? image.id : 0, 10) || 0;
                return imageId && !previousIds.has(imageId);
            });
            if (newlyLoaded) {
                focusImage(parseInt(newlyLoaded.id, 10) || 0);
            }
        }
        return true;
    }

    async function reloadImagesForCurrentWord() {
        if (isBusy() || !termId || !currentWord) {
            return false;
        }

        const generation = contextGeneration;
        startInFlight = true;
        resetImagePages();
        refreshControls();
        try {
            const loaded = await fetchImagePage(true, reloadImagesForCurrentWord);
            if (!loaded || generation !== contextGeneration) {
                return false;
            }
            buildImageGrid();
            return true;
        } finally {
            if (generation === contextGeneration) {
                startInFlight = false;
                refreshControls();
                if (currentWord) {
                    focusImage();
                }
            }
        }
    }

    $retry.on('click', async function () {
        if (isBusy() || typeof retryAction !== 'function') {
            return;
        }

        const action = retryAction;
        const $fallbackFocus = retryFocusTarget;
        retryAction = null;
        retryFocusTarget = null;
        $retry.prop('disabled', true);
        await action();
        if (!$retry.is(':visible') && $fallbackFocus && $fallbackFocus.length && !isBusy()) {
            $fallbackFocus.trigger('focus');
        }
    });

    $start.on('click', startMatching);

    $skip.on('click', async function () {
        if (isBusy() || !currentWord) {
            return;
        }
        const skippedWordId = parseInt(currentWord.id || '0', 10) || 0;
        if (skippedWordId && !excludeIds.includes(skippedWordId)) {
            excludeIds.push(skippedWordId);
        }
        await fetchNext({ focusFirst: true });
    });

    $loadMoreImages.on('click', loadMoreImages);

    $catSel.on('change', function () {
        if (isBusy()) {
            return;
        }
        cancelContextRequests();
        termId = 0;
        wordsetId = parseInt(($wsSel.val() || '0'), 10) || 0;
        excludeIds = [];
        resetImagePages();
        uiIdle();
    });

    $wsSel.on('change', async function () {
        if (isBusy()) {
            return;
        }
        const generation = cancelContextRequests();
        termId = 0;
        wordsetId = parseInt(($wsSel.val() || '0'), 10) || 0;
        excludeIds = [];
        resetImagePages();
        uiIdle();
        replaceCategoryOptions([], '');
        await renderCategoryOptions('', generation);
    });

    $rematch.on('change', function () {
        if ($(this).is(':checked')) {
            $hideUsed.prop('checked', false);
        }
        refreshControls();
    });

    $hideUsed.on('change', reloadImagesForCurrentWord);

    (async function preselectFromUrl() {
        const query = new URLSearchParams(window.location.search);
        const id = query.get('term_id') || query.get('category') || query.get('cat') || query.get('word_category');
        const slug = query.get('category_slug') || query.get('slug');
        const generation = contextGeneration;
        const rendered = await renderCategoryOptions(initialCategoryId, generation);
        if (!rendered || (!id && !slug)) {
            return;
        }

        let value = null;
        if (id) {
            const parsedId = String(parseInt(id, 10) || 0);
            if ($catSel.find('option[value="' + parsedId + '"]').length) {
                value = parsedId;
            }
        } else if (slug) {
            const normalizedSlug = String(slug).toLowerCase();
            const $slugOption = $catSel.find('option').filter(function () {
                return String($(this).attr('data-slug') || '').toLowerCase() === normalizedSlug;
            }).first();
            if ($slugOption.length) {
                value = $slugOption.val();
            } else {
                const $labelOption = $catSel.find('option').filter(function () {
                    return $(this).text().trim().toLowerCase() === normalizedSlug;
                }).first();
                if ($labelOption.length) {
                    value = $labelOption.val();
                }
            }
        }

        if (value) {
            $catSel.val(value).trigger('change');
        }
    })().catch(function (error) {
        if (!isCancelled(error)) {
            showError(
                error,
                t('categoryLoadError', 'Could not load categories.'),
                function () {
                    return renderCategoryOptions(initialCategoryId, contextGeneration);
                },
                $wsSel
            );
        }
    });

    if ($rematch.is(':checked')) {
        $hideUsed.prop('checked', false);
    }
    refreshControls();
})(jQuery);
