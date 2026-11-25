/* Direct server transport. No private response cache, persistent write queue or offline fallback. */
(() => {
  'use strict';
  let active = null;
  const attempts = new Map();
  // Share only a request that is still in flight; settled responses are never cached.
  const reads = new Map();
  function endpoint(path, api) {
    const url = new URL(api);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + path);
    else url.pathname = url.pathname.replace(/\/?$/, '/') + path;
    return url;
  }
  function failure(message, status = 0) { const error = new Error(message); error.status = status; return error; }
  function connection(connected) { window.dispatchEvent(new CustomEvent('nasr:connection', {detail:{connected}})); }
  async function send(path, data, api, token = '', query = {}, options = {}) {
    if (options.signal?.aborted) throw new DOMException('Cancelled', 'AbortError');
    if (!navigator.onLine) { connection(false); throw failure('اتصال اینترنت قطع است؛ اطلاعات ثبت نشده است.'); }
    const url = endpoint(path, api);
    Object.entries(query).forEach(([k,v]) => { if (v !== '' && v !== undefined && v !== null) url.searchParams.set(k,v); });
    let response;
    const controller = new AbortController(), abort = () => controller.abort();
    options.signal?.addEventListener('abort', abort, {once:true});
    const timer = setTimeout(abort, 25000);
    try {
      if (window.NasrNative?.request) response = await window.NasrNative.request(url.href, data, token, {signal:controller.signal});
      else {
        const r = await fetch(url, {method:data === undefined ? 'GET':'POST', credentials:'omit', cache:'no-store', signal:controller.signal,
          headers:{'Content-Type':'application/json', ...(token ? {Authorization:'Nasr '+token}:{})}, ...(data === undefined ? {}:{body:JSON.stringify(data)})});
        let body;
        try { body = await r.json(); } catch (e) { throw failure('پاسخ معتبر از سامانه دریافت نشد. نتیجهٔ ثبت را بررسی و دوباره تلاش کنید.', r.status || 502); }
        response = {status:r.status, data:body};
      }
    } catch (error) {
      if (options.signal?.aborted) throw new DOMException('Cancelled','AbortError');
      if (error.status) throw error;
      connection(false);
      throw failure(data === undefined ? 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.' : 'تأیید ثبت از سرور دریافت نشد. برای بررسی نتیجه، دوباره همین ثبت را ارسال کنید.');
    } finally { clearTimeout(timer); options.signal?.removeEventListener('abort', abort); }
    connection(true);
    if (response.status < 200 || response.status >= 300) throw failure(response.data?.message || 'عملیات انجام نشد.', response.status);
    return response.data;
  }
  function uuid() { return crypto.randomUUID?.() || [...crypto.getRandomValues(new Uint8Array(24))].map(v=>v.toString(16).padStart(2,'0')).join(''); }
  async function request(path, data, query = {}, options = {}) {
    if (!active) throw failure('ابتدا وارد برنامه شوید.',401);
    const session = active;
    // A read begun before a mutation must never satisfy its follow-up refresh.
    if(data!==undefined)reads.clear();
    const reliable = data !== undefined && (/^grades\/\d+$/.test(path) || path === 'attendance');
    let identity = '', requestId = '';
    if (reliable) {
      identity = path+'|'+JSON.stringify(data);
      requestId = data.client_request_id || attempts.get(identity) || uuid();
      attempts.set(identity, requestId);
      if (attempts.size > 50) attempts.delete(attempts.keys().next().value);
      data = {...data, client_request_id:requestId};
    }
    let result;
    if(data===undefined && !options.signal){
      const identity=path+'|'+JSON.stringify(Object.entries(query).sort(([a],[b])=>a.localeCompare(b)));
      let pending=reads.get(identity);
      if(!pending){
        pending=send(path,data,session.api,session.token,query,options);reads.set(identity,pending);
        const release=()=>{if(reads.get(identity)===pending)reads.delete(identity);};
        pending.then(release,release);
      }
      result=await pending;
    }else {
      try{result=await send(path,data,session.api,session.token,query,options);}
      finally{if(data!==undefined)reads.clear();}
    }
    if (session !== active) throw failure('حساب ورود تغییر کرده است؛ دوباره تلاش کنید.',401);
    if (path === 'bootstrap' && (+result.user_id !== +session.user_id || result.role !== session.role)) throw failure('حساب یا نقش ورود تغییر کرده است؛ دوباره وارد شوید.',401);
    if (reliable) {
      if (result._sync?.request_id !== requestId || result._sync?.status !== 'confirmed') throw failure('تأیید قطعی ثبت دریافت نشد. دوباره همین ثبت را ارسال کنید.');
      attempts.delete(identity);
    }
    return result;
  }
  window.NasrOnline = {endpoint, send, request,
    configure(api, session) { active = {api, token:session.token, user_id:session.user_id, role:session.role}; attempts.clear(); reads.clear(); },
    clear() { active = null; attempts.clear(); reads.clear(); }
  };
})();
