/* global jQuery */
(function ($) {
    'use strict';

    const batch = 12;
    const paths = {
        play: 'm8 5 11 7-11 7Z',
        repeat: 'M19 10a7 7 0 1 0-1 7M19 4v6h-6',
        clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 1 1 18 0Z',
        bell: 'M5 17h14l-2-3V9a5 5 0 0 0-10 0v5ZM10 20h4',
        list: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
        back: 'm12 5-7 7 7 7M5 12h15',
        stats: 'M4 20h16M7 16v-5M12 16V4M17 16V8',
        check: 'm4 12 5 5L20 6'
    };

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icon(name) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', paths[name]);
        svg.append(path);
        return svg;
    }

    function button(label, className, glyph, action) {
        const control = element('button', 'll-level-button ' + className);
        control.type = 'button';
        control.title = label;
        control.setAttribute('aria-label', label);
        if (glyph) control.append(icon(glyph));
        control.addEventListener('click', action);
        return control;
    }

    function create(options) {
        const root = options.root;
        const section = root.querySelector('[data-ll-wordset-levels]');
        const config = options.config;
        if (!section || !config || !Array.isArray(config.levels) || !config.levels.length) return null;

        const t = config.i18n;
        const input = root.querySelector('[data-ll-wordset-page-search]');
        const groups = new Map();
        const categoryLevels = new Map();
        const contentLevels = new Map();
        const historyKey = 'llWordsetCurriculum';
        const wordset = options.wordsetId;
        const panels = new Set();
        const saves = new Map();
        let state = readState(history.state);
        let summary = null;
        let fetchError = false;
        let credentialsExpired = false;
        let request = null;
        let dirty = false;
        let notice = '';
        let noticeTimer = 0;
        let refreshTimer = 0;
        let retryUntil = 0;
        let refreshOnReturn = false;
        let lastSummaryRequestAt = 0;
        const staleAfter = 60 * 60 * 1000;
        const thumbnailAttempts = new Set();
        const thumbnailUrls = new Map();
        const thumbnailQueue = new Set();
        let thumbnailRequest = null;
        let thumbnailTimer = 0;
        const observer = typeof window.IntersectionObserver === 'function'
            ? new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    observer.unobserve(entry.target);
                    queueThumbnail(Number(entry.target.dataset.llThumbCategory));
                });
            }, {rootMargin:'120px'}) : null;

        function register(row) {
            const group = Object.assign({}, row, {
                categoryIds: new Set((row.categoryIds || []).map(Number)),
                contentIds: new Set((row.contentIds || []).map(Number))
            });
            groups.set(Number(row.level), group);
            group.categoryIds.forEach(id => categoryLevels.set(id, Number(row.level)));
            group.contentIds.forEach(id => contentLevels.set(id, Number(row.level)));
        }
        config.levels.forEach(register);
        if ((config.additionalCategoryIds || []).length || (config.additionalContentIds || []).length) {
            register({level: 0, categoryIds: config.additionalCategoryIds, contentIds: config.additionalContentIds});
        }
        if (state.view === 'level' && !groups.has(state.level)) state = home();

        function home() { return {wordset, view: 'home', level: null, query: '', limit: batch, parent: false}; }
        function readState(raw) {
            const saved = raw && raw[historyKey];
            if (!saved || saved.wordset !== wordset || !['home','level','search'].includes(saved.view)) return home();
            return Object.assign(home(), saved);
        }
        function writeState(method) {
            const next = Object.assign({}, history.state, {[historyKey]: state});
            try { history[method](next, '', location.href); } catch (_) { /* History can be disabled. */ }
        }
        function label(level) { return level ? t.level.replace(/%d/g, level) : t.additional; }
        function go(view, level) {
            if (state.view === view && state.level === level) return;
            state.scroll = window.scrollY;
            writeState('replaceState');
            state = Object.assign(home(), {view, level, parent: true, origin: state.view === 'level' ? state.level : null});
            writeState('pushState');
            apply(true);
        }
        function back() {
            if (state.parent && history.state && history.state[historyKey]) {
                history.back();
                return;
            }
            state = home();
            writeState('replaceState');
            apply(true);
        }
        function apply(focusHeading) {
            root.dataset.llLevelView = state.view;
            if (input) input.value = state.view === 'search' ? state.query : '';
            render();
            options.change(state.view === 'search' ? state.query : '');
            if (focusHeading && state.view !== 'search') {
                const heading = section.querySelector('[data-ll-level-focus]');
                if (heading) heading.focus({preventScroll:true});
            }
        }
        function announce(message) {
            notice = message;
            const status = section.querySelector('[data-ll-level-notice]');
            if (status) status.textContent = message;
            window.clearTimeout(noticeTimer);
            if (message) noticeTimer = window.setTimeout(() => announce(''), 8000);
        }
        function ajax(operation, data) {
            function requireReload() {
                credentialsExpired = true;
                fetchError = true;
                summary = null;
                window.clearTimeout(refreshTimer);
                render();
            }
            return $.ajax({url: options.ajaxUrl, method: 'POST', dataType: 'json', timeout: 20000,
                data: Object.assign({action:'ll_tools_wordset_levels', operation, wordset_id:wordset,
                    nonce:config.nonce, token:config.token},data)}).done(response => {
                if (response === -1 || response === '-1') requireReload();
            }).fail(xhr => {
                if (xhr.status === 403 || xhr.status === 410 || String(xhr.responseText || '').trim() === '-1') requireReload();
            });
        }
        function scheduleRefresh(delay) {
            window.clearTimeout(refreshTimer);
            refreshTimer = 0;
            if (document.hidden) { refreshOnReturn = true; return; }
            refreshTimer = window.setTimeout(refresh,delay);
        }
        function refresh() {
            if (!options.signedIn || credentialsExpired) return;
            if (document.hidden) { refreshOnReturn = true; return; }
            if (request) { dirty = true; return; }
            window.clearTimeout(refreshTimer);
            refreshTimer = 0;
            refreshOnReturn = false;
            lastSummaryRequestAt = Date.now();
            dirty = false;
            request = ajax('summary');
            request.done(response => {
                const data = response && response.success && response.data;
                fetchError = !data || !data.levels;
                if (!fetchError) {
                    retryUntil = 0;
                    summary = data;
                }
            }).fail(xhr => {
                const detail = xhr.responseJSON && xhr.responseJSON.data;
                if (detail && detail.code === 'll_level_progress_preparing' && xhr.status === 503) {
                    if (!retryUntil) retryUntil = Date.now() + 180000;
                    const wait = Math.max(1, Math.min(15,Number(detail.retry_after) || 3)) * 1000;
                    if (Date.now() + wait <= retryUntil) {
                        fetchError = false;
                        scheduleRefresh(wait);
                        return;
                    }
                }
                retryUntil = 0;
                fetchError = true;
            }).always(() => {
                request = null;
                if (dirty) { refresh(); return; }
                render();
            });
        }
        function refreshSoon() {
            if (!options.signedIn || credentialsExpired) return;
            scheduleRefresh(250);
        }
        function saveDays(level, value) {
            const id = Number(level);
            let pending = saves.get(id);
            if (!pending) {
                pending = {active:false, value, status:''};
                saves.set(id, pending);
            }
            pending.value = value;
            if (pending.active) return;
            function submit() {
                const sent = pending.value;
                pending.active = true;
                intervalStatus(t.saving);
                let ok = false;
                ajax('interval', {level:id,days:sent}).done(response => {
                    ok = !!(response && response.success && Number(response.data && response.data.days) === sent);
                }).always(() => {
                    pending.active = false;
                    if (sent !== pending.value) { submit(); return; }
                    intervalStatus(ok ? t.saved : t.saveError);
                    if (ok) refreshSoon();
                    else render();
                });
            }
            function intervalStatus(message) {
                pending.status = message;
                const el = section.querySelector('[data-ll-level-save="' + id + '"]');
                if (el) el.textContent = message;
            }
            submit();
        }
        function putThumbnail(control, url) {
            if (!url || control.querySelector('img')) return;
            const image = element('img','ll-level-thumb');
            image.src = url;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            control.prepend(image);
        }
        function queueThumbnail(id) {
            if (!id || thumbnailAttempts.has(id) || !options.loadThumbnails) return;
            thumbnailQueue.add(id);
            if (!thumbnailTimer && !thumbnailRequest) thumbnailTimer = window.setTimeout(loadThumbnails,30);
        }
        function loadThumbnails() {
            thumbnailTimer = 0;
            if (state.view !== 'home') { thumbnailQueue.clear(); return; }
            const batchIds = Array.from(thumbnailQueue).slice(0,8);
            if (!batchIds.length) return;
            batchIds.forEach(id => {thumbnailQueue.delete(id); thumbnailAttempts.add(id);});
            const result = options.loadThumbnails(batchIds);
            if (!result) return;
            thumbnailRequest = result;
            result.done(urls => {
                batchIds.forEach(id => {
                    const url = urls && urls[id];
                    if (url) thumbnailUrls.set(id,url);
                    section.querySelectorAll('[data-ll-thumb-category="' + id + '"]').forEach(el => putThumbnail(el,url));
                });
            }).always(() => {
                thumbnailRequest = null;
                if (thumbnailQueue.size) thumbnailTimer = window.setTimeout(loadThumbnails,30);
            });
        }
        function action(group, plan, categoryId, preview, global, fallback) {
            const item = categoryId ? options.category(categoryId) : null;
            const name = item && (item.name || item.translation) || '';
            const verb = plan === 'due' ? t.review : (plan === 'learn' ? (fallback ? t.start : t.learn) : t.practice);
            const text = global ? t.reviewAll : (name ? verb + ' · ' + name : verb);
            const btn = button(text, global ? 'll-level-review-all' : 'll-level-activity', plan === 'learn' ? 'play' : 'repeat', () => {
                announce('');
                options.launch(group.level,plan,categoryId,ajax,() => announce(t.launchError),fallback);
            });
            btn.dataset.llLevelAction = plan;
            btn.dataset.level = String(group.level);
            btn.dataset.llFocusKey = 'activity-' + group.level + '-' + plan;
            if (preview && item) {
                const thumb = (item.preview || []).find(it => it.type === 'image' && it.url);
                const url = (thumb && thumb.url) || thumbnailUrls.get(categoryId) || options.thumbnail(categoryId);
                if (url) putThumbnail(btn,url);
                else if (item.preview_deferred || item.has_images) btn.dataset.llThumbCategory = String(categoryId);
            }
            btn.append(element('span','',text));
            return btn;
        }
        function addStat(list, term, value) {
            list.append(element('dt','',term),element('dd','',value));
        }
        function studyDate(value) {
            if (!value) return t.never;
            let date = String(value);
            if (/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(date)) date = date.replace(' ','T') + 'Z';
            const parsed = new Date(date);
            return Number.isFinite(parsed.getTime()) ? parsed.toLocaleDateString(document.documentElement.lang || undefined) : String(value);
        }
        function detail(group, stats) {
            const level = Number(group.level);
            const info = element('details','ll-level-details');
            info.open = panels.has(level);
            const heading = element('summary','ll-level-details-toggle');
            heading.title = t.stats;
            heading.dataset.llFocusKey = 'stats-' + level;
            heading.append(icon('stats'),element('span','',t.stats));
            info.append(heading);
            info.addEventListener('toggle',() => {
                if (!info.isConnected) return;
                if (info.open) panels.add(level); else panels.delete(level);
            });
            const list = element('dl','ll-level-facts');
            addStat(list,t.lessons,group.categoryIds.size + group.contentIds.size);
            if (stats) {
                addStat(list,t.words,stats.total);
                addStat(list,t.mastered,stats.mastered);
                addStat(list,t.working,stats.working);
                addStat(list,t.unseen,stats.new);
                addStat(list,t.dueLabel,stats.due);
                addStat(list,t.addedLabel,stats.added);
                addStat(list,t.lastStudy,studyDate(stats.last_studied_at));
            }
            info.append(list);
            if (stats && options.signedIn) {
                const pending = saves.get(level);
                const wrap = element('label','ll-level-interval',t.interval);
                const field = element('input');
                field.type = 'number'; field.min = '1'; field.max = '365'; field.step = '1';
                field.inputMode = 'numeric'; field.dataset.llFocusKey = 'interval-' + level;
                field.value = String(pending ? pending.value : stats.days);
                field.addEventListener('change',() => {
                    if (!field.value || !field.reportValidity()) return;
                    saveDays(level,Number(field.value));
                });
                wrap.append(field);
                const feedback = element('span','ll-level-save',pending ? pending.status : '');
                feedback.dataset.llLevelSave = String(level);
                feedback.setAttribute('role','status');
                info.append(wrap,feedback);
                if (pending && pending.status === t.saveError) {
                    const retry = button(t.retry,'ll-level-retry','repeat',() => saveDays(level,pending.value));
                    retry.append(document.createTextNode(t.retry)); info.append(retry);
                }
            }
            return info;
        }
        function progressBar(stats) {
            const bar = element('div','ll-level-track');
            if (stats && Number(stats.total) > 0) {
                const values = [[t.mastered,'mastered',stats.mastered],[t.working,'working',stats.working],[t.unseen,'new',stats.new]];
                bar.setAttribute('role','img');
                const text = values.map(([title,,v]) => title + ' ' + v).join(', ');
                bar.setAttribute('aria-label',text);
                bar.title = text;
                values.forEach(([,key,v]) => {
                    if (Number(v) <= 0) return;
                    const span = element('span','ll-level-track-' + key);
                    span.style.flexGrow = String(v);
                    bar.append(span);
                });
            } else { bar.setAttribute('aria-hidden','true'); }
            return bar;
        }
        function card(group,current) {
            const id = Number(group.level);
            const stats = summary && summary.levels && summary.levels[id];
            const shell = element('article','ll-level-card');
            shell.dataset.llLevelCard = String(id);
            const head = element('div','ll-level-card-head');
            const title = element('h2','ll-level-title');
            const open = button(t.browse + ' · ' + label(id),'ll-level-open',null,() => go('level',id));
            open.textContent = label(id);
            open.dataset.llFocusKey = 'browse-' + id;
            title.append(open);
            head.append(title);
            open.append(icon('list'));
            shell.append(head);
            if (!id) return shell;
            const badges = element('div','ll-level-badges');
            if (id === current) {
                shell.classList.add('is-current');
                shell.setAttribute('aria-current','step');
                head.append(element('span','ll-level-current',t.current));
            } else if (stats && Number(stats.total) > 0 && Number(stats.mastered) === Number(stats.total)) {
                const check = element('span','ll-level-complete');
                check.setAttribute('role','img');
                check.setAttribute('aria-label',t.complete);
                check.title = t.complete;
                check.append(icon('check'));
                head.append(check);
            }
            if (stats && Number(stats.due) > 0) {
                const dueText = t.due.replace(/%d/g,stats.due);
                const due = button(dueText,'ll-level-badge ll-level-overdue','clock',() =>
                    options.launch(id,'due',Number(stats.due_category_id),ajax,() => announce(t.launchError)));
                due.append(element('span','',stats.due)); badges.append(due);
            }
            if (stats && Number(stats.added) > 0) {
                const newText = t.added.replace(/%d/g,stats.added);
                const marker = element('span','ll-level-badge ll-level-added');
                marker.title = newText; marker.setAttribute('aria-label',newText); marker.setAttribute('role','img');
                marker.append(icon('bell'),element('span','',stats.added)); badges.append(marker);
            }
            shell.append(progressBar(stats));
            if (badges.childElementCount) shell.append(badges);
            if (!stats) {
                shell.append(element('small','ll-level-lesson-count',t.lessons + ' · ' + (group.categoryIds.size + group.contentIds.size)));
            }
            const actions = element('div','ll-level-activities');
            if (stats) {
                if (Number(stats.due) > 0 && stats.due_category_id) actions.append(action(group,'due',Number(stats.due_category_id),true,false));
                else if (stats.practice_category_id) actions.append(action(group,'practice',Number(stats.practice_category_id),true,false));
                if (stats.next_category_id) actions.append(action(group,'learn',Number(stats.next_category_id),true,false));
            }
            if (!actions.childElementCount && (group.nextId || group.practiceId)) {
                actions.append(action(group,group.nextId ? 'learn' : 'practice',Number(group.nextId || group.practiceId),true,false,true));
            }
            if (actions.childElementCount) shell.append(actions);
            shell.append(detail(group,stats));
            return shell;
        }
        function render() {
            const focused = document.activeElement;
            // Updating progress must not eat partially typed interval edits.
            if (section.contains(focused) && focused.matches('input[type=number]') && state.view === 'home') {
                focused.addEventListener('blur',render,{once:true});
                return;
            }
            const key = section.contains(focused) && focused.dataset.llFocusKey;
            const fragment = document.createDocumentFragment();
            if (state.view === 'home') {
                if (options.signedIn && (!summary || fetchError)) {
                    const status = element('div','ll-level-state');
                    status.setAttribute('role','status');
                    status.append(element('span','',credentialsExpired ? t.scopeExpired : (fetchError ? t.error : t.loading)));
                    if (fetchError) {
                        const label = credentialsExpired ? t.reload : t.retry;
                        const retry = button(label,'ll-level-retry','repeat',credentialsExpired ? () => location.reload() : refresh);
                        retry.append(element('span','',label)); status.append(retry);
                    }
                    fragment.append(status);
                }
                if (summary && Number(summary.due) > 0) {
                    const review = action({level:0},'due',0,false,true);
                    review.append(element('span','ll-level-count',summary.due)); fragment.append(review);
                }
                const grid = element('div','ll-level-grid');
                const rows = Array.from(groups.values()).filter(row => Number(row.level) > 0);
                const current = !summary ? rows[0] : rows.find(row => {
                    const stat = summary.levels[row.level];
                    return stat && (Number(stat.new) > 0 || Number(stat.working) > 0) &&
                        (stat.next_category_id || stat.practice_category_id || stat.due_category_id || row.nextId || row.practiceId);
                });
                groups.forEach(group => grid.append(card(group,current && Number(current.level))));
                fragment.append(grid);
            } else {
                const nav = element('div','ll-level-nav');
                const prev = button(t.back,'ll-level-back','back',back);
                prev.dataset.llFocusKey = 'back';
                prev.append(element('span','',t.back));
                const heading = element('h2','ll-level-title',state.view === 'search' ? t.allLessons : label(state.level));
                heading.tabIndex = -1; heading.dataset.llLevelFocus = '';
                nav.append(prev,heading); fragment.append(nav);
            }
            const status = element('div','ll-level-notice',notice);
            status.dataset.llLevelNotice = ''; status.setAttribute('role','status');
            fragment.append(status);
            section.replaceChildren(fragment);
            section.hidden = false;
            if (observer) observer.disconnect();
            const previews = section.querySelectorAll('[data-ll-thumb-category]');
            previews.forEach((btn,index) => {
                if (observer) observer.observe(btn);
                else if (index < 8) queueThumbnail(Number(btn.dataset.llThumbCategory));
            });
            if (key) {
                const target = section.querySelector('[data-ll-focus-key="' + key + '"]');
                if (target) target.focus({preventScroll:true});
            }
        }
        const more = button(t.more,'ll-level-more',null,() => {
            state.limit += batch;
            writeState('replaceState');
            options.change('');
        });
        more.textContent = t.more;
        more.hidden = true;
        const grid = root.querySelector('[data-ll-wordset-main-grid], .ll-wordset-grid');
        if (grid) grid.after(more);
        function inGroup(type,id) {
            return (type === 'content' ? contentLevels : categoryLevels).get(Number(id)) === state.level;
        }
        function badges(container) {
            container.querySelectorAll('.ll-wordset-card[data-cat-id],.ll-wordset-card[data-lesson-id]').forEach(node => {
                if (node.querySelector('[data-ll-level-badge]')) return;
                const level = node.hasAttribute('data-cat-id') ? categoryLevels.get(Number(node.dataset.catId)) : contentLevels.get(Number(node.dataset.lessonId));
                if (level === undefined) return;
                const heading = node.querySelector('.ll-wordset-card__heading');
                if (!heading) return;
                const badge = element('span','ll-wordset-level-badge',label(level));
                badge.dataset.llLevelBadge = String(level);
                heading.append(badge);
            });
        }
        const instance = {
            init(query) {
                if (query && state.view !== 'search') state = Object.assign(home(),{view:'search',query});
                writeState('replaceState');
                apply(false);
                if (options.signedIn) refresh();
            },
            isScoped: () => state.view !== 'search',
            containsCategory: (level,id) => categoryLevels.get(Number(id)) === level,
            resultAction(level, currentIds) {
                const group = groups.get(level);
                const browse = () => {
                    document.getElementById('ll-tools-close-flashcard')?.click();
                    go(level && group ? 'level' : 'home', level && group ? level : null);
                };
                const explore = {label: level ? t.browse + ' · ' + label(level) : t.allLevels, run: browse};
                if (!level || !group) return explore;
                let nextId;
                if (options.signedIn) {
                    nextId = summary && summary.levels[level] && summary.levels[level].next_category_id;
                } else {
                    const ids = Array.from(group.categoryIds);
                    const last = Math.max(...currentIds.map(id => ids.indexOf(Number(id))));
                    nextId = ids.slice(last + 1).find(id => options.category(id)?.learning_supported);
                }
                if (!nextId) return explore;
                return {
                    label: t.continueLevel.replace(/%d/g,level),
                    run: () => options.launch(level,'learn',Number(nextId),ajax,browse)
                };
            },
            contentIds: () => state.view === 'level' ? Array.from(groups.get(state.level).contentIds) : [],
            allows: (type,id) => state.view === 'search' || (state.view === 'level' && inGroup(type,id)),
            pageLimit: () => state.limit,
            updateMore(count) { more.hidden = state.view !== 'level' || count <= state.limit; },
            input(value) {
                if (state.view !== 'search') go('search',null);
                if (input && input.value !== value) input.value = value;
                state.query = value;
                writeState('replaceState');
            },
            badges
        };
        if (input) input.addEventListener('focus',() => { if (state.view !== 'search') go('search',null); });
        window.addEventListener('popstate',event => {
            const previous = state;
            state = readState(event.state);
            apply(false);
            if (typeof state.scroll === 'number') window.scrollTo(0,state.scroll);
            if (previous.view === 'level' && state.view === 'home') {
                section.querySelector('[data-ll-focus-key="browse-' + previous.level + '"]')?.focus({preventScroll:true});
            } else if (state.view === 'search' && input) input.focus({preventScroll:true});
            else section.querySelector('[data-ll-level-focus], [aria-current="step"] .ll-level-open, .ll-level-open')?.focus({preventScroll:true});
        });
        function resumeSummary() {
            if (document.hidden || !options.signedIn || credentialsExpired || request || refreshTimer) return;
            if (refreshOnReturn || Date.now() - lastSummaryRequestAt >= staleAfter) refresh();
        }
        document.addEventListener('visibilitychange',() => {
            if (document.hidden) {
                if (refreshTimer) {
                    window.clearTimeout(refreshTimer);
                    refreshTimer = 0;
                    refreshOnReturn = true;
                }
                retryUntil = 0;
            } else resumeSummary();
        });
        window.addEventListener('pageshow',resumeSummary);
        $(document).on('lltools:flashcard-closed.llLevels',refreshSoon);
        $(document).on('lltools:progress-updated.llLevels',() => {
            if (!document.body.classList.contains('ll-tools-flashcard-open')) refreshSoon();
        });
        return instance;
    }

    window.LLWordsetLevels = {create};
})(jQuery);
