/* Browser transport uses WordPress's HttpOnly authentication cookie and REST nonce. */
(()=>{
  'use strict';
  const config=window.NasrPWAConfig;
  let expiring=false,installPrompt=null;
  window.NasrNative={
    async request(value,data,token,options={}){
      const url=new URL(value),api=new URL(config.api);
      const route=api.searchParams.get('rest_route');
      const allowed=route?url.pathname===api.pathname && (url.searchParams.get('rest_route')||'').startsWith(route.replace(/\/?$/,'/')):url.pathname.startsWith(api.pathname.replace(/\/?$/,'/'));
      if(url.origin!==location.origin || url.origin!==api.origin || !allowed)throw new Error('نشانی سامانه معتبر نیست.');
      const response=await fetch(url,{method:data===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',signal:options.signal,
        headers:{'Content-Type':'application/json','X-WP-Nonce':config.restNonce,'X-Nasr-PWA':'1'},...(data===undefined?{}:{body:JSON.stringify(data)})});
      let body;try{body=await response.json();}catch(e){throw new Error('پاسخ سامانه معتبر نیست؛ دوباره تلاش کنید.');}
      if(!expiring && (response.status===401 || body?.code==='rest_cookie_invalid_nonce')){expiring=true;setTimeout(()=>location.replace(config.app),0);}
      return {status:response.status,data:body};
    },
    async openPayment(value){const url=new URL(value);if(url.protocol!=='https:'||url.username||url.password)throw new Error('نشانی درگاه معتبر نیست.');location.assign(url.href);}
  };
  const standalone=()=>matchMedia('(display-mode: standalone)').matches || navigator.standalone===true;
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;});
  window.addEventListener('appinstalled',()=>{installPrompt=null;document.querySelectorAll('[data-pwa-install]').forEach(x=>x.remove());});
  document.addEventListener('click',async event=>{
    const trigger=event.target.closest('[data-pwa-install]');if(!trigger)return;
    if(installPrompt){await installPrompt.prompt();installPrompt=null;return;}
    const old=document.getElementById('pwa-install-help');old?.remove();
    const dialog=document.createElement('dialog');dialog.id='pwa-install-help';dialog.className='app-account-dialog';dialog.setAttribute('aria-label','نصب وب‌اپ');
    dialog.innerHTML='<h2>نصب روی گوشی</h2><p>در آیفون، این صفحه را با Safari باز کن. از منوی Share گزینهٔ Add to Home Screen را انتخاب کن و اگر Open as Web App نمایش داده شد آن را روشن کن؛ سپس Add را بزن.</p><button class="nasr-button primary" type="button">بستن</button>';
    document.getElementById('nasr-app').append(dialog);dialog.querySelector('button').onclick=()=>dialog.close();dialog.showModal();
  });
  window.NasrPWAStandalone=standalone;
  if('serviceWorker' in navigator && window.isSecureContext){
    window.addEventListener('load',async()=>{
      try{
        const scope=new URL(config.scope).href;
        const existing=(await navigator.serviceWorker.getRegistrations()).find(r=>r.scope===scope);
        const worker=existing?.active || existing?.waiting || existing?.installing;
        // Preserve an existing worker owned by another application on this site.
        if(worker && new URL(worker.scriptURL).href!==new URL(config.worker).href)return;
        await navigator.serviceWorker.register(config.worker,{scope:config.scope,updateViaCache:'none'});
      }catch(e){/* Home Screen installation and online use remain available. */}
    },{once:true});
  }
})();
