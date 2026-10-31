'use strict';
const {expect}=require('@playwright/test');
const fs=require('node:fs');
const path=require('node:path');

// Runs only inside the temporary synthetic print database owned by print-layout.js.
module.exports=async function verifyLayout(page,base,fixture,output){
 const destination=path.join(output,'../layout');fs.mkdirSync(destination,{recursive:true});
 let writes=0;const record=request=>{if(request.method()==='POST'&&!new URL(request.url()).pathname.endsWith('/api/hitung'))writes++;};page.on('request',record);
 const noOverflow=async()=>expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 const closeFeedback=async()=>{if(await page.locator('#feedback-modal').isVisible()){await page.locator('#feedback-accept').click();await expect(page.locator('#feedback-modal')).toBeHidden();}};
 const choose=async(id,value)=>{await page.locator('#'+id+'-toggle').click();await page.locator('#'+id+'-options [data-select-value="'+value+'"]').click();};
 const checkYear=async(id)=>{
  const input=page.locator('#'+id),initialUrl=page.url();
  await expect(input).toHaveAttribute('type','number');await expect(input).toHaveAttribute('min','1900');await expect(input).toHaveAttribute('max','9999');await expect(input).toHaveAttribute('step','1');
  await expect(page.locator('#'+id+'-toggle')).toHaveCount(0);await expect(page.locator('#'+id+'-options')).toHaveCount(0);await expect(input).not.toHaveAttribute('list');
  for(const value of ['2027','2030','1900','9999','1988']){await input.fill(value);await expect(input).toHaveValue(value);expect(await input.evaluate(element=>element.validity.valid)).toBe(true);}
  for(const value of ['1899','10000','2026.5','']){await input.fill(value);expect(await input.evaluate(element=>element.validity.valid)).toBe(false);await expect(input).toHaveValue(value);}
  await input.fill('2026');await input.press('ArrowUp');await expect(input).toHaveValue('2027');await input.press('ArrowDown');await expect(input).toHaveValue('2026');
  const bounds=await input.boundingBox(),padding=await input.evaluate(element=>parseFloat(getComputedStyle(element).paddingRight));
  expect(await input.evaluate(element=>getComputedStyle(element,'::-webkit-inner-spin-button').opacity)).toBe('1');
  await page.mouse.click(bounds.x+bounds.width-padding-5,bounds.y+bounds.height/2-4);await expect(input).toHaveValue('2027');
  await page.mouse.click(bounds.x+bounds.width-padding-5,bounds.y+bounds.height/2+4);await expect(input).toHaveValue('2026');
  await input.fill('1900');await input.press('ArrowDown');await expect(input).toHaveValue('1900');await input.fill('9999');await input.press('ArrowUp');await expect(input).toHaveValue('9999');
  expect(page.url()).toBe(initialUrl);await input.fill('2026');
 };
 const reset=async(type)=>{
  await page.locator('.report-actions').getByRole('link',{name:'Reset',exact:true}).click();
  await expect(page).toHaveURL(base+'/laporan?type='+type);
  await expect(page.locator('.report-surface')).toHaveCount(0);
  await expect(page.locator('.alert-danger')).toHaveCount(0);
  await expect(page.locator('#report-scope')).toHaveValue('semua');
  if(await page.locator('#report-employee').count())await expect(page.locator('#report-employee')).toHaveValue('');
  if(await page.locator('#report-department').count())await expect(page.locator('#report-department')).toHaveValue('');
  if(await page.locator('#report-detail').count())await expect(page.locator('#report-detail')).toHaveValue('0');
  if(await page.locator('#report-year').count())await expect(page.locator('#report-year')).toHaveValue('2026');
  if(await page.locator('#dari-year').count()){
   await expect(page.locator('#dari-year')).toHaveValue('2026');await expect(page.locator('#dari-month')).toHaveValue('1');
   await expect(page.locator('#sampai-year')).toHaveValue('2026');await expect(page.locator('#sampai-month')).toHaveValue('10');
  }
 };
 await page.goto(base+'/');
 await expect(page.locator('.department-table th').nth(1)).toHaveCSS('text-align','center');
 await expect(page.locator('.department-table tbody td').nth(1)).toHaveCSS('text-align','center');
 await expect(page.locator('.department-table th').nth(2)).toHaveCSS('text-align','right');
 await page.goto(base+'/transaksi/form');
 const context=page.locator('.payroll-context');
 const fields=await context.locator('.context-field').evaluateAll(elements=>elements.map(element=>{const r=element.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width};}));
 for(const field of fields.slice(0,4))expect(field.y).toBe(fields[0].y);
 expect(Math.abs(fields[0].width-fields[1].width)).toBeLessThan(1);
 expect(Math.abs(fields[2].width-fields[3].width)).toBeLessThan(1);
 expect(fields[2].width).toBeGreaterThan(fields[0].width*1.9);
 expect(fields[4].y).toBe(fields[5].y);
 for(const control of await context.locator(':is(.form-control,button.select-dropdown-toggle,button[data-bs-target="#employee-picker"]):visible').all())expect(Math.round((await control.boundingBox()).height)).toBe(48);
 const nip=await page.locator('#employee-nip').boundingBox(),find=await page.getByRole('button',{name:'Cari',exact:true}).boundingBox();expect(Math.abs(find.x-(nip.x+nip.width)-8)).toBeLessThan(1);
 const year=page.locator('#form-year');await year.focus();await expect(page.locator('#form-year-options')).toBeVisible();
 await expect(page.locator('#form-year-options .dropdown-item').first()).toHaveText('2026');
 await year.fill('1988');await year.press('Escape');await expect(year).toHaveValue('1988');expect(await year.evaluate(element=>element.validity.valid)).toBe(true);
 await year.fill('1899');expect(await year.evaluate(element=>element.validity.valid)).toBe(false);
 await year.fill('2026');await year.press('ArrowDown');await page.keyboard.press('Enter');await expect(year).toHaveValue('2026');await expect(page.locator('#form-year-options')).toBeHidden();
 await page.screenshot({path:path.join(destination,'context-desktop.png'),fullPage:true});
 await page.goto(base+'/transaksi/form?id='+fixture.first.id);await expect(page.locator('#form-year')).toHaveAttribute('readonly');await expect(page.locator('#form-year-toggle')).toBeDisabled();
 const types=['slip_karyawan','slip_bagian','rekap_bagian','rekap_periode','rekap_periode_bagian','absensi','gapok'];
 for(const type of types){
  await page.goto(base+'/laporan?type='+type);
  const form=page.locator('.report-filters'),actions=page.locator('.report-actions');
  const bounds=await form.boundingBox(),buttons=await actions.boundingBox();
  expect(Math.abs(buttons.x+buttons.width-(bounds.x+bounds.width-24))).toBeLessThan(2);
  for(const control of await form.locator(':is(.form-control,.select-dropdown-toggle,.report-actions .btn):visible').all())expect(Math.round((await control.boundingBox()).height)).toBe(48);
  if(type==='slip_karyawan'){
   const search=await page.locator('#report-employee').boundingBox(),month=await page.locator('#report-month-toggle').boundingBox();expect(search.width).toBeGreaterThan(month.width*1.7);
  }
  if(type.includes('periode')){
   await expect(page.locator('.report-period-group')).toHaveCount(2);
   const periodFields=await page.locator('.report-period-group').evaluateAll(elements=>elements.map(element=>element.getBoundingClientRect().y));expect(periodFields[0]).toBe(periodFields[1]);
  }
  for(const id of ['report-year','dari-year','sampai-year'])if(await page.locator('#'+id).count())await checkYear(id);
  if(type==='slip_karyawan'){
   await page.locator('#report-year').focus();await page.screenshot({path:path.join(destination,'year-input-desktop.png'),fullPage:true});
  }
  await page.screenshot({path:path.join(destination,type+'-desktop.png'),fullPage:true});
  await page.getByRole('button',{name:'Tampilkan',exact:true}).click();await expect(page.locator('.report-surface')).toBeVisible();
  await reset(type);
  await choose('report-scope','tertentu');
  if(type==='slip_karyawan'||type==='rekap_periode'){
   await page.locator('#report-employee').fill('Karyawan Contoh');await page.locator('#report-employee-options [data-select-value="'+fixture.reference.nip+'"]').click();
   if(type==='slip_karyawan')await choose('report-month','9');
  }else await choose('report-department','PA001');
  if(await page.locator('#report-detail').count())await choose('report-detail','1');
  await page.getByRole('button',{name:'Tampilkan',exact:true}).click();await expect(page.locator('.report-document')).toBeVisible();
  await reset(type);
 }
 await page.goto(base+'/laporan?type=rekap_periode&dari_bulan=12&dari_tahun=2025&sampai_bulan=10&sampai_tahun=2026&view=1&rincian=1');await expect(page.locator('.report-document')).toBeVisible();await reset('rekap_periode');
 await page.goto(base+'/laporan?type=rekap_periode&dari_bulan=12&dari_tahun=2026&sampai_bulan=1&sampai_tahun=2025&view=1');await expect(page.locator('.alert-danger')).toBeVisible();await closeFeedback();await reset('rekap_periode');
 await page.locator('#dari-year').fill('1899');expect(await page.locator('#dari-year').evaluate(element=>element.validity.valid)).toBe(false);await page.locator('#dari-year').press('Escape');await reset('rekap_periode');
 await page.goto(base+'/laporan?type=slip_karyawan&bulan=1&tahun=2099&view=1');await expect(page.getByText('Tidak ada data pada cakupan ini',{exact:true})).toBeVisible();await reset('slip_karyawan');
 await page.locator('#report-year').fill('2027');await page.getByRole('button',{name:'Tampilkan',exact:true}).click();await expect(page.getByText('Tidak ada data pada cakupan ini',{exact:true})).toBeVisible();await expect(page).toHaveURL(/tahun=2027/);await reset('slip_karyawan');
 await page.goto(base+'/laporan?type=rekap_periode');await page.locator('#dari-year').fill('2025');await page.locator('#dari-year').press('Escape');await page.locator('#sampai-year').fill('2027');await page.getByRole('button',{name:'Tampilkan',exact:true}).click();await expect(page.locator('.report-document')).toBeVisible();await reset('rekap_periode');
 await page.goto(base+'/transaksi');
 const defaultStats=await page.locator('.stats-grid').innerText();
 const resetPayroll=async()=>{
  await page.locator('.payroll-actions').getByRole('link',{name:'Reset',exact:true}).click();await expect(page).toHaveURL(base+'/transaksi');
  await expect(page.locator('#list-month')).toHaveValue('10');await expect(page.locator('#list-year')).toHaveValue('2026');await expect(page.locator('#list-department')).toHaveValue('');await expect(page.locator('#list-search')).toHaveValue('');
  expect(await page.locator('.stats-grid').innerText()).toBe(defaultStats);await expect(page.locator('.app-table tbody tr')).toHaveCount(2);await expect(page.locator('.table-pagination')).toHaveCount(0);
 };
 for(const query of ['?bulan=9&tahun=2026&bagian=PA001&q='+fixture.reference.nip,'?bulan=10&tahun=2026&page=2','?bulan=1&tahun=2030&q=missing']){await page.goto(base+'/transaksi'+query);await resetPayroll();}
 await page.locator('#list-year').fill('1899');expect(await page.locator('#list-year').evaluate(element=>element.validity.valid)).toBe(false);await resetPayroll();
 await page.locator('#list-year').fill('');await resetPayroll();
 await page.goto(base+'/transaksi?bulan=9&tahun=2026');
 const payroll=page.locator('.payroll-filters'),payrollButton=payroll.getByRole('button',{name:'Tampilkan',exact:true});
 await expect(page.locator('#list-year')).toHaveAttribute('type','number');
 const payrollBounds=await payroll.boundingBox(),buttonBounds=await payrollButton.boundingBox(),monthBounds=await page.locator('#list-month-toggle').boundingBox(),searchBounds=await page.locator('#list-search').boundingBox();
 expect(buttonBounds.width).toBe(260);expect(Math.abs(buttonBounds.x+buttonBounds.width-(payrollBounds.x+payrollBounds.width-23))).toBeLessThan(1);expect(searchBounds.width).toBeGreaterThan(monthBounds.width*1.8);await expect(payroll.getByRole('link',{name:'Reset',exact:true})).toBeVisible();
 await page.screenshot({path:path.join(destination,'payroll-filters-desktop.png'),fullPage:true});
 await page.locator('#list-search').fill('Karyawan Contoh');await page.locator('#list-search-options [data-select-value="'+fixture.reference.nip+'"]').click();await payrollButton.click();await expect(page.locator('tbody')).toContainText(fixture.reference.nip);
 for(const width of [1440,1024,768,390]){
  await page.setViewportSize({width,height:900});
  await page.goto(base+'/transaksi/form');await noOverflow();
  const positions=await page.locator('.context-field').evaluateAll(elements=>elements.map(element=>element.getBoundingClientRect().y));
  if(width<768)for(let i=1;i<positions.length;i++)expect(positions[i]).toBeGreaterThan(positions[i-1]);
  else if(width<1200){expect(positions[0]).toBe(positions[1]);expect(positions[2]).toBe(positions[3]);}
  if(width===390)await page.screenshot({path:path.join(destination,'context-mobile.png'),fullPage:true});
  await page.goto(base+'/transaksi?bulan=9&tahun=2026');await noOverflow();
  const filter=page.locator('.payroll-filters'),show=filter.getByRole('button',{name:'Tampilkan',exact:true}),filterRect=await filter.boundingBox(),showRect=await show.boundingBox();
  const filterPadding=await filter.evaluate(element=>parseFloat(getComputedStyle(element).paddingRight));
  expect(Math.abs(showRect.x+showRect.width-(filterRect.x+filterRect.width-filterPadding))).toBeLessThan(1);expect(Math.round(showRect.height)).toBe(48);expect(await page.locator('#list-month-selected').evaluate(element=>element.scrollWidth<=element.clientWidth)).toBe(true);
  if(width<768){const fieldRect=await page.locator('#list-search').boundingBox();expect(showRect.width).toBeCloseTo(fieldRect.width,0);}else expect(showRect.width).toBe(260);
  if(width===390)await page.screenshot({path:path.join(destination,'payroll-filters-mobile.png'),fullPage:true});
  for(const type of types){
   await page.goto(base+'/laporan?type='+type);await noOverflow();
   if(width===390)await page.screenshot({path:path.join(destination,type+'-mobile.png'),fullPage:true});
   const id=type.includes('periode')?'dari-month':type==='gapok'?'report-scope':'report-month';
   await page.locator('#'+id+'-toggle').click();await expect(page.locator('#'+id+'-options')).toBeVisible();
   const menu=await page.locator('#'+id+'-options').boundingBox();expect(menu.x).toBeGreaterThanOrEqual(0);expect(menu.x+menu.width).toBeLessThanOrEqual(width);
   await page.keyboard.press('Escape');await expect(page.locator('#'+id+'-options')).toBeHidden();
   for(const yearId of ['report-year','dari-year','sampai-year'])if(await page.locator('#'+yearId).count()){
    const yearInput=page.locator('#'+yearId);await expect(yearInput).toHaveAttribute('type','number');await expect(page.locator('.year-panel')).toHaveCount(0);
    await yearInput.fill('2030');await yearInput.press('ArrowUp');await expect(yearInput).toHaveValue('2031');await yearInput.fill('2026');
    if(width===390&&type==='rekap_periode'&&yearId==='dari-year'){await yearInput.focus();await page.screenshot({path:path.join(destination,'year-input-mobile.png'),fullPage:true});}

   }
  }
 }
 expect(writes,'Presentation and Reset must never submit a save/delete POST').toBe(0);page.off('request',record);await page.setViewportSize({width:1600,height:1000});
 console.log('PASS: centered dashboard column, transaction grid, editable years, seven report filters/Reset, payroll Reset, numeric year spinners, invalid/empty/cross-year states, keyboard and four responsive widths; no save/delete POST.');
};
