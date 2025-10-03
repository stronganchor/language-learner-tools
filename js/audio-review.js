(function ($) {
    'use strict';

    let excludedIds = [];
    let currentPostId = null;

    function updatePendingCount() {
        $('#ll-pending-count').text(Math.max(0, parseInt($('#ll-pending-count').text()) - 1));
    }

    function showStatus(message, type) {
        $('#ll-review-status')
            .text(message)
            .removeClass('success error')
            .addClass(type);
    }

    function loadNextWord() {
        showStatus('Loading...', '');

        $.ajax({
            url: llAudioReview.ajaxurl,
            method: 'POST',
            data: {
                action: 'll_get_next_audio_review',
                nonce: llAudioReview.nonce,
                exclude: excludedIds
            },
            success: function (response) {
                if (response.success && response.data.item) {
                    displayWord(response.data.item);
                } else {
                    showComplete();
                }
            },
            error: function () {
                showStatus('Error loading next word', 'error');
            }
        });
    }

    function displayWord(item) {
        currentPostId = item.id;

        $('#ll-review-title').text(item.title);
        $('#ll-review-category').text('Category: ' + (item.categories || 'None'));
        $('#ll-review-wordset').text('Word Set: ' + (item.wordsets || 'None'));
        $('#ll-review-translation-text').text(item.translation || 'No translation');

        if (item.image_url) {
            $('#ll-review-img').attr('src', item.image_url).show();
        } else {
            $('#ll-review-img').hide();
        }

        if (item.audio_url) {
            $('#ll-review-audio').attr('src', item.audio_url);
            $('#ll-review-audio')[0].load();
        }

        $('#ll-review-start').hide();
        $('#ll-review-complete').hide();
        $('#ll-review-stage').show();
        $('#ll-review-status').text('');
    }

    function showComplete() {
        $('#ll-review-stage').hide();
        $('#ll-review-start').hide();
        $('#ll-review-complete').show();
    }

    function approveAudio() {
        if (!currentPostId) return;

        showStatus('Approving...', '');
        $('#ll-approve-btn, #ll-skip-btn').prop('disabled', true);

        $.ajax({
            url: llAudioReview.ajaxurl,
            method: 'POST',
            data: {
                action: 'll_approve_audio',
                nonce: llAudioReview.nonce,
                post_id: currentPostId
            },
            success: function (response) {
                if (response.success) {
                    showStatus('✓ Approved', 'success');
                    updatePendingCount();
                    excludedIds.push(currentPostId);

                    setTimeout(function () {
                        $('#ll-approve-btn, #ll-skip-btn').prop('disabled', false);
                        loadNextWord();
                    }, 800);
                } else {
                    showStatus('Error: ' + (response.data || 'Unknown error'), 'error');
                    $('#ll-approve-btn, #ll-skip-btn').prop('disabled', false);
                }
            },
            error: function () {
                showStatus('Network error', 'error');
                $('#ll-approve-btn, #ll-skip-btn').prop('disabled', false);
            }
        });
    }

    function markForReprocessing() {
        if (!currentPostId) return;

        showStatus('Marking for reprocessing...', '');
        $('#ll-approve-btn, #ll-reprocess-btn, #ll-skip-btn').prop('disabled', true);

        $.ajax({
            url: llAudioReview.ajaxurl,
            method: 'POST',
            data: {
                action: 'll_mark_for_reprocessing',
                nonce: llAudioReview.nonce,
                post_id: currentPostId
            },
            success: function (response) {
                if (response.success) {
                    showStatus('✓ Marked for reprocessing', 'success');
                    updatePendingCount();
                    excludedIds.push(currentPostId);

                    setTimeout(function () {
                        $('#ll-approve-btn, #ll-reprocess-btn, #ll-skip-btn').prop('disabled', false);
                        loadNextWord();
                    }, 800);
                } else {
                    showStatus('Error: ' + (response.data || 'Unknown error'), 'error');
                    $('#ll-approve-btn, #ll-reprocess-btn, #ll-skip-btn').prop('disabled', false);
                }
            },
            error: function () {
                showStatus('Network error', 'error');
                $('#ll-approve-btn, #ll-reprocess-btn, #ll-skip-btn').prop('disabled', false);
            }
        });
    }

    function skipWord() {
        if (!currentPostId) return;
        excludedIds.push(currentPostId);
        loadNextWord();
    }

    // Event handlers
    $('#ll-start-review').on('click', loadNextWord);
    $('#ll-approve-btn').on('click', approveAudio);
    $('#ll-reprocess-btn').on('click', markForReprocessing);
    $('#ll-skip-btn').on('click', skipWord);
    $('#ll-review-refresh').on('click', function () {
        location.reload();
    });

})(jQuery);