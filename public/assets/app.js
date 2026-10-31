'use strict';
const appUrl = path => (document.querySelector('meta[name=app-base-path]')?.content ?? '') + '/' + path.replace(/^\/+/, '');
const rupiah = value => {const s=String(value??'0').split('.')[0];return `${s.startsWith('-')?'-':''}Rp ${s.replace('-','').replace(/\B(?=(\d{3})+(?!\d))/g,'.')}`;};
const numberInputs=new Map();
const invalidNumberPaste=new WeakSet();
const groupInteger=value=>value.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
const inputInteger=(input,value)=>numberInputs.get(input)?.test(value)?value.replace(/\./g,''):null;
const setNumberInputValue=(input,value)=>{const text=String(value??'');const raw=inputInteger(input,text);const formatted=raw===null?text:groupInteger(raw);if(input.value!==formatted)input.value=formatted;if(numberInputs.has(input)&&!invalidNumberPaste.has(input)&&(text===''||raw!==null))input.setCustomValidity('');};
// Format integers as strings so 18-digit amounts never pass through floating point.
document.querySelectorAll('input[inputmode="numeric"]:not([data-year-control])').forEach(input=>{
 const signed=input.pattern.startsWith('-?');const sign=signed?'-?':'';
 const pattern=sign+'(?:[0-9]{1,18}|[0-9]{1,3}(?:\\.[0-9]{3}){1,5})';
 numberInputs.set(input,new RegExp('^'+pattern+'$'));input.pattern=pattern;input.maxLength=24;input.dataset.numberFormat='';
 const error='Gunakan bilangan bulat dengan maksimal 18 digit. Contoh: 100.000.';
 const position=(value,count)=>{let seen=0;for(let i=0;i<value.length;i++){if(value[i]!=='.')seen++;if(seen===count)return i+1;}return count===0?0:value.length;};
 const format=(start,end=start)=>{const raw=input.value.replace(/\./g,'');const text=groupInteger(raw);input.value=text;if(start!==undefined)input.setSelectionRange(position(text,start),position(text,end));};
 const validate=()=>input.setCustomValidity(!invalidNumberPaste.has(input)&&(input.value===''||inputInteger(input,input.value)!==null)?'':error);
 input.addEventListener('input',event=>{
  if(event.isComposing)return;
  invalidNumberPaste.delete(input);
  const raw=input.value.replace(/\./g,'');const editing=event.inputType?.startsWith('delete')||(/^insert(?:Text|ReplacementText)$/.test(event.inputType??'')&&/^-?[0-9]*$/.test(event.data??''));
  if(inputInteger(input,input.value)!==null||(editing&&new RegExp('^'+sign+'[0-9]{1,18}$').test(raw))){
   const start=input.value.slice(0,input.selectionStart??input.value.length).replace(/\./g,'').length;const end=input.value.slice(0,input.selectionEnd??input.value.length).replace(/\./g,'').length;format(start,end);
  }
  validate();
 });
 input.addEventListener('paste',event=>{
  if(input.readOnly||input.disabled)return;
  const text=event.clipboardData?.getData('text').trim();if(text===undefined)return;
  event.preventDefault();const pasted=inputInteger(input,text);
  const before=input.value.slice(0,input.selectionStart??0).replace(/\./g,'');const after=input.value.slice(input.selectionEnd??input.value.length).replace(/\./g,'');const combined=before+(pasted??'')+after;
  if(pasted===null||!new RegExp('^'+sign+'[0-9]{1,18}$').test(combined)){invalidNumberPaste.add(input);input.setCustomValidity(error);input.reportValidity();return;}
  input.value=combined;format(before.length+pasted.length);input.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertFromPaste'}));
 });
 input.addEventListener('beforeinput',event=>{
  if(input.readOnly||input.disabled)return;
  if(input.selectionStart!==input.selectionEnd)return;const caret=input.selectionStart;
  const backward=event.inputType==='deleteContentBackward'&&input.value[caret-1]==='.';const forward=event.inputType==='deleteContentForward'&&input.value[caret]==='.';
  if(!backward&&!forward)return;
  event.preventDefault();const before=input.value.slice(0,caret).replace(/\./g,'');const after=input.value.slice(caret).replace(/\./g,'');input.value=backward?before.slice(0,-1)+after:before+after.slice(1);format(backward?before.length-1:before.length);input.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:event.inputType}));
 });
 input.addEventListener('blur',()=>{setNumberInputValue(input,input.value);validate();});
 input.addEventListener('compositionend',()=>input.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText'})));
 input.form?.addEventListener('reset',()=>setTimeout(()=>{invalidNumberPaste.delete(input);setNumberInputValue(input,input.value);validate();},0));
 setNumberInputValue(input,input.value);validate();
});
// Year controls remain editable and never use monetary grouping separators.
document.querySelectorAll('[data-year-control]').forEach(input=>{
 const validate=()=>{const value=input.value;input.setCustomValidity(value===''||(/^\d{4}$/.test(value)&&Number(value)>=1900&&Number(value)<=9999)?'':'Tahun harus berupa 4 digit antara 1900 dan 9999.');};
 input.addEventListener('input',validate);input.addEventListener('blur',validate);
 input.form?.addEventListener('reset',()=>setTimeout(validate,0));validate();
});
// Keep the visible separators while both native submissions and AJAX use raw integers.
new Set(Array.from(numberInputs.keys(),input=>input.form).filter(Boolean)).forEach(form=>form.addEventListener('formdata',event=>{
 for(const input of numberInputs.keys()){if(input.form!==form||!event.formData.has(input.name))continue;const raw=inputInteger(input,input.value);if(raw!==null)event.formData.set(input.name,raw);}
}));
document.querySelectorAll('[data-sidebar-toggle]').forEach(button=>button.addEventListener('click',()=>{const open=document.body.classList.toggle('sidebar-open');button.setAttribute('aria-expanded',String(open));}));
document.querySelectorAll('[data-sidebar-close]').forEach(button=>button.addEventListener('click',()=>{document.body.classList.remove('sidebar-open');document.querySelector('[data-sidebar-toggle]')?.setAttribute('aria-expanded','false');}));
document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelector('[data-sidebar-close]')?.click();});
document.querySelectorAll('[data-auto-submit]').forEach(input=>input.addEventListener('change',()=>input.form.requestSubmit()));
document.querySelectorAll('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.passwordToggle);input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Lihat':'Sembunyikan';button.setAttribute('aria-label',input.type==='password'?'Tampilkan kata sandi':'Sembunyikan kata sandi');}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
window.addEventListener('beforeprint',()=>{
 const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Jakarta',year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date()).map(part=>[part.type,part.value]));
 const timestamp=`${parts.day}-${parts.month}-${parts.year}, ${parts.hour}:${parts.minute}:${parts.second} WIB`;
 document.querySelectorAll('[data-print-timestamp]').forEach(element=>{element.textContent=timestamp;});
});
document.querySelectorAll('[data-report-range]').forEach(input=>input.addEventListener('change',()=>{document.querySelectorAll('[data-range-field]').forEach(field=>{field.hidden=!input.checked;field.querySelectorAll('input,select').forEach(i=>i.disabled=!input.checked);});}));
const dropdownSync=new WeakMap();
const syncDropdown=control=>dropdownSync.get(control)?.();
// All choices share the dashboard dropdown; original controls still submit and validate.
if(window.bootstrap?.Dropdown){
 document.querySelectorAll('select:not([multiple]),input[list]').forEach((control,index)=>{
  const editable=control.matches('input');const options=Array.from(editable?control.list?.options??[]:control.options);
  if(!editable&&!options.length)return;
  control.id||=`dropdown-${index}`;
  const fieldLabel=(control.labels?.[0]?.textContent??control.getAttribute('aria-label')??control.name).replace(/\*/g,'').trim();
  let wrapper=control.closest('.select-dropdown');
  if(!wrapper){wrapper=document.createElement('div');wrapper.className='dropdown select-dropdown';control.before(wrapper);wrapper.append(control);}
  let toggle=wrapper.querySelector('[data-select-toggle]');
  if(!toggle){
   toggle=document.createElement('button');toggle.type='button';toggle.className='btn btn-light select-dropdown-toggle';toggle.id=control.id+'-toggle';toggle.dataset.selectToggle='';toggle.dataset.bsToggle='dropdown';toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-haspopup','true');toggle.setAttribute('aria-label','Pilih '+fieldLabel.toLowerCase());
   if(!editable){const text=document.createElement('span');text.dataset.selectedLabel='';toggle.append(text);}
   const caret=document.createElementNS('http://www.w3.org/2000/svg','svg');
   for(const [key,value]of Object.entries({class:'icon',width:'14',height:'14',viewBox:'0 0 24 24',fill:'none',stroke:'currentColor','stroke-width':'1.7','stroke-linecap':'round','stroke-linejoin':'round','aria-hidden':'true'}))caret.setAttribute(key,value);
   const path=document.createElementNS(caret.namespaceURI,'path');path.setAttribute('d','m6 9 6 6 6-6');caret.append(path);toggle.append(caret);wrapper.append(toggle);
  }
  let menu=wrapper.querySelector('[data-select-options]');
  if(!menu){menu=document.createElement('ul');menu.className='dropdown-menu';menu.dataset.selectOptions='';menu.setAttribute('aria-labelledby',toggle.id);wrapper.append(menu);}
  menu.replaceChildren();menu.id=control.id+'-options';toggle.setAttribute('aria-controls',menu.id);
  const month=!editable&&options.length===12&&options.every((option,i)=>Number(option.value)===i+1);
  if(month)menu.classList.add('period-month-menu');
  const label=toggle.querySelector('[data-selected-label]');
  if(label){label.id=control.id+'-selected';toggle.setAttribute('aria-describedby',label.id);}
  const dropdown=bootstrap.Dropdown.getOrCreateInstance(toggle,{reference:editable?wrapper:'toggle',popperConfig:config=>({...config,strategy:'fixed',modifiers:[...config.modifiers,{name:'preventOverflow',options:{boundary:'viewport',padding:18}}]})});
  const entries=[];
  const sync=()=>{
   if(label)label.textContent=control.selectedOptions[0]?.textContent??'';
   toggle.disabled=control.disabled||Boolean(control.readOnly);
   for(const {button,option}of entries){const active=option.value===control.value;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','true');else button.removeAttribute('aria-current');}
   if(control.disabled||control.validity.valid){wrapper.classList.remove('is-invalid');toggle.removeAttribute('aria-invalid');const feedback=wrapper.querySelector('.invalid-feedback');if(feedback){feedback.hidden=true;feedback.textContent='';}if(label)toggle.setAttribute('aria-describedby',label.id);else toggle.removeAttribute('aria-describedby');}
  };
  let search;
  if(!month&&!editable&&(options.length>8||control.matches('[name="nip"],[name="bagian"],[name="kd_bagian"]'))){
   const row=document.createElement('li');row.className='select-dropdown-search';search=document.createElement('input');search.type='search';search.className='form-control';search.placeholder='Cari pilihan…';search.setAttribute('aria-label','Cari '+fieldLabel.toLowerCase());row.append(search);menu.append(row);
  }
  let skipFocusOpen=false;
  const focusControl=()=>{skipFocusOpen=true;(editable?control:toggle).focus();skipFocusOpen=false;};
  const choose=option=>{
   const changed=control.value!==option.value;control.value=option.value;sync();dropdown.hide();focusControl();
   if(changed){control.dispatchEvent(new Event('input',{bubbles:true}));control.dispatchEvent(new Event('change',{bubbles:true}));}
  };
  for(const option of options){
   const item=document.createElement('li');const button=document.createElement('button');button.type='button';button.className='dropdown-item';button.dataset.selectValue=option.value;button.textContent=control.hasAttribute('data-year-control')?option.value:(editable?option.value+' · '+(option.label||option.textContent):option.textContent);button.disabled=option.disabled||Boolean(option.parentElement.disabled);
   button.addEventListener('click',()=>choose(option));item.append(button);menu.append(item);entries.push({item,button,option});
  }
  const empty=document.createElement('li');empty.className='select-dropdown-empty';empty.textContent='Tidak ada pilihan yang sesuai.';empty.hidden=true;menu.append(empty);
  const filter=query=>{const text=query.toLocaleLowerCase('id').trim();let count=0;for(const {item,button}of entries){item.hidden=!button.textContent.toLocaleLowerCase('id').includes(text);if(!item.hidden)count++;}empty.hidden=count>0;dropdown.update();};
  const focusOption=event=>{if(event.key==='ArrowDown'&&!control.disabled&&!control.readOnly){event.preventDefault();event.stopPropagation();dropdown.show();entries.find(entry=>!entry.item.hidden&&!entry.button.disabled)?.button.focus();}};
  if(search){search.addEventListener('input',()=>filter(search.value));search.addEventListener('keydown',focusOption);search.addEventListener('click',event=>event.stopPropagation());}
  toggle.addEventListener('show.bs.dropdown',()=>{
   document.querySelectorAll('[data-select-toggle][aria-expanded="true"]').forEach(other=>{if(other!==toggle)bootstrap.Dropdown.getInstance(other)?.hide();});
   menu.style.width=Math.min(Math.max(wrapper.getBoundingClientRect().width,month?280:240),innerWidth-36)+'px';
   if(search)search.value='';
   filter('');
  });
  if(editable){
   wrapper.classList.add('select-dropdown-editable');toggle.classList.add('select-dropdown-input-toggle');control.removeAttribute('list');if(!control.hasAttribute('data-year-control'))control.removeAttribute('maxlength');control.setAttribute('autocomplete','off');control.setAttribute('aria-controls',menu.id);control.setAttribute('aria-haspopup','true');
   control.addEventListener('focus',()=>{if(!skipFocusOpen&&!control.disabled&&!control.readOnly)dropdown.show();});
   control.addEventListener('click',event=>{event.stopPropagation();if(!control.disabled&&!control.readOnly)dropdown.show();});
   control.addEventListener('input',event=>{if(event.isTrusted&&!control.readOnly&&!control.disabled){dropdown.show();filter(control.value);}});
   control.addEventListener('keydown',event=>{focusOption(event);if(event.key==='Escape'){event.preventDefault();dropdown.hide();}});
   toggle.addEventListener('hidden.bs.dropdown',()=>{if(document.activeElement===toggle)focusControl();});
  }else{
   control.addEventListener('invalid',event=>{
    event.preventDefault();wrapper.classList.add('is-invalid');toggle.setAttribute('aria-invalid','true');
    let feedback=wrapper.querySelector('.invalid-feedback');if(!feedback){feedback=document.createElement('div');feedback.className='invalid-feedback';feedback.id=control.id+'-error';wrapper.append(feedback);}
    feedback.textContent='Pilih '+fieldLabel.toLowerCase()+' terlebih dahulu.';feedback.hidden=false;toggle.setAttribute('aria-describedby',[label?.id,feedback.id].filter(Boolean).join(' '));
    if(!control.form?.querySelector('[data-select-toggle][aria-invalid="true"]:focus')){toggle.focus();dropdown.show();}
   });
   control.labels?.forEach(field=>field.addEventListener('click',event=>{event.preventDefault();toggle.focus();}));
   control.hidden=true;
  }
  control.addEventListener('change',sync);control.form?.addEventListener('reset',()=>setTimeout(sync,0));
  new MutationObserver(sync).observe(control,{attributes:true,attributeFilter:['disabled','required','readonly']});
  dropdownSync.set(control,sync);sync();toggle.hidden=false;
 });
}
const dashData=document.getElementById('dashboard-data');
if(dashData&&window.Chart){
 const data=JSON.parse(dashData.textContent);const font={family:"'Segoe UI',Arial,sans-serif",size:14};
 Chart.defaults.font.family=font.family;Chart.defaults.font.size=font.size;Chart.defaults.color='#466253';Chart.defaults.plugins.legend.display=false;
 const annual=document.getElementById('annual-chart');
 const annualCtx=annual.getContext('2d');const bars=annualCtx.createLinearGradient(0,0,0,220);bars.addColorStop(0,'#b8deb7');bars.addColorStop(1,'#5a9d79');
 new Chart(annual,{type:'bar',data:{labels:['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'],datasets:[{label:'Penghasilan bersih',data:data.annual.map(v=>v===null?null:Number(v)),backgroundColor:bars,borderRadius:5,borderSkipped:false,maxBarThickness:12}]},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{tooltip:{callbacks:{label:ctx=>rupiah(data.annual[ctx.dataIndex])}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{color:'#c1dcc9',font}},y:{beginAtZero:true,border:{display:false},grid:{color:'#ffffff24',drawTicks:false},ticks:{maxTicksLimit:4,color:'#c1dcc9',padding:9,font,callback:v=>`${v/1000000} jt`}}}}});
 const dep=document.getElementById('department-chart');const ctx=dep.getContext('2d');const fill=ctx.createLinearGradient(0,0,0,300);fill.addColorStop(0,'#36b77a30');fill.addColorStop(1,'#36b77a00');
 new Chart(dep,{type:'line',data:{labels:data.distribution.map(d=>d.name),datasets:[{label:'Penghasilan bruto',data:data.distribution.map(d=>Number(d.gross)),borderColor:'#0b6b43',backgroundColor:fill,fill:true,borderWidth:2,pointRadius:3,pointHoverRadius:5,pointBackgroundColor:'#0b6b43',cubicInterpolationMode:'monotone',tension:.3},{label:'Potongan',data:data.distribution.map(d=>Number(d.deductions)),borderColor:'#687d3f',backgroundColor:'#a8bf8310',borderWidth:2,pointRadius:3,pointBackgroundColor:'#687d3f',cubicInterpolationMode:'monotone',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{tooltip:{callbacks:{label:context=>context.dataset.label+': '+rupiah(data.distribution[context.dataIndex][context.datasetIndex===0?'gross':'deductions'])}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{font,color:'#466253',maxRotation:35,minRotation:0}},y:{beginAtZero:true,border:{display:false},grid:{color:'#dce6de',drawTicks:false},ticks:{maxTicksLimit:6,padding:10,font,color:'#466253',callback:v=>v===0?'0':`${v/1000000} jt`}}}}});
}
const picker=document.getElementById('employee-picker');
if(picker){
 const search=picker.querySelector('input[type=search]');const results=picker.querySelector('[data-picker-results]');let timeout;let request=0;
 const load=async()=>{const current=++request;results.textContent='Memuat karyawan…';try{const department=document.querySelector('[name=bagian]')?.value??'';const response=await fetch(appUrl('api/karyawan')+'?'+new URLSearchParams({q:search.value,bagian:department}),{headers:{Accept:'application/json'}});const json=await response.json();if(current!==request)return;if(!response.ok)throw new Error(json.error);results.replaceChildren();if(!json.employees.length){results.textContent='Tidak ada karyawan yang sesuai.';return;}for(const employee of json.employees){const button=document.createElement('button');button.type='button';button.className='picker-result';const name=document.createElement('span');name.textContent=employee.nama;const info=document.createElement('small');info.textContent=`${employee.nip} · ${employee.nama_bagian??employee.kd_bagian}`;button.append(name,info);button.addEventListener('click',()=>{document.getElementById('employee-nip').value=employee.nip;document.getElementById('employee-name').value=employee.nama;document.querySelector('[name=bagian]').value=employee.kd_bagian;syncDropdown(document.querySelector('[name=bagian]'));bootstrap.Modal.getInstance(picker).hide();document.getElementById('employee-nip').dispatchEvent(new Event('change',{bubbles:true}));});results.append(button);}}catch(error){results.textContent=error.message||'Pencarian belum berhasil. Coba kembali.';}};
 picker.addEventListener('shown.bs.modal',()=>{search.focus();load();});search.addEventListener('input',()=>{clearTimeout(timeout);timeout=setTimeout(load,200);});
}
