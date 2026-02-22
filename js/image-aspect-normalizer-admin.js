(function ($) {
    'use strict';

    const cfg = (window.llAspectNormalizerData && typeof window.llAspectNormalizerData === 'object')
        ? window.llAspectNormalizerData
        : {};
    const i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};
    const actions = (cfg.actions && typeof cfg.actions === 'object') ? cfg.actions : {};
    const ajaxUrl = String(cfg.ajaxUrl || window.ajaxurl || '');
    const nonce = String(cfg.nonce || '');
    const preselectedCategoryId = parseInt(cfg.preselectedCategoryId, 10) || 0;

    const $root = $('[data-ll-aspect-normalizer-root]');
    if (!$root.length || !ajaxUrl || !nonce) {
        return;
    }

    const $worklist = $root.find('[data-ll-aspect-worklist]');
    const $status = $root.find('[data-ll-aspect-status]');
    const $errors = $root.find('[data-ll-aspect-errors]');
    const $title = $root.find('[data-ll-aspect-category-title]');
    const $summary = $root.find('[data-ll-aspect-category-summary]');
    const $controls = $root.find('[data-ll-aspect-controls]');
    const $ratioSelect = $root.find('[data-ll-aspect-ratio-select]');
    const $ratioCustomWrap = $root.find('[data-ll-aspect-ratio-custom-wrap]');
    const $ratioCustom = $root.find('[data-ll-aspect-ratio-custom]');
    const $previewButton = $root.find('[data-ll-aspect-preview]');
    const $applyButton = $root.find('[data-ll-aspect-apply]');
    const $offenders = $root.find('[data-ll-aspect-offenders]');

    let worklist = [];
    let activeCategoryId = 0;
    let activePayload = null;
    let editorsByAttachment = {};
    let pendingCategoryRequestId = 0;
    let dragState = null;

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

    function gcd(left, right) {
        let a = Math.abs(parseInt(left, 10) || 0);
        let b = Math.abs(parseInt(right, 10) || 0);
        if (!a || !b) {
            return a || b || 1;
        }
        while (b !== 0) {
            const tmp = b;
            b = a % b;
            a = tmp;
        }
        return a || 1;
    }

    function normalizeRatioKey(value) {
        const text = String(value || '').trim();
        const match = text.match(/^(\d+)\s*:\s*(\d+)$/);
        if (!match) {
            return '';
        }
        const width = parseInt(match[1], 10) || 0;
        const height = parseInt(match[2], 10) || 0;
        if (!width || !height) {
            return '';
        }
        const divisor = gcd(width, height);
        const reducedWidth = Math.max(1, Math.round(width / divisor));
        const reducedHeight = Math.max(1, Math.round(height / divisor));
        return reducedWidth + ':' + reducedHeight;
    }

    function normalizeIntList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : []).map(function (value) {
            return parseInt(value, 10) || 0;
        }).filter(function (value) {
            if (value <= 0 || seen[value]) {
                return false;
            }
            seen[value] = true;
            return true;
        });
    }

    function setStatus(message, type) {
        const text = String(message || '').trim();
        if (!text) {
            $status.removeClass('is-success is-error is-loading is-info').empty().hide();
            return;
        }
        const kind = String(type || 'info');
        $status
            .removeClass('is-success is-error is-loading is-info')
            .addClass('is-' + kind)
            .text(text)
            .show();
    }

    function setWarnings(list) {
        const warnings = Array.isArray(list) ? list.filter(Boolean) : [];
        if (!warnings.length) {
            $errors.hide().empty();
            return;
        }

        const html = warnings.map(function (warning) {
            return '<li>' + escapeHtml(String(warning || '')) + '</li>';
        }).join('');
        $errors.html('<ul>' + html + '</ul>').show();
    }

    function request(actionKey, payload) {
        const action = String(actions[actionKey] || '');
        if (!action) {
            return $.Deferred().reject().promise();
        }
        const body = Object.assign({
            action: action,
            nonce: nonce
        }, payload || {});
        return $.post(ajaxUrl, body);
    }

    function getRequestedRatioFromControls() {
        const selected = String($ratioSelect.val() || '');
        if (selected === '__custom__') {
            return normalizeRatioKey($ratioCustom.val() || '');
        }
        return normalizeRatioKey(selected);
    }

    function setRatioControlsFromKey(ratioKey) {
        const normalized = normalizeRatioKey(ratioKey || '');
        if (!normalized) {
            return false;
        }

        let hasOption = false;
        $ratioSelect.find('option').each(function () {
            if (String($(this).val() || '') === normalized) {
                hasOption = true;
                return false;
            }
            return undefined;
        });

        if (hasOption) {
            $ratioSelect.val(normalized);
            $ratioCustomWrap.prop('hidden', true);
            $ratioCustom.val('');
            return true;
        }

        $ratioSelect.val('__custom__');
        $ratioCustomWrap.prop('hidden', false);
        $ratioCustom.val(normalized);
        return true;
    }

    function renderWorklist() {
        if (!worklist.length) {
            $worklist.text(i18n.emptyWorklist || 'No categories currently need image aspect normalization.');
            return;
        }

        const rows = worklist.map(function (row) {
            const id = parseInt(row && row.id, 10) || 0;
            if (!id) {
                return '';
            }
            const label = String((row && row.label) || (row && row.raw_name) || '');
            const offendingCount = Math.max(0, parseInt(row && row.offending_count, 10) || 0);
            const totalAttachments = Math.max(0, parseInt(row && row.total_attachments, 10) || 0);
            const ratioCount = Math.max(0, parseInt(row && row.ratio_count, 10) || 0);
            const activeClass = (id === activeCategoryId) ? ' is-active' : '';
            const stats = formatTemplate(
                i18n.worklistOffending || '%1$d of %2$d images need fixes',
                [offendingCount, totalAttachments]
            );
            const ratioText = formatTemplate(
                i18n.worklistRatios || '%d aspect ratios detected',
                [ratioCount]
            );
            return ''
                + '<button type="button" class="ll-aspect-worklist-item' + activeClass + '" data-ll-aspect-worklist-item="' + id + '">'
                + '<span class="ll-aspect-worklist-item__label">' + escapeHtml(label) + '</span>'
                + '<span class="ll-aspect-worklist-item__stats">' + escapeHtml(stats) + '</span>'
                + '<span class="ll-aspect-worklist-item__ratios">' + escapeHtml(ratioText) + '</span>'
                + '</button>';
        }).join('');

        $worklist.html(rows);
    }

    function pickNextCategoryId(preferredId) {
        const candidate = parseInt(preferredId, 10) || 0;
        const availableIds = normalizeIntList(worklist.map(function (row) {
            return row && row.id;
        }));
        if (!availableIds.length) {
            return 0;
        }
        if (candidate && availableIds.indexOf(candidate) !== -1) {
            return candidate;
        }
        if (activeCategoryId && availableIds.indexOf(activeCategoryId) !== -1) {
            return activeCategoryId;
        }
        return availableIds[0];
    }

    function resetCategoryView() {
        activePayload = null;
        editorsByAttachment = {};
        dragState = null;
        $controls.prop('hidden', true);
        $offenders.empty();
        $title.text(i18n.chooseCategory || 'Select a category from the left to preview crops and white-padding updates.');
        $summary.text(i18n.chooseCategory || 'Select a category from the left to preview crops and white-padding updates.');
        setWarnings([]);
    }

    function loadWorklist(preferredCategoryId) {
        setStatus(i18n.loading || 'Loading categories...', 'loading');
        return request('worklist', {}).done(function (res) {
            if (!res || !res.success || !res.data || !Array.isArray(res.data.categories)) {
                setStatus(i18n.applyError || 'Unable to load categories.', 'error');
                return;
            }
            worklist = res.data.categories.slice();
            const nextCategoryId = pickNextCategoryId(preferredCategoryId);
            if (!nextCategoryId) {
                activeCategoryId = 0;
                renderWorklist();
                resetCategoryView();
                setStatus('', '');
                return;
            }
            activeCategoryId = nextCategoryId;
            renderWorklist();
            loadCategory(activeCategoryId, '');
        }).fail(function () {
            setStatus(i18n.applyError || 'Unable to load categories.', 'error');
        });
    }

    function renderRatioControls(payload) {
        const ratios = Array.isArray(payload && payload.ratios) ? payload.ratios : [];
        const canonicalKey = normalizeRatioKey(payload && payload.canonical && payload.canonical.key);
        const ratioOptions = {};
        const optionRows = [];

        ratios.forEach(function (row) {
            const key = normalizeRatioKey(row && row.key);
            if (!key || ratioOptions[key]) {
                return;
            }
            ratioOptions[key] = true;
            const label = String((row && row.label) || key);
            const attachmentCount = Math.max(0, parseInt(row && row.attachment_count, 10) || 0);
            optionRows.push({
                key: key,
                text: label + ' (' + attachmentCount + ')'
            });
        });

        if (canonicalKey && !ratioOptions[canonicalKey]) {
            optionRows.unshift({
                key: canonicalKey,
                text: canonicalKey
            });
        }

        const optionsHtml = optionRows.map(function (row) {
            return '<option value="' + escapeHtml(row.key) + '">' + escapeHtml(row.text) + '</option>';
        }).join('');
        $ratioSelect.html(optionsHtml + '<option value="__custom__">' + escapeHtml(i18n.ratioCustom || 'Custom ratio') + '</option>');

        if (canonicalKey) {
            setRatioControlsFromKey(canonicalKey);
        } else {
            $ratioSelect.val('__custom__');
            $ratioCustomWrap.prop('hidden', false);
            $ratioCustom.val('');
        }
    }

    function renderCategoryHeader(payload) {
        const category = (payload && payload.category && typeof payload.category === 'object') ? payload.category : {};
        const canonical = (payload && payload.canonical && typeof payload.canonical === 'object') ? payload.canonical : {};
        const label = String(category.label || category.raw_name || '');
        const offendingCount = Math.max(0, parseInt(payload && payload.offending_count, 10) || 0);
        const totalAttachments = Math.max(0, parseInt(payload && payload.total_attachments, 10) || 0);
        const canonicalLabel = String(canonical.label || canonical.key || '');

        $title.text(label || (i18n.chooseCategory || 'Select a category'));
        $summary.text(formatTemplate(
            i18n.categorySummary || '%1$d images need fixes out of %2$d tracked images.',
            [offendingCount, totalAttachments]
        ));

        if (canonicalLabel) {
            setStatus(formatTemplate(i18n.ratioDetectedFrom || 'Current ratio: %s', [canonicalLabel]), 'info');
        } else {
            setStatus('', '');
        }
    }

    function readoutText(crop) {
        return formatTemplate(i18n.cropReadout || 'Crop: x:%1$d y:%2$d w:%3$d h:%4$d', [
            parseInt(crop && crop.x, 10) || 0,
            parseInt(crop && crop.y, 10) || 0,
            parseInt(crop && crop.width, 10) || 0,
            parseInt(crop && crop.height, 10) || 0
        ]);
    }

    function clamp(value, min, max) {
        const num = Number(value) || 0;
        return Math.min(Math.max(num, min), max);
    }

    function sanitizeCrop(crop, editor) {
        const ratio = Number(editor.ratio) || 0;
        if (ratio <= 0) {
            return {
                x: 0,
                y: 0,
                width: editor.naturalWidth,
                height: editor.naturalHeight
            };
        }

        const imageWidth = editor.naturalWidth;
        const imageHeight = editor.naturalHeight;
        const minWidth = Math.min(imageWidth, Math.max(20, Math.round(imageWidth * 0.06)));
        const minHeight = Math.min(imageHeight, Math.max(20, Math.round(imageHeight * 0.06)));

        let width = Math.max(1, Number(crop.width) || editor.crop.width || imageWidth);
        let height = Math.max(1, Number(crop.height) || editor.crop.height || imageHeight);
        let x = Number(crop.x);
        let y = Number(crop.y);
        if (!Number.isFinite(x)) { x = 0; }
        if (!Number.isFinite(y)) { y = 0; }

        height = width / ratio;
        if (height > imageHeight) {
            height = imageHeight;
            width = height * ratio;
        }
        if (width > imageWidth) {
            width = imageWidth;
            height = width / ratio;
        }

        if (width < minWidth) {
            width = minWidth;
            height = width / ratio;
        }
        if (height < minHeight) {
            height = minHeight;
            width = height * ratio;
        }

        if (height > imageHeight) {
            height = imageHeight;
            width = height * ratio;
        }
        if (width > imageWidth) {
            width = imageWidth;
            height = width / ratio;
        }

        x = clamp(x, 0, Math.max(0, imageWidth - width));
        y = clamp(y, 0, Math.max(0, imageHeight - height));

        return {
            x: Math.round(x),
            y: Math.round(y),
            width: Math.max(1, Math.round(width)),
            height: Math.max(1, Math.round(height))
        };
    }

    function updateEditorVisual(editor) {
        if (!editor || !editor.$cropBox || !editor.naturalWidth || !editor.naturalHeight) {
            return;
        }
        const crop = sanitizeCrop(editor.crop, editor);
        editor.crop = crop;

        const leftPercent = (crop.x / editor.naturalWidth) * 100;
        const topPercent = (crop.y / editor.naturalHeight) * 100;
        const widthPercent = (crop.width / editor.naturalWidth) * 100;
        const heightPercent = (crop.height / editor.naturalHeight) * 100;

        editor.$cropBox.css({
            left: leftPercent + '%',
            top: topPercent + '%',
            width: widthPercent + '%',
            height: heightPercent + '%'
        });
        editor.$readout.text(readoutText(crop));
    }

    function computeDragCrop(editor, startCrop, handle, dx, dy) {
        const ratio = Number(editor.ratio) || 0;
        if (ratio <= 0) {
            return startCrop;
        }

        const start = {
            x: Number(startCrop.x) || 0,
            y: Number(startCrop.y) || 0,
            width: Number(startCrop.width) || editor.naturalWidth,
            height: Number(startCrop.height) || editor.naturalHeight
        };

        const centerX = start.x + (start.width / 2);
        const centerY = start.y + (start.height / 2);
        let next = {
            x: start.x,
            y: start.y,
            width: start.width,
            height: start.height
        };

        if (handle === 'move') {
            next.x = start.x + dx;
            next.y = start.y + dy;
            return sanitizeCrop(next, editor);
        }

        if (handle === 'e' || handle === 'w') {
            const widthDelta = (handle === 'e') ? dx : -dx;
            next.width = start.width + widthDelta;
            next.height = next.width / ratio;
            next.x = (handle === 'e') ? start.x : (start.x + start.width - next.width);
            next.y = centerY - (next.height / 2);
            return sanitizeCrop(next, editor);
        }

        if (handle === 's' || handle === 'n') {
            const heightDelta = (handle === 's') ? dy : -dy;
            next.height = start.height + heightDelta;
            next.width = next.height * ratio;
            next.y = (handle === 's') ? start.y : (start.y + start.height - next.height);
            next.x = centerX - (next.width / 2);
            return sanitizeCrop(next, editor);
        }

        const hasEast = handle.indexOf('e') !== -1;
        const hasWest = handle.indexOf('w') !== -1;
        const hasSouth = handle.indexOf('s') !== -1;
        const hasNorth = handle.indexOf('n') !== -1;

        const trialWidth = start.width + (hasEast ? dx : (hasWest ? -dx : 0));
        const trialHeight = start.height + (hasSouth ? dy : (hasNorth ? -dy : 0));
        const widthChange = Math.abs(trialWidth - start.width) / Math.max(1, start.width);
        const heightChange = Math.abs(trialHeight - start.height) / Math.max(1, start.height);

        if (widthChange >= heightChange) {
            next.width = trialWidth;
            next.height = next.width / ratio;
        } else {
            next.height = trialHeight;
            next.width = next.height * ratio;
        }

        if (hasWest) {
            next.x = start.x + start.width - next.width;
        } else {
            next.x = start.x;
        }
        if (hasNorth) {
            next.y = start.y + start.height - next.height;
        } else {
            next.y = start.y;
        }

        return sanitizeCrop(next, editor);
    }

    function startDrag(event, editor, handle) {
        const stageWidth = editor.$stage.width();
        const stageHeight = editor.$stage.height();
        if (!stageWidth || !stageHeight) {
            return;
        }

        dragState = {
            attachmentId: editor.attachmentId,
            handle: String(handle || 'move'),
            startX: event.clientX,
            startY: event.clientY,
            startCrop: Object.assign({}, editor.crop),
            scaleX: editor.naturalWidth / stageWidth,
            scaleY: editor.naturalHeight / stageHeight
        };
        editor.$card.addClass('is-dragging');
    }

    function stopDrag() {
        if (!dragState) {
            return;
        }
        const editor = editorsByAttachment[dragState.attachmentId];
        if (editor && editor.$card) {
            editor.$card.removeClass('is-dragging');
        }
        dragState = null;
    }

    function bindEditorHandlers(editor) {
        editor.$cropBox.on('pointerdown', function (event) {
            const nativeEvent = event.originalEvent;
            if (!nativeEvent) {
                return;
            }
            event.preventDefault();
            const target = $(nativeEvent.target);
            const handle = String(target.attr('data-ll-aspect-handle') || 'move');
            startDrag(nativeEvent, editor, handle);
        });
    }

    function buildEditorCard(row) {
        const attachmentId = parseInt(row && row.attachment_id, 10) || 0;
        if (!attachmentId) {
            return '';
        }
        const label = String(row && row.title ? row.title : ('#' + attachmentId));
        const ratioLabel = String((row && row.ratio_label) || (row && row.ratio_key) || '');
        const ratioKey = normalizeRatioKey((row && row.ratio_key) || ratioLabel);
        const useRatioLabel = String(i18n.useRatioButton || 'Use as canonical');
        const useRatioAria = formatTemplate(
            i18n.useRatioButtonAria || 'Use ratio %s as the canonical ratio and refresh preview.',
            [ratioKey || ratioLabel]
        );
        const padButtonLabel = String(i18n.applyPadButton || 'Apply White Padding');
        const padButtonAria = formatTemplate(
            i18n.applyPadButtonAria || 'Apply white padding to image %s using the current canonical ratio.',
            [label]
        );
        const useRatioDisabled = ratioKey ? '' : ' disabled';
        const wordsLabel = formatTemplate((i18n.wordsLabel || 'Words') + ': %d', [Math.max(0, parseInt(row && row.word_count, 10) || 0)]);
        const wordImagesLabel = formatTemplate((i18n.wordImagesLabel || 'Word Images') + ': %d', [Math.max(0, parseInt(row && row.word_image_count, 10) || 0)]);
        const detectedRatioLabel = formatTemplate(i18n.ratioDetectedFrom || 'Current ratio: %s', [ratioLabel || '']);
        return ''
            + '<article class="ll-aspect-card" data-ll-aspect-card data-attachment-id="' + attachmentId + '">'
            + '<header class="ll-aspect-card__header">'
            + '<h3 class="ll-aspect-card__title">' + escapeHtml(label) + '</h3>'
            + '<p class="ll-aspect-card__meta">' + escapeHtml(detectedRatioLabel) + '</p>'
            + '<div class="ll-aspect-card__counts">'
            + '<span>' + escapeHtml(wordsLabel) + '</span>'
            + '<span>' + escapeHtml(wordImagesLabel) + '</span>'
            + '</div>'
            + '<div class="ll-aspect-card__actions">'
            + '<button type="button" class="button button-secondary button-small ll-aspect-card__use-ratio" data-ll-aspect-use-ratio="' + escapeHtml(ratioKey) + '" aria-label="' + escapeHtml(useRatioAria) + '"' + useRatioDisabled + '>'
            + escapeHtml(useRatioLabel)
            + '</button>'
            + '<button type="button" class="button button-secondary button-small ll-aspect-card__apply-pad" data-ll-aspect-pad-single="' + attachmentId + '" aria-label="' + escapeHtml(padButtonAria) + '">'
            + escapeHtml(padButtonLabel)
            + '</button>'
            + '</div>'
            + '</header>'
            + '<div class="ll-aspect-stage" data-ll-aspect-stage>'
            + '<img src="' + escapeHtml(String(row && row.url || '')) + '" alt="' + escapeHtml(label) + '" data-ll-aspect-image>'
            + '<div class="ll-aspect-crop-box" data-ll-aspect-crop-box>'
            + '<span class="ll-aspect-handle ll-aspect-handle--nw" data-ll-aspect-handle="nw"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--n" data-ll-aspect-handle="n"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--ne" data-ll-aspect-handle="ne"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--e" data-ll-aspect-handle="e"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--se" data-ll-aspect-handle="se"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--s" data-ll-aspect-handle="s"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--sw" data-ll-aspect-handle="sw"></span>'
            + '<span class="ll-aspect-handle ll-aspect-handle--w" data-ll-aspect-handle="w"></span>'
            + '</div>'
            + '</div>'
            + '<p class="ll-aspect-card__readout" data-ll-aspect-readout></p>'
            + '</article>';
    }

    function initializeEditors(payload) {
        editorsByAttachment = {};
        const ratioValue = Number(payload && payload.canonical && payload.canonical.value) || 0;
        const rows = Array.isArray(payload && payload.offending_attachments) ? payload.offending_attachments : [];
        if (!rows.length) {
            $offenders.html('<div class="ll-aspect-empty">' + escapeHtml(i18n.noOffenders || 'All category images already match this ratio.') + '</div>');
            return;
        }

        const cardsHtml = rows.map(function (row) {
            return buildEditorCard(row);
        }).join('');
        $offenders.html(cardsHtml);

        rows.forEach(function (row) {
            const attachmentId = parseInt(row && row.attachment_id, 10) || 0;
            if (!attachmentId) {
                return;
            }
            const $card = $offenders.find('[data-attachment-id="' + attachmentId + '"]');
            if (!$card.length) {
                return;
            }
            const $stage = $card.find('[data-ll-aspect-stage]');
            const $image = $card.find('[data-ll-aspect-image]');
            const $cropBox = $card.find('[data-ll-aspect-crop-box]');
            const $readout = $card.find('[data-ll-aspect-readout]');

            const naturalWidth = Math.max(1, parseInt(row && row.width, 10) || 0);
            const naturalHeight = Math.max(1, parseInt(row && row.height, 10) || 0);
            const suggestedCrop = (row && row.suggested_crop && typeof row.suggested_crop === 'object')
                ? row.suggested_crop
                : {};
            const editor = {
                attachmentId: attachmentId,
                ratio: ratioValue > 0 ? ratioValue : (naturalWidth / Math.max(1, naturalHeight)),
                naturalWidth: naturalWidth,
                naturalHeight: naturalHeight,
                crop: sanitizeCrop({
                    x: parseInt(suggestedCrop.x, 10) || 0,
                    y: parseInt(suggestedCrop.y, 10) || 0,
                    width: parseInt(suggestedCrop.width, 10) || naturalWidth,
                    height: parseInt(suggestedCrop.height, 10) || naturalHeight
                }, {
                    ratio: ratioValue > 0 ? ratioValue : (naturalWidth / Math.max(1, naturalHeight)),
                    naturalWidth: naturalWidth,
                    naturalHeight: naturalHeight,
                    crop: {
                        x: 0,
                        y: 0,
                        width: naturalWidth,
                        height: naturalHeight
                    }
                }),
                $card: $card,
                $stage: $stage,
                $image: $image,
                $cropBox: $cropBox,
                $readout: $readout
            };

            editorsByAttachment[attachmentId] = editor;
            bindEditorHandlers(editor);

            if ($image[0] && !$image[0].complete) {
                $image.on('load', function () {
                    updateEditorVisual(editor);
                });
            }
            updateEditorVisual(editor);
        });
    }

    function renderCategoryPayload(payload) {
        activePayload = payload;
        setWarnings([]);
        renderCategoryHeader(payload);
        renderRatioControls(payload);
        $controls.prop('hidden', false);
        initializeEditors(payload);
    }

    function loadCategory(categoryId, canonicalRatioKey) {
        const targetCategoryId = parseInt(categoryId, 10) || 0;
        if (!targetCategoryId) {
            return;
        }

        activeCategoryId = targetCategoryId;
        renderWorklist();
        setWarnings([]);
        setStatus(i18n.loadingCategory || 'Loading category details...', 'loading');

        const requestId = ++pendingCategoryRequestId;
        const body = {
            category_id: targetCategoryId
        };
        const ratioKey = normalizeRatioKey(canonicalRatioKey || '');
        if (ratioKey) {
            body.canonical_ratio_key = ratioKey;
        }

        request('category', body).done(function (res) {
            if (requestId !== pendingCategoryRequestId) {
                return;
            }
            if (!res || !res.success || !res.data) {
                setStatus(i18n.applyError || 'Unable to load category details.', 'error');
                return;
            }
            renderCategoryPayload(res.data);
        }).fail(function () {
            if (requestId !== pendingCategoryRequestId) {
                return;
            }
            setStatus(i18n.applyError || 'Unable to load category details.', 'error');
        });
    }

    function collectCropPayload() {
        const cropMap = {};
        Object.keys(editorsByAttachment).forEach(function (key) {
            const attachmentId = parseInt(key, 10) || 0;
            const editor = editorsByAttachment[attachmentId];
            if (!attachmentId || !editor) {
                return;
            }
            const crop = sanitizeCrop(editor.crop, editor);
            cropMap[attachmentId] = {
                x: crop.x,
                y: crop.y,
                width: crop.width,
                height: crop.height
            };
        });
        return cropMap;
    }

    function setActionButtonsDisabled(disabled) {
        const state = !!disabled;
        $applyButton.prop('disabled', state);
        $offenders.find('[data-ll-aspect-pad-single]').prop('disabled', state);
    }

    function applyCrops() {
        if (!activeCategoryId || !activePayload) {
            return;
        }

        const ratioKey = getRequestedRatioFromControls();
        if (!ratioKey) {
            setStatus(i18n.invalidRatio || 'Enter a valid ratio like 4:3.', 'error');
            return;
        }

        const confirmMessage = i18n.applyConfirm || 'Apply these crops and update affected posts in this category?';
        if (!window.confirm(confirmMessage)) {
            return;
        }

        setWarnings([]);
        setStatus(i18n.applyWorking || 'Applying crops...', 'loading');
        setActionButtonsDisabled(true);

        request('apply', {
            category_id: activeCategoryId,
            canonical_ratio_key: ratioKey,
            operation: 'crop',
            crops: JSON.stringify(collectCropPayload())
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                setStatus(i18n.applyError || 'Unable to apply image updates right now.', 'error');
                return;
            }

            const processedCount = Math.max(0, parseInt(res.data.processed_count, 10) || 0);
            const updatedPostCount = Math.max(0, parseInt(res.data.updated_post_count, 10) || 0);
            const successText = formatTemplate(
                i18n.applySuccess || 'Applied %1$d crop(s), updated %2$d post thumbnail(s).',
                [processedCount, updatedPostCount]
            );
            setStatus(successText, 'success');

            const warnings = Array.isArray(res.data.warning_messages) ? res.data.warning_messages : [];
            if (warnings.length) {
                setWarnings(warnings);
                setStatus(formatTemplate(
                    i18n.statusWarnings || 'Completed with %d warning(s). Check the error list below.',
                    [warnings.length]
                ), 'info');
            }

            loadWorklist(activeCategoryId);
        }).fail(function () {
            setStatus(i18n.applyError || 'Unable to apply image updates right now.', 'error');
        }).always(function () {
            setActionButtonsDisabled(false);
        });
    }

    function applySinglePadding(attachmentId) {
        const targetAttachmentId = parseInt(attachmentId, 10) || 0;
        if (!activeCategoryId || !activePayload || !targetAttachmentId) {
            return;
        }

        const ratioKey = getRequestedRatioFromControls();
        if (!ratioKey) {
            setStatus(i18n.invalidRatio || 'Enter a valid ratio like 4:3.', 'error');
            return;
        }

        const confirmMessage = i18n.applyPadConfirm || 'Apply white padding to this image and update affected posts that use it?';
        if (!window.confirm(confirmMessage)) {
            return;
        }

        setWarnings([]);
        setStatus(i18n.applyPadWorking || 'Applying white padding to image...', 'loading');
        setActionButtonsDisabled(true);

        request('apply', {
            category_id: activeCategoryId,
            canonical_ratio_key: ratioKey,
            operation: 'pad',
            target_attachment_ids: JSON.stringify([targetAttachmentId])
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                setStatus(i18n.applyError || 'Unable to apply image updates right now.', 'error');
                return;
            }

            const processedCount = Math.max(0, parseInt(res.data.processed_count, 10) || 0);
            const updatedPostCount = Math.max(0, parseInt(res.data.updated_post_count, 10) || 0);
            const successText = formatTemplate(
                i18n.applyPadSuccess || 'Applied white padding to %1$d image(s), updated %2$d post thumbnail(s).',
                [processedCount, updatedPostCount]
            );
            setStatus(successText, 'success');

            const warnings = Array.isArray(res.data.warning_messages) ? res.data.warning_messages : [];
            if (warnings.length) {
                setWarnings(warnings);
                setStatus(formatTemplate(
                    i18n.statusWarnings || 'Completed with %d warning(s). Check the error list below.',
                    [warnings.length]
                ), 'info');
            }

            loadWorklist(activeCategoryId);
        }).fail(function () {
            setStatus(i18n.applyError || 'Unable to apply image updates right now.', 'error');
        }).always(function () {
            setActionButtonsDisabled(false);
        });
    }

    function handleRatioSelectionChange() {
        const value = String($ratioSelect.val() || '');
        if (value === '__custom__') {
            $ratioCustomWrap.prop('hidden', false);
            $ratioCustom.trigger('focus');
            return;
        }
        $ratioCustomWrap.prop('hidden', true);
        const ratioKey = normalizeRatioKey(value);
        if (!ratioKey || !activeCategoryId) {
            return;
        }
        loadCategory(activeCategoryId, ratioKey);
    }

    function bindEvents() {
        $worklist.on('click', '[data-ll-aspect-worklist-item]', function () {
            const categoryId = parseInt($(this).attr('data-ll-aspect-worklist-item'), 10) || 0;
            if (!categoryId || categoryId === activeCategoryId) {
                return;
            }
            loadCategory(categoryId, '');
        });

        $ratioSelect.on('change', handleRatioSelectionChange);

        $previewButton.on('click', function () {
            if (!activeCategoryId) {
                return;
            }
            const ratioKey = getRequestedRatioFromControls();
            if (!ratioKey) {
                setStatus(i18n.invalidRatio || 'Enter a valid ratio like 4:3.', 'error');
                return;
            }
            loadCategory(activeCategoryId, ratioKey);
        });

        $ratioCustom.on('keydown', function (event) {
            if ((event.key || '') !== 'Enter') {
                return;
            }
            event.preventDefault();
            $previewButton.trigger('click');
        });

        $applyButton.on('click', function () {
            applyCrops();
        });

        $offenders.on('click', '[data-ll-aspect-use-ratio]', function () {
            const ratioKey = normalizeRatioKey($(this).attr('data-ll-aspect-use-ratio') || '');
            if (!ratioKey || !activeCategoryId) {
                return;
            }
            setRatioControlsFromKey(ratioKey);
            loadCategory(activeCategoryId, ratioKey);
        });

        $offenders.on('click', '[data-ll-aspect-pad-single]', function () {
            const attachmentId = parseInt($(this).attr('data-ll-aspect-pad-single'), 10) || 0;
            if (!attachmentId) {
                return;
            }
            applySinglePadding(attachmentId);
        });

        $(document).on('pointermove.llAspectCrop', function (event) {
            if (!dragState) {
                return;
            }
            const editor = editorsByAttachment[dragState.attachmentId];
            if (!editor) {
                stopDrag();
                return;
            }
            event.preventDefault();
            const dx = (event.clientX - dragState.startX) * dragState.scaleX;
            const dy = (event.clientY - dragState.startY) * dragState.scaleY;
            editor.crop = computeDragCrop(editor, dragState.startCrop, dragState.handle, dx, dy);
            updateEditorVisual(editor);
        });

        $(document).on('pointerup.llAspectCrop pointercancel.llAspectCrop', function () {
            stopDrag();
        });

        $(window).on('resize.llAspectCrop', function () {
            Object.keys(editorsByAttachment).forEach(function (key) {
                const editor = editorsByAttachment[key];
                if (editor) {
                    updateEditorVisual(editor);
                }
            });
        });
    }

    bindEvents();
    loadWorklist(preselectedCategoryId);
})(jQuery);
