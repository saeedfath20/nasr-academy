/* Online application shell shared by the website, Android and iOS. */
(async () => {
  'use strict';
  const root=document.getElementById('nasr-app'), online=window.NasrOnline, config=window.NasrAppConfig || {};
  const labels={manager:'مدیر',secretary:'منشی',teacher:'معلم',student:'زبان‌آموز',family:'خانواده'};
  const h=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let api=config.api || '', assets=new URL(config.assets || './core/',location.href).href, session=null, opening=false;
  const sessionKey=()=> 'nasr-online-login:'+api;
  const vault={
    async load(){if(window.NasrNative?.loadSession)return window.NasrNative.loadSession(api);try{return JSON.parse(sessionStorage.getItem(sessionKey()) || 'null');}catch(e){return null;}},
    async save(value){if(window.NasrNative?.saveSession)return window.NasrNative.saveSession(api,value);try{sessionStorage.setItem(sessionKey(),JSON.stringify(value));}catch(e){}},
    async clear(){if(window.NasrNative?.forgetSession)return window.NasrNative.forgetSession(api);try{sessionStorage.removeItem(sessionKey());}catch(e){}}
  };
  function error(e){const out=document.getElementById('nasr-app-error');if(out){out.hidden=false;out.textContent=e.message || String(e);}else alert(e.message || String(e));}
  const direct=(path,data,token='')=>online.send(path,data,api,token);
  function script(name){return new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=new URL(name,assets).href;s.onload=resolve;s.onerror=()=>reject(new Error('بارگذاری برنامه کامل نشد.'));document.head.append(s);});}
  function form(title,body,submit){
    root.innerHTML=`<main class="nasr-app-login"><div class="nasr-login-orbit" aria-hidden="true"><span>Aa</span><i>✧</i></div><section><div class="nasr-login-brand"><img class="nasr-logo" src="${h(assets)}nasr-logo.png" alt="نصر"><span lang="en">NASR<br><b>ACADEMY</b></span></div><span class="nasr-login-eyebrow">آموزش، همیشه همراه شما</span><h1>${h(title)}</h1><p id="nasr-app-error" class="nasr-alert error" role="alert" hidden></p><form id="nasr-app-form">${body}<button class="nasr-button primary nasr-login-submit" type="submit"><span>${h(submit)}</span>${window.NasrMobileUI?.icon('arrow') || ''}</button></form></section><span class="nasr-login-footer">سامانه یکپارچه آموزشگاه نصر</span></main>`;
  }
  function login(){
    form('خوش آمدید',`<label>نام کاربری<input name="username" autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" required maxlength="100" placeholder="Username"></label><label>رمز عبور<div class="nasr-password-wrap"><input name="password" type="password" autocomplete="current-password" dir="ltr" required maxlength="4096" placeholder="Password"><button type="button" data-password-toggle aria-label="نمایش رمز عبور" aria-pressed="false">${window.NasrMobileUI.icon('eye')}</button></div></label>`,'ورود به حساب');
    document.getElementById('nasr-app-form').onsubmit=async event=>{event.preventDefault();const f=event.currentTarget,b=f.querySelector('[type=submit]');if(b.disabled)return;b.disabled=true;
      try{session=await direct('mobile/login',Object.fromEntries(new FormData(f)));await vault.save(session);await chooseOrOpen();}
      catch(e){error(e);b.disabled=false;}
    };
  }
  async function chooseOrOpen(){
    if(session.role && !session.choose_role)return openPanel();
    form('با چه نقشی وارد می‌شوید؟',`<div class="nasr-app-role-list">${session.available_roles.map(role=>`<button type="button" data-mobile-role="${h(role)}" class="nasr-button soft">${window.NasrMobileUI.icon(role==='teacher'?'book':role==='manager'||role==='secretary'?'report':'user')}<span>${h(labels[role])}</span>${window.NasrMobileUI.icon('arrow')}</button>`).join('')}</div>`,'');
    root.querySelector('.nasr-login-submit').remove();
    root.onclick=async event=>{const button=event.target.closest('[data-mobile-role]');if(!button)return;button.disabled=true;
      try{session={...session,...await direct('mobile/role',{role:button.dataset.mobileRole},session.token)};delete session.choose_role;await vault.save(session);root.onclick=null;await openPanel();}
      catch(e){error(e);button.disabled=false;}
    };
  }
  async function openPanel(){
    if(opening)return;opening=true;
    try{
      online.configure(api,session);window.NasrTransport=online;
      window.NasrConfig={api,assets,nonce:'',portal:location.href.split('#')[0],logout:'#nasr-app-logout',roleChoice:'#nasr-app-role-choice',isWPAdmin:false};
      root.innerHTML='<div class="nasr-loading" role="status"><span class="nasr-spinner"></span><p>در حال دریافت اطلاعات…</p></div>';
      let upgrade;
      try{await script('legacy-transfer.js');upgrade=await window.NasrLegacyTransfer(api,session,online.request);}catch(e){upgrade={error:e.message};}
      await script('portal-features.js');await script('portal.js');
      if(upgrade?.error)setTimeout(()=>{const box=document.getElementById('nasr-toast');if(box){box.hidden=false;box.className='nasr-toast error';box.textContent='انتقال اطلاعات نسخهٔ قبلی تأیید نشد؛ دادهٔ ثبت‌نشده محفوظ است. '+upgrade.error;}},1000);
    }catch(e){opening=false;error(e);}
  }
  async function setup(){
    form('اتصال به آموزشگاه نصر','<label>نشانی سایت وردپرس<input name="site" type="url" placeholder="https://example.com/" dir="ltr" required></label>','اتصال');
    document.getElementById('nasr-app-form').onsubmit=async event=>{event.preventDefault();const f=event.currentTarget,b=f.querySelector('[type=submit]');if(b.disabled)return;b.disabled=true;
      try{
        const site=new URL(new FormData(f).get('site'));if(site.protocol!=='https:' || site.username || site.password || site.search || site.hash)throw new Error('نشانی HTTPS سایت وردپرس را وارد کنید.');
        if(!site.pathname.endsWith('/'))site.pathname+='/';api=new URL('wp-json/nasr/v1/',site).href;
        let info;try{info=await direct('mobile/info');}catch(e){const fallback=new URL(site);fallback.searchParams.set('rest_route','/nasr/v1/');api=fallback.href;info=await direct('mobile/info');}
        if(info.protocol!==1)throw new Error('نسخهٔ افزونه با این برنامه سازگار نیست.');
        localStorage.setItem('nasr-app-api',api);login();
      }catch(e){error(e);b.disabled=false;}
    };
  }
  document.addEventListener('click',async event=>{
    const link=event.target.closest('a');if(!link)return;const href=link.getAttribute('href');
    if(!['#nasr-app-logout','#nasr-app-role-choice'].includes(href))return;event.preventDefault();
    try{
      if(href==='#nasr-app-logout'){
        try{await direct('mobile/logout',{},session.token);}catch(e){if(![401,403].includes(e.status))throw e;}
        online.clear();await vault.clear();location.hash='dashboard';location.reload();
      }else{session.choose_role=true;await vault.save(session);location.hash='dashboard';location.reload();}
    }catch(e){error(e);}
  },true);
  try{
    await script('mobile-ui.js');api ||= localStorage.getItem('nasr-app-api') || '';
    if(!api)return setup();
    const checked=new URL(api);if(checked.protocol!=='https:' || checked.username || checked.password)throw new Error('نشانی سرور باید HTTPS باشد.');
    if(checked.searchParams.has('rest_route'))checked.searchParams.set('rest_route',checked.searchParams.get('rest_route').replace(/\/?$/,'/'));else checked.pathname=checked.pathname.replace(/\/?$/,'/');api=checked.href;
    session=await vault.load();if(!session)return login();
    try{session={...session,...await direct('mobile/me',undefined,session.token)};await vault.save(session);}
    catch(e){if([401,403].includes(e.status)){await vault.clear();return login();}throw e;}
    await chooseOrOpen();
  }catch(e){form('اتصال به سامانه','','تلاش دوباره');document.getElementById('nasr-app-form').onsubmit=event=>{event.preventDefault();location.reload();};error(e);}
})();
