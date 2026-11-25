/* Shared mobile presentation. Authorization and data remain in the existing portal. */
(() => {
  'use strict';
  const root = document.getElementById('nasr-app');
  if (!root || window.NasrMobileUI) return;
  document.body.classList.add('nasr-mobile-ui');
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const paths = {
    home:'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    book:'<path d="M12 5v16M3 4c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2z"/>',
    report:'<path d="M5 3h10l4 4v14H5zM14 3v5h5M9 16v-3m3 3v-5m3 5v-2"/>',
    wallet:'<rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 8V5l14-3v3m4 6h-6v5h6m-3-2.5h.01"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    menu:'<circle cx="5" cy="5" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="19" cy="5" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="19" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="19" cy="19" r="1"/>',
    close:'<path d="m6 6 12 12M6 18 18 6"/>',
    arrow:'<path d="M19 12H5m6-6-6 6 6 6"/>',
    refresh:'<path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1"/>',
    layers:'<path d="m3 7 9-5 9 5-9 5zm0 5 9 5 9-5M3 17l9 5 9-5"/>',
    download:'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
    globe:'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
    eye:'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    lock:'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>'
  };
  const icon = name => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.book}</svg>`;
  window.NasrMobileUI = { icon };
  const mobile = matchMedia('(max-width: 720px)');
  let current = 'dashboard', menuObserver, returnFocus, menuWasOpen = false;
  const roleLabels = {manager:'مدیر آموزشگاه',secretary:'منشی',teacher:'معلم',student:'زبان‌آموز',family:'خانواده'};

  function closeMenu(restore = true) {
    root.querySelector('.nasr-sidebar')?.classList.remove('open');
    syncMenu();
    if (restore && returnFocus?.isConnected) returnFocus.focus({preventScroll:true});
  }
  function syncMenu() {
    const sidebar = root.querySelector('.nasr-sidebar');
    if (!sidebar) return;
    const open = mobile.matches && sidebar.classList.contains('open');
    document.body.classList.toggle('nasr-drawer-open', open);
    root.querySelectorAll('[data-action="menu"]').forEach(button => button.setAttribute('aria-expanded', String(open)));
    const backdrop = root.querySelector('.nasr-drawer-backdrop');
    if (backdrop) backdrop.hidden = !open;
    sidebar.inert = mobile.matches && !open;
    sidebar.setAttribute('aria-hidden', String(mobile.matches && !open));
    const workspace = root.querySelector('.nasr-workspace'), nav = root.querySelector('.nasr-bottom-nav');
    if (workspace) workspace.inert = open;
    if (nav) nav.inert = open;
    if (open && !menuWasOpen) root.querySelector('.nasr-drawer-close')?.focus({preventScroll:true});
    menuWasOpen = open;
  }
  function activeNav() {
    let matched = false;
    root.querySelectorAll('[data-mobile-nav]').forEach(link => {
      const active = link.dataset.mobileNav === current;
      matched ||= active;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current');
    });
    root.querySelector('.nasr-bottom-more')?.classList.toggle('active', !matched);
  }
  root.addEventListener('nasr:shell', event => {
    const b = event.detail.boot, assets = window.NasrConfig.assets;
    const topbar = root.querySelector('.nasr-topbar');
    topbar.innerHTML = `<div class="nasr-app-heading"><button class="nasr-icon-button nasr-mobile-menu" data-action="menu" aria-label="باز کردن منو" aria-controls="nasr-sidebar" aria-expanded="false">${icon('menu')}</button><a class="nasr-top-brand" href="#dashboard" aria-label="صفحه اصلی"><img src="${escape(assets)}nasr-logo.png" alt="نصر"></a><div><small lang="en">NASR ACADEMY</small><b id="nasr-view-label">نمای کلی</b></div></div><div class="nasr-app-heading-end"><span class="nasr-live-state" data-connected="true" role="status" aria-label="متصل به سرور" title="متصل به سرور"></span><span class="nasr-header-role">${escape(roleLabels[b.role])}</span><button class="nasr-header-profile" data-action="password" aria-label="حساب من و تغییر رمز عبور">${escape(b.name.trim().slice(0,1))}</button></div>`;
    const items = [['dashboard','خانه','home'],['classes','کلاس‌ها','book']];
    if (['manager','secretary'].includes(b.role)) items.push(['reports','گزارشات','report'],['payments','پرداخت‌ها','wallet']);
    else if (b.role === 'teacher') items.push(['my-salary','حقوق','wallet'],['account','حساب من','user']);
    else items.push(['payments','پرداخت‌ها','wallet'],['account','حساب من','user']);
    root.insertAdjacentHTML('beforeend', `<nav class="nasr-bottom-nav" aria-label="دسترسی سریع">${items.map(([route,label,ico])=>route==='account'?`<button type="button" data-action="password">${icon(ico)}<span>${label}</span></button>`:`<a href="#${route}" data-mobile-nav="${route}">${icon(ico)}<span>${label}</span></a>`).join('')}<button type="button" class="nasr-bottom-more" data-action="menu" aria-label="منوی بیشتر" aria-controls="nasr-sidebar" aria-expanded="false">${icon('menu')}<span>بیشتر</span></button></nav><button type="button" class="nasr-drawer-backdrop" data-mobile-close-menu aria-label="بستن منو" hidden></button>`);
    const sidebar = root.querySelector('.nasr-sidebar');
    sidebar.insertAdjacentHTML('afterbegin',`<button type="button" class="nasr-drawer-close nasr-icon-button" data-mobile-close-menu aria-label="بستن منو">${icon('close')}</button>`);
    menuObserver?.disconnect();
    menuObserver = new MutationObserver(syncMenu);
    menuObserver.observe(sidebar, {attributes:true,attributeFilter:['class']});
    syncMenu(); activeNav();
  });
  root.addEventListener('nasr:route', event => {
    current = event.detail.view;
    document.body.dataset.nasrView = current;
    closeMenu(false); activeNav();
  });
  root.addEventListener('nasr:rendered', event => {
    const hero = root.querySelector('.nasr-welcome');
    if (hero) {
      const b = event.detail.boot;
      const today = new Intl.DateTimeFormat('fa-IR', {weekday:'long',day:'numeric',month:'long',timeZone:'Asia/Tehran'}).format(new Date());
      hero.innerHTML = `<div class="nasr-hero-copy"><div class="nasr-hero-topline"><span class="nasr-hero-label">فضای آموزشی شما</span><span class="nasr-hero-date">${escape(today)}</span></div><h1>سلام، ${escape(b.name)}</h1><p>${b.role==='teacher'?'هر کلاس، شروع یک تأثیر تازه.':b.role==='manager'||b.role==='secretary'?'آموزشگاه شما، در یک نگاه.':'یادگیری، هر روز یک قدم جلوتر.'}</p><a class="nasr-hero-cta" href="#classes">${b.role==='manager'||b.role==='secretary'?'مشاهده کلاس‌ها':'کلاس‌های من'}${icon('arrow')}</a></div><div class="nasr-hero-art" aria-hidden="true"><div class="nasr-orbit-ring"></div><div class="nasr-glass-book"><span>Aa</span><small>LEARN · GROW</small></div><span class="nasr-art-chip">${icon('book')}</span><span class="nasr-art-spark">✧</span></div>`;
    }
    if(!event.detail.background)window.scrollTo({top:0,behavior:'instant'});
  });
  root.addEventListener('click', event => {
    if (event.target.closest('[data-mobile-close-menu]')) { event.preventDefault(); closeMenu(); }
    const trigger = event.target.closest('[data-action="menu"]');
    if (trigger && mobile.matches) {
      returnFocus = trigger;
    }
    const toggle = event.target.closest('[data-password-toggle]');
    if (toggle) {
      const input = root.querySelector('input[name="password"]'), visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      toggle.setAttribute('aria-pressed',String(visible));
      toggle.setAttribute('aria-label',visible?'پنهان کردن رمز عبور':'نمایش رمز عبور');
    }
  });
  document.addEventListener('keydown', event => {
    if (document.querySelector('dialog[open]')) return;
    const sidebar = root.querySelector('.nasr-sidebar.open');
    if (!mobile.matches || !sidebar) return;
    if (event.key === 'Escape') { event.preventDefault(); closeMenu(); }
    if (event.key === 'Tab') {
      const items = [...sidebar.querySelectorAll('a[href],button,input,select,summary,[tabindex="0"]')].filter(el=>!el.disabled && el.getClientRects().length && getComputedStyle(el).visibility!=='hidden');
      const first = items[0], last = items.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  mobile.addEventListener('change', () => closeMenu(false));
  function keyboardState() {
    const editing = document.activeElement?.matches('input:not([type=checkbox]):not([type=radio]),textarea');
    const viewport = window.visualViewport;
    document.body.classList.toggle('nasr-keyboard-open',!!(editing && viewport && innerHeight - viewport.height > 140));
  }
  window.visualViewport?.addEventListener('resize',keyboardState);
  window.addEventListener('nasr:connection',event=>root.querySelectorAll('.nasr-live-state').forEach(el=>{el.dataset.connected=String(event.detail.connected);el.setAttribute('aria-label',event.detail.connected?'متصل به سرور':'ارتباط با سرور قطع است');el.title=el.getAttribute('aria-label');}));
  document.addEventListener('focusin',keyboardState);
  document.addEventListener('focusout',()=>requestAnimationFrame(keyboardState));
})();
