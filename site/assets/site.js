const modal=document.querySelector('#contactModal');const interest=document.querySelector('#interest');function openModal(plan){if(plan&&interest)interest.value=plan;modal?.classList.add('open')}function closeModal(){modal?.classList.remove('open')}document.querySelector('#openContact')?.addEventListener('click',()=>openModal('20-minute demo'));document.querySelector('#closeContact')?.addEventListener('click',closeModal);modal?.addEventListener('click',e=>{if(e.target===modal)closeModal()});document.querySelectorAll('[data-plan]').forEach(el=>el.addEventListener('click',()=>openModal(el.dataset.plan)));document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});document.querySelector('#year').textContent=new Date().getFullYear();document.querySelector('#contactForm')?.addEventListener('submit',e=>{e.preventDefault();const d=new FormData(e.currentTarget);const subject=encodeURIComponent('VetNexo enquiry — '+d.get('interest'));const body=encodeURIComponent(`Name: ${d.get('name')}\nClinic: ${d.get('clinic')}\nEmail: ${d.get('email')}\nInterest: ${d.get('interest')}\nNotes: ${d.get('notes')||'—'}\n\nReplace this prototype workflow with the verified VetNexo business email before launch.`);window.location.href=`mailto:hello@vetnexo.example?subject=${subject}&body=${body}`});
(() => {
  const targets = document.querySelectorAll('.hero > div, .editorial-story > *, .section-head, .feature, .plan, .pilot, .faq details, .demo-welcome, .demo-card, .demo-panel');
  targets.forEach((el, i) => { el.classList.add('reveal'); el.style.transitionDelay = `${Math.min(i % 4, 3) * 70}ms`; });
  if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    targets.forEach(el => el.classList.add('is-visible')); return;
  }
  const observer = new IntersectionObserver(entries => entries.forEach(entry => {
    if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
  }), { threshold: 0.12 });
  targets.forEach(el => observer.observe(el));
})();
