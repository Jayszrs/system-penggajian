const {test,expect}=require('@playwright/test');

// app.spec.js creates this isolated QA account and employee first. Never reset a
// database here or create a fallback account against an unexpected server.
const QA_NIP='000000000000001';
const QA_DEPARTMENT='TQA01';
const income=['gapok','t_jabatan','t_harian','t_konsumsi','ulembur','uinfal','uovertime','lain_lain','kelebihan','lain1'];
const deductions=['makan','cash_bon','pinjaman_lunak','absensi','tabungan','arisan','pinjaman_bsm','p_anak','plain'];
const attendance=['hefektif','hmasuk','hijin','halpha','lembur','infal','overtime'];
const baseInput=overrides=>({...Object.fromEntries([...income,...deductions,...attendance].map(field=>[field,'0'])),id:'0',nip:QA_NIP,bagian:QA_DEPARTMENT,bulan:'4',tahun:'2033',calculation_mode:'manual',hefektif:'21',hmasuk:'21',gapok:'100000',...overrides});
const modal=page=>page.locator('#feedback-modal');
const csrf=page=>page.locator('meta[name="csrf-token"]').getAttribute('content');

async function dismissFeedback(page){
 if(await modal(page).isVisible()){
  await modal(page).getByRole('button',{name:'Mengerti',exact:true}).click();
  await expect(modal(page)).toBeHidden();
 }
}
async function calculate(page,overrides={}){
 return page.request.post('/api/hitung',{form:{...baseInput(overrides),_csrf:await csrf(page)}});
}
async function manualForm(page,month='4'){
 await page.goto(`/transaksi/form?bulan=${month}&tahun=2033&bagian=${QA_DEPARTMENT}`);
 await page.locator('#employee-nip').fill(QA_NIP);
 await page.locator('#employee-nip').press('Tab');
 await page.locator('#calculation-mode-toggle').click();
 await page.locator('#calculation-mode-options [data-select-value="manual"]').click();
 await page.locator('#payroll-hefektif').fill('21');
 await page.locator('#payroll-hmasuk').fill('21');
 await page.locator('#payroll-gapok').fill('100000');
 await expect(page.locator('[data-total="tpenghasilan"]')).toHaveText('Rp 100.000');
}
async function transactionCount(page,month='4'){
 const response=await page.request.get(`/transaksi?bulan=${month}&tahun=2033&bagian=${QA_DEPARTMENT}&q=${QA_NIP}`);
 expect(response.status()).toBe(200);
 const html=await response.text();
 const match=html.match(/class="stat-value"[^>]*>\s*(\d+)\s*<\/strong>/);
 expect(match,'Transaction list exposes its filtered count').not.toBeNull();
 return Number(match[1]);
}
async function removeTestPayroll(page,month='4'){
 await page.goto(`/transaksi?bulan=${month}&tahun=2033&bagian=${QA_DEPARTMENT}&q=${QA_NIP}`);
 const button=page.getByRole('button',{name:`Hapus transaksi ${QA_NIP}`,exact:true});
 if(await button.count()){
  await button.click();await expect(modal(page)).toBeVisible();
  await modal(page).getByRole('button',{name:'Ya, hapus',exact:true}).click();
  await expect(modal(page)).toBeVisible();await expect(modal(page).locator('.feedback-message')).toContainText('dihapus');
  await dismissFeedback(page);
 }
 expect(await transactionCount(page,month)).toBe(0);
}

test.describe.serial('Accounting validation, confirmation and feedback',()=>{
 test.beforeEach(async({page,baseURL})=>{
  expect(new URL(baseURL).hostname).toBe('127.0.0.1');
  expect(new URL(baseURL).origin).toBe(new URL(process.env.PAYROLL_TEST_URL||'http://127.0.0.1:8018').origin);
  await page.goto('/login');
  await page.getByLabel('ID pengguna',{exact:true}).fill('qa_penggajian');
  await page.getByLabel('Kata sandi',{exact:true}).fill('Qa!Payroll-2026-Local');
  await page.getByRole('button',{name:'Masuk ke ruang kerja',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Dashboard Penggajian.'})).toBeVisible();
  await dismissFeedback(page);
  const fixture=await page.request.get('/api/karyawan?q='+QA_NIP);
  expect(fixture.status()).toBe(200);
  expect((await fixture.json()).employees.some(employee=>employee.nip===QA_NIP&&employee.kd_bagian===QA_DEPARTMENT),'Use only the reserved QA employee').toBe(true);
 });

 test('API recomputes submitted totals exactly and returns structured accounting errors',async({page})=>{
  const response=await calculate(page,{gapok:'1000',t_jabatan:'100',plain:'50',tpenghasilan:'tampered',tpotongan:'999999',tbersih:'999999'});
  expect(response.status()).toBe(200);const result=await response.json();
  expect(result.values).toMatchObject({tpenghasilan:'1100',tpotongan:'50',tbersih:'1050'});
  expect(result.calculation_version).toBe('accounting_v2');
  expect(result.issues).toEqual(expect.arrayContaining([expect.objectContaining({code:'manual_override',severity:'warning'})]));
  expect(result.confirmation_token).toMatch(/^[a-f0-9]{48}$/);
  const large=await calculate(page,{gapok:'999999999999999999',plain:'1'});
  expect(large.status()).toBe(200);expect((await large.json()).values.tbersih).toBe('999999999999999998');
  const zeroRate=await calculate(page,{calculation_mode:'master'});
  expect(zeroRate.status()).toBe(200);const zeroResult=await zeroRate.json();
  expect(zeroResult.values.t_konsumsi).toBe('0');expect(zeroResult.issues).toEqual([]);
  const cases=[
   [{gapok:'-1'},'negative_amount','gapok'],
   [{plain:'-1'},'negative_amount','plain'],
   [{gapok:'100',plain:'101'},'negative_net','tbersih'],
   [{hefektif:'29',hmasuk:'29',bulan:'2',tahun:'2025'},'effective_days_exceed_calendar','hefektif'],
   [{hmasuk:'22'},'attendance_exceeds_effective','hmasuk'],
   [{bulan:'2.5'},'invalid_period','bulan'],
   [{calculation_mode:'master',infal:'1'},'missing_required_rate','uinfal'],
  ];
  for(const [input,code,field] of cases){
   const rejected=await calculate(page,input);expect(rejected.status()).toBe(422);
   expect((await rejected.json()).issues).toEqual(expect.arrayContaining([expect.objectContaining({code,field,severity:'error'})]));
  }
 });

 test('warning acknowledgement is required and becomes invalid after input changes',async({page})=>{
  expect(await transactionCount(page,'3')).toBe(0);
  const input=baseInput({bulan:'3'});const preview=await calculate(page,input);expect(preview.status()).toBe(200);
  const token=(await preview.json()).confirmation_token;expect(token).toMatch(/^[a-f0-9]{48}$/);
  for(const payload of [{...input},{...input,gapok:'200000',confirmation_token:token}]){
   const rejected=await page.request.post('/transaksi/form',{form:{...payload,_csrf:await csrf(page)},maxRedirects:0});
   expect(rejected.status()).toBe(422);
   expect(await rejected.text()).toContain('Lanjutkan Simpan');
   expect(await transactionCount(page,'3')).toBe(0);
  }
 });

 test('warning cancellation retains input and success follows a real commit; delete needs confirmation',async({page})=>{
  await removeTestPayroll(page);await manualForm(page);
  let writes=0;page.on('request',request=>{if(new URL(request.url()).pathname==='/transaksi/form'&&request.method()==='POST')writes++;});
  await page.getByRole('button',{name:'Simpan penghasilan',exact:true}).click();
  await expect(modal(page)).toBeVisible();await expect(modal(page)).toContainText('manual');
  expect(writes).toBe(0);expect(await transactionCount(page)).toBe(0);
  await modal(page).getByRole('button',{name:'Kembali Periksa',exact:true}).click();
  await expect(modal(page)).toBeHidden();await expect(page.locator('#payroll-gapok')).toHaveValue('100.000');
  expect(writes).toBe(0);
  await page.getByRole('button',{name:'Simpan penghasilan',exact:true}).click();
  await expect(modal(page)).toBeVisible();
  await modal(page).getByRole('button',{name:'Lanjutkan Simpan',exact:true}).click();
  await expect(page).toHaveURL(/\/transaksi\/form\?id=\d+$/);
  await expect(modal(page)).toBeVisible();await expect(modal(page).locator('.feedback-message')).toContainText('disimpan');
  expect(writes).toBe(1);expect(await transactionCount(page)).toBe(1);
  await dismissFeedback(page);
  await removeTestPayroll(page);
 });

 test('mobile error modal and keyboard navigation preserve attendance values',async({page})=>{
  await page.setViewportSize({width:390,height:844});await manualForm(page,'5');
  await page.locator('#payroll-hmasuk').fill('22');
  await page.getByRole('button',{name:'Simpan penghasilan',exact:true}).click();
  await expect(modal(page)).toBeVisible();
  await expect(modal(page)).toHaveAttribute('aria-modal','true');
  await expect(modal(page)).toContainText('hari efektif');
  await expect(modal(page).getByRole('button',{name:'Perbaiki data',exact:true})).toBeFocused();
  const bounds=await modal(page).locator('.modal-dialog').boundingBox();
  expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(390);
  await page.keyboard.press('Tab');
  await expect(modal(page).locator(':focus')).toHaveCount(1);
  await page.keyboard.press('Escape');await expect(modal(page)).toBeHidden();
  await expect(page.locator('#payroll-hmasuk')).toHaveValue('22');await expect(page.locator('#payroll-gapok')).toHaveValue('100.000');
  expect(await transactionCount(page,'5')).toBe(0);
 });

 test('a failed calculation never submits payroll automatically or clears entered data',async({page})=>{
  await manualForm(page,'6');let writes=0;
  page.on('request',request=>{if(new URL(request.url()).pathname==='/transaksi/form'&&request.method()==='POST')writes++;});
  await page.route('**/api/hitung',route=>route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({error:'Layanan perhitungan sementara tidak tersedia.'})}));
  await page.locator('#payroll-gapok').fill('200000');
  await page.getByRole('button',{name:'Simpan penghasilan',exact:true}).click();
  await expect(modal(page)).toBeVisible();await expect(modal(page).locator('.feedback-message')).toContainText('tidak tersedia');
  await modal(page).getByRole('button',{name:'Perbaiki data',exact:true}).click();
  await expect(modal(page)).toBeHidden();await expect(page.locator('#payroll-gapok')).toHaveValue('200.000');
  expect(writes).toBe(0);expect(await transactionCount(page,'6')).toBe(0);
 });

 test('master other deductions feed plain without adding other income',async({page})=>{
  await page.goto('/master/p_lain?new=1');
  await page.locator('#master-nip-toggle').click();
  await page.locator(`#master-nip-options [data-select-value="${QA_NIP}"]`).click();
  await page.locator('#master-jumlah').fill('12000');
  await page.getByRole('button',{name:'Simpan data',exact:true}).click();
  await expect(modal(page)).toBeVisible();await dismissFeedback(page);
  try{
   const response=await calculate(page,{calculation_mode:'master'});expect(response.status()).toBe(200);
   const result=await response.json();expect(result.values.plain).toBe('12000');expect(result.values.lain1).toBe('0');
   expect(result.values.tbersih).toBe((BigInt(result.values.tpenghasilan)-BigInt(result.values.tpotongan)).toString());
  }finally{
   await page.goto('/master/p_lain?q='+QA_NIP);
   await page.getByRole('button',{name:'Hapus '+QA_NIP,exact:true}).click();
   await modal(page).getByRole('button',{name:'Ya, hapus',exact:true}).click();
   await expect(modal(page)).toBeVisible();await dismissFeedback(page);
  }
 });

 test('stored rate snapshots and explicit recalculation keep persisted amounts until save',async({page})=>{
  await page.goto(`/transaksi?bulan=10&tahun=2026&bagian=${QA_DEPARTMENT}&q=${QA_NIP}`);
  await page.getByRole('link',{name:`Ubah transaksi ${QA_NIP}`,exact:true}).click();
  await page.getByText('Dasar perhitungan dan sumber tarif',{exact:true}).click();
  await expect(page.locator('#rate-sources')).toContainText('Sumber tarif saat transaksi disimpan');
  await expect(page.locator('#rate-sources')).toContainText('Rp 20.000');
  let writes=0;page.on('request',request=>{if(new URL(request.url()).pathname==='/transaksi/form'&&request.method()==='POST')writes++;});
  for(const width of [1600,390]){
   await page.setViewportSize({width,height:844});
   await page.getByRole('button',{name:'Muat komponen master',exact:true}).click();
   await expect(modal(page)).toContainText('dihitung ulang');
   await modal(page).getByRole('button',{name:'Kembali Periksa',exact:true}).click();
   await expect(page.locator('#calculation-mode')).toHaveValue('manual');
   await expect(page.locator('#payroll-gapok')).toHaveValue('1.000.000');
   await page.getByRole('button',{name:'Muat komponen master',exact:true}).click();
   await modal(page).getByRole('button',{name:'Hitung ulang',exact:true}).click();
   await expect(page.locator('#payroll-gapok')).toHaveValue('2.000.000');
   expect(writes).toBe(0);
   await page.reload();
   await expect(page.locator('#payroll-gapok')).toHaveValue('1.000.000');
   await expect(page.locator('#calculation-mode')).toHaveValue('manual');
  }
  expect(writes).toBe(0);
 });
});
