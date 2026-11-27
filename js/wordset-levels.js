/* global jQuery */
(function ($) {
    'use strict';

    const batch = 12;
    const paths = {
        repeat: 'M19 10a7 7 0 1 0-1 7M19 4v6h-6',
        back: 'm12 5-7 7 7 7M5 12h15',
        forward: 'M4 12h15m-6-6 6 6-6 6',
        chevron: 'm3 8 9 9 9-9',
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
        svg.setAttribute('class','ll-level-glyph ll-level-glyph--' + name);
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
        let renderAfterMotion = false;
        const openFilters = new Map();
        const levelFilters = [
            {mode:'due',status:'older',name:t.dueLabel,help:t.dueHelp},
            {mode:'hard',status:'hard',name:t.hard,help:t.hardHelp},
            {mode:'starred',status:'starred',name:t.starred,help:t.starredHelp}
        ];
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
        let dayResetTimer = 0;
        let dayResetAt = 0;
        const staleAfter = 60 * 1000;
        const thumbnailAttempts = new Set();
        const thumbnailUrls = new Map();
        const brokenThumbnails = new Set();
        const thumbnailQueue = new Set();
        let thumbnailRequest = null;
        let thumbnailTimer = 0;
        const observer = typeof window.IntersectionObserver === 'function'
            ? new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    observer.unobserve(entry.target);
                    queueButtonThumbnails(entry.target);
                });
            }, {rootMargin:'120px'}) : null;
        let unobservedThumbnails = [];
        function loadNearbyThumbnails() {
            unobservedThumbnails = unobservedThumbnails.filter(control => {
                const rect = control.getBoundingClientRect();
                if (rect.bottom < -120 || rect.top > window.innerHeight + 120) return true;
                queueButtonThumbnails(control);
                return false;
            });
        }
        if (!observer) {
            window.addEventListener('scroll',loadNearbyThumbnails,{passive:true});
            window.addEventListener('resize',loadNearbyThumbnails);
        }

        function register(row) {
            const group = Object.assign({}, row, {
                categoryIds: new Set((row.categoryIds || []).map(Number)),
                contentIds: new Set((row.contentIds || []).map(Number))
            });
            groups.set(Number(row.level), group);
            group.categoryIds.forEach(id => categoryLevels.set(id, Number(row.level)));
            group.contentIds.forEach(id => contentLevels.set(id, Number(row.level)));
        }
        config.levels.filter(row => Number(row.level) > 0).forEach(register);
        if (state.view === 'level' && !groups.has(state.level)) state = home();

        function home() { return {wordset, view: 'home', level: null, query: '', limit: batch, parent: false}; }
        function readState(raw) {
            const saved = raw && raw[historyKey];
            if (!saved || saved.wordset !== wordset || !['home','level','search'].includes(saved.view)) return home();
            if (saved.view === 'level' && (!saved.level || (groups.size && !groups.has(saved.level)))) return home();
            return Object.assign(home(), saved);
        }
        function writeState(method) {
            const next = Object.assign({}, history.state, {[historyKey]: state});
            try { history[method](next, '', location.href); } catch (_) { /* History can be disabled. */ }
        }
        function label(level) { return t.level.replace(/%d/g, level); }
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
            paginationReady = false;
            paginationNearby = false;
            manualPage = null;
            autoPageAt = null;
            if (pageObserver) pageObserver.unobserve(more);
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
                    window.clearTimeout(dayResetTimer);
                    const resetIn = Number(data.next_reset_in) * 1000;
                    dayResetAt = resetIn > 0 ? Date.now() + resetIn : 0;
                    if (dayResetAt) dayResetTimer = window.setTimeout(refresh,resetIn);
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
        function distinctThumbnails(urls) {
            const seen = new Set();
            return urls.filter(url => {
                if (!url) return false;
                // WordPress can serve the same upload in different thumbnail sizes.
                const key = url.replace(/-\d+x\d+(?=\.(?:jpe?g|png|webp|avif|gif)(?:[?#]|$))/i,'');
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        }
        function categoryThumbnails(id) {
            const previews = options.category(id)?.preview || [];
            const images = previews.filter(item => item.type === 'image').map(item => item.url);
            return distinctThumbnails(images.concat(thumbnailUrls.get(id) || [], options.thumbnails(id))).slice(0,2);
        }
        function updateButtonThumbnails(control) {
            const ids = control.dataset.llThumbCategories.split(' ').map(Number);
            const sources = ids.map(categoryThumbnails);
            // A mixed review can show one photo from each lesson before a second from either.
            const urls = distinctThumbnails(sources.map(images => images[0]).concat(...sources)).slice(0,2);
            const mayLoadMore = !!options.loadThumbnails && ids.some((id,index) => {
                const cat = options.category(id);
                return !thumbnailUrls.has(id) && sources[index].length < 2 && (cat.preview_deferred || cat.has_images);
            });
            let strip = control.querySelector('.ll-level-thumbs');
            const slotCount = mayLoadMore ? 2 : urls.length;
            if (!slotCount && !strip) return;
            if (!strip) {
                strip = element('span','ll-level-thumbs');
                strip.setAttribute('aria-hidden','true');
                control.querySelector('.ll-level-activity-mode').after(strip);
            }
            // Keep the original footprint when a lookup returns fewer photos than expected.
            for (let index = 0; index < Math.max(slotCount,strip.children.length); index++) {
                let slot = strip.children[index];
                if (!slot) {
                    slot = element('span','ll-level-thumb-slot');
                    strip.append(slot);
                }
                const url = urls[index];
                if (!url || brokenThumbnails.has(url)) {
                    slot.replaceChildren();
                    slot.classList.toggle('ll-level-sheen',!url && mayLoadMore);
                    continue;
                }
                if (slot.firstChild?.getAttribute('src') === url) continue;
                const image = element('img','ll-level-thumb');
                image.alt = '';
                image.loading = 'lazy';
                image.decoding = 'async';
                slot.classList.add('ll-level-sheen');
                slot.replaceChildren(image);
                image.onload = async () => {
                    if (image.decode) {
                        try { await image.decode(); } catch (_) { /* The loaded image can still be painted. */ }
                    }
                    if (image.parentElement === slot) slot.classList.remove('ll-level-sheen');
                };
                image.onerror = () => {
                    brokenThumbnails.add(url);
                    if (image.parentElement !== slot) return;
                    slot.classList.remove('ll-level-sheen');
                    image.remove();
                };
                image.src = url;
            }
        }
        function queueButtonThumbnails(control) {
            const ids = control.dataset.llThumbCategories.split(' ').map(Number);
            const needPair = control.querySelectorAll('.ll-level-thumb').length < 2;
            ids.forEach(id => {
                const cat = options.category(id);
                const count = categoryThumbnails(id).length;
                if (cat && (cat.preview_deferred || cat.has_images) && (!count || (needPair && count < 2))) queueThumbnail(id);
            });
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
            function finish(urls) {
                const controls = new Set();
                batchIds.forEach(id => {
                    thumbnailUrls.set(id,urls && urls[id] || []);
                    section.querySelectorAll('[data-ll-thumb-categories~="' + id + '"]').forEach(control => controls.add(control));
                });
                controls.forEach(updateButtonThumbnails);
                thumbnailRequest = null;
                if (thumbnailQueue.size) thumbnailTimer = window.setTimeout(loadThumbnails,30);
            }
            if (!result) { finish(); return; }
            thumbnailRequest = result;
            result.done(finish).fail(() => finish());
        }
        function dailyReview(review) {
            const reasons = {
                due: ['older',t.dueLabel], working: ['studied',t.working], hard: ['hard',t.hard],
                starred: ['starred',t.starred], least_recent: ['practice',t.leastRecent]
            };
            const reason = review && reasons[review.reason];
            if (!reason || !(Number(review.count) > 0)) return null;
            const [status,reasonLabel] = reason;
            const backlog = Number(review.due) || 0;
            const topic = review.reason === 'due' ? t.due.replace(/%d/g,backlog) : reasonLabel;
            const verb = review.reason === 'due' ? t.reviewAll : t.practice;
            const label = [verb,topic,t.dailyRemaining,review.count].join(' · ');
            const control = button(label,'ll-level-review-all ll-level-metric-' + status,null,() => {
                announce('');
                options.launch(0,'review',0,ajax,() => announce(t.launchError));
            });
            control.dataset.llLevelAction = 'review';
            control.dataset.level = '0';
            control.dataset.llFocusKey = 'daily-review';
            control.insertAdjacentHTML('beforeend',status === 'practice' ? options.modeIcon('practice','ll-level-daily-icon ll-level-mode-icon') :
                options.progressIcon(status,'ll-level-daily-icon'));
            const copy = element('span','ll-level-review-copy');
            copy.append(element('strong','',verb),element('span','ll-level-review-reason',topic));
            const size = element('span','ll-level-round-size');
            size.append(element('span','',t.dailyRemaining),element('strong','',review.count));
            control.append(copy,size,icon('forward'));
            return control;
        }
        function loadingPlaceholder(className) {
            const slot = element('span','ll-level-button ll-level-sheen ll-level-placeholder ' + className);
            slot.setAttribute('aria-hidden','true');
            return slot;
        }
        function action(group, plan, categoryId, fallback) {
            const item = categoryId ? options.category(categoryId) : null;
            const stats = summary && summary.levels[group.level];
            const planned = stats && plan === 'practice' && stats.practice_activity;
            const severalLessons = !fallback && planned && Array.isArray(planned.category_ids) && planned.category_ids.length > 1;
            const name = severalLessons
                ? (planned.label || [t.words,planned.word_count || planned.session_word_ids?.length].filter(Boolean).join(' · '))
                : (item && (item.name || item.translation) || '');
            const verb = plan === 'learn' ? t.learn : t.practice;
            const text = name ? verb + ' · ' + name : verb;
            const btn = button(text,'ll-level-activity',null,() => {
                announce('');
                options.launch(group.level,plan,categoryId,ajax,() => announce(t.launchError),fallback);
            });
            btn.dataset.llLevelAction = plan;
            btn.dataset.level = String(group.level);
            btn.dataset.category = String(categoryId);
            btn.dataset.llFocusKey = 'activity-' + group.level + '-' + plan;
            const mode = element('span','ll-level-activity-mode');
            mode.insertAdjacentHTML('beforeend',options.modeIcon(plan === 'learn' ? 'learning' : 'practice','ll-level-mode-icon'));
            mode.append(element('span','ll-level-mode-caption',verb));
            btn.append(mode);
            const previewIds = severalLessons ? [categoryId,...planned.category_ids] : [categoryId];
            if (Array.isArray(previewIds) && previewIds.length) {
                const ids = new Set(previewIds.map(Number));
                const imaged = [];
                const deferred = [];
                for (const id of ids) {
                    const cat = options.category(id);
                    if (!cat) continue;
                    if (cat.has_images || categoryThumbnails(id).length) imaged.push(id);
                    else if (cat.preview_deferred) deferred.push(id);
                    if (imaged.length === 2) break;
                }
                const imageIds = imaged.concat(deferred).slice(0,2);
                if (imageIds.length) {
                    btn.dataset.llThumbCategories = imageIds.join(' ');
                    updateButtonThumbnails(btn);
                }
            }
            if (name) btn.append(element('span','ll-level-action-name',name));
            btn.append(icon('forward'));
            return btn;
        }
        function addLevelFilterButtons(level, stats, badges, panelParent) {
            const controls = [];
            levelFilters.forEach(({mode,status,name,help}) => {
                const count = Number(stats[mode]);
                if (!Number.isFinite(count) || count <= 0) return;
                const key = 'head-' + mode + '-' + level;
                const number = count.toLocaleString(document.documentElement.lang || undefined);
                const due = mode === 'due';
                const title = due ? t.due.replace(/%d/g,number) : name + ' · ' + number;
                const metric = ' ll-level-metric-' + status;
                const panel = element('div','ll-level-filter-panel' + metric);
                panel.id = 'll-level-' + wordset + '-' + key;
                panel.hidden = openFilters.get(level) !== mode;
                const toggle = button(title + ' · ' + label(level),'ll-level-filter-toggle' + metric,null,() => {
                    const opening = panel.hidden;
                    if (opening) openFilters.set(level,mode); else openFilters.delete(level);
                    controls.forEach(control => {
                        const expanded = opening && control.panel === panel;
                        control.panel.hidden = !expanded;
                        control.toggle.setAttribute('aria-expanded',String(expanded));
                    });
                });
                toggle.dataset.llLevelFilter = mode;
                toggle.dataset.llFocusKey = key;
                toggle.setAttribute('aria-controls',panel.id);
                toggle.setAttribute('aria-expanded',String(!panel.hidden));
                const pill = element('span','ll-level-badge');
                pill.insertAdjacentHTML('beforeend',options.progressIcon(status,'ll-level-status-icon'));
                pill.append(element('span','',number),icon('chevron'));
                toggle.append(pill);
                controls.push({toggle,panel});
                badges.append(toggle);

                panel.append(element('strong','',title),element('p','',help));
                const launch = button([t.practice,name,label(level)].join(' · '),'ll-level-filter-start',null,() => {
                    announce('');
                    options.launch(level,mode,due ? Number(stats.due_category_id) : 0,ajax,() => announce(t.launchError));
                });
                launch.dataset.llFocusKey = key + '-start';
                launch.insertAdjacentHTML('beforeend',options.modeIcon('practice','ll-level-mode-icon'));
                launch.append(element('span','',t.practice),icon('forward'));
                const round = due && stats.due_activity?.session_word_ids?.length;
                if (round && round < count) panel.append(element('small','',t.roundSize + ' · ' + round));
                panel.append(launch);
                panelParent.append(panel);
            });
        }
        function addStat(list, term, value, status) {
            const pair = element('div','ll-level-fact');
            const count = element('dd',status ? 'll-level-stat-value ll-level-metric-' + status : '');
            if (status) count.insertAdjacentHTML('beforeend',options.progressIcon(status,'ll-level-status-icon'));
            count.append(value);
            pair.append(element('dt','',term),count);
            list.append(pair);
        }
        function studyDate(value) {
            if (!value) return t.never;
            let date = String(value);
            if (/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(date)) date = date.replace(' ','T') + 'Z';
            const parsed = new Date(date);
            return Number.isFinite(parsed.getTime()) ? parsed.toLocaleDateString(document.documentElement.lang || undefined) : String(value);
        }
        function bindDetailToggle(level, info, heading, body) {
            let motion = null;
            function reflect(open) {
                if (open) panels.add(level); else panels.delete(level);
                heading.setAttribute('aria-expanded',String(open));
            }
            function settle(open) {
                info.open = open;
                if (motion) motion.cancel();
                motion = null;
                body.classList.remove('is-animating');
                body.inert = false;
                if (renderAfterMotion && info.isConnected) render();
            }
            reflect(info.open);
            info.addEventListener('toggle',() => {
                // Native reveals (e.g. find in page) also survive a progress refresh.
                if (info.isConnected && !motion) reflect(info.open);
            });
            heading.addEventListener('click',event => {
                event.preventDefault();
                const open = motion ? !panels.has(level) : !info.open;
                const from = info.open ? body.getBoundingClientRect().height : 0;
                if (motion) {
                    motion.onfinish = null;
                    motion.cancel();
                }
                if (!open && body.contains(document.activeElement)) heading.focus({preventScroll:true});
                reflect(open);
                if (!body.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    settle(open);
                    return;
                }
                // Keep the native disclosure open until its closing slide is finished.
                info.open = true;
                const to = open ? body.getBoundingClientRect().height : 0;
                if (from === to) {
                    settle(open);
                    return;
                }
                body.classList.add('is-animating');
                body.inert = !open;
                motion = body.animate([{height:from + 'px'},{height:to + 'px'}],{duration:220,easing:'ease-in-out',fill:'both'});
                motion.onfinish = () => settle(open);
            });
        }
        function detail(group, stats) {
            const level = Number(group.level);
            const info = element('details','ll-level-details');
            info.open = panels.has(level);
            const heading = element('summary','ll-level-details-toggle');
            heading.title = t.stats + ' · ' + label(level);
            heading.setAttribute('aria-label',heading.title);
            heading.dataset.llFocusKey = 'stats-' + level;
            heading.append(icon('chevron'));
            const body = element('div','ll-level-detail-body');
            const panel = element('div','ll-level-detail-panel');
            body.append(panel);
            info.append(heading,body);
            bindDetailToggle(level,info,heading,body);
            const modes = options.signedIn && Array.isArray(stats?.modes) ? stats.modes : group.modes;
            panel.append(options.quizButtons(level,modes,label(level),credentialsExpired || !config.nonce || !config.token,mode => {
                announce('');
                options.launchQuiz(level,mode,ajax,() => announce(t.launchError));
            }));
            const panelHeader = element('div','ll-level-detail-header');
            const browse = button(t.seeAll + ' · ' + label(level),'ll-level-see-all',null,() => go('level',level));
            browse.dataset.llFocusKey = 'see-all-' + level;
            browse.append(element('span','',t.seeAll),icon('forward'));
            panelHeader.append(element('h3','ll-level-detail-title',t.stats),browse);
            panel.append(panelHeader);
            const list = element('dl','ll-level-facts');
            panel.append(list);
            addStat(list,t.lessons,group.categoryIds.size + group.contentIds.size);
            if (stats) {
                addStat(list,t.words,stats.total);
                addStat(list,t.mastered,stats.mastered,'mastered');
                addStat(list,t.working,stats.working,'studied');
                addStat(list,t.unseen,stats.new,'new');
                levelFilters.forEach(({mode,status,name}) => {
                    const count = Number(stats[mode]);
                    if (Number.isFinite(count)) addStat(list,name,Math.max(0,count),status);
                });
                addStat(list,t.lastStudy,studyDate(stats.last_studied_at));
            }
            if (stats && options.signedIn) {
                const pending = saves.get(level);
                const wrap = element('div','ll-level-interval');
                const fieldLabel = element('label','ll-level-interval-label',t.interval);
                const unit = element('span','',t.days);
                const help = element('span','ll-level-interval-help',t.intervalHelp);
                const field = element('input');
                field.id = 'll-level-interval-' + wordset + '-' + level;
                fieldLabel.htmlFor = field.id;
                unit.id = field.id + '-unit';
                help.id = field.id + '-help';
                field.setAttribute('aria-describedby',unit.id + ' ' + help.id);
                field.type = 'number'; field.min = '1'; field.max = '365'; field.step = '1';
                field.inputMode = 'numeric'; field.dataset.llFocusKey = 'interval-' + level;
                field.value = String(pending ? pending.value : stats.days);
                field.addEventListener('change',() => {
                    if (!field.value || !field.reportValidity()) return;
                    saveDays(level,Number(field.value));
                });
                const value = element('span','ll-level-interval-value');
                value.append(field,unit);
                const feedback = element('span','ll-level-save',pending ? pending.status : '');
                feedback.dataset.llLevelSave = String(level);
                feedback.setAttribute('role','status');
                value.append(feedback);
                wrap.append(fieldLabel,value,help);
                panel.append(wrap);
                if (pending && pending.status === t.saveError) {
                    const retry = button(t.retry,'ll-level-retry','repeat',() => saveDays(level,pending.value));
                    retry.append(document.createTextNode(t.retry)); panel.append(retry);
                }
            }
            return info;
        }
        function card(group) {
            const id = Number(group.level);
            const stats = summary && summary.levels && summary.levels[id];
            const shell = element('article','ll-level-card');
            shell.dataset.llLevelCard = String(id);
            const row = element('div','ll-level-row');
            const overview = element('div','ll-level-overview');
            const head = element('div','ll-level-card-head');
            const title = element('h2','ll-level-title');
            const open = button(t.browse + ' · ' + label(id),'ll-level-open',null,() => go('level',id));
            open.textContent = label(id);
            open.dataset.llFocusKey = 'browse-' + id;
            title.append(open);
            head.append(title);
            overview.append(head);
            row.append(overview);
            shell.append(row);
            const badges = element('div','ll-level-badges');
            if (stats && Number(stats.total) > 0 && Number(stats.mastered) === Number(stats.total)) {
                const check = element('span','ll-level-complete');
                check.setAttribute('role','img');
                check.setAttribute('aria-label',t.complete);
                check.title = t.complete;
                check.append(icon('check'));
                head.append(check);
            }
            const loadingStats = options.signedIn && !summary && !fetchError;
            if (stats) addLevelFilterButtons(id,stats,badges,overview);
            else if (loadingStats && group.categoryIds.size) {
                badges.setAttribute('aria-hidden','true');
                levelFilters.forEach(() => {
                    const placeholder = element('span','ll-level-badge-placeholder');
                    placeholder.append(element('span','ll-level-badge ll-level-sheen'));
                    badges.append(placeholder);
                });
            }
            if (badges.childElementCount) head.append(badges);
            const number = value => Number(value).toLocaleString(document.documentElement.lang || undefined);
            if (!loadingStats && (!stats || Number(stats.total) === 0)) {
                overview.append(element('span','ll-level-progress-count',t.lessons + ' · ' + number(group.categoryIds.size + group.contentIds.size)));
            }
            const actions = element('div','ll-level-activities');
            if (options.signedIn && !summary && !fetchError && !credentialsExpired) {
                if (group.nextId) actions.append(loadingPlaceholder('ll-level-activity'));
                if (group.practiceId) actions.append(loadingPlaceholder('ll-level-activity'));
            } else if (stats) {
                if (stats.next_category_id) actions.append(action(group,'learn',Number(stats.next_category_id)));
                if (stats.practice_category_id) actions.append(action(group,'practice',Number(stats.practice_category_id)));
            }
            if (!actions.childElementCount && (group.nextId || group.practiceId)) {
                actions.append(action(group,group.nextId ? 'learn' : 'practice',Number(group.nextId || group.practiceId),true));
            }
            if (actions.childElementCount) row.append(actions);
            if (group.categoryIds.size) {
                const progress = options.progressBar(stats,options.signedIn && !stats && !fetchError);
                progress.querySelector('summary').dataset.llFocusKey = 'progress-' + id;
                shell.append(progress);
            }
            shell.append(detail(group,stats));
            return shell;
        }
        function render() {
            if (state.view === 'home' && section.querySelector('.ll-level-detail-body.is-animating')) {
                renderAfterMotion = true;
                return;
            }
            renderAfterMotion = false;
            const focused = document.activeElement;
            // Updating progress must not eat partially typed interval edits.
            if (section.contains(focused) && focused.matches('input[type=number]') && state.view === 'home') {
                focused.addEventListener('blur',render,{once:true});
                return;
            }
            const key = section.contains(focused) && focused.dataset.llFocusKey;
            const openProgress = new Set(Array.from(section.querySelectorAll('.ll-wordset-card__progress[open]'),
                progress => progress.closest('[data-ll-level-card]').dataset.llLevelCard));
            const fragment = document.createDocumentFragment();
            if (state.view === 'home') {
                if (credentialsExpired || (options.signedIn && (!summary || fetchError))) {
                    const status = element('div',fetchError || credentialsExpired ? 'll-level-state' : 'screen-reader-text ll-level-loading-status');
                    status.setAttribute('role','status');
                    status.append(element('span','',credentialsExpired ? t.scopeExpired : (fetchError ? t.error : t.loading)));
                    if (fetchError) {
                        const label = credentialsExpired ? t.reload : t.retry;
                        const retry = button(label,'ll-level-retry','repeat',credentialsExpired ? () => location.reload() : refresh);
                        retry.append(element('span','',label)); status.append(retry);
                    }
                    fragment.append(status);
                }
                const grid = element('div','ll-level-grid');
                const review = summary && dailyReview(summary.review);
                if (review) fragment.append(review);
                else if (options.signedIn && !summary && !fetchError && !credentialsExpired) {
                    fragment.append(loadingPlaceholder('ll-level-review-placeholder'));
                }
                groups.forEach(group => {
                    const levelCard = card(group);
                    if (openProgress.has(levelCard.dataset.llLevelCard)) {
                        levelCard.querySelector('.ll-wordset-card__progress').open = true;
                    }
                    grid.append(levelCard);
                });
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
            const previews = section.querySelectorAll('[data-ll-thumb-categories]');
            if (observer) previews.forEach(btn => observer.observe(btn));
            else {
                unobservedThumbnails = Array.from(previews);
                loadNearbyThumbnails();
            }
            if (key) {
                const target = section.querySelector('[data-ll-focus-key="' + key + '"]');
                if (target) target.focus({preventScroll:true});
            }
        }
        const grid = root.querySelector('[data-ll-wordset-main-grid], .ll-wordset-grid');
        let paginationReady = false;
        let paginationNearby = false;
        let autoPageAt = null;
        let manualPage = null;
        function visibleCards() {
            return Array.from(grid.querySelectorAll('.ll-wordset-card:not([hidden])'));
        }
        function loadNextPage(event) {
            if (state.view !== 'level' || more.hidden) return;
            if (event && event.detail === 0 && grid) {
                manualPage = new Set(visibleCards().map(card => card.dataset.catId || 'lesson-' + card.dataset.lessonId));
            }
            state.limit += batch;
            writeState('replaceState');
            options.change('');
        }
        const more = button(t.more,'ll-level-more',null,loadNextPage);
        more.textContent = t.more;
        more.hidden = true;
        if (grid) grid.after(more);
        function loadNearbyPage() {
            if (state.view !== 'level' || document.hidden || !paginationReady || document.activeElement === more) return;
            if (autoPageAt !== null && Math.abs(window.scrollY - autoPageAt) < 32) return;
            const box = more.getBoundingClientRect();
            if (box.bottom >= 0 && box.top <= window.innerHeight + 180) {
                autoPageAt = window.scrollY;
                loadNextPage();
            }
        }
        const pageObserver = typeof window.IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
            paginationNearby = entries[entries.length-1].isIntersecting;
            if (paginationNearby) loadNearbyPage();
        },{rootMargin:'0px 0px 180px 0px'}) : null;
        let pageScrollFrame = 0;
        window.addEventListener('scroll',() => {
            if (!paginationReady || (pageObserver && !paginationNearby) || pageScrollFrame) return;
            pageScrollFrame = requestAnimationFrame(() => { pageScrollFrame = 0; loadNearbyPage(); });
        },{passive:true});
        function updateMore(count, rendered) {
            more.hidden = state.view !== 'level' || count <= state.limit;
            const ready = !more.hidden && rendered >= Math.min(state.limit,count);
            if (ready !== paginationReady && pageObserver) {
                if (ready) pageObserver.observe(more);
                else pageObserver.unobserve(more);
            }
            paginationReady = ready;
            if (manualPage && grid) {
                const next = visibleCards().find(card => !manualPage.has(card.dataset.catId || 'lesson-' + card.dataset.lessonId));
                const target = next && (next.querySelector('.ll-wordset-card__heading[href],a[href]') || next.querySelector('button:not(:disabled)'));
                if (target) { manualPage = null; target.focus(); }
            }
        }
        function inGroup(type,id) {
            return (type === 'content' ? contentLevels : categoryLevels).get(Number(id)) === state.level;
        }
        function badges(container) {
            container.querySelectorAll('.ll-wordset-card[data-cat-id],.ll-wordset-card[data-lesson-id]').forEach(node => {
                const level = node.hasAttribute('data-cat-id') ? categoryLevels.get(Number(node.dataset.catId)) : contentLevels.get(Number(node.dataset.lessonId));
                const existing = node.querySelector('[data-ll-level-badge]');
                if (!level) { existing?.remove(); return; }
                if (existing) return;
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
            updateMore,
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
            else section.querySelector('[data-ll-level-focus], .ll-level-open')?.focus({preventScroll:true});
        });
        function resumeSummary() {
            if (document.hidden || !options.signedIn || credentialsExpired || request || refreshTimer) return;
            if (refreshOnReturn || (dayResetAt && Date.now() >= dayResetAt) || Date.now() - lastSummaryRequestAt >= staleAfter) refresh();
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
        window.addEventListener('pageshow',event => {
            if (event.persisted) refreshOnReturn = true;
            resumeSummary();
        });
        $(document).on('lltools:flashcard-closed.llLevels',refreshSoon);
        $(document).on('lltools:progress-updated.llLevels',() => {
            if (!document.body.classList.contains('ll-tools-flashcard-open')) refreshSoon();
        });
        return instance;
    }

    window.LLWordsetLevels = {create};
})(jQuery);
