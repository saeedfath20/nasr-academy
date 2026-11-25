/* Nasr Academy — no external requests, client-side secrets or local storage of student records. */
(() => {
  'use strict';
  const root = document.getElementById('nasr-app');
  if (!root || typeof NasrConfig === 'undefined') return;
  if (root.dataset.nasrInitialized === '1') return;
  root.dataset.nasrInitialized = '1';
  const C = NasrConfig;
  const S = { boot: null, page: 1, q: '', detail: null, terms: [], people: [], busy: false, branchId: '', reportFilters: {}, report: null };
  const roleNames = { manager: 'مدیریت آموزشگاه', secretary: 'پنل منشی', teacher: 'پنل معلم', student: 'پنل زبان‌آموز', family: 'پنل خانواده' };
  const personNames = { teacher: 'معلم', student: 'زبان‌آموز', family: 'خانواده', manager: 'مدیر آموزشگاه', secretary: 'منشی' };
  const gradeNames = { listening: 'Listening', speaking: 'Speaking', reading: 'Reading', writing: 'Writing', midterm: 'Midterm', final_exam: 'Final Exam' };
  const methodNames = { cash: 'نقدی', card: 'کارت‌خوان', transfer: 'انتقال بانکی', manual: 'ثبت دستی', zarinpal: 'زرین‌پال', digipay: 'دیجی‌پی', snapppay: 'اسنپ‌پی' };
  const classDays = [['friday','جمعه'],['saturday','شنبه'],['sunday','یکشنبه'],['monday','دوشنبه'],['tuesday','سه‌شنبه'],['wednesday','چهارشنبه'],['thursday','پنجشنبه']];
  const classDayNames = Object.fromEntries(classDays);
  const icons = {
    dashboard: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    book: '<path d="M12 5v15M3 4c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2z"/>',
    people: '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m3 10v-2a6 6 0 0 0-3-5"/>',
    teacher: '<path d="m2 8 10-5 10 5-10 5zM6 11v5c3 3 9 3 12 0v-5M22 8v8"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-14 4h2m4 0h2"/>',
    wallet: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 8V5c0-1 1-2 2-2h12v2m4 6h-6v6h6m-3-3h.01"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="m9 3 6 0 1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>', arrow: '<path d="M19 12H5m6-6-6 6 6 6"/>',
    logout: '<path d="M9 4H4v16h5m5-4 4-4-4-4m-6 4h13"/>',
    check: '<path d="m5 12 4 4L19 6"/>', search: '<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>', menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    edit: '<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-4-4L5 15z"/>',
    print: '<path d="M7 8V3h10v5M7 17H3V9h18v8h-4M7 14h10v7H7z"/>',
  };
  const icon = (name) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.book}</svg>`;
  const h = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const digits = v => String(v ?? '').replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/٫/g,'.');
  const num = v => new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 }).format(Number(v) || 0);
  const numEN = v => new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 }).format(Number(v) || 0);
  const dateEN = v => v && !Number.isNaN(dateObj(v).getTime()) ? new Intl.DateTimeFormat('en-GB', {year:'numeric',month:'short',day:'numeric',timeZone:'Asia/Tehran'}).format(dateObj(v)) : 'Not set';
  const money = v => `${num(v)} <small>${h(S.boot.settings.currency)}</small>`;
  const fullName = p => `${p.first_name} ${p.last_name}`;
  const learnerMeta = p => p.kind==='teacher' ? `معلم${p.student_code?` · کد ${num(p.student_code)}`:''}` : `کد ${num(p.student_code)}`;
  const toPersianDigits = v => String(v).replace(/[0-9]/g,c=>'۰۱۲۳۴۵۶۷۸۹'[c]);
  const twoDigits = v => String(v).padStart(2,'0');
  const durationOptions=Array.from({length:32},(_,i)=>{const minutes=(i+1)*15;return [String(minutes),`${num(minutes)} دقیقه`];});
  const scheduleDays=c=>{
    const raw=c?.class_days;
    const values=Array.isArray(raw)?raw:typeof raw==='string' && raw?raw.split(','):[c?.class_day];
    return classDays.map(([value])=>value).filter(value=>values.includes(value));
  };
  const normaliseClassTime=value=>{
    let text=digits(value).trim();
    if(/^\d{4}$/.test(text))text=text.slice(0,2)+':'+text.slice(2);
    const match=text.match(/^(\d{1,2}):(\d{2})$/);
    return match && +match[1]<24 && +match[2]<60 ? `${twoDigits(match[1])}:${twoDigits(match[2])}` : '';
  };
  const educationList = () => {
    const raw=S.boot?.settings?.education_options, values=Array.isArray(raw)?raw:String(raw || '').split(/\r?\n/);
    return [...new Set(values.map(v=>String(v).trim()).filter(Boolean))];
  };
  const classSchedule=c=>{
    const days=scheduleDays(c),time=normaliseClassTime(c?.start_time),duration=Number(c?.duration);
    if(!days.length || !time || !duration)return c?.schedule || 'روزهای کلاس و ساعت شروع را مشخص کنید';
    const [hour,minute]=time.split(':').map(Number),start=hour*60+minute,end=start+duration;
    const clock=minutes=>toPersianDigits(`${twoDigits(Math.floor(minutes/60)%24)}:${twoDigits(minutes%60)}`);
    return `${days.map(day=>classDayNames[day]).join(' و ')}، ${clock(start)} تا ${clock(end)} · ${num(duration)} دقیقه${end>=1440?' · روز بعد':''}`;
  };
  const isManager = () => S.boot.role === 'manager';
  const canManage = () => ['manager','secretary'].includes(S.boot.role);
  const isStaff = () => ['manager','secretary','teacher'].includes(S.boot.role);
  const hasStudentAccess = () => ['student','family'].includes(S.boot.role);
  const canViewPayments = () => canManage() || hasStudentAccess();
  const dateObj = v => new Date(String(v).length === 10 ? `${v}T12:00:00Z` : `${String(v).replace(' ','T')}Z`);
  const date = (v, time = false) => {
    if (!v) return 'تعیین نشده';
    const d = dateObj(v);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(S.boot.settings.calendar === 'jalali' ? 'fa-IR-u-ca-persian' : 'fa-IR-u-ca-gregory', { year:'numeric', month:'short', day:'numeric', timeZone:'Asia/Tehran', ...(time ? {hour:'2-digit',minute:'2-digit'} : {}) }).format(d);
  };
  const dateInput = v => {
    if (!v) return '';
    const parts = new Intl.DateTimeFormat(S.boot.settings.calendar === 'jalali' ? 'en-u-ca-persian' : 'en-u-ca-gregory', {year:'numeric', month:'2-digit',day:'2-digit',timeZone:'Asia/Tehran'}).formatToParts(dateObj(v));
    return ['year','month','day'].map(k=>parts.find(p=>p.type===k)?.value || '').join('/');
  };
  const persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const weekDays = ['ش','ی','د','س','چ','پ','ج'];
  function jalaliToGregorian(jy,jm,jd) {
    jy += 1595;
    let days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd;
    days += jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186;
    let gy = 400 * Math.floor(days / 146097); days %= 146097;
    if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    let gd = days + 1; const months = [0,31,((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28,31,30,31,30,31,31,30,31,30,31]; let gm = 1;
    for (; gm <= 12 && gd > months[gm]; gm++) gd -= months[gm];
    return [gy,gm,gd];
  }
  function gregorianToJalali(gy,gm,gd) {
    const sum=[0,31,59,90,120,151,181,212,243,273,304,334],gy2=gm>2?gy+1:gy;
    let days=355666+365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+sum[gm-1];
    let jy=-1595+33*Math.floor(days/12053);days%=12053;jy+=4*Math.floor(days/1461);days%=1461;
    if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
    let jm,jd;if(days<186){jm=1+Math.floor(days/31);jd=1+(days%31);}else{jm=7+Math.floor((days-186)/30);jd=1+((days-186)%30);}
    return [jy,jm,jd];
  }
  function calendarToday() {
    const calendar = S.boot.settings.calendar === 'gregorian' ? 'gregory' : 'persian';
    const parts = new Intl.DateTimeFormat('en-u-ca-'+calendar,{year:'numeric',month:'numeric',day:'numeric',timeZone:'Asia/Tehran'}).formatToParts(new Date());
    return ['year','month','day'].map(k=>Number(parts.find(p=>p.type===k)?.value || 1));
  }
  function calendarDays(year,month) {
    if (S.boot.settings.calendar === 'gregorian') return new Date(Date.UTC(year,month,0)).getUTCDate();
    if (month <= 6) return 31; if (month <= 11) return 30;
    const g = jalaliToGregorian(year,12,30); return gregorianToJalali(...g)[1] === 12 && gregorianToJalali(...g)[2] === 30 ? 30 : 29;
  }
  function calendarWeekOffset(year,month,day=1) {
    const g = S.boot.settings.calendar === 'gregorian' ? [year,month,day] : jalaliToGregorian(year,month,day);
    return (new Date(Date.UTC(g[0],g[1]-1,g[2],12)).getUTCDay()+1)%7;
  }
  function calendarBounds(input) {
    const gregorian=S.boot.settings.calendar==='gregorian';
    return {
      min:input?.hasAttribute('data-term-date') ? (gregorian?[2020,3,20]:[1399,1,1]) : [gregorian?1800:1200,1,1],
      max:[gregorian?2200:1499,12,gregorian?31:calendarDays(1499,12)]
    };
  }
  const compareDateParts=(a,b)=>a[0]-b[0] || a[1]-b[1] || a[2]-b[2];
  function calendarAllowed(parts,input) {
    const b=calendarBounds(input);
    return compareDateParts(parts,b.min)>=0 && compareDateParts(parts,b.max)<=0;
  }
  function calendarParts(input) {
    const raw=digits(input.value || '').trim().replace(/-/g,'/');
    let p=raw.split('/').map(Number);
    if (!(p.length===3 && p.every(Number.isFinite) && p[0]>0 && p[1]>=1 && p[1]<=12 && p[2]>=1 && p[2]<=calendarDays(p[0],p[1]))) p=calendarToday();
    const b=calendarBounds(input);
    if(compareDateParts(p,b.min)<0) p=b.min;
    if(compareDateParts(p,b.max)>0) p=b.max;
    return {year:p[0],month:p[1],day:p[2]};
  }
  let calendarState=null;
  function closeCalendar() {
    if(!calendarState)return;
    calendarState.input.setAttribute('aria-expanded','false');
    calendarState.el.remove();calendarState=null;
  }
  function positionCalendar() {
    if(!calendarState?.el?.isConnected)return;
    const r=calendarState.input.getBoundingClientRect(),el=calendarState.el;
    const view=window.visualViewport, top=view?.offsetTop || 0, height=view?.height || window.innerHeight;
    el.style.maxHeight=Math.max(100,height-16)+'px';
    const w=el.offsetWidth || 282,hgt=el.offsetHeight || 330;
    el.style.left=Math.min(Math.max(8,r.left),Math.max(8,window.innerWidth-w-8))+'px';
    const below=r.bottom+8, above=r.top-hgt-8;
    el.style.top=Math.max(top+8,Math.min(top+height-hgt-8,below+hgt<=top+height-8?below:above))+'px';
  }
  function calendarMonthName(month) {
    return S.boot.settings.calendar==='gregorian' ? new Intl.DateTimeFormat('fa-IR-u-ca-gregory',{month:'long',timeZone:'UTC'}).format(new Date(Date.UTC(2020,month-1,1))) : persianMonths[month-1];
  }
  function calendarYearOptions(year,input=calendarState?.input) {
    const b=calendarBounds(input),options=[];
    for(let value=b.min[0];value<=b.max[0];value++)options.push(`<option value="${value}" ${value===year?'selected':''}>${toPersianDigits(value)}</option>`);
    return options.join('');
  }
  function renderCalendar() {
    if(!calendarState)return;
    const c=calendarState,days=calendarDays(c.year,c.month),offset=calendarWeekOffset(c.year,c.month),now=calendarToday(),b=calendarBounds(c.input);
    // Keep the native select nodes alive. Replacing them while their OS popup is
    // open interrupts selection and causes a visible open/close flicker.
    if(!c.el.querySelector('.nasr-calendar-grid')) {
      c.el.innerHTML=`<div class="nasr-calendar-head"><button type="button" data-calendar-action="prev" aria-label="ماه قبل">‹</button><div class="nasr-calendar-selects"><select data-calendar-part="month" aria-label="انتخاب ماه">${Array.from({length:12},(_,i)=>`<option value="${i+1}">${h(calendarMonthName(i+1))}</option>`).join('')}</select><select data-calendar-part="year" aria-label="انتخاب سال">${calendarYearOptions(c.year,c.input)}</select></div><button type="button" data-calendar-action="next" aria-label="ماه بعد">›</button></div><div class="nasr-calendar-grid"></div><div class="nasr-calendar-foot"><button type="button" data-calendar-action="today">امروز</button><button type="button" data-calendar-action="clear">پاک کردن</button></div>`;
    }
    c.el.querySelector('[data-calendar-part="month"]').value=String(c.month);
    c.el.querySelector('[data-calendar-part="year"]').value=String(c.year);
    c.el.querySelector('[data-calendar-action="prev"]').disabled=compareDateParts([c.year,c.month,1],[b.min[0],b.min[1],1])<=0;
    c.el.querySelector('[data-calendar-action="next"]').disabled=compareDateParts([c.year,c.month,1],[b.max[0],b.max[1],1])>=0;
    c.el.querySelector('[data-calendar-action="today"]').disabled=!calendarAllowed(now,c.input);
    let cells=weekDays.map(x=>`<span class="nasr-calendar-weekday">${x}</span>`).join('');
    for(let i=0;i<offset;i++)cells+='<span class="nasr-calendar-empty"></span>';
    for(let day=1;day<=days;day++) {
      const today=day===now[2] && c.month===now[1] && c.year===now[0];
      cells+=`<button type="button" class="nasr-calendar-day ${day===c.day?'selected':''} ${today?'today':''}" data-calendar-day="${day}" ${calendarAllowed([c.year,c.month,day],c.input)?'':'disabled'}>${num(day)}</button>`;
    }
    c.el.querySelector('.nasr-calendar-grid').innerHTML=cells;
    positionCalendar();
  }
  function openCalendar(input) {
    if(!input.matches('[data-calendar-field]'))return;
    if(calendarState?.input===input){positionCalendar();return;}
    closeCalendar();const p=calendarParts(input),el=document.createElement('div');
    el.id='nasr-calendar';el.className='nasr-calendar';el.setAttribute('role','dialog');el.setAttribute('aria-label','تقویم انتخاب تاریخ');
    (input.closest('dialog') || root).append(el);
    calendarState={input,el,...p};input.setAttribute('aria-expanded','true');renderCalendar();
  }
  function calendarValue(parts) {
    const value=`${parts[0]}/${String(parts[1]).padStart(2,'0')}/${String(parts[2]).padStart(2,'0')}`;
    return S.boot.settings.calendar === 'gregorian' ? value : toPersianDigits(value);
  }
  function setCalendarValue(input,parts) {
    if(!calendarAllowed(parts,input))return;
    input.value=calendarValue(parts);
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.dispatchEvent(new Event('change',{bubbles:true}));
  }
  const today = () => {
    const parts = new Intl.DateTimeFormat('en',{year:'numeric',month:'2-digit',day:'2-digit',timeZone:'Asia/Tehran'}).formatToParts(new Date());
    return ['year','month','day'].map(k=>parts.find(p=>p.type===k).value).join('-');
  };
  const badge = (v, label) => `<span class="nasr-badge ${h(v)}">${h(label)}</span>`;
  const payBadge = p => p.voided_at ? badge('neutral','باطل‌شده') : badge(p.status,{success:'موفق',pending:'در انتظار',failed:'ناموفق'}[p.status] || '—');
  const methodLabel = method => S.boot?.settings?.payment_method_names?.[method] || methodNames[method] || method;
  const gradeValue = v => v === null || v === undefined ? '—' : num(v);
  async function api(path, data, query = {}, options = {}) {
    if (window.NasrTransport) return window.NasrTransport.request(path,data,query,options);
    const url = new URL(C.api + path, location.href);
    Object.entries(query).forEach(([k,v])=>{ if (v !== '' && v !== undefined && v !== null) url.searchParams.set(k,v); });
    let res;
    try { res = await fetch(url, { method: data === undefined ? 'GET' : 'POST', credentials:'same-origin', cache:'no-store', signal:options.signal, headers:{'X-WP-Nonce':C.nonce,'Content-Type':'application/json'}, ...(data === undefined ? {} : { body:JSON.stringify(data) }) }); }
    catch (e) { if(e.name==='AbortError') throw e; throw new Error('ارتباط برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.'); }
    let body;
    try { body = await res.json(); } catch (e) { throw new Error('پاسخ سامانه معتبر نیست. اتصال وردپرس و تنظیمات کش را بررسی کنید.'); }
    if (!res.ok) {
      if (res.status === 401 || body.code === 'rest_cookie_invalid_nonce') throw new Error('نشست شما منقضی شده است. صفحه را تازه کنید و دوباره وارد شوید.');
      throw new Error(body.message || 'عملیات انجام نشد.');
    }
    return body;
  }
  function toast(message, error = false) {
    let container = document.getElementById('nasr-toast');
    if (!container) { container = document.createElement('div'); container.id='nasr-toast'; container.setAttribute('role','status'); root.append(container); }
    container.className = `nasr-toast ${error ? 'error' : ''}`; container.textContent=message; container.hidden=false;
    clearTimeout(toast.timer); toast.timer=setTimeout(()=>container.hidden=true,error ? 14000 : 7000);
  }
  const button = (label, action, id = '', cls = 'primary', ico = '') => `<button type="button" class="nasr-button ${cls}" data-action="${h(action)}" data-id="${h(id)}">${ico ? icon(ico) : ''}${h(label)}</button>`;
  const link = (label, path, cls = '') => `<a href="#${h(path)}" class="nasr-button ${cls}">${h(label)}</a>`;
  const empty = (title, text, extra = '') => `<div class="nasr-empty">${icon('book')}<h3>${h(title)}</h3>${text?`<p>${h(text)}</p>`:''}${extra}</div>`;
  const sectionHead = (title, sub, extra = '') => `<div class="nasr-section-head"><div><h2>${h(title)}</h2>${sub ? `<p>${h(sub)}</p>` : ''}</div>${extra}</div>`;
  function pager(res) {
    return `<div class="nasr-pagination"><span>${num(res.total)} مورد · صفحه ${num(res.page)} از ${num(res.pages)}</span><div><button class="nasr-button" data-action="page" data-id="${res.page-1}" ${res.page<=1?'disabled':''}>قبلی</button><button class="nasr-button" data-action="page" data-id="${res.page+1}" ${res.page>=res.pages?'disabled':''}>بعدی</button></div></div>`;
  }
  const searchForm = placeholder => `<form id="nasr-search" class="nasr-search">${icon('search')}<input name="q" value="${h(S.q)}" placeholder="${h(placeholder)}" aria-label="${h(placeholder)}"><button class="nasr-button" type="submit">جست‌وجو</button></form>`;
  document.addEventListener('click',event=>{
    const account=root.querySelector('.nasr-user-menu[open]');
    if(account&&(!account.contains(event.target)||event.target.closest('.nasr-user-dropdown a,.nasr-user-dropdown button')))account.open=false;
  });
  root.addEventListener('keydown',event=>{
    const account=root.querySelector('.nasr-user-menu[open]');
    if(event.key==='Escape'&&account){account.open=false;account.querySelector('summary').focus();event.preventDefault();}
  });
  function shell() {
    const b=S.boot;
    let nav=[['dashboard','نمای کلی','dashboard'],['classes',isStaff()?'کلاس‌ها':'کلاس‌ها و کارنامه','book']];
    if(b.role==='teacher') nav.push(['my-salary','حقوق','wallet']);
    if(canManage()) nav.push(['branches','شعبه','book'],['reports','گزارشات','book']);
    if(isManager()) nav.push(['financial-reports','گزارشات مالی','wallet']);
    if(isManager()) nav.push(['people/secretary','منشی‌ها','people']);
    if(canManage()) nav.push(['people/student','زبان‌آموزان','people'],['people/teacher','معلم‌ها','teacher'],['people/family','خانواده‌ها','people'],['terms','ترم‌های آموزشی','calendar']);
    if(canViewPayments()) nav.push(['payments','سوابق پرداخت','wallet']);
    if(canManage()) nav.push(['payment-gateways','درگاه‌های پرداخت','wallet'],['sms','ارسال پیامک','people'],['settings','تنظیمات','settings'],['audit','تاریخچه تغییرات','clock'],['trash','حذف‌شده‌ها','close']);
    const roleSwitch=(b.available_roles || []).length>1?`<a class="nasr-role-switch" href="${h(C.roleChoice)}">${icon('people')}تغییر نقش ورود</a>`:'';
    root.innerHTML=`<div class="nasr-shell"><aside class="nasr-sidebar" id="nasr-sidebar" data-lenis-prevent><a class="nasr-brand" href="#dashboard"><img class="nasr-logo" src="${h(C.assets)}nasr-logo.png" alt=""><div><strong>${h(b.settings.academy_name)}</strong><small>NASR ACADEMY</small></div></a>${canManage()?`<div class="nasr-global-branch">${select('شعبه','global_branch',S.branchId,branchOptions())}</div>`:''}<div class="nasr-nav-label">فضای آموزشی شما</div><nav aria-label="منوی سامانه">${nav.map(([path,label,ico])=>path==='people/teacher'?`<details class="nasr-teacher-menu"><summary>${icon(ico)}<span>معلم‌ها</span><span class="nasr-menu-chevron">⌄</span></summary><a href="#people/teacher" data-nav="people/teacher">فهرست معلم‌ها</a><a href="#teacher-payroll" data-nav="teacher-payroll">اضافه و کاهش حقوق</a></details>`:`<a href="#${path}" data-nav="${path}">${icon(ico)}<span>${label}</span></a>`).join('')}</nav></aside><div class="nasr-workspace"><header class="nasr-topbar"><div><button class="nasr-icon-button nasr-mobile-menu" data-action="menu" aria-label="باز و بسته کردن منو" aria-expanded="false">${icon('menu')}</button><span class="nasr-breadcrumb">سامانه آموزش <span>/</span> <b id="nasr-view-label">نمای کلی</b></span></div><div class="nasr-topbar-end"><span class="nasr-top-date">${icon('calendar')}${date(today())}</span><details class="nasr-user-menu"><summary aria-label="حساب کاربری ${h(b.name)}"><span class="nasr-avatar">${h(b.name.trim().slice(0,1))}</span><span class="nasr-user-label"><strong>${h(b.name)}</strong><small>${h(roleNames[b.role])}</small></span><span class="nasr-user-chevron" aria-hidden="true">⌄</span></summary><div class="nasr-user-dropdown"><div class="nasr-user-info"><strong>${h(b.name)}</strong><small>${h(roleNames[b.role])}</small>${b.student_code?`<small>کد زبان‌آموزی: ${num(b.student_code)}</small>`:''}</div><nav aria-label="گزینه‌های حساب کاربری"><button type="button" data-action="password">${icon('settings')}تغییر رمز عبور</button>${roleSwitch}<a class="nasr-logout" href="${h(C.logout)}">${icon('logout')}خروج از حساب</a></nav></div></details></div></header><main id="nasr-main" tabindex="-1"></main><footer class="nasr-footer">${h(b.settings.academy_name)} </footer></div></div><div id="nasr-dialog-holder"></div><div id="nasr-toast" role="status" hidden></div>`;
    extras.applyAppearance();
    root.dispatchEvent(new CustomEvent('nasr:shell',{detail:{boot:b}}));
  }
  let routeToken=0;
  async function render(options = {}) {
    const background=options.background===true;
    stopPeopleSearch();stopStudentLookup();
    const token=++routeToken;clearTimeout(reportLive.timer);reportLive.controller?.abort();reportLive.context=null;reportLive.token++;
    const [view='dashboard',id='',intent='']=location.hash.replace(/^#/,'').split('/'); S.view=view; S.routeId=id;
    document.getElementById('nasr-sidebar')?.classList.remove('open');
    if(view==='teacher-payroll' || (view==='people' && id==='teacher')){const group=root.querySelector('.nasr-teacher-menu');if(group)group.open=true;}
    document.querySelectorAll('[data-nav]').forEach(a=>a.classList.toggle('active',a.dataset.nav===(view==='people'?`${view}/${id}`:view)));
    const title={schedule:'برنامه کلاس‌ها','my-salary':'حقوق','teacher-payroll':'اضافه و کاهش حقوق',sms:'ارسال پیامک',dashboard:'نمای کلی',classes:'کلاس‌ها',checkout:'پرداخت شهریه',people:personNames[id] ? `${personNames[id]}‌ها` : 'افراد',branches:'شعبه',reports:'گزارشات','financial-reports':'گزارشات مالی',terms:'ترم‌های آموزشی',payments:'سوابق پرداخت','payment-gateways':'درگاه‌های پرداخت',settings:'تنظیمات',audit:'تاریخچه تغییرات',trash:'حذف‌شده‌ها'}[view] || 'سامانه نصر';
    document.getElementById('nasr-view-label').textContent=title;
    root.dispatchEvent(new CustomEvent('nasr:route',{detail:{view,id,title}}));
    document.title=`${title} | ${S.boot.settings.academy_name}`;
    const main=document.getElementById('nasr-main'); if(!background)main.innerHTML=window.NasrMobileUI?.loading('در حال دریافت اطلاعات…') || '<div class="nasr-loading" role="status"><span class="nasr-spinner"></span><p>در حال دریافت اطلاعات…</p></div>';
    try {
      let html;
      if(view==='dashboard') html=await dashboard(options.bootFresh===true);
      else if(view==='classes') html=id ? await classDetail(id) : await classes();
      else if(view==='schedule' && window.NasrAppViews?.schedule) html=await classes('schedule');
      else if(view==='checkout' && id && hasStudentAccess()) html=await checkout(id);
      else if(view==='people' && canManage()) html=await people(id);
      else if(view==='terms' && canManage()) html=await terms();
      else if(view==='branches' && canManage()) html=await branchesPage();
      else if((view==='reports' && canManage()) || (view==='financial-reports' && isManager())) html=await reportsPage(view==='financial-reports');
      else if(view==='payments' && canViewPayments()) html=await payments();
      else if(view==='payment-gateways' && canManage()) html=await gatewaySettingsPage();
      else if(view==='teacher-payroll' && canManage()) html=await payrollPage();
      else if(view==='my-salary' && S.boot.role==='teacher') html=await teacherSalaryPage();
      else if(view==='sms' && canManage()) html=await extras.smsPage(id,intent);
      else if(view==='settings' && canManage()) html=settings();
      else if(view==='audit' && canManage()) html=await audit();
      else if(view==='trash' && canManage()) html=await trash();
      else html=empty('این بخش در دسترس نیست.','');
      if(token===routeToken) { if(background && !canLiveRefresh())return false;const position=background?{x:window.scrollX,y:window.scrollY}:null;main.innerHTML=html; if(!background){main.classList.remove('nasr-enter'); void main.offsetWidth; main.classList.add('nasr-enter');}
        root.dispatchEvent(new CustomEvent('nasr:rendered',{detail:{view,id,boot:S.boot,background}}));
        if(position)window.scrollTo(position.x,position.y);
        if(view==='people' && intent==='new' && canManage() && personNames[id]) {
          history.replaceState(null,'',`#people/${id}`);await personModal(null,id);
        }
        return true;
      }
    } catch(e) { if(token===routeToken && !background) main.innerHTML=empty('دریافت اطلاعات انجام نشد',e.message,button('تلاش دوباره','retry','','primary')); }
    return false;
  }
  const statsCard=(title,value,ico,color,sub='')=>`<div class="nasr-stat"><span class="nasr-stat-icon ${color}">${icon(ico)}</span><span class="nasr-stat-title">${h(title)}</span><strong>${value}</strong></div>`;
  async function dashboard(bootFresh=false) {
    const [b,res]=await Promise.all([bootFresh?S.boot:api('bootstrap'),api('classes',undefined,{per_page:6,branch_id:canManage()?S.branchId:''})]);S.boot=b;
    const welcome=canManage()?'امروز، فرصت تازه‌ای برای رشد.':b.role==='teacher'?'یادگیری با شما جان می‌گیرد.':b.role==='family'?'همراه مسیر پیشرفت فرزندتان.':'قدم بعدی یادگیری از اینجا شروع می‌شود.';
    let stats=statsCard('کلاس‌های فعال',num(b.stats.classes),'book','blue','کلاس‌های در دسترس شما');
    if(canManage()) stats+=statsCard('زبان‌آموزان فعال',num(b.stats.students),'people','green','در همهٔ دوره‌های آموزشگاه')+statsCard('معلم‌های فعال',num(b.stats.teachers),'teacher','orange','تیم آموزشی نصر');
    else if(hasStudentAccess()) stats+=statsCard('پرداخت‌های موفق',money(b.stats.paid),'wallet','green','جمع پرداخت‌های تأییدشده');
    if(!canManage() && hasStudentAccess()) stats+=statsCard('ماندهٔ شهریه',money(b.stats.due),'wallet','orange','شامل بدهی سوابق حذف‌شده و بایگانی');
    const upcoming=res.items.flatMap(c=>[['mid_date','میان‌ترم'],['final_date','فاینال']].filter(([k])=>c[k] && c[k]>=today() && +c.active).map(([k,label])=>({name:c.name,id:c.id,date:c[k],label}))).sort((a,b)=>a.date.localeCompare(b.date)).slice(0,4);
    if(window.NasrAppViews?.dashboard)return window.NasrAppViews.dashboard({boot:b,classes:res.items,upcoming,date,money,classSchedule});
    return `<div class="nasr-welcome"><div><span class="nasr-eyebrow">سلام، ${h(b.name)}</span><h1>${welcome}</h1></div><div class="nasr-welcome-art" aria-hidden="true"><span>Aa</span><small>LEARN<br>WITH NASR</small></div></div><div class="nasr-stats">${stats}</div>${b.role==='family'?`<div class="nasr-children">${b.children.length?b.children.map(p=>`<span class="nasr-child">${icon('people')}<strong>${h(fullName(p))}</strong><small>کد ${num(p.student_code)}</small></span>`).join(''): '<p>هنوز زبان‌آموزی به این حساب مرتبط نشده است. با آموزشگاه تماس بگیرید.</p>'}</div>`:''}<div class="nasr-dashboard-grid"><section class="nasr-panel">${sectionHead('کلاس‌های من','',link('همهٔ کلاس‌ها','classes','text'))}${res.items.length?res.items.map(c=>`<a class="nasr-class-row" href="#classes/${c.id}"><span class="nasr-class-letter">${icon('book')}</span><div><h3>${h(c.name)}</h3><p>${h(c.term_name)} · ${h(c.teacher_name)}</p></div><div class="nasr-class-row-meta">${badge(+c.active?'success':'neutral',+c.active?'فعال':'بایگانی')}${c.student_count!==undefined&&c.student_count!==null?`<small>${num(c.student_count)} زبان‌آموز</small>`:''}</div>${icon('arrow')}</a>`).join(''):empty('اولین کلاس، شروع یک مسیر تازه','',canManage()?button('ساخت کلاس','new-class','','primary','plus'):'')}</section><div class="nasr-dashboard-side"><section class="nasr-panel nasr-upcoming">${sectionHead('آزمون‌های پیش رو','')}${upcoming.length?upcoming.map(x=>`<a class="nasr-event" href="#classes/${x.id}"><span>${icon('calendar')}</span><div><strong>${h(x.label)} · ${h(x.name)}</strong><small>${date(x.date)}</small></div></a>`).join(''): '<p class="nasr-muted">آزمونی برای این کلاس‌ها در آینده ثبت نشده است.</p>'}</section><section class="nasr-panel nasr-quick">${sectionHead(canManage()?'دسترسی سریع':'فضای شخصی','')}${canManage()?button('تعریف زبان‌آموز','new-person','student','soft','people')+button('تعریف معلم','new-person','teacher','soft','teacher')+button('تعریف ترم','new-term','','soft','calendar'):button('تغییر رمز عبور','password','','soft','settings')}${b.student_code?`<p>کد زبان‌آموزی شما: <strong>${num(b.student_code)}</strong></p>`:''}</section></div></div>`;
  }
  async function people(kind) {
    if(!personNames[kind] || (kind==='secretary' && !isManager())) return empty('نقش معتبر نیست','');
    const res=await api('people',undefined,{kind,page:S.page,q:S.q}); S.people=res.items;
    return `${sectionHead(`${personNames[kind]}‌ها`,'',button(`تعریف ${personNames[kind]}`,'new-person',kind,'primary','plus')+(kind==='secretary'?button('افزودن منشی از افراد موجود','grant-secretary','','soft','people'):''))}<section class="nasr-panel">${searchForm('جست‌وجوی زنده با تایپ نام، کد، کد ملی یا همراه')}<div id="nasr-people-results" aria-live="polite">${peopleResults(res,kind)}</div></section>`;
  }
  function peopleResults(res,kind){return `${res.items.length?`<div class="nasr-table-wrap"><table><thead><tr><th>نام و نام خانوادگی</th><th>${kind==='student'?'کد زبان‌آموزی':'نام کاربری'}</th><th>شماره تماس ۱</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>${res.items.map(p=>`<tr><td><div class="nasr-person-cell"><span class="nasr-avatar subtle">${h(p.first_name.slice(0,1))}</span><div><strong>${h(fullName(p))}</strong>${kind==='student'&&p.kind==='teacher'?'<small>معلم / زبان‌آموز</small>':''}<small dir="ltr">${h(englishName(p) || p.username)}</small></div></div></td><td><bdi>${kind==='student'?num(p.student_code):h(p.username)}</bdi></td><td><bdi>${h(p.mobile)}</bdi></td><td>${badge(+p.active?'success':'neutral',+p.active?'فعال':'غیرفعال')}</td><td>${button(p.kind==='teacher'?'پروندهٔ معلم و ویرایش':'پرونده و ویرایش','edit-person',p.id,'text','edit')}${kind==='secretary'?button('لغو دسترسی منشی','revoke-secretary',p.id,'text danger'):['student','teacher'].includes(kind)&&p.kind===kind?button('حذف','delete-person',p.id,'text danger'):''}</td></tr>`).join('')}</tbody></table></div>${pager(res)}`:empty('موردی پیدا نشد','')}`;}
  const peopleSearch={timer:null,controller:null,token:0};
  function stopPeopleSearch(){clearTimeout(peopleSearch.timer);peopleSearch.controller?.abort();peopleSearch.token++;}
  function searchPeople(value,delay=300){
    if(S.view!=='people')return;stopPeopleSearch();S.q=value;S.page=1;const token=peopleSearch.token,kind=S.routeId,target=root.querySelector('#nasr-people-results');if(!target)return;
    target.setAttribute('aria-busy','true');target.querySelectorAll('button').forEach(b=>b.disabled=true);
    peopleSearch.timer=setTimeout(async()=>{const controller=new AbortController();peopleSearch.controller=controller;
      try{const res=await api('people',undefined,{kind,q:value,page:1},{signal:controller.signal});if(token!==peopleSearch.token || !target.isConnected)return;S.people=res.items;target.innerHTML=peopleResults(res,kind);}
      catch(e){if(e.name!=='AbortError' && token===peopleSearch.token && target.isConnected)target.innerHTML=empty('جست‌وجو انجام نشد',e.message,button('تلاش دوباره','retry','','soft'));}
      finally{if(token===peopleSearch.token && target.isConnected)target.setAttribute('aria-busy','false');}
    },delay);
  }
  const studentLookup={timer:null,controller:null,token:0,rows:[],active:-1,q:''};
  function stopStudentLookup(){clearTimeout(studentLookup.timer);studentLookup.controller?.abort();studentLookup.token++;studentLookup.active=-1;}
  function studentSearchField(id,options,purpose='financial'){const p=options.people.find(p=>['student','teacher'].includes(p.kind) && +p.user_id===+id);return `<div class="nasr-field nasr-student-lookup" data-purpose="${h(purpose)}"><label for="nasr-student-query">${purpose==='grades'?'زبان‌آموز':'فرد ثبت‌نام‌شده'} <b class="nasr-required">*</b></label><input type="hidden" name="student_id" value="${h(id || '')}"><input id="nasr-student-query" name="student_lookup" value="${h(p?fullName(p)+' · '+learnerMeta(p):'')}" placeholder="نام فارسی، انگلیسی یا کد را تایپ کنید" maxlength="100" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="nasr-student-options"><div id="nasr-student-popup" class="nasr-student-popup" data-lenis-prevent data-scroll-lock-scrollable hidden><div id="nasr-student-options" role="listbox" aria-label="نتایج جست‌وجوی زبان‌آموزان"></div><div id="nasr-student-pages"></div></div><span id="nasr-student-search-status" role="status"></span></div>`;}
  function hideStudentLookup(){stopStudentLookup();const box=root.querySelector('#nasr-student-popup'),input=root.querySelector('#nasr-student-query');if(box)box.hidden=true;if(input){input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}}
  function positionStudentLookup(){
    const input=root.querySelector('#nasr-student-query'),popup=root.querySelector('#nasr-student-popup');if(!input || popup.hidden)return;
    const bounds=input.getBoundingClientRect(),field=input.closest('.nasr-student-lookup').getBoundingClientRect(),limit=Math.min(320,innerHeight*.45),below=innerHeight-bounds.bottom-12,above=bounds.top-12,up=below<Math.min(180,limit) && above>below;
    popup.style.maxHeight=Math.max(0,Math.min(limit,up?above:below))+'px';popup.style.top=up?'auto':(bounds.bottom-field.top+4)+'px';popup.style.bottom=up?(field.bottom-bounds.top+4)+'px':'auto';
  }
  function searchStudents(value,page=1,delay=250){
    const input=root.querySelector('#nasr-student-query'),popup=root.querySelector('#nasr-student-popup'),list=root.querySelector('#nasr-student-options'),status=root.querySelector('#nasr-student-search-status');if(!input)return;
    const purpose=input.closest('.nasr-student-lookup').dataset.purpose || 'financial',allNames=purpose==='grades';
    stopStudentLookup();const token=studentLookup.token;studentLookup.q=value;studentLookup.rows=[];popup.hidden=false;popup.scrollTop=0;input.setAttribute('aria-expanded','true');input.removeAttribute('aria-activedescendant');list.innerHTML='';root.querySelector('#nasr-student-pages').innerHTML='';status.textContent='در حال جست‌وجو…';
    positionStudentLookup();
    studentLookup.timer=setTimeout(async()=>{const controller=new AbortController();studentLookup.controller=controller;
      try{const res=await api('directory',undefined,{kind:'student',purpose,live:1,q:value,...(allNames?{}:{page,per_page:20})},{signal:controller.signal});if(token!==studentLookup.token || !input.isConnected)return;studentLookup.rows=res.items;
        list.innerHTML=res.items.map((p,i)=>`<button type="button" id="nasr-student-option-${i}" role="option" aria-selected="false" data-action="select-report-student" data-id="${p.user_id}"><strong>${h(fullName(p))}</strong> · ${h(learnerMeta(p))}<small dir="ltr">${h(englishName(p))}</small>${p.deleted_at?'<small>حذف‌شده؛ سوابق مالی محفوظ است</small>':!+p.active?'<small>غیرفعال</small>':''}</button>`).join('');
        root.querySelector('#nasr-student-pages').innerHTML=!allNames && res.pages>1?`${page>1?button('قبلی','student-search-page',page-1,'text'):''}<span>${num(page)} / ${num(res.pages)}</span>${page<res.pages?button('بعدی','student-search-page',page+1,'text'):''}`:'';status.textContent=res.total?`${num(res.total)} نفر پیدا شد؛ یک نام را انتخاب کنید.`:'نامی پیدا نشد.';
        positionStudentLookup();
      }catch(e){if(e.name!=='AbortError' && token===studentLookup.token && input.isConnected)status.textContent=e.message;}
    },delay);
  }
  function chooseReportStudent(id){const p=studentLookup.rows.find(p=>+p.user_id===+id);if(!p)return;S.reportFilters[S.view].student_id=String(p.user_id);S.page=1;hideStudentLookup();render();}
  async function terms() {
    S.terms=await api('terms');
    const shown=S.terms.filter(t=>S.branchId==='' || +t.branch_id===+S.branchId);
    return `${sectionHead('ترم‌های آموزشی','',button('تعریف ترم','new-term','','primary','plus'))}<section class="nasr-panel">${shown.length?`<div class="nasr-table-wrap"><table><thead><tr><th>شعبه</th><th>ترم / کد ترم</th><th>شروع</th><th>میان‌ترم</th><th>فاینال</th><th>پایان</th><th>وضعیت</th><th></th></tr></thead><tbody>${shown.map(t=>`<tr><td>${h(t.branch_name || branchName(t.branch_id))}</td><td><strong>${h(t.name)}</strong><small class="nasr-block">${h(t.code)}</small></td><td>${date(t.start_date)}</td><td>${date(t.mid_date)}</td><td>${date(t.final_date)}</td><td>${date(t.end_date)}</td><td>${badge(+t.active?'success':'neutral',+t.active?'فعال':'غیرفعال')}</td><td>${button('ویرایش','edit-term',t.id,'text','edit')}${button('حذف','delete-term',t.id,'text danger')}</td></tr>`).join('')}</tbody></table></div>`:empty('هنوز ترمی ثبت نشده است','')}</section>`;
  }
  async function classes(presentation='classes') {
    const terms=await api(canManage()?'terms':'class-terms',undefined,canManage()?{branch_id:S.branchId}:{});
    if(!terms.some(t=>String(t.id)===String(S.classTermId)))S.classTermId='';
    const termFilter=`<div class="nasr-class-term-filter">${select('ترم آموزشی','class_list_term',S.classTermId || '',[['','ترم را انتخاب کنید'],...terms.map(t=>[t.id,termLabel(t)])])}</div>`;
    if(window.NasrAppViews?.classes){
      const res=S.classTermId?await api('classes',undefined,{page:S.page,q:S.q,branch_id:canManage()?S.branchId:'',term_id:S.classTermId}):null;
      return window.NasrAppViews[presentation]({boot:S.boot,termFilter,res,search:searchForm('جست‌وجوی کلاس'),pager:res?pager(res):'',date,classSchedule,canManage:canManage(),button});
    }
    if(!S.classTermId)return `${sectionHead('کلاس‌ها','',canManage()?`<div class="nasr-actions">${button('تعریف کلاس','new-class','','primary','plus')}${button('تعریف ترم','new-term','','soft','plus')}</div>`:'')}<section class="nasr-panel">${termFilter}${terms.length?'':empty('ترمی برای نمایش وجود ندارد','')}</section>`;
    const res=await api('classes',undefined,{page:S.page,q:S.q,branch_id:canManage()?S.branchId:'',term_id:S.classTermId});
    return `${sectionHead(isStaff()?'کلاس‌ها':'کلاس‌ها و کارنامه','',canManage()?`<div class="nasr-actions">${button('افزودن گروهی از کلاس قبلی','bulk-enroll','','soft','people')}${button('تعریف کلاس','new-class','','primary','plus')}</div>`:'')}<section class="nasr-panel nasr-class-list">${termFilter}${searchForm('جست‌وجوی نام کلاس یا ترم')}${res.items.length?`<div class="nasr-class-grid">${res.items.map((c,i)=>`<a href="#classes/${c.id}" class="nasr-class-card accent-${i%3}"><div class="nasr-card-top"><span class="nasr-class-letter">${icon('book')}</span>${badge(+c.active?'success':'neutral',+c.active?'فعال':'بایگانی')}</div><span class="nasr-eyebrow">${h(c.term_name)}</span><h3>${h(c.name)}</h3><p>${icon('teacher')}${h(c.teacher_name)}</p><div class="nasr-card-dates"><span>شروع <b>${date(c.start_date)}</b></span><span>پایان <b>${date(c.end_date)}</b></span></div><div class="nasr-card-bottom"><span>${c.student_count!==undefined&&c.student_count!==null?`${num(c.student_count)} زبان‌آموز`:h(classSchedule(c) || 'مشاهدهٔ جزئیات و کارنامه')}</span>${icon('arrow')}</div></a>`).join('')}</div>${pager(res)}`:empty('هنوز کلاسی برای نمایش وجود ندارد','')}</section>`;
  }
  function gradeStrip(e,c) {
    const g=e.grades;
    const score=v=>v===null || v===undefined?'Not graded':numEN(v);
    return `<div class="nasr-grade-grid">${Object.entries(gradeNames).map(([key,label])=>`<div class="nasr-grade"><span>${label}</span><strong>${score(g[key])}<small> / ${numEN(key==='midterm'?c.mid_max:key==='final_exam'?c.final_max:10)}</small></strong></div>`).join('')}<div class="nasr-grade classwork"><span>Classwork</span><strong>${score(g.classwork)}<small> / 40</small></strong></div><div class="nasr-grade total"><span>Total Score</span><strong>${score(g.total)}<small> / ${numEN(g.total_max)}</small></strong></div></div>${g.total===null?`<p class="nasr-muted">${g.version?'Incomplete grades':'No grades have been recorded yet.'}</p>`:''}${g.feedback?`<div class="nasr-feedback"><b>Teacher Feedback</b><p dir="auto">${h(g.feedback).replace(/\n/g,'<br>')}</p></div>`:''}${g.updated_at?`<small class="nasr-muted">Last updated: ${h(dateEN(g.updated_at))}</small>`:''}`;
  }
  function reportCard(e,c){
    return `<div class="nasr-report-inner nasr-english-report" data-report="${e.id}" lang="en" dir="ltr">${sectionHead('Report Card',englishName(e) || fullName(e),button('Print Report Card','print-report',e.id,'text','print'))}<div class="nasr-report-metadata">${[['Student ID',numEN(e.student_code)],['Class',c.name],['Term',c.term_name],['Teacher',c.teacher_name_en || c.teacher_name],['Midterm Date',dateEN(c.mid_date)],['Final Exam Date',dateEN(c.final_date)]].map(([label,value])=>`<div><span>${h(label)}</span><strong dir="auto">${h(value)}</strong></div>`).join('')}</div>${!+e.active?'<p class="nasr-alert">This enrollment is withdrawn or archived.</p>':''}${gradeStrip(e,c)}</div>`;
  }
  const receiptButton=p=>p.status==='success'?button('نمایش رسید پرداخت','payment-receipt',p.id,'text'):'';
  async function paymentReceipt(id){
    const data=await api(`payments/${Number(id)}/receipt`),p=data.payment;
    const details=[['شماره رسید',`NASR-${p.id}`],['نام زبان‌آموز',p.student_name],['کد زبان‌آموزی',num(p.student_code)],['کلاس',p.class_name],['ترم',`${p.term_name} · ${p.term_code}`],['شعبه',p.branch_name || 'تعیین نشده'],['تاریخ پرداخت',date(p.paid_on)],['روش پرداخت',methodLabel(p.method)],['شماره پیگیری',p.reference || 'ثبت نشده']];
    const html=`<article class="nasr-payment-receipt" dir="rtl"><h2>${h(data.academy_name)}</h2><h3>رسید پرداخت شهریه</h3><p class="nasr-alert ${p.voided_at?'error':'success'}">${p.voided_at?'این رسید باطل شده است و اعتبار پرداخت ندارد.':'پرداخت موفق و ثبت‌شده'}</p><div class="nasr-receipt-amount">مبلغ پرداخت‌شده: <strong>${num(p.amount)} ${h(data.currency)}</strong></div><table><tbody>${details.map(([label,value])=>`<tr><th>${h(label)}</th><td><bdi>${h(value)}</bdi></td></tr>`).join('')}</tbody></table>${p.notes?`<p class="nasr-wrap-text">توضیحات: ${h(p.notes)}</p>`:''}${p.voided_at?`<p class="nasr-alert error">تاریخ ابطال: ${h(date(p.voided_at,true))}<br>علت ابطال: ${h(p.void_reason)}</p>`:''}</article>`;
    modal('رسید پرداخت',html,()=>extras.printDocument('رسید پرداخت شهریه',html,'','',{embeddedTitle:true,receipt:true}),'چاپ / ذخیرهٔ PDF',true);
  }
  function paymentTable(rows,actions=false) {
    if(!rows.length) return '<p class="nasr-muted nasr-pad">هنوز سابقهٔ پرداختی ثبت نشده است.</p>';
    return `<div class="nasr-table-wrap"><table><thead><tr><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th><th>روش</th><th>شماره پیگیری / توضیح</th><th>رسید / عملیات</th></tr></thead><tbody>${rows.map(p=>`<tr><td>${date(p.paid_on)}</td><td>${money(p.amount)}</td><td>${payBadge(p)}</td><td>${h(methodLabel(p.method))}</td><td><bdi>${h(p.reference || '—')}</bdi><small class="nasr-block nasr-wrap-text">${h(p.notes)}${p.voided_at?` · علت ابطال: ${h(p.void_reason)}`:''}</small></td><td>${receiptButton(p)}${actions&&!p.voided_at?button('ابطال سابقه','void-payment',p.id,'text danger'):''}</td></tr>`).join('')}</tbody></table></div>`;
  }
  function consumePaymentNotice(classId) {
    try {
      const raw=sessionStorage.getItem('nasrPaymentNotice');if(!raw)return '';
      const notice=JSON.parse(raw);if(!notice || +notice.class_id!==+classId)return '';
      sessionStorage.removeItem('nasrPaymentNotice');
      const success=notice.status==='success';
      return `<div class="nasr-alert nasr-payment-notice ${success?'success':'error'}" role="status"><strong>${success?'پرداخت شهریه ثبت شد':'پرداخت تکمیل نشد'}</strong><span>${h(notice.message || '')}</span>${notice.reference?`<small>شماره پیگیری: <bdi>${h(notice.reference)}</bdi></small>`:''}</div>`;
    } catch(e) { try{sessionStorage.removeItem('nasrPaymentNotice');}catch(ignore){} return ''; }
  }
  async function classDetail(id) {
    const res=await api(`classes/${id}`); S.detail=res; const c=res.class;
    const staffView=res.viewer_mode ? res.viewer_mode==='staff' : isStaff();
    const actions=canManage()?button('اطلاع‌رسانی پیامکی','sms-class',c.id,'soft','people')+button('ویرایش کلاس','edit-class',c.id,'','edit')+button('افزودن زبان‌آموز / معلم','enroll',c.id,'primary','plus')+button('افزودن گروهی از کلاس قبلی','bulk-enroll',c.id,'soft','people')+button('حذف کلاس','delete-class',c.id,'text danger'):'';
    const meta=[['شعبه',c.branch_name || 'تعیین نشده'],['ترم',c.term_name],['جلسات برنامه‌ریزی‌شده',c.sessions_planned===null?'تعیین نشده':num(c.sessions_planned)],['جلسات برگزارشده',c.sessions_held===null?'تعیین نشده':num(c.sessions_held)],['کد ترم',c.term_code],['معلم',c.teacher_name],['شروع',date(c.start_date)],['میان‌ترم',date(c.mid_date)],['فاینال',date(c.final_date)],['پایان',date(c.end_date)],['روز و ساعت',classSchedule(c)],['محل کلاس',c.room || 'تعیین نشده']];
    let html=`<a class="nasr-back" href="#classes">بازگشت به کلاس‌ها</a>${consumePaymentNotice(c.id)}${sectionHead(c.name,c.term_name,`<div class="nasr-actions">${badge(+c.active?'success':'neutral',+c.active?'کلاس فعال':'کلاس بایگانی')}${staffView?button('حضور و غیاب','attendance',c.id,'soft'):''}${actions}</div>`)}<section class="nasr-panel nasr-class-summary"><div class="nasr-meta-grid">${meta.map(([k,v])=>`<div><small>${h(k)}</small><strong>${h(v)}</strong></div>`).join('')}</div>${c.notes?`<p class="nasr-class-note">${h(c.notes).replace(/\n/g,'<br>')}</p>`:''}${canManage()?`<div class="nasr-fee-line"><span>شهریه ثابت: <b>${money(c.base_fee)}</b></span><span>کپی و کتاب: <b>${money(c.extras_fee)}</b></span><strong>شهریه کل: ${money(+c.base_fee + +c.extras_fee)}</strong><span>حق‌التدریس ساعتی: <b>${c.hourly_rate===null?'تعیین نشده':toman(c.hourly_rate)}</b></span></div>`:''}</section>`;
    if(staffView) {
      html+=`<section class="nasr-panel">${sectionHead('زبان‌آموزان کلاس',`${num(res.enrollments.length)} ثبت‌نام`,button('چاپ فهرست','print','','text','print'))}${res.enrollments.length?`<div class="nasr-table-wrap"><table><thead><tr><th>زبان‌آموز</th><th>کد</th><th>وضعیت</th><th lang="en">Classwork / 40</th><th lang="en">Midterm / ${numEN(c.mid_max)}</th><th lang="en">Final Exam / ${numEN(c.final_max)}</th><th lang="en">Total Score / ${numEN(c.total_max)}</th>${canManage()?'<th>شهریه خالص</th><th>مانده</th>':''}<th></th></tr></thead><tbody>${res.enrollments.map(e=>`<tr><td><button class="nasr-name-button" data-action="grades" data-id="${e.id}">${h(fullName(e))}</button></td><td>${num(e.student_code)}</td><td>${badge(+e.active?'success':'neutral',+e.active?'فعال':'انصراف/بایگانی')}</td><td>${gradeValue(e.grades.classwork)}</td><td>${gradeValue(e.grades.midterm)}</td><td>${gradeValue(e.grades.final_exam)}</td><td><strong class="nasr-score">${gradeValue(e.grades.total)}</strong></td>${canManage()?`<td>${money(e.net_fee)}${+e.discount?`<small class="nasr-block">${num(e.discount)}٪ تخفیف</small>`:''}</td><td>${money(e.balance)}${+e.credit?`<small>بستانکاری: ${money(e.credit)}</small>`:''}</td>`:''}<td><div class="nasr-row-actions">${button('Grades','grades',e.id,'text')}${canManage()?button('شهریه','finance',e.id,'text')+button('حذف از کلاس','remove-student',e.id,'text danger'):''}</div></td></tr>`).join('')}</tbody></table></div>`:empty('فهرست کلاس هنوز خالی است','')}</section>`;
    } else {
      html+=res.enrollments.map(e=>`<section class="nasr-panel nasr-student-report">${reportCard(e,c)}<div class="nasr-student-finance"><h3>شهریه و پرداخت‌ها</h3><div class="nasr-finance-overview"><div><small>شهریه ثابت</small><strong>${money(e.base_fee)}</strong></div><div><small>کپی و کتاب</small><strong>${money(e.extras_fee)}</strong></div><div><small>تخفیف ${e.discount_scope==='base'?'شهریه ثابت':'کل شهریه'}</small><strong>${num(e.discount)}٪</strong></div>${Object.entries(extraFeeLabels).map(([key,label])=>`<div><small>${h(label)}</small><strong>${money(e[key] || 0)}</strong></div>`).join('')}<div><small>شهریه و هزینه‌های نهایی</small><strong>${money(e.net_fee)}</strong></div><div><small>پرداخت موفق</small><strong>${money(e.paid)}</strong></div><div class="nasr-balance"><small>ماندهٔ قابل پرداخت</small><strong>${money(e.balance)}</strong></div></div>${+e.credit?`<p class="nasr-alert">بستانکاری شما: ${money(e.credit)}</p>`:''}${+e.balance?`<div class="nasr-online-pay"><div><strong>پرداخت اینترنتی شهریه</strong><small>مبلغ قابل پرداخت: ${money(e.balance)}</small></div>${link('پرداخت شهریه',`checkout/${e.id}`,'primary')}</div>`:'<p class="nasr-alert success">شهریه این کلاس تسویه شده است.</p>'}<h3>سوابق پرداخت شهریه</h3>${paymentTable(e.payments)}</div></section>`).join('');
    }
    return html;
  }
  async function checkout(id) {
    const data=await api(`checkout/${id}`),c=data.class;
    const gateways=data.gateways.map((gateway,index)=>`<label class="nasr-gateway-choice"><input type="radio" name="gateway" value="${h(gateway.id)}" ${index===0?'checked':''}><span class="nasr-gateway-mark">${h(gateway.title.slice(0,1))}</span><span><strong>${h(gateway.title)}</strong><small>${h(gateway.description || '')}</small></span><i aria-hidden="true"></i></label>`).join('');
    const unavailable=data.unavailable?.length?`<details class="nasr-gateway-unavailable"><summary>درگاه‌های موقتاً در دسترس نیستند</summary>${data.unavailable.map(item=>`<p><strong>${h(item.title)}</strong> — ${h(item.message)}</p>`).join('')}</details>`:'';
    return `<a class="nasr-back" href="#classes/${c.id}">بازگشت به صفحهٔ کلاس</a>${sectionHead('پرداخت شهریه','')}<div class="nasr-checkout-layout"><section class="nasr-panel nasr-checkout-summary"><span class="nasr-eyebrow">صورتحساب شهریه</span><h2>${h(c.name)}</h2><div class="nasr-checkout-meta"><div><small>زبان‌آموز</small><strong>${h(data.enrollment.student_name)}</strong></div><div><small>کد زبان‌آموزی</small><strong>${num(data.enrollment.student_code)}</strong></div><div><small>ترم</small><strong>${h(c.term_name)}</strong></div><div><small>شعبه</small><strong>${h(c.branch_name || 'تعیین نشده')}</strong></div><div><small>معلم</small><strong>${h(c.teacher_name)}</strong></div><div><small>محل کلاس</small><strong>${h(c.room || 'تعیین نشده')}</strong></div></div><div class="nasr-checkout-amount"><span>مبلغ قابل پرداخت</span><strong>${money(data.amount)}</strong></div></section><section class="nasr-panel nasr-checkout-gateways"><h2>انتخاب روش پرداخت</h2>${data.gateways.length?`<form id="nasr-checkout-form">${gateways}<button class="nasr-button primary nasr-pay-submit" type="submit">ورود به درگاه و پرداخت ${money(data.amount)}</button></form>`:empty('درگاه فعالی وجود ندارد','')}${unavailable}</section></div>`;
  }
  async function payments() {
    const options=await api('payment-options');
    if(!S.paymentFilters)S.paymentFilters={branch_id:canManage()?S.branchId:'',term_id:''};
    const f=S.paymentFilters;
    if(f.branch_id!=='' && !options.branches.some(b=>+b.id===+f.branch_id)){f.branch_id='';f.term_id='';}
    const terms=f.branch_id===''?[]:options.terms.filter(t=>+(t.branch_id || 0)===+f.branch_id);
    if(f.term_id && !terms.some(t=>+t.id===+f.term_id))f.term_id='';
    const controls=`<section class="nasr-panel"><div class="nasr-form-grid">${select('۱. شعبه','payment_branch',f.branch_id,[['','شعبه را انتخاب کنید'],...options.branches.map(b=>[b.id,b.name])])}${select('۲. ترم','payment_term',f.term_id,[['',f.branch_id===''?'ابتدا شعبه را انتخاب کنید':'ترم را انتخاب کنید'],...terms.map(t=>[t.id,termLabel(t)])]).replace('name="payment_term"',`name="payment_term" ${f.branch_id===''?'disabled':''}`)}</div></section>`;
    const head=sectionHead('سوابق پرداخت شهریه','')+controls;
    if(f.branch_id==='' || !f.term_id)return head+`<section class="nasr-panel">${empty('ابتدا شعبه و ترم را انتخاب کنید','')}</section>`;
    const res=await api('payments',undefined,{page:S.page,branch_id:f.branch_id,term_id:f.term_id});
    return `${head}<section class="nasr-panel">${res.items.length?`<div class="nasr-table-wrap"><table><thead><tr><th>زبان‌آموز</th><th>کلاس / ترم</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th><th>پیگیری</th><th>رسید پرداخت</th></tr></thead><tbody>${res.items.map(p=>`<tr><td>${h(p.student_name)}<small class="nasr-block">${num(p.student_code)}</small></td><td>${+p.removed?h(p.class_name)+badge('neutral','سوابق حذف‌شده'):`<a href="#classes/${p.class_id}">${h(p.class_name)}</a>`}<small class="nasr-block">${h(p.term_name)}</small></td><td>${date(p.paid_on)}</td><td>${money(p.amount)}</td><td>${payBadge(p)}</td><td><bdi>${h(p.reference || '—')}</bdi></td><td>${receiptButton(p)}</td></tr>`).join('')}</tbody></table></div>${pager(res)}`:empty('سابقهٔ پرداختی وجود ندارد','')}</section>`;
  }
  const field = (label,name,value='',opts={}) => `<label class="nasr-field ${opts.wide?'wide':''} ${opts.date?'nasr-date-field':''}"><span>${h(label)}${opts.required?' <b class="nasr-required">*</b>':''}</span>${opts.area?`<textarea name="${h(name)}" rows="3" maxlength="2000">${h(value)}</textarea>`:`<input name="${h(name)}" type="${opts.type || 'text'}" value="${h(value)}" ${opts.required?'required':''} ${opts.readonly?'readonly':''} ${opts.dir?`dir="${h(opts.dir)}"`:''} ${opts.mode?`inputmode="${h(opts.mode)}"`:''} ${opts.date?'data-calendar-field="1" aria-haspopup="dialog" aria-expanded="false" autocomplete="off"':''} ${opts.termDate?'data-term-date="1"':''} ${opts.autoCredential?'data-auto-credential="1"':''} ${opts.placeholder?`placeholder="${h(opts.placeholder)}"`:''} ${opts.minlength?`minlength="${opts.minlength}"`:''} ${opts.maxlength?`maxlength="${opts.maxlength}"`:''} ${opts.autocomplete?`autocomplete="${h(opts.autocomplete)}"`:''}>`}</label>`;
  const select = (label,name,value,options,help='') => `<label class="nasr-field"><span>${h(label)}</span><select name="${h(name)}">${options.map(([val,text])=>`<option value="${h(val)}" ${String(value)===String(val)?'selected':''}>${h(text)}</option>`).join('')}</select></label>`;
  const classDayFields=selected=>`<fieldset class="nasr-weekdays wide" data-class-days><legend>روزهای کلاس در هفته <b class="nasr-required">*</b></legend><div>${classDays.map(([value,label])=>`<label><input type="checkbox" name="class_days" value="${value}" ${selected.includes(value)?'checked':''}><span>${label}</span></label>`).join('')}</div></fieldset>`;
  const check = (label,name,value=true) => `<label class="nasr-check wide"><input type="checkbox" name="${name}" value="1" ${value?'checked':''}>${h(label)}</label>`;
  function settings() {
    const s=S.boot.settings;
    return `${sectionHead('تنظیمات آموزشگاه','')}<section class="nasr-panel nasr-settings"><form id="nasr-settings-form"><div class="nasr-form-grid">${field('نام آموزشگاه','academy_name',s.academy_name,{required:true,maxlength:100})}${field('تلفن پشتیبانی','support_phone',s.support_phone,{dir:'ltr',mode:'tel'})}${branchesEditor()}${field('گزینه‌های مدرک تحصیلی','education_options',educationList().join('\n'),{area:true,wide:true,help:'هر مدرک را در یک خط وارد کنید؛ ترتیب نمایش در فرم پرونده نیز همین ترتیب است.'})}${select('واحد پول','currency',s.currency,[['تومان','تومان'],['ریال','ریال']],'پس از ایجاد اولین کلاس، واحد پول ثابت می‌ماند.')}${select('تقویم ورودی و نمایش','calendar',s.calendar,[['jalali','شمسی'],['gregorian','میلادی']])}${field('Midterm Maximum','mid_max',s.mid_max,{required:true,mode:'decimal',dir:'ltr'})}${field('Final Exam Maximum','final_max',s.final_max,{required:true,mode:'decimal',dir:'ltr'})}${select('مبنای پیش‌فرض تخفیف','discount_scope',s.discount_scope,[['base','فقط شهریه ثابت'],['total','شهریه ثابت + کپی و کتاب']])}</div>${extras.preferences()}<button class="nasr-button primary" type="submit">ذخیرهٔ تنظیمات</button></form>${C.isWPAdmin?`<div class="nasr-settings-manager"><h3>مدیران آموزشگاه</h3>${button('تعریف مدیر آموزشگاه','new-person','manager','soft','plus')} ${link('فهرست مدیران','people/manager')}</div>`:''}</section>`;
  }
  function gatewayField(gateway,config) {
    const value=gateway.values[config.key] ?? '',key=h(config.key),label=`${h(config.label)}${config.required?' <b class="nasr-required">*</b>':''}`;
    let control='';
    if(config.type==='checkbox')control=`<label class="nasr-check wide"><input type="checkbox" data-gateway-setting="${key}" ${+value?'checked':''}>${label}</label>`;
    else if(config.type==='select')control=`<label class="nasr-field"><span>${label}</span><select data-gateway-setting="${key}">${Object.entries(config.options || {}).map(([id,name])=>`<option value="${h(id)}" ${String(value)===String(id)?'selected':''}>${h(name)}</option>`).join('')}</select></label>`;
    else control=`<label class="nasr-field"><span>${label}</span><input data-gateway-setting="${key}" type="${config.type==='password'?'password':'text'}" value="${config.secret?'':h(value)}" ${config.type==='number'?'inputmode="numeric" dir="ltr"':''} ${['password','url'].includes(config.type)?'dir="ltr"':''} ${config.secret && gateway.saved_secrets[config.key]?'placeholder="مقدار امن قبلی ذخیره شده است"':''} autocomplete="off"></label>`;
    if(config.secret && gateway.saved_secrets[config.key])control+=`<label class="nasr-check nasr-clear-secret"><input type="checkbox" data-gateway-clear="${key}">پاک کردن مقدار ذخیره‌شدهٔ «${h(config.label)}»</label>`;
    return control;
  }
  const transactionStatus=status=>({created:'ایجادشده',redirected:'انتقال به درگاه',paid:'پرداخت موفق',failed:'ناموفق',cancelled:'لغوشده'}[status] || status);
  async function gatewaySettingsPage() {
    const data=await api('payment-gateways');S.gatewayData=data;
    const cards=data.gateways.map(gateway=>`<article class="nasr-gateway-settings-card" data-gateway-id="${h(gateway.id)}"><header><div class="nasr-gateway-mark">${h(gateway.name.slice(0,1))}</div><div><h2>${h(gateway.name)}</h2><p>${h(gateway.description)}</p></div>${badge(gateway.configured?'success':'neutral',gateway.configured?'تنظیمات کامل':'نیازمند تنظیم')}</header><div class="nasr-form-grid"><label class="nasr-check wide"><input type="checkbox" data-gateway-enabled ${gateway.enabled?'checked':''}>فعال در صفحهٔ پرداخت</label>${field('عنوان نمایشی','gateway_title_'+gateway.id,gateway.title,{required:true,maxlength:100}).replace('name="gateway_title_'+gateway.id+'"','data-gateway-title')}${field('توضیح کوتاه','gateway_description_'+gateway.id,gateway.checkout_description,{maxlength:300,wide:true}).replace('name="gateway_description_'+gateway.id+'"','data-gateway-description')}${gateway.fields.map(config=>gatewayField(gateway,config)).join('')}</div><div class="nasr-callback-url"><span>آدرس بازگشت قابل ثبت نزد درگاه</span><code dir="ltr">${h(gateway.callback_url)}</code></div></article>`).join('');
    const logs=data.transactions.length?`<div class="nasr-table-wrap"><table><thead><tr><th>شناسه</th><th>زبان‌آموز / کلاس</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>زمان / پیگیری</th></tr></thead><tbody>${data.transactions.map(tx=>`<tr><td>${num(tx.id)}</td><td><strong>${h(tx.student_name)}</strong><small class="nasr-block">${h(tx.class_name)} · ${h(tx.term_name)}</small></td><td>${h(methodLabel(tx.gateway))}</td><td>${num(tx.amount)} <small>${h(tx.currency)}</small></td><td>${badge(tx.status==='paid'?'success':tx.status==='failed'?'failed':'pending',transactionStatus(tx.status))}${tx.error_message?`<small class="nasr-block nasr-wrap-text">${h(tx.error_message)}</small>`:''}</td><td>${date(tx.paid_at || tx.created_at,true)}<small class="nasr-block"><bdi>${h(tx.reference || '—')}</bdi></small></td></tr>`).join('')}</tbody></table></div>`:empty('هنوز تراکنشی ایجاد نشده است','');
    return `${sectionHead('درگاه‌های پرداخت','')}<form id="nasr-gateway-settings-form">${cards || empty('افزونهٔ درگاهی نصب نشده است','')} ${cards?'<button class="nasr-button primary" type="submit">ذخیرهٔ تنظیمات درگاه‌ها</button>':''}</form><section class="nasr-panel nasr-payment-log">${sectionHead('۵۰ تراکنش اخیر','')}${logs}</section>`;
  }
    const branchName=id=>S.boot.settings.branches.find(b=>+b.id===+id)?.name || 'تعیین نشده';
  const branchOptions=(all=true)=>[...(all?[['','همهٔ شعبه‌ها'],['0','تعیین نشده (سوابق قبلی)']]:[['','شعبه را انتخاب کنید']]),...S.boot.settings.branches.map(b=>[b.id,b.name+(!+b.active?' · غیرفعال':'')])];
  const termLabel=t=>`${t.name} · ${t.code} · ${t.branch_name || branchName(t.branch_id)}${t.deleted_at?' · حذف‌شده':''}`;
  const englishName=p=>[p.first_name_en,p.last_name_en].filter(Boolean).join(' ');
  const toman=v=>`${num(v)} <small>تومان</small>`;
  const branchEditorRow=b=>`<div class="nasr-branch-row" data-branch-row data-branch-id="${h(b.id || '')}"><label class="nasr-field"><span>نام شعبه <b class="nasr-required">*</b></span><input data-branch-name name="branch_name[]" value="${h(b.name || '')}" required maxlength="150"></label><label class="nasr-check"><input type="checkbox" data-branch-active ${b.active===undefined || +b.active?'checked':''}>فعال</label>${!b.id?button('حذف ردیف','remove-branch-row','','text danger'):''}</div>`;
  function branchesEditor(){return `<div class="wide nasr-branches-editor"><h3>شعبه‌ها</h3><div id="nasr-branch-rows">${S.boot.settings.branches.map(branchEditorRow).join('')}</div>${button('افزودن شعبه','add-branch-row','','soft','plus')}</div>`;}
  async function branchesPage(){
    const rows=await api('branches');
    return sectionHead('شعبه','',link('تعریف و ویرایش شعبه‌ها','settings','primary'))+`<section class="nasr-panel"><div class="nasr-class-grid">${rows.map(b=>`<article class="nasr-class-card"><h3>${h(b.name)}</h3><p>${num(b.term_count)} ترم</p>${badge(+b.active?'success':'neutral',+b.active?'فعال':'غیرفعال')}<div class="nasr-actions">${button('ترم‌های این شعبه','choose-branch',b.id,'soft','calendar')}</div></article>`).join('') || empty('هنوز شعبه‌ای تعریف نشده','')}</div><div class="nasr-actions">${button('همهٔ شعبه‌ها','choose-branch','','text')}${button('ترم‌های بدون شعبه','choose-branch','0','text')}</div></section>`;
  }
  const reportLive={timer:null,controller:null,token:0,context:null};
  function reportResult(data,context){
    const actions=`<div class="nasr-actions">${button('خروجی CSV کل نتایج','export-report','','soft')}${button('چاپ کل نتایج / PDF','print-list-report','','soft','print')}</div>`;
    if(data.layout==='teacher_pay_detail')return `${actions}${teacherPayReport(data)}${data.pages>1?pager(data):''}`;
    if(data.layout==='comprehensive_class')return `${actions}${comprehensiveClassReport(data)}${data.pages>1?pager(data):''}`;
    if(data.layout==='student_grades')return `${actions}${studentGradesReport(data)}${data.pages>1?pager(data):''}`;
    return `<h3>${h(data.title)}</h3>${data.metadata?.length?reportMetadata(data):`<p class="nasr-muted">${h(context)}</p>`}${reportSummary(data)}${actions}${data.items.length?reportTable(data)+pager(data):empty('موردی یافت نشد','')}`;
  }
  function scheduleReportSearch(value){
    const ctx=S.report || reportLive.context;if(!ctx || !['reports','financial-reports'].includes(S.view))return;
    clearTimeout(reportLive.timer);reportLive.controller?.abort();const token=++reportLive.token;
    reportLive.context=ctx;S.reportFilters[S.view].q=value;S.page=1;S.report=null;
    const target=document.getElementById('nasr-report-result');if(!target)return;
    target.setAttribute('aria-busy','true');target.querySelectorAll('button').forEach(x=>x.disabled=true);
    reportLive.timer=setTimeout(async()=>{
      const controller=new AbortController();reportLive.controller=controller;const query={...ctx.query,q:value};
      try{const data=await api('reports',undefined,{...query,page:1,per_page:25},{signal:controller.signal});
        if(token!==reportLive.token || !target.isConnected)return;
        const context=reportContext(query,S.reportOptions);S.report={query,data,context};reportLive.context=S.report;target.innerHTML=reportResult(data,context);
      }catch(e){if(e.name!=='AbortError' && token===reportLive.token && target.isConnected)target.innerHTML=empty('جست‌وجو انجام نشد',e.message,button('تلاش دوباره','retry','','soft'));}
      finally{if(token===reportLive.token && target.isConnected)target.setAttribute('aria-busy','false');}
    },300);
  }
  function adjustmentRow(kind,row={}){
    const legacy=kind==='addition' && row.legacy_index!==undefined;
    const details=kind==='addition'
      ? `${field('حق‌التدریس ساعتی (تومان)','adjustment_hourly_rate',row.hourly_rate ?? '',{required:!legacy,mode:'numeric',dir:'ltr'})}${field('تعداد جلسات','adjustment_sessions',row.sessions ?? '',{required:!legacy,mode:'numeric',dir:'ltr'})}${field('میزان ساعت کل','adjustment_total_hours',row.total_hours ?? '',{required:!legacy,mode:'decimal',dir:'ltr'})}${field(legacy?'مبلغ حق‌التدریس کل (تومان) · سابقه قبلی':'مبلغ حق‌التدریس کل (تومان)','adjustment_amount',row.amount ?? '',{readonly:true,dir:'ltr'})}`
      : field('مبلغ (تومان)','adjustment_amount',row.amount ?? '',{required:true,mode:'numeric',dir:'ltr'});
    return `<div class="nasr-adjustment-row" data-adjustment-row="${h(kind)}"${legacy?` data-legacy-index="${h(row.legacy_index)}" data-legacy-amount="${h(row.amount)}"`:''}>${field('بابت','adjustment_reason',row.reason || '',{required:true,maxlength:200,wide:kind==='addition'})}${details}${button('حذف ردیف','remove-adjustment','','text danger')}</div>`;
  }
  function adjustmentFormRows(form){
    return [...form.querySelectorAll('[data-adjustment-row]')].map(row=>{
      const entry={kind:row.dataset.adjustmentRow,reason:row.querySelector('[name=adjustment_reason]').value,amount:row.querySelector('[name=adjustment_amount]').value};
      if(entry.kind==='addition'){
        for(const key of ['hourly_rate','sessions','total_hours'])entry[key]=row.querySelector(`[name=adjustment_${key}]`).value;
        if(row.dataset.legacyIndex!==undefined)entry.legacy_index=row.dataset.legacyIndex;
      }
      return entry;
    });
  }
  async function teacherSalaryPage(){
    const options=await api('my-salary');
    if(!options.terms.some(t=>String(t.id)===String(S.salaryTermId)))S.salaryTermId='';
    const controls=`<section class="nasr-panel"><div class="nasr-form-grid">${select('ترم','salary_term',S.salaryTermId || '',[['','ترم را انتخاب کنید'],...options.terms.map(t=>[t.id,termLabel(t)])])}</div></section>`;
    const head=sectionHead('حقوق','',button('به‌روزرسانی','retry','','soft'))+controls;
    if(!S.salaryTermId)return head+`<section class="nasr-panel">${empty(options.terms.length?'ترم را انتخاب کنید':'ترمی برای نمایش حقوق وجود ندارد','')}</section>`;
    const res=await api('my-salary',undefined,{term_id:S.salaryTermId});
    if(res.locked)return head+`<section class="nasr-panel" id="nasr-salary-locked"><div class="nasr-alert" role="status">${h(res.message)}</div><div class="nasr-table-wrap"><table><thead><tr><th>کلاس</th><th>نمرات ناقص (نفر)</th></tr></thead><tbody>${(res.pending_classes || []).map(c=>`<tr><td>${h(c.class_name)}<div class="nasr-block">${c.active?link('تکمیل نمرات',`classes/${c.class_id}`,'text'):'کلاس بایگانی است؛ با مدیر هماهنگ کنید.'}</div></td><td>${num(c.missing_count)}</td></tr>`).join('')}</tbody></table></div></section>`;
    const amount=v=>v==null?'تعیین نشده':toman(v),totals=res.totals;
    return head+`<section class="nasr-panel" id="nasr-my-salary"><div class="nasr-table-wrap"><table><thead><tr><th>نام کلاس</th><th>حق‌التدریس (تومان)</th></tr></thead><tbody>${res.items.map(c=>`<tr><td>${h(c.class_name)}</td><td>${amount(c.amount)}</td></tr>`).join('')}</tbody><tfoot><tr><th scope="row">جمع حق‌التدریس کلاس‌ها</th><td>${amount(totals.teaching)}</td></tr></tfoot></table></div><div class="nasr-report-summary">${[['جمع حق‌التدریس کلاس‌ها',totals.teaching],['اضافه‌حقوق',totals.addition],['کاهش‌حقوق',totals.deduction],['حقوق نهایی',totals.net]].map(([label,value])=>`<div><span>${h(label)}</span><strong>${amount(value)}</strong></div>`).join('')}</div>${totals.net==null?'<div class="nasr-alert" role="status">اطلاعات حق‌التدریس بعضی کلاس‌ها هنوز توسط مدیر تکمیل نشده است.</div>':''}</section>`;
  }
  async function payrollPage(){
    if(!S.payrollFilters)S.payrollFilters={term_id:'',teacher_id:''};
    let f=S.payrollFilters,res=await api('teacher-payroll',undefined,f);
    const terms=res.terms.filter(t=>S.branchId==='' || +t.branch_id===+S.branchId);
    if(f.term_id && !terms.some(t=>+t.id===+f.term_id)){f=S.payrollFilters={term_id:'',teacher_id:''};res=await api('teacher-payroll');}
    if(f.teacher_id && !res.teachers.some(p=>+p.user_id===+f.teacher_id))f.teacher_id='';
    S.payrollData=res;
    const controls=`<section class="nasr-panel"><div class="nasr-form-grid">${select('۱. انتخاب ترم','payroll_term',f.term_id,[['','ابتدا ترم را انتخاب کنید'],...terms.map(t=>[t.id,termLabel(t)])])}${select('۲. معلم‌های این ترم','payroll_teacher',f.teacher_id,[['',f.term_id?'معلم را انتخاب کنید':'ابتدا ترم را انتخاب کنید'],...res.teachers.map(p=>[p.user_id,fullName(p)+(p.deleted_at?' · حذف‌شده':'')])])}</div></section>`;
    let body=empty('ترم و معلم را انتخاب کنید','');
    if(f.teacher_id){const c=res.calculation;
      body=`<section class="nasr-panel"><form id="nasr-payroll-form"><div class="nasr-adjustments-grid">${[['addition','اضافه‌حقوق'],['deduction','کاهش‌حقوق']].map(([kind,title])=>`<fieldset class="nasr-adjustments"><legend>${title}</legend><div data-adjustments="${kind}">${res.rows.filter(r=>r.kind===kind).map(r=>adjustmentRow(kind,r)).join('')}</div>${button('افزودن ردیف','add-adjustment',kind,'soft','plus')}</fieldset>`).join('')}</div><div class="nasr-report-summary"><div><span>حق‌التدریس پس از کسورات تأخیر</span><strong>${c?.teaching_net==null?'اطلاعات کلاس‌ها ناقص است':toman(c.teaching_net)}</strong></div><div><span>جمع اضافه‌حقوق</span><strong id="nasr-payroll-add">${toman(c?.salary_addition || 0)}</strong></div><div><span>جمع کاهش‌حقوق</span><strong id="nasr-payroll-deduct">${toman(c?.salary_deduction || 0)}</strong></div><div><span>حقوق نهایی</span><strong id="nasr-payroll-total">${c?.net==null?'قابل محاسبه نیست':toman(c.net)}</strong></div></div><button class="nasr-button primary" type="submit">ذخیرهٔ اضافه و کاهش حقوق</button></form><details class="nasr-payroll-classes"><summary>نرخ و سابقهٔ کلاس‌های این ترم</summary><div class="nasr-table-wrap"><table><thead><tr><th>کلاس</th><th>نرخ ساعتی (تومان)</th><th>جلسات برگزارشده</th><th>دقیقهٔ جلسه</th><th>دقایق تأخیر</th></tr></thead><tbody>${res.classes.map(c=>`<tr><td>${c.deleted_at?h(c.name)+' · حذف‌شده':`<a href="#classes/${c.id}">${h(c.name)}</a>`}</td><td>${c.hourly_rate===null?'تعیین نشده':num(c.hourly_rate)}</td><td>${c.sessions_held===null?'تعیین نشده':num(c.sessions_held)}</td><td>${c.duration===null?'تعیین نشده':num(c.duration)}</td><td>${num(c.late_minutes)}</td></tr>`).join('')}</tbody></table></div></details></section>`;
    }
    return sectionHead('اضافه و کاهش حقوق','')+controls+body;
  }
  function updatePayrollPreview(){
    let add=0,deduct=0,invalid=false;
    root.querySelectorAll('[data-adjustment-row]').forEach(row=>{
      const amountField=row.querySelector('[name=adjustment_amount]');
      if(row.dataset.adjustmentRow==='addition'){
        const fields=['hourly_rate','sessions','total_hours'].map(k=>row.querySelector(`[name=adjustment_${k}]`)),values=fields.map(el=>digits(el.value).trim());
        const legacy=row.dataset.legacyIndex!==undefined && values.every(v=>v==='');
        fields.forEach(el=>el.required=!legacy);
        if(legacy)amountField.value=row.dataset.legacyAmount;
        else {
          const [rate,sessions,hours]=values.map(Number),complete=/^\d+$/.test(values[0]) && /^\d+$/.test(values[1]) && /^\d+(?:\.\d{1,2})?$/.test(values[2]) && rate>=1 && rate<=1e9 && sessions>=1 && sessions<=1000 && hours>=.01 && hours<=1e5;
          const total=complete?Math.floor((rate*Math.round(hours*100)+50)/100):NaN;
          amountField.value=Number.isSafeInteger(total)&&total>=1&&total<=1e12?String(total):'';
          if(amountField.value==='')invalid=true;
        }
        add+=Number(amountField.value)||0;
      } else deduct+=Number(digits(amountField.value))||0;
    });
    const c=S.payrollData?.calculation,a=root.querySelector('#nasr-payroll-add'),d=root.querySelector('#nasr-payroll-deduct'),t=root.querySelector('#nasr-payroll-total');
    if(a)a.innerHTML=invalid?'اطلاعات اضافه‌حقوق ناقص است':toman(add);if(d)d.innerHTML=toman(deduct);if(t)t.innerHTML=invalid?'اطلاعات اضافه‌حقوق ناقص است':c?.teaching_net==null?'اطلاعات کلاس‌ها ناقص است':toman(+c.teaching_net+add-deduct);
  }
  function updateExtraFees(box){const extra=Object.keys(extraFeeLabels).reduce((sum,key)=>sum+(Number(digits(box.querySelector(`[name=${key}]`).value))||0),0),total=+box.dataset.base+extra,paid=+box.dataset.paid;
    root.querySelector('#nasr-fees-total').innerHTML=money(total);root.querySelector('#nasr-fees-balance').innerHTML=money(Math.max(0,total-paid));root.querySelector('#nasr-fees-credit').textContent=paid>total?`بستانکاری: ${num(paid-total)} ${S.boot.settings.currency}`:'';
  }

  const academicReports=[['comprehensive_class','گزارشات کلاسی جامع'],['class_grades','گزارش نمرات کلاسی زبان‌آموزان'],['student_grades','گزارش کارنامه نمرات زبان‌آموز'],['ungraded_students','گزارش زبان‌آموزان بدون نمره'],['attendance','لیست حضور و غیاب'],['classes','لیست کلاس‌های هر ترم'],['students','لیست زبان‌آموزان ترم / کلاس'],['new_students','لیست زبان‌آموزان جدید در یک ترم'],['growth','لیست ریزش و رویش زبان‌آموزان'],['teacher_classes','لیست کلاس‌های یک معلم در یک ترم']];
  const financialReports=[['payments','لیست پرداختی‌های ترم و سال'],['unpaid','زبان‌آموزان پرداخت‌نکرده / بدهکار'],['student_payments','پرداخت‌های شخص در ترم‌های مختلف'],['teacher_pay','حق‌التدریس معلم در یک ترم'],['teacher_history','حق‌التدریس معلم در ترم‌های مختلف'],['annual','گزارش سالیانه']];
  const attendanceLabels={present:'حاضر',absent:'غایب',late:'تأخیر',excused:'موجه',unmarked:'ثبت نشده'};
  function reportValue(value,type,plain=false){
    if(value===null || value===undefined || value==='')return '—';
    if(type==='attendance')return ({present:'P',absent:'A',late:'L',excused:'E'}[value] || '');
    if(type==='yesno')return +value?'بله':'خیر';
    if(type==='date')return date(value);
    if(type==='date_list')return String(value).split('|').filter(Boolean).map(v=>toPersianDigits(dateInput(v))).join(plain?' | ':'<br>') || '—';
    if(type==='multiline')return String(value).split('|').filter(Boolean).map(v=>plain?String(v):h(v)).join(plain?' | ':'<br>') || '—';
    if(type==='money')return plain?String(value):money(value);
    if(type==='toman')return plain?String(value):toman(value);
    if(type==='number' && plain)return String(value);
    if(type==='number_en')return plain?String(value):numEN(value);
    if(type==='number')return new Intl.NumberFormat('fa-IR',{maximumFractionDigits:2}).format(Number(value));
    return plain?String(value):h(value);
  }
  function reportCell(row,column,plain=false){
    if(column.max_key)return `${row[column.key]===null || row[column.key]===undefined?'—':numEN(row[column.key])} / ${numEN(row[column.max_key])}`;
    return reportValue(row[column.key],column.type,plain);
  }
  function studentGradesReport(data,rows=data.items,options={}){
    const groups=new Map();for(const row of rows){const key=String(row.term_id);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(row);}
    const metadata=options.metadata===false?'':`<div class="nasr-report-metadata">${(data.metadata || []).map(x=>`<div><span>${h(x.label)}</span><strong dir="auto">${x.value===null || x.value===undefined || x.value===''?'Not set':reportValue(x.value,x.type)}</strong></div>`).join('')}</div>`;
    return `<article class="nasr-student-grade-report" lang="en" dir="ltr"><h3>${h(data.title)}</h3>${metadata}${[...groups.values()].map(items=>{const term=items[0];return `<section class="nasr-student-grade-term"><h4>Term: <bdi>${h(term.term_name)}</bdi> · <bdi>${h(term.term_code)}</bdi></h4><div class="nasr-student-grade-branch">Branch: <bdi>${h(term.branch_name)}</bdi></div>${reportTable(data,items)}</section>`;}).join('') || '<p class="nasr-muted">No academic records found.</p>'}</article>`;
  }
  function reportTable(report,rows=report.items){
    const attendance=report.layout==='attendance';
    return `<div class="nasr-table-wrap"><table class="nasr-report-table ${attendance?'nasr-attendance-table nasr-attendance-sheet':report.layout==='teacher_classes'?'nasr-teacher-classes-table':report.layout==='teacher_pay_detail'?'nasr-payroll-class-table':report.layout==='student_registration'?'nasr-registration-table':report.layout==='annual'?'nasr-annual-table':report.layout==='student_grades'?'nasr-student-grade-table':''}" dir="${report.direction || 'rtl'}"><thead><tr>${report.columns.map(c=>`<th data-col="${h(c.key)}" scope="col">${h(c.label)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${report.columns.map(c=>`<td data-col="${h(c.key)}"${c.key==='english_name'?' dir="ltr"':''}>${report.empty_cells && (row[c.key]===null || row[c.key]===undefined || row[c.key]==='' || row[c.key]==='unmarked')?'':reportCell(row,c)}</td>`).join('')}</tr>`).join('')}</tbody>${report.totals?`<tfoot><tr>${report.columns.map((c,i)=>`<td data-col="${h(c.key)}">${i===0?h(report.totals_label || 'جمع')+(['number','toman','money'].includes(c.type)?'<br>'+reportValue(report.totals[c.key],c.type):''):reportValue(report.totals[c.key],c.type)}</td>`).join('')}</tr></tfoot>`:''}</table></div>`;
  }
  function comprehensiveClassReport(report,rows=report.items,options={}){
    const info=report.class_info || {},columns=options.columns || report.columns,minimum=options.fillRows===false?0:10;
    const compactDate=value=>value?toPersianDigits(dateInput(value)):'—';
    const cell=(row,column)=>{
      if(row.__blank)return column.key==='row_number'?num(row.row_number):'&nbsp;';
      const value=row[column.key];
      if(report.empty_cells && (value===null || value===undefined || value==='' || value==='unmarked'))return '&nbsp;';
      if(column.type==='date_list' || column.type==='multiline')return reportValue(value,column.type);
      return reportValue(value,column.type);
    };
    const displayRows=rows.slice(),populated=displayRows.length,last=populated?Number(displayRows[populated-1].row_number):((Number(report.page)||1)-1)*25;
    for(let index=populated;index<minimum;index++)displayRows.push({__blank:true,row_number:last+(index-populated)+1});
    return `<article class="nasr-comprehensive-report" dir="rtl"><h2>لیست کلاس ${h(info.term_code || '')}</h2><div class="nasr-comprehensive-head"><div class="nasr-comprehensive-class-name"><b>نام کلاس:</b><strong>${h(info.class_name || '—')}</strong></div><div><b>تایم کلاس:</b><span>${h(info.schedule || '—')}</span></div><div class="nasr-comprehensive-fees"><b>مبلغ شهریه:</b><span>${num(info.base_fee)} <small>${h(S.boot.settings.currency)}</small> شهریه</span><i>—</i><span>${num(info.extras_fee)} <small>${h(S.boot.settings.currency)}</small> کتاب و کپی</span></div><div><b>نام معلم:</b><span>${h(info.teacher_name || '—')}</span></div><div class="nasr-comprehensive-dates"><span><b>شروع ترم:</b> ${compactDate(info.start_date)}</span><span><b>میانترم:</b> ${compactDate(info.mid_date)}</span><span><b>پایانترم:</b> ${compactDate(info.final_date)}</span></div></div><div class="nasr-table-wrap"><table class="nasr-comprehensive-table"><thead><tr>${columns.map(column=>`<th data-col="${h(column.key)}">${h(column.label)}</th>`).join('')}</tr></thead><tbody>${displayRows.map(row=>`<tr class="${row.__blank?'nasr-comprehensive-blank':''}">${columns.map(column=>`<td data-col="${h(column.key)}"${column.key==='mobile'?' dir="ltr"':''}>${cell(row,column)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></article>`;
  }
  function payrollAdjustmentReports(data){
    return ['addition','deduction'].map(kind=>{
      const items=data.adjustments[kind] || [],addition=kind==='addition';
      const columns=addition?[{key:'reason',label:'بابت'},{key:'hourly_rate',label:'حق‌التدریس ساعتی',type:'toman'},{key:'sessions',label:'تعداد جلسات',type:'number'},{key:'total_hours',label:'میزان ساعت کل',type:'number'},{key:'amount',label:'مبلغ حق‌التدریس کل',type:'toman'}]:[{key:'reason',label:'بابت'},{key:'amount',label:'مبلغ',type:'toman'}];
      const totals={};for(const c of columns.slice(1))totals[c.key]=items.reduce((sum,row)=>sum+Number(row[c.key] || 0),0);
      if(addition)totals.total_hours=Math.round(totals.total_hours*100)/100;
      return {title:addition?'اضافه‌حقوق':'کاهش‌حقوق',columns,items,totals,totals_label:addition?'جمع اضافه‌حقوق':'جمع کاهش‌حقوق'};
    });
  }
  function payrollSettlementRows(data){
    const c=data.calculation;
    return [
      ['حق‌التدریس قابل محاسبهٔ کل کلاس‌های ترم پس از کسورات تأخیر',c.calculable_teaching_net],
      ['جمع اضافه‌حقوق (+)',c.salary_addition],['جمع کاهش‌حقوق (−)',c.salary_deduction],
      [c.missing?'حقوق نهایی قابل محاسبه':'حقوق نهایی',c.calculable_net]
    ];
  }
  function teacherPayReport(data,rows=data.items){
    return `<article class="nasr-payroll-report"><h3>${h(data.title)}</h3>${reportMetadata(data)}<h4>جزئیات کلاس‌ها</h4>${reportTable(data,rows)}${payrollAdjustmentReports(data).map(report=>`<section class="nasr-payroll-adjustment-report"><h4>${h(report.title)}</h4>${reportTable(report)}</section>`).join('')}<section class="nasr-payroll-settlement"><h4>محاسبهٔ حقوق ترم</h4><table><tbody>${payrollSettlementRows(data).map(([label,value],index)=>`<tr${index===3?' class="nasr-payroll-final"':''}><th scope="row">${h(label)}</th><td>${toman(value)}</td></tr>`).join('')}</tbody></table>${data.calculation.missing?`<div class="nasr-alert error" role="status">اطلاعات ${num(data.calculation.missing)} کلاس ناقص است؛ مبلغ نمایش‌داده‌شده فقط شامل حق‌التدریس قابل محاسبه است.</div>`:''}</section></article>`;
  }
  function reportMetadata(report){return report.metadata?.length?`<div class="nasr-report-metadata">${report.metadata.map(x=>`<div><span>${h(x.label)}</span><strong>${x.value?reportValue(x.value,x.type):'تعیین نشده'}</strong></div>`).join('')}</div>`:'';}
  function reportSummary(report){return `<div class="nasr-report-summary">${report.summary.map(x=>`<div><span>${h(x.label)}</span><strong>${reportValue(x.value,x.type)}</strong></div>`).join('')}</div>`;}
  function reportContext(filters,options){
    const pieces=[S.boot.settings.academy_name];
    if(filters.branch_id!=='')pieces.push(`شعبه: ${branchName(filters.branch_id)}`);
    for(const [k,caption] of [['term_id','ترم'],['previous_term_id','ترم مبنا']]){
      const t=options.terms.find(x=>+x.id===+filters[k]);if(t)pieces.push(`${caption}: ${termLabel(t)}`);
    }
    if(filters.year)pieces.push(`سال شروع ترم: ${filters.year}`);
    if(filters.paid_year)pieces.push(`سال پرداخت: ${filters.paid_year}`);
    if(filters.class_id){const c=options.classes.find(x=>+x.id===+filters.class_id);if(c)pieces.push(`کلاس: ${c.name}`);}
    for(const k of ['student_id','teacher_id']){const p=options.people.find(x=>+x.user_id===+filters[k]);if(p)pieces.push(fullName(p));}
    if(filters.q)pieces.push(`جست‌وجو: ${filters.q}`);
    return pieces.join(' | ');
  }
  async function reportsPage(finance){
    const options=await api('report-options');S.reportOptions=options;
    const key=finance?'financial-reports':'reports';
    if(!S.reportFilters[key])S.reportFilters[key]={type:finance?'payments':'classes',branch_id:S.branchId};
    const f=S.reportFilters[key],types=finance?financialReports:academicReports;
    if(!types.some(x=>x[0]===f.type))f.type=types[0][0];
    const noGrades=f.type==='ungraded_students',annual=f.type==='annual',hasBranch=f.branch_id!=='' && f.branch_id!=null;
    if(noGrades)f.year='';
    const annualYears=[...new Set(options.terms.filter(t=>!t.deleted_at && (!hasBranch || +t.branch_id===+f.branch_id)).map(t=>dateInput(t.start_date).split('/')[0]))].filter(y=>/^\d{4}$/.test(y)).sort((a,b)=>+b-+a);
    if(annual){
      for(const k of ['term_id','class_id','teacher_id','student_id','paid_year','previous_term_id'])f[k]='';
      if(f.year && !annualYears.includes(digits(f.year)))f.year='';
    }
    const year=digits(f.year || ''), list=options.terms.filter(t=>(!noGrades || hasBranch) && !t.deleted_at && (f.branch_id==='' || +t.branch_id===+f.branch_id) && (!year || dateInput(t.start_date).startsWith(year+'/')));
    if(f.term_id && !list.some(t=>+t.id===+f.term_id))f.term_id='';
    const isTeacher=['teacher_pay','teacher_history','teacher_classes'].includes(f.type),isAttendance=f.type==='attendance';
    const needClass=['attendance','comprehensive_class','class_grades'].includes(f.type),needTerm=['comprehensive_class','class_grades','ungraded_students','new_students','growth','teacher_pay','teacher_classes'].includes(f.type),needPerson=['student_payments','student_grades','teacher_history','teacher_pay','teacher_classes'].includes(f.type);
    if(isTeacher && f.term_id && f.teacher_id && !options.classes.some(c=>+c.term_id===+f.term_id && +c.teacher_id===+f.teacher_id && !c.deleted_at))f.teacher_id='';
    if(f.teacher_id && !options.people.some(p=>p.kind==='teacher' && !p.deleted_at && +p.user_id===+f.teacher_id))f.teacher_id='';
    if(f.student_id && !options.people.some(p=>!p.deleted_at && +p.user_id===+f.student_id))f.student_id='';
    const selectedTerm=options.terms.find(t=>+t.id===+f.term_id);
    const classList=options.classes.filter(c=>!c.deleted_at && list.some(t=>+t.id===+c.term_id) && (!f.term_id || +c.term_id===+f.term_id) && (!f.teacher_id || !isTeacher || +c.teacher_id===+f.teacher_id));
    if(f.class_id && !classList.some(c=>+c.id===+f.class_id))f.class_id='';
    const prev=options.terms.filter(t=>!t.deleted_at && selectedTerm && +t.branch_id===+selectedTerm.branch_id && t.start_date<selectedTerm.start_date);
    if(f.previous_term_id && !prev.some(t=>+t.id===+f.previous_term_id))f.previous_term_id='';
    const peopleOptions=kind=>[['','انتخاب کنید'],...options.people.filter(p=>p.kind===kind && !p.deleted_at && (kind!=='teacher' || !f.term_id || options.classes.some(c=>+c.teacher_id===+p.user_id && +c.term_id===+f.term_id && !c.deleted_at))).map(p=>[p.user_id,fullName(p)+(p.student_code?` · ${p.student_code}`:'')+(p.deleted_at?' · حذف‌شده':'')])];
    let controls=select('نوع گزارش','type',f.type,types)+select(noGrades?'شعبه *':'شعبه','branch_id',f.branch_id,noGrades?[['','شعبه را انتخاب کنید'],...branchOptions().filter(([id])=>id!=='')]:branchOptions());
    if(annual)controls+=select('سال *','year',digits(f.year || ''),[['',annualYears.length?'سال را انتخاب کنید':'ترمی ثبت نشده است'],...annualYears.map(y=>[y,toPersianDigits(y)])]);
    else if(!noGrades)controls+=field('سال شروع ترم','year',f.year || '',{dir:'ltr',mode:'numeric',placeholder:S.boot.settings.calendar==='jalali'?'مثلاً ۱۴۰۵':'مثلاً 2026',help:'خالی = همهٔ سال‌ها'});
    if(!annual)controls+=select(needTerm?'ترم *':'ترم','term_id',f.term_id,[['',noGrades?(hasBranch?'ترم را انتخاب کنید':'ابتدا شعبه را انتخاب کنید'):'همهٔ ترم‌ها / انتخاب کنید'],...list.map(t=>[t.id,termLabel(t)])]).replace('name="term_id"',`name="term_id" ${noGrades&&!hasBranch?'disabled':''}`);
    if(f.type==='growth')controls+=select('ترم مبنا برای مقایسه *','previous_term_id',f.previous_term_id,[['','ترم قبلی همان شعبه را انتخاب کنید'],...prev.map(t=>[t.id,termLabel(t)])])+select('نوع فهرست','movement',f.movement || 'changes',[['changes','فقط ریزش و رویش'],['all','ریزش، رویش و ماندگار']]);
    if(isTeacher)controls+=select(needPerson?'معلم *':'معلم','teacher_id',f.teacher_id,peopleOptions('teacher'));
    if(['comprehensive_class','class_grades','students','attendance','payments','unpaid'].includes(f.type)){
      const sequential=f.type==='class_grades',availableClasses=sequential&&!f.term_id?[]:classList;
      controls+=select(needClass?'کلاس *':'کلاس','class_id',f.class_id,[['',sequential&&!f.term_id?'ابتدا ترم را انتخاب کنید':'همهٔ کلاس‌ها / انتخاب کنید'],...availableClasses.map(c=>[c.id,`${c.name} · ${options.terms.find(t=>+t.id===+c.term_id)?.name || ''}`])]);
    }
    if(['student_payments','student_grades'].includes(f.type))controls+=studentSearchField(f.student_id,options,f.type==='student_grades'?'grades':'financial');
    if(f.type==='payments')controls+=field('سال پرداخت (اختیاری)','paid_year',f.paid_year || '',{dir:'ltr',mode:'numeric',help:'سال تاریخ واریز؛ مستقل از سال ترم'});
    if(f.type==='unpaid')controls+=select('نوع بدهکار','debt_mode',f.debt_mode || 'unpaid',[['unpaid','بدون هیچ پرداخت موفق'],['remaining','همهٔ دارای مانده (شامل پرداخت ناقص)']]);
    if(isAttendance)controls+=field('جلسهٔ شروع','session_from',f.session_from || 1,{dir:'ltr',mode:'numeric',help:'۲۰ ستون جلسه از این شماره نمایش داده می‌شود؛ پیش‌فرض: Session 1 تا Session 20'});
    controls+=field('جست‌وجوی زنده در گزارش','q',f.q || '',{wide:true,maxlength:150,help:'نام، کد یا نام کلاس را بنویسید؛ نتایج همهٔ صفحات بدون زدن دکمه جست‌وجو می‌شوند.'});
    const form=`<form id="nasr-report-filter" class="nasr-report-filter"><div class="nasr-form-grid">${controls}</div><button type="submit" class="nasr-button primary">نمایش گزارش</button></form>`;
    let resultHTML='';S.report=null;
    const personKey=isTeacher?'teacher_id':'student_id';
    const ready=(!annual || f.year) && (!noGrades || hasBranch) && (!needClass || f.class_id) && (!needTerm || f.term_id) && (!needPerson || f[personKey]) && (f.type!=='growth' || f.previous_term_id);
    if(!ready)resultHTML=empty('فیلترهای گزارش را انتخاب کنید','');
    else{
      // Send only applicable filters, so a hidden selection cannot restrict a different report.
      const query={type:f.type,branch_id:f.branch_id,year:digits(f.year || ''),term_id:f.term_id,q:f.q || ''};
      if(annual)delete query.term_id;
      const keys=f.type==='growth'?['previous_term_id','movement']:isAttendance?['class_id','session_from']:['comprehensive_class','class_grades'].includes(f.type)?['class_id']:isTeacher?['teacher_id']:['student_payments','student_grades'].includes(f.type)?['student_id']:f.type==='payments'?['class_id','paid_year']:f.type==='unpaid'?['class_id','debt_mode']:f.type==='students'?['class_id']:[];
      for(const k of keys)query[k]=f[k] || '';
      try{
        const data=await api('reports',undefined,{...query,page:S.page,per_page:25});
        const context=reportContext(query,options);S.report={query,data,context};
        resultHTML=`<div id="nasr-report-result">${reportResult(data,context)}</div>`;
      }catch(error){resultHTML=empty('گزارش آماده نشد',error.message);}
    }
    return sectionHead(finance?'گزارشات مالی':'گزارشات','')+`<section class="nasr-panel">${form}${resultHTML}</section>`;
  }
  async function allReportRows(ctx){
    const first=await api('reports',undefined,{...ctx.query,page:1,per_page:100});
    let rows=first.items;
    for(let page=2;page<=first.pages;page++){
      toast(`در حال آماده‌سازی گزارش: ${num(page)} از ${num(first.pages)} صفحه`);
      const next=await api('reports',undefined,{...ctx.query,page,per_page:100});rows=rows.concat(next.items);
    }
    return {data:first,rows};
  }
  async function exportReport(print=false){
    const ctx=S.report;if(!ctx)throw new Error('ابتدا گزارش را نمایش دهید.');
    const {data,rows}=await allReportRows(ctx);
    if(print){ extras.printTable(data,rows,ctx.query.type,ctx.context.replace(S.boot.settings.academy_name,'').replace(/^[ ·،]+/,''));return; }
    const cell=value=>{let s=String(value ?? '');if(/^[\s]*[=+@-]/.test(s))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';};
    const columns=[...(data.export_columns || []),...data.columns];
    const lines=[[data.title],[ctx.context],...(data.metadata || []).map(x=>[x.label,reportValue(x.value,x.type,true)]),columns.map(c=>c.label+(c.type==='money'?` (${S.boot.settings.currency})`:c.type==='toman'?' (تومان)':'')),...rows.map(row=>columns.map(c=>data.empty_cells && (row[c.key]===null || row[c.key]===undefined || row[c.key]==='' || row[c.key]==='unmarked')?'':reportCell(row,c,true)))];
    if(data.totals && data.layout!=='teacher_pay_detail')lines.push(data.columns.map((c,i)=>i===0?data.totals_label || 'جمع':reportValue(data.totals[c.key],c.type,true)));
    if(data.layout==='teacher_pay_detail'){
      lines.push(data.columns.map((c,i)=>i===0?data.totals_label:reportValue(data.totals[c.key],c.type,true)));
      for(const report of payrollAdjustmentReports(data)){
        lines.push([],[report.title],report.columns.map(c=>c.label+(c.type==='toman'?' (تومان)':'')),...report.items.map(row=>report.columns.map(c=>reportValue(row[c.key],c.type,true))),report.columns.map((c,i)=>i===0?report.totals_label:reportValue(report.totals[c.key],c.type,true)));
      }
      lines.push([],['محاسبهٔ حقوق ترم (تومان)'],...payrollSettlementRows(data));
      if(data.calculation.missing)lines.push(['کلاس‌های دارای اطلاعات ناقص',data.calculation.missing]);
    }
    const blob=new Blob(['\ufeff'+lines.map(line=>line.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'});
    const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`nasr-${ctx.query.type}-${today()}.csv`;root.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    toast(`${num(rows.length)} ردیف آماده و دانلود شد.`);
  }
  async function attendanceModal(classId,sessionNo=1){
    const a=await api('attendance',undefined,{class_id:classId,session_no:sessionNo});
    const body=`<div class="nasr-form-grid">${select('جلسه','attendance_session',a.session_no,Array.from({length:+a.sessions_planned},(_,i)=>[i+1,`Session ${i+1}`]))}${field('تاریخ جلسه','session_date',dateInput(a.session_date || today()),{required:true,dir:'ltr',date:true})}</div><p class="nasr-muted">${h(a.class_name)}</p><div class="nasr-table-wrap"><table dir="ltr" class="nasr-attendance-table"><thead><tr><th>Name</th><th>Status</th></tr></thead><tbody>${a.rows.map(p=>`<tr><td>${h(englishName(p))}</td><td><select data-attendance-id="${p.enrollment_id}" aria-label="وضعیت ${h(englishName(p))}">${Object.entries(attendanceLabels).map(([key,label])=>`<option value="${key}" ${p.status===key?'selected':''}>${label}</option>`).join('')}</select></td></tr>`).join('')}</tbody></table></div>`;
    modal(`حضور و غیاب · Session ${a.session_no}`,body,form=>saved('attendance',{class_id:classId,session_no:a.session_no,session_date:formData(form).session_date,version:a.version,rows:[...form.querySelectorAll('[data-attendance-id]')].map(el=>({enrollment_id:+el.dataset.attendanceId,status:el.value}))}),'ذخیرهٔ حضور و غیاب',true);
    const form=document.getElementById('nasr-modal-form');
    form.dataset.attendanceClass=classId;form.dataset.attendanceSession=a.session_no;
  }

  const auditNames={branches_saved:'ذخیرهٔ شعبه‌ها',teacher_rate_saved:'ثبت نرخ ساعتی معلم',attendance_saved:'ثبت حضور و غیاب',person_created:'ایجاد پرونده',person_updated:'ویرایش پرونده',student_code_assigned:'اختصاص کد زبان‌آموزی',term_saved:'ذخیرهٔ ترم',class_saved:'ذخیرهٔ کلاس',enrolled:'ثبت‌نام',enrollments_copied:'افزودن گروهی از کلاس قبلی',enrollment_updated:'ویرایش ثبت‌نام و تخفیف',grades_saved:'ثبت نمره',payment_recorded:'ثبت پرداخت',payment_voided:'ابطال سابقهٔ پرداخت',online_payment_started:'شروع پرداخت اینترنتی',online_payment_confirmed:'تأیید پرداخت اینترنتی',payment_gateways_saved:'تنظیمات درگاه‌های پرداخت',settings_saved:'تنظیمات',password_changed:'تغییر رمز',record_deleted:'انتقال به حذف‌شده‌ها',record_restored:'بازیابی مورد حذف‌شده',record_purged:'حذف همیشگی'};
  async function audit() {
    const res=await api('audit',undefined,{page:S.page});
    return `${sectionHead('تاریخچه تغییرات','')}<section class="nasr-panel">${res.items.length?`<div class="nasr-table-wrap"><table><thead><tr><th>کاربر</th><th>عملیات</th><th>شناسهٔ رکورد</th><th>زمان تهران</th><th>جزئیات</th></tr></thead><tbody>${res.items.map(a=>`<tr><td>${h(a.actor_name)}</td><td>${h(auditNames[a.action] || a.action)}</td><td>${num(a.entity_id)}</td><td>${date(a.created_at,true)}</td><td><details><summary>مشاهده</summary><pre dir="ltr">${h(JSON.stringify(safeJSON(a.details),null,2))}</pre></details></td></tr>`).join('')}</tbody></table></div>${pager(res)}`:empty('هنوز تغییری ثبت نشده است','')}</section>`;
  }
  const entityNames={people:'پرونده',classes:'کلاس',terms:'ترم',enrollments:'عضویت در کلاس'};
  async function trash() {
    const res=await api('trash',undefined,{page:S.page});S.trash=res.items;
    return `${sectionHead('حذف‌شده‌ها','')}<section class="nasr-panel">${res.items.length?`<div class="nasr-table-wrap"><table><thead><tr><th>عنوان</th><th>نوع</th><th>زمان حذف</th><th>عملیات</th></tr></thead><tbody>${res.items.map(item=>`<tr><td><strong>${h(item.name)}</strong><small class="nasr-block">${h(item.context)}</small></td><td>${h(entityNames[item.entity])}</td><td>${date(item.deleted_at,true)}</td><td>${button('بازیابی','restore-record',item.entity+'/'+item.id,'soft')}${button('حذف همیشگی','purge-record',item.entity+'/'+item.id,'destructive')}</td></tr>`).join('')}</tbody></table></div>${pager(res)}`:empty('حذف‌شده‌ای وجود ندارد','')}</section>`;
  }
  async function deleteModal(entity,id) {
    let name='',note='';
    if(entity==='people'){
      const person=await api(`people/${id}`);name=fullName(person);
      note=person.kind==='teacher'?'ابتدا معلم کلاس‌های وابسته را تغییر دهید یا آن کلاس‌ها را حذف کنید.':'ورود این زبان‌آموز بسته می‌شود و از فهرست کلاس‌ها و فرزندان قابل مشاهدهٔ خانواده کنار می‌رود.';
    }
    if(entity==='terms'){const term=S.terms.find(t=>+t.id===+id);name=term?.name || 'ترم';note='برای حذف ترم، ابتدا کلاس‌های آن را حذف یا به ترم دیگری منتقل کنید.';}
    if(entity==='classes'){name=S.detail.class.name;note='این کلاس و فهرست اعضای آن از پنل کاربران کنار می‌روند؛ پروندهٔ افراد حذف نمی‌شود.';}
    if(entity==='enrollments'){name=fullName(enrollment(id));note='فقط عضویت این زبان‌آموز در کلاس حذف می‌شود؛ پرونده و سایر کلاس‌های او باقی می‌مانند.';}
    modal(`تأیید حذف ${name}`,`<p>${h(note)}</p><label class="nasr-check"><input type="checkbox" name="confirm" required>حذف «${h(name)}» و حفظ سوابق را تأیید می‌کنم.</label>`,form=>saved(`${entity}/${id}/delete`,formData(form),async()=>{
      if(entity==='classes')location.hash='classes';
      else {if(entity==='terms')S.terms=[];await render();}
    }),'تأیید حذف');
    document.querySelector('#nasr-modal-form button[type="submit"]').classList.add('destructive');
  }
  function restoreModal(key) {
    const item=S.trash?.find(row=>row.entity+'/'+row.id===key);if(!item)throw new Error('مورد پیدا نشد؛ صفحه را تازه کنید.');
    modal('بازیابی '+item.name,`<p>«${h(item.name)}» با همان اطلاعات، وضعیت فعال/غیرفعال و سوابق قبلی بازمی‌گردد.</p><label class="nasr-check"><input type="checkbox" name="confirm" required>بازیابی این مورد را تأیید می‌کنم.</label>`,form=>saved(`${key}/restore`,formData(form)),'بازیابی');
  }
  async function purgeModal(key) {
    const item=S.trash?.find(row=>row.entity+'/'+row.id===key);if(!item)throw new Error('مورد پیدا نشد؛ صفحه را تازه کنید.');
    const preview=await api(`${key}/purge-preview`);
    const body=`<p>«${h(item.name)}» و موارد زیر برای همیشه حذف می‌شوند و قابل بازیابی نیستند.</p><ul>${preview.counts.map(row=>`<li>${h(row.label)}: ${num(row.count)}</li>`).join('')}</ul><p>این عملیات بازپرداخت بانکی انجام نمی‌دهد. تاریخچهٔ عملیات مدیریتی باقی می‌ماند.${preview.user_id?' حساب وردپرس حذف نمی‌شود؛ دسترسی فرد به پنل آموزشگاه با حذف پرونده قطع می‌شود.':''}</p><label class="nasr-check"><input type="checkbox" name="confirm" required>حذف همیشگی این رکورد و تمام سوابق فهرست‌شده را تأیید می‌کنم.</label>`;
    modal('حذف همیشگی '+item.name,body,form=>saved(`${key}/purge`,{...formData(form),signature:preview.signature},async()=>{S.terms=[];S.reportOptions=null;S.page=1;await render();}),'حذف همیشگی');
    document.querySelector('#nasr-modal-form button[type="submit"]').classList.add('destructive');
  }
  function safeJSON(v) { try { return JSON.parse(v); } catch(e) { return v; } }
  function formData(form) {
    const d=Object.fromEntries(new FormData(form).entries());
    form.querySelectorAll('input[type="checkbox"][name]').forEach(el=>{if(el.name!=='class_days')d[el.name]=el.checked;});
    if(form.querySelector('[data-class-days]'))d.class_days=[...form.querySelectorAll('[name="class_days"]:checked')].map(el=>el.value);
    return d;
  }
  let modalSubmit=null;
  function modal(title,body,onSubmit,label='ذخیره',wide=false) {
    const old=document.getElementById('nasr-dialog');
    const returnFocus=old?.open ? old.nasrReturnFocus : document.activeElement;
    closeModal();
    const holder=document.getElementById('nasr-dialog-holder');
    holder.innerHTML=`<dialog id="nasr-dialog" class="nasr-dialog ${wide?'large':''}" aria-labelledby="nasr-dialog-title" data-lenis-prevent><form id="nasr-modal-form"><header><div><span class="nasr-eyebrow">${h(S.boot.settings.academy_name)}</span><h2 id="nasr-dialog-title">${h(title)}</h2></div><button class="nasr-icon-button" type="button" data-action="close" aria-label="بستن">${icon('close')}</button></header><div class="nasr-dialog-body" data-lenis-prevent data-scroll-lock-scrollable tabindex="0" role="region" aria-label="محتوای پنجره"><div class="nasr-alert error" id="nasr-modal-error" role="alert" hidden></div>${body}</div><footer>${onSubmit?`<button type="submit" class="nasr-button primary">${h(label)}</button>`:''}<button type="button" class="nasr-button" data-action="close">${onSubmit?'انصراف':'بستن'}</button></footer></form></dialog>`;
    modalSubmit=onSubmit;
    const d=document.getElementById('nasr-dialog');d.nasrReturnFocus=returnFocus;
    d.addEventListener('close',()=>{
      if(!d.isConnected || document.querySelector('#nasr-dialog[open]'))return;
      closeCalendar();disposeLivePicker();disposeBulkCopy();
      if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});
    },{once:true});
    d.addEventListener('cancel',event=>{
      if(calendarState){event.preventDefault();closeCalendar();}
      else if(d.querySelector('form').dataset.saving)event.preventDefault();
    });
    // Contain wheel/touch events so page-level smooth-scroll code cannot route
    // them to the inert page. Native select scrolling must remain native.
    d.addEventListener('wheel',event=>{
      if(event.ctrlKey)return;
      event.stopPropagation();
      if(event.target.closest('select,option'))return;
      if(Math.abs(event.deltaX)>Math.abs(event.deltaY))return;
      const content=d.querySelector('.nasr-dialog-body');
      const delta=event.deltaY*(event.deltaMode===1?16:event.deltaMode===2?content.clientHeight:1);
      let node=event.target;
      while(node && node!==d){
        const style=getComputedStyle(node);
        if(/auto|scroll/.test(style.overflowY) && node.scrollHeight>node.clientHeight &&
          (delta>0?node.scrollTop<node.scrollHeight-node.clientHeight-1:node.scrollTop>0)){
          if(event.cancelable)event.preventDefault();
          node.scrollTop+=delta;return;
        }
        node=node.parentElement;
      }
      if(event.cancelable)event.preventDefault();
    },{passive:false});
    d.addEventListener('touchmove',event=>event.stopPropagation(),{passive:true});
    d.showModal();layoutModal();
  }
  function layoutModal() {
    const d=document.querySelector('#nasr-dialog[open]');if(!d)return;
    const height=window.visualViewport?.height || window.innerHeight;
    const head=d.querySelector('form > header').offsetHeight,foot=d.querySelector('form > footer').offsetHeight;
    d.style.maxHeight=Math.max(160,height-24)+'px';
    d.querySelector('.nasr-dialog-body').style.maxHeight=Math.max(40,height-head-foot-28)+'px';
    positionCalendar();
  }
  function closeModal() {
    closeCalendar();disposeLivePicker();disposeBulkCopy();document.getElementById('nasr-dialog')?.close();
  }
  async function saved(path,data,after) {
    const result=await api(path,data); closeModal(); toast(result.message || 'ذخیره شد.');
    if(after) await after(result); else await render();
    return result;
  }
  function studentPicker(list,selected=[],exclude=[]) {
    const ids=new Set(selected.map(Number)),excluded=new Set(exclude.map(Number));
    return `<div class="nasr-picker wide"><div class="nasr-picker-tools"><input type="search" placeholder="جست‌وجوی نام یا کد زبان‌آموزی…" aria-label="جست‌وجوی زبان‌آموز" data-picker-search><button type="button" class="nasr-button text" data-action="pick-visible">انتخاب نتایج فعال</button><button type="button" class="nasr-button text" data-action="clear-picks">پاک کردن انتخاب‌ها</button></div><div class="nasr-picker-list">${list.filter(p=>!excluded.has(+p.user_id)).map(p=>`<label class="nasr-picker-item" data-key="${h(fullName(p)+' '+p.student_code)}"><input type="checkbox" data-student-id="${p.user_id}" ${ids.has(+p.user_id)?'checked':''} ${!+p.active && !ids.has(+p.user_id)?'disabled':''}><span>${h(fullName(p))}<small>${p.student_code?num(p.student_code):''} ${!+p.active?'· غیرفعال':''}</small></span></label>`).join('') || '<p>زبان‌آموز قابل انتخابی وجود ندارد.</p>'}</div><small class="nasr-picker-count">انتخاب‌شده: ${num(ids.size)}</small></div>`;
  }
  let livePicker=null;
  function disposeLivePicker() {
    if(!livePicker)return;
    clearTimeout(livePicker.timer);livePicker.controller?.abort();livePicker=null;
  }
  const picked=()=>livePicker ? [...livePicker.selected.keys()] : [...document.querySelectorAll('[data-student-id]:checked')].map(el=>+el.dataset.studentId);
  function livePickerMarkup() {
    return `<div class="nasr-picker wide" id="nasr-live-picker"><div class="nasr-picker-tools"><input type="search" data-live-search maxlength="100" placeholder="نام یا کد زبان‌آموز/معلم را تایپ کنید…" aria-label="جست‌وجوی زندهٔ زبان‌آموز یا معلم" autocomplete="off"><button type="button" class="nasr-button text" data-action="pick-visible">انتخاب این صفحه</button><button type="button" class="nasr-button text" data-action="clear-picks">پاک کردن انتخاب‌ها</button></div><div id="nasr-live-selected" class="nasr-selected-students"></div><p id="nasr-live-status" role="status" aria-live="polite">در حال دریافت افراد قابل ثبت‌نام…</p><div id="nasr-live-results" class="nasr-picker-list" aria-busy="true"></div><div id="nasr-live-pager" class="nasr-live-pager"></div><small class="nasr-picker-count">انتخاب‌شده: ۰</small></div>`;
  }
  function syncLiveSelection() {
    if(!livePicker)return;
    root.querySelectorAll('[data-live-student]').forEach(el=>el.checked=livePicker.selected.has(+el.dataset.studentId));
    const chips=document.getElementById('nasr-live-selected');
    if(chips)chips.innerHTML=[...livePicker.selected.values()].map(p=>`<button type="button" class="nasr-selected-chip" data-action="unpick-student" data-id="${p.user_id}" aria-label="حذف انتخاب ${h(fullName(p))}">${h(fullName(p))} · ${h(learnerMeta(p))} ×</button>`).join('');
    updatePickerCount();
  }
  function chooseLiveStudent(person,checked) {
    if(!livePicker || !person)return;
    if(checked){
      if(livePicker.selected.size>=500 && !livePicker.selected.has(+person.user_id))throw new Error('حداکثر ۵۰۰ نفر در هر نوبت انتخاب کنید.');
      livePicker.selected.set(+person.user_id,person);
    }else livePicker.selected.delete(+person.user_id);
  }
  async function loadLiveStudents(page=1) {
    const state=livePicker;if(!state)return;
    clearTimeout(state.timer);state.controller?.abort();
    const token=++state.request;state.controller=new AbortController();state.page=page;state.rows=[];
    const status=document.getElementById('nasr-live-status'),results=document.getElementById('nasr-live-results'),pager=document.getElementById('nasr-live-pager');
    if(!results)return;
    status.textContent='در حال جست‌وجو…';results.setAttribute('aria-busy','true');results.innerHTML='';pager.innerHTML='';
    try {
      const res=await api('directory',undefined,{kind:'student',purpose:'enrollment',live:1,class_id:state.classId,q:state.q,page,per_page:20},{signal:state.controller.signal});
      if(state!==livePicker || token!==state.request || !results.isConnected)return;
      state.rows=res.items;
      results.innerHTML=res.items.map(p=>`<label class="nasr-picker-item"><input type="checkbox" data-live-student data-student-id="${p.user_id}" ${state.selected.has(+p.user_id)?'checked':''}><span>${h(fullName(p))}<small>${h(learnerMeta(p))}${+p.rejoin?' · بازگشت با شهریه و سوابق قبلی':''}</small></span></label>`).join('');
      status.textContent=res.total?`${num(res.total)} زبان‌آموز یا معلم قابل ثبت‌نام · صفحه ${num(res.page)} از ${num(res.pages)}`:'نتیجه‌ای پیدا نشد؛ نام یا کد را تغییر دهید یا پروندهٔ جدید بسازید.';
      pager.innerHTML=`<button type="button" class="nasr-button text" data-action="live-page" data-id="${res.page-1}" ${res.page<=1?'disabled':''}>صفحه قبل</button><button type="button" class="nasr-button text" data-action="live-page" data-id="${res.page+1}" ${res.page>=res.pages?'disabled':''}>صفحه بعد</button>`;
    } catch(error) {
      if(error.name!=='AbortError' && state===livePicker && token===state.request){
        status.textContent=error.message;pager.innerHTML=button('تلاش دوباره','live-page',page,'text');
      }
    } finally {
      if(state===livePicker && token===state.request && results.isConnected)results.setAttribute('aria-busy','false');
    }
  }
  function scheduleLiveSearch(value) {
    if(!livePicker)return;
    const state=livePicker;state.q=digits(value).trim();state.request++;
    clearTimeout(state.timer);state.controller?.abort();state.rows=[];
    document.getElementById('nasr-live-results').innerHTML='';
    document.getElementById('nasr-live-pager').innerHTML='';
    document.getElementById('nasr-live-status').textContent='در حال جست‌وجو…';
    document.getElementById('nasr-live-results').setAttribute('aria-busy','true');
    state.timer=setTimeout(()=>{if(state===livePicker)loadLiveStudents(1);},300);
  }
  async function personModal(id,kind) {
    const p=id?await api(`people/${id}`):{kind,active:1}; kind=p.kind;
    if(kind==='secretary' && !isManager())throw new Error('تعریف منشی فقط برای مدیر قابل دسترسی است.');
    const family=kind==='family', isNew=!id;
    const list=family?await api('directory',undefined,{kind:'student'}):[];
    const educations=educationList();
    if (p.education && !educations.includes(p.education)) educations.unshift(p.education);
    const birthHint=S.boot.settings.calendar==='jalali'?'۱۴۰۰/۰۱/۰۱':'2000/01/01';
    const birthValue=p.birth_date?dateInput(p.birth_date):'';
    const credentialHelp='برای حساب جدید، نام کاربری و رمز عبور اولیه هر دو کد ملی هستند.';
    const nationalLogin=['student','teacher','secretary'].includes(kind);
    const credentialFields=isNew
      ? `${field('نام کاربری اولیه','username',p.national_id || '',{required:true,readonly:true,dir:'ltr',autocomplete:'off',autoCredential:true,help:credentialHelp})}${field('رمز عبور اولیه','password',p.national_id || '',{type:'password',required:true,readonly:true,dir:'ltr',autocomplete:'new-password',autoCredential:true,help:'پس از ساخت حساب، کد ملی را به‌عنوان رمز اولیه به صاحب حساب تحویل دهید.'})}`
      : nationalLogin
        ? `${field('نام کاربری (کد ملی)','username',p.national_id || p.username,{readonly:true,dir:'ltr',autocomplete:'off',autoCredential:true,help:`با ذخیرهٔ پرونده، نام کاربری ${personNames[kind]} دقیقاً با کد ملی همگام می‌شود.`})}${field('رمز ورود پس از تغییر کد ملی','credential_preview',p.national_id || '',{readonly:true,dir:'ltr',autocomplete:'off',autoCredential:true,help:'اگر کد ملی تغییر کند، رمز ورود نیز خودکار همین مقدار می‌شود و نشست‌های قبلی بسته می‌شوند.'})}${field('رمز جدید دستی (اختیاری)','password','',{type:'password',dir:'ltr',autocomplete:'new-password',help:'فقط وقتی کد ملی تغییر نکرده است؛ برای حفظ رمز فعلی خالی بگذارید.'})}`
        : `${field('نام کاربری','username',p.username,{readonly:true,dir:'ltr',autocomplete:'off',help:'نام کاربری حساب قابل تغییر نیست.'})}${field('رمز جدید (اختیاری)','password','',{type:'password',dir:'ltr',autocomplete:'new-password',help:'برای حفظ رمز فعلی، این خانه را خالی بگذارید.'})}`;
    modal(`${isNew?'تعریف':'ویرایش'} ${personNames[kind]}`,`<div class="nasr-form-grid">${field('نام فارسی','first_name',p.first_name,{required:true,maxlength:100})}${field('نام خانوادگی فارسی','last_name',p.last_name,{required:true,maxlength:100})}${['teacher','student','secretary'].includes(kind)?field('نام انگلیسی','first_name_en',p.first_name_en,{dir:'ltr',maxlength:100})+field('نام خانوادگی انگلیسی','last_name_en',p.last_name_en,{dir:'ltr',maxlength:100}):''}${field('کد ملی','national_id',p.national_id,{required:isNew || nationalLogin,dir:'ltr',mode:'numeric',maxlength:10,autoCredential:isNew || nationalLogin,help:isNew?'کد ملی ۱۰ رقمی؛ مبنای نام کاربری و رمز اولیه است.':nationalLogin?`با تغییر و ذخیره، نام کاربری و رمز ورود ${personNames[kind]} نیز خودکار تغییر می‌کنند.`:''})}${kind==='student'?select('جنسیت *','gender',p.gender || '',[['','انتخاب کنید'],['female','دختر / خانم'],['male','پسر / آقا']]):''}${field('شماره شناسنامه','birth_certificate',p.birth_certificate,{dir:'ltr',maxlength:50})}${field('شماره تماس ۱','mobile',p.mobile,{required:true,dir:'ltr',mode:'tel',help:'شماره همراه اصلی؛ با ۰۹، بدون صفر، یا با +۹۸ پذیرفته می‌شود.'})}${field('شماره تماس ۲','landline',p.landline,{dir:'ltr',mode:'tel'})}${field('شماره تماس ۳','extra_phone',p.extra_phone,{dir:'ltr',mode:'tel'})}${select('مدرک تحصیلی','education',p.education,[['','انتخاب کنید'],...educations.map(value=>[value,value])])}${field('تاریخ تولد','birth_date',birthValue,{dir:'ltr',mode:'numeric',date:true,placeholder:birthHint,help:!p.birth_date&&p.birth_year?`در پروندهٔ قدیمی فقط سال ${num(p.birth_year)} ثبت شده؛ روز و ماه را در صورت اطلاع تکمیل کنید.`:''})}${kind==='student'?field('نام پدر','father_name',p.father_name,{maxlength:150}):''}${field('آدرس','address',p.address,{area:true,wide:true})}${field('ایمیل (اختیاری؛ برای بازیابی رمز)','email',p.email,{type:'email',dir:'ltr'})}${credentialFields}${id&&p.student_code?field('کد زبان‌آموزی','student_code',p.student_code,{readonly:true,dir:'ltr'}):''}${check('حساب فعال باشد','active',!!+p.active)}${family?`<h3 class="wide">زبان‌آموزان مرتبط با این خانواده</h3>${studentPicker(list,p.student_ids || [])}`:''}</div>`,async form=>{
      const d=formData(form); d.kind=kind; if(family)d.student_ids=picked();
      await saved(id?`people/${id}`:'people',d,res=>{if(res.student_code)toast(`${res.message || 'پرونده ذخیره شد.'} کد زبان‌آموزی: ${num(res.student_code)}`);return render();});
    },'ذخیرهٔ پرونده',true);
  }
  function grantSecretaryModal(){
    if(!isManager())throw new Error('تعریف منشی فقط برای مدیر قابل دسترسی است.');
    modal('افزودن منشی از افراد موجود',field('کد ملی','national_id','',{required:true,dir:'ltr',mode:'numeric',maxlength:10}),form=>saved('secretary-access',formData(form),()=>render()),'افزودن دسترسی منشی');
  }
  function revokeSecretaryModal(id){
    if(!isManager())throw new Error('لغو دسترسی منشی فقط برای مدیر قابل دسترسی است.');
    const person=S.people.find(p=>+p.id===+id);if(!person)throw new Error('منشی پیدا نشد.');
    modal('لغو دسترسی منشی',`<p>${h(fullName(person))}</p>${check('لغو دسترسی منشی را تأیید می‌کنم','confirm',false)}`,form=>saved(`secretary-access/${id}/revoke`,formData(form),()=>render()),'لغو دسترسی');
  }
  async function termModal(id) {
    if(!S.terms.length) S.terms=await api('terms');
    const t=id?S.terms.find(x=>+x.id===+id):{active:1,branch_id:+S.branchId || ''}; if(!t)throw new Error('ترم پیدا نشد.');
    if(!id && !S.boot.settings.branches.some(b=>+b.active)){modal('ابتدا شعبه تعریف کنید','<p>در تنظیمات، نام شعبه را اضافه و ذخیره کنید.</p>'+link('تنظیمات','settings','primary'),null);return;}
    const hint=S.boot.settings.calendar==='jalali'?'۱۴۰۵/۰۷/۰۱':'2026/09/23';
    modal(id?'ویرایش ترم':'تعریف ترم',`<div class="nasr-form-grid">${select('شعبه *','branch_id',t.branch_id,[['',id?'تعیین نشده (سابقهٔ قبلی)':'شعبه را انتخاب کنید'],...S.boot.settings.branches.filter(b=>+b.active || +b.id===+t.branch_id).map(b=>[b.id,b.name])])}${field('نام ترم','name',t.name,{required:true})}${field('کد ترم','code',t.code,{required:true,dir:'ltr',maxlength:60})}${[['start_date','تاریخ شروع'],['mid_date','تاریخ میان‌ترم'],['final_date','تاریخ فاینال'],['end_date','تاریخ پایان']].map(([key,label])=>field(label,key,dateInput(t[key]),{required:['start_date','end_date'].includes(key),dir:'ltr',mode:'numeric',date:true,termDate:true,placeholder:hint})).join('')}${check('ترم فعال باشد','active',!!+t.active)}</div>`,form=>saved(id?`terms/${id}`:'terms',formData(form),async()=>{S.terms=await api('terms');await render();}));
  }
  async function classModal(id) {
    const [terms,teachers]=await Promise.all([api('terms'),api('directory',undefined,{kind:'teacher'})]);
    const c=id?(await api(`classes/${id}`)).class:{active:1,term_id:terms.some(t=>String(t.id)===String(S.classTermId) && +t.active)?S.classTermId:'',base_fee:0,extras_fee:0,class_days:[],start_time:'16:30',duration:90,sessions_planned:'',sessions_held:0,late_minutes:0};
    if(!terms.length || !teachers.length){modal('پیش‌نیاز ساخت کلاس','<p>ابتدا حداقل یک معلم و یک ترم تعریف کنید.</p>',null);return;}
    const days=scheduleDays(c),start=normaliseClassTime(c.start_time) || '16:30',duration=String(c.duration || 90);
    const legacy=!!id && !days.length && !!c.schedule;
    const preview=legacy?c.schedule:classSchedule({class_days:days,start_time:start,duration});
    const classTerms=terms.filter(t=>S.branchId==='' || +t.branch_id===+S.branchId || +t.id===+c.term_id);
    modal(id?'ویرایش کلاس':'تعریف کلاس',`<div class="nasr-form-grid">${field('نام کلاس','name',c.name,{required:true,placeholder:'مثلاً Family and Friends 2 — گروه A'})}${select('ترم *','term_id',c.term_id,[['','انتخاب کنید'],...classTerms.filter(t=>+t.active||+t.id===+c.term_id).map(t=>[t.id,termLabel(t)])])}${select('معلم *','teacher_id',c.teacher_id,[['','انتخاب کنید'],...teachers.filter(t=>+t.active||+t.user_id===+c.teacher_id).map(t=>[t.user_id,fullName(t)])])}${field('حق‌التدریس ساعتی این کلاس (تومان)','hourly_rate',c.hourly_rate ?? '',{dir:'ltr',mode:'numeric',help:'نرخ همین کلاس؛ مثلاً ۲۵۰۰۰۰. خالی یعنی هنوز تعیین نشده است.'})}${field(`شهریه ثابت (${S.boot.settings.currency})`,'base_fee',c.base_fee,{required:true,dir:'ltr',mode:'numeric'})}${field(`کپی و کتاب (${S.boot.settings.currency})`,'extras_fee',c.extras_fee,{required:true,dir:'ltr',mode:'numeric'})}${field('تعداد جلسات برنامه‌ریزی‌شده','sessions_planned',c.sessions_planned,{required:!id,mode:'numeric',dir:'ltr'})}${field('تعداد جلسات برگزارشده','sessions_held',c.sessions_held,{required:!id,mode:'numeric',dir:'ltr',help:'مبنای محاسبهٔ حق‌التدریس معلم'})}${field('کسورات (دقایق تأخیر معلم)','late_minutes',c.late_minutes ?? 0,{required:true,mode:'numeric',dir:'ltr',help:'مجموع دقایق تأخیر این کلاس در کل ترم؛ مبلغ آن با نرخ ساعتی همین کلاس محاسبه می‌شود.'})}${classDayFields(days)}${field('ساعت شروع (۲۴ساعته)','start_time',start,{required:true,dir:'ltr',mode:'text',maxlength:5,placeholder:'16:30',help:'ساعت و دقیقه در یک کادر؛ مانند 16:30 یا ۰۹:۰۵.'})}${select('مدت کلاس','duration',duration,durationOptions,'مدت هر جلسه برحسب دقیقه است.')}<div class="nasr-form-total"><span>شهریه کل</span><strong id="nasr-class-total">${money(+c.base_fee + +c.extras_fee)}</strong></div><div class="nasr-form-total nasr-schedule-total wide"><span>برنامهٔ کلاس</span><strong id="nasr-schedule-preview">${h(preview)}</strong></div>${field('اتاق / محل کلاس','room',c.room,{maxlength:100})}${field('توضیحات قابل مشاهده برای اعضای کلاس','notes',c.notes,{area:true,wide:true})}${check('کلاس فعال باشد','active',!!+c.active)}</div>`,form=>{
      const data=formData(form);
      if(legacy && !form.dataset.scheduleTouched){delete data.class_days;delete data.start_time;delete data.duration;}
      else{
        if(!data.class_days.length)throw new Error('حداقل یک روز هفته را انتخاب کنید.');
        data.start_time=normaliseClassTime(data.start_time);
        if(!data.start_time)throw new Error('ساعت را به صورت ۲۴ساعته مانند 16:30 وارد کنید.');
      }
      return saved(id?`classes/${id}`:'classes',data,async res=>{if(location.hash===`#classes/${res.id}`)await render();else location.hash=`classes/${res.id}`;});
    },'ذخیرهٔ کلاس',true);
  }
  async function enrollModal() {
    const c=S.detail.class;
    modal('افزودن زبان‌آموز یا معلم به کلاس',`<div class="nasr-enroll-head"><p class="nasr-muted">${h(c.name)} · ${h(c.term_name)}</p><div class="nasr-actions">${button('افزودن از کلاس قبلی','bulk-enroll',c.id,'soft','people')}${link('ساخت زبان‌آموز جدید','people/student/new','soft')}</div></div>${livePickerMarkup()}<div class="nasr-form-grid">${field('درصد تخفیف برای ثبت‌نام‌های جدید','discount',0,{required:true,dir:'ltr',mode:'decimal'})}${select('مبنای تخفیف', 'discount_scope',S.boot.settings.discount_scope,[['base','فقط شهریه ثابت'],['total','شهریه ثابت + کپی و کتاب']])}</div><div class="nasr-form-total"><span>شهریهٔ ثبت‌نام جدید هر نفر</span><strong id="nasr-enroll-total">${money(+c.base_fee + +c.extras_fee)}</strong></div>`,form=>{
      const ids=picked();if(!ids.length)throw new Error('حداقل یک زبان‌آموز یا معلم انتخاب کنید.');
      return saved('enroll',{...formData(form),class_id:c.id,student_ids:ids});
    },'ثبت‌نام افراد انتخاب‌شده',true);
    livePicker={classId:c.id,selected:new Map(),rows:[],q:'',page:1,request:0,timer:null,controller:null};
    await loadLiveStudents();
  }
  let bulkCopy=null;
  function disposeBulkCopy() {
    if(!bulkCopy)return;
    bulkCopy.source.controller?.abort();bulkCopy.target.controller?.abort();bulkCopy.rosterController?.abort();bulkCopy=null;
  }
  const copyQuery=value=>digits(value).toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s\u200b-\u200f٬,]/g,'');
  const copyEligible=row=>+row.active===1 && !+row.already_enrolled;
  const copyKey=state=>String(state.source.classId)+':'+String(state.target.classId || 0);
  function copySelection(state=bulkCopy) {
    if(!state)return {selected:[],eligible:[],ready:false,validDiscount:false};
    const selected=state.rows.filter(row=>state.selected.has(+row.user_id)),eligible=selected.filter(copyEligible);
    const raw=digits(state.discount).trim(),discount=Number(raw);
    const validDiscount=/^\d+(?:\.\d)?$/.test(raw) && discount>=0 && discount<=100;
    const target=state.target.classes.find(c=>String(c.id)===String(state.target.classId));
    const ready=!!(state.source.classId && target && +target.active && String(state.source.classId)!==String(target.id) && state.rosterKey===copyKey(state) &&
      !state.source.loading && !state.target.loading && !state.rosterLoading && !state.rosterError && !state.saving && eligible.length && eligible.length<=500 && validDiscount);
    return {selected,eligible,target,ready,validDiscount,discount};
  }
  function copySelect(label,side,part,options=[],value='',disabled=false) {
    return '<label class="nasr-field"><span>'+h(label)+' <b class="nasr-required">*</b></span><select id="nasr-copy-'+side+'-'+part+'" name="'+side+'_'+part+'_id" data-copy-side="'+side+'" data-copy-part="'+part+'" required '+(disabled?'disabled':'')+'>'+
      options.map(([id,name])=>'<option value="'+h(id)+'" '+(String(value)===String(id)?'selected':'')+'>'+h(name)+'</option>').join('')+'</select></label>';
  }
  function fillCopyClasses(sideName,placeholder='کلاس را انتخاب کنید') {
    const state=bulkCopy;if(!state)return;
    const side=state[sideName],select=document.getElementById('nasr-copy-'+sideName+'-class');if(!select)return;
    select.innerHTML='<option value="">'+h(placeholder)+'</option>'+side.classes.map(c=>'<option value="'+h(c.id)+'">'+h(c.name)+(!+c.active?' · بایگانی':'')+'</option>').join('');
    select.value=side.classId;
  }
  function renderCopyRoster() {
    const state=bulkCopy,list=document.getElementById('nasr-copy-roster');if(!state || !list)return;
    const query=copyQuery(state.q);
    const rows=state.rows.filter(row=>copyQuery(fullName(row)+' '+row.student_code).includes(query));
    list.innerHTML=rows.map(row=>'<label class="nasr-copy-person">'+
      '<input type="checkbox" data-copy-student="'+h(row.user_id)+'" '+(state.selected.has(+row.user_id)?'checked':'')+'>'+
      '<span><strong>'+h(fullName(row))+'</strong><small>'+h(learnerMeta(row))+
      (!+row.active?' · حساب غیرفعال':+row.already_enrolled?' · در کلاس مقصد ثبت‌نام دارد':+row.rejoin?' · بازیابی عضویت قبلی مقصد':!+row.enrollment_active?' · عضویت مبدأ بایگانی است':'')+
      '</small></span></label>').join('');
    if(state.rows.length && !rows.length)list.innerHTML='<p class="nasr-muted">موردی پیدا نشد.</p>';
    syncCopyUI();
  }
  function syncCopyUI() {
    const state=bulkCopy,panel=document.getElementById('nasr-bulk-copy');if(!state || !panel)return;
    const selection=copySelection(state),busy=state.source.loading || state.target.loading || state.rosterLoading;
    for(const name of ['source','target']){
      const side=state[name];
      const term=document.getElementById('nasr-copy-'+name+'-term'),classSelect=document.getElementById('nasr-copy-'+name+'-class');
      if(term)term.disabled=state.saving;
      if(classSelect)classSelect.disabled=state.saving || side.loading || !side.classes.length;
      const status=document.getElementById('nasr-copy-'+name+'-status');
      if(status)status.textContent=side.loading?'در حال دریافت کلاس‌ها…':side.error || (side.termId && !side.classes.length?'این ترم کلاس قابل انتخاب ندارد.':'');
      const retry=panel.querySelector('[data-action="copy-retry-classes"][data-id="'+name+'"]');
      if(retry){retry.hidden=!side.error;retry.disabled=state.saving;}
    }
    panel.querySelectorAll('[data-copy-student]').forEach(input=>{
      const row=state.rows.find(item=>+item.user_id===+input.dataset.copyStudent);
      input.checked=state.selected.has(+input.dataset.copyStudent);
      input.disabled=busy || state.saving || !!state.rosterError || !row || !copyEligible(row);
    });
    panel.querySelectorAll('[data-copy-search],[name="discount"],[name="discount_scope"]').forEach(input=>input.disabled=state.saving);
    panel.querySelectorAll('[data-action="copy-select-all"],[data-action="copy-clear"],[data-action="copy-refresh"]').forEach(button=>button.disabled=state.saving || busy || !state.source.classId);
    const status=document.getElementById('nasr-copy-roster-status');
    if(status)status.textContent=state.rosterLoading?'در حال دریافت اعضای کلاس…':state.rosterError || (!state.source.classId?'ابتدا ترم و کلاس مبدأ را انتخاب کنید.':state.rows.length?
      num(state.rows.length)+' نفر در کلاس · '+num(state.rows.filter(copyEligible).length)+' نفر قابل افزودن'+(state.target.classId?' · '+num(state.rows.filter(row=>+row.already_enrolled).length)+' نفر از قبل در مقصد هستند':''):'کلاس مبدأ عضو قابل نمایشی ندارد.');
    document.getElementById('nasr-copy-roster')?.setAttribute('aria-busy',String(state.rosterLoading));
    const summary=document.getElementById('nasr-copy-summary');
    if(summary){
      const restored=selection.eligible.filter(row=>+row.rejoin).length;
      summary.textContent='انتخاب‌شده: '+num(selection.selected.length)+' نفر · قابل افزودن: '+num(selection.eligible.length)+' نفر'+(restored?' · '+num(restored)+' بازیابی در مقصد':'');
    }
    const targetSummary=document.getElementById('nasr-copy-target-summary');
    if(targetSummary)targetSummary.innerHTML=selection.target?'<strong>'+h(selection.target.name)+'</strong><p>'+h(classSchedule(selection.target))+'</p>':'ترم و کلاس مقصد را انتخاب کنید.';
    const fee=document.getElementById('nasr-copy-fee');
    if(fee){
      const target=selection.target;
      const base=Number(target?.base_fee || 0),extra=Number(target?.extras_fee || 0);
      fee.innerHTML=!target?'—':!selection.validDiscount?'درصد تخفیف معتبر نیست':money(Math.max(0,Math.round(base+extra-(state.scope==='total'?base+extra:base)*selection.discount/100)));
    }
    const submit=document.getElementById('nasr-modal-form')?.querySelector('button[type="submit"]');
    if(submit){submit.disabled=!selection.ready;submit.textContent=state.saving?'در حال افزودن…':'افزودن '+num(selection.eligible.length)+' نفر به کلاس مقصد';}
  }
  function resetCopySource(state) {
    state.rows=[];state.selected.clear();state.q='';
    const search=document.getElementById('nasr-copy-search');if(search)search.value='';
  }
  async function loadCopyClasses(sideName,termId,preset='') {
    const state=bulkCopy;if(!state || !['source','target'].includes(sideName))return;
    const side=state[sideName];side.controller?.abort();const token=++side.token;
    side.controller=new AbortController();side.termId=String(termId || '');side.classId='';side.classes=[];side.error='';side.loading=!!termId;
    state.rosterController?.abort();state.rosterToken++;state.rosterLoading=false;state.rosterKey='';state.rosterError='';
    if(sideName==='source')resetCopySource(state);
    fillCopyClasses(sideName,termId?'در حال دریافت…':'ابتدا ترم را انتخاب کنید');renderCopyRoster();
    try{
      if(termId){
        const res=await api('enrollment-copy/classes',undefined,{term_id:termId,target:sideName==='target'?1:0},{signal:side.controller.signal});
        if(state!==bulkCopy || token!==side.token)return;
        side.classes=res.items;side.classId=side.classes.some(c=>String(c.id)===String(preset))?String(preset):'';
        fillCopyClasses(sideName,side.classes.length?'کلاس را انتخاب کنید':'کلاسی وجود ندارد');
      }
    }catch(error){
      if(state!==bulkCopy || token!==side.token || error.name==='AbortError')return;
      side.error=error.message;fillCopyClasses(sideName,'دریافت کلاس‌ها انجام نشد');
    }finally{
      if(state===bulkCopy && token===side.token){side.loading=false;syncCopyUI();}
    }
    if(state===bulkCopy && token===side.token && !side.error)await loadCopyRoster();
  }
  async function loadCopyRoster() {
    const state=bulkCopy;if(!state)return;
    state.rosterController?.abort();const token=++state.rosterToken,key=copyKey(state);
    state.rosterKey='';state.rosterError='';state.rosterLoading=false;
    if(!state.source.classId){state.rows=[];renderCopyRoster();return;}
    if(String(state.source.classId)===String(state.target.classId)){
      state.rosterError='کلاس مبدأ و مقصد نباید یکسان باشند.';syncCopyUI();return;
    }
    state.rosterController=new AbortController();state.rosterLoading=true;syncCopyUI();
    try{
      const res=await api('enrollment-copy/roster',undefined,{source_class_id:state.source.classId,target_class_id:state.target.classId || 0},{signal:state.rosterController.signal});
      if(state!==bulkCopy || token!==state.rosterToken || key!==copyKey(state))return;
      state.rows=res.items;state.rosterKey=key;
      const ids=new Set(state.rows.map(row=>+row.user_id));
      state.selected.forEach(id=>{if(!ids.has(id))state.selected.delete(id);});
      renderCopyRoster();
    }catch(error){
      if(state===bulkCopy && token===state.rosterToken && error.name!=='AbortError')state.rosterError=error.message;
    }finally{
      if(state===bulkCopy && token===state.rosterToken){state.rosterLoading=false;syncCopyUI();}
    }
  }
  function chooseCopyStudent(id,checked) {
    const state=bulkCopy;if(!state || state.saving || state.rosterLoading)return;
    const row=state.rows.find(item=>+item.user_id===+id);if(!row)return;
    if(!checked)state.selected.delete(+id);
    else if(copyEligible(row)){
      if(state.selected.size>=500 && !state.selected.has(+id))throw new Error('حداکثر ۵۰۰ نفر را در هر نوبت انتخاب کنید.');
      state.selected.add(+id);
    }
  }
  async function submitCopy(form) {
    const state=bulkCopy,selection=copySelection(state);
    if(!state || !selection.ready)throw new Error('کلاس مبدأ، کلاس مقصد و حداقل یک فرد قابل افزودن را انتخاب کنید و منتظر تکمیل دریافت فهرست بمانید.');
    const body=formData(form),targetId=state.target.classId;
    const payload={source_term_id:+state.source.termId,source_class_id:+state.source.classId,target_term_id:+state.target.termId,target_class_id:+targetId,
      student_ids:selection.eligible.map(row=>+row.user_id),discount:body.discount,discount_scope:body.discount_scope};
    state.saving=true;syncCopyUI();
    try{
      const result=await api('enrollment-copy',payload);
      // A completed request must not close a different dialog opened meanwhile.
      if(state!==bulkCopy){toast(result.message);return;}
      closeModal();toast(result.message);
      if(location.hash==='#classes/'+targetId)await render();else location.hash='classes/'+targetId;
    }finally{
      if(state===bulkCopy){state.saving=false;syncCopyUI();}
    }
  }
  async function bulkCopyModal(targetId='') {
    const [terms,preset]=await Promise.all([api('terms'),targetId?api('classes/'+targetId):Promise.resolve(null)]);
    if(!terms.length){modal('افزودن گروهی','<p>ابتدا ترم و کلاس مبدأ و مقصد را تعریف کنید.</p>'+link('ترم‌های آموزشی','terms'),null);return;}
    const sourceOptions=[['','ترم قبلی را انتخاب کنید'],...terms.map(t=>[t.id,termLabel(t)+(!+t.active?' · بایگانی':'')])];
    const targetOptions=[['','ترم جدید را انتخاب کنید'],...terms.filter(t=>+t.active).map(t=>[t.id,termLabel(t)])];
    const canPreset=preset?.class && +preset.class.active && terms.some(t=>+t.active && +t.id===+preset.class.term_id);
    modal('افزودن گروهی از کلاس قبلی',
      '<div id="nasr-bulk-copy"><div class="nasr-copy-grid">'+
      '<section class="nasr-copy-column nasr-copy-source"><h3><span>۱</span> کلاس مبدأ · سمت راست</h3><div class="nasr-copy-fields">'+
      copySelect('ترم قبلی','source','term',sourceOptions)+copySelect('کلاس قبلی','source','class',[['','ابتدا ترم را انتخاب کنید']],'',true)+'</div><p id="nasr-copy-source-status" class="nasr-copy-status" role="status"></p>'+
      button('تلاش دوباره','copy-retry-classes','source','text')+
      '<div class="nasr-copy-search"><input id="nasr-copy-search" type="search" data-copy-search maxlength="100" placeholder="جست‌وجوی نام یا کد در این کلاس" aria-label="جست‌وجوی زبان‌آموزان کلاس مبدأ"></div>'+
      '<div class="nasr-actions">'+button('انتخاب همه (حداکثر ۵۰۰)','copy-select-all','','text')+button('پاک کردن انتخاب‌ها','copy-clear','','text')+button('تازه‌سازی فهرست','copy-refresh','','text')+'</div>'+
      '<p id="nasr-copy-roster-status" class="nasr-copy-status" role="status" aria-live="polite"></p><div id="nasr-copy-roster" class="nasr-copy-roster" aria-busy="false"></div></section>'+
      '<section class="nasr-copy-column nasr-copy-target"><h3><span>۲</span> کلاس مقصد · سمت چپ</h3><div class="nasr-copy-fields">'+
      copySelect('ترم جدید','target','term',targetOptions,canPreset?preset.class.term_id:'')+copySelect('کلاس جدید','target','class',[['','ابتدا ترم را انتخاب کنید']],'',true)+'</div><p id="nasr-copy-target-status" class="nasr-copy-status" role="status"></p>'+
      button('تلاش دوباره','copy-retry-classes','target','text')+
      '<div id="nasr-copy-target-summary" class="nasr-copy-target-summary"></div><div class="nasr-copy-fields">'+
      field('درصد تخفیف ثبت‌نام‌های جدید','discount',0,{required:true,dir:'ltr',mode:'decimal'})+select('مبنای تخفیف','discount_scope',S.boot.settings.discount_scope,[['base','فقط شهریه ثابت'],['total','شهریه ثابت + کپی و کتاب']])+
      '</div><div class="nasr-form-total"><span>شهریهٔ جدید هر نفر</span><strong id="nasr-copy-fee">—</strong></div>'+
      '<div id="nasr-copy-summary" class="nasr-copy-selection-summary" role="status" aria-live="polite"></div>'+
      '</section></div></div>',
      submitCopy,'افزودن به کلاس مقصد',true);
    const side=()=>({termId:'',classId:'',classes:[],loading:false,error:'',controller:null,token:0});
    bulkCopy={terms,source:side(),target:side(),rows:[],selected:new Set(),q:'',discount:'0',scope:S.boot.settings.discount_scope,
      rosterKey:'',rosterLoading:false,rosterError:'',rosterToken:0,rosterController:null,saving:false};
    document.getElementById('nasr-dialog').classList.add('nasr-bulk-dialog');layoutModal();syncCopyUI();
    if(canPreset)await loadCopyClasses('target',preset.class.term_id,preset.class.id);
  }
  function enrollment(id) { const e=S.detail?.enrollments.find(e=>+e.id===+id); if(!e)throw new Error('پروندهٔ کلاس پیدا نشد؛ صفحه را تازه کنید.'); return e; }
  function gradesModal(id) {
    const e=enrollment(id),c=S.detail.class,g=e.grades;
    modal(`Report Card — ${englishName(e) || fullName(e)}`,`<p class="nasr-muted">${h(c.name)} · ${h(c.term_name)} · Student ID: ${numEN(e.student_code)}</p><div class="nasr-form-grid nasr-grade-form">${Object.entries(gradeNames).map(([key,label])=>field(`${label} (out of ${numEN(key==='midterm'?c.mid_max:key==='final_exam'?c.final_max:10)})`,key,g[key],{dir:'ltr',mode:'decimal',placeholder:'Not graded'})).join('')}<div class="nasr-form-total wide"><span>Total Score / ${numEN(g.total_max)}</span><strong id="nasr-total-preview">${g.total===null?'Incomplete':numEN(g.total)}</strong></div>${field('Teacher Feedback','feedback',g.feedback,{area:true,wide:true})}</div>`,form=>saved(`grades/${e.id}`,{...formData(form),version:g.version}),'Save Grades',true);
    const dialog=document.getElementById('nasr-dialog');dialog.classList.add('nasr-english-report');dialog.dir='ltr';dialog.lang='en';dialog.querySelectorAll('[data-action=close]').forEach(el=>{if(el.closest('footer'))el.textContent='Cancel';else el.setAttribute('aria-label','Close');});
  }
  const extraFeeLabels={snack_stationery_fee:'هزینه خوراکی تحریر',private_fee:'هزینه خصوصی',other_fee:'سایر هزینه‌ها'};
  const extraFeeTotal=e=>Object.keys(extraFeeLabels).reduce((sum,key)=>sum+(Number(e[key])||0),0);
  function financeModal(id) {
    const e=enrollment(id);
    modal(`شهریهٔ ${fullName(e)}`,`<div class="nasr-finance-overview"><div><small>شهریه پس از تخفیف</small><strong>${money(+e.net_fee-extraFeeTotal(e))}</strong></div><div><small>شهریه و هزینه‌ها</small><strong id="nasr-fees-total">${money(e.net_fee)}</strong></div><div><small>پرداخت موفق</small><strong>${money(e.paid)}</strong></div><div><small>مانده</small><strong id="nasr-fees-balance">${money(e.balance)}</strong></div></div><div class="nasr-form-grid" data-extra-fees data-base="${+e.net_fee-extraFeeTotal(e)}" data-paid="${+e.paid}">${Object.entries(extraFeeLabels).map(([key,label])=>field(`${label} (${S.boot.settings.currency})`,key,e[key] || 0,{dir:'ltr',mode:'numeric',required:true})).join('')}</div><p id="nasr-fees-credit" class="nasr-muted">${+e.credit?`بستانکاری: ${num(e.credit)} ${h(S.boot.settings.currency)}`:''}</p><div class="nasr-actions">${button('ویرایش تخفیف و ثبت‌نام','discount',e.id,'soft','edit')}${button('ثبت سابقهٔ پرداخت','payment',e.id,'primary','plus')}</div><h3>سوابق پرداخت</h3>${paymentTable(e.payments,true)}`,form=>saved(`enrollments/${e.id}`,{...formData(form),finance_version:e.finance_version}),'ذخیرهٔ هزینه‌ها',true);
  }
  function discountModal(id) {
    const e=enrollment(id);
    modal(`ویرایش ثبت‌نام ${fullName(e)}`,`<div class="nasr-alert">شهریه ثابت هنگام ثبت‌نام: ${money(e.base_fee)}<br>کپی و کتاب: ${money(e.extras_fee)}</div><div class="nasr-form-grid">${field('درصد تخفیف','discount',e.discount,{required:true,dir:'ltr',mode:'decimal'})}${select('مبنای تخفیف','discount_scope',e.discount_scope,[['base','فقط شهریه ثابت'],['total','شهریه ثابت + کپی و کتاب']])}${check('ثبت‌نام فعال باشد','active',!!+e.active)}</div>`,form=>saved(`enrollments/${e.id}`,{...formData(form),finance_version:e.finance_version}),'ذخیرهٔ تغییرات');
  }
  function paymentModal(id) {
    const e=enrollment(id); const bytes=new Uint8Array(16); crypto.getRandomValues(bytes); const requestKey=[...bytes].map(v=>v.toString(16).padStart(2,'0')).join('');
    modal(`ثبت پرداخت ${fullName(e)}`,`<div class="nasr-alert">ماندهٔ شهریه: ${money(e.balance)}</div><div class="nasr-form-grid">${field(`مبلغ (${S.boot.settings.currency})`,'amount',e.balance || '',{required:true,dir:'ltr',mode:'numeric'})}${select('وضعیت','status','success',[['success','موفق — وجه دریافت شده'],['pending','در انتظار بررسی'],['failed','ناموفق']])}${select('روش پرداخت','method','transfer',['cash','card','transfer','manual'].map(key=>[key,methodNames[key]]))}${field('تاریخ پرداخت','paid_on',dateInput(today()),{required:true,dir:'ltr',mode:'numeric',date:true})}${field('شماره پیگیری / رسید','reference','',{dir:'ltr',maxlength:120})}${field('توضیحات قابل مشاهده برای خانواده','notes','',{area:true,wide:true})}</div>`,form=>saved('payments',{...formData(form),enrollment_id:e.id,request_key:requestKey}),'ثبت سابقهٔ پرداخت');
  }
  function voidModal(id) {
    modal('ابطال سابقهٔ پرداخت',`${field('علت ابطال','reason','',{required:true,wide:true,maxlength:500})}`,form=>saved(`payments/${id}/void`,formData(form)),'تأیید ابطال سابقه');
  }
  function passwordModal() {
    modal('تغییر رمز عبور',`${field('رمز فعلی','current','',{type:'password',required:true,dir:'ltr',autocomplete:'current-password'})}${field('رمز جدید','next','',{type:'password',required:true,dir:'ltr',autocomplete:'new-password',minlength:10,maxlength:200,placeholder:'رمز ترکیبی؛ حداقل ۱۰ نویسه'})}${field('تکرار رمز جدید','confirm','',{type:'password',required:true,dir:'ltr',autocomplete:'new-password',minlength:10,maxlength:200})}`,async form=>{
      const d=formData(form); if(d.next!==d.confirm)throw new Error('رمز جدید و تکرار آن یکسان نیستند.');
      await api('password',d); location.assign(C.portal);
    },'ذخیره و ورود دوباره');
  }
  root.addEventListener('click',async event=>{
    const target=event.target;
    if(target.closest('select,option')){event.stopPropagation();if(calendarState && !target.closest('#nasr-calendar'))closeCalendar();return;}
    if (calendarState && !target.closest('#nasr-calendar') && !target.closest('[data-calendar-field]')) closeCalendar();
    const calAction=target.closest('[data-calendar-action]');
    if (calAction && calendarState && !calAction.disabled) {
      event.preventDefault();
      const c=calendarState, action=calAction.dataset.calendarAction;
      if (action==='prev' || action==='next') {
        c.month += action==='prev' ? -1 : 1;
        if (c.month<1) { c.month=12; c.year--; }
        if (c.month>12) { c.month=1; c.year++; }
        const b=calendarBounds(c.input);
        if(compareDateParts([c.year,c.month,1],[b.min[0],b.min[1],1])<0){c.year=b.min[0];c.month=b.min[1];}
        if(compareDateParts([c.year,c.month,1],[b.max[0],b.max[1],1])>0){c.year=b.max[0];c.month=b.max[1];}
        c.day=Math.min(c.day,calendarDays(c.year,c.month)); renderCalendar(); return;
      }
      if (action==='today') { setCalendarValue(c.input,calendarToday()); closeCalendar(); return; }
      if (action==='clear') { c.input.value=''; c.input.dispatchEvent(new Event('input',{bubbles:true})); c.input.dispatchEvent(new Event('change',{bubbles:true})); closeCalendar(); return; }
      return;
    }
    const day=target.closest('[data-calendar-day]');
    if (day && calendarState && !day.disabled) {
      event.preventDefault(); const c=calendarState, selected=+day.dataset.calendarDay;
      setCalendarValue(c.input,[c.year,c.month,selected]); closeCalendar(); return;
    }
    const calendarInput=target.closest('[data-calendar-field]');
    if (calendarInput) { event.preventDefault(); openCalendar(calendarInput); return; }
    const el=event.target.closest('[data-action]'); if(!el)return;
    const action=el.dataset.action,id=el.dataset.id;
    try {
      if(action==='close'){if(el.closest('form')?.dataset.saving)return;return closeModal();}
      if(action==='menu') { const open=document.getElementById('nasr-sidebar').classList.toggle('open');el.setAttribute('aria-expanded',String(open));return; }
      if(action==='retry')return render();
      if(action==='page') { S.page=+id; return render(); }
      if(action==='select-report-student')return chooseReportStudent(id);
      if(action==='student-search-page')return searchStudents(studentLookup.q,+id,0);
      if(action==='add-adjustment'){root.querySelector(`[data-adjustments="${id}"]`).insertAdjacentHTML('beforeend',adjustmentRow(id));updatePayrollPreview();return;}
      if(action==='remove-adjustment'){el.closest('[data-adjustment-row]').remove();updatePayrollPreview();return;}
      if(action==='print')return extras.printRoster();
      if(action.startsWith('sms-'))return await extras.smsAction(action,id,el);
      if(action==='add-branch-row'){document.getElementById('nasr-branch-rows').insertAdjacentHTML('beforeend',branchEditorRow({}));return;}
      if(action==='remove-branch-row'){el.closest('[data-branch-row]')?.remove();return;}
      if(action==='choose-branch'){S.branchId=id;S.page=1;S.terms=[];S.reportFilters={};shell();if(location.hash==='#terms')await render();else location.hash='terms';return;}
      if(action==='print-report')return extras.printIndividual(id);
      if(action==='payment-receipt')return await paymentReceipt(id);
      if(bulkCopy && action.startsWith('copy-')){
        if(bulkCopy.saving)return;
        if(action==='copy-refresh')return loadCopyRoster();
        if(action==='copy-retry-classes')return loadCopyClasses(id,bulkCopy[id]?.termId);
        if(action==='copy-clear')bulkCopy.selected.clear();
        if(action==='copy-select-all'){
          bulkCopy.selected=new Set(bulkCopy.rows.filter(copyEligible).slice(0,500).map(row=>+row.user_id));
        }
        syncCopyUI();return;
      }
      if(action==='live-page')return loadLiveStudents(+id);
      if(action==='unpick-student' && livePicker){livePicker.selected.delete(+id);syncLiveSelection();return;}
      if(livePicker && (action==='pick-visible' || action==='clear-picks')){
        if(action==='clear-picks')livePicker.selected.clear();
        else{if(new Set([...livePicker.selected.keys(),...livePicker.rows.map(p=>+p.user_id)]).size>500)throw new Error('حداکثر ۵۰۰ نفر در هر نوبت انتخاب کنید.');livePicker.rows.forEach(p=>chooseLiveStudent(p,true));}
        syncLiveSelection();return;
      }
      if(action==='pick-visible' || action==='clear-picks') { root.querySelectorAll('.nasr-picker-item').forEach(l=>{const cb=l.querySelector('input');if(action==='clear-picks')cb.checked=false;else if(!l.hidden && !cb.disabled)cb.checked=true;});updatePickerCount();return; }
      if(S.busy)return; S.busy=true; el.disabled=true;
      if(action==='export-report')await exportReport(false);
      if(action==='print-list-report')await exportReport(true);
      if(action==='attendance')await attendanceModal(id);
      if(action==='new-person')await personModal(null,id);
      if(action==='grant-secretary')grantSecretaryModal();
      if(action==='revoke-secretary')revokeSecretaryModal(id);
      if(action==='edit-person')await personModal(id);
      if(action==='new-term')await termModal();
      if(action==='edit-term')await termModal(id);
      if(action==='new-class')await classModal();
      if(action==='edit-class')await classModal(id);
      if(action==='enroll')await enrollModal();
      if(action==='bulk-enroll')await bulkCopyModal(id);
      if(action==='grades')gradesModal(id);
      if(action==='finance')financeModal(id);
      if(action==='discount')discountModal(id);
      if(action==='payment')paymentModal(id);
      if(action==='void-payment')voidModal(id);
      if(action==='password')passwordModal();
      if(action==='delete-person')await deleteModal('people',id);
      if(action==='delete-class')await deleteModal('classes',id);
      if(action==='delete-term')await deleteModal('terms',id);
      if(action==='remove-student')await deleteModal('enrollments',id);
      if(action==='restore-record')restoreModal(id);
      if(action==='purge-record')await purgeModal(id);
    } catch(e) { toast(e.message,true); }
    finally { S.busy=false;el.disabled=false; }
  });
  root.addEventListener('change',event=>{
    if(event.target.closest('select'))event.stopPropagation();
    const control=event.target;
    if(control.name==='salary_term'){S.salaryTermId=control.value;render();return;}
    if(control.name==='payment_branch'){S.paymentFilters={branch_id:control.value,term_id:''};S.page=1;render();return;}
    if(control.name==='payment_term'){S.paymentFilters.term_id=control.value;S.page=1;render();return;}
    if(control.name==='class_list_term'){S.classTermId=control.value;S.page=1;S.q='';render();return;}
    if(control.name==='payroll_term'){S.payrollFilters={term_id:control.value,teacher_id:''};render();return;}
    if(control.name==='payroll_teacher'){S.payrollFilters.teacher_id=control.value;render();return;}
    if(control.name==='global_branch'){S.branchId=control.value;S.page=1;S.reportFilters={};S.paymentFilters={branch_id:control.value,term_id:''};render();return;}
    if(control.closest('#nasr-report-filter')){
      if(['type','branch_id','term_id','teacher_id','class_id'].includes(control.name) || (control.name==='year' && control.tagName==='SELECT')){const f=formData(control.form);if(control.name==='type' && f.type==='student_grades'){for(const k of ['branch_id','term_id','year','class_id','teacher_id','previous_term_id','paid_year','q','student_id'])f[k]='';}if(control.name==='class_id'){f.session_from='1';f.session_to='';}S.reportFilters[S.view]=f;S.page=1;render();}return;
    }

    if(control.name==='attendance_session'){
      const form=control.form,session=control.value;
      if(form.dataset.attendanceDirty){control.value=form.dataset.attendanceSession;toast('ابتدا تغییرات این جلسه را ذخیره کنید.',true);return;}
      attendanceModal(form.dataset.attendanceClass,session).catch(e=>toast(e.message,true));return;
    }
    if(bulkCopy && control.dataset.copySide){
      const side=control.dataset.copySide;if(bulkCopy.saving || !['source','target'].includes(side))return;
      if(control.dataset.copyPart==='term'){loadCopyClasses(side,control.value);return;}
      bulkCopy[side].classId=String(control.value);
      if(side==='source')resetCopySource(bulkCopy);
      renderCopyRoster();loadCopyRoster();return;
    }
    const part=event.target.closest('[data-calendar-part]');
    if(!part || !calendarState)return;
    const c=calendarState;
    if(part.dataset.calendarPart==='month')c.month=Number(part.value);
    if(part.dataset.calendarPart==='year')c.year=Number(part.value);
    c.day=Math.min(c.day,calendarDays(c.year,c.month));renderCalendar();
  });
  for(const type of ['pointerdown','mousedown'])root.addEventListener(type,event=>{
    if(event.target.closest('select,option'))event.stopPropagation();
  });
  root.addEventListener('keydown',event=>{
    if(event.target.id==='nasr-student-query'){
      const input=event.target,popup=root.querySelector('#nasr-student-popup');
      if(event.key==='Escape' || event.key==='Tab'){hideStudentLookup();if(event.key==='Escape')event.preventDefault();return;}
      if(['ArrowDown','ArrowUp'].includes(event.key)){
        event.preventDefault();if(popup.hidden){searchStudents(input.closest('.nasr-student-lookup').querySelector('[name=student_id]').value?'':input.value,1,0);return;}
        const options=[...root.querySelectorAll('#nasr-student-options [role=option]')];if(!options.length)return;
        studentLookup.active=studentLookup.active<0?(event.key==='ArrowDown'?0:options.length-1):(studentLookup.active+(event.key==='ArrowDown'?1:-1)+options.length)%options.length;
        options.forEach((el,i)=>el.setAttribute('aria-selected',String(i===studentLookup.active)));const current=options[studentLookup.active];input.setAttribute('aria-activedescendant',current.id);const itemBounds=current.getBoundingClientRect(),boxBounds=popup.getBoundingClientRect(),top=boxBounds.top+popup.clientTop,bottom=top+popup.clientHeight;if(itemBounds.top<top)popup.scrollTop-=top-itemBounds.top;else if(itemBounds.bottom>bottom)popup.scrollTop+=itemBounds.bottom-bottom;return;
      }
      if(event.key==='Enter' && !popup.hidden){event.preventDefault();const p=studentLookup.rows[Math.max(0,studentLookup.active)];if(p)chooseReportStudent(p.user_id);return;}
    }
    const input=event.target.closest('[data-calendar-field]');
    if(input && ['Enter','ArrowDown'].includes(event.key)){
      event.preventDefault();openCalendar(input);calendarState?.el.querySelector('select')?.focus();return;
    }
    if(event.key==='Escape' && calendarState){
      event.preventDefault();event.stopPropagation();const input=calendarState.input;closeCalendar();input.focus({preventScroll:true});
    }
  });
  window.addEventListener('scroll',()=>{if(calendarState)positionCalendar();},true);
  window.addEventListener('resize',layoutModal);
  window.visualViewport?.addEventListener('resize',layoutModal);
  root.addEventListener('submit',async event=>{
    const form=event.target;
    if(!['nasr-search','nasr-modal-form','nasr-settings-form','nasr-report-filter','nasr-payroll-form','nasr-checkout-form','nasr-gateway-settings-form'].includes(form.id))return;
    event.preventDefault();
    if(form.id==='nasr-report-filter'){S.reportFilters[S.view]=formData(form);S.page=1;return render();}
    if(form.id==='nasr-search') { if(S.view==='people')return searchPeople(formData(form).q || '',0);S.q=formData(form).q || '';S.page=1;return render(); }
    if(form.dataset.saving)return;form.dataset.saving='1';
    const submit=form.querySelector('button[type="submit"]');if(submit)submit.disabled=true;
    try {
      if(form.id==='nasr-modal-form') { document.getElementById('nasr-modal-error').hidden=true;if(modalSubmit)await modalSubmit(form); }
      else if(form.id==='nasr-payroll-form'){const rows=adjustmentFormRows(form);const r=await api('teacher-payroll',{...S.payrollFilters,version:S.payrollData.version,rows});await render();toast(r.message);}
      else if(form.id==='nasr-checkout-form'){
        const gateway=form.querySelector('[name="gateway"]:checked')?.value;if(!gateway)throw new Error('یک درگاه پرداخت انتخاب کنید.');
        if(submit)submit.textContent='در حال اتصال امن به درگاه…';
        const result=await api(`checkout/${S.routeId}/start`,{gateway});if(window.NasrNative?.openPayment)await window.NasrNative.openPayment(result.redirect_url);else location.assign(result.redirect_url);
      }
      else if(form.id==='nasr-gateway-settings-form'){
        const gateways={};form.querySelectorAll('[data-gateway-id]').forEach(card=>{
          const values={},clear={};card.querySelectorAll('[data-gateway-setting]').forEach(input=>values[input.dataset.gatewaySetting]=input.type==='checkbox'?input.checked:input.value);
          card.querySelectorAll('[data-gateway-clear]').forEach(input=>clear[input.dataset.gatewayClear]=input.checked);
          gateways[card.dataset.gatewayId]={enabled:card.querySelector('[data-gateway-enabled]').checked,title:card.querySelector('[data-gateway-title]').value,description:card.querySelector('[data-gateway-description]').value,values,clear};
        });
        const result=await api('payment-gateways',{gateways});await render();toast(result.message);
      }
      else { const data=formData(form);data.preferences=extras.readPreferences(form);data.branches=[...form.querySelectorAll('[data-branch-row]')].map(row=>({id:+row.dataset.branchId || 0,name:row.querySelector('[data-branch-name]').value,active:row.querySelector('[data-branch-active]').checked}));const r=await api('settings',data);S.boot=await api('bootstrap');shell();toast(r.message);await render(); }
    } catch(e) {
      if(form.id==='nasr-modal-form' && form.isConnected) { const alert=form.querySelector('#nasr-modal-error');alert.textContent=e.message;alert.hidden=false;alert.scrollIntoView({block:'nearest'}); }
      else toast(e.message,true);
    } finally { delete form.dataset.saving;if(submit)submit.disabled=false; }
  });
  function updatePickerCount() { const count=root.querySelector('.nasr-picker-count');if(count)count.textContent=`انتخاب‌شده: ${num(picked().length)}`; }
  root.addEventListener('input',event=>{
    const el=event.target;
    if(event.isComposing)return;
    if(el.id==='nasr-student-query'){
      el.closest('.nasr-student-lookup').querySelector('[name=student_id]').value='';S.reportFilters[S.view].student_id='';S.report=null;clearTimeout(reportLive.timer);reportLive.controller?.abort();reportLive.token++;
      const result=root.querySelector('#nasr-report-result');if(result)result.innerHTML=empty('زبان‌آموز را انتخاب کنید','');searchStudents(el.value);return;
    }
    if(el.name==='q' && el.closest('#nasr-search') && S.view==='people'){searchPeople(el.value);return;}
    if(el.name==='q' && el.closest('#nasr-report-filter')){scheduleReportSearch(el.value);return;}
    if(el.closest('[data-adjustment-row]'))updatePayrollPreview();
    if(el.closest('[data-extra-fees]'))updateExtraFees(el.closest('[data-extra-fees]'));
    if(bulkCopy && el.closest('#nasr-bulk-copy')){
      if(bulkCopy.saving)return;
      if(el.matches('[data-copy-search]')){bulkCopy.q=el.value;renderCopyRoster();}
      else if(el.matches('[data-copy-student]')){
        try{chooseCopyStudent(+el.dataset.copyStudent,el.checked);}catch(error){el.checked=false;toast(error.message,true);}
        syncCopyUI();
      }else if(el.name==='discount'){bulkCopy.discount=el.value;syncCopyUI();}
      else if(el.name==='discount_scope'){bulkCopy.scope=el.value;syncCopyUI();}
      return;
    }
    if(el.matches('[data-live-search]')){scheduleLiveSearch(el.value);return;}
    if(el.matches('[data-live-student]') && livePicker){
      try{chooseLiveStudent(livePicker.rows.find(p=>+p.user_id===+el.dataset.studentId),el.checked);}catch(error){el.checked=false;toast(error.message,true);}
      syncLiveSelection();
    }
    if(el.matches('[data-picker-search]')) { const q=digits(el.value).trim().toLowerCase();root.querySelectorAll('.nasr-picker-item').forEach(l=>l.hidden=!digits(l.dataset.key).toLowerCase().includes(q)); }
    if(el.matches('[data-student-id]'))updatePickerCount();
    const form=el.closest('form'); if(!form)return;
    if(form.dataset.attendanceClass && el.name!=='attendance_session')form.dataset.attendanceDirty='1';
    if(['class_days','start_time','duration'].includes(el.name))form.dataset.scheduleTouched='1';
    if(el.name==='national_id') form.querySelectorAll('[data-auto-credential]').forEach(target=>{if(target!==el)target.value=digits(el.value);});
    if(root.querySelector('#nasr-class-total')) { const d=formData(form);root.querySelector('#nasr-class-total').innerHTML=money((Number(digits(d.base_fee))||0)+(Number(digits(d.extras_fee))||0)); }
    if(root.querySelector('#nasr-schedule-preview')) { const d=formData(form);root.querySelector('#nasr-schedule-preview').textContent=classSchedule({class_days:d.class_days,start_time:d.start_time,duration:d.duration}); }
    if(root.querySelector('#nasr-enroll-total')) { const d=formData(form),c=S.detail.class,base=+c.base_fee,extra=+c.extras_fee,disc=Number(digits(d.discount))||0;root.querySelector('#nasr-enroll-total').innerHTML=money(Math.max(0,Math.round(base+extra-(d.discount_scope==='total'?base+extra:base)*disc/100))); }
    if(root.querySelector('#nasr-total-preview')) { const d=formData(form),vals=Object.keys(gradeNames).map(k=>digits(d[k]).trim());root.querySelector('#nasr-total-preview').textContent=vals.some(v=>v==='' || !Number.isFinite(Number(v)))?'Incomplete':numEN(vals.reduce((sum,v)=>sum+Number(v),0)); }
  });
  root.addEventListener('focusout',event=>{
    const input=event.target;
    if(input.name==='start_time'){
      const time=normaliseClassTime(input.value);
      if(time && time!==input.value){input.value=time;input.dispatchEvent(new Event('input',{bubbles:true}));}
    }
  });
  window.addEventListener('hashchange',()=>{S.page=1;S.q='';closeModal();render();});
  root.addEventListener('focusin',event=>{const el=event.target;if(el.id==='nasr-student-query')searchStudents(el.closest('.nasr-student-lookup').querySelector('[name=student_id]').value?'':el.value,1,0);});
  root.addEventListener('click',event=>{const el=event.target;if(el.id==='nasr-student-query' && root.querySelector('#nasr-student-popup').hidden)searchStudents(el.closest('.nasr-student-lookup').querySelector('[name=student_id]').value?'':el.value,1,0);});
  window.addEventListener('resize',positionStudentLookup);
  window.addEventListener('scroll',positionStudentLookup,true);
  document.addEventListener('pointerdown',event=>{if(!event.target.closest('.nasr-student-lookup'))hideStudentLookup();});
  const liveState={revision:null,busy:false,dirty:new WeakSet(),lastInput:0};
  function canLiveRefresh(){
    if(document.querySelector('dialog[open]') || root.querySelector('[data-saving="1"]') || document.querySelector('.nasr-sidebar.open'))return false;
    if(Date.now()-liveState.lastInput<1500)return false;
    if(document.activeElement?.matches('input,textarea,select,[contenteditable="true"]') && root.contains(document.activeElement))return false;
    return ![...root.querySelectorAll('form')].some(form=>liveState.dirty.has(form));
  }
  function trackLiveEdit(event){
    liveState.lastInput=Date.now();const form=event.target.closest('form');
    if(form && ['nasr-settings-form','nasr-payroll-form','nasr-gateway-settings-form'].includes(form.id))liveState.dirty.add(form);
  }
  root.addEventListener('input',trackLiveEdit);root.addEventListener('change',trackLiveEdit);
  async function pollLive(){
    if(liveState.busy || document.visibilityState==='hidden' || !S.boot || !document.getElementById('nasr-main'))return;
    if(!navigator.onLine){window.dispatchEvent(new CustomEvent('nasr:connection',{detail:{connected:false}}));return;}
    liveState.busy=true;
    try{
      const value=await api('updates');
      if(typeof value.revision!=='string')return;
      window.dispatchEvent(new CustomEvent('nasr:connection',{detail:{connected:true}}));
      if(liveState.revision===null){liveState.revision=value.revision;return;}
      if(value.revision!==liveState.revision && canLiveRefresh()){
        const fresh=await api('bootstrap');if(!canLiveRefresh())return;S.boot=fresh;extras.applyAppearance();
        if(await render({background:true,bootFresh:true}))liveState.revision=value.revision;
      }
    }catch(e){if(e.status!==404)window.dispatchEvent(new CustomEvent('nasr:connection',{detail:{connected:false}}));}
    finally{liveState.busy=false;}
  }
  window.addEventListener('online',pollLive);window.addEventListener('focus',pollLive);window.addEventListener('nasr:resume',pollLive);
  window.addEventListener('offline',()=>window.dispatchEvent(new CustomEvent('nasr:connection',{detail:{connected:false}})));
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')pollLive();});
  async function init() {
    try { S.boot=await api('bootstrap');if(!location.hash)history.replaceState(null,'',`${location.pathname}${location.search}#dashboard`);shell();void pollLive();await render({bootFresh:true});setInterval(pollLive,3000); }
    catch(e) { root.innerHTML=empty('ورود به پنل انجام نشد',e.message,`<a class="nasr-button primary" href="${h(C.portal)}">تازه‌سازی و ورود دوباره</a>`); }
  }
  const extras=window.NasrFeatures({root,S,C,h,num,date,dateInput,today,field,select,check,button,link,sectionHead,empty,pager,api,toast,modal,closeModal,formData,render,reportTable,reportSummary,reportMetadata,comprehensiveClassReport,teacherPayReport,studentGradesReport,englishName,fullName,isManager:canManage,academicReports,financialReports,branchOptions});
  if(window.NasrAppViews)window.NasrAppPortal={async reloadRole(){
    ++routeToken;closeModal();stopPeopleSearch();stopStudentLookup();
    const boot=await api('bootstrap');
    for(const key of Object.keys(S))delete S[key];
    Object.assign(S,{boot,page:1,q:'',detail:null,terms:[],people:[],busy:false,branchId:'',reportFilters:{},report:null});
    liveState.revision=null;liveState.dirty=new WeakSet();liveState.lastInput=0;
    history.replaceState(null,'','#dashboard');shell();await render({bootFresh:true});void pollLive();
  }};
  init();
})();
