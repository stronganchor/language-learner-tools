(function ($) {
    'use strict';

    function collectSelectedIds() {
        var ids = [];
        $('tr[data-word-id]').each(function () {
            var $row = $(this);
            var checked = $row.find('.ll-row-check').prop('checked');
            if (checked) {
                ids.push(parseInt($row.attr('data-word-id'), 10));
            }
        });
        return ids;
    }

    function logMessage(html) {
        $('#ll-migration-log').prepend(
            $('<div/>', { class: 'notice notice-info is-dismissible' }).append(
                $('<p/>').html(html)
            )
        );
    }

    $('#ll-select-all').on('click', function (e) {
        e.preventDefault();
        $('.ll-row-check').prop('checked', true);
    });
    $('#ll-deselect-all').on('click', function (e) {
        e.preventDefault();
        $('.ll-row-check').prop('checked', false);
    });

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
                    tr.css('opacity', 0.5);
                    tr.find('.ll-row-check').prop('checked', false);
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
        }).fail(function (jq, textStatus) {
            logMessage('<strong>AJAX failed:</strong> ' + textStatus);
        }).always(function () {
            $btn.prop('disabled', false).text('Convert selected');
        });
    });

})(jQuery);
