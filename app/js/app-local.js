(()=>{if(window.autoVetLocal)return;window.autoVetLocal=true;
const token=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
const local=u=>new URL(u||location.href,location.href).origin===location.origin;
function secure(f){if(f.method.toLowerCase()!=='post'||!local(f.action))return;let i=f.querySelector('input[name="csrf_token"]');if(!i){i=document.createElement('input');i.type='hidden';i.name='csrf_token';f.append(i)}i.value=token()}
const submit=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){secure(this);return submit.call(this)};
document.addEventListener('submit',e=>{if(e.target instanceof HTMLFormElement)secure(e.target)},true);
const fetch0=window.fetch;window.fetch=function(input,options={}){const u=input instanceof Request?input.url:input;if(local(u)){const h=new Headers(options.headers||(input instanceof Request?input.headers:{}));h.set('X-CSRF-Token',token());options={...options,headers:h}}return fetch0.call(this,input,options)};
const open=XMLHttpRequest.prototype.open,send=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u,...a){this.avLocal=local(u);return open.call(this,m,u,...a)};XMLHttpRequest.prototype.send=function(...a){if(this.avLocal)this.setRequestHeader('X-CSRF-Token',token());return send.apply(this,a)};
document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('form').forEach(secure);document.querySelectorAll('table').forEach(t=>{if(!t.closest('.table-responsive,.table-container,.responsive-table,.av-table-scroll')){const w=document.createElement('div');w.className='av-table-scroll';t.before(w);w.append(t)}})});
})();
