(function ($) {
    'use strict';

    const cfg = (window.llWebpOptimizerData && typeof window.llWebpOptimizerData === 'object')
        ? window.llWebpOptimizerData
        : {};
    const actions = (cfg.actions && typeof cfg.actions === 'object') ? cfg.actions : {};
    const i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};
    const screenCfg = (cfg.screen && typeof cfg.screen === 'object') ? cfg.screen : {};
    const ajaxUrl = String(cfg.ajaxUrl || window.ajaxurl || '');
    const nonce = String(cfg.nonce || '');
    const batchSize = Math.max(1, parseInt(cfg.batchSize, 10) || 8);
    const quality = Math.max(35, Math.min(100, parseInt(cfg.quality, 10) || 82));
    const preselectedWordImageId = Math.max(0, parseInt(cfg.preselectedWordImageId, 10) || 0);

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTemplate(template, values) {
        let output = String(template || '');
        const list = Array.isArray(values) ? values : [];
        list.forEach(function (value, index) {
            const safe = String(value === null || value === undefined ? '' : value);
            const oneBased = index + 1;
            output = output.replace(new RegExp('%' + oneBased + '\\$s', 'g'), safe);
            output = output.replace(new RegExp('%' + oneBased + '\\$d', 'g'), safe);
        });
        if (list.length) {
            output = output.replace(/%s/g, String(list[0]));
            output = output.replace(/%d/g, String(list[0]));
        }
        return output;
    }

    function request(actionKey, payload) {
        const action = String(actions[actionKey] || '');
        if (!action || !ajaxUrl || !nonce) {
            return $.Deferred().reject({ message: 'AJAX unavailable' }).promise();
        }
        return $.post(ajaxUrl, Object.assign({
            action: action,
            nonce: nonce
        }, payload || {}));
    }

    function chunkIds(ids, size) {
        const out = [];
        const chunkSize = Math.max(1, size || 1);
        for (let i = 0; i < ids.length; i += chunkSize) {
            out.push(ids.slice(i, i + chunkSize));
        }
        return out;
    }

    function formatBytes(bytes) {
        const value = Math.max(0, parseInt(bytes, 10) || 0);
        if (value >= 1073741824) {
            return (Math.round((value / 1073741824) * 10) / 10) + ' GB';
        }
        if (value >= 1048576) {
            return (Math.round((value / 1048576) * 10) / 10) + ' MB';
        }
        if (value >= 1024) {
            return (Math.round((value / 1024) * 10) / 10) + ' KB';
        }
        return value + ' B';
    }

    function normalizeItem(item) {
        return (item && typeof item === 'object') ? item : {};
    }

    function statusClassForItem(item) {
        const statusKey = String(item.status_key || '');
        if (statusKey === 'needs') {
            return 'is-needs';
        }
        if (statusKey === 'ok') {
            return 'is-ok';
        }
        if (statusKey === 'unsupported' || statusKey === 'missing') {
            return 'is-warn';
        }
        return 'is-muted';
    }

    function renderReasonBadges(item) {
        const labels = Array.isArray(item.reason_labels) ? item.reason_labels.filter(Boolean) : [];
        if (!labels.length) {
            return '';
        }
        return labels.map(function (label) {
            return '<span class="ll-webp-badge ll-webp-badge--reason">' + escapeHtml(label) + '</span>';
        }).join('');
    }

    function getPrimaryActionLabel(item) {
        const row = normalizeItem(item);
        const isWebp = !!row.is_webp;
        const needsConversion = !!row.needs_conversion;
        if (isWebp && needsConversion) {
            const thresholdLabel = String(row.threshold_label || cfg.thresholdLabel || '300 KB');
            return formatTemplate(i18n.compressToThreshold || 'Optimize to %s', [thresholdLabel]);
        }
        return String(i18n.convertOne || 'Optimize Image');
    }

    function renderToolCard(item, extra) {
        const row = normalizeItem(item);
        const opts = (extra && typeof extra === 'object') ? extra : {};
        const wordImageId = parseInt(row.word_image_id, 10) || 0;
        const title = String(row.title || ('#' + wordImageId));
        const editUrl = String(row.edit_url || '');
        const thumb = String(row.thumbnail_url || '');
        const categories = Array.isArray(row.categories) ? row.categories.filter(Boolean) : [];
        const categoryText = categories.length ? categories.join(', ') : '';
        const metaBits = [];
        if (row.format_label) {
            metaBits.push(String(row.format_label));
        }
        if (row.file_size_label) {
            metaBits.push(String(row.file_size_label));
        }
        if (row.dimensions_label) {
            metaBits.push(String(row.dimensions_label));
        }
        const metaText = metaBits.join(' · ');
        const canConvert = !!row.can_convert && !!cfg.encodingSupported;
        const needsConversion = !!row.needs_conversion;
        const isFocus = !!opts.isFocus;
        const focusLabel = String(i18n.focusLabel || 'Opened from list view');
        const statusLabel = String(row.status_label || '');
        const problemLabel = String(row.problem_label || '');
        const primaryActionLabel = getPrimaryActionLabel(row);
        return ''
            + '<article class="ll-webp-card' + (isFocus ? ' is-focus' : '') + '" data-ll-webp-card data-word-image-id="' + wordImageId + '">'
            + (isFocus ? '<div class="ll-webp-card__focus-label">' + escapeHtml(focusLabel) + '</div>' : '')
            + '<div class="ll-webp-card__media">'
            + (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" loading="lazy">' : '<div class="ll-webp-card__media-empty"></div>')
            + '</div>'
            + '<div class="ll-webp-card__body">'
            + '<div class="ll-webp-card__header">'
            + '<h3 class="ll-webp-card__title">'
            + (editUrl ? ('<a href="' + escapeHtml(editUrl) + '">' + escapeHtml(title) + '</a>') : escapeHtml(title))
            + '</h3>'
            + '<span class="ll-webp-pill ' + escapeHtml(statusClassForItem(row)) + '">' + escapeHtml(statusLabel || 'Unknown') + '</span>'
            + '</div>'
            + (metaText ? '<div class="ll-webp-card__meta">' + escapeHtml(metaText) + '</div>' : '')
            + (categoryText ? '<div class="ll-webp-card__cats">' + escapeHtml(categoryText) + '</div>' : '')
            + (problemLabel ? '<div class="ll-webp-card__problem">' + escapeHtml(problemLabel) + '</div>' : '')
            + (needsConversion ? '<div class="ll-webp-card__reasons">' + renderReasonBadges(row) + '</div>' : '')
            + '<div class="ll-webp-card__actions">'
            + (canConvert
                ? ('<button type="button" class="button button-primary ll-webp-card__convert" data-ll-webp-convert-card data-word-image-id="' + wordImageId + '">'
                    + '<span class="ll-webp-button__text">' + escapeHtml(primaryActionLabel) + '</span>'
                    + '<span class="ll-webp-button__spinner" aria-hidden="true"></span>'
                    + '</button>')
                : '<button type="button" class="button ll-webp-card__convert" disabled>' + escapeHtml(statusLabel || 'Unavailable') + '</button>')
            + '</div>'
            + '<div class="ll-webp-card__feedback" data-ll-webp-card-feedback aria-live="polite"></div>'
            + '</div>'
            + '</article>';
    }

    function setCardFeedback(wordImageId, message, type) {
        const id = parseInt(wordImageId, 10) || 0;
        if (!id) {
            return;
        }
        const $card = $('[data-ll-webp-card][data-word-image-id="' + id + '"]');
        if (!$card.length) {
            return;
        }
        const $feedback = $card.find('[data-ll-webp-card-feedback]');
        const text = String(message || '').trim();
        const kind = String(type || 'info');
        if (!text) {
            $feedback.removeClass('is-success is-error is-info').empty();
            return;
        }
        $feedback.removeClass('is-success is-error is-info').addClass('is-' + kind).text(text);
    }

    function replaceCard(item, options) {
        const row = normalizeItem(item);
        const id = parseInt(row.word_image_id, 10) || 0;
        if (!id) {
            return;
        }
        const $existing = $('[data-ll-webp-card][data-word-image-id="' + id + '"]');
        if (!$existing.length) {
            return;
        }
        const isFocus = $existing.hasClass('is-focus') || !!(options && options.isFocus);
        $existing.replaceWith(renderToolCard(row, { isFocus: isFocus }));
    }

    function initWordImagesListInline() {
        if (!screenCfg.isWordImagesList) {
            return;
        }

        $(document).on('click', '[data-ll-webp-inline-convert]', function (event) {
            event.preventDefault();
            if (!cfg.encodingSupported) {
                return;
            }

            const $button = $(this);
            if ($button.prop('disabled')) {
                return;
            }

            const wordImageId = parseInt($button.attr('data-word-image-id') || $button.data('wordImageId'), 10) || 0;
            if (!wordImageId) {
                return;
            }

            const $root = $button.closest('[data-ll-webp-list-cell-root]');
            const $status = $root.find('[data-ll-webp-inline-status]');

            $button.prop('disabled', true).addClass('is-working');
            $status.removeClass('is-success is-error').text(i18n.working || 'Optimizing...');

            request('convert', {
                word_image_ids: [wordImageId],
                quality: quality
            }).done(function (res) {
                if (!res || !res.success || !res.data || !Array.isArray(res.data.results) || !res.data.results.length) {
                    $status.addClass('is-error').text(i18n.convertFailed || 'Could not optimize this image right now.');
                    return;
                }

                const result = res.data.results[0] || {};
                const html = String(result.list_cell_html || '');
                const message = String(result.message || (result.success ? (i18n.convertSuccess || 'Optimized image and updated the word image.') : (i18n.convertFailed || 'Could not optimize this image right now.')));

                if (html) {
                    $root.replaceWith(html);
                    const $newRoot = $('[data-ll-webp-list-cell-root][data-word-image-id="' + wordImageId + '"]');
                    const $newStatus = $newRoot.find('[data-ll-webp-inline-status]');
                    $newStatus.removeClass('is-success is-error').addClass(result.success ? 'is-success' : 'is-error').text(message);
                } else {
                    $button.removeClass('is-working').prop('disabled', false);
                    $status.removeClass('is-success is-error').addClass(result.success ? 'is-success' : 'is-error').text(message);
                }
            }).fail(function (xhr) {
                const message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    ? String(xhr.responseJSON.data.message)
                    : (i18n.convertFailed || 'Could not optimize this image right now.');
                $button.removeClass('is-working').prop('disabled', false);
                $status.removeClass('is-success is-error').addClass('is-error').text(message);
            });
        });
    }

    function initToolPage() {
        if (!screenCfg.isToolPage) {
            return;
        }

        const $root = $('[data-ll-webp-optimizer-root]');
        if (!$root.length) {
            return;
        }

        const $cards = $root.find('[data-ll-webp-cards]');
        const $summary = $root.find('[data-ll-webp-summary]');
        const $pagination = $root.find('[data-ll-webp-pagination]');
        const $status = $root.find('[data-ll-webp-status]');
        const $progress = $root.find('[data-ll-webp-progress]');
        const $progressFill = $root.find('[data-ll-webp-progress-fill]');
        const $progressLabel = $root.find('[data-ll-webp-progress-label]');
        const $categoryFilter = $root.find('[data-ll-webp-filter-category]');
        const $searchFilter = $root.find('[data-ll-webp-filter-search]');
        const $applyFilters = $root.find('[data-ll-webp-apply-filters]');
        const $refresh = $root.find('[data-ll-webp-refresh]');
        const $convertAll = $root.find('[data-ll-webp-convert-all]');

        const state = {
            page: 1,
            perPage: 18,
            totalPages: 1,
            totalItems: 0,
            items: [],
            summary: {},
            focusItem: null,
            isBusy: false,
            isQueueLoading: false
        };

        function setStatus(message, type) {
            const text = String(message || '').trim();
            if (!text) {
                $status.removeClass('is-success is-error is-info is-loading').empty().prop('hidden', true);
                return;
            }
            const kind = String(type || 'info');
            $status.removeClass('is-success is-error is-info is-loading').addClass('is-' + kind).text(text).prop('hidden', false);
        }

        function setProgress(current, total, label) {
            const max = Math.max(0, parseInt(total, 10) || 0);
            const now = Math.max(0, parseInt(current, 10) || 0);
            if (!max) {
                $progress.prop('hidden', true);
                $progressFill.css('width', '0%');
                $progressLabel.empty();
                return;
            }
            const percent = Math.max(0, Math.min(100, Math.round((now / max) * 100)));
            $progress.prop('hidden', false);
            $progress.find('.ll-webp-progress__bar').attr('aria-valuenow', String(percent));
            $progressFill.css('width', percent + '%');
            $progressLabel.text(String(label || ''));
        }

        function setBusy(isBusy) {
            state.isBusy = !!isBusy;
            $root.toggleClass('is-busy', state.isBusy);
            $applyFilters.prop('disabled', state.isBusy);
            $refresh.prop('disabled', state.isBusy);
            $categoryFilter.prop('disabled', state.isBusy);
            $searchFilter.prop('disabled', state.isBusy);
            $convertAll.prop('disabled', state.isBusy || !cfg.encodingSupported);
            $convertAll.toggleClass('is-working', state.isBusy);
        }

        function setQueueLoading(isLoading) {
            state.isQueueLoading = !!isLoading;
            if (state.isQueueLoading) {
                $cards.html(''
                    + '<div class="ll-webp-card-skeleton"></div>'
                    + '<div class="ll-webp-card-skeleton"></div>'
                    + '<div class="ll-webp-card-skeleton"></div>'
                    + '<div class="ll-webp-card-skeleton"></div>');
            }
        }

        function currentFilters() {
            return {
                category_id: parseInt($categoryFilter.val(), 10) || 0,
                search: String($searchFilter.val() || '').trim()
            };
        }

        function renderSummary() {
            const summary = (state.summary && typeof state.summary === 'object') ? state.summary : {};
            const queuedCount = Math.max(0, parseInt(summary.queued_count, 10) || 0);
            const queuedBytesLabel = String(summary.queued_bytes_label || '0 B');
            const nonWebpCount = Math.max(0, parseInt(summary.non_webp_count, 10) || 0);
            const oversizeCount = Math.max(0, parseInt(summary.oversize_count, 10) || 0);
            const supportedCount = Math.max(0, parseInt(summary.supported_count, 10) || 0);
            const thresholdLabel = String(summary.threshold_label || cfg.thresholdLabel || '300 KB');
            const animatedThresholdLabel = String(
                summary.animated_webp_threshold_label
                || cfg.animatedWebpThresholdLabel
                || thresholdLabel
            );
            const animatedWebpLabel = String(i18n.animatedWebpLabel || 'animated WebP');
            const oversizeThresholdText = (animatedThresholdLabel && animatedThresholdLabel !== thresholdLabel)
                ? ('Over ' + thresholdLabel + ' (' + animatedWebpLabel + ': ' + animatedThresholdLabel + ')')
                : ('Over ' + thresholdLabel);

            $summary.html(''
                + '<div class="ll-webp-stat-card"><div class="ll-webp-stat-card__label">Flagged</div><div class="ll-webp-stat-card__value">' + escapeHtml(String(queuedCount)) + '</div><div class="ll-webp-stat-card__sub">Current filter queue</div></div>'
                + '<div class="ll-webp-stat-card"><div class="ll-webp-stat-card__label">Bytes in Queue</div><div class="ll-webp-stat-card__value">' + escapeHtml(queuedBytesLabel) + '</div><div class="ll-webp-stat-card__sub">Source file sizes</div></div>'
                + '<div class="ll-webp-stat-card"><div class="ll-webp-stat-card__label">Needs Format Upgrade</div><div class="ll-webp-stat-card__value">' + escapeHtml(String(nonWebpCount)) + '</div><div class="ll-webp-stat-card__sub">JPEG / PNG to WebP</div></div>'
                + '<div class="ll-webp-stat-card"><div class="ll-webp-stat-card__label">Oversized WebP</div><div class="ll-webp-stat-card__value">' + escapeHtml(String(oversizeCount)) + '</div><div class="ll-webp-stat-card__sub">' + escapeHtml(oversizeThresholdText) + ' (' + escapeHtml(String(supportedCount)) + ' supported)</div></div>');
        }

        function renderCards() {
            const items = Array.isArray(state.items) ? state.items : [];
            const focusItem = state.focusItem && typeof state.focusItem === 'object' ? state.focusItem : null;

            if (!items.length && !(focusItem && focusItem.word_image_id)) {
                $cards.html('<div class="ll-webp-empty">' + escapeHtml(i18n.emptyQueue || 'No word images currently need WebP optimization.') + '</div>');
                return;
            }

            const renderedIds = {};
            const html = [];

            if (focusItem && parseInt(focusItem.word_image_id, 10)) {
                const focusId = parseInt(focusItem.word_image_id, 10);
                let foundOnPage = false;
                items.forEach(function (item) {
                    if ((parseInt(item.word_image_id, 10) || 0) === focusId) {
                        foundOnPage = true;
                    }
                });
                if (!foundOnPage) {
                    html.push(renderToolCard(focusItem, { isFocus: true }));
                    renderedIds[focusId] = true;
                }
            }

            items.forEach(function (item) {
                const id = parseInt(item.word_image_id, 10) || 0;
                if (!id || renderedIds[id]) {
                    return;
                }
                const isFocus = !!focusItem && ((parseInt(focusItem.word_image_id, 10) || 0) === id);
                html.push(renderToolCard(item, { isFocus: isFocus }));
                renderedIds[id] = true;
            });

            $cards.html(html.join(''));
        }

        function renderPagination() {
            const totalPages = Math.max(1, parseInt(state.totalPages, 10) || 1);
            const page = Math.max(1, parseInt(state.page, 10) || 1);
            if (totalPages <= 1) {
                $pagination.empty().prop('hidden', true);
                return;
            }

            const label = formatTemplate(i18n.pageLabel || 'Page %1$d of %2$d', [page, totalPages]);
            $pagination.html(''
                + '<button type="button" class="button" data-ll-webp-page="prev" ' + (page <= 1 ? 'disabled' : '') + '>' + escapeHtml(i18n.prevPage || 'Previous') + '</button>'
                + '<span class="ll-webp-pagination__label">' + escapeHtml(label) + '</span>'
                + '<button type="button" class="button" data-ll-webp-page="next" ' + (page >= totalPages ? 'disabled' : '') + '>' + escapeHtml(i18n.nextPage || 'Next') + '</button>');
            $pagination.prop('hidden', false);
        }

        function updateCardButtonsWorking(ids, isWorking) {
            (Array.isArray(ids) ? ids : []).forEach(function (idRaw) {
                const id = parseInt(idRaw, 10) || 0;
                if (!id) {
                    return;
                }
                const $card = $cards.find('[data-ll-webp-card][data-word-image-id="' + id + '"]');
                const $button = $card.find('[data-ll-webp-convert-card]');
                if (!$button.length) {
                    return;
                }
                $button.toggleClass('is-working', !!isWorking);
                $button.prop('disabled', !!isWorking || state.isBusy);
            });
        }

        function loadQueue(options) {
            const opts = (options && typeof options === 'object') ? options : {};
            const filters = currentFilters();
            const page = Math.max(1, parseInt(opts.page || state.page || 1, 10) || 1);
            const focusWordImageId = (opts.includeFocus && preselectedWordImageId > 0) ? preselectedWordImageId : 0;
            const quietStatus = !!opts.quietStatus;

            setQueueLoading(true);
            if (!quietStatus) {
                setStatus(i18n.loadingQueue || 'Loading image queue...', 'loading');
            }

            return request('queue', {
                page: page,
                per_page: state.perPage,
                category_id: filters.category_id,
                search: filters.search,
                focus_word_image_id: focusWordImageId
            }).done(function (res) {
                if (!res || !res.success || !res.data) {
                    setStatus(i18n.queueFailed || 'Could not load the WebP optimization queue.', 'error');
                    return;
                }

                state.page = Math.max(1, parseInt(res.data.page, 10) || 1);
                state.totalPages = Math.max(1, parseInt(res.data.total_pages, 10) || 1);
                state.totalItems = Math.max(0, parseInt(res.data.total_items, 10) || 0);
                state.items = Array.isArray(res.data.items) ? res.data.items : [];
                state.summary = (res.data.summary && typeof res.data.summary === 'object') ? res.data.summary : {};
                state.focusItem = (res.data.focus_item && typeof res.data.focus_item === 'object' && Object.keys(res.data.focus_item).length)
                    ? res.data.focus_item
                    : null;

                renderSummary();
                renderCards();
                renderPagination();

                if (!cfg.encodingSupported || !res.data.encoding_supported) {
                    setStatus(i18n.webpUnavailable || 'WebP encoding is not available on this server. The queue can still be reviewed, but optimization is disabled.', 'info');
                } else if (!quietStatus) {
                    setStatus('', '');
                }
            }).fail(function (xhr) {
                const message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    ? String(xhr.responseJSON.data.message)
                    : (i18n.queueFailed || 'Could not load the WebP optimization queue.');
                setStatus(message, 'error');
                $cards.html('<div class="ll-webp-empty is-error">' + escapeHtml(message) + '</div>');
                $pagination.empty().prop('hidden', true);
                $summary.html('');
            }).always(function () {
                setQueueLoading(false);
            });
        }

        function fetchAllFlaggedIds() {
            const filters = currentFilters();
            return request('queue', {
                ids_only: 1,
                category_id: filters.category_id,
                search: filters.search
            }).then(function (res) {
                if (!res || !res.success || !res.data || !Array.isArray(res.data.ids)) {
                    return $.Deferred().reject({ message: i18n.queueFailed || 'Could not load the WebP optimization queue.' }).promise();
                }
                return res.data.ids.map(function (id) {
                    return parseInt(id, 10) || 0;
                }).filter(function (id) {
                    return id > 0;
                });
            });
        }

        function runConversion(ids, options) {
            const idList = (Array.isArray(ids) ? ids : []).map(function (id) {
                return parseInt(id, 10) || 0;
            }).filter(function (id) {
                return id > 0;
            });
            if (!idList.length) {
                return $.Deferred().resolve(null).promise();
            }
            if (!cfg.encodingSupported) {
                setStatus(i18n.webpUnavailable || 'WebP encoding is not available on this server. The queue can still be reviewed, but optimization is disabled.', 'error');
                return $.Deferred().reject({ message: 'webp-unavailable' }).promise();
            }

            const opts = (options && typeof options === 'object') ? options : {};
            const chunks = chunkIds(idList, batchSize);
            const totalChunks = chunks.length;
            const deferred = $.Deferred();
            let chunkIndex = 0;
            let convertedCount = 0;
            let failedCount = 0;
            let warningCount = 0;
            let bytesSavedTotal = 0;
            const postReloadFeedbacks = {};

            setBusy(true);
            setStatus(i18n.working || 'Optimizing...', 'loading');

            function finish() {
                const totalBytesLabel = formatBytes(bytesSavedTotal);
                const summaryMessage = formatTemplate(
                    i18n.resultSummary || 'Optimized %1$d image(s); %2$d failed; saved %3$s total.',
                    [convertedCount, failedCount, totalBytesLabel]
                );
                const warningSuffix = warningCount > 0
                    ? (' ' + formatTemplate(i18n.resultSummaryWarnings || '%d image(s) are still flagged and remain in the queue.', [warningCount]))
                    : '';
                const finalStatusMessage = summaryMessage + warningSuffix;
                const finalStatusType = (failedCount > 0 || warningCount > 0) ? 'info' : 'success';

                setProgress(0, 0, '');
                setBusy(false);

                if (opts.reloadAfter !== false) {
                    loadQueue({ page: state.page, includeFocus: false, quietStatus: true }).always(function () {
                        Object.keys(postReloadFeedbacks).forEach(function (idKey) {
                            const payload = postReloadFeedbacks[idKey] || {};
                            setCardFeedback(parseInt(idKey, 10) || 0, String(payload.message || ''), String(payload.type || 'info'));
                        });
                        setStatus(finalStatusMessage, finalStatusType);
                        deferred.resolve({
                            convertedCount: convertedCount,
                            failedCount: failedCount,
                            warningCount: warningCount,
                            bytesSavedTotal: bytesSavedTotal
                        });
                    });
                } else {
                    setStatus(finalStatusMessage, finalStatusType);
                    deferred.resolve({
                        convertedCount: convertedCount,
                        failedCount: failedCount,
                        warningCount: warningCount,
                        bytesSavedTotal: bytesSavedTotal
                    });
                }
            }

            function processNextChunk() {
                if (chunkIndex >= totalChunks) {
                    finish();
                    return;
                }

                const currentChunk = chunks[chunkIndex].slice();
                updateCardButtonsWorking(currentChunk, true);
                const progressText = formatTemplate(i18n.progressLabel || 'Optimizing batch %1$d of %2$d...', [chunkIndex + 1, totalChunks]);
                setProgress(chunkIndex, totalChunks, progressText);

                request('convert', {
                    word_image_ids: currentChunk,
                    quality: quality
                }).done(function (res) {
                    if (!res || !res.success || !res.data || !Array.isArray(res.data.results)) {
                        failedCount += currentChunk.length;
                        return;
                    }

                    convertedCount += Math.max(0, parseInt(res.data.converted_count, 10) || 0);
                    failedCount += Math.max(0, parseInt(res.data.failed_count, 10) || 0);
                    warningCount += Math.max(0, parseInt(res.data.warning_count, 10) || 0);
                    bytesSavedTotal += Math.max(0, parseInt(res.data.bytes_saved_total, 10) || 0);

                    res.data.results.forEach(function (result) {
                        const id = parseInt(result && result.word_image_id, 10) || 0;
                        if (!id) {
                            return;
                        }
                        if (result.item && typeof result.item === 'object' && Object.keys(result.item).length) {
                            replaceCard(result.item, {});
                        }
                        const feedbackType = result.success ? (result.warning ? 'info' : 'success') : 'error';
                        const feedbackMessage = String(result.message || '');
                        postReloadFeedbacks[id] = {
                            message: feedbackMessage,
                            type: feedbackType
                        };
                        setCardFeedback(id, feedbackMessage, feedbackType);
                    });
                }).fail(function (xhr) {
                    const message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                        ? String(xhr.responseJSON.data.message)
                        : (i18n.convertFailed || 'Could not optimize this image right now.');
                    failedCount += currentChunk.length;
                    currentChunk.forEach(function (id) {
                        postReloadFeedbacks[id] = {
                            message: message,
                            type: 'error'
                        };
                        setCardFeedback(id, message, 'error');
                    });
                }).always(function () {
                    updateCardButtonsWorking(currentChunk, false);
                    chunkIndex += 1;
                    const processedChunks = Math.min(chunkIndex, totalChunks);
                    const doneText = (processedChunks >= totalChunks)
                        ? (i18n.progressDone || 'Batch optimization finished.')
                        : formatTemplate(i18n.progressLabel || 'Optimizing batch %1$d of %2$d...', [processedChunks + 1, totalChunks]);
                    setProgress(processedChunks, totalChunks, doneText);
                    processNextChunk();
                });
            }

            processNextChunk();
            return deferred.promise();
        }

        $applyFilters.on('click', function () {
            state.page = 1;
            loadQueue({ page: 1, includeFocus: true });
        });

        $refresh.on('click', function () {
            loadQueue({ page: state.page, includeFocus: true });
        });

        $searchFilter.on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                state.page = 1;
                loadQueue({ page: 1, includeFocus: true });
            }
        });

        $pagination.on('click', '[data-ll-webp-page]', function () {
            if (state.isBusy) {
                return;
            }
            const dir = String($(this).attr('data-ll-webp-page') || '');
            if (dir === 'prev' && state.page > 1) {
                loadQueue({ page: state.page - 1, includeFocus: false });
            } else if (dir === 'next' && state.page < state.totalPages) {
                loadQueue({ page: state.page + 1, includeFocus: false });
            }
        });

        $cards.on('click', '[data-ll-webp-convert-card]', function () {
            if (state.isBusy) {
                return;
            }
            const wordImageId = parseInt($(this).attr('data-word-image-id') || $(this).data('wordImageId'), 10) || 0;
            if (!wordImageId) {
                return;
            }
            runConversion([wordImageId], { reloadAfter: true });
        });

        $convertAll.on('click', function () {
            if (state.isBusy || !cfg.encodingSupported) {
                return;
            }

            const queuedCount = Math.max(0, parseInt(state.summary && state.summary.queued_count, 10) || 0);
            if (!queuedCount) {
                setStatus(i18n.emptyQueue || 'No word images currently need WebP optimization.', 'info');
                return;
            }

            if (!window.confirm(i18n.convertAllConfirm || 'Optimize all currently flagged word images using batched WebP optimization?')) {
                return;
            }

            setStatus(i18n.loadingIds || 'Building optimization queue...', 'loading');
            setBusy(true);
            fetchAllFlaggedIds().done(function (ids) {
                setBusy(false);
                if (!Array.isArray(ids) || !ids.length) {
                    setStatus(i18n.emptyQueue || 'No word images currently need WebP optimization.', 'info');
                    return;
                }
                runConversion(ids, { reloadAfter: true });
            }).fail(function (err) {
                setBusy(false);
                const message = (err && err.message) ? String(err.message) : (i18n.queueFailed || 'Could not load the WebP optimization queue.');
                setStatus(message, 'error');
            });
        });

        renderSummary();
        if (!cfg.encodingSupported) {
            setStatus(i18n.webpUnavailable || 'WebP encoding is not available on this server. The queue can still be reviewed, but optimization is disabled.', 'info');
        }
        loadQueue({ page: 1, includeFocus: preselectedWordImageId > 0 });
    }

    initWordImagesListInline();
    initToolPage();
})(jQuery);
