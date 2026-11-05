'use strict';

// Keep the preferred desktop width while smaller screens use the mobile drawer.
(()=>{
 const handle=document.getElementById('sidebar-resizer'),sidebar=document.getElementById('sidebar');
 if(!handle||!sidebar)return;
 const root=document.documentElement,desktop=matchMedia('(min-width:761px)');
 const storageKey='mutiara.sidebarWidth:'+(document.querySelector('meta[name=app-base-path]')?.content??'');
 const defaultWidth=260,minWidth=defaultWidth,maxWidth=400,mobileWidth=240;
 let preferredWidth=defaultWidth,pointerId=null,startX=0,startWidth=0,startPreference=defaultWidth,frame=0;
 try{
  const saved=Number(localStorage.getItem(storageKey));
  const previousDefault=Number(localStorage.getItem(storageKey+':default')??280);
  // Migrate the old default once, while preserving manually chosen widths.
  if(Number.isFinite(saved)&&saved>=minWidth&&saved<=maxWidth)preferredWidth=saved===previousDefault?defaultWidth:Math.round(saved);
  localStorage.setItem(storageKey+':default',String(defaultWidth));
  if(saved===previousDefault)localStorage.setItem(storageKey,String(preferredWidth));
 }catch{}
 const limit=()=>Math.max(minWidth,Math.min(maxWidth,innerWidth-480));
 const clamp=value=>Math.round(Math.max(minWidth,Math.min(limit(),value)));
 const render=()=>{
  frame=0;const width=desktop.matches?clamp(preferredWidth):mobileWidth;
  root.style.setProperty('--sidebar-width',width+'px');handle.hidden=!desktop.matches;
  handle.setAttribute('aria-valuemax',String(limit()));handle.setAttribute('aria-valuenow',String(width));handle.setAttribute('aria-valuetext',width+' piksel');
 };
 const save=()=>{try{localStorage.setItem(storageKey,String(preferredWidth));}catch{}};
 const finish=(cancel=false)=>{
  if(pointerId===null)return;
  const captured=pointerId;pointerId=null;
  if(frame)cancelAnimationFrame(frame);
  if(cancel)preferredWidth=startPreference;
  render();document.body.classList.remove('sidebar-resizing');
  if(handle.hasPointerCapture(captured))handle.releasePointerCapture(captured);
  if(!cancel)save();
 };
 handle.addEventListener('pointerdown',event=>{
  if(!desktop.matches||event.button!==0||!event.isPrimary||pointerId!==null)return;
  event.preventDefault();pointerId=event.pointerId;startX=event.clientX;startWidth=sidebar.getBoundingClientRect().width;startPreference=preferredWidth;
  handle.setPointerCapture(pointerId);handle.focus({preventScroll:true});document.body.classList.add('sidebar-resizing');
 });
 handle.addEventListener('pointermove',event=>{
  if(event.pointerId!==pointerId)return;
  preferredWidth=clamp(startWidth+event.clientX-startX);
  if(!frame)frame=requestAnimationFrame(render);
 });
 handle.addEventListener('pointerup',event=>{if(event.pointerId===pointerId)finish();});
 handle.addEventListener('pointercancel',event=>{if(event.pointerId===pointerId)finish(true);});
 handle.addEventListener('lostpointercapture',()=>finish());
 handle.addEventListener('keydown',event=>{
  if(!desktop.matches)return;
  if(event.key==='Escape'&&pointerId!==null){event.preventDefault();finish(true);return;}
  if(pointerId!==null)return;
  const current=sidebar.getBoundingClientRect().width,step=event.shiftKey?20:10;
  const width=({'ArrowLeft':current-step,'ArrowRight':current+step,'Home':minWidth,'End':limit(),'Enter':defaultWidth})[event.key];
  if(width===undefined)return;
  event.preventDefault();preferredWidth=clamp(width);render();save();
 });
 handle.addEventListener('dblclick',()=>{if(desktop.matches){finish();preferredWidth=defaultWidth;render();save();}});
 window.addEventListener('resize',()=>{finish(true);render();});
 window.addEventListener('blur',()=>finish());
 render();
})();
