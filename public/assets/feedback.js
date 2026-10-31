'use strict';
document.addEventListener('DOMContentLoaded', function () {
 const dialog=document.getElementById('feedback-modal');
 if(!dialog||!window.bootstrap?.Modal)return;
 const modal=bootstrap.Modal.getOrCreateInstance(dialog,{backdrop:'static'});
 const title=dialog.querySelector('#feedback-title'),message=dialog.querySelector('.feedback-message'),list=dialog.querySelector('[data-feedback-issues]');
 const accept=dialog.querySelector('#feedback-accept'),cancel=dialog.querySelector('#feedback-cancel');
 const queue=[];let active=false;
 dialog.addEventListener('keydown',event=>{
  if(event.key!=='Tab'||!dialog.classList.contains('show'))return;
  const controls=Array.from(dialog.querySelectorAll('button,a[href],input,select,textarea,[tabindex]')).filter(control=>!control.disabled&&!control.closest('[hidden]')&&control.tabIndex>=0&&control.getClientRects().length>0);
  if(!controls.length){event.preventDefault();dialog.focus();return;}
  const first=controls[0],last=controls[controls.length-1],focused=document.activeElement;
  if(event.shiftKey&&(focused===first||!dialog.contains(focused))){event.preventDefault();last.focus();}
  else if(!event.shiftKey&&(focused===last||focused===dialog||!dialog.contains(focused))){event.preventDefault();first.focus();}
 });
 const showFeedback=event=>new Promise(resolve=>{queue.push({event,resolve});runQueue();});
 const runQueue=()=>{
  if(active||!queue.length)return;
  active=true;const item=queue.shift(),event=item.event;const previous=document.activeElement;
  let accepted=false;
  dialog.dataset.severity=event.severity||'info';dialog.setAttribute('role',event.severity==='error'?'alertdialog':'dialog');
  title.textContent=event.title||'Pemberitahuan';message.textContent=event.message||'Periksa rincian berikut.';
  list.replaceChildren();
  for(const issue of event.issues||[]){const li=document.createElement('li');li.textContent=issue.message;list.append(li);}
  list.hidden=!list.children.length;cancel.hidden=!event.confirmation;cancel.textContent=event.cancelLabel||'Kembali Periksa';
  accept.textContent=event.primaryLabel||(event.confirmation?'Lanjutkan Simpan':event.severity==='error'?'Perbaiki data':'Mengerti');
  accept.onclick=()=>{accepted=true;modal.hide();};
  dialog.addEventListener('shown.bs.modal',()=>{(event.confirmation?cancel:accept).focus();},{once:true});
  dialog.addEventListener('hidden.bs.modal',()=>{
   accept.onclick=null;active=false;
   const first=event.severity==='error'?(event.issues||[]).find(issue=>issue.field):null;
   const field=first?document.querySelector('[name="'+CSS.escape(first.field)+'"]'):null;
   const target=field?.hidden?document.getElementById(field.id+'-toggle'):field;
   if(dialog.contains(document.activeElement)||document.activeElement===document.body){
    if(target&&!target.disabled)target.focus();else if(previous?.isConnected)previous.focus();
   }
   item.resolve(accepted);runQueue();
  },{once:true});
  modal.show();
 };
 window.payrollFeedback={show:showFeedback};
 const fail=async(error,fallback)=>{
  const status=error.status;
  return showFeedback({severity:'error',title:status===401||status===419?'Sesi perlu diperbarui':'Data belum dapat diproses',message:error.error||(error instanceof TypeError?fallback:error.message)||fallback,issues:error.issues||[]});
 };
 const readResponse=async response=>{
  try{return await response.json();}catch{throw {status:response.status,error:'Respons server belum dapat dibaca. Input tetap tersedia; periksa status transaksi sebelum mencoba lagi.'};}
 };
 const headers={'Accept':'application/json','X-Requested-With':'XMLHttpRequest'};
 const submitting=new WeakSet();
 document.querySelectorAll('form[method=post],form[method=POST]').forEach(form=>{
  if(form.getAttribute('id')==='payroll-form')return;
  form.addEventListener('submit',async event=>{
   event.preventDefault();if(submitting.has(form))return;submitting.add(form);
   try{
    if(form.dataset.confirm&&!await showFeedback({severity:'warning',title:'Konfirmasi penghapusan',message:form.dataset.confirm,confirmation:true,primaryLabel:'Ya, hapus',cancelLabel:'Batal'}))return;
    const body=new FormData(form);form.inert=true;
    const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,headers});const data=await readResponse(response);
    if(!response.ok||data.error)throw {...data,status:response.status};
    if(data.redirect){location.assign(data.redirect);return;}
    await showFeedback({severity:'success',title:'Berhasil',message:data.message||'Perubahan berhasil disimpan.'});
   }catch(error){await fail(error,'Penyimpanan belum dapat dipastikan. Input tetap tersedia; periksa data sebelum mencoba lagi.');}
   finally{form.inert=false;submitting.delete(form);}
  });
 });
 let invalidTimer,invalidIssues=[];
 document.addEventListener('invalid',event=>{
  event.preventDefault();const input=event.target;
  const label=(input.labels?.[0]?.textContent||input.name||'Isian').replace(/\*/g,'').trim();
  invalidIssues.push({code:'client_validation',severity:'error',field:input.name,message:input.validity.valueMissing?label+' wajib diisi.':input.validationMessage||label+' belum valid.'});
  clearTimeout(invalidTimer);invalidTimer=setTimeout(()=>{const issues=invalidIssues;invalidIssues=[];showFeedback({severity:'error',title:'Periksa isian formulir',message:'Lengkapi atau perbaiki isian sebelum melanjutkan.',issues});},0);
 },true);
 const form=document.getElementById('payroll-form');
 if(form){
  const mode=form.querySelector('[name=calculation_mode]'),tokenInput=form.querySelector('[name=confirmation_token]'),acceptedInput=form.querySelector('[name=warnings_accepted]');
  const status=document.getElementById('calculation-status'),inline=document.getElementById('calculation-message'),loadButton=document.getElementById('load-components'),saveButton=form.querySelector('[type=submit]');
  const derived=['gapok','t_jabatan','t_harian','t_konsumsi','ulembur','uinfal','uovertime','absensi','kelebihan','p_anak','pinjaman_bsm','arisan','pinjaman_lunak','plain'];
  let timer,abortController,revision=0,busy=false,lastMode=mode.value;
  const sourceFields=[['karyawan','t_harian','Tarif harian karyawan'],['karyawan','t_lembur','Tarif lembur karyawan'],['tunj_konsumsi','jumlah','Tarif konsumsi bagian'],['tunj_infal','jumlah','Tarif infal bagian'],['tunj_overtime','jumlah','Tarif overtime bagian']];
  const updateMode=()=>{for(const field of derived){const input=form.querySelector('[name="'+field+'"]');if(input)input.readOnly=mode.value==='master';}form.querySelector('[name=lain1]').readOnly=false;};
  const invalidate=()=>{revision++;abortController?.abort();tokenInput.value='';acceptedInput.value='0';status.textContent='Ada perubahan; ringkasan akan diperiksa kembali.';};
  const apply=data=>{
   for(const [field,value] of Object.entries(data.values||{})){
    const input=form.querySelector('[name="'+field+'"]');
    if(input){setNumberInputValue(input,value);syncDropdown(input);}
    const output=document.querySelector('[data-total="'+field+'"]');if(output)output.textContent=rupiah(value);
   }
   tokenInput.value=data.confirmation_token||'';acceptedInput.value=data.acknowledged?'1':'0';
   status.textContent='Komponen dan total sudah diperiksa server.';
   const notices=(data.issues||[]).map(issue=>issue.message);
   inline.hidden=!notices.length&&!data.notice;inline.textContent=notices.join(' ')||data.notice||'';inline.className='alert alert-'+(notices.length?'warning':'info');
   const sources=document.getElementById('rate-sources');
   if(sources&&data.snapshot?.mode==='master'){
    sources.replaceChildren();
    for(const [table,field,label] of sourceFields){const row=document.createElement('div');const value=data.snapshot.sources?.[table]?.[field];row.textContent=label+': '+(value===null||value===undefined?'Belum diatur':rupiah(value));sources.append(row);}
   }
  };
  const calculate=async options=>{
   options=options||{};clearTimeout(timer);abortController?.abort();abortController=new AbortController();const current=++revision;
   loadButton.disabled=true;
   try{
    const body=new FormData(form);body.set('load_defaults',mode.value==='master'?'1':'0');
    const response=await fetch(appUrl('api/hitung'),{method:'POST',body,headers,signal:abortController.signal});const data=await readResponse(response);
    if(current!==revision)return null;
    if(!response.ok)throw {...data,status:response.status};
    if(options.apply!==false)apply(data);return data;
   }catch(error){
    if(error.name==='AbortError')return null;
    inline.hidden=false;inline.className='alert alert-warning';inline.textContent=error.error||error.message||'Perhitungan belum berhasil. Input tetap tersedia.';
    status.textContent='Ringkasan belum valid; belum ada data yang disimpan.';
    if(options.explicit)await fail(error,'Perhitungan belum berhasil.');return null;
   }finally{if(current===revision)loadButton.disabled=false;}
  };
  const warning=data=>({severity:'warning',title:'Periksa sebelum menyimpan',message:'Bruto '+rupiah(data.values.tpenghasilan)+', potongan '+rupiah(data.values.tpotongan)+', bersih '+rupiah(data.values.tbersih)+'. Periksa kondisi berikut sebelum melanjutkan.',issues:data.issues||[],confirmation:true});
  const loadMaster=async()=>{
   const previousMode=lastMode,previousToken=tokenInput.value,previousAccepted=acceptedInput.value;
   mode.value='master';syncDropdown(mode);updateMode();
   const data=await calculate({explicit:true,apply:false});
   if(!data){mode.value=previousMode;syncDropdown(mode);updateMode();return;}
   if(data.issues?.length&&!data.acknowledged){
    const accepted=await showFeedback({...warning(data),primaryLabel:form.dataset.recordId!=='0'?'Hitung ulang':'Gunakan hasil'});
    if(!accepted){mode.value=previousMode;tokenInput.value=previousToken;acceptedInput.value=previousAccepted;syncDropdown(mode);updateMode();return;}
    apply(data);acceptedInput.value='1';
   }else apply(data);
   lastMode=mode.value;
  };
  updateMode();
  mode.addEventListener('change',async()=>{if(mode.value==='master'){await loadMaster();return;}lastMode=mode.value;invalidate();updateMode();await calculate();});
  loadButton.addEventListener('click',loadMaster);
  form.addEventListener('input',event=>{
   if(!event.target.matches('[data-payroll-value],[name=bulan],[name=tahun],[name=nip],[name=bagian]'))return;
   invalidate();clearTimeout(timer);timer=setTimeout(()=>calculate(),450);
  });
  form.querySelector('[name=nip]')?.addEventListener('change',async event=>{
   invalidate();const nip=event.target.value;
   try{
    const response=await fetch(appUrl('api/karyawan')+'?'+new URLSearchParams({q:nip}),{headers:{Accept:'application/json'}});const data=await readResponse(response);
    if(!response.ok)throw {...data,status:response.status};
    if(event.target.value!==nip)return;
    const employee=data.employees.find(row=>row.nip===nip);document.getElementById('employee-name').value=employee?.nama||'';
    if(employee){const department=form.querySelector('[name=bagian]');if(!department.disabled){department.value=employee.kd_bagian;syncDropdown(department);}}
   }catch(error){inline.hidden=false;inline.textContent=error.error||'Identitas karyawan belum dapat diperiksa.';inline.className='alert alert-warning';}
  });
  form.addEventListener('submit',async event=>{
   event.preventDefault();if(busy)return;busy=true;saveButton.disabled=true;
   try{
    let calculation=await calculate({explicit:true});if(!calculation)return;
    if(calculation.issues?.length&&!calculation.acknowledged){if(!await showFeedback(warning(calculation)))return;acceptedInput.value='1';}
    while(true){
     const body=new FormData(form);form.inert=true;
     let response,data;
     try{response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,headers});data=await readResponse(response);}
     finally{form.inert=false;}
     if(response.ok&&data.saved){location.assign(data.redirect);return;}
     if(data.requires_confirmation){
      calculation=data.calculation||data;apply(calculation);
      if(!await showFeedback(warning(calculation)))return;
      acceptedInput.value='1';continue;
     }
     throw {...data,status:response.status};
    }
   }catch(error){await fail(error,'Penyimpanan belum dapat dipastikan. Input tetap tersedia; periksa daftar transaksi sebelum mencoba lagi.');}
   finally{busy=false;saveButton.disabled=false;form.inert=false;}
  });
 }
 const events=document.getElementById('feedback-events');
 if(events){try{for(const event of JSON.parse(events.textContent))showFeedback(event).then(accepted=>{if(accepted&&event.confirmation&&form){form.querySelector('[name=warnings_accepted]').value='1';form.requestSubmit();}});}catch{}}
});
