/* Network-only PWA worker: no response cache, database or deferred write queue. */
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('fetch',event=>{
  const url=new URL(event.request.url);
  if(event.request.method!=='GET' || event.request.mode!=='navigate' || url.origin!==self.location.origin || url.searchParams.get('nasr_pwa')!=='app')return;
  event.respondWith(fetch(event.request,{cache:'no-store'}).catch(()=>new Response('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>آموزشگاه نصر</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f6fc;color:#303958;font-family:Tahoma,sans-serif}main{text-align:center;padding:28px}h1{font-size:21px}p{line-height:1.9;color:#737b92}button{padding:13px 24px;border:0;border-radius:14px;background:#3e4ed7;color:white;font:inherit}</style><main><h1>اتصال اینترنت برقرار نیست</h1><p>برای ورود به سامانه به اینترنت متصل شوید.</p><button onclick="location.reload()">تلاش دوباره</button></main></html>',{status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}})));
});
