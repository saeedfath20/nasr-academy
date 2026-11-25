(() => {
  'use strict';
  const dialog=document.getElementById('nasr-recovery-dialog');
  if (!dialog) return;
  const opener=document.querySelector('[data-recovery-open]');
  const open=()=>{
    if (typeof dialog.showModal!=='function') { dialog.setAttribute('open',''); return; }
    if (dialog.open) dialog.close();
    dialog.showModal();
    dialog.querySelector('[name=national_id]')?.focus();
  };
  const clearResult=()=>{
    const url=new URL(location.href);
    url.searchParams.delete('nasr_recovery');
    history.replaceState(null,'',url);
  };
  opener?.addEventListener('click',event=>{
    // A completed reset gets a new form on the next request.
    if (!dialog.querySelector('[name=national_id]')) return;
    event.preventDefault(); open();
  });
  dialog.querySelectorAll('[data-recovery-close]').forEach(link=>link.addEventListener('click',event=>{
    if (typeof dialog.close!=='function') return;
    event.preventDefault(); dialog.close(); clearResult(); opener?.focus();
  }));
  dialog.addEventListener('cancel',clearResult);
  dialog.querySelector('form').addEventListener('submit',()=>{
    const submit=dialog.querySelector('button[type=submit]');
    if (submit) { submit.disabled=true; submit.textContent='در حال بازیابی…'; }
  });
  if (dialog.open) open();
})();
