/* One-time upgrade bridge: submits old pending writes, never creates new local records. */
(() => {
  'use strict';
  const name = 'nasr-academy-sync-v1';
  async function existingDatabase() {
    if (!window.indexedDB) return null;
    if (indexedDB.databases && !(await indexedDB.databases()).some(db=>db.name===name)) return null;
    return new Promise((resolve,reject)=>{
      const request = indexedDB.open(name);
      let missing = false;
      request.onupgradeneeded=()=>{missing=true;request.transaction.abort();};
      request.onsuccess=()=>resolve(request.result);
      request.onerror=()=>missing?resolve(null):reject(request.error);
      request.onblocked=()=>reject(new Error('پنجره‌های نسخهٔ قبلی برنامه را ببندید و دوباره وارد شوید.'));
    });
  }
  function run(db, store, mode, operation) {
    return new Promise((resolve,reject)=>{
      const tx=db.transaction(store,mode), request=operation(tx.objectStore(store));let result;
      request.onsuccess=()=>{result=request.result;};
      tx.oncomplete=()=>resolve(result);tx.onabort=tx.onerror=()=>reject(tx.error || new Error('خواندن اطلاعات نسخهٔ قبلی انجام نشد.'));
    });
  }
  window.NasrLegacyTransfer = async (api, session, send) => {
    const db=await existingDatabase();if(!db)return {transferred:0};
    let transferred=0;
    try {
      if (!db.objectStoreNames.contains('records') || !db.objectStoreNames.contains('keys')) return {transferred:0};
      const encoder=new TextEncoder(), decoder=new TextDecoder();
      const digest=await crypto.subtle.digest('SHA-256',encoder.encode(api+'|'+session.user_id+'|'+session.role));
      const scope=[...new Uint8Array(digest)].map(v=>v.toString(16).padStart(2,'0')).join('');
      const records=await run(db,'records','readonly',s=>s.getAll());
      // Read caches and discarded history are no longer used. Keep unconfirmed writes intact.
      for(const row of records.filter(r=>r.type!=='write'))await run(db,'records','readwrite',s=>s.delete(row.id));
      const pending=records.filter(r=>r.type==='write' && r.scope===scope).sort((a,b)=>a.created-b.created || a.id.localeCompare(b.id));
      if(pending.length){
        const key=await run(db,'keys','readonly',s=>s.get('account:'+scope));
        if(!key)throw new Error('اطلاعات ثبت‌نشدهٔ نسخهٔ قبلی محفوظ است؛ کلید خواندن آن در دسترس نیست.');
        for(const row of pending){
          const content=JSON.parse(decoder.decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:new Uint8Array(row.sealed.iv)},key.key,new Uint8Array(row.sealed.data))));
          if(!(/^grades\/\d+$/.test(content.path) || content.path==='attendance') || content.body?.client_request_id!==row.id)throw new Error('اطلاعات نسخهٔ قبلی معتبر نیست؛ بدون حذف نگه داشته شد.');
          const response=await send(content.path,content.body);
          if(response._sync?.request_id!==row.id || response._sync?.status!=='confirmed')throw new Error('تأیید انتقال اطلاعات نسخهٔ قبلی دریافت نشد.');
          await run(db,'records','readwrite',s=>s.delete(row.id));transferred++;
        }
      }
      const remaining=await run(db,'records','readonly',s=>s.getAll());
      const scopes=new Set(remaining.filter(r=>r.type==='write').map(r=>'account:'+r.scope));
      for(const key of await run(db,'keys','readonly',s=>s.getAll()))if(!scopes.has(key.id))await run(db,'keys','readwrite',s=>s.delete(key.id));
      return {transferred};
    } finally { db.close(); }
  };
})();
