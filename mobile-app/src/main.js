let interfaceUpdater=null,lastUpdateCheck=0;
import { Capacitor, CapacitorHttp, registerPlugin } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
if (Capacitor.isNativePlatform()) {
  const secure=registerPlugin('NasrSecureStore'), key=api=>'login:'+api;
  if(Capacitor.getPlatform()==='android')interfaceUpdater=registerPlugin('NasrLiveUpdate');
  const foregroundWaiters=new Set();
  let foregroundActive=false,foregroundRevision=0;
  const readForeground=async()=>{
    const revision=foregroundRevision,state=await App.getState();
    if(revision===foregroundRevision)foregroundActive=state.isActive;
  };
  const ready=async()=>{
    await readForeground();
    if(!foregroundActive)await new Promise(resolve=>foregroundWaiters.add(resolve));
    // Let the foreground activity draw before opening a system authentication prompt.
    await new Promise(resolve=>requestAnimationFrame(resolve));
  };
  const vaultError=()=>Object.assign(new Error('ذخیرهٔ ورود در این گوشی تأیید نشد. دوباره تلاش کنید.'),{code:'VAULT_VERIFY_FAILED'});
  window.NasrNative={
    ready,
    request:async(url,data,token,options={})=>{
      if(options.signal?.aborted)throw new DOMException('Cancelled','AbortError');
      const response=await CapacitorHttp.request({url,method:data===undefined?'GET':'POST',headers:{'Content-Type':'application/json',...(token?{Authorization:'Nasr '+token}:{})},...(data===undefined?{}:{data}),connectTimeout:8000,readTimeout:20000,disableRedirects:true,responseType:'json'});
      if(options.signal?.aborted)throw new DOMException('Cancelled','AbortError');
      return {...response,data:typeof response.data==='string'?JSON.parse(response.data):response.data};
    },
    saveSession:async(api,value)=>{
      const encoded=JSON.stringify(value);
      await secure.set({key:key(api),value:encoded});
      if((await secure.get({key:key(api)}))?.value!==encoded)throw vaultError();
      return {saved:true};
    },
    loadSession:async api=>{const r=await secure.get({key:key(api)});return r.value?JSON.parse(r.value):null;},
    forgetSession:async api=>{
      await secure.remove({key:key(api)});
      if((await secure.get({key:key(api)}))?.value)throw vaultError();
    },
    biometricStatus:async api=>{await ready();return secure.biometricStatus({key:key(api)});},
    biometricSave:async(api,value)=>{
      await ready();await secure.biometricSet({key:key(api),value:JSON.stringify(value)});
      if((await secure.biometricStatus({key:key(api)}))?.saved!==true)throw vaultError();
      return {saved:true};
    },
    biometricLoad:async api=>{await ready();const r=await secure.biometricGet({key:key(api)});return r.value?JSON.parse(r.value):null;},
    biometricForget:async api=>secure.biometricRemove({key:key(api)}),
    openPayment:async value=>{const url=new URL(value);if(url.protocol!=='https:' || url.username || url.password)throw new Error('نشانی درگاه پرداخت معتبر نیست.');await Browser.open({url:url.href});}
  };
  let backgroundAt=0;
  const refresh=()=>window.dispatchEvent(new Event('nasr:resume'));
  await App.addListener('appStateChange',state=>{
    foregroundRevision++;foregroundActive=state.isActive;
    if(state.isActive){for(const resolve of foregroundWaiters)resolve();foregroundWaiters.clear();}
    if(window.NasrAuthPromptActive)return;
    if(!state.isActive){backgroundAt=Date.now();return;}
    if(backgroundAt && Date.now()-backgroundAt>60000 && window.NasrAppAuthenticated){location.reload();return;}
    backgroundAt=0;refresh();
  });
  // The app may already have resumed between module evaluation and listener registration.
  await readForeground();
  if(foregroundActive){for(const resolve of foregroundWaiters)resolve();foregroundWaiters.clear();}
  await Browser.addListener('browserFinished',refresh);
  await App.addListener('backButton',async()=>{
    const dialog=document.querySelector('dialog[open]');if(dialog){dialog.close();return;}
    const drawer=document.querySelector('.nasr-sidebar.open');if(drawer){drawer.classList.remove('open');return;}
    if(location.hash && location.hash!=='#dashboard'){history.back();return;}await App.minimizeApp();
  });
}
const assets=window.NasrAppConfig.assets;
try{
  const load=url=>new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=url.href;script.onload=resolve;script.onerror=()=>reject(new Error('بارگذاری برنامه کامل نشد.'));document.head.append(script);});
  await Promise.all([load(new URL('online.js',assets)),load(new URL('./app-ui.js',location.href))]);
  await load(new URL('./app-auth.js',location.href));
  if(!window.NasrAppAuth)throw new Error('رابط ورود بارگذاری نشد.');
  if(interfaceUpdater){
    await interfaceUpdater.ready().catch(()=>{});
    const check=()=>{if(!navigator.onLine||Date.now()-lastUpdateCheck<300000)return;lastUpdateCheck=Date.now();void interfaceUpdater.check().catch(()=>{});};
    setTimeout(check,5000);window.addEventListener('nasr:resume',check);window.addEventListener('online',check);
  }
}catch(error){document.getElementById('nasr-app').textContent=error.message;}
