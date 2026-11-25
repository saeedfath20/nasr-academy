/* Independent application presentation; the WordPress website never loads this file. */
(()=>{
  'use strict';
  const root=document.getElementById('nasr-app');
  const h=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const paths={
    home:'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    book:'<path d="M12 5v16M3 4c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2z"/>',
    report:'<path d="M5 3h10l4 4v14H5zM14 3v5h5M9 16v-3m3 3v-5m3 5v-2"/>',
    wallet:'<rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 8V5l14-3v3m4 6h-6v5h6m-3-2.5h.01"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    menu:'<rect x="3" y="3" width="6" height="6" rx="2"/><rect x="15" y="3" width="6" height="6" rx="2"/><rect x="3" y="15" width="6" height="6" rx="2"/><rect x="15" y="15" width="6" height="6" rx="2"/>',
    close:'<path d="m6 6 12 12M6 18 18 6"/>',arrow:'<path d="M19 12H5m6-6-6 6 6 6"/>',
    eye:'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    lock:'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
    calendar:'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-14 4h2m4 0h2"/>',
    clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    fingerprint:'<path d="M5 10a7 7 0 0 1 14 0v3m-11 8c3-4 1-8 2-11a2 2 0 0 1 4 0v4c0 3-1 5-2 7M5 13v2l-1 3m13-7v3c0 3-1 6-2 8M7 18l1-6a4 4 0 0 1 8 0m-4 0v4m7 0-1 4"/>',
    face:'<path d="M3 8V4a1 1 0 0 1 1-1h4m8 0h4a1 1 0 0 1 1 1v4m0 8v4a1 1 0 0 1-1 1h-4m-8 0H4a1 1 0 0 1-1-1v-4M8 9v1m8-1v1m-4-1v4h-1m-3 3c2 2 6 2 8 0"/>',
    spark:'<path d="m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3z"/>'
  };
  const icon=name=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name]||paths.book}</svg>`;
  const n=value=>new Intl.NumberFormat('fa-IR').format(Number(value)||0);
  const orbit=ico=>`<div class="app-auth-orbit" aria-hidden="true"><span class="app-auth-halo"></span><span class="app-auth-ring"></span><span class="app-auth-symbol">${icon(ico)}</span></div>`;
  const loading=text=>`<div class="app-loading" role="status" aria-live="polite" aria-busy="true">${orbit('spark')}<p>${h(text)}</p><span class="app-loading-dots" aria-hidden="true"><i></i><i></i><i></i></span></div>`;
  window.NasrMobileUI={icon,escape:h,orbit,loading};
  const roles={manager:'مدیر آموزشگاه',secretary:'منشی',teacher:'معلم',student:'زبان‌آموز',family:'خانواده'};
  let current='dashboard',boot=null,returnFocus=null,observer=null,opened=false;
  function menuSync(){
    const sidebar=root.querySelector('.nasr-sidebar');if(!sidebar)return;
    const open=sidebar.classList.contains('open');
    document.body.classList.toggle('nasr-drawer-open',open);sidebar.inert=!open;sidebar.setAttribute('aria-hidden',String(!open));
    root.querySelector('.nasr-workspace').inert=open;
    const bottom=root.querySelector('.nasr-bottom-nav');if(bottom)bottom.inert=open;
    const backdrop=root.querySelector('.nasr-drawer-backdrop');if(backdrop)backdrop.hidden=!open;
    root.querySelectorAll('[data-action=menu]').forEach(b=>b.setAttribute('aria-expanded',String(open)));
    if(open&&!opened)root.querySelector('.nasr-drawer-close')?.focus({preventScroll:true});opened=open;
  }
  function closeMenu(restore=true){root.querySelector('.nasr-sidebar')?.classList.remove('open');menuSync();if(restore&&returnFocus?.isConnected)returnFocus.focus({preventScroll:true});}
  function navSync(){root.querySelectorAll('[data-mobile-nav]').forEach(link=>{const active=link.dataset.mobileNav===current;link.classList.toggle('active',active);if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});}
  const dateLabel=()=>new Intl.DateTimeFormat('fa-IR',{weekday:'long',day:'numeric',month:'long',timeZone:'Asia/Tehran'}).format(new Date());
  const classTile=(c,index,{date,classSchedule},compact=false)=>{const schedule=classSchedule(c);return `<a href="#classes/${h(c.id)}" class="app-course ${compact?'app-course-compact':''} tone-${index%3}"><div class="app-course-cover"><span class="app-course-glyph" aria-hidden="true">${icon('book')}</span><span class="app-course-badge">${+c.active?'کلاس فعال':'بایگانی'}</span><span class="app-course-letter" aria-hidden="true">Aa</span></div><div class="app-course-body"><small>${h(c.term_name)}</small><h3 dir="auto">${h(c.name)}</h3><p>${icon('user')}<span>${h(c.teacher_name)}</span></p>${schedule&&!schedule.includes('مشخص کنید')?`<p class="app-schedule">${icon('clock')}<span>${h(schedule)}</span></p>`:''}${!compact?`<div class="app-course-dates"><span>شروع <b>${h(date(c.start_date))}</b></span><span>پایان <b>${h(date(c.end_date))}</b></span></div>`:''}<div class="app-course-footer"><span>${c.student_count!==undefined&&c.student_count!==null?`${n(c.student_count)} زبان‌آموز`:'جزئیات و کارنامه'}</span><span class="app-course-open">${icon('arrow')}</span></div></div></a>`;};
  window.NasrAppViews={
    dashboard(data){
      const b=data.boot,manage=['manager','secretary'].includes(b.role),teacher=b.role==='teacher',student=['student','family'].includes(b.role);
      const cards=data.classes.filter(c=>+c.active).slice(0,4);
      const quick=manage?[['classes','کلاس‌ها','book'],['people/student','زبان‌آموزان','user'],['reports','گزارشات','report']]:teacher?[['classes','کلاس‌های من','book'],['my-salary','حقوق من','wallet'],['classes','ثبت نمرات','report']]:[['classes','کلاس‌های من','book'],['classes','کارنامه','report'],['payments','پرداخت‌ها','wallet']];
      return `<div class="app-home-title"><span>${h(dateLabel())}</span><h1>سلام، ${h(b.name)} <span aria-hidden="true">✦</span></h1><p>${manage?'امروز آموزشگاه را از همین‌جا مدیریت کن.':teacher?'یک روز تازه برای آموزش و الهام‌بخشی.':'برای قدم بعدی یادگیری آماده‌ای؟'}</p></div><section class="app-learning-banner"><div><span class="app-banner-label">${manage?'آموزشگاه من':teacher?'مسیر آموزش تو':'مسیر یادگیری تو'}</span><h2>${manage?'آموزش، با نظم بیشتر':teacher?'تأثیر تو، آیندهٔ آن‌هاست':'دنیای تو، بزرگ‌تر می‌شود'}</h2><a href="#classes">${manage?'مدیریت کلاس‌ها':'بریم سراغ کلاس‌ها'}${icon('arrow')}</a></div><div class="app-banner-art" aria-hidden="true"><span class="app-banner-orbit"></span><span class="app-banner-book">Aa<small>NASR</small></span><i>✧</i></div></section><nav class="app-shortcuts" aria-label="دسترسی‌های آموزشی">${quick.map(([route,label,ico],i)=>`<a href="#${route}" class="shortcut-${i}"><span>${icon(ico)}</span><b>${label}</b></a>`).join('')}</nav><div class="app-mini-stats"><div>${icon('book')}<span>کلاس‌های فعال<strong>${n(b.stats.classes)}</strong></span></div>${manage?`<div>${icon('user')}<span>زبان‌آموزان<strong>${n(b.stats.students)}</strong></span></div>`:student?`<div>${icon('wallet')}<span>ماندهٔ شهریه<strong>${data.money(b.stats.due)}</strong></span></div>`:`<div>${icon('calendar')}<span>نقش شما<strong>معلم نصر</strong></span></div>`}</div><section class="app-courses-section"><div class="app-section-heading"><h2>${manage?'کلاس‌های آموزشگاه':'کلاس‌های من'}</h2><a href="#classes">همهٔ کلاس‌ها${icon('arrow')}</a></div>${cards.length?`<div class="app-course-carousel">${cards.map((c,i)=>classTile(c,i,data,true)).join('')}</div>`:'<div class="nasr-empty"><h3>کلاس فعالی ثبت نشده است</h3></div>'}</section><section class="app-agenda"><div class="app-section-heading"><h2>آزمون‌های پیش رو</h2>${icon('calendar')}</div>${data.upcoming.length?data.upcoming.map(x=>`<a href="#classes/${h(x.id)}" class="app-event"><span class="app-event-icon">${icon('calendar')}</span><div><strong>${h(x.label)}</strong><small dir="auto">${h(x.name)}</small></div><span class="app-event-date">${h(data.date(x.date))}</span></a>`).join(''):'<p class="app-muted">در این کلاس‌ها آزمون پیش رو ثبت نشده است.</p>'}</section>${b.role==='family'&&b.children?.length?`<section class="app-agenda"><h2>زبان‌آموزان من</h2>${b.children.map(p=>`<p>${h(p.first_name)} ${h(p.last_name)}</p>`).join('')}</section>`:''}`;
    },
    schedule(data){
      return `<div class="app-page-heading"><span lang="en">CLASS SCHEDULE</span><h1>برنامه کلاس‌ها</h1></div><section class="app-term-picker">${icon('calendar')}${data.termFilter}</section>${data.res?`<section class="app-classes-content">${data.search}<div class="app-class-count">${n(data.res.total)} کلاس در این ترم</div><div class="app-schedule-list">${data.res.items.map(c=>`<a class="app-schedule-card" href="#classes/${h(c.id)}"><span class="app-schedule-icon">${icon('calendar')}</span><div><h2 dir="auto">${h(c.name)}</h2><p class="app-schedule-time">${icon('clock')}<span>${h(data.classSchedule(c))}</span></p><p>${icon('user')}<span>${h(c.teacher_name)}</span></p><div class="app-schedule-dates"><span>شروع <b>${h(data.date(c.start_date))}</b></span><span>پایان <b>${h(data.date(c.end_date))}</b></span></div></div>${icon('arrow')}</a>`).join('')}</div>${data.res.items.length?'':'<div class="nasr-empty"><h3>کلاسی پیدا نشد</h3></div>'}${data.pager}</section>`:`<div class="app-term-empty">${icon('calendar')}<h2>برنامه این ترم</h2><p>ترم را انتخاب کن تا زمان‌بندی کلاس‌ها نمایش داده شود.</p></div>`}`;
    },
    classes(data){
      return `<div class="app-page-heading"><span lang="en">MY LEARNING SPACE</span><h1>${data.canManage?'کلاس‌های آموزشگاه':'کلاس‌های من'}</h1>${data.canManage?`<div class="nasr-actions">${data.button('تعریف کلاس','new-class','','primary','plus')}${data.button('تعریف ترم','new-term','','soft','plus')}${data.res?data.button('افزودن گروهی','bulk-enroll','','soft','people'):''}</div>`:''}</div><section class="app-term-picker">${icon('calendar')}${data.termFilter}</section>${data.res?`<section class="app-classes-content">${data.search}<div class="app-class-count">${n(data.res.total)} کلاس در این ترم</div><div class="app-course-grid">${data.res.items.map((c,i)=>classTile(c,i,data)).join('')}</div>${data.res.items.length?'':'<div class="nasr-empty"><h3>کلاسی پیدا نشد</h3></div>'}${data.pager}</section>`:`<div class="app-term-empty">${icon('book')}<h2>این ترم، چه چیزی یاد می‌گیری؟</h2><p>ترم را انتخاب کن تا کلاس‌هایت نمایش داده شوند.</p></div>`}`;
    }
  };
  function independentAppearance(){root.removeAttribute('style');document.body.style.removeProperty('background');root.dataset.density='comfortable';}
  root.addEventListener('nasr:shell',event=>{
    independentAppearance();
    boot=event.detail.boot;
    root.querySelector('.nasr-topbar').innerHTML=`<div class="app-top-brand"><img src="${h(window.NasrConfig.assets)}nasr-logo.png" alt="نصر"><div><small lang="en">NASR ACADEMY</small><b id="nasr-view-label">خانه</b></div></div><div class="app-top-actions"><span class="nasr-live-state" data-connected="true" role="status" aria-label="متصل به سرور"></span><button class="app-header-avatar" data-app-account aria-label="حساب من">${h(boot.name.trim().slice(0,1))}</button></div>`;
    const items=[['dashboard','خانه','home'],['classes','کلاس‌ها','book']];
    items.push(boot.role==='teacher'?['my-salary','حقوق','wallet']:['manager','secretary'].includes(boot.role)?['reports','گزارشات','report']:['payments','پرداخت‌ها','wallet']);
    root.insertAdjacentHTML('beforeend',`<nav class="nasr-bottom-nav" aria-label="ناوبری برنامه">${items.map(([route,label,ico])=>`<a href="#${route}" data-mobile-nav="${route}">${icon(ico)}<span>${label}</span></a>`).join('')}<button type="button" class="nasr-bottom-more" data-action="menu" aria-controls="nasr-sidebar" aria-expanded="false">${icon('menu')}<span>بیشتر</span></button></nav><button class="nasr-drawer-backdrop" data-mobile-close-menu aria-label="بستن منو" hidden></button>`);
    const sidebar=root.querySelector('.nasr-sidebar');
    sidebar.querySelector('.nasr-nav-label')?.remove();
    const account=sidebar.querySelector('.nasr-account');
    account?.removeAttribute('data-action');account?.setAttribute('data-app-account','');
    sidebar.querySelector('.nasr-logout')?.remove();
    sidebar.insertAdjacentHTML('afterbegin',`<button type="button" class="nasr-drawer-close" data-mobile-close-menu aria-label="بستن منو">${icon('close')}</button>`);
    sidebar.insertAdjacentHTML('beforeend',`<small class="app-menu-build">${h(roles[boot.role])} · <bdi>${h(window.NasrAppConfig.version)}</bdi></small>`);
    observer?.disconnect();observer=new MutationObserver(menuSync);observer.observe(sidebar,{attributes:true,attributeFilter:['class']});menuSync();navSync();
  });
  root.addEventListener('nasr:route',event=>{current=event.detail.view;document.body.dataset.nasrView=current;document.getElementById('nasr-view-label').textContent=current==='dashboard'?'خانه':event.detail.title;closeMenu(false);navSync();});
  root.addEventListener('nasr:rendered',event=>{independentAppearance();if(!event.detail.background)window.scrollTo({top:0,behavior:'instant'});});
  root.addEventListener('click',event=>{
    if(event.target.closest('[data-mobile-close-menu]')){event.preventDefault();closeMenu();}
    const trigger=event.target.closest('[data-action=menu]');if(trigger)returnFocus=trigger;
    if(event.target.closest('[data-app-account]')){closeMenu(false);window.NasrAppAuth.showAccount();}
    const toggle=event.target.closest('[data-password-toggle]');
    if(toggle){const input=root.querySelector('input[name=password]'),visible=input.type==='password';input.type=visible?'text':'password';toggle.setAttribute('aria-pressed',String(visible));toggle.setAttribute('aria-label',visible?'پنهان کردن رمز عبور':'نمایش رمز عبور');}
  });
  document.addEventListener('keydown',event=>{
    if(document.querySelector('dialog[open]')||!root.querySelector('.nasr-sidebar.open'))return;
    if(event.key==='Escape'){event.preventDefault();closeMenu();}
    if(event.key==='Tab'){
      const items=[...root.querySelector('.nasr-sidebar').querySelectorAll('a[href],button,input,select,summary')].filter(el=>!el.disabled&&el.getClientRects().length);
      if(event.shiftKey&&document.activeElement===items[0]){event.preventDefault();items.at(-1)?.focus();}
      else if(!event.shiftKey&&document.activeElement===items.at(-1)){event.preventDefault();items[0]?.focus();}
    }
  });
  function keyboardState(){
    document.documentElement.style.setProperty('--app-viewport-height',`${window.visualViewport?.height||innerHeight}px`);
    document.body.classList.toggle('nasr-keyboard-open',!!(document.activeElement?.matches('input:not([type=checkbox]),textarea')&&window.visualViewport&&innerHeight-visualViewport.height>140));
  }
  keyboardState();
  window.visualViewport?.addEventListener('resize',keyboardState);document.addEventListener('focusin',keyboardState);document.addEventListener('focusout',()=>requestAnimationFrame(keyboardState));
  window.addEventListener('nasr:connection',event=>root.querySelectorAll('.nasr-live-state').forEach(el=>{el.dataset.connected=String(event.detail.connected);el.setAttribute('aria-label',event.detail.connected?'متصل به سرور':'ارتباط با سرور قطع است');}));
})();
