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
const groups = [
  {level:1,categoryIds:firstLevel,contentIds:[],nextId:1,practiceId:1},
  {level:2,categoryIds:[15],contentIds:[],nextId:15,practiceId:15}
];
const base = 'https://wordset-levels.test';

async function mount(page, options = {}) {
  const ids = categories.map(item => item.id);
  const reqs = [];
  const summaryRow = {total:10,mastered:3,working:2,new:5,due:2,added:1,last_studied_at:'2026-09-02 12:00:00',
    next_category_id:1,practice_category_id:2,due_category_id:2,days:90};
  const config = {view:'main',wordsetId:77,ajaxUrl:'/ajax',nonce:'',categories,isLoggedIn:!!options.signed,
    curriculumLevels:{levels:groups,nonce:'nonce',token:'scope',i18n:labels},
    state:{category_ids:ids},i18n:{selectAll:'Select all',deselectAll:'Deselect all',repeatLabel:'Repeat',modeCategoryAria:'%1$s: %2$s'},modeLabels:{learning:'Learn', practice:'Practice'}};
  if (options.exerciseOnly) config.curriculumLevels.levels=groups.map(group => group.level === 2 ? {...group,nextId:0} : group);
  if (options.modeUi) config.modeUi=options.modeUi;
  if (options.catalogNonce) config.nonce='catalog';
  const files = {
    '/jquery.js': fs.readFileSync(require.resolve('jquery'),'utf8'),
    '/loader.js': fs.readFileSync(path.join(root,'js/flashcard-widget/loader.js'),'utf8'),
    '/levels.js': fs.readFileSync(path.join(root,'js/wordset-levels.js'),'utf8'),
    '/main.js': fs.readFileSync(path.join(root,'js/wordset-pages.js'),'utf8'),
    '/catalog.css': fs.readFileSync(path.join(root,'css/wordset-pages.css'),'utf8'),
    '/levels.css': fs.readFileSync(path.join(root,'css/wordset-levels.css'),'utf8')
  };
  function existing(item) {
    return '<article class="ll-wordset-card" data-cat-id="'+item.id+'"><div class="ll-wordset-card__top"><label><input type="checkbox" data-ll-wordset-select value="'+item.id+'"></label><a class="ll-wordset-card__heading" href="'+item.url+'"><h2 class="ll-wordset-card__title">'+item.name+'</h2></a></div></article>';
  }
  if (options.sparse) {
    config.categories = categories.map(cat=>({...cat,preview_deferred:true,has_images:true}));
    config.lazyCards={enabled:true,nonce:'lazy',token:'cards',wordsetId:77,total:16,loaded:1};
  }
  const body = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/catalog.css"><link rel="stylesheet" href="/levels.css">' +
    '<main class="ll-wordset-page" data-ll-wordset-page><div class="ll-wordset-grid-tools"><div class="ll-wordset-grid-search-tools"><div class="ll-wordset-progress-search ll-wordset-progress-search--wordset-page"><input class="ll-wordset-progress-search__input" aria-label="Search" placeholder="Search words or translations" type="search" data-ll-wordset-page-search><button class="ll-wordset-progress-search__clear" data-ll-wordset-page-search-clear hidden>Clear</button></div></div><button data-ll-wordset-select-all>Select all</button></div>' +
    '<section data-ll-wordset-levels hidden></section><div data-ll-wordset-main-grid class="ll-wordset-grid">' + (options.sparse ? categories.slice(0,1) : categories).map(existing).join('') +
    '</div><div data-ll-wordset-page-search-empty hidden>Empty</div></main><div id="ll-study-results-actions" style="display:none"><button id="ll-study-results-same-chunk"></button><button id="ll-study-results-different-chunk"></button><button id="ll-study-results-next-chunk"></button></div><script>window.llWordsetPageData='+JSON.stringify(config)+'</script>' +
    '<script src="/jquery.js"></script><script src="/loader.js"></script><script src="/levels.js"></script><script src="/main.js"></script>';
  await page.route(base+'/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const data = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      reqs.push(data);
      if(data.action === 'll_tools_wordset_page_lazy_cards' && data.category_ids) {
        const ids=data.category_ids.split(',').map(Number);
        const html=ids.map(id=>'<article class="ll-wordset-card" data-cat-id="'+id+'"><span class="ll-wordset-preview-item--image"><img src="/thumbnail.svg" alt=""></span></article>').join('');
        return route.fulfill({json:{success:true,data:{category_ids:ids,html}}});
      }
      if (data.operation === 'summary') {
        if (options.expired && reqs.filter(r=>r.operation==='summary').length===1) {
          return route.fulfill({status:403,contentType:'text/plain',body:'-1'});
        }
        if (options.prepare && reqs.filter(r => r.operation === 'summary').length === 1) {
          return route.fulfill({status:503,json:{success:false,data:{code:'ll_level_progress_preparing',retry_after:1}}});
        }
        const first=options.completed ? {...summaryRow,mastered:10,working:0,new:0,next_category_id:0} : summaryRow;
        if (options.overlap) first.due_activity={category_ids:[1,2]};
        const second = {...summaryRow,days:30,added:0,due:0,practice_category_id:0,next_category_id:options.exerciseOnly ? 0 : 15};
        const totals=options.summary || {due:2,levels:{1:first,2:second}};
        return route.fulfill({json:{success:true,data:totals}});
      }
      if (data.operation === 'interval') {
        summaryRow.days=Number(data.days);
        return route.fulfill({json:{success:true,data:{level:1,days:summaryRow.days}}});
      }
      if (data.operation === 'launch' && options.successful) {
        if (data.launch_mode === 'learn') return route.fulfill({json:{success:true,data:{mode:'learning',category_ids:[15],session_word_ids:[]}}});
        const plan = options.overlap && data.level === '1'
          ? {category_ids:[1,2],session_word_ids:[2002,1001],word_category_ids:{2002:2,1001:1}}
          : {category_ids:[2],session_word_ids:[2002],word_category_ids:{2002:2}};
        return route.fulfill({json:{success:true,data:{mode:'practice',...plan}}});
      }
      if (data.action === 'll_get_words_by_category' || data.action === 'll_get_flashcard_payload_page') {
        const wordIds=data.category_id === '15' ? [1501,1502,1503,1504,1505,1506] : [1001,2002,9999];
        const rows=wordIds.map(id=>({id,title:'Word '+id,label:'Word '+id,translation:'Translation '+id,audio:'/sound.mp3',image:'/thumbnail.svg'}));
        if (options.untracked) rows.splice(0,rows.length,{id:78493,is_prompt_card:true,answer_word_id:2002,progress_word_id:0});
        const payload=data.action === 'll_get_flashcard_payload_page' ? {rows,next_cursor:''} : rows;
        return route.fulfill({json:{success:true,data:payload}});
      }
      if (data.operation === 'launch') return route.fulfill({status:409,json:{success:false,data:{code:'ll_level_no_activity'}}});
      return route.fulfill({json:{success:true,data:{}}});
    }
    if (url.pathname === '/thumbnail.svg') return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="17" fill="teal"/></svg>'});
    if (files[url.pathname]) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:files[url.pathname]});
    return route.fulfill({contentType:'text/html',body:url.pathname === '/' ? body : '<h1>Lesson</h1>'});
  });
  await page.goto(base+'/');
  await expect(page.locator('[data-ll-wordset-page]')).toHaveAttribute('data-ll-level-view','home');
  return reqs;
}

test('mobile browse, bounded pages, global search, clear, and Back preserve their own scope', async ({page}) => {
  await page.setViewportSize({width:390,height:664});
  const errors=[];
  page.on('pageerror', e=>errors.push(e.message));
  const requests=await mount(page);
  const grid=page.locator('[data-ll-wordset-main-grid]');
  const search=page.getByRole('searchbox');
  const home=page.locator('[data-ll-wordset-page]');
  await expect(grid).toBeHidden();
  await expect(page.locator('[aria-current="step"]')).toHaveAttribute('data-ll-level-card','1');
  await expect(page.getByRole('button',{name:'Start · Lesson 1'})).toBeVisible();
  await page.getByRole('button',{name:'Browse lessons · Level 1'}).focus();
  await page.keyboard.press('Enter');
  await expect(grid.locator('article:visible')).toHaveCount(12);
  await page.getByRole('button',{name:'Show more lessons'}).click();
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

test('personalized cards retry preparing summaries, expose review scopes, and autosave intervals', async ({page})=>{
  const messages=[];
  page.on('pageerror', e=>messages.push(e.message));
  const reqs=await mount(page,{signed:true,prepare:true,catalogNonce:true});
  await expect(page.getByText('Loading progress…')).toBeVisible();
  await expect(page.getByRole('button',{name:labels.reviewAll})).toBeVisible();
  const first=page.locator('[data-ll-level-card="1"]');
  await expect(first.getByRole('img',{name:'Learned 3, In progress 2, Unseen 5'})).toBeVisible();
  await expect(first.getByRole('img',{name:/Added since/})).toBeVisible();
  await expect(first.getByRole('img',{name:'Due for review (2)'})).toBeVisible();
  await expect(first.getByText('3 / 10 learned')).toBeVisible();
  await expect(first.locator('.is-suggested')).toHaveAccessibleName('Practice · Lesson 2');
  await expect(page.locator('.ll-level-nudge')).toHaveCount(1);
  await expect(page.getByRole('button',{name:'Review now · 2'})).toBeVisible();
  await first.getByLabel('Level details · Level 1',{exact:true}).click();
  const days=first.getByRole('spinbutton',{name:'Days before review'});
  await days.fill('45');
  await days.press('Tab');
  await expect(first.getByRole('status')).toHaveText('Saved');
  await expect(days).toHaveValue('45');
  await expect.poll(()=>reqs.filter(r=>r.operation==='summary').length).toBeGreaterThan(2);
  await expect(page.locator('.ll-level-nudge')).toHaveCount(0);
  await page.getByRole('button',{name:labels.reviewAll}).click();
  await expect.poll(()=>reqs.filter(r=>r.operation==='launch').map(r=>[r.level,r.launch_mode])).toEqual([['0','due']]);
  await expect(page.getByText('Could not start this activity.')).toBeVisible();
  expect(reqs.filter(r=>r.operation==='interval').map(r=>[r.level,r.days])).toEqual([['1','45']]);
  expect(reqs.filter(r=>r.action==='ll_user_study_recommendation')).toHaveLength(0);
  expect(messages).toEqual([]);
});

test('returning learner sees the first unfinished level and receives only visible CTA thumbnails',async({page})=>{
  const reqs=await mount(page,{signed:true,sparse:true,completed:true});
  await expect(page.locator('[aria-current="step"]')).toHaveAttribute('data-ll-level-card','2');
  await expect(page.getByRole('img',{name:'Level mastered'})).toBeVisible();
  await expect(page.locator('[data-ll-level-card="2"] .ll-level-activity img')).toBeVisible();
  const lookups=reqs.filter(r=>r.action==='ll_tools_wordset_page_lazy_cards');
  expect(lookups.length).toBeGreaterThan(0);
  expect(lookups.every(r=>r.category_ids && r.category_ids.split(',').length<=8 && r.preview_limit==='1')).toBe(true);
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
  await expect(first.locator('[data-ll-level-action="due"] .ll-level-mode-icon')).toHaveAttribute('data-emoji','❓');
  await expect(first.locator('.ll-level-nudge > .ll-level-glyph--forward')).toHaveCSS('animation-name','none');
  await expect(page.getByText('Recommended level',{exact:true})).toHaveCount(0);
  const badges=first.locator('.ll-level-badge');
  const due=await badges.first().boundingBox(), added=await badges.last().boundingBox();
  expect(due.width).toBe(added.width);
  expect(due.height).toBe(added.height);
  for(const width of [1240,390,320]) {
    await page.setViewportSize({width,height:820});
    const upper=await rows.nth(0).boundingBox(), lower=await rows.nth(1).boundingBox();
    expect(upper.x).toBe(lower.x);
    expect(upper.width).toBe(lower.width);
    expect(upper.y+upper.height).toBeLessThanOrEqual(lower.y);
    expect(upper.x+upper.width).toBeLessThanOrEqual(width);
    const a=await first.locator('.ll-level-activity').nth(0).boundingBox(), b=await first.locator('.ll-level-activity').nth(1).boundingBox();
    if(width===1240) expect(a.y).toBe(b.y);
    else expect(a.y+a.height).toBeLessThanOrEqual(b.y);
  }
  const details=first.getByLabel('Level details · Level 1',{exact:true});
  await details.focus();
  await page.keyboard.press('Enter');
  await expect(first.getByRole('spinbutton',{name:'Days before review'})).toBeVisible();
  await page.keyboard.press('Space');
  await expect(first.getByRole('spinbutton',{name:'Days before review'})).toBeHidden();
});

test('a completed curriculum highlights one global review while a learner with no due words gets Learn',async({page})=>{
  const complete={total:10,mastered:10,working:0,new:0,studied:10,due:2,added:0,practice_category_id:2,due_category_id:2,days:90};
  await mount(page,{signed:true,summary:{due:2,levels:{1:complete,2:{...complete,due:0,practice_category_id:15}}}});
  await expect(page.locator('.is-suggested')).toHaveCount(1);
  await expect(page.locator('.is-suggested')).toHaveAccessibleName('Review now · 2');
  await expect(page.getByRole('img',{name:'Level mastered'})).toHaveCount(2);
  const nextPage=await page.context().newPage();
  const active={...complete,mastered:3,working:2,new:5,due:0,next_category_id:1};
  await mount(nextPage,{signed:true,summary:{due:0,levels:{1:active,2:complete}}});
  await expect(nextPage.locator('.is-suggested')).toHaveAccessibleName('Learn · Lesson 1');
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

test('one-word review and Repeat keep the exact target, then another level starts with a fresh scope',async({page})=>{
  await instrumentQuiz(page);
  const reqs=await mount(page,{signed:true,successful:true});
  await page.getByRole('button',{name:labels.reviewAll}).click();
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
  await page.getByRole('button',{name:'Practice · Level 1'}).click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes)).toMatchObject([{mode:'practice',scope:1,ownership:{'1':[1001],'2':[2002]}}]);
  await showResults(page,'practice');
  await expect(page.getByRole('button',{name:'Continue Level 1'})).toBeVisible();
  await expect(page.locator('#ll-study-results-different-chunk')).toBeHidden();
});

test('signed-in learner can start a practice-only level without earlier study',async({page})=>{
  await mount(page,{signed:true,exerciseOnly:true});
  await expect(page.getByRole('button',{name:labels.reviewAll})).toBeVisible();
  await expect(page.locator('[data-ll-level-card="2"]').getByRole('button',{name:'Practice · Everyday Expressions'})).toBeVisible();
});

test('an answer-only prompt cannot take the place of the planned review word',async({page})=>{
  await instrumentQuiz(page);
  await mount(page,{signed:true,successful:true,untracked:true});
  await page.getByRole('button',{name:labels.reviewAll}).click();
  await expect(page.getByText('Could not start this activity.')).toBeVisible();
  expect(await page.evaluate(()=>window.launchedQuizzes)).toEqual([]);
});

test('expired signed-in credentials leave browsing and a working reload available',async({page})=>{
  const reqs=await mount(page,{signed:true,expired:true});
  await expect(page.getByText('Reload this page to update your levels.')).toBeVisible();
  await expect(page.getByRole('button',{name:'Start · Lesson 1'})).toBeVisible();
  await page.getByRole('button',{name:'Reload',exact:true}).click();
  await expect(page.getByRole('button',{name:labels.reviewAll})).toBeVisible();
  expect(reqs.filter(r=>r.operation==='summary')).toHaveLength(2);
});

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
  await expect(page.getByRole('button',{name:labels.reviewAll})).toBeVisible();
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  expect(reads()).toBe(2);
  await page.clock.setSystemTime(new Date('2026-11-01T11:00:00Z'));
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  await expect.poll(reads).toBe(3);
});
