/* Same mobile presentation; browser sign-in stays with WordPress authentication. */
(async()=>{
  'use strict';
  const root=document.getElementById('nasr-app'),c=window.NasrPWAConfig,ui=window.NasrMobileUI,h=ui.escape,icon=ui.icon;
  const labels={manager:'مدیر',secretary:'منشی',teacher:'معلم',student:'زبان‌آموز',family:'خانواده'};
  const errors={invalid:'نام کاربری یا رمز عبور درست نیست.',denied:'این حساب دسترسی فعال به سامانه ندارد.',expired:'اعتبار فرم تمام شده است؛ دوباره تلاش کنید.',locked:'تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه دیگر امتحان کنید.',role:'نقش انتخاب‌شده در دسترس نیست.'};
  const hidden=(name,value)=>`<input type="hidden" name="${h(name)}" value="${h(value)}">`;
  const formStart=(action,cls='')=>`<form method="post" action="${h(c.post)}" class="${h(cls)}" data-pwa-form>${hidden('action','nasr_pwa_'+action)}${hidden('_nasr_pwa_nonce',c.nonces[action])}`;
  function page(title,body){
    root.innerHTML=`<main class="app-login"><div class="app-login-art" aria-hidden="true"><span class="app-art-orbit"></span><div class="app-art-book"><b>Aa</b><small>LEARN & GROW</small></div><span class="app-art-bubble bubble-one">Hello!</span><span class="app-art-bubble bubble-two">Bonjour</span><span class="app-art-star">✦</span></div><section class="app-login-card"><div class="app-login-brand"><img src="${h(c.assets)}nasr-logo.png" alt="آموزشگاه نصر"><div><b>نصر، همراه یادگیری تو</b><small lang="en">NASR LANGUAGE INSTITUTE</small></div></div><h1>${h(title)}</h1>${errors[c.error]?`<p class="nasr-alert error" role="alert">${h(errors[c.error])}</p>`:''}${body}${!window.NasrPWAStandalone()?'<button type="button" class="pwa-install" data-pwa-install>نصب روی گوشی</button>':''}</section><small class="app-build" dir="ltr">NASR · ${h(c.version)}</small></main>`;
  }
  function login(){
    page('به دنیای یادگیری خوش آمدی',`${formStart('login')}<label class="app-input">نام کاربری<div>${icon('user')}<input name="username" autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" required maxlength="100" placeholder="نام کاربری"></div></label><label class="app-input">رمز عبور<div>${icon('lock')}<input name="password" type="password" autocomplete="current-password" dir="ltr" required maxlength="4096" placeholder="رمز عبور"><button type="button" data-password-toggle aria-label="نمایش رمز عبور" aria-pressed="false">${icon('eye')}</button></div></label><label class="app-check"><input name="remember" type="checkbox" value="1" checked><span>مرا به خاطر بسپار</span></label><button class="nasr-button primary nasr-login-submit" type="submit">ورود به حساب${icon('arrow')}</button><div class="pwa-loading app-loading" role="status">${ui.orbit('lock')}<p>در حال ورود به سامانه…</p></div></form>${c.recovery?`<div class="pwa-login-links"><a href="${h(c.recovery)}">فراموشی رمز عبور</a></div>`:''}`);
  }
  function roles(){
    page('با چه نقشی وارد می‌شوی؟',`<div class="app-role-list">${c.user.available_roles.map(role=>`${formStart('role')}${hidden('role',role)}<button type="submit">${icon(role==='teacher'?'book':'user')}<span>${h(labels[role] || role)}</span>${icon('arrow')}</button></form>`).join('')}</div>`);
  }
  function dialog(id,title,body){
    document.getElementById(id)?.remove();const previous=document.activeElement;
    const el=document.createElement('dialog');el.id=id;el.className='app-account-dialog';el.setAttribute('aria-label',title);
    el.innerHTML=`<button class="app-dialog-close" aria-label="بستن" data-pwa-close>${icon('close')}</button><span class="app-account-avatar">${icon('user')}</span><h2>${h(title)}</h2>${body}`;
    root.append(el);el.querySelector('[data-pwa-close]').onclick=()=>el.close();el.addEventListener('close',()=>{if(previous?.isConnected)previous.focus({preventScroll:true});},{once:true});el.showModal();return el;
  }
  window.NasrAppAuth={
    showAccount(){
      const el=dialog('pwa-account','حساب من',`<p>${h(c.user.name)} · ${h(labels[c.user.role])}</p><button type="button" class="app-account-row" data-action="password">${icon('lock')}تغییر رمز عبور${icon('arrow')}</button><button type="button" class="app-account-row" data-pwa-settings>${icon('lock')}تنظیمات ورود${icon('arrow')}</button>${c.user.available_roles.length>1?'<button type="button" class="app-account-row" data-pwa-roles>انتخاب نقش ورود</button>':''}`);
      el.querySelector('[data-action=password]').addEventListener('click',()=>el.close());el.querySelector('[data-pwa-settings]').onclick=()=>{el.close();this.showLoginSettings();};
      const role=el.querySelector('[data-pwa-roles]');if(role)role.onclick=()=>{el.close();dialog('pwa-roles','انتخاب نقش ورود',`<div class="app-role-list">${c.user.available_roles.map(r=>`${formStart('role')}${hidden('role',r)}<button type="submit">${h(labels[r])}</button></form>`).join('')}</div>`);};
    },
    showLoginSettings(){
      dialog('pwa-settings','تنظیمات ورود',`${formStart('remember')}<label class="app-setting-toggle"><span>مرا به خاطر بسپار</span><input type="checkbox" name="remember" value="1" ${c.remember?'checked':''}></label><div class="app-dialog-actions"><button type="submit" class="nasr-button primary">ذخیره</button></div></form>${formStart('logout')}<div class="app-dialog-actions"><button type="submit" class="nasr-button danger">خروج از این دستگاه</button></div></form>`);
    }
  };
  document.addEventListener('submit',event=>{
    const form=event.target;if(!form.matches('[data-pwa-form]'))return;
    if(form.dataset.submitting){event.preventDefault();return;}
    if(!navigator.onLine){event.preventDefault();let out=form.querySelector('[data-pwa-error]');if(!out){out=document.createElement('p');out.dataset.pwaError='';out.className='nasr-alert error';out.setAttribute('role','alert');form.append(out);}out.textContent='اتصال اینترنت قطع است؛ دوباره پس از اتصال تلاش کنید.';return;}
    form.dataset.submitting='1';form.classList.add('pwa-submitting');form.setAttribute('aria-busy','true');
  });
  window.addEventListener('pageshow',()=>document.querySelectorAll('[data-pwa-form]').forEach(form=>{delete form.dataset.submitting;form.classList.remove('pwa-submitting');form.removeAttribute('aria-busy');}));
  if(!c.user){login();return;}
  if(!c.user.role){roles();return;}
  const script=name=>new Promise((resolve,reject)=>{const el=document.createElement('script');el.src=new URL(name+'?v='+encodeURIComponent(c.version),c.assets).href;el.onload=resolve;el.onerror=()=>reject(new Error('بارگذاری برنامه کامل نشد.'));document.head.append(el);});
  try{
    window.NasrOnline.configure(c.api,{...c.user,token:'wordpress-cookie'});window.NasrTransport=window.NasrOnline;
    window.NasrConfig={api:c.api,assets:c.assets,nonce:c.restNonce,portal:c.app,logout:c.app,roleChoice:c.app,isWPAdmin:false};
    root.innerHTML=ui.loading('در حال دریافت اطلاعات…');
    await script('portal-features.js');await script('portal.js');window.NasrAppAuthenticated=true;
  }catch(e){root.innerHTML=`<div class="nasr-empty"><h2>دریافت برنامه انجام نشد</h2><p>${h(e.message)}</p><a class="nasr-button" href="${h(c.app)}">تلاش دوباره</a></div>`;}
})();
