function openLiveSelector(){
  const url=document.getElementById('list_url')?.value?.trim();
  if(!url){alert('Informe a URL da página/listagem primeiro.');return}
  window.open('selector-live.php?url='+encodeURIComponent(url),'rssmotor_selector','width=1500,height=920,scrollbars=yes,resizable=yes')
}
async function previewFeed(){
  const form=document.getElementById('feedForm');
  const box=document.getElementById('previewBox');
  const content=document.getElementById('previewContent');
  if(!form||!box||!content)return;
  box.classList.remove('hidden');
  content.innerHTML='<p>Testando raspagem...</p>';
  const fd=new FormData(form);
  fd.set('csrf',window.RSSMOTOR_CSRF||fd.get('csrf'));
  try{
    const res=await fetch('api.php?action=preview',{method:'POST',body:fd});
    const data=await res.json();
    if(!data.ok)throw new Error(data.error||'Erro ao testar');
    content.innerHTML='<p><b>'+data.count+' item(ns) encontrados.</b></p>'+data.items.map(i=>'<div class="preview-item"><b>'+esc(i.title||'')+'</b><small>'+esc(i.published_at||'')+'</small><a target="_blank" href="'+esc(i.url||'')+'">'+esc(i.url||'')+'</a><p>'+esc((i.summary||i.content||'').slice(0,280))+'</p>'+(i.image?'<img src="'+esc(i.image)+'">':'')+'</div>').join('');
  }catch(e){content.innerHTML='<div class="alert error">'+esc(e.message)+'</div>'}
}
function esc(v){return String(v).replace(/[&<>"]/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]))}
function refreshSelectorChecklist(){
  document.querySelectorAll('[data-check]').forEach(el=>{const input=document.querySelector('[name="'+el.dataset.check+'"]');el.classList.toggle('ok',!!(input&&input.value.trim()))})
}
document.addEventListener('input',refreshSelectorChecklist);
document.addEventListener('DOMContentLoaded',refreshSelectorChecklist);
