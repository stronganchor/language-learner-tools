(function ($) {
    'use strict';

    var cfg = window.llToolsCategoryLineupManagerData || {};
    var ajaxUrl = String(cfg.ajaxUrl || window.ajaxurl || '');
    var fetchAction = String(cfg.fetchAction || 'll_tools_get_category_lineup_candidates');
    var updateAction = String(cfg.updateAction || 'll_tools_update_category_lineup_sequence');
    var pageSize = Math.max(5, Math.min(50, parseInt(cfg.pageSize, 10) || 25));
    var i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};

    function message(key, fallback) {
        return typeof i18n[key] === 'string' && i18n[key] ? i18n[key] : fallback;
    }

    function responseMessage(response, fallback) {
        if (response && response.data && typeof response.data.message === 'string' && response.data.message) {
            return response.data.message;
        }
        return fallback;
    }

    function inputValue($form, name) {
        var $input = $form.find('[name="' + name + '"]').first();
        return $input.length ? String($input.val() || '') : '';
    }

    function requestContext($root) {
        var $form = $root.closest('form').first();
        var categoryId = parseInt($root.attr('data-ll-category-lineup-term-id'), 10) || 0;
        if (!categoryId && $form.length) {
            categoryId = parseInt(inputValue($form, 'll_vocab_lesson_category_settings_category_id'), 10) || 0;
        }

        var context = {
            category_id: categoryId,
            nonce: String($root.attr('data-ll-category-lineup-nonce') || cfg.nonce || '')
        };
        if ($form.length) {
            var lessonId = parseInt(inputValue($form, 'll_vocab_lesson_category_settings_lesson_id'), 10) || 0;
            if (lessonId > 0) {
                context.lesson_id = lessonId;
                context.wordset_id = parseInt(inputValue($form, 'll_vocab_lesson_category_settings_wordset_id'), 10) || 0;
                context.nonce = inputValue($form, 'll_vocab_lesson_category_settings_nonce');
            }
        }

        return context;
    }

    function managerRequest(action, context, data) {
        return $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action }, context, data || {})
        });
    }

    function pageSummary(page, totalPages) {
        return message('pageSummary', 'Page {page} of {pages}')
            .replace('{page}', String(page))
            .replace('{pages}', String(totalPages));
    }

    function iconButton(options) {
        return $('<button>', {
            type: 'button',
            class: 'll-category-lineup-manager__icon-button',
            'aria-label': options.label,
            title: options.label,
            'data-ll-lineup-mutation': options.mutation,
            'data-word-id': String(options.wordId),
            disabled: !!options.disabled
        }).append($('<span>', {
            'aria-hidden': 'true',
            text: options.icon
        }));
    }

    function renderPagination($target, data, view) {
        var page = Math.max(1, parseInt(data.page, 10) || 1);
        var totalPages = Math.max(1, parseInt(data.total_pages, 10) || 1);
        $target.empty();
        if (parseInt(data.total, 10) <= 0 || totalPages <= 1) {
            return;
        }

        $('<button>', {
            type: 'button',
            class: 'll-category-lineup-manager__page-button',
            text: message('previous', 'Previous'),
            disabled: page <= 1,
            'data-ll-lineup-page-view': view,
            'data-ll-lineup-page': String(page - 1)
        }).appendTo($target);
        $('<span>', {
            class: 'll-category-lineup-manager__page-summary',
            text: pageSummary(page, totalPages)
        }).appendTo($target);
        $('<button>', {
            type: 'button',
            class: 'll-category-lineup-manager__page-button',
            text: message('next', 'Next'),
            disabled: page >= totalPages,
            'data-ll-lineup-page-view': view,
            'data-ll-lineup-page': String(page + 1)
        }).appendTo($target);
    }

    function renderSequence($manager, data) {
        var $list = $manager.find('[data-ll-lineup-sequence-list]').first();
        var $empty = $manager.find('[data-ll-lineup-sequence-empty]').first();
        var total = Math.max(0, parseInt(data.total, 10) || 0);
        $list.empty();

        if (!Array.isArray(data.items) || !data.items.length) {
            $empty.text(message('noSequence', 'No custom positions yet.')).removeAttr('hidden');
        } else {
            $empty.attr('hidden', 'hidden');
            data.items.forEach(function (item) {
                var wordId = parseInt(item.id, 10) || 0;
                var position = Math.max(1, parseInt(item.position, 10) || 1);
                var $row = $('<li>', {
                    class: 'll-category-lineup-manager__row',
                    'data-ll-lineup-sequence-item': '1',
                    'data-word-id': String(wordId),
                    'data-position': String(position)
                });
                $('<span>', {
                    class: 'll-category-lineup-manager__position',
                    text: String(position),
                    'aria-hidden': 'true'
                }).appendTo($row);
                $('<span>', {
                    class: 'll-category-lineup-manager__title',
                    dir: 'auto',
                    text: String(item.title || '')
                }).appendTo($row);

                var $actions = $('<span>', { class: 'll-category-lineup-manager__row-actions' });
                iconButton({
                    label: message('moveUp', 'Move earlier'),
                    mutation: 'move_up',
                    wordId: wordId,
                    icon: '\u2191',
                    disabled: position <= 1 || !!item.missing
                }).appendTo($actions);
                iconButton({
                    label: message('moveDown', 'Move later'),
                    mutation: 'move_down',
                    wordId: wordId,
                    icon: '\u2193',
                    disabled: position >= total || !!item.missing
                }).appendTo($actions);
                iconButton({
                    label: message('reset', 'Reset to automatic position'),
                    mutation: 'reset',
                    wordId: wordId,
                    icon: '\u21ba'
                }).appendTo($actions);
                $actions.appendTo($row);
                $row.appendTo($list);
            });
        }

        renderPagination($manager.find('[data-ll-lineup-sequence-pagination]').first(), data, 'sequence');
    }

    function renderCandidates($manager, data) {
        var $list = $manager.find('[data-ll-lineup-candidate-list]').first();
        var $empty = $manager.find('[data-ll-lineup-candidate-empty]').first();
        $list.empty();

        if (!Array.isArray(data.items) || !data.items.length) {
            $empty.text(message('noCandidates', 'No category words match this search.')).removeAttr('hidden');
        } else {
            $empty.attr('hidden', 'hidden');
            data.items.forEach(function (item) {
                var wordId = parseInt(item.id, 10) || 0;
                var selected = !!item.selected;
                var $row = $('<li>', {
                    class: 'll-category-lineup-manager__row ll-category-lineup-manager__row--candidate',
                    'data-ll-lineup-candidate-item': '1',
                    'data-word-id': String(wordId)
                });
                $('<span>', {
                    class: 'll-category-lineup-manager__title',
                    dir: 'auto',
                    text: String(item.title || '')
                }).appendTo($row);
                if (selected) {
                    $('<span>', {
                        class: 'll-category-lineup-manager__selected',
                        text: '\u2713 ' + message('selected', 'Custom positioned')
                    }).appendTo($row);
                } else {
                    iconButton({
                        label: message('add', 'Add to custom sequence'),
                        mutation: 'add',
                        wordId: wordId,
                        icon: '+'
                    }).appendTo($row);
                }
                $row.appendTo($list);
            });
        }

        renderPagination($manager.find('[data-ll-lineup-candidate-pagination]').first(), data, 'candidates');
    }

    function setStatus($manager, text, state) {
        var $status = $manager.find('[data-ll-lineup-manager-status]').first();
        $status
            .text(String(text || ''))
            .attr('data-state', String(state || 'idle'));
        if (text) {
            $status.removeAttr('hidden');
        } else {
            $status.attr('hidden', 'hidden');
        }
    }

    function setBusy($manager, busy) {
        $manager.attr('aria-busy', busy ? 'true' : 'false');
        $manager.toggleClass('is-busy', !!busy);
    }

    function buildManager($root) {
        $root.find('[data-ll-category-lineup-list], [data-ll-category-lineup-order-input]').remove();
        $root.children('.ll-vocab-lesson-category-settings-help').remove();

        var $manager = $root.find('[data-ll-category-lineup-manager]').first();
        if (!$manager.length) {
            $manager = $('<div>', {
                class: 'll-category-lineup-manager-shell',
                'data-ll-category-lineup-manager': '1'
            }).appendTo($root.children('td').first().length ? $root.children('td').first() : $root);
        }
        $manager.empty().addClass('ll-category-lineup-manager');

        var $sequence = $('<section>', { class: 'll-category-lineup-manager__section' });
        $('<h4>', {
            class: 'll-category-lineup-manager__heading',
            text: message('sequenceHeading', 'Custom sequence')
        }).appendTo($sequence);
        $('<p>', {
            class: 'll-category-lineup-manager__help',
            text: message('sequenceHelp', 'Custom-positioned words are used first in the order shown.')
        }).appendTo($sequence);
        $('<ol>', {
            class: 'll-category-lineup-manager__list',
            'data-ll-lineup-sequence-list': '1'
        }).appendTo($sequence);
        $('<p>', {
            class: 'll-category-lineup-manager__empty',
            'data-ll-lineup-sequence-empty': '1',
            hidden: 'hidden'
        }).appendTo($sequence);
        $('<div>', {
            class: 'll-category-lineup-manager__pagination',
            'data-ll-lineup-sequence-pagination': '1'
        }).appendTo($sequence);
        $sequence.appendTo($manager);

        var $candidates = $('<section>', { class: 'll-category-lineup-manager__section' });
        $('<h4>', {
            class: 'll-category-lineup-manager__heading',
            text: message('candidatesHeading', 'Find category words')
        }).appendTo($candidates);
        var searchId = 'll-category-lineup-search-' + String(Math.random()).slice(2);
        var $search = $('<div>', { class: 'll-category-lineup-manager__search' });
        $('<label>', {
            class: 'screen-reader-text',
            for: searchId,
            text: message('searchPlaceholder', 'Search words')
        }).appendTo($search);
        $('<input>', {
            id: searchId,
            type: 'search',
            class: 'll-category-lineup-manager__search-input',
            placeholder: message('searchPlaceholder', 'Search words'),
            'data-ll-lineup-search-input': '1'
        }).appendTo($search);
        $('<button>', {
            type: 'button',
            class: 'll-category-lineup-manager__search-button',
            text: message('search', 'Search'),
            'data-ll-lineup-search': '1'
        }).appendTo($search);
        $('<button>', {
            type: 'button',
            class: 'll-category-lineup-manager__clear-button',
            text: message('clearSearch', 'Clear search'),
            'data-ll-lineup-clear-search': '1',
            hidden: 'hidden'
        }).appendTo($search);
        $search.appendTo($candidates);
        $('<ul>', {
            class: 'll-category-lineup-manager__list',
            'data-ll-lineup-candidate-list': '1'
        }).appendTo($candidates);
        $('<p>', {
            class: 'll-category-lineup-manager__empty',
            'data-ll-lineup-candidate-empty': '1',
            hidden: 'hidden'
        }).appendTo($candidates);
        $('<div>', {
            class: 'll-category-lineup-manager__pagination',
            'data-ll-lineup-candidate-pagination': '1'
        }).appendTo($candidates);
        $candidates.appendTo($manager);

        $('<p>', {
            class: 'll-category-lineup-manager__status',
            role: 'status',
            'aria-live': 'polite',
            'data-ll-lineup-manager-status': '1',
            hidden: 'hidden'
        }).appendTo($manager);
        return $manager;
    }

    function initManager($root) {
        var context = requestContext($root);
        if (!ajaxUrl || !context.category_id || !context.nonce) {
            return;
        }

        var $manager = buildManager($root);
        var state = {
            sequencePage: 1,
            candidatePage: 1,
            candidateSearch: '',
            sequenceData: null,
            candidateData: null,
            sequenceToken: 0,
            candidateToken: 0,
            pendingRequests: 0,
            mutating: false
        };

        function loadView(view) {
            var isSequence = view === 'sequence';
            var tokenKey = isSequence ? 'sequenceToken' : 'candidateToken';
            var page = isSequence ? state.sequencePage : state.candidatePage;
            state[tokenKey] += 1;
            var token = state[tokenKey];
            state.pendingRequests += 1;
            setBusy($manager, true);
            setStatus($manager, message('loading', 'Loading Line-Up words...'), 'loading');

            return managerRequest(fetchAction, context, {
                view: view,
                page: page,
                per_page: pageSize,
                search: isSequence ? '' : state.candidateSearch
            }).done(function (response) {
                if (token !== state[tokenKey]) {
                    return;
                }
                if (!response || response.success !== true || !response.data) {
                    setStatus($manager, responseMessage(response, message('loadError', 'Unable to load Line-Up words right now.')), 'error');
                    return;
                }
                if (isSequence) {
                    state.sequenceData = response.data;
                    state.sequencePage = Math.max(1, parseInt(response.data.page, 10) || 1);
                    renderSequence($manager, response.data);
                } else {
                    state.candidateData = response.data;
                    state.candidatePage = Math.max(1, parseInt(response.data.page, 10) || 1);
                    renderCandidates($manager, response.data);
                }
                setStatus($manager, '', 'idle');
            }).fail(function (jqXHR) {
                if (token !== state[tokenKey]) {
                    return;
                }
                setStatus(
                    $manager,
                    responseMessage(jqXHR && jqXHR.responseJSON, message('loadError', 'Unable to load Line-Up words right now.')),
                    'error'
                );
            }).always(function () {
                state.pendingRequests = Math.max(0, state.pendingRequests - 1);
                setBusy($manager, state.pendingRequests > 0 || state.mutating);
            });
        }

        function mutate($button) {
            if (state.mutating) {
                return;
            }
            var mutation = String($button.attr('data-ll-lineup-mutation') || '');
            var wordId = parseInt($button.attr('data-word-id'), 10) || 0;
            if (!mutation || !wordId) {
                return;
            }

            var $row = $button.closest('[data-ll-lineup-sequence-item]');
            var position = parseInt($row.attr('data-position'), 10) || 0;
            var sequenceData = state.sequenceData || {};
            var sequencePageSize = parseInt(sequenceData.per_page, 10) || pageSize;
            var total = parseInt(sequenceData.total, 10) || 0;

            state.mutating = true;
            setBusy($manager, true);
            setStatus($manager, message('updating', 'Updating sequence...'), 'loading');
            managerRequest(updateAction, context, {
                mutation: mutation,
                word_id: wordId
            }).done(function (response) {
                if (!response || response.success !== true || !response.data) {
                    setStatus($manager, responseMessage(response, message('updateError', 'Unable to update the Line-Up sequence right now.')), 'error');
                    return;
                }

                var sequenceCount = Math.max(0, parseInt(response.data.sequence_count, 10) || 0);
                if (mutation === 'add') {
                    state.sequencePage = Math.max(1, Math.ceil(sequenceCount / pageSize));
                } else if (mutation === 'reset') {
                    state.sequencePage = Math.max(1, Math.min(state.sequencePage, Math.ceil(sequenceCount / pageSize) || 1));
                } else if (mutation === 'move_up' && position > 0 && position === ((state.sequencePage - 1) * sequencePageSize) + 1 && state.sequencePage > 1) {
                    state.sequencePage -= 1;
                } else if (mutation === 'move_down' && position > 0 && position === state.sequencePage * sequencePageSize && position < total) {
                    state.sequencePage += 1;
                }

                setStatus($manager, responseMessage(response, message('updated', 'Sequence updated.')), 'success');
                loadView('sequence');
                loadView('candidates');
            }).fail(function (jqXHR) {
                setStatus(
                    $manager,
                    responseMessage(jqXHR && jqXHR.responseJSON, message('updateError', 'Unable to update the Line-Up sequence right now.')),
                    'error'
                );
            }).always(function () {
                state.mutating = false;
                setBusy($manager, state.pendingRequests > 0);
            });
        }

        $manager.on('click', '[data-ll-lineup-page-view]', function () {
            var view = String($(this).attr('data-ll-lineup-page-view') || '');
            var nextPage = Math.max(1, parseInt($(this).attr('data-ll-lineup-page'), 10) || 1);
            if (view === 'sequence') {
                state.sequencePage = nextPage;
            } else {
                state.candidatePage = nextPage;
            }
            loadView(view);
        });
        $manager.on('click', '[data-ll-lineup-mutation]', function () {
            mutate($(this));
        });
        $manager.on('click', '[data-ll-lineup-search]', function () {
            state.candidateSearch = String($manager.find('[data-ll-lineup-search-input]').val() || '').trim();
            state.candidatePage = 1;
            if (state.candidateSearch) {
                $manager.find('[data-ll-lineup-clear-search]').removeAttr('hidden');
            } else {
                $manager.find('[data-ll-lineup-clear-search]').attr('hidden', 'hidden');
            }
            loadView('candidates');
        });
        $manager.on('click', '[data-ll-lineup-clear-search]', function () {
            state.candidateSearch = '';
            state.candidatePage = 1;
            $manager.find('[data-ll-lineup-search-input]').val('');
            $(this).attr('hidden', 'hidden');
            loadView('candidates');
        });
        $manager.on('keydown', '[data-ll-lineup-search-input]', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $manager.find('[data-ll-lineup-search]').trigger('click');
            }
        });

        loadView('sequence');
        loadView('candidates');
    }

    $(function () {
        $('[data-ll-category-lineup-ordering]').each(function () {
            initManager($(this));
        });
    });
}(jQuery));
