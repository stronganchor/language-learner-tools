// assets/js/legacy-audio-migrator.js
(function ($) {
    'use strict';

    // ==== Selection utilities (added) ====
    function getBoxes() {
        // Works with either data-word-id rows or plain checkboxes.
        return Array.prototype.slice.call($('.ll-row-check').get());
    }

    function wireShiftClickSelection() {
        var boxes = getBoxes();
        var lastIndex = null;

        function idxOf(el) { return boxes.indexOf(el); }

        function handleClick(e) {
            var idx = idxOf(this);
            if (idx === -1) return;

            if (e.shiftKey && lastIndex !== null) {
                var start = Math.min(lastIndex, idx);
                var end = Math.max(lastIndex, idx);
                var val = this.checked;
                for (var i = start; i <= end; i++) {
                    boxes[i].checked = val;
                }
                // Stop accidental text selection if user drag-clicks
                if (window.getSelection) {
                    var s = window.getSelection();
                    if (s && s.removeAllRanges) s.removeAllRanges();
                }
                e.preventDefault();
            }
            lastIndex = idx;
        }

        boxes.forEach(function (cb) {
            cb.removeEventListener('click', handleClick, false);
            cb.addEventListener('click', handleClick, false);

            // Keyboard: shift+space for range
            cb.addEventListener('keydown', function (e) {
                if (e.key === ' ' && e.shiftKey) {
                    e.preventDefault();
                    cb.click();
                }
            });
        });

        // Row click toggles (nice big targets)
        $('tbody tr').off('click.llRowToggle').on('click.llRowToggle', function (e) {
            if ($(e.target).is('a, a *') || $(e.target).is('.ll-row-check')) return;
            var cb = $(this).find('.ll-row-check')[0];
            if (!cb) return;
            cb.click(); // preserves shift state via the click handler above
        });

        // Recompute list if DOM changes (e.g., after migration hides rows)
        $(document).off('ll-refresh-boxes').on('ll-refresh-boxes', function () {
            boxes = getBoxes();
            lastIndex = null;
        });
    }

    // ==== Existing helpers (unchanged) ====
    function collectSelectedIds() {
        var ids = [];
        $('tr[data-word-id]').each(function () {
            var $row = $(this);
            var checked = $row.find('.ll-row-check').prop('checked');
            if (checked) {
                ids.push(parseInt($row.attr('data-word-id'), 10));
            }
        });
        // If rows don’t have data-word-id, fall back to checkbox values
        if (!ids.length) {
            $('.ll-row-check:checked').each(function () {
                var v = parseInt($(this).val(), 10);
                if (!isNaN(v)) ids.push(v);
            });
        }
        return ids;
    }

    function logMessage(html) {
        $('#ll-migration-log').prepend(
            $('<div/>', { class: 'notice notice-info is-dismissible' }).append(
                $('<p/>').html(html)
            )
        );
    }

    // Select all / Deselect all buttons (keep working)
    $('#ll-select-all').on('click', function (e) {
        e.preventDefault();
        $('.ll-row-check').prop('checked', true);
        $(document).trigger('ll-refresh-boxes');
    });
    $('#ll-deselect-all').on('click', function (e) {
        e.preventDefault();
        $('.ll-row-check').prop('checked', false);
        $(document).trigger('ll-refresh-boxes');
    });

    // Wire up shift-click selection on ready
    $(function () {
        wireShiftClickSelection();
    });

    // ==== Convert handler (unchanged, with tiny refresh hook) ====
    $('#ll-convert-selected').on('click', function (e) {
        e.preventDefault();
        var ids = collectSelectedIds();
        if (!ids.length) {
            alert('No rows selected.');
            return;
        }

        var recordingType = $('#ll-recording-type').val() || '';
        if (!recordingType) {
            alert('Please choose a recording type.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Converting…');

        $.ajax({
            url: LLMigrator.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'll_convert_legacy_audio_batch',
                nonce: LLMigrator.nonce,
                ids: ids,
                recording_type: recordingType
            }
        }).done(function (resp) {
            if (!resp || !resp.success) {
                logMessage('<strong>Error:</strong> ' + (resp && resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                return;
            }
            var data = resp.data || {};
            var ok = data.ok || [];
            var skipped = data.skipped || [];
            var failed = data.failed || [];

            if (ok.length) {
                ok.forEach(function (row) {
                    var tr = $('tr[data-word-id="' + row.id + '"]');
                    if (tr.length) {
                        tr.css('opacity', 0.5);
                        tr.find('.ll-row-check').prop('checked', false);
                    } else {
                        // If table lacks data-word-id, just uncheck matching checkbox
                        $('.ll-row-check[value="' + row.id + '"]').prop('checked', false).closest('tr').css('opacity', 0.5);
                    }
                });
            }

            var summary = [
                '<strong>Done.</strong>',
                'Created: ' + ok.length,
                'Skipped: ' + skipped.length,
                'Failed: ' + failed.length
            ].join(' • ');
            logMessage(summary);

            if (skipped.length) {
                var s = skipped.map(function (r) { return r.id + ' (' + r.reason + ')'; }).join(', ');
                logMessage('<em>Skipped:</em> ' + s);
            }
            if (failed.length) {
                var f = failed.map(function (r) { return r.id + ' (' + r.reason + (r.error ? ': ' + r.error : '') + ')'; }).join(', ');
                logMessage('<em>Failed:</em> ' + f);
            }

            $(document).trigger('ll-refresh-boxes');
        }).fail(function (jq, textStatus) {
            logMessage('<strong>AJAX failed:</strong> ' + textStatus);
        }).always(function () {
            $btn.prop('disabled', false).text('Convert selected');
        });
    });

})(jQuery);
