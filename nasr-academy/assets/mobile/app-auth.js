/* App-only sign-in. Saved sessions are encrypted by the native vault and verified online. */
(async()=>{
  'use strict';
  const root=document.getElementById('nasr-app'),config=window.NasrAppConfig,online=window.NasrOnline;
  const ui=window.NasrMobileUI,h=ui.escape,icon=ui.icon,native=window.NasrNative;
  const labels={manager:'مدیر',secretary:'منشی',teacher:'معلم',student:'زبان‌آموز',family:'خانواده'};
  const api=config.api,assets=config.assets;
  let session=null,opening=false,busy=false,enableBiometric=false,remember=true,mode='none';
  let biometric={available:false,saved:false};
  const direct=(path,data,token='')=>online.send(path,data,api,token);
  const script=name=>new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=new URL(name,assets).href;s.onload=resolve;s.onerror=()=>reject(new Error('بارگذاری برنامه کامل نشد.'));document.head.append(s);});
  async function prompt(task){window.NasrAuthPromptActive=true;try{return await task();}finally{window.NasrAuthPromptActive=false;}}
  const message=error=>{const out=document.getElementById('nasr-app-error');if(out){out.hidden=false;out.textContent=error.message || String(error);}};
  async function biometricState(strict=false){
    try{biometric=await native?.biometricStatus(api) || {available:false,saved:false};}
    catch(e){if(strict)throw e;biometric={available:false,saved:false};}
    return biometric;
  }
  const biometricLabel=()=>biometric.type==='face'?'ورود با تشخیص چهره':biometric.type==='fingerprint'?'ورود با اثر انگشت':'ورود با اثر انگشت / چهره';
  function page(title,body,submit){
    root.innerHTML=`<main class="app-login"><div class="app-login-art" aria-hidden="true"><span class="app-art-orbit"></span><div class="app-art-book"><b>Aa</b><small>LEARN & GROW</small></div><span class="app-art-bubble bubble-one">Hello!</span><span class="app-art-bubble bubble-two">Bonjour</span><span class="app-art-star">✦</span></div><section class="app-login-card"><div class="app-login-brand"><img src="${h(assets)}nasr-logo.png" alt="آموزشگاه نصر"><div><b>نصر، همراه یادگیری تو</b><small lang="en">NASR LANGUAGE INSTITUTE</small></div></div><h1>${h(title)}</h1><p id="nasr-app-error" class="nasr-alert error" role="alert" hidden></p><form id="nasr-app-form">${body}${submit?`<button class="nasr-button primary nasr-login-submit" type="submit">${h(submit)}${icon('arrow')}</button>`:''}</form></section><small class="app-build" dir="ltr">NASR · ${h(config.version)}</small></main>`;
  }
  function authStatus(text){const out=root.querySelector('.app-login-gate [role=status]');if(out)out.textContent=text;}
  function authBusy(value,text){
    busy=value;
    const gate=root.querySelector('.app-login-gate');gate?.classList.toggle('is-auth-busy',value);gate?.setAttribute('aria-busy',String(value));
    if(text)authStatus(text);
    root.querySelectorAll('#nasr-app-form input,#nasr-app-form button,[data-biometric-login],[data-resume-login],[data-use-password]').forEach(el=>el.disabled=value);
  }
  async function login(restore=false){
    window.NasrAppAuthenticated=false;session=null;opening=false;mode='none';enableBiometric=false;remember=true;
    if(restore)return resumeSaved();
    await biometricState();
    const biom=biometric.available?`<label class="app-check"><input name="enable_biometric" type="checkbox" ${biometric.saved?'checked':''}><span>فعال‌سازی ورود با اثر انگشت / چهره</span></label>`:'';
    page('به دنیای یادگیری خوش آمدی',`<label class="app-input">نام کاربری<div>${icon('user')}<input name="username" autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" required maxlength="100" placeholder="نام کاربری"></div></label><label class="app-input">رمز عبور<div>${icon('lock')}<input name="password" type="password" autocomplete="current-password" dir="ltr" required maxlength="4096" placeholder="رمز عبور"><button type="button" data-password-toggle aria-label="نمایش رمز عبور" aria-pressed="false">${icon('eye')}</button></div></label><label class="app-check"><input name="remember_login" type="checkbox" checked><span>مرا به خاطر بسپار</span></label>${biom}`,'ورود به حساب');
    if(biometric.available&&biometric.saved){
      document.getElementById('nasr-app-form').insertAdjacentHTML('afterend',`<div class="app-or"><span>یا</span></div><button class="app-biometric" type="button" data-biometric-login>${icon(biometric.type==='face'?'face':'fingerprint')}<span>${biometricLabel()}</span></button>`);
      root.querySelector('[data-biometric-login]').onclick=biometricLogin;
    }
    document.getElementById('nasr-app-form').onsubmit=async event=>{
      event.preventDefault();if(busy)return;
      const form=event.currentTarget;
      enableBiometric=!!form.elements.enable_biometric?.checked;
      remember=!!form.elements.remember_login.checked;
      const credentials={username:form.elements.username.value,password:form.elements.password.value};
      authBusy(true);
      try{
        const authenticated=await direct('mobile/login',credentials);
        form.elements.password.value='';
        // A successful switch of account must retire the previous device login first.
        await native?.forgetSession(api);await native?.biometricForget(api);
        session=authenticated;mode='password';
        if((session.available_roles||[]).length>1)session.choose_role=true;
        await chooseOrOpen();
      }catch(e){message(e);}
      finally{credentials.password='';authBusy(false);}
    };
    authBusy(false);

  }
  async function verifySaved(saved){
    if(!saved?.token)throw Object.assign(new Error('ابتدا با نام کاربری و رمز عبور وارد شوید.'),{status:401});
    const me=await direct('mobile/me',undefined,saved.token);
    session={...saved,...me};
    // A returning account keeps its server-authorized role; a new login still gets the picker.
    delete session.choose_role;
    if(!session.role&&(session.available_roles||[]).length>1)session.choose_role=true;
  }
  async function expiredLogin(){
    await native?.forgetSession(api);await native?.biometricForget(api);
    await login(false);message(new Error('اعتبار ورود ذخیره‌شده تمام شده است؛ با نام کاربری و رمز عبور وارد شوید.'));
  }
  function gate(kind,error){
    const bio=kind==='biometric';
    page(bio?'ورود با اثر انگشت / چهره':'ورود به حساب ذخیره‌شده',`<div class="app-login-gate">${ui.orbit(bio?'fingerprint':'lock')}<p role="status" aria-live="polite">${error?'برای ادامه دوباره تلاش کنید.':bio?'اثر انگشت یا چهرهٔ خود را تأیید کنید.':'در حال بررسی ورود ذخیره‌شده…'}</p><button class="app-biometric" type="button" ${bio?'data-biometric-login':'data-resume-login'}>${bio?biometricLabel():'تلاش دوباره'}</button><button class="nasr-button text app-password-fallback" type="button" data-use-password>ورود با نام کاربری و رمز عبور</button></div>`,'');
    root.querySelector('[data-use-password]').onclick=()=>login(false);
    root.querySelector(bio?'[data-biometric-login]':'[data-resume-login]').onclick=bio?biometricLogin:resumeSaved;
    if(error)message(error);
  }
  async function resumeSaved(){
    if(busy)return;
    gate('saved');authBusy(true);
    try{
      await native?.ready?.();
      await biometricState(true);
      if(biometric.saved){
        gate('biometric');authBusy(true);
        // Do not expose credentials while the vault or foreground activity is starting.
        await native?.forgetSession(api);
        if(!biometric.available){gate('biometric',new Error('اثر انگشت یا چهره فعلاً در دسترس نیست؛ دوباره تلاش کنید یا ورود با رمز را انتخاب کنید.'));return;}
        authBusy(false);
        await biometricLogin();return;
      }
      const saved=await native?.loadSession(api);
      if(!saved?.token){await login(false);return;}
      authStatus('در حال تأیید ورود…');await verifySaved(saved);remember=true;mode='session';await chooseOrOpen();
    }catch(e){
      if([401,403].includes(e.status))await expiredLogin();
      else gate(biometric.saved?'biometric':'saved',e);
    }finally{authBusy(false);}
  }
  async function biometricLogin(){
    if(busy)return;
    gate('biometric');authBusy(true);
    try{
      const saved=await prompt(()=>native.biometricLoad(api));
      authStatus('تأیید شد؛ در حال ورود به پنل…');
      await verifySaved(saved);enableBiometric=false;remember=true;mode='biometric';await chooseOrOpen();
    }catch(e){
      if([401,403].includes(e.status))await expiredLogin();
      else if(e.code==='BIOMETRIC_INVALIDATED'){
        await native.biometricForget(api);await login(false);message(e);
      }else gate('biometric',e.code==='BIOMETRIC_CANCELLED'?null:e);
    }finally{authBusy(false);}
  }
  async function chooseOrOpen(){
    if(session.role&&!session.choose_role)return openPanel();
    page('با چه نقشی وارد می‌شوی؟',`<div class="app-role-list">${session.available_roles.map(role=>`<button type="button" data-mobile-role="${h(role)}">${icon(role==='teacher'?'book':'user')}<span>${h(labels[role])}</span>${icon('arrow')}</button>`).join('')}</div>`,'');
    root.querySelectorAll('[data-mobile-role]').forEach(button=>button.onclick=async()=>{
      if(button.disabled)return;root.querySelectorAll('[data-mobile-role]').forEach(b=>b.disabled=true);
      try{session={...session,...await direct('mobile/role',{role:button.dataset.mobileRole},session.token)};delete session.choose_role;await openPanel();}
      catch(e){message(e);root.querySelectorAll('[data-mobile-role]').forEach(b=>b.disabled=false);}
    });
  }
  const notSaved=()=>Object.assign(new Error('ذخیرهٔ ورود در این گوشی تأیید نشد. دوباره تلاش کنید.'),{code:'VAULT_VERIFY_FAILED'});
  async function storeSession(){
    if(!native?.saveSession||!native?.loadSession)throw notSaved();
    const ack=await native.saveSession(api,session);
    if(ack?.saved!==true){
      const check=await native.loadSession(api);
      if(check?.token!==session.token||+check?.user_id!==+session.user_id)throw notSaved();
    }
  }
  async function storeBiometric(){
    if(!native?.biometricSave)throw notSaved();
    await prompt(()=>native.biometricSave(api,session));
    const state=await biometricState(true);
    if(state.saved!==true)throw notSaved();
  }
  async function persistLogin(){
    if(mode==='password'&&enableBiometric){
      try{
        await storeBiometric();mode='biometric';remember=true;
      }catch(e){
        // A cancelled enrollment must not discard a separately selected remember option.
        const state=await biometricState(true);
        if(state.saved){mode='biometric';remember=true;await native?.forgetSession(api);return '';}
        if(remember){await storeSession();mode='session';}
        else mode='none';
        return e.code==='BIOMETRIC_CANCELLED'?'':remember?'اثر انگشت فعال نشد؛ ورود عادی شما در این گوشی ذخیره شد.':e.message;
      }finally{enableBiometric=false;}
    }else if(mode==='password'){
      if(remember){await storeSession();mode='session';}
      else mode='none';
    }
    if(mode==='biometric')await native?.forgetSession(api);
    return '';
  }
  function storageRecovery(error){
    opening=false;
    page('ورود در این گوشی ذخیره نشد','<div class="app-login-gate"><button class="nasr-button primary" type="button" data-retry-storage>تلاش دوباره برای ذخیرهٔ ورود</button><button class="nasr-button text" type="button" data-once>ورود فقط برای این بار</button></div>','');
    message(error);
    root.querySelector('[data-retry-storage]').onclick=()=>openPanel();
    root.querySelector('[data-once]').onclick=async()=>{
      try{await native?.forgetSession(api);await native?.biometricForget(api);remember=false;enableBiometric=false;mode='none';await openPanel();}
      catch(e){message(e);}
    };
  }
  async function openPanel(){
    if(opening)return;opening=true;
    try{
      let storageError='';
      try{storageError=await persistLogin();}catch(e){storageRecovery(e);return;}
      online.configure(api,session);window.NasrTransport=online;
      window.NasrConfig={api,assets,nonce:'',portal:location.href.split('#')[0],logout:'#nasr-app-logout',roleChoice:'#nasr-app-role-choice',isWPAdmin:false};
      root.innerHTML=ui.loading('در حال آماده‌شدن کلاس‌های شما…');
      let upgrade;
      await Promise.all([(async()=>{try{await script('legacy-transfer.js');upgrade=await window.NasrLegacyTransfer(api,session,online.request);}catch(e){upgrade={error:e.message};}})(),script('portal-features.js')]);
      await script('portal.js');window.NasrAppAuthenticated=true;
      if(upgrade?.error||storageError)setTimeout(()=>{const box=document.getElementById('nasr-toast');if(box){box.hidden=false;box.className='nasr-toast error';box.textContent=storageError||'انتقال اطلاعات قبلی تأیید نشد؛ دادهٔ ثبت‌نشده محفوظ است. '+upgrade.error;}},1000);
    }catch(e){opening=false;page('ارتباط برقرار نشد','<button type="button" class="nasr-button" onclick="location.reload()">تلاش دوباره</button>','');message(e);}
  }
  function accountDialog(id,title,body){
    document.getElementById(id)?.remove();
    const previous=document.activeElement;
    const dialog=document.createElement('dialog');dialog.id=id;dialog.className='app-account-dialog';dialog.setAttribute('aria-label',title);
    dialog.innerHTML=`<button class="app-dialog-close" aria-label="بستن" data-close-account>${icon('close')}</button><span class="app-account-avatar">${icon('lock')}</span><h2>${h(title)}</h2>${body}<p class="app-setting-error" role="alert" hidden></p>`;
    root.append(dialog);dialog.showModal();
    dialog.querySelector('[data-close-account]').onclick=()=>dialog.close();
    dialog.addEventListener('close',()=>{if(previous?.isConnected)previous.focus({preventScroll:true});},{once:true});
    return dialog;
  }
  function dialogError(dialog,error){const out=dialog.querySelector('[role=alert]');out.hidden=false;out.textContent=error.message || String(error);}
  async function logout(){
    if(busy)return;busy=true;
    try{
      try{await direct('mobile/logout',{},session.token);}catch(e){if(![401,403].includes(e.status))throw e;}
      await native?.forgetSession(api);await native?.biometricForget(api);online.clear();
      location.hash='dashboard';location.reload();
    }finally{busy=false;}
  }
  window.NasrAppAuth={
    async showAccount(){
      if(!session)return;
      const dialog=accountDialog('app-account-dialog','حساب من',`<p>${h(session.name || '')} · ${h(labels[session.role])}</p><button class="app-account-row" data-action="password">${icon('lock')}تغییر رمز عبور${icon('arrow')}</button><button class="app-account-row" data-open-login-settings>${icon('fingerprint')}<span>تنظیمات ورود</span>${icon('arrow')}</button>${(session.available_roles||[]).length>1?'<a class="app-account-row" href="#nasr-app-role-choice">انتخاب نقش ورود</a>':''}<small class="app-build" dir="ltr">NASR · ${h(config.version)}</small>`);
      dialog.querySelector('[data-action=password]').addEventListener('click',()=>dialog.close());
      dialog.querySelector('[data-open-login-settings]').onclick=()=>{dialog.close();window.NasrAppAuth.showLoginSettings();};
    },
    async showLoginSettings(){
      if(!session)return;
      await biometricState();
      const dialog=accountDialog('app-login-settings','تنظیمات ورود',`<label class="app-setting-toggle"><span>${biometric.saved?'ورود ذخیره‌شده با بیومتریک':'مرا به خاطر بسپار'}</span><input type="checkbox" data-remember-setting ${mode==='session'||biometric.saved?'checked':''} ${biometric.saved?'disabled':''}></label>${biometric.available||biometric.saved?`<button class="app-account-row" data-biometric-setting>${icon('fingerprint')}<span>${biometric.saved?'غیرفعال‌کردن':'فعال‌کردن'} ورود با اثر انگشت / چهره</span></button>`:''}<div class="app-dialog-actions"><button class="nasr-button danger" type="button" data-device-logout>خروج از این دستگاه</button><button class="nasr-button primary" type="button" data-close-settings>بستن</button></div>`);
      dialog.querySelector('[data-close-settings]').onclick=()=>dialog.close();
      dialog.querySelector('[data-device-logout]').onclick=async event=>{const button=event.currentTarget;button.disabled=true;try{await logout();}catch(e){dialogError(dialog,e);button.disabled=false;}};
      const keep=dialog.querySelector('[data-remember-setting]');
      keep.onchange=async()=>{
        keep.disabled=true;
        try{if(keep.checked){await storeSession();mode='session';remember=true;}else{await native.forgetSession(api);mode='none';remember=false;}}
        catch(e){keep.checked=mode==='session';dialogError(dialog,e);}finally{keep.disabled=false;}
      };
      const button=dialog.querySelector('[data-biometric-setting]');
      if(button)button.onclick=async()=>{
        button.disabled=true;
        try{
          if(biometric.saved){await native.biometricForget(api);await native.forgetSession(api);mode='none';remember=false;}
          else{await storeBiometric();mode='biometric';remember=true;await native.forgetSession(api);}
          dialog.close();await window.NasrAppAuth.showLoginSettings();
        }catch(e){if(e.code!=='BIOMETRIC_CANCELLED')dialogError(dialog,e);button.disabled=false;}
      };
    }
  };
  document.addEventListener('click',async event=>{
    const href=event.target.closest('a')?.getAttribute('href');
    if(!['#nasr-app-logout','#nasr-app-role-choice'].includes(href))return;
    event.preventDefault();event.stopPropagation();
    if(busy||!session)return;
    if(href==='#nasr-app-logout'){try{await logout();}catch(e){alert(e.message);}return;}
    busy=true;
    try{
      document.getElementById('app-account-dialog')?.close();
      const dialog=document.createElement('dialog');dialog.className='app-role-dialog';
      dialog.innerHTML=`<h2>انتخاب نقش ورود</h2><div class="app-role-list">${session.available_roles.map(role=>`<button type="button" data-role="${h(role)}">${h(labels[role])}</button>`).join('')}</div><div class="app-dialog-actions"><button class="nasr-button" data-cancel>انصراف</button></div><p role="alert"></p>`;
      root.append(dialog);dialog.showModal();dialog.querySelector('[data-cancel]').onclick=()=>dialog.close();
      dialog.querySelectorAll('[data-role]').forEach(button=>button.onclick=async()=>{
        dialog.querySelectorAll('button').forEach(b=>b.disabled=true);
        try{
          session={...session,...await direct('mobile/role',{role:button.dataset.role},session.token)};delete session.choose_role;
          if(mode==='session')await storeSession();
          online.configure(api,session);dialog.close();await window.NasrAppPortal.reloadRole();
        }catch(e){dialog.querySelector('[role=alert]').textContent=e.message;dialog.querySelectorAll('button').forEach(b=>b.disabled=false);}
      });
    }catch(e){alert(e.message);}finally{busy=false;}
  },true);
  try{
    const checked=new URL(api);if(checked.protocol!=='https:'||checked.username||checked.password)throw new Error('نشانی امن سامانه تنظیم نشده است.');
    try{sessionStorage.removeItem('nasr-online-login:'+api);}catch(e){}
    await login(true);
  }catch(e){page('ورود به سامانه','','');message(e);}
})();
