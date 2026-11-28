const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../../..');
const labels = Object.fromEntries([...fs.readFileSync(path.join(root, 'includes/lib/wordset-level-labels.php'), 'utf8')
  .matchAll(/'([a-zA-Z]+)' => __\('([^']*)'/g)].map(match => [match[1], match[2]]));
const categories = Array.from({ length: 16 }, (_, index) => ({
  id: index + 1, name: index === 14 ? 'Everyday Expressions' : 'Lesson ' + (index + 1),
  url: '/lesson/' + (index + 1), count: 10, learning_supported: true, preview: []
}));
const firstLevel = categories.slice(0, 14).map(item => item.id);
const quizModes = {learning:'Learn',practice:'Practice',listening:'Listen','self-check':'Self Check',gender:'Gender'};
const groups = [
  {level:1,categoryIds:firstLevel,contentIds:[],nextId:1,practiceId:1},
  {level:2,categoryIds:[15],contentIds:[],nextId:15,practiceId:15}
];
const base = 'https://wordset-levels.test';

async function paletteColor(locator, property) {
  return locator.evaluate((el, property) => {
    const sample = document.createElement('span');
    sample.style.color = 'var(' + property + ')';
    el.append(sample);
    const color = getComputedStyle(sample).color;
    sample.remove();
    return color;
  }, property);
}

async function mount(page, options = {}) {
  const catalog = options.categories || categories;
  const ids = catalog.map(item => item.id);
  const modeNames=options.modeLabels || quizModes;
  const reqs = [];
  const summaryRow = {total:10,mastered:3,working:2,new:5,due:2,hard:1,starred:3,last_studied_at:'2026-09-02 12:00:00',
    next_category_id:1,practice_category_id:2,due_category_id:3,days:30};
  const config = {view:'main',wordsetId:77,ajaxUrl:'/ajax',nonce:'',categories:catalog,isLoggedIn:!!options.signed,
    curriculumLevels:{levels:options.groups || groups,nonce:'nonce',token:'scope',i18n:labels},
    state:{category_ids:ids},gender:{enabled:true,options:['Masculine','Feminine']},
    i18n:{selectAll:'Select all',deselectAll:'Deselect all',repeatLabel:'Repeat',modeCategoryAria:'%1$s: %2$s',quizModesTitle:options.quizTitle || 'Quiz modes',
      analyticsMastered:labels.mastered,analyticsStudied:labels.working,analyticsNew:labels.unseen,
      modeLearning:modeNames.learning,modePractice:modeNames.practice,modeListening:modeNames.listening,modeSelfCheck:modeNames['self-check'],modeGender:modeNames.gender},
    modeLabels:modeNames};
  if (options.exerciseOnly) config.curriculumLevels.levels=groups.map(group => group.level === 2 ? {...group,nextId:0} : group);
  if (options.completionHistory) config.curriculumLevels.completionHistory=options.completionHistory;
  if (options.modeUi) config.modeUi=options.modeUi;
  if (options.previews) config.categories=catalog.map(cat=>({...cat,preview:(options.previews[cat.id] || []).map(url=>({type:'image',url}))}));
  if (options.catalogNonce) config.nonce='catalog';
  const files = {
    '/jquery.js': fs.readFileSync(require.resolve('jquery'),'utf8'),
    '/loader.js': fs.readFileSync(path.join(root,'js/flashcard-widget/loader.js'),'utf8'),
    '/levels.js': fs.readFileSync(path.join(root,'js/wordset-levels.js'),'utf8'),
    '/main.js': fs.readFileSync(path.join(root,'js/wordset-pages.js'),'utf8'),
    '/core.css': fs.readFileSync(path.join(root,'css/language-learner-tools.css'),'utf8'),
    '/catalog.css': fs.readFileSync(path.join(root,'css/wordset-pages.css'),'utf8'),
    '/levels.css': fs.readFileSync(path.join(root,'css/wordset-levels.css'),'utf8')
  };
  function existing(item, preview='') {
    const actions=options.previewActions ? '<div class="ll-wordset-card__quiz-actions"><button type="button" class="ll-wordset-card__quiz-btn" data-ll-wordset-category-mode data-cat-id="'+item.id+'" data-mode="listening" aria-label="Listen: '+item.name+'">Listen</button></div>' : '';
    const progress=options.previewActions ? '<details class="ll-wordset-card__progress"><summary class="ll-wordset-card__progress-toggle">Progress</summary></details>' : '';
    const media=preview ? '<a class="ll-wordset-card__lesson-link" aria-label="'+item.name+'" href="'+item.url+'"><div class="ll-wordset-card__preview has-images">'+preview+'</div></a>' : '';
    return '<article class="ll-wordset-card" data-cat-id="'+item.id+'"><div class="ll-wordset-card__top"><label class="ll-wordset-card__select"><input type="checkbox" data-ll-wordset-select value="'+item.id+'"></label><a class="ll-wordset-card__heading" href="'+item.url+'"><h2 class="ll-wordset-card__title">'+item.name+'</h2></a></div>'+media+progress+actions+'</article>';
  }
  if (options.sparse) {
    config.categories = config.categories.map(cat=>({...cat,preview_deferred:true,has_images:true}));
    config.lazyCards={enabled:true,nonce:'lazy',token:'cards',wordsetId:77,total:16,loaded:1};
  }
  if (options.flat) delete config.curriculumLevels;
  const shell = options.flat ? '' : '<section data-ll-wordset-levels><div class="ll-level-grid">' +
    config.curriculumLevels.levels.map(group => '<article class="ll-level-card"><h2 class="ll-level-title">Level ' + group.level + '</h2></article>').join('') + '</div></section>';
  const body = () => '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/core.css"><link rel="stylesheet" href="/catalog.css"><link rel="stylesheet" href="/levels.css">' +
    '<main class="ll-wordset-page" data-ll-wordset-page' + (options.flat ? '' : ' data-ll-level-initial') + '><div class="ll-wordset-grid-tools"><div class="ll-wordset-grid-search-tools"><div class="ll-wordset-progress-search ll-wordset-progress-search--wordset-page"><input class="ll-wordset-progress-search__input" aria-label="Search" placeholder="Search words or translations" type="search" data-ll-wordset-page-search><button class="ll-wordset-progress-search__clear" data-ll-wordset-page-search-clear hidden>Clear</button></div></div><button data-ll-wordset-select-all>Select all</button></div>' +
    shell + '<div data-ll-wordset-main-grid class="ll-wordset-grid">' + catalog.slice(0,options.sparse ? 1 : options.initialCards).map(item=>existing(item)).join('') +
    '</div><div data-ll-wordset-page-search-empty hidden>Empty</div></main><div id="ll-study-results-actions" style="display:none"><button id="ll-study-results-same-chunk"></button><button id="ll-study-results-different-chunk"></button><button id="ll-study-results-next-chunk"></button></div><script>window.llWordsetPageData='+JSON.stringify(config)+'</script>' +
    '<script src="/jquery.js"></script><script src="/loader.js"></script><script src="/levels.js"></script><script src="/main.js"></script>';
  await page.route(base+'/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const data = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      reqs.push(data);
      if (data.operation === 'completion_seen') {
        const history={},celebrate=[];
        for(const [key,value] of Object.entries(data)){
          const level=key.match(/^levels\[(\d+)\]$/)?.[1];
          if(!level) continue;
          const previous=options.completionHistory[level];
          if(previous===0 && value==='1') celebrate.push(Number(level));
          history[level]=options.completionHistory[level]=Math.max(previous || 0,Number(value));
        }
        return route.fulfill({json:{success:true,data:{history,celebrate}}});
      }
      if(data.action === 'll_tools_wordset_page_lazy_cards' && data.category_ids) {
        if (options.pauseThumbnails) await options.pauseThumbnails;
        if (options.holdPreview && data.category_ids.split(',').includes(options.holdPreview.id)) await options.holdPreview.until;
        if (options.thumbnailLookupFails) return route.fulfill({status:500,body:''});
        const ids=data.category_ids.split(',').map(Number);
        const html=ids.map(id=>{
          const urls=options.serverPreviews?.[id] || ['/thumbnail.svg','/thumbnail-two.svg'];
          return existing(catalog.find(cat=>cat.id===id),
            urls.map(src=>'<span class="ll-wordset-preview-item ll-wordset-preview-item--image"><img src="'+src+'" alt=""></span>').join(''));
        }).join('');
        return route.fulfill({json:{success:true,data:{category_ids:ids,html}}});
      }
      if (data.operation === 'summary') {
        if (options.holdSummary) await options.holdSummary;
        if (options.pauseSummary) await options.pauseSummary;
        if (options.summaryFailures) {
          const failure = options.summaryFailures[reqs.filter(r=>r.operation==='summary').length - 1];
          if (failure) return route.fulfill(failure);
        }
        if (options.prepare && reqs.filter(r => r.operation === 'summary').length === 1) {
          return route.fulfill({status:503,json:{success:false,data:{code:'ll_level_progress_preparing',retry_after:1}}});
        }
        const first=options.completed ? {...summaryRow,mastered:10,working:0,new:0,next_category_id:0} : summaryRow;
        if (options.overlap) first.practice_activity={category_ids:[1,2],word_count:3,label:'2 words in progress'};
        const second = {...summaryRow,days:30,due:0,practice_category_id:0,next_category_id:options.exerciseOnly ? 0 : 15};
        if (options.partialSummary) {
          if (!data.ready_levels) {
            return route.fulfill({json:{success:true,data:{preparing:true,ready_lessons:14,ready_levels:[1],levels:{1:first}}}});
          }
          await options.partialSummary;
        }
        if (options.secondSummaryModes) second.modes=options.secondSummaryModes;
        const totals=options.summary || {due:2,review:{reason:'due',count:2,due:2},levels:{1:first,2:second}};
        return route.fulfill({json:{success:true,data:totals}});
      }
      if (data.operation === 'interval') {
        summaryRow.days=Number(data.days);
        return route.fulfill({json:{success:true,data:{level:1,days:summaryRow.days}}});
      }
      if (data.operation === 'launch' && options.launchFailure) return route.fulfill(options.launchFailure);
      if ((data.operation === 'launch' || data.operation === 'quiz') && options.successful) {
        if (data.launch_mode === 'listening' && options.coldListening) {
          return route.fulfill({json:{success:true,data:{mode:'listening',stream_categories:true,category_ids:[2,1],session_word_ids:[]}}});
        }
        if (data.operation==='quiz' && options.quizWarming && data.launch_mode==='practice') {
          const attempt=reqs.filter(req=>req.operation==='quiz' && req.launch_mode==='practice').length;
          if(attempt<=2) return route.fulfill({status:attempt===1?429:503,headers:{'Retry-After':'1'},
            json:{success:false,data:{code:attempt===1?'cache_warming':'ll_level_progress_preparing'}}});
        }
        if (data.launch_mode === 'learn') return route.fulfill({json:{success:true,data:{mode:'learning',category_ids:[15],session_word_ids:[]}}});
        const plan = options.plan || (options.overlap && data.level === '1'
          ? {category_ids:[1,2],session_word_ids:[2002,1001],word_category_ids:{2002:2,1001:1}}
          : {category_ids:[2],session_word_ids:[2002],word_category_ids:{2002:2}});
        const chunks=[];
        for(let start=0;start<plan.session_word_ids.length;start+=12) {
          const word_ids=plan.session_word_ids.slice(start,start+12);
          const category_ids=[...new Set(word_ids.map(id=>plan.word_category_ids[id]))];
          const chunk={word_ids,category_ids};
          if(data.launch_mode==='gender') chunk.details={gender_level:2,gender_auto_continue:true};
          if(data.launch_mode==='learning') Object.assign(chunk,{target_word_ids:word_ids,compatibility_key:'no-image|audio->image'});
          chunks.push(chunk);
        }
        const mode=data.operation==='quiz' ? data.launch_mode : 'practice';
        const bounded=options.bounded || {...chunks[0],mode,chunks,chunk_count:chunks.length,matched_count:plan.session_word_ids.length,planned_count:plan.session_word_ids.length,truncated:false,
          ...(mode==='learning'?{expanded_count:0}:{})};
        return route.fulfill({json:{success:true,data:{mode,...plan,bounded}}});
      }
      if (data.action === 'll_get_words_by_category' || data.action === 'll_get_flashcard_payload_page') {
        if (options.holdCandidate && data.candidate_word_ids?.split(',')[0] === options.holdCandidate.id) {
          await options.holdCandidate.until;
        }
        const wordIds=options.plan && data.candidate_word_ids ? data.candidate_word_ids.split(',').map(Number) :
          data.category_id === '15' ? [1501,1502,1503,1504,1505,1506] : [1001,2002,9999];
        const rows=wordIds.map(id=>({id,title:'Word '+id,label:'Word '+id,translation:'Translation '+id,audio:'/sound.mp3',image:'/thumbnail.svg',
          grammatical_gender:id%2===0?'Masculine':'Feminine',part_of_speech:['noun']}));
        if (options.untracked) rows.splice(0,rows.length,{id:78493,is_prompt_card:true,answer_word_id:2002,progress_word_id:0});
        const payload=data.action === 'll_get_flashcard_payload_page' ? {rows,next_cursor:''} : rows;
        return route.fulfill({json:{success:true,data:payload}});
      }
      if (data.operation === 'launch') return route.fulfill({status:409,json:{success:false,data:{code:'ll_level_no_activity'}}});
      return route.fulfill({json:{success:true,data:{}}});
    }
    if (url.pathname.startsWith('/thumbnail')) {
      if (options.pauseImages) await options.pauseImages;
      if (url.pathname === '/thumbnail-broken') return route.fulfill({status:404,body:''});
      return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="17" fill="teal"/></svg>'});
    }
    if (url.pathname === '/jquery.js' && options.scriptGate) await options.scriptGate;
    if (files[url.pathname]) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:files[url.pathname]});
    if (options.badgeCookie && options.signed) {
      const cookies = (await route.request().headerValue('cookie') || '').split(/;\s*/);
      const pair = cookies.find(value=>value.startsWith(options.badgeCookie+'='));
      config.curriculumLevels.badgeLayout = {cookie:options.badgeCookie,slots:pair?.split('=')[1] || ''};
    }
    return route.fulfill({contentType:'text/html',body:url.pathname === '/' ? body() : '<h1>Lesson</h1>'});
  });
  await page.goto(base+'/', {waitUntil: options.scriptGate ? 'commit' : 'load'});
  if (!options.scriptGate && !options.noScripts && !options.flat) {
    await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','home');
  }
  return reqs;
}

test('slow quiz scripts paint curriculum rows before initialization and leave flat catalogs visible', async ({page}) => {
  for (const flat of [false, true]) {
    let release;
    const scriptGate = new Promise(resolve => { release = resolve; });
    try {
      await mount(page, {flat, signed:true, scriptGate});
      const grid = page.locator('[data-ll-wordset-main-grid]');
      await expect(page.getByRole('searchbox')).toBeVisible();
      if (flat) {
        await expect(grid.getByRole('link').first()).toBeVisible();
      } else {
        await expect(page.getByRole('heading', {name:'Level 1', exact:true})).toBeVisible();
        await expect(grid).toBeHidden();
        await expect(page.locator('[data-ll-wordset-levels] button')).toHaveCount(0);
        const row = await page.locator('.ll-level-card').first().boundingBox();
        const search = await page.locator('.ll-wordset-progress-search--wordset-page').boundingBox();
        expect(Math.abs(row.x - search.x)).toBeLessThanOrEqual(1);
        expect(Math.abs(row.width - search.width)).toBeLessThanOrEqual(1);
      }
    } finally { release(); }
    await page.waitForLoadState('load');
    if (!flat) await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','home');
    await page.unrouteAll({behavior:'wait'});
  }
});

test('without JavaScript both curriculum and flat wordsets expose regular lesson links', async ({browser}) => {
  const context = await browser.newContext({javaScriptEnabled:false});
  try {
    for (const flat of [false, true]) {
      const page = await context.newPage();
      await mount(page, {flat, noScripts:true});
      await expect(page.locator('[data-ll-wordset-levels]')).toBeHidden();
      const lesson = page.getByRole('link', {name:'Lesson 1', exact:true});
      await expect(lesson).toBeVisible();
      await lesson.click();
      await expect(page.getByRole('heading', {name:'Lesson', exact:true})).toBeVisible();
      await page.close();
    }
  } finally { await context.close(); }
});

test('mobile browse, bounded pages, global search, clear, and Back preserve their own scope', async ({page}) => {
  await page.setViewportSize({width:390,height:664});
  const errors=[];
  page.on('pageerror', e=>errors.push(e.message));
  const requests=await mount(page);
  await page.addStyleTag({content:'.ll-wordset-card {min-height:150px!important;}'});
  const grid=page.locator('[data-ll-wordset-main-grid]');
  const search=page.getByRole('searchbox');
  const home=page.locator('[data-ll-wordset-page]');
  await expect(grid).toBeHidden();
  await expect(page.locator('[aria-current="step"],.ll-level-card.is-current')).toHaveCount(0);
  const guestLearn=page.getByRole('button',{name:'Learn · Lesson 1',exact:true});
  await expect(guestLearn.locator('.ll-level-action-name')).toHaveText('Lesson 1');
  await expect(guestLearn.locator('.ll-level-activity-mode:first-child .ll-level-mode-icon')).toBeVisible();
  await expect(guestLearn.locator('.ll-level-mode-caption')).toHaveText('Learn');
  await expect(guestLearn.locator('.ll-level-thumbs')).toHaveCount(0);
  await expect(guestLearn.locator(':scope > .ll-level-glyph--forward:last-child')).toBeVisible();
  await page.getByRole('button',{name:'Browse lessons · Level 1'}).focus();
  await page.keyboard.press('Enter');
  await expect(grid.locator('article:visible')).toHaveCount(12);
  await page.getByRole('button',{name:'Show more lessons'}).scrollIntoViewIfNeeded();
  await expect(grid.locator('article:visible')).toHaveCount(14);
  await page.getByRole('button',{name:'Select all'}).click();
  await expect(grid.locator('article:visible input:checked')).toHaveCount(14);
  await expect(grid.locator('[data-cat-id="15"] input')).not.toBeChecked();
  await search.focus();
  await expect(home).toHaveAttribute('data-ll-level-view','search');
  await expect(grid.locator('article:visible')).toHaveCount(16);
  await expect(grid.locator('[data-cat-id="16"] [data-ll-level-badge]')).toHaveCount(0);
  await search.fill('Lesson 16');
  await expect(grid.locator('article:visible')).toHaveCount(1);
  await expect(grid.locator('article:visible')).toContainText('Lesson 16');
  await search.fill('Everyday');
  await expect(grid.locator('article:visible')).toHaveCount(1);
  await expect(grid.locator('article:visible [data-ll-level-badge]')).toHaveText('Level 2');
  await grid.locator('article:visible a').click();
  await expect(page).toHaveURL(/\/lesson\/15$/);
  await page.goBack();
  await expect(search).toHaveValue('Everyday');
  await expect(grid.locator('article:visible')).toHaveCount(1);
  await page.getByRole('button',{name:'Clear'}).click();
  await expect(search).toHaveValue('');
  await expect(grid.locator('article:visible')).toHaveCount(16);
  await page.getByRole('button',{name:'Back'}).click();
  await expect(home).toHaveAttribute('data-ll-level-view','level');
  await expect(grid.locator('article:visible')).toHaveCount(14);
  await page.getByRole('button',{name:'Back'}).click();
  await expect(grid).toBeHidden();
  await expect(page.getByRole('button',{name:/Additional lessons/})).toHaveCount(0);
  await expect(page.locator('[data-ll-level-card]')).toHaveCount(2);
  expect(requests.filter(r=>r.operation)).toEqual([]);
  expect(errors).toEqual([]);
});

test('a short grid loads the next page on continued scrolling without draining the level while stationary',async({page})=>{
  await page.setViewportSize({width:1200,height:980});
  const catalog=Array.from({length:60},(_,i)=>({...categories[0],id:i+1,name:'Lesson '+(i+1),url:'/lesson/'+(i+1)}));
  await mount(page,{categories:catalog,groups:[{level:1,categoryIds:catalog.map(cat=>cat.id),contentIds:[],nextId:1,practiceId:1}]});
  await page.addStyleTag({content:`
    .ll-wordset-page .ll-wordset-grid{display:grid;grid-template-columns:repeat(6,1fr)}
    .ll-wordset-page .ll-wordset-card{height:48px;min-height:0;overflow:hidden;padding:0}
    .ll-wordset-page:after{content:"";display:block;height:1300px}`});
  await page.getByRole('button',{name:'Browse lessons · Level 1'}).click();
  const cards=page.locator('[data-ll-wordset-main-grid] article:visible');
  await expect(cards).toHaveCount(24);
  await page.evaluate(()=>window.dispatchEvent(new Event('scroll')));
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await expect(cards).toHaveCount(24);
  await page.evaluate(()=>window.scrollTo(0,50));
  await expect(cards).toHaveCount(36);
  await page.evaluate(()=>window.scrollTo(0,100));
  await expect(cards).toHaveCount(48);
});

test('personalized cards retry preparing summaries, expose review scopes, and autosave intervals', async ({page})=>{
  const messages=[];
  page.on('pageerror', e=>messages.push(e.message));
  const reqs=await mount(page,{signed:true,prepare:true,catalogNonce:true});
  await expect(page.getByText('Loading progress…')).toBeVisible();
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  const first=page.locator('[data-ll-level-card="1"]');
  await expect(first.getByLabel('3 Learned, 2 In progress, 5 New',{exact:true})).toBeVisible();
  await expect(first.getByRole('img',{name:/Added since/})).toHaveCount(0);
  const localDue=first.locator('.ll-level-card-head').getByRole('button',{name:'Due for review (2) · Level 1'});
  await expect(localDue).toBeVisible();
  await expect(first).not.toContainText(/\b3\s*\/\s*10\s*learned\b/i);
  await expect(first.getByRole('button',{name:'Learn · Lesson 1'})).toBeVisible();
  await expect(page.locator('.ll-level-nudge,.is-suggested,.is-current')).toHaveCount(0);
  await expect(page.locator('.ll-level-review-all')).toHaveAccessibleName('Review now · Due for review (2) · Words left today · 2');
  await expect(page.locator('.ll-level-review-all .ll-level-thumbs')).toHaveCount(0);
  await first.getByLabel('Level details · Level 1',{exact:true}).click();
  const days=first.getByRole('spinbutton',{name:labels.interval, exact:true});
  await expect(days).toHaveValue('30');
  await expect(days).toHaveAccessibleDescription(labels.days+' '+labels.intervalHelp);
  await expect(first.getByText(labels.days,{exact:true})).toBeVisible();
  await days.fill('45');
  await days.press('Tab');
  await expect(first.getByRole('status')).toHaveText('Saved');
  await expect(days).toHaveValue('45');
  await expect.poll(()=>reqs.filter(r=>r.operation==='summary').length).toBeGreaterThan(2);
  await expect(page.locator('.ll-level-nudge')).toHaveCount(0);
  await page.locator('.ll-level-review-all').click();
  await localDue.click();
  await expect(localDue).toHaveAttribute('aria-expanded','true');
  await first.locator('.ll-level-overview').getByRole('button',{name:'Practice · Due for review · Level 1'}).click();
  await first.getByRole('button',{name:'Practice · Lesson 2'}).click();
  await expect.poll(()=>reqs.filter(r=>r.operation==='launch').map(r=>[r.level,r.launch_mode])).toEqual([['0','review'],['1','due'],['1','practice']]);
  await expect(page.getByText('Could not start this activity.')).toBeVisible();
  expect(reqs.filter(r=>r.operation==='interval').map(r=>[r.level,r.days])).toEqual([['1','45']]);
  expect(reqs.filter(r=>r.action==='ll_user_study_recommendation')).toHaveLength(0);
  expect(messages).toEqual([]);
});

test('keyboard pagination focuses the newly visible lessons and a scoped sparse browse hydrates only matching IDs',async({page})=>{
  await page.setViewportSize({width:390,height:664});
  const reqs=await mount(page,{sparse:true});
  await page.addStyleTag({content:'.ll-wordset-card {min-height:150px!important;}'});
  await expect(page.locator('[data-ll-level-card="2"] .ll-level-activity img')).toHaveCount(2);
  reqs.length=0;
  await page.getByRole('button',{name:'Browse lessons · Level 1'}).click();
  const grid=page.locator('[data-ll-wordset-main-grid]');
  await expect(grid.locator('article:visible')).toHaveCount(12);
  const more=page.getByRole('button',{name:'Show more lessons'});
  await more.focus();
  await page.keyboard.press('Enter');
  await expect(grid.locator('article:visible')).toHaveCount(14);
  await expect(grid.locator('[data-cat-id="13"] :focus')).toHaveCount(1);
  await expect(more).toBeHidden();
  const hydration=reqs.filter(r=>r.action==='ll_tools_wordset_page_lazy_cards');
  expect(hydration.length).toBeGreaterThan(0);
  expect(hydration.every(r=>r.category_ids && r.category_ids.split(',').every(id=>Number(id)<=14))).toBe(true);
});

test('returning learner sees completed levels and receives only visible CTA thumbnails',async({page})=>{
  const reqs=await mount(page,{signed:true,sparse:true,completed:true});
  await expect(page.locator('[aria-current="step"],.ll-level-card.is-current')).toHaveCount(0);
  await expect(page.getByRole('img',{name:'Level mastered'})).toBeVisible();
  await expect(page.locator('[data-ll-level-card="2"] .ll-level-activity img:visible')).toHaveCount(2);
  const lookups=reqs.filter(r=>r.action==='ll_tools_wordset_page_lazy_cards');
  expect(lookups.length).toBeGreaterThan(0);
  expect(lookups.every(r=>r.category_ids && r.category_ids.split(',').length<=8 && r.preview_limit==='2')).toBe(true);
});

for (const width of [390, 1240]) {
  test('completed level keeps a green header above a white expanded menu at '+width+'px',async({page})=>{
    await page.setViewportSize({width,height:850});
    await page.emulateMedia({reducedMotion:'reduce'});
    await mount(page,{signed:true,completed:true});
    const card=page.locator('[data-ll-level-card="1"].is-complete');
    const menu=card.locator(':scope > .ll-level-details'),toggle=menu.locator('summary.ll-level-details-toggle');
    const green=await paletteColor(card,'--ll-wp-pill-green'),white=await paletteColor(card,'--ll-wp-card');
    await expect(card).toHaveCSS('background-color',green);
    const closed=await card.boundingBox();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded','true');
    await expect(menu).toHaveCSS('background-color',white);
    await expect(card).toHaveCSS('background-color',green);
    const innerEdges=await menu.evaluate(el=>{
      const card=el.parentElement,outer=card.getBoundingClientRect(),inner=el.getBoundingClientRect();
      return [inner.left-outer.left-card.clientLeft,outer.right-inner.right-card.clientLeft,
        outer.bottom-inner.bottom-parseFloat(getComputedStyle(card).borderBottomWidth)];
    });
    innerEdges.forEach(gap=>expect(Math.abs(gap)).toBeLessThan(1));
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded','false');
    await expect(menu).toHaveCSS('background-color','rgba(0, 0, 0, 0)');
    await expect(card).toHaveCSS('background-color',green);
    expect((await card.boundingBox()).height).toBeCloseTo(closed.height,0);
  });
}

test('only newly completed levels celebrate, retain their actions and remember completion on return',async({page,context})=>{
  const completionHistory={},oldLevel={total:4,mastered:4,working:0,new:0,practice_category_id:1,days:30};
  const stats={...oldLevel,total:8,mastered:7,working:1,practice_category_id:15};
  const options={signed:true,completionHistory,summary:{levels:{1:oldLevel,2:stats}}};
  await mount(page,options);
  const oldCard=page.locator('[data-ll-level-card="1"]'),card=page.locator('[data-ll-level-card="2"]');
  await expect(oldCard).toHaveClass(/is-complete/);
  await expect(oldCard).toHaveCSS('background-color',await paletteColor(oldCard,'--ll-wp-pill-green'));
  await expect(oldCard.locator('.ll-level-complete + .ll-level-title')).toBeVisible();
  await expect(oldCard.locator('.ll-level-open')).toHaveAccessibleDescription('Level mastered');
  await expect(oldCard.locator(':scope > .ll-wordset-card__progress')).toHaveCount(0);
  await expect(oldCard.locator('.ll-level-activity')).toBeVisible();
  await expect.poll(()=>completionHistory).toEqual({1:1,2:0});
  await expect(page.locator('.is-celebrating')).toHaveCount(0);
  // Keep the unfinished bar open and focused when the quiz refresh reports the last learned word.
  await card.locator(':scope > .ll-wordset-card__progress summary').click();
  await page.evaluate(()=>{
    window.awards=[];
    document.addEventListener('animationstart',event=>{
      if(event.animationName==='ll-level-completion-sweep') window.awards.push(event.target.dataset.llLevelCard);
    });
  });
  stats.mastered=8;stats.working=0;
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  await expect.poll(()=>page.evaluate(()=>window.awards)).toEqual(['2']);
  await expect(card.locator('.ll-level-open')).toBeFocused();
  await expect(card.locator(':scope > .ll-wordset-card__progress')).toHaveCount(0);
  await card.locator('.ll-level-details-toggle').click();
  await expect(card.locator('.ll-level-fact dd.ll-level-metric-mastered')).toContainText('8');
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  await expect(card).not.toHaveClass(/is-celebrating/);
  expect(await page.evaluate(()=>window.awards)).toEqual(['2']);
  const returning=await context.newPage();
  const requests=await mount(returning,options);
  await expect(returning.locator('.ll-level-card.is-complete')).toHaveCount(2);
  expect(requests.filter(request=>request.operation==='completion_seen')).toHaveLength(0);
  await expect(returning.locator('.is-celebrating')).toHaveCount(0);
  stats.total=9; stats.new=1;
  await returning.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  const updated=returning.locator('[data-ll-level-card="2"]');
  await expect(updated).not.toHaveClass(/is-complete/);
  await expect(updated.locator(':scope > .ll-wordset-card__progress')).toBeVisible();
  expect(completionHistory[2]).toBe(1);
});

test('an earned celebration below the fold waits until the card is visible',async({page})=>{
  await page.setViewportSize({width:390,height:300});
  const history={1:0,2:0};
  const reqs=await mount(page,{signed:true,completionHistory:history,summary:{
    levels:{1:{total:3,mastered:1,next_category_id:1,practice_category_id:2},2:{total:3,mastered:3,practice_category_id:15}}
  }});
  const card=page.locator('[data-ll-level-card="2"]');
  await expect(card).toHaveClass(/is-complete/);
  await expect(card).not.toBeInViewport();
  expect(reqs.filter(r=>r.operation==='completion_seen')).toHaveLength(0);
  await card.scrollIntoViewIfNeeded();
  await expect.poll(()=>history[2]).toBe(1);
  await expect(page.locator('[data-ll-level-notice]')).toContainText('Level 2');
});

test('completed levels respect reduced motion, and empty or missing totals cannot earn a celebration',async({page})=>{
  await page.emulateMedia({reducedMotion:'reduce'});
  const history={1:0};
  await mount(page,{signed:true,completionHistory:history,summary:{levels:{1:{total:2,mastered:2},2:{total:0,mastered:0}}}});
  await expect(page.locator('.ll-level-card.is-complete')).toHaveCount(1);
  await expect(page.locator('.ll-level-card.is-celebrating')).toHaveCount(0);
  await expect.poll(()=>history).toEqual({1:1});
  await expect(page.locator('[data-ll-level-notice]')).toHaveText('Level 1 · Level mastered');
});

test('loading lesson thumbnails and refreshing level stats preserve the learner’s open lesson progress and focus',async({page})=>{
  await page.emulateMedia({reducedMotion:'reduce'});
  let release;
  const until=new Promise(resolve=>{ release=resolve; });
  const stats={total:6,mastered:1,working:2,new:3,next_category_id:7,practice_category_id:7};
  const catalog=categories.map(cat=>({...cat,count:6,mastered_words:1,studied_words:3,new_words:3}));
  const requests=await mount(page,{signed:true,sparse:true,previewActions:true,categories:catalog,
    groups:[{level:1,categoryIds:[3,4,7],contentIds:[],nextId:7,practiceId:7}],
    summary:{levels:{1:stats}},holdPreview:{id:'3',until}});
  const card=page.locator('[data-ll-level-card="1"]');
  const levelBar=card.locator(':scope > .ll-wordset-card__progress');
  const lesson=card.locator('[data-ll-level-preview-category="3"]');
  const lessonBar=lesson.locator('.ll-wordset-card__progress');
  const toggle=lessonBar.locator('summary');
  try {
    await expect(levelBar.locator('summary')).toHaveAccessibleName('1 Learned, 2 In progress, 3 New');
    await card.locator('.ll-level-details-toggle').press('Enter');
    await expect.poll(()=>requests.some(r=>r.category_ids?.split(',').includes('3'))).toBe(true);
    await toggle.press('Enter');
    await expect(lessonBar).toHaveJSProperty('open',true);
  } finally { release(); }
  await expect(lesson.locator('img').first()).toBeVisible();
  await expect(lessonBar).toHaveJSProperty('open',true);
  await expect(toggle).toBeFocused();
  stats.mastered=2; stats.new=2;
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  await expect(levelBar.locator('summary')).toHaveAccessibleName('2 Learned, 2 In progress, 2 New');
  await expect(levelBar).toHaveJSProperty('open',false);
  await expect(lessonBar).toHaveJSProperty('open',true);
  await expect(toggle).toBeFocused();
});

for (const width of [390,1240]) {
  test('expanded level previews link and launch its lessons at '+width+'px',async({page})=>{
    await page.setViewportSize({width,height:850});
    await page.emulateMedia({reducedMotion:'reduce'});
    await instrumentQuiz(page);
    const catalog=categories.map(cat=>({...cat,listening_supported:true}));
    const scoped=[3,4,5,6,7,8];
    const requests=await mount(page,{sparse:true,previewActions:true,categories:catalog,groups:[
      {level:1,categoryIds:scoped,contentIds:[],nextId:7,practiceId:7,modes:['listening']},
      {level:2,categoryIds:[15],contentIds:[],nextId:15,practiceId:15,modes:['listening']}
    ]});
    const card=page.locator('[data-ll-level-card="1"]');
    const preview=card.locator('.ll-level-lessons');
    const lessons=preview.locator('.ll-level-lesson-previews .ll-wordset-card');
    const seeAll=preview.getByRole('button',{name:'See all lessons · Level 1'});
    const fetches=()=>requests.filter(req=>req.action==='ll_tools_wordset_page_lazy_cards');
    const previewIds=()=>fetches().flatMap(req=>String(req.category_ids).split(',').map(Number)).filter(id=>id===3 || id===4);
    await expect(card.locator('.ll-level-activity img').first()).toBeVisible();
    expect(previewIds()).toEqual([]);
    await expect(preview).toBeHidden();
    await card.locator('.ll-level-details-toggle').press('Enter');
    await expect(preview.getByRole('heading',{name:'Lessons in this level'})).toBeVisible();
    await expect(lessons).toHaveCount(2);
    await expect(lessons.getByRole('checkbox')).toHaveCount(0);
    const links=lessons.locator('a.ll-wordset-card__heading');
    await expect(links).toHaveText(['Lesson 3','Lesson 4']);
    await expect(links.first()).toHaveAttribute('href','/lesson/3');
    await expect(links.nth(1)).toHaveAttribute('href','/lesson/4');
    await expect(lessons.first().locator('img').first()).toBeVisible();
    expect([...new Set(previewIds())].sort()).toEqual([3,4]);
    expect(fetches().every(req=>req.preview_limit==='2' && req.category_ids)).toBe(true);
    expect(requests.filter(req=>req.action==='ll_get_words_by_category' || req.action==='ll_get_flashcard_payload_page')).toEqual([]);
    await expect(seeAll).toBeVisible();
    await expect(seeAll.locator('svg').first()).toBeVisible();
    const modes=await card.locator('.ll-level-quiz-section').boundingBox();
    const details=await card.locator('.ll-level-detail-header').boundingBox();
    const section=await preview.boundingBox();
    expect(modes.y+modes.height).toBeLessThanOrEqual(section.y+1);
    expect(section.y+section.height).toBeLessThanOrEqual(details.y+1);
    const first=await lessons.first().boundingBox(), second=await lessons.nth(1).boundingBox(), browse=await seeAll.boundingBox();
    if(width>=1000) {
      expect(Math.abs(first.y-second.y)).toBeLessThan(2);
      expect(browse.x).toBeGreaterThanOrEqual(second.x+second.width);
      expect(browse.y+browse.height).toBeGreaterThan(second.y);
      expect(browse.y).toBeLessThan(second.y+second.height);
    } else {
      expect(browse.y).toBeGreaterThanOrEqual(Math.max(first.y+first.height,second.y+second.height)-1);
      expect(browse.x+browse.width).toBeLessThanOrEqual(width);
    }
    await lessons.first().locator('[data-ll-wordset-category-mode][data-mode="listening"]').click();
    await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.map(q=>[q.mode,q.categories]))).toEqual([['listening',['Lesson 3']]]);
    await seeAll.click();
    await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','level');
    const grid=page.locator('[data-ll-wordset-main-grid]');
    await expect(grid.locator('article:visible')).toHaveCount(scoped.length);
    expect(await grid.locator('article:visible').evaluateAll(items=>items.map(el=>Number(el.dataset.catId)))).toEqual(scoped);
    await page.getByRole('button',{name:'Back',exact:true}).click();
    if (!await seeAll.isVisible()) await card.locator('.ll-level-details-toggle').press('Enter');
    await links.first().click();
    await expect(page).toHaveURL(/\/lesson\/3$/);
  });
}

for (const width of [360,1240]) {
  test('level thumbnails and status flags reserve their space on delayed responses at '+width+'px',async({page})=>{
    await page.setViewportSize({width,height:820});
    await page.emulateMedia({reducedMotion:width===360 ? 'reduce' : 'no-preference'});
    let showSummary, showUrls, showImages;
    const pauseSummary=new Promise(resolve=>{showSummary=resolve;});
    const pauseThumbnails=new Promise(resolve=>{showUrls=resolve;});
    const pauseImages=new Promise(resolve=>{showImages=resolve;});
    const row={total:10,mastered:4,working:3,new:3,due:2,hard:1,starred:3,next_category_id:1,practice_category_id:2};
    await mount(page,{signed:true,sparse:true,pauseSummary,pauseThumbnails,pauseImages,
      summary:{levels:{1:row,2:{...row,due:0,hard:0,starred:0,next_category_id:15}}}});
    const first=page.locator('[data-ll-level-card="1"]');
    const badges=first.locator('.ll-level-badges');
    const placeholders=badges.locator('.ll-level-sheen');
    await expect(placeholders).toHaveCount(1);
    await expect(badges).toHaveAttribute('aria-hidden','true');
    await expect(first.locator('[data-ll-level-filter]')).toHaveCount(0);
    const animation=width===360 ? 'none' : 'll-wordset-skeleton-shimmer';
    await expect(placeholders.first()).toHaveCSS('animation-name',animation);
    const heightBefore=(await badges.boundingBox()).height;
    showSummary();
    await expect(first.locator('[data-ll-level-filter]')).toHaveCount(3);
    await expect(placeholders).toHaveCount(0);
    expect((await badges.boundingBox()).height).toBe(heightBefore);
    await expect(page.locator('[data-ll-level-card="2"] .ll-level-badges')).toHaveCount(0);
    const learn=first.locator('[data-ll-level-action="learn"]');
    const loading=learn.locator('.ll-level-thumb-slot.ll-level-sheen:visible');
    await expect(loading).toHaveCount(2);
    await expect(loading.first()).toHaveCSS('animation-name',animation);
    const dimensions=()=>learn.evaluate(el=>{
      const label=el.querySelector('.ll-level-action-name').getBoundingClientRect();
      const button=el.getBoundingClientRect();
      return [label.left-button.left,label.top-button.top,button.height];
    });
    const before=await dimensions();
    showUrls();
    await expect(learn.locator('img.ll-level-thumb')).toHaveCount(2);
    await expect(loading).toHaveCount(2);
    await expect(learn.locator('img').first()).toHaveCSS('opacity','0');
    showImages();
    await expect(loading).toHaveCount(0);
    await expect(learn.locator('img').first()).toHaveCSS('opacity','1');
    expect(await dimensions()).toEqual(before);
  });
}

test('a returning learner sees the last rendered badge count for each level throughout partial loading',async({page})=>{
  const row = {total:10,mastered:2,working:8,due:0,hard:2,starred:1,practice_category_id:2};
  const options = {signed:true,badgeCookie:'ll_level_badges_fixture',groups:[...groups,{...groups[1],level:3}],
    summary:{levels:{1:row,2:{...row,hard:0,starred:0},3:{...row,due:1}}}};
  const saved = async ()=>(await page.context().cookies(base)).find(cookie=>cookie.name===options.badgeCookie)?.value;
  await mount(page,options);
  await expect.poll(saved).toBe('203');

  let releaseInitial,releaseRest;
  options.pauseSummary=new Promise(resolve=>{releaseInitial=resolve;});
  options.partialSummary=new Promise(resolve=>{releaseRest=resolve;});
  options.summary.levels[2].starred=1;
  const chips=(level,selector)=>page.locator('[data-ll-level-card="'+level+'"] '+selector);
  try {
    await page.reload();
    for(const [level,count] of [[1,2],[2,0],[3,3]]) {
      await expect(chips(level,'.ll-level-badge-placeholder')).toHaveCount(count);
    }
    releaseInitial();
    await expect(chips(1,'[data-ll-level-filter]')).toHaveCount(3);
    await expect(chips(3,'.ll-level-badge-placeholder')).toHaveCount(3);
    await expect.poll(saved).toBe('303');
  } finally { releaseInitial(); releaseRest(); }
  await expect(chips(2,'[data-ll-level-filter]')).toHaveCount(1);
  await expect.poll(saved).toBe('213');
});

test('empty previews, failed lookups and broken images leave no loading sheen or broken image',async({page})=>{
  for (const thumbnailLookupFails of [false,true]) {
    const view=await page.context().newPage();
    await mount(view,{sparse:true,thumbnailLookupFails,
      serverPreviews:{1:[],15:['/thumbnail-broken']},previews:{15:['/thumbnail-broken']}});
    const slots=view.locator('.ll-level-thumb-slot');
    await expect(slots.first()).toBeVisible();
    await expect(view.locator('.ll-level-thumb-slot.ll-level-sheen')).toHaveCount(0);
    await expect(view.locator('img.ll-level-thumb')).toHaveCount(0);
    await expect(view.locator('.ll-level-badge-placeholder')).toHaveCount(0);
    await view.close();
  }
});

test('scrolling to later levels resolves thumbnail slots without IntersectionObserver',async({page})=>{
  await page.addInitScript(()=>{delete window.IntersectionObserver;});
  await page.setViewportSize({width:360,height:650});
  const manyLevels=categories.map(item=>({level:item.id,categoryIds:[item.id],contentIds:[],nextId:item.id}));
  const reqs=await mount(page,{groups:manyLevels,sparse:true});
  const last=page.locator('[data-ll-level-card="16"]');
  const lookups=()=>reqs.filter(req=>req.action==='ll_tools_wordset_page_lazy_cards' && req.category_ids)
    .flatMap(req=>req.category_ids.split(',').map(Number));
  await expect.poll(lookups).toContain(1);
  expect(lookups()).not.toContain(16);
  await last.scrollIntoViewIfNeeded();
  await expect.poll(lookups).toContain(16);
  await expect(last.locator('.ll-level-thumb')).toHaveCount(2);
  await expect(last.locator('.ll-level-thumb-slot.ll-level-sheen:visible')).toHaveCount(0);
});

test('photos stay distinct, represent mixed Practice and use one image when only one exists',async({page})=>{
  const previews={1:['/thumbnail-family-150x150.webp'],2:['/thumbnail-cat.webp','/thumbnail-dog.webp'],15:['/thumbnail-bird.webp']};
  const serverPreviews={1:['/thumbnail-family-300x300.webp','/thumbnail-house.webp'],15:['/thumbnail-bird.webp']};
  await mount(page,{signed:true,overlap:true,sparse:true,previews,serverPreviews});
  const practice=page.getByRole('button',{name:'Practice · 2 words in progress'}).locator('.ll-level-thumb');
  await expect(practice).toHaveCount(2);
  await expect(practice.nth(0)).toHaveAttribute('src','/thumbnail-cat.webp');
  await expect(practice.nth(1)).toHaveAttribute('src','/thumbnail-family-150x150.webp');
  const learn=page.getByRole('button',{name:'Learn · Lesson 1'}).locator('.ll-level-thumb');
  await expect(learn).toHaveCount(2);
  await expect(learn.nth(1)).toHaveAttribute('src','/thumbnail-house.webp');
  const mixed=page.locator('[data-ll-level-card="1"] [data-ll-level-action="practice"]');
  await expect(mixed.locator('.ll-level-action-name')).toHaveText('2 words in progress');
  expect((await learn.first().boundingBox()).width).toBeGreaterThanOrEqual(48);
  await expect(page.getByRole('button',{name:'Learn · Everyday Expressions'}).locator('.ll-level-thumb')).toHaveCount(1);
  for (const width of [1240,390,320]) {
    await page.setViewportSize({width,height:820});
    for (const name of ['Learn · Lesson 1','Practice · 2 words in progress']) {
      const button=page.getByRole('button',{name,exact:true});
      const mode=button.locator(':scope > .ll-level-activity-mode:first-child .ll-level-mode-icon');
      const caption=button.locator('.ll-level-mode-caption');
      await expect(caption).toHaveText(name.split(' · ')[0]);
      await expect(caption).toBeVisible();
      const arrow=button.locator(':scope > .ll-level-glyph--forward:last-child');
      await expect(mode).toHaveAttribute('aria-hidden','true');
      await expect(arrow).toHaveAttribute('aria-hidden','true');
      await expect(arrow).toHaveAttribute('focusable','false');
      const firstPhoto=button.locator('.ll-level-thumb:visible').first();
      const lastPhoto=button.locator('.ll-level-thumb:visible').last();
      const [icon,captionBox,first,last,label,go,control]=await Promise.all(
        [mode,caption,firstPhoto,lastPhoto,button.locator('.ll-level-action-name'),arrow,button].map(node=>node.boundingBox())
      );
      expect(icon.x).toBeGreaterThanOrEqual(control.x);
      expect(first.x).toBeGreaterThan(icon.x+icon.width);
      expect(icon.width).toBeLessThan(first.width);
      expect(captionBox.y).toBeGreaterThanOrEqual(icon.y+icon.height);
      expect(Math.abs(captionBox.x+captionBox.width/2-icon.x-icon.width/2)).toBeLessThan(1);
      expect(label.x).toBeGreaterThan(last.x+last.width);
      expect(go.x).toBeGreaterThan(label.x+label.width);
      expect(go.x+go.width).toBeLessThanOrEqual(control.x+control.width);
    }
  }
  await page.addStyleTag({content:'.ll-wordset-page .ll-level-activity {width:190px}'});
  await expect(practice.nth(0)).toBeVisible();
  await expect(practice.nth(1)).toBeHidden();
  await page.locator('html').evaluate(el=>el.dir='rtl');
  const forward=mixed.locator('.ll-level-glyph--forward');
  await expect(forward).toHaveCSS('transform','matrix(-1, 0, 0, 1, 0, 0)');
  const icon=await mixed.locator('.ll-level-mode-icon').boundingBox();
  const picture=await practice.first().boundingBox();
  const label=await mixed.locator('.ll-level-action-name').boundingBox();
  const arrow=await forward.boundingBox();
  expect(icon.x).toBeGreaterThan(picture.x+picture.width);
  expect(label.x).toBeGreaterThan(arrow.x+arrow.width);
});

test('daily review separates the complete due backlog from the next round and follows the fallback reason',async({page})=>{
  const row={total:900,mastered:800,working:80,new:20,studied:880,due:681,next_category_id:1,practice_category_id:2,due_category_id:3,days:90,
    due_activity:{session_word_ids:Array.from({length:12},(_,i)=>i+1)}};
  const summary={due:681,review:{reason:'due',count:64,due:681},levels:{1:row,2:{...row,due:0,next_category_id:15,practice_category_id:15,due_category_id:0}}};
  const reqs=await mount(page,{signed:true,summary});
  const review=page.locator('.ll-level-review-all');
  await expect(review).toHaveAccessibleName('Review now · Due for review (681) · Words left today · 64');
  await expect(review.locator('img')).toHaveCount(0);
  await expect(review).toHaveClass(/ll-level-metric-older/);
  async function checkDailyColors(palette) {
    await page.mouse.move(0,0);
    await review.evaluate(el => el.blur());
    const tint = await paletteColor(review,palette);
    const cardColor = await paletteColor(review,'--ll-wp-card');
    expect(cardColor).not.toBe(tint);
    await expect(review).toHaveCSS('background-color',tint);
    await review.hover();
    await expect(review).toHaveCSS('background-color',cardColor);
    await page.mouse.move(0,0);
    await page.keyboard.press('ArrowRight');
    await review.focus();
    expect(await review.evaluate(el => el.matches(':focus-visible'))).toBe(true);
    await expect(review).toHaveCSS('background-color',cardColor);
    await expect(review).toHaveCSS('outline-style','solid');
  }
  await checkDailyColors('--ll-wp-pill-purple');
  await expect(page.locator('.is-suggested')).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Practice · Lesson 2'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Learn · Lesson 1'})).toBeVisible();
  await page.setViewportSize({width:320,height:640});
  const first=page.locator('[data-ll-level-card="1"]');
  const badge=first.locator('.ll-level-card-head [data-ll-level-filter="due"]');
  const panel=page.locator('#'+await badge.getAttribute('aria-controls'));
  await expect(panel).toBeHidden();
  await badge.focus();
  await page.keyboard.press('Enter');
  await expect(badge).toHaveAttribute('aria-expanded','true');
  await expect(panel.getByText(labels.dueHelp)).toBeVisible();
  await expect(panel.getByText('Words this round · 12')).toBeVisible();
  await page.keyboard.press('Tab');
  const launch=panel.getByRole('button',{name:'Practice · Due for review · Level 1'});
  await expect(launch).toBeFocused();
  await expect(launch).toHaveText('Practice');
  await expect(launch.locator('.ll-level-mode-icon')).toHaveAttribute('data-emoji','❓');
  expect(await panel.evaluate(el=>el.getBoundingClientRect().right<=window.innerWidth)).toBe(true);
  await badge.focus();
  await page.keyboard.press('Space');
  await expect(panel).toBeHidden();
  expect(reqs.filter(r=>r.operation==='launch')).toHaveLength(0);
  await expect(review.getByText('Due for review (681)')).toBeVisible();
  await expect(review.locator('.ll-level-round-size strong')).toHaveText('64');
  await expect(review.locator('.ll-level-glyph--forward')).toBeVisible();
  expect(await review.evaluate(btn=>btn.getBoundingClientRect().right)).toBeLessThanOrEqual(320);
  await review.click();
  await expect.poll(()=>reqs.filter(r=>r.operation==='launch').map(r=>[r.level,r.launch_mode])).toEqual([['0','review']]);
  await expect(page.getByText(labels.launchError)).toBeVisible();
  for (const [reason,status,text,palette] of [['working','studied','In progress','--ll-wp-pill-blue'],['hard','hard','Hard','--ll-wp-pill-red'],
    ['starred','starred','Starred','--ll-wp-pill-gold'],['least_recent','practice','Least recently studied','--ll-wp-bg-accent']]) {
    summary.due=0;
    summary.review={reason,count:5,due:0};
    await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
    await expect(review).toHaveAccessibleName('Practice · '+text+' · Words left today · 5');
    await expect(review).toHaveClass(new RegExp('ll-level-metric-'+status));
    await expect(review.locator('.ll-level-daily-icon')).toBeVisible();
    await checkDailyColors(palette);
  }
  summary.review={reason:'',count:0,due:0};
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  await expect(review).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Learn · Lesson 1'})).toBeVisible();
});

test('desktop and phone each show one level per row and keep mode icons, statuses and native details accessible',async({page})=>{
  await page.setViewportSize({width:1240,height:850});
  await page.emulateMedia({reducedMotion:'reduce'});
  const customSvg='<svg viewBox="0 0 16 16" fill="#3175af"><circle cx="8" cy="8" r="6"/></svg>';
  await mount(page,{signed:true,modeUi:{learning:{svg:customSvg},practice:{icon:'❓'}}});
  const rows=page.locator('[data-ll-level-card]');
  const first=rows.first();
  await expect(first.getByRole('button',{name:'Practice · Lesson 2'})).toBeVisible();
  await expect(first.locator('[data-ll-level-action="learn"] .ll-level-mode-icon svg')).toHaveCSS('fill','rgb(49, 117, 175)');
  await expect(first.locator('[data-ll-level-action="practice"] .ll-level-mode-icon')).toHaveAttribute('data-emoji','❓');
  await expect(first.locator('.ll-level-nudge,.is-suggested')).toHaveCount(0);
  await expect(page.getByText('Recommended level',{exact:true})).toHaveCount(0);
  const badges=first.locator('.ll-level-card-head .ll-level-badge');
  await expect(badges).toHaveCount(3);
  await page.addStyleTag({content:'.ll-wordset-page {--ll-wp-main-shell-max-width:860px;--ll-wordset-grid-right-offset:13px!important;}'});
  for(const width of [1240,390,320]) {
    await page.setViewportSize({width,height:820});
    const upper=await rows.nth(0).boundingBox(), lower=await rows.nth(1).boundingBox();
    expect(upper.x).toBe(lower.x);
    expect(upper.width).toBe(lower.width);
    expect(lower.y-upper.y-upper.height).toBeGreaterThanOrEqual(16);
    await expect(first).toHaveCSS('border-top-width','2px');
    expect(upper.x+upper.width).toBeLessThanOrEqual(width);
    const search=await page.getByRole('searchbox').boundingBox();
    expect(Math.abs(upper.x-search.x)).toBeLessThanOrEqual(1);
    expect(Math.abs(upper.x+upper.width-search.x-search.width)).toBeLessThanOrEqual(1);
    const bar=first.locator('.ll-wordset-card__progress-track');
    const track=await bar.boundingBox();
    const detailToggle=await first.getByLabel('Level details · Level 1',{exact:true}).boundingBox();
    const segments=bar.locator('.ll-wordset-card__progress-segment');
    await expect(segments).toHaveCount(3);
    await expect(bar.locator('.ll-wordset-card__progress-icon:visible')).toHaveCount(3);
    expect(track.width).toBeGreaterThan(upper.width*.84);
    const a=await first.locator('.ll-level-activity').nth(0).boundingBox(), b=await first.locator('.ll-level-activity').nth(1).boundingBox();
    expect(track.y).toBeGreaterThanOrEqual(Math.max(a.y+a.height,b.y+b.height));
    expect(track.y+track.height).toBeLessThanOrEqual(detailToggle.y);
    if(width===1240) expect(a.y).toBe(b.y);
    else expect(a.y+a.height).toBeLessThanOrEqual(b.y);
    const single=await rows.nth(1).locator('.ll-level-activity').boundingBox();
    expect(single.width).toBeLessThanOrEqual(352);
  }
  const details=first.getByLabel('Level details · Level 1',{exact:true});
  const chevron=details.locator('.ll-level-glyph--chevron');
  await expect(chevron).toBeVisible();
  await page.mouse.move(0,0);
  await expect(chevron).toHaveCSS('color',await paletteColor(details,'--ll-wp-muted'));
  await expect(chevron).toHaveCSS('background-color',await paletteColor(details,'--ll-wp-bg'));
  await details.hover();
  await expect(chevron).toHaveCSS('color',await paletteColor(details,'--ll-wp-text'));
  await expect(chevron).toHaveCSS('background-color',await paletteColor(details,'--ll-wp-border'));
  await page.mouse.move(0,0);
  await page.keyboard.press('ArrowRight');
  await details.focus();
  await expect(details).toHaveCSS('outline-color',await paletteColor(details,'--ll-wp-muted'));
  await expect(details).toHaveCSS('outline-style','solid');
  const tail=async()=>first.evaluate(node=>node.getBoundingClientRect().bottom-node.querySelector('.ll-level-details-toggle').getBoundingClientRect().bottom);
  expect(await tail()).toBeLessThan(20);
  await expect(chevron).toHaveCSS('transform','none');
  const title=first.getByRole('button',{name:'Browse lessons · Level 1'});
  await title.hover();
  await expect(title).toHaveCSS('border-top-color','rgba(0, 0, 0, 0)');
  await expect(title.locator('svg')).toHaveCount(0);
  await details.focus();
  await page.keyboard.press('Enter');
  const moving = () => first.evaluate(card => card.querySelector('.ll-level-details').getAnimations({subtree:true})
    .filter(animation => animation.playState === 'running' || animation.pending).length);
  expect(await moving()).toBe(0);
  await expect(chevron).toHaveCSS('transform','matrix(-1, 0, 0, -1, 0, 0)');
  expect(await tail()).toBeLessThan(20);
  await expect(first.getByRole('spinbutton',{name:labels.interval, exact:true})).toBeVisible();
  const detail=first.locator('.ll-level-detail-panel');
  for (const [status,name,count] of [['mastered',labels.mastered,3],['studied',labels.working,2],['new',labels.unseen,5],
    ['older',labels.dueLabel,2],['hard',labels.hard,1],['starred',labels.starred,3]]) {
    const row=detail.locator('.ll-level-fact').filter({has:page.getByText(name,{exact:true})});
    await expect(row.locator('dt')).toHaveText(name);
    const badge=row.locator('dd.ll-level-stat-value.ll-level-metric-'+status);
    await expect(badge).toHaveText(String(count));
    await expect(badge.locator('.ll-level-status-icon')).toBeVisible();
    await expect(row.locator('button,a,summary,[role="button"],[aria-expanded],[tabindex]')).toHaveCount(0);
  }
  await expect(detail.locator('[data-ll-level-filter],.ll-level-filter-panel')).toHaveCount(0);
  await expect(rows.nth(1).locator('.ll-level-detail-panel .ll-level-metric-older')).toHaveText('0');
  await page.keyboard.press('Space');
  expect(await moving()).toBe(0);
  await expect(first.getByRole('spinbutton',{name:labels.interval, exact:true})).toBeHidden();
  await details.click();
  await first.getByRole('button',{name:'See all lessons · Level 1'}).click();
  await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','level');
  await expect(page.locator('.ll-wordset-card[data-cat-id="15"]')).toBeHidden();
});

test('progress disclosures show the tooltip counts on levels and lessons, and glyphs only where they fit',async({page})=>{
  const stats={total:100,mastered:1,working:3,new:96,days:30,next_category_id:1};
  const catalog=categories.map(cat=>({...cat,count:100,mastered_words:1,studied_words:4,new_words:96}));
  await page.setViewportSize({width:360,height:800});
  await mount(page,{signed:true,summary:{levels:{1:stats}},categories:catalog,initialCards:0});
  const level=page.locator('[data-ll-level-card="1"]');
  const progress=level.locator('details.ll-wordset-card__progress');
  const summary=progress.locator('summary');
  const icon=status=>progress.locator('.ll-wordset-card__progress-segment--'+status+' .ll-wordset-card__progress-icon');
  const checkLegend=async target=>{
    const keys=target.locator('.ll-wordset-card__progress-key');
    await expect(keys).toHaveText(['1 Learned','3 In progress','96 New']);
    expect((await keys.allTextContents()).join(', ')).toBe(await target.locator('summary').getAttribute('title'));
  };
  await expect(summary).toHaveAccessibleName('1 Learned, 3 In progress, 96 New');
  await expect(icon('mastered')).toBeHidden();
  await expect(icon('studied')).toBeHidden();
  await expect(icon('new')).toBeVisible();
  await expect(progress.locator('.ll-wordset-card__progress-segment--studied')).toBeVisible();
  await summary.press('Space');
  await expect(progress).toHaveJSProperty('open',true);
  await checkLegend(progress);
  await expect(level.locator('.ll-level-details')).toHaveJSProperty('open',false);
  await page.setViewportSize({width:1240,height:820});
  await expect(icon('studied')).toBeVisible();
  stats.working=2;
  stats.new=97;
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  await expect(summary).toHaveAttribute('title','1 Learned, 2 In progress, 97 New');
  await expect(progress).toHaveJSProperty('open',true);
  await expect(summary).toBeFocused();
  await summary.press('Enter');
  await expect(progress).toHaveJSProperty('open',false);
  await level.getByRole('button',{name:'Browse lessons · Level 1'}).click();
  const lesson=page.locator('[data-cat-id="1"].ll-wordset-card details.ll-wordset-card__progress');
  await lesson.locator('.ll-wordset-card__progress-track').click();
  await expect(lesson).toHaveJSProperty('open',true);
  await checkLegend(lesson);
  await expect(page).toHaveURL(base+'/');
  await lesson.locator('summary').click();
  await expect(lesson).toHaveJSProperty('open',false);
});

test('lesson thumbnail decoding and progress hydration reserve their final dimensions',async({page})=>{
  const catalog=categories.map(cat=>cat.id===4 || cat.id===5 ? {
    ...cat,aspect_bucket:'ratio:16:9',preview_requires_images:true,
    has_images:cat.id===4,preview_deferred:cat.id===5,
    preview:cat.id===4 ? [{type:'image',url:'/lesson-image.svg'}] : []
  } : cat);
  let finishSummary, finishImage;
  const pauseSummary=new Promise(resolve=>{finishSummary=resolve;});
  await page.setViewportSize({width:390,height:830});
  await mount(page,{signed:true,initialCards:0,categories:catalog,pauseSummary});
  const bar=page.locator('[data-ll-level-card="1"] .ll-wordset-card__progress');
  const track=bar.locator('.ll-wordset-card__progress-track');
  const animation=element=>element.evaluate(el=>getComputedStyle(el,'::after').animationName);
  await expect(bar).toHaveJSProperty('inert',true);
  await expect(track).toHaveCSS('background-color','rgb(226, 232, 240)');
  expect(await animation(track)).toBe('ll-wordset-skeleton-shimmer');
  const initial=await bar.boundingBox();
  await page.emulateMedia({reducedMotion:'reduce'});
  expect(await animation(track)).toBe('none');
  finishSummary();
  await expect(bar).toHaveJSProperty('inert',false);
  const ready=await bar.boundingBox();
  expect(ready.height).toBe(initial.height);
  expect(ready.width).toBe(initial.width);
  await page.emulateMedia({reducedMotion:'no-preference'});
  const pauseImage=new Promise(resolve=>{finishImage=resolve;});
  await page.route(base+'/lesson-image.svg',async route=>{
    await pauseImage;
    await route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"/>'});
  });
  await page.getByRole('button',{name:'Browse lessons · Level 1'}).click();
  const card=page.locator('.ll-wordset-card[data-cat-id="4"]');
  const photo=card.locator('.ll-wordset-preview-item--image');
  const deferredPhoto=page.locator('.ll-wordset-card[data-cat-id="5"] .ll-wordset-preview-item--lazy-skeleton').first();
  await photo.scrollIntoViewIfNeeded();
  await expect(photo).toHaveClass(/ll-image-load-pending/);
  expect(await animation(photo)).toBe('ll-wordset-skeleton-shimmer');
  await expect(deferredPhoto).toHaveCSS('animation-name','ll-wordset-skeleton-shimmer');
  const beforePhoto=await photo.boundingBox();
  const beforeModes=await card.locator('.ll-wordset-card__quiz-actions').boundingBox();
  const reserved=await deferredPhoto.boundingBox();
  expect(beforePhoto.width/beforePhoto.height).toBeCloseTo(16/9,2);
  expect(reserved.width/reserved.height).toBeCloseTo(16/9,2);
  await page.emulateMedia({reducedMotion:'reduce'});
  expect(await animation(photo)).toBe('none');
  await expect(deferredPhoto).toHaveCSS('animation-name','none');
  finishImage();
  await expect(photo).toHaveClass(/ll-image-loaded/);
  expect(await photo.boundingBox()).toEqual(beforePhoto);
  expect(await card.locator('.ll-wordset-card__quiz-actions').boundingBox()).toEqual(beforeModes);
});

test('a level opens and closes progressively and reverses from its current height',async({page})=>{
  await page.setViewportSize({width:390,height:800});
  await page.emulateMedia({reducedMotion:'no-preference'});
  await mount(page);
  const card=page.locator('[data-ll-level-card="1"]');
  const trace=await card.evaluate(async node=>{
    const summary=node.querySelector('.ll-level-details-toggle');
    const arrow=summary.querySelector('.ll-level-glyph--chevron');
    const height=()=>node.getBoundingClientRect().height;
    async function toggle(reverseBelow=0) {
      const heights=[height()];
      let steadyFrames=0;
      let reversal=null;
      let rotated=false;
      const deadline=performance.now()+2500;
      summary.click();
      while(steadyFrames<5 && performance.now()<deadline) {
        await new Promise(resolve=>requestAnimationFrame(resolve));
        const current=height();
        const transform=getComputedStyle(arrow).transform;
        if(transform!=='none' && Math.abs(new DOMMatrixReadOnly(transform).b)>.05) rotated=true;
        steadyFrames=Math.abs(current-heights.at(-1))<0.1 ? steadyFrames+1 : 0;
        heights.push(current);
        if(reverseBelow && !reversal && current<reverseBelow) {
          summary.click();
          reversal={before:current,after:height()};
          steadyFrames=0;
        }
      }
      return {heights,reversal,rotated};
    }
    const opening=await toggle();
    const collapsed=opening.heights[0], expanded=opening.heights.at(-1);
    const interrupted=await toggle(collapsed+(expanded-collapsed)*.75);
    const closing=await toggle();
    return {opening,closing,interrupted,collapsed,expanded};
  });
  const {opening,closing,interrupted,collapsed,expanded}=trace;
  expect(expanded-collapsed).toBeGreaterThan(50);
  const transitional=height=>height>collapsed+1 && height<expanded-1;
  expect(opening.heights.some(transitional)).toBe(true);
  expect(closing.heights.some(transitional)).toBe(true);
  expect(opening.rotated).toBe(true);
  expect(closing.rotated).toBe(true);
  expect(interrupted.reversal).not.toBeNull();
  expect(transitional(interrupted.reversal.before)).toBe(true);
  expect(Math.abs(interrupted.reversal.before-interrupted.reversal.after)).toBeLessThan(2);
  expect(interrupted.heights.at(-1)).toBeCloseTo(expanded,0);
  expect(closing.heights.at(-1)).toBeCloseTo(collapsed,0);
  await expect(card.getByRole('region',{name:'Quiz modes'})).toBeHidden();
  await expect(card.locator('.ll-level-details')).not.toHaveAttribute('open');
});

test('scrolling during level expansion follows the gesture without anchoring to the moving arrow',async({page})=>{
  await page.setViewportSize({width:390,height:720});
  await page.emulateMedia({reducedMotion:'no-preference'});
  const manyLevels=categories.map(({id:level})=>({level,categoryIds:[level],contentIds:[],nextId:level}));
  const progress={total:10,mastered:3,working:2,new:5,next_category_id:4};
  await mount(page,{signed:true,sparse:true,groups:manyLevels,summary:{levels:{
    4:progress, 7:{...progress,mastered:10,working:0,new:0,next_category_id:0}
  }}});
  await expect(page.locator('[data-ll-level-card="7"]')).toHaveClass(/is-complete/);
  for (const level of [4,7]) {
    const card=page.locator('[data-ll-level-card="'+level+'"]');
    const toggle=card.locator('.ll-level-details-toggle');
    const initialScroll=await toggle.evaluate(node=>{
      window.scrollTo({top:window.scrollY+node.getBoundingClientRect().top-40,behavior:'instant'});
      node.focus({preventScroll:true});
      return window.scrollY;
    });
    await page.mouse.move(180,240);
    await toggle.evaluate(node=>{
      node.click();
      // Leave plenty of time to send the wheel event while the disclosure is moving.
      node.parentElement.querySelector('.ll-level-detail-body').getAnimations()[0].playbackRate=.25;
    });
    await card.locator('.ll-level-detail-body.is-animating').waitFor();
    await page.mouse.wheel(0,250);
    await expect(card.locator('.ll-level-detail-body.is-animating')).toHaveCount(0);
    await expect(toggle).toHaveAttribute('aria-expanded','true');
    await expect(toggle).toBeFocused();
    expect(Math.abs(await page.evaluate(()=>window.scrollY)-initialScroll-250)).toBeLessThanOrEqual(2);
  }
});

test('a progress refresh arriving during expansion preserves the open level and keyboard focus',async({page})=>{
  const stats={total:10,mastered:3,working:2,new:5,days:30,due:0,next_category_id:1};
  await mount(page,{signed:true,summary:{levels:{1:stats}}});
  const card=page.locator('[data-ll-level-card="1"]');
  const toggle=card.getByLabel('Level details · Level 1',{exact:true});
  const learned=card.locator('.ll-level-detail-panel dd.ll-level-metric-mastered');
  await expect(learned).toHaveText('3');
  let received;
  const pending=new Promise(resolve=>{received=resolve;});
  await page.route(base+'/ajax',route=>received(route),{times:1});
  stats.mastered=4;
  await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
  const refresh=await pending;
  await toggle.press('Enter');
  await refresh.fallback();
  await expect(learned).toHaveText('4');
  await expect(card.getByRole('region',{name:'Quiz modes'})).toBeVisible();
  await expect(toggle).toBeFocused();
});

test('complete and unfinished curricula expose actions without singling out a level or activity',async({page})=>{
  const complete={total:10,mastered:10,working:0,new:0,studied:10,due:2,practice_category_id:2,due_category_id:2,days:90};
  await mount(page,{signed:true,summary:{due:2,review:{reason:'due',count:2,due:2},levels:{1:complete,2:{...complete,due:0,practice_category_id:15}}}});
  await expect(page.locator('.is-suggested,.is-current')).toHaveCount(0);
  await expect(page.locator('.ll-level-review-all')).toHaveAccessibleName('Review now · Due for review (2) · Words left today · 2');
  await expect(page.getByRole('img',{name:'Level mastered'})).toHaveCount(2);
  const nextPage=await page.context().newPage();
  const active={...complete,total:20,mastered:12,working:4,new:4,due:0,next_category_id:1};
  await mount(nextPage,{signed:true,summary:{due:0,levels:{1:active,2:{...complete,practice_category_id:15,due_category_id:15}}}});
  await expect(nextPage.getByRole('button',{name:'Learn · Lesson 1'})).toBeVisible();
  await expect(nextPage.locator('.is-suggested,.is-current')).toHaveCount(0);
  await nextPage.close();
});

async function instrumentQuiz(page) {
  await page.addInitScript(()=>{
    window.llToolsFlashcardsData={};
    window.launchedQuizzes=[];
    window.initFlashcardWidget=(categories,mode)=>{
      const data=window.llToolsFlashcardsData;
      window.launchedQuizzes.push({mode,categories,scope:data.lastLaunchPlan.details.curriculum_level,ownership:data.sessionWordIdsByCategoryId});
    };
  });
}
async function showResults(page,mode) {
  await page.evaluate(mode=>window.jQuery(document).trigger('lltools:flashcard-results-shown',[{mode}]),mode);
}

for (const signed of [true,false]) {
  test((signed?'returning learners':'guests')+' can launch all five level quizzes from the expanded menu',async({page})=>{
    await page.setViewportSize({width:signed?1140:320,height:800});
    await instrumentQuiz(page);
    const modes=Object.keys(quizModes);
    const modeLabels=signed?quizModes:{learning:'Öğren',practice:'Tekrarla',listening:'Dinle','self-check':'Kontrol',gender:'Cinsiyet'};
    const quizTitle=signed?'Quiz modes':'Quiz modları';
    const catalog=categories.map(cat=>({...cat,gender_supported:cat.id===2,listening_supported:true}));
    const secondSummaryModes=modes.filter(mode=>mode!=='gender');
    // A signed-in learner can ignore the only lesson offering Gender in an otherwise public level.
    const levelGroups=groups.map(group=>({...group,modes:group.level===1 || signed ? modes : secondSummaryModes}));
    const ids=Array.from({length:10},(_,index)=>2010-index);
    const plan={category_ids:[2],session_word_ids:ids,word_category_ids:Object.fromEntries(ids.map(id=>[id,2]))};
    const requests=await mount(page,{signed,successful:true,plan,categories:catalog,groups:levelGroups,secondSummaryModes,modeLabels,quizTitle});
    const card=page.locator('[data-ll-level-card="1"]');
    const quizSection=card.getByRole('region',{name:quizTitle,exact:true});
    await expect(card.getByRole('heading',{name:quizTitle})).toBeHidden();
    await card.getByLabel('Level details · Level 1',{exact:true}).click();
    await expect(quizSection.getByRole('heading',{name:quizTitle,level:3})).toBeVisible();
    const buttons=quizSection.getByRole('button');
    await expect(buttons).toHaveCount(5);
    const detailHeading=card.getByRole('heading',{name:labels.stats,exact:true});
    expect(await quizSection.evaluate((section,heading)=>Boolean(section.compareDocumentPosition(heading)&Node.DOCUMENT_POSITION_FOLLOWING),await detailHeading.elementHandle())).toBe(true);
    const quizBox=await quizSection.boundingBox(), headingBox=await detailHeading.boundingBox();
    expect(quizBox.y+quizBox.height).toBeLessThanOrEqual(headingBox.y+1);
    expect(await card.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
    for(const [index,mode] of modes.entries()){
      const button=quizSection.getByRole('button',{name:modeLabels[mode]+': Level 1',exact:true});
      await expect(button).toBeVisible();
      await expect(button).toBeEnabled();
      await expect(button.getByText(modeLabels[mode],{exact:true})).toBeVisible();
      const bounds=await button.boundingBox();
      expect(bounds.height).toBeGreaterThanOrEqual(44);
      expect(await button.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
      await button.click();
      await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(index+1);
      const launched = await page.evaluate(()=>window.launchedQuizzes.at(-1));
      expect(launched).toMatchObject({mode,scope:1});
      if (!signed && mode === 'listening') {
        expect(launched.categories.slice().sort()).toEqual(catalog.filter(cat=>firstLevel.includes(cat.id)).map(cat=>cat.name).sort());
      } else {
        expect(launched.ownership).toEqual({2:ids});
      }
      if(mode==='gender') expect(await page.evaluate(()=>window.llToolsFlashcardsData.genderSessionPlan)).toMatchObject({level:2,word_ids:ids});
    }
    expect(requests.filter(req=>req.operation==='quiz').map(req=>[req.level,req.launch_mode,req.token])).toEqual(
      modes.filter(mode=>signed || mode!=='listening').map(mode=>['1',mode,'scope']));
    const second=page.locator('[data-ll-level-card="2"]');
    await second.getByLabel('Level details · Level 2',{exact:true}).click();
    await expect(second.locator('[data-ll-level-quiz-mode="gender"]')).toBeDisabled();
  });
}

test('cold and warm signed-in level Listen launch before any separate candidate hydration',async({page})=>{
  await instrumentQuiz(page);
  const catalog=categories.map(cat=>({...cat,listening_supported:true}));
  const groupsWithListen=groups.map(group=>({...group,modes:['listening']}));
  for (const coldListening of [true,false]) {
    const row={id:2002,title:'Word',audio:'/sound.mp3',image:'/thumbnail.svg'};
    const plan={category_ids:[2],session_word_ids:[row.id],word_category_ids:{2002:2},starter_rows:[row]};
    const requests=await mount(page,{signed:true,successful:true,groups:groupsWithListen,categories:catalog,
      secondSummaryModes:['listening'],plan,coldListening});
    const card=page.locator('[data-ll-level-card="1"]');
    await card.getByLabel('Level details · Level 1',{exact:true}).click();
    await card.locator('[data-ll-level-quiz-mode="listening"]').click();
    await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
    const result=await page.evaluate(()=>window.launchedQuizzes[0]);
    expect(result.categories).toEqual(coldListening?['Lesson 2','Lesson 1']:['Lesson 2']);
    expect(requests.filter(req=>req.operation==='quiz').map(req=>req.fast_start)).toEqual(['1']);
    expect(requests.filter(req=>req.action==='ll_get_words_by_category' || req.action==='ll_get_flashcard_payload_page')).toHaveLength(0);
    await page.unrouteAll({behavior:'wait'});
  }
});

test('a guest level quiz waits for its exact selection and ignores a superseded retry',async({page})=>{
  await instrumentQuiz(page);
  await page.clock.install();
  const menuGroups=groups.map(group=>({...group,modes:['practice','self-check']}));
  const options={quizWarming:true,successful:true,groups:menuGroups};
  const openMenu=async()=>{
    const card=page.locator('[data-ll-level-card="1"]');
    await card.getByLabel('Level details · Level 1',{exact:true}).click();
    return card;
  };
  let requests=await mount(page,options);
  let card=await openMenu();
  await card.locator('[data-ll-level-quiz-mode="practice"]').click();
  const attempts=()=>requests.filter(req=>req.operation==='quiz' && req.launch_mode==='practice').length;
  await expect.poll(attempts).toBe(1);
  await page.clock.runFor(1000);
  await expect.poll(attempts).toBe(2);
  expect(await page.evaluate(()=>window.launchedQuizzes)).toEqual([]);
  await page.clock.runFor(1000);
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
  expect(attempts()).toBe(3);
  await expect(page.getByText(labels.launchError)).toHaveCount(0);

  requests=await mount(page,options);
  card=await openMenu();
  await card.locator('[data-ll-level-quiz-mode="practice"]').click();
  await expect.poll(attempts).toBe(1);
  await card.locator('[data-ll-level-quiz-mode="self-check"]').click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.map(quiz=>quiz.mode))).toEqual(['self-check']);
  await page.clock.runFor(3000);
  expect(attempts()).toBe(1);
});

for (const filter of ['hard','starred']) {
  test('opening '+filter+' explains the level count before launching its exact Practice selection',async({page})=>{
    await page.setViewportSize({width:filter==='hard' ? 320 : 1140,height:800});
    await instrumentQuiz(page);
    const ids=filter==='hard' ? [1001] : [2002,2003,2004];
    const row={due:2,hard:1,starred:3,total:12,studied:6,working:2,new:6,mastered:4,days:30,practice_category_id:2};
    const owner=filter==='hard' ? 1 : 2;
    const plan={category_ids:[owner],session_word_ids:ids,word_category_ids:Object.fromEntries(ids.map(id=>[id,owner]))};
    const reqs=await mount(page,{signed:true,successful:true,plan,summary:{levels:{1:row,2:{...row,due:0,hard:0,starred:0,practice_category_id:15}}}});
    const card=page.locator('[data-ll-level-card="1"]');
    const header=card.locator('.ll-level-card-head');
    await expect(header.locator('[data-ll-level-filter]')).toHaveCount(3);
    await expect(page.locator('[data-ll-level-card="2"] [data-ll-level-filter]')).toHaveCount(0);
    for(const status of ['older','hard','starred']){
      await expect(page.locator('[data-ll-level-card="2"] .ll-level-detail-panel .ll-level-stat-value.ll-level-metric-'+status)).toHaveText('0');
    }
    const due=header.locator('[data-ll-level-filter="due"]');
    await due.click();
    const badge=header.locator('[data-ll-level-filter="'+filter+'"]');
    await expect(badge).toHaveAccessibleName(labels[filter]+' · '+ids.length+' · Level 1');
    await badge.focus();
    await page.keyboard.press('Enter');
    await expect(badge).toHaveAttribute('aria-expanded','true');
    await expect(due).toHaveAttribute('aria-expanded','false');
    const preview=page.locator('#'+await badge.getAttribute('aria-controls'));
    await expect(preview.getByText(labels[filter+'Help'])).toBeVisible();
    const color=filter==='hard' ? 'rgb(246, 214, 214)' : 'rgb(253, 231, 163)';
    await expect(preview).toHaveCSS('background-color',color);
    await expect(badge.locator('.ll-level-badge')).toHaveCSS('background-color',color);
    await expect(badge.locator('.ll-level-status-icon')).toBeVisible();
    expect(await card.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
    const launchLabel='Practice · '+labels[filter]+' · Level 1';
    const launch=preview.getByRole('button',{name:launchLabel,exact:true});
    await expect(launch).toHaveText('Practice');
    await expect(launch.locator('.ll-level-mode-icon')).toHaveAttribute('data-emoji','❓');
    expect(reqs.filter(r=>r.operation==='launch')).toHaveLength(0);
    await launch.click();
    await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
    expect(reqs.filter(r=>r.operation==='launch').map(r=>[r.level,r.launch_mode])).toEqual([['1',filter]]);
    expect(await page.evaluate(()=>window.llToolsFlashcardsData.logicalSessionWordIds)).toEqual(ids);
  });
}

test('leaving a daily quiz early updates the remainder after the answer syncs, and it survives a reload',async({page})=>{
  const summary={due:90,review:{reason:'due',count:2,due:90},levels:{}};
  await instrumentQuiz(page);
  await mount(page,{signed:true,successful:true,summary});
  const daily=page.locator('.ll-level-review-all');
  await daily.click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
  await page.addScriptTag({path:path.join(root,'js/flashcard-widget/progress-tracker.js')});
  const pending=page.waitForRequest(req=>req.postData()?.includes('ll_user_study_progress_batch'));
  let release;
  const delayed=new Promise(done=>{release=done;});
  await page.route(base+'/ajax',async route=>{
    const form=new URLSearchParams(route.request().postData());
    if(form.get('action')!=='ll_user_study_progress_batch') return route.fallback();
    await delayed;
    const outcomes=JSON.parse(form.get('events'));
    expect(outcomes.map(e=>[e.wordset_id,e.word_id,e.is_correct])).toEqual([[77,2002,true]]);
    summary.review={reason:'due',count:1,due:89};
    await route.fulfill({json:{success:true,data:{}}});
  });
  await page.evaluate(()=>{
    Object.assign(window.llToolsFlashcardsData,{isUserLoggedIn:true,ajaxurl:'/ajax',userStudyNonce:'test'});
    const tracker=window.LLFlashcards.ProgressTracker;
    tracker.trackWordOutcome({wordsetId:77,wordId:2002,categoryId:2,mode:'practice',isCorrect:true});
    tracker.flush();
    window.jQuery(document).trigger('lltools:flashcard-closed');
  });
  await pending;
  await expect(daily.locator('.ll-level-round-size strong')).toHaveText('2');
  release();
  await expect(daily).toHaveAccessibleName('Review now · Due for review (89) · Words left today · 1');
  await page.reload();
  await expect(daily).toHaveAccessibleName('Review now · Due for review (89) · Words left today · 1');
});

test('ready levels show exact progress before other lessons finish preparing',async({page})=>{
  await instrumentQuiz(page);
  let finish;
  const partialSummary=new Promise(resolve=>{finish=resolve;});
  const starter={id:15001,title:'Word',audio:'/sound.mp3',image:'/thumbnail.svg'};
  const reqs=await mount(page,{signed:true,partialSummary,successful:true,
    groups:groups.map(group=>({...group,modes:Object.keys(quizModes)})),
    categories:categories.map(cat=>({...cat,listening_supported:true})),
    plan:{category_ids:[15],session_word_ids:[starter.id],word_category_ids:{15001:15},starter_rows:[starter]}});
  const first=page.locator('[data-ll-level-card="1"]'), second=page.locator('[data-ll-level-card="2"]');
  try {
    await expect(first.getByLabel('3 Learned, 2 In progress, 5 New',{exact:true})).toBeVisible();
    await expect(first.getByRole('button',{name:'Practice · Lesson 2'})).toBeVisible();
    await expect(first.locator('.ll-level-activities .ll-level-placeholder')).toHaveCount(0);
    const actions=second.locator('.ll-level-activities');
    await expect(actions.locator('.ll-level-activity.ll-level-placeholder.ll-level-sheen')).toBeVisible();
    await expect(actions.getByRole('button')).toHaveCount(0);
    await expect(second.locator('.ll-wordset-card__progress-track.is-loading')).toBeVisible();
    await expect(page.locator('.ll-level-review-all')).toHaveCount(0);
    await expect.poll(()=>reqs.filter(r=>r.operation==='summary').at(-1)?.ready_levels).toBe('1');
    await second.getByLabel('Level details · Level 2',{exact:true}).click();
    await second.locator('[data-ll-level-quiz-mode="listening"]').click();
    await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes[0]?.categories)).toEqual(['Everyday Expressions']);
    expect(reqs.find(r=>r.operation==='quiz').level).toBe('2');
  } finally { finish(); }
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await expect(second.locator('.ll-level-activities .ll-level-placeholder')).toHaveCount(0);
  await expect(second.locator('.ll-wordset-card__progress-track.is-loading')).toHaveCount(0);
});

for(const width of [390,1140]) {
  test('each pending level shows one button-sized sheen until its actions are ready at '+width+'px',async({page})=>{
    await page.setViewportSize({width,height:850});
    let release;
    const holdSummary=new Promise(done=>{release=done;});
    await mount(page,{signed:true,holdSummary,groups:groups.map(group=>({...group,modes:Object.keys(quizModes)}))});
    const first=page.locator('[data-ll-level-card="1"]');
    const actions=first.locator('.ll-level-activities');
    const pending=actions.locator('.ll-level-activity.ll-level-sheen');
    let loadingTop, loadingButton;
    try {
      const actionRows=page.locator('[data-ll-level-card] .ll-level-activities');
      await expect(actionRows).toHaveCount(groups.length);
      for(const row of await actionRows.all()){
        const placeholder=row.locator(':scope > .ll-level-activity.ll-level-placeholder.ll-level-sheen');
        await expect(placeholder).toBeVisible();
        await expect(placeholder).toHaveAttribute('aria-hidden','true');
        await expect(row).toHaveText('');
        await expect(row.getByRole('button')).toHaveCount(0);
      }
      loadingTop=await page.locator('.ll-level-review-placeholder').boundingBox();
      loadingButton=await pending.boundingBox();
    } finally { release(); }
    const daily=page.locator('.ll-level-review-all');
    await expect(daily).toBeVisible();
    await expect(pending).toHaveCount(0);
    const readyButton=await actions.getByRole('button').first().boundingBox();
    expect(Math.abs((await daily.boundingBox()).height-loadingTop.height)).toBeLessThanOrEqual(1);
    expect(Math.abs(readyButton.height-loadingButton.height)).toBeLessThanOrEqual(1);
    expect(Math.abs(readyButton.y-loadingButton.y)).toBeLessThanOrEqual(2);
  });
}

test('an open wordset refreshes its goal at site midnight',async({page})=>{
  await page.clock.install();
  const summary={review:{reason:'hard',count:3,due:12},levels:{},next_reset_in:45};
  await mount(page,{signed:true,summary});
  const daily=page.locator('.ll-level-review-all');
  await expect(daily).toHaveAccessibleName('Practice · Hard · Words left today · 3');
  summary.review={reason:'due',count:10,due:12};
  summary.next_reset_in=86400;
  await page.clock.fastForward(46000);
  await expect(daily).toHaveAccessibleName('Review now · Due for review (12) · Words left today · 10');
});

test('daily practice prefetches one batch after launch and shares it with a waiting continuation',async({page})=>{
  const ids=Array.from({length:16},(_,i)=>2001+i);
  const plan={category_ids:[2],session_word_ids:ids,word_category_ids:Object.fromEntries(ids.map(id=>[id,2]))};
  await instrumentQuiz(page);
  await page.addInitScript(()=>{
    window.appendedTargets=[];
    window.LLFlashcards={Main:{appendBoundedSelectionChunk(){
      window.appendedTargets.push(window.llToolsFlashcardsData.sessionWordIds.slice());
    }}};
  });
  let release;
  const until=new Promise(resolve=>{release=resolve;});
  const reqs=await mount(page,{signed:true,successful:true,plan,holdCandidate:{id:String(ids[12]),until}});
  await page.locator('.ll-level-review-all').click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
  const hydrated=()=>reqs.filter(r=>r.action==='ll_get_words_by_category').map(r=>r.candidate_word_ids.split(',').map(Number));
  await expect.poll(hydrated).toEqual([ids.slice(0,12),ids.slice(12)]);
  expect(reqs.filter(r=>r.action==='ll_get_words_by_category').every(r=>r.include_option_pool==='1')).toBe(true);
  expect(await page.evaluate(()=>window.llToolsFlashcardsData.logicalSessionTotal)).toBe(16);
  const continuation=page.evaluate(()=>window.llToolsFlashcardsData.boundedSessionContinuation());
  release();
  await continuation;
  expect(hydrated()).toEqual([ids.slice(0,12),ids.slice(12)]);
  expect(await page.evaluate(()=>window.appendedTargets)).toEqual([ids.slice(12)]);
  expect(await page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
  expect(await page.evaluate(()=>window.llToolsFlashcardsData.logicalSessionWordIds)).toEqual(ids);
});

test('a mismatched exact batch owner is rejected before fetching candidate words',async({page})=>{
  const plan={category_ids:[1,2],session_word_ids:[2002],word_category_ids:{2002:2}};
  const chunk={category_ids:[1],word_ids:[2002]};
  const bounded={...chunk,mode:'practice',chunks:[chunk],chunk_count:1,matched_count:1,planned_count:1,truncated:false};
  const reqs=await mount(page,{signed:true,successful:true,plan,bounded});
  await page.locator('.ll-level-review-all').click();
  await expect(page.getByText('Could not start this activity.')).toBeVisible();
  expect(reqs.filter(r=>r.action==='ll_get_words_by_category' || r.action==='ll_get_flashcard_payload_page')).toHaveLength(0);
});

test('closing a level quiz aborts its speculative batch before another launch',async({page})=>{
  await instrumentQuiz(page);
  await page.addInitScript(()=>{
    window.canceledQuizReads=[];
    const send=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send=function(body) {
      const ids=new URLSearchParams(String(body)).get('candidate_word_ids');
      if (ids) this.addEventListener('abort',()=>window.canceledQuizReads.push(ids));
      return send.call(this,body);
    };
  });
  const ids=Array.from({length:16},(_,i)=>2001+i);
  const plan={category_ids:[2],session_word_ids:ids,word_category_ids:Object.fromEntries(ids.map(id=>[id,2]))};
  let release;
  const until=new Promise(resolve=>{release=resolve;});
  const reqs=await mount(page,{signed:true,successful:true,plan,holdCandidate:{id:String(ids[12]),until}});
  try {
    await page.locator('.ll-level-review-all').click();
    await expect.poll(()=>reqs.filter(r=>r.action==='ll_get_words_by_category').length).toBe(2);
    await page.evaluate(()=>window.jQuery(document).trigger('lltools:flashcard-closed'));
    await expect.poll(()=>page.evaluate(()=>window.canceledQuizReads)).toEqual([ids.slice(12).join(',')]);
    expect(await page.evaluate(()=>window.llToolsFlashcardsData.boundedSessionContinuation)).toBeUndefined();
  } finally {
    release();
  }
});

test('one-word review and Repeat keep the exact target, then another level starts with a fresh scope',async({page})=>{
  await instrumentQuiz(page);
  const reqs=await mount(page,{signed:true,successful:true});
  await page.locator('.ll-level-review-all').click();
  const review={mode:'practice',categories:['Lesson 2'],scope:0,ownership:{'2':[2002]}};
  const quizzes=()=>page.evaluate(()=>window.launchedQuizzes);
  await expect.poll(quizzes).toEqual([review]);
  expect(reqs.filter(r=>r.action==='ll_get_words_by_category').map(r=>[r.candidate_word_ids,r.include_option_pool])).toEqual([['2002','1']]);
  await showResults(page,'practice');
  await expect(page.getByRole('button',{name:'Back to levels'})).toBeVisible();
  await page.getByRole('button',{name:'Repeat'}).click();
  await expect.poll(quizzes).toEqual([review,review]);
  await showResults(page,'practice');
  await page.getByRole('button',{name:'Back to levels'}).click();
  await page.getByRole('button',{name:'Learn · Everyday Expressions'}).click();
  await expect.poll(quizzes).toEqual([review,review,{mode:'learning',categories:['Everyday Expressions'],scope:2}]);
  await showResults(page,'learning');
  await expect(page.getByRole('button',{name:'Continue Level 2'})).toBeVisible();
  await page.getByRole('button',{name:'Continue Level 2'}).click();
  await expect.poll(()=>reqs.filter(r=>r.operation==='launch').map(r=>r.level)).toEqual(['0','2','2']);
});

test('per-level review uses server-assigned category ownership for overlapping words and remains in that level',async({page})=>{
  await instrumentQuiz(page);
  await mount(page,{signed:true,successful:true,overlap:true});
  await page.getByRole('button',{name:'Practice · 2 words in progress'}).click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes)).toMatchObject([{mode:'practice',scope:1,ownership:{'1':[1001],'2':[2002]}}]);
  await showResults(page,'practice');
  await expect(page.getByRole('button',{name:'Continue Level 1'})).toBeVisible();
  await expect(page.locator('#ll-study-results-different-chunk')).toBeHidden();
});

test('signed-in learner can start a practice-only level without earlier study',async({page})=>{
  await mount(page,{signed:true,exerciseOnly:true});
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await expect(page.locator('[data-ll-level-card="2"]').getByRole('button',{name:'Practice · Everyday Expressions'})).toBeVisible();
});

test('an answer-only prompt cannot take the place of the planned review word',async({page})=>{
  await instrumentQuiz(page);
  await mount(page,{signed:true,successful:true,untracked:true});
  await page.locator('.ll-level-review-all').click();
  await expect(page.getByText('Could not start this activity.')).toBeVisible();
  expect(await page.evaluate(()=>window.launchedQuizzes)).toEqual([]);
});

async function mountWithFailingSummary(page, failures) {
  const documents = [];
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  let release;
  const pauseSummary = new Promise(resolve => { release = resolve; });
  const requests = await mount(page,{signed:true,pauseSummary,summaryFailures:failures});
  return {documents, release, summaryCount:() => requests.filter(request => request.operation === 'summary').length};
}

const recoveredLevelDocuments = [base+'/',base+'/?ll_category_search_recovery=1'];
const expiredLevelScope = {status:410,json:{success:false,data:{code:'ll_level_scope_expired'}}};

test('an expired level nonce automatically reloads the document and restores personalized activities',async({page})=>{
  const probe = await mountWithFailingSummary(page,[{status:403,contentType:'text/plain',body:'-1'}]);
  probe.release();
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await expect(page.getByRole('button',{name:'Reload',exact:true})).toHaveCount(0);
  expect(probe.documents).toEqual(recoveredLevelDocuments);
  await expect(page).toHaveURL(base+'/');
  expect(probe.summaryCount()).toBe(2);
});

test('a persistently expired level scope reloads once and leaves the lesson catalog browsable',async({page})=>{
  const probe = await mountWithFailingSummary(page,[expiredLevelScope,expiredLevelScope]);
  probe.release();
  await expect.poll(probe.summaryCount).toBe(2);
  const fallback = page.getByRole('button',{name:'Learn · Lesson 1'});
  await expect(fallback).toBeVisible();
  await expect(page.getByText('Reload this page to update your levels.')).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Reload',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Browse lessons · Level 1',exact:true}).click();
  await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','level');
  await expect(page.locator('.ll-wordset-card[data-cat-id="1"]')).toBeVisible();
  expect(probe.documents).toEqual(recoveredLevelDocuments);
  await expect(page).toHaveURL(base+'/');
  expect(probe.summaryCount()).toBe(2);
});

test('an expiry discovered during a quiz waits until the quiz closes to reload',async({page})=>{
  const probe = await mountWithFailingSummary(page,[expiredLevelScope]);
  await page.evaluate(() => {
    document.body.classList.add('ll-tools-flashcard-open');
    window.jQuery(document).one('ajaxComplete',() => { window.summarySettled = true; });
  });
  probe.release();
  await expect.poll(()=>page.evaluate(()=>window.summarySettled)).toBe(true);
  expect(probe.documents).toHaveLength(1);
  await expect(page.getByText('Reload this page to update your levels.')).toHaveCount(0);
  await page.evaluate(() => {
    document.body.classList.remove('ll-tools-flashcard-open');
    window.jQuery(document).trigger('lltools:flashcard-closed');
  });
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  expect(probe.documents).toEqual(recoveredLevelDocuments);
  await expect(page).toHaveURL(base+'/');
  expect(probe.summaryCount()).toBe(2);
});

for (const view of ['level','search']) {
  test('automatic expiry recovery preserves the current '+view+' view and bookmark',async({page})=>{
    const probe = await mountWithFailingSummary(page,[expiredLevelScope]);
    const bookmark = base+'/?utm_source=bookmark#levels';
    await page.evaluate(url=>history.replaceState(history.state,'',url),bookmark);
    if (view === 'level') {
      await page.getByRole('button',{name:'Browse lessons · Level 2',exact:true}).click();
    } else {
      await page.getByRole('searchbox').fill('Everyday');
    }
    const root = page.locator('[data-ll-wordset-page]');
    await expect(root).toHaveAttribute('data-ll-level-view',view);
    probe.release();
    await expect.poll(probe.summaryCount).toBe(2);
    await expect(root).toHaveAttribute('data-ll-level-view',view);
    await expect(page).toHaveURL(bookmark);
    if (view === 'level') {
      await expect(page.locator('.ll-wordset-card[data-cat-id="15"]')).toBeVisible();
      await expect(page.locator('.ll-wordset-card[data-cat-id="1"]')).toBeHidden();
    } else {
      await expect(page.getByRole('searchbox')).toHaveValue('Everyday');
    }
    expect(probe.documents).toHaveLength(2);
  });
}

test('an expired level launch refreshes after its loading popup closes without replaying the activity',async({page})=>{
  await instrumentQuiz(page);
  const reqs = await mount(page,{signed:true,launchFailure:{status:403,json:{success:false,data:{code:'ll_level_scope_changed'}}}});
  await page.locator('.ll-level-review-all').click();
  await expect.poll(()=>reqs.filter(request=>request.operation==='summary').length).toBe(2);
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/ll-tools-flashcard-open/);
  expect(reqs.filter(request=>request.operation==='launch')).toHaveLength(1);
  expect(await page.evaluate(()=>window.launchedQuizzes)).toEqual([]);
});

for (const status of [403,500]) {
  test('an unrelated level server error ('+status+') can be retried in the current document',async({page})=>{
    const probe = await mountWithFailingSummary(page,[{status,json:{success:false,data:{code:'unrelated_error'}}}]);
    probe.release();
    await page.getByRole('button',{name:labels.retry,exact:true}).click();
    await expect(page.locator('.ll-level-review-all')).toBeVisible();
    expect(probe.documents).toEqual([base+'/']);
    expect(probe.summaryCount()).toBe(2);
  });
}

test('hidden tabs pause warming and refresh once when a stale catalog returns',async({page})=>{
  await page.clock.install({time:new Date('2026-11-01T09:00:00Z')});
  const reqs=await mount(page,{signed:true,prepare:true});
  const reads=()=>reqs.filter(r=>r.operation==='summary').length;
  const visibility=hidden=>page.evaluate(hidden=>{
    Object.defineProperty(document,'hidden',{configurable:true,value:hidden});
    document.dispatchEvent(new Event('visibilitychange'));
  },hidden);
  await expect.poll(reads).toBe(1);
  await visibility(true);
  await page.clock.fastForward(61000);
  expect(reads()).toBe(1);
  await visibility(false);
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  expect(reads()).toBe(2);
  await page.clock.fastForward(61000);
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  await expect.poll(reads).toBe(3);
  await page.waitForFunction(()=>window.jQuery.active===0);
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  await expect.poll(reads).toBe(4);
});

test('level status buttons stay together and reserve a shared activity column', async ({page}) => {
  const complete = {total:1000, mastered:1000, working:0, new:0, due:601, hard:120, starred:399, practice_category_id:2};
  const next = {...complete, total:1100, new:100, due:0, hard:0, next_category_id:15, practice_category_id:15};
  await mount(page, {signed:true, summary:{levels:{1:complete, 2:next}}});
  const first = page.locator('[data-ll-level-card="1"]');
  const chips = first.locator('.ll-level-filter-toggle');
  const activity = first.locator('.ll-level-activity');
  await expect(chips).toHaveCount(3);

  for (const width of [1240, 850, 320]) {
    await page.setViewportSize({width, height:850});
    const title = await first.locator('.ll-level-title').boundingBox();
    const boxes = await Promise.all((await chips.all()).map(chip => chip.boundingBox()));
    const button = await activity.boundingBox();
    for (const box of boxes) expect(box.y).toBeCloseTo(boxes[0].y, 0);
    if (width === 320) {
      expect(boxes[0].y).toBeGreaterThanOrEqual(title.y + title.height);
      expect(button.y).toBeGreaterThanOrEqual(boxes[0].y + boxes[0].height);
    } else {
      expect(boxes[0].x).toBeGreaterThanOrEqual(title.x + title.width);
      expect(boxes[0].y + boxes[0].height / 2).toBeCloseTo(title.y + title.height / 2, 0);
      expect(button.x).toBeGreaterThanOrEqual(boxes[2].x + boxes[2].width);
      const nextButton = await page.locator('[data-ll-level-card="2"] .ll-level-activity').first().boundingBox();
      expect(nextButton.x).toBeCloseTo(button.x, 0);
      await chips.first().click();
      expect((await activity.boundingBox()).x).toBeCloseTo(button.x, 0);
      await chips.first().click();
    }
  }
});

test('opening the lesson catalog starts at the top and Back restores the overview position', async ({page}) => {
  await page.setViewportSize({width:390,height:664});
  await page.emulateMedia({reducedMotion:'reduce'});
  await mount(page,{
    groups:[
      {level:1,categoryIds:[15],contentIds:[],nextId:15},
      {level:2,categoryIds:firstLevel,contentIds:[],nextId:1}
    ]
  });
  await page.addStyleTag({content:'html {scroll-behavior:smooth} .ll-wordset-card {min-height:150px}'});
  const level = page.locator('[data-ll-level-card="2"]');
  await level.locator('.ll-level-details-toggle').click();
  const seeAll = level.getByRole('button',{name:'See all lessons · Level 2'});
  await seeAll.scrollIntoViewIfNeeded();
  const overviewScroll = await page.evaluate(() => window.scrollY);
  expect(overviewScroll).toBeGreaterThan(100);
  await seeAll.click();
  const root = page.locator('[data-ll-wordset-page]');
  await expect(root).toHaveAttribute('data-ll-level-view','level');
  await expect(page.getByRole('heading',{name:'Level 2',exact:true})).toBeInViewport();
  expect(await page.evaluate(() => window.scrollY)).toBe(0);

  await page.getByRole('button',{name:'Back',exact:true}).click();
  await expect(root).toHaveAttribute('data-ll-level-view','home');
  await expect(seeAll).toBeInViewport();
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(overviewScroll);
});
