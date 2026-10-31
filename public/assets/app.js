'use strict';
const appUrl = path => (document.querySelector('meta[name=app-base-path]')?.content ?? '') + '/' + path.replace(/^\/+/, '');
const rupiah = value => {const s=String(value??'0').split('.')[0];return `${s.startsWith('-')?'-':''}Rp ${s.replace('-','').replace(/\B(?=(\d{3})+(?!\d))/g,'.')}`;};
document.querySelectorAll('[data-sidebar-toggle]').forEach(button=>button.addEventListener('click',()=>{const open=document.body.classList.toggle('sidebar-open');button.setAttribute('aria-expanded',String(open));}));
document.querySelectorAll('[data-sidebar-close]').forEach(button=>button.addEventListener('click',()=>{document.body.classList.remove('sidebar-open');document.querySelector('[data-sidebar-toggle]')?.setAttribute('aria-expanded','false');}));
document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelector('[data-sidebar-close]')?.click();});
document.querySelectorAll('[data-auto-submit]').forEach(input=>input.addEventListener('change',()=>input.form.requestSubmit()));
document.querySelectorAll('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.passwordToggle);input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Lihat':'Sembunyikan';button.setAttribute('aria-label',input.type==='password'?'Tampilkan kata sandi':'Sembunyikan kata sandi');}));
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm))event.preventDefault();}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
document.querySelectorAll('[data-report-range]').forEach(input=>input.addEventListener('change',()=>{document.querySelectorAll('[data-range-field]').forEach(field=>{field.hidden=!input.checked;field.querySelectorAll('input,select').forEach(i=>i.disabled=!input.checked);});}));
// Enhance dashboard selects with the local Bootstrap dropdown; keep native controls without JavaScript.
if(window.bootstrap?.Dropdown){
 document.querySelectorAll('[data-dropdown-select]').forEach(select=>{
  const wrapper=select.closest('.select-dropdown');const toggle=wrapper.querySelector('[data-select-toggle]');const menu=wrapper.querySelector('[data-select-options]');const label=toggle.querySelector('[data-selected-label]');
  const sync=()=>{
   label.textContent=select.selectedOptions[0]?.textContent??'';
   menu.querySelectorAll('[data-select-value]').forEach(button=>{const active=button.dataset.selectValue===select.value;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','true');else button.removeAttribute('aria-current');});
  };
  for(const option of select.options){
   const item=document.createElement('li');const button=document.createElement('button');button.type='button';button.className='dropdown-item';button.dataset.selectValue=option.value;button.textContent=option.textContent;button.disabled=option.disabled;
   button.addEventListener('click',()=>{const changed=select.value!==option.value;select.value=option.value;sync();bootstrap.Dropdown.getOrCreateInstance(toggle).hide();toggle.focus();if(changed)select.dispatchEvent(new Event('change',{bubbles:true}));});
   item.append(button);menu.append(item);
  }
  sync();select.addEventListener('change',sync);select.hidden=true;toggle.hidden=false;
 });
}
const dashData=document.getElementById('dashboard-data');
if(dashData&&window.Chart){
 const data=JSON.parse(dashData.textContent);const font={family:"'Segoe UI',Arial,sans-serif",size:14};
 Chart.defaults.font.family=font.family;Chart.defaults.font.size=font.size;Chart.defaults.color='#9aaf9d';Chart.defaults.plugins.legend.display=false;
 const annual=document.getElementById('annual-chart');
 const annualCtx=annual.getContext('2d');const bars=annualCtx.createLinearGradient(0,0,0,220);bars.addColorStop(0,'#b8deb7');bars.addColorStop(1,'#5a9d79');
 new Chart(annual,{type:'bar',data:{labels:['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'],datasets:[{label:'Penghasilan bersih',data:data.annual.map(v=>v===null?null:Number(v)),backgroundColor:bars,borderRadius:5,borderSkipped:false,maxBarThickness:12}]},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{tooltip:{callbacks:{label:ctx=>rupiah(data.annual[ctx.dataIndex])}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{color:'#82a18c',font}},y:{beginAtZero:true,border:{display:false},grid:{color:'#ffffff0a',drawTicks:false},ticks:{maxTicksLimit:4,color:'#81a58d',padding:9,font,callback:v=>`${v/1000000} jt`}}}}});
 const dep=document.getElementById('department-chart');const ctx=dep.getContext('2d');const fill=ctx.createLinearGradient(0,0,0,300);fill.addColorStop(0,'#36b77a30');fill.addColorStop(1,'#36b77a00');
 new Chart(dep,{type:'line',data:{labels:data.distribution.map(d=>d.name),datasets:[{label:'Penghasilan bruto',data:data.distribution.map(d=>Number(d.gross)),borderColor:'#20a96e',backgroundColor:fill,fill:true,borderWidth:2,pointRadius:3,pointHoverRadius:5,pointBackgroundColor:'#20a96e',cubicInterpolationMode:'monotone',tension:.3},{label:'Potongan',data:data.distribution.map(d=>Number(d.deductions)),borderColor:'#a8bf83',backgroundColor:'#a8bf8310',borderWidth:2,pointRadius:3,pointBackgroundColor:'#a8bf83',cubicInterpolationMode:'monotone',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{tooltip:{callbacks:{label:context=>context.dataset.label+': '+rupiah(data.distribution[context.dataIndex][context.datasetIndex===0?'gross':'deductions'])}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{font,color:'#a3b39d',maxRotation:35,minRotation:0}},y:{beginAtZero:true,border:{display:false},grid:{color:'#edf2e8',drawTicks:false},ticks:{maxTicksLimit:6,padding:10,font,color:'#a6b49d',callback:v=>v===0?'0':`${v/1000000} jt`}}}}});
}
const picker=document.getElementById('employee-picker');
if(picker){
 const search=picker.querySelector('input[type=search]');const results=picker.querySelector('[data-picker-results]');let timeout;let request=0;
 const load=async()=>{const current=++request;results.textContent='Memuat karyawan…';try{const department=document.querySelector('[name=bagian]')?.value??'';const response=await fetch(appUrl('api/karyawan')+'?'+new URLSearchParams({q:search.value,bagian:department}),{headers:{Accept:'application/json'}});const json=await response.json();if(current!==request)return;if(!response.ok)throw new Error(json.error);results.replaceChildren();if(!json.employees.length){results.textContent='Tidak ada karyawan yang sesuai.';return;}for(const employee of json.employees){const button=document.createElement('button');button.type='button';button.className='picker-result';const name=document.createElement('span');name.textContent=employee.nama;const info=document.createElement('small');info.textContent=`${employee.nip} · ${employee.nama_bagian??employee.kd_bagian}`;button.append(name,info);button.addEventListener('click',()=>{document.getElementById('employee-nip').value=employee.nip;document.getElementById('employee-name').value=employee.nama;document.querySelector('[name=bagian]').value=employee.kd_bagian;bootstrap.Modal.getInstance(picker).hide();document.getElementById('employee-nip').dispatchEvent(new Event('change',{bubbles:true}));});results.append(button);}}catch(error){results.textContent=error.message||'Pencarian belum berhasil. Coba kembali.';}};
 picker.addEventListener('shown.bs.modal',()=>{search.focus();load();});search.addEventListener('input',()=>{clearTimeout(timeout);timeout=setTimeout(load,200);});
}
const payrollForm=document.getElementById('payroll-form');
if(payrollForm){
 const message=document.getElementById('calculation-message');const token=document.querySelector('meta[name=csrf-token]').content;let timeout;let controller;let revision=0;
 const calculate=async(useDefaults=false)=>{clearTimeout(timeout);controller?.abort();controller=new AbortController();const thisRevision=++revision;const button=document.getElementById('load-components');if(button)button.disabled=true;try{const body=new FormData(payrollForm);body.set('load_defaults',useDefaults?'1':'0');const response=await fetch(appUrl('api/hitung'),{method:'POST',body,headers:{'X-CSRF-Token':token,Accept:'application/json'},signal:controller.signal});const data=await response.json();if(thisRevision!==revision)return;if(!response.ok)throw new Error(data.error);for(const [field,value]of Object.entries(data.values)){const input=payrollForm.querySelector(`[name="${field}"]`);if(input)input.value=value??'';const output=document.querySelector(`[data-total="${field}"]`);if(output)output.textContent=rupiah(value);}message.hidden=!data.notice;message.textContent=data.notice??'';message.className='alert alert-info';}catch(error){if(error.name!=='AbortError'){message.hidden=false;message.textContent=error.message||'Perhitungan belum berhasil. Input Anda tetap tersimpan di formulir.';message.className='alert alert-warning';}}finally{if(thisRevision===revision&&button)button.disabled=false;}};
 const modeInput=document.getElementById('calculation-mode');
 const derived=['gapok','t_jabatan','t_harian','t_konsumsi','ulembur','uinfal','uovertime','absensi','kelebihan','p_anak','pinjaman_bsm','arisan','pinjaman_lunak','lain1'];
 const updateMode=()=>{for(const field of derived){const input=payrollForm.querySelector(`[name="${field}"]`);if(input)input.readOnly=modeInput.value==='master';}};
 updateMode();modeInput.addEventListener('change',()=>{updateMode();calculate(modeInput.value==='master');});
 payrollForm.addEventListener('input',event=>{if(event.target.matches('[data-payroll-value]')){clearTimeout(timeout);timeout=setTimeout(()=>calculate(false),450);}});
 document.getElementById('load-components')?.addEventListener('click',()=>{modeInput.value='master';updateMode();calculate(true);});
 document.getElementById('employee-nip')?.addEventListener('change',async event=>{const nip=event.target.value;message.hidden=false;message.className='alert alert-info';message.textContent='Memeriksa identitas karyawan…';try{const response=await fetch(appUrl('api/karyawan')+'?'+new URLSearchParams({q:nip}));const result=await response.json();if(event.target.value!==nip)return;if(!response.ok)throw new Error(result.error);const employee=result.employees.find(row=>row.nip===nip);document.getElementById('employee-name').value=employee?.nama??'';if(!employee)throw new Error('NIP tidak ditemukan pada master karyawan.');const department=payrollForm.querySelector('select[name=bagian]');if(department&&!department.disabled)department.value=employee.kd_bagian;message.textContent='Karyawan dipilih. Klik Muat komponen master untuk mengisi komponen awal.';}catch(error){message.className='alert alert-warning';message.textContent=error.message||'Identitas karyawan belum berhasil dimuat.';}});
 payrollForm.addEventListener('submit',()=>{payrollForm.querySelector('[type=submit]').disabled=true;});
}
