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
      modeLearning:modeNames.learning,modePractice:modeNames.practice,modeListening:modeNames.listening,modeSelfCheck:modeNames['self-check'],modeGender:modeNames.gender},
    modeLabels:modeNames};
  if (options.exerciseOnly) config.curriculumLevels.levels=groups.map(group => group.level === 2 ? {...group,nextId:0} : group);
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
  function existing(item) {
    return '<article class="ll-wordset-card" data-cat-id="'+item.id+'"><div class="ll-wordset-card__top"><label><input type="checkbox" data-ll-wordset-select value="'+item.id+'"></label><a class="ll-wordset-card__heading" href="'+item.url+'"><h2 class="ll-wordset-card__title">'+item.name+'</h2></a></div></article>';
  }
  if (options.sparse) {
    config.categories = config.categories.map(cat=>({...cat,preview_deferred:true,has_images:true}));
    config.lazyCards={enabled:true,nonce:'lazy',token:'cards',wordsetId:77,total:16,loaded:1};
  }
  const body = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/core.css"><link rel="stylesheet" href="/catalog.css"><link rel="stylesheet" href="/levels.css">' +
    '<main class="ll-wordset-page" data-ll-wordset-page><div class="ll-wordset-grid-tools"><div class="ll-wordset-grid-search-tools"><div class="ll-wordset-progress-search ll-wordset-progress-search--wordset-page"><input class="ll-wordset-progress-search__input" aria-label="Search" placeholder="Search words or translations" type="search" data-ll-wordset-page-search><button class="ll-wordset-progress-search__clear" data-ll-wordset-page-search-clear hidden>Clear</button></div></div><button data-ll-wordset-select-all>Select all</button></div>' +
    '<section data-ll-wordset-levels hidden></section><div data-ll-wordset-main-grid class="ll-wordset-grid">' + (options.sparse ? catalog.slice(0,1) : catalog).map(existing).join('') +
    '</div><div data-ll-wordset-page-search-empty hidden>Empty</div></main><div id="ll-study-results-actions" style="display:none"><button id="ll-study-results-same-chunk"></button><button id="ll-study-results-different-chunk"></button><button id="ll-study-results-next-chunk"></button></div><script>window.llWordsetPageData='+JSON.stringify(config)+'</script>' +
    '<script src="/jquery.js"></script><script src="/loader.js"></script><script src="/levels.js"></script><script src="/main.js"></script>';
  await page.route(base+'/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const data = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      reqs.push(data);
      if(data.action === 'll_tools_wordset_page_lazy_cards' && data.category_ids) {
        const ids=data.category_ids.split(',').map(Number);
        const html=ids.map(id=>{
          const urls=options.serverPreviews?.[id] || ['/thumbnail.svg','/thumbnail-two.svg'];
          return existing(catalog.find(cat=>cat.id===id)).replace('</article>',
            urls.map(src=>'<span class="ll-wordset-preview-item--image"><img src="'+src+'" alt=""></span>').join('')+'</article>');
        }).join('');
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
        if (options.overlap) first.practice_activity={category_ids:[1,2],word_count:3,label:'2 words in progress'};
        const second = {...summaryRow,days:30,due:0,practice_category_id:0,next_category_id:options.exerciseOnly ? 0 : 15};
        if (options.secondSummaryModes) second.modes=options.secondSummaryModes;
        const totals=options.summary || {due:2,review:{reason:'due',count:2,due:2},levels:{1:first,2:second}};
        return route.fulfill({json:{success:true,data:totals}});
      }
      if (data.operation === 'interval') {
        summaryRow.days=Number(data.days);
        return route.fulfill({json:{success:true,data:{level:1,days:summaryRow.days}}});
      }
      if ((data.operation === 'launch' || data.operation === 'quiz') && options.successful) {
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
    if (url.pathname.startsWith('/thumbnail')) return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="17" fill="teal"/></svg>'});
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
  await page.addStyleTag({content:'.ll-wordset-card {min-height:150px!important;}'});
  const grid=page.locator('[data-ll-wordset-main-grid]');
  const search=page.getByRole('searchbox');
  const home=page.locator('[data-ll-wordset-page]');
  await expect(grid).toBeHidden();
  await expect(page.locator('[aria-current="step"],.ll-level-card.is-current')).toHaveCount(0);
  const guestLearn=page.getByRole('button',{name:'Learn · Lesson 1',exact:true});
  await expect(guestLearn).toHaveText('Lesson 1');
  await expect(guestLearn.locator(':scope > .ll-level-mode-icon:first-child')).toBeVisible();
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
  await expect(first.getByRole('img',{name:'Learned 3, In progress 2, New 5'})).toBeVisible();
  await expect(first.getByRole('img',{name:/Added since/})).toHaveCount(0);
  const localDue=first.locator('.ll-level-card-head').getByRole('button',{name:'Due for review (2) · Level 1'});
  await expect(localDue).toBeVisible();
  await expect(first).not.toContainText(/\b3\s*\/\s*10\s*learned\b/i);
  await expect(first.getByRole('button',{name:'Learn · Lesson 1'})).toBeVisible();
  await expect(page.locator('.ll-level-nudge,.is-suggested,.is-current')).toHaveCount(0);
  await expect(page.locator('.ll-level-review-all')).toHaveAccessibleName('Review now · Due for review (2) · Words this round · 2');
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
  await first.locator('.ll-level-overview').getByRole('button',{name:'Review now · Level 1'}).click();
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
  await expect(mixed).toHaveText('2 words in progress');
  expect((await learn.first().boundingBox()).width).toBeGreaterThanOrEqual(48);
  await expect(page.getByRole('button',{name:'Learn · Everyday Expressions'}).locator('.ll-level-thumb')).toHaveCount(1);
  for (const width of [1240,390,320]) {
    await page.setViewportSize({width,height:820});
    for (const name of ['Learn · Lesson 1','Practice · 2 words in progress']) {
      const button=page.getByRole('button',{name,exact:true});
      const mode=button.locator(':scope > .ll-level-mode-icon:first-child');
      const arrow=button.locator(':scope > .ll-level-glyph--forward:last-child');
      await expect(mode).toHaveAttribute('aria-hidden','true');
      await expect(arrow).toHaveAttribute('aria-hidden','true');
      await expect(arrow).toHaveAttribute('focusable','false');
      const firstPhoto=button.locator('.ll-level-thumb:visible').first();
      const lastPhoto=button.locator('.ll-level-thumb:visible').last();
      const [icon,first,last,label,go,control]=await Promise.all(
        [mode,firstPhoto,lastPhoto,button.locator('.ll-level-action-name'),arrow,button].map(node=>node.boundingBox())
      );
      expect(icon.x).toBeGreaterThanOrEqual(control.x);
      expect(first.x).toBeGreaterThan(icon.x+icon.width);
      expect(Math.abs(icon.width-first.width)).toBeLessThanOrEqual(2);
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
  await expect(review).toHaveAccessibleName('Review now · Due for review (681) · Words this round · 64');
  await expect(review.locator('img')).toHaveCount(0);
  await expect(review).toHaveClass(/ll-level-metric-older/);
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
  await expect(panel.getByRole('button',{name:'Review now · Level 1'})).toBeFocused();
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
  for (const [reason,status,text] of [['working','studied','In progress'],['hard','hard','Hard'],['starred','starred','Starred'],['least_recent','practice','Least recently studied']]) {
    summary.due=0;
    summary.review={reason,count:5,due:0};
    await page.evaluate(()=>window.jQuery(document).trigger('lltools:progress-updated'));
    await expect(review).toHaveAccessibleName('Practice · '+text+' · Words this round · 5');
    await expect(review).toHaveClass(new RegExp('ll-level-metric-'+status));
    await expect(review.locator('.ll-level-daily-icon')).toBeVisible();
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
  const tail=async()=>first.evaluate(node=>node.getBoundingClientRect().bottom-node.querySelector('summary').getBoundingClientRect().bottom);
  expect(await tail()).toBeLessThan(20);
  await expect(chevron).toHaveCSS('transform','none');
  const title=first.getByRole('button',{name:'Browse lessons · Level 1'});
  await title.hover();
  await expect(title).toHaveCSS('border-top-color','rgba(0, 0, 0, 0)');
  await expect(title.locator('svg')).toHaveCount(0);
  await details.focus();
  await page.keyboard.press('Enter');
  const moving = () => first.evaluate(card => card.querySelector('details').getAnimations({subtree:true})
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

test('a level opens and closes progressively and reverses from its current height',async({page})=>{
  await page.setViewportSize({width:390,height:800});
  await page.emulateMedia({reducedMotion:'no-preference'});
  await mount(page);
  const card=page.locator('[data-ll-level-card="1"]');
  const trace=await card.evaluate(async node=>{
    const summary=node.querySelector('summary');
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
  await expect(card.locator('details')).not.toHaveAttribute('open');
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
  await expect(page.locator('.ll-level-review-all')).toHaveAccessibleName('Review now · Due for review (2) · Words this round · 2');
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
      expect(await page.evaluate(()=>window.launchedQuizzes.at(-1))).toMatchObject({mode,scope:1,ownership:{2:ids}});
      if(mode==='gender') expect(await page.evaluate(()=>window.llToolsFlashcardsData.genderSessionPlan)).toMatchObject({level:2,word_ids:ids});
    }
    expect(requests.filter(req=>req.operation==='quiz').map(req=>[req.level,req.launch_mode,req.token])).toEqual(modes.map(mode=>['1',mode,'scope']));
    const second=page.locator('[data-ll-level-card="2"]');
    await second.getByLabel('Level details · Level 2',{exact:true}).click();
    await expect(second.locator('[data-ll-level-quiz-mode="gender"]')).toBeDisabled();
  });
}

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
    expect(reqs.filter(r=>r.operation==='launch')).toHaveLength(0);
    await launch.click();
    await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
    expect(reqs.filter(r=>r.operation==='launch').map(r=>[r.level,r.launch_mode])).toEqual([['1',filter]]);
    expect(await page.evaluate(()=>window.llToolsFlashcardsData.logicalSessionWordIds)).toEqual(ids);
  });
}

test('a daily round hydrates 16 exact targets in two batches and appends the second to the active quiz',async({page})=>{
  const ids=Array.from({length:16},(_,i)=>2001+i);
  const plan={category_ids:[2],session_word_ids:ids,word_category_ids:Object.fromEntries(ids.map(id=>[id,2]))};
  await instrumentQuiz(page);
  await page.addInitScript(()=>{
    window.appendedTargets=[];
    window.LLFlashcards={Main:{appendBoundedSelectionChunk(){
      window.appendedTargets.push(window.llToolsFlashcardsData.sessionWordIds.slice());
    }}};
  });
  const reqs=await mount(page,{signed:true,successful:true,plan});
  await page.locator('.ll-level-review-all').click();
  await expect.poll(()=>page.evaluate(()=>window.launchedQuizzes.length)).toBe(1);
  const hydrated=()=>reqs.filter(r=>r.action==='ll_get_words_by_category').map(r=>r.candidate_word_ids.split(',').map(Number));
  expect(hydrated()).toEqual([ids.slice(0,12)]);
  expect(await page.evaluate(()=>window.llToolsFlashcardsData.logicalSessionTotal)).toBe(16);
  await page.evaluate(()=>window.llToolsFlashcardsData.boundedSessionContinuation());
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

test('expired signed-in credentials leave browsing and a working reload available',async({page})=>{
  const reqs=await mount(page,{signed:true,expired:true});
  await expect(page.getByText('Reload this page to update your levels.')).toBeVisible();
  await expect(page.getByRole('button',{name:'Learn · Lesson 1'})).toHaveText('Lesson 1');
  await page.getByRole('button',{name:'Reload',exact:true}).click();
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
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
  await expect(page.locator('.ll-level-review-all')).toBeVisible();
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  expect(reads()).toBe(2);
  await page.clock.setSystemTime(new Date('2026-11-01T11:00:00Z'));
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  await expect.poll(reads).toBe(3);
});
