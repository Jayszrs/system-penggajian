const {test,expect}=require('@playwright/test');
const closeFeedback=async page=>{await expect(page.locator('#feedback-modal')).toBeVisible();await page.locator('#feedback-accept').click();await expect(page.locator('#feedback-modal')).toBeHidden();};
test.describe.serial('Penggajian with restored source fixtures',()=>{
 let state;
 const errors=[];
 test('first account, authentication and exact dashboard totals',async({page})=>{
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('/setup');await expect(page.getByRole('heading',{name:'Mulai bersama Mutiara.'})).toBeVisible();
  await page.getByLabel('Nama lengkap',{exact:true}).fill('Petugas Pengujian');await page.getByLabel('ID pengguna',{exact:true}).fill('qa_penggajian');await page.getByLabel('Kata sandi',{exact:true}).fill('Qa!Payroll-2026-Local');await page.getByLabel('Konfirmasi kata sandi',{exact:true}).fill('Qa!Payroll-2026-Local');await page.getByRole('button',{name:'Buat akun pengelola'}).click();
  await expect(page).toHaveURL(/\/login$/);await closeFeedback(page);await page.getByLabel('ID pengguna',{exact:true}).fill('qa_penggajian');await page.getByLabel('Kata sandi',{exact:true}).fill('Qa!Payroll-2026-Local');await page.getByRole('button',{name:'Masuk ke ruang kerja'}).click();
  await expect(page.getByRole('heading',{name:'Dashboard Penggajian.'})).toBeVisible();await expect(page.getByText('Periode September 2025',{exact:true})).toBeVisible();
  await expect(page.locator('.stat-card').first().locator('.stat-value')).toHaveText('24');await expect(page.locator('#annual-chart')).toBeVisible();await expect(page.locator('#department-chart')).toBeVisible();
  await page.screenshot({path:'storage/qa/dashboard-desktop.png',fullPage:true});
  state=await page.context().storageState();expect(errors).toEqual([]);
 });
 test.beforeEach(async({context})=>{if(state)await context.addCookies(state.cookies);});
 test('dashboard dropdowns open and preserve the selected year and department',async({page})=>{
  await page.goto('/?bulan=9&tahun=2025');
  const monthToggle=page.getByRole('button',{name:'Pilih bulan',exact:true});
  await monthToggle.click();await expect(monthToggle).toHaveAttribute('aria-expanded','true');
  await expect(page.locator('.period-month-menu .dropdown-item')).toHaveCount(12);
  await page.locator('.period-month-menu').getByRole('button',{name:'Desember',exact:true}).click();
  await expect(monthToggle).toContainText('Desember');await expect(monthToggle).toHaveAttribute('aria-expanded','false');
  await page.locator('#period-year').fill('2024');await page.getByRole('button',{name:'Terapkan periode',exact:true}).click();
  await expect(page.getByText('Periode Desember 2024',{exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Pilih bagian',exact:true}).click();
  const department=page.locator('.department-dropdown .dropdown-item').nth(1);const code=await department.getAttribute('data-select-value');
  await department.click();await expect(page).toHaveURL(new RegExp('bagian='+encodeURIComponent(code)));
  await expect(page.getByText('Periode Desember 2024',{exact:true})).toBeVisible();
  await monthToggle.click();await page.locator('.period-month-menu').getByRole('button',{name:'Januari',exact:true}).click();
  await page.getByRole('button',{name:'Terapkan periode',exact:true}).click();
  await expect(page).toHaveURL(new RegExp('bagian='+encodeURIComponent(code)));await expect(page.getByText('Periode Januari 2024',{exact:true})).toBeVisible();
  await monthToggle.focus();await page.keyboard.press('ArrowDown');await expect(monthToggle).toHaveAttribute('aria-expanded','true');
  await expect(page.locator('.period-month-menu .dropdown-item').first()).toBeFocused();
  await page.keyboard.press('Escape');await expect(monthToggle).toHaveAttribute('aria-expanded','false');await expect(monthToggle).toBeFocused();
 });
 test('all legacy master modules and full migration archive',async({page})=>{
  test.setTimeout(60000);
  const modules=['bagian','karyawan','tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime','tunj_jam','p_anak','p_bjbs','p_bank','p_lunak','p_lain'];
  for(const key of modules){const response=await page.goto('/master/'+key);expect(response.status()).toBe(200);await expect(page.locator('h1')).toBeVisible();}
  await page.goto('/migrasi');await expect(page.getByText('19 tabel terarsip')).toBeVisible();await expect(page.getByText('485 baris arsip',{exact:false})).toBeVisible();
  await page.goto('/arsip?table=dbo.Sheet1%24');await expect(page.locator('h1')).toContainText('Sheet1$');
 });
 test('master CRUD preserves leading zero NIP and validates dependencies',async({page})=>{
  await page.goto('/master/bagian?new=1');await page.getByLabel('Kode bagian').fill('TQA01');await page.getByLabel('Nama bagian').fill('Bagian pengujian');await page.getByRole('button',{name:'Simpan data'}).click();await expect(page.locator('.alert-success')).toContainText('disimpan');await closeFeedback(page);
  await page.goto('/master/karyawan?new=1');await page.locator('#master-nip').fill('000000000000001');await page.getByLabel('Nama lengkap').fill('Karyawan <Uji>');await page.locator('#master-kd_bagian-toggle').click();await page.locator('#master-kd_bagian-options [data-select-value="TQA01"]').click();await page.locator('#master-gapok').fill('1000000');await page.locator('#master-t_harian').fill('20000');await expect(page.locator('#master-gapok')).toHaveValue('1.000.000');await expect(page.locator('#master-t_harian')).toHaveValue('20.000');await page.getByRole('button',{name:'Simpan data'}).click();await expect(page.locator('tbody')).toContainText('000000000000001');await expect(page.locator('tbody')).toContainText('Karyawan <Uji>');expect(await page.locator('tbody script').count()).toBe(0);await closeFeedback(page);
  await page.goto('/master/tunj_konsumsi?new=1');await page.locator('#master-kd_bagian-toggle').click();await page.locator('#master-kd_bagian-options [data-select-value="TQA01"]').click();await page.locator('#master-jumlah').fill('0');await page.getByRole('button',{name:'Simpan data'}).click();await closeFeedback(page);
  await page.goto('/master/bagian?q=TQA01');await page.getByRole('button',{name:'Hapus TQA01',exact:true}).click();await expect(page.locator('#feedback-modal')).toBeVisible();await page.locator('#feedback-modal').getByRole('button',{name:'Ya, hapus',exact:true}).click();await expect(page.locator('#feedback-modal .feedback-message')).toContainText('masih digunakan');await closeFeedback(page);
 });
 test('shared dropdowns validate required choices, search names and sync payroll controls',async({page})=>{
  const failures=[];page.on('pageerror',error=>failures.push(error.message));
  await page.goto('/master/p_anak?new=1');await page.getByRole('button',{name:'Simpan data',exact:true}).click();
  await closeFeedback(page);const employeeToggle=page.locator('#master-nip-toggle');await expect(employeeToggle).toBeFocused();await expect(employeeToggle).toHaveAttribute('aria-invalid','true');
  await expect(page.locator('.select-dropdown .invalid-feedback')).toContainText('terlebih dahulu');
  if(await employeeToggle.getAttribute('aria-expanded')!=='true')await employeeToggle.click();await page.locator('#master-nip-options input[type=search]').fill('Karyawan <Uji>');
  await expect(page.locator('#master-nip-options .dropdown-item:visible')).toHaveCount(1);
  await page.locator('#master-nip-options [data-select-value="000000000000001"]').click();
  await expect(page.locator('#master-nip')).toHaveValue('000000000000001');await expect(employeeToggle).not.toHaveAttribute('aria-invalid');
  await page.goto('/transaksi/form?bulan=10&tahun=2026&bagian=TQA01');
  await page.getByLabel('NIP karyawan',{exact:true}).fill('000000000000001');await page.getByLabel('Hari efektif',{exact:true}).fill('21');await page.getByLabel('Masuk kerja',{exact:true}).fill('21');
  await page.getByRole('button',{name:'Muat komponen master',exact:true}).click();await expect(page.locator('[data-total=tbersih]')).toHaveText('Rp 1.420.000');
  await expect(page.locator('#form-department-toggle')).toContainText('Bagian pengujian');await expect(page.locator('#calculation-mode-toggle')).toContainText('Hitung dari master saat ini');
  await page.locator('#calculation-mode-toggle').click();await page.locator('#calculation-mode-options [data-select-value="manual"]').click();
  await expect(page.getByLabel('Gaji pokok',{exact:true})).not.toHaveAttribute('readonly');expect(failures).toEqual([]);
 });
 test('formatted integer inputs preserve precision, editing and raw calculation requests',async({page})=>{
  await page.goto('/transaksi/form?bulan=10&tahun=2026&bagian=TQA01');
  await page.getByLabel('NIP karyawan',{exact:true}).fill('000000000000001');await page.getByLabel('Hari efektif',{exact:true}).fill('21');await page.getByLabel('Masuk kerja',{exact:true}).fill('21');
  await page.locator('#calculation-mode-toggle').click();await page.locator('#calculation-mode-options [data-select-value="manual"]').click();
  await expect(page.getByLabel('Gaji pokok',{exact:true})).not.toHaveAttribute('readonly');
  const amount=page.getByLabel('Gaji pokok',{exact:true});
  const calculated=async(raw,formatted,expectedStatus=200)=>{
   const response=page.waitForResponse(r=>r.url().endsWith('/api/hitung')&&r.request().method()==='POST'&&r.request().postData()?.includes('name="gapok"\r\n\r\n'+raw+'\r\n'));
   await amount.fill(raw);await expect(amount).toHaveValue(formatted);const result=await response;expect(result.status()).toBe(expectedStatus);const data=await result.json();if(expectedStatus===200)expect(data.values.gapok).toBe(raw);else expect(data.issues[0].code).toBe('negative_amount');
   expect(await page.locator('#payroll-form').evaluate(form=>new FormData(form).get('gapok'))).toBe(raw);
  };
  await calculated('100000','100.000');
  await amount.evaluate(input=>input.setSelectionRange(1,1));await page.keyboard.insertText('2');await expect(amount).toHaveValue('1.200.000');expect(await amount.evaluate(input=>input.selectionStart)).toBe(3);
  await amount.fill('1234567');await amount.evaluate(input=>input.setSelectionRange(2,2));await page.keyboard.press('Backspace');await expect(amount).toHaveValue('234.567');
  await amount.evaluate(input=>input.select());await amount.evaluate(input=>{const data=new DataTransfer();data.setData('text/plain','100.000');input.dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:data}));});await expect(amount).toHaveValue('100.000');
  await calculated('999999999999999999','999.999.999.999.999.999');await calculated('-100000','-100.000',422);
  await amount.fill('1e10');expect(await amount.evaluate(input=>input.validity.valid)).toBe(false);
  await amount.fill('100000');await amount.evaluate(input=>input.select());await amount.evaluate(input=>{const data=new DataTransfer();data.setData('text/plain','1,50');input.dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:data}));});await expect(amount).toHaveValue('100.000');expect(await amount.evaluate(input=>input.validity.valid)).toBe(false);await closeFeedback(page);
  await amount.fill('');await expect(amount).toHaveValue('');expect(await amount.evaluate(input=>input.validity.valid)).toBe(true);
 });
 test('server calculations, duplicate period, history after tariff changes, and cancellation',async({page})=>{
  await page.goto('/transaksi/form?bulan=10&tahun=2026&bagian=TQA01');await page.getByLabel('NIP karyawan',{exact:true}).fill('000000000000001');await page.getByLabel('Hari efektif',{exact:true}).fill('21');await page.getByLabel('Masuk kerja',{exact:true}).fill('21');await page.getByRole('button',{name:'Muat komponen master'}).click();await expect(page.locator('[data-total=tbersih]')).toHaveText('Rp 1.420.000');
  await page.getByRole('button',{name:'Simpan penghasilan'}).click();await expect(page.locator('.alert-success')).toContainText('disimpan');await closeFeedback(page);const savedUrl=page.url();
  await page.goto('/transaksi/form?bulan=10&tahun=2026&bagian=TQA01');await page.getByLabel('NIP karyawan',{exact:true}).fill('000000000000001');await page.getByLabel('Hari efektif',{exact:true}).fill('21');await page.getByLabel('Masuk kerja',{exact:true}).fill('21');await page.getByRole('button',{name:'Simpan penghasilan'}).click();await expect(page.locator('#feedback-modal .feedback-message')).toContainText('sudah memiliki transaksi');await closeFeedback(page);
  await page.goto('/master/karyawan?q=000000000000001');await page.getByRole('link',{name:'Ubah 000000000000001',exact:true}).click();await page.locator('#master-gapok').fill('2000000');await page.getByRole('button',{name:'Simpan data'}).click();await closeFeedback(page);
  await page.goto(savedUrl);await expect(page.locator('[data-total=tbersih]')).toHaveText('Rp 1.420.000');await expect(page.locator('#calculation-mode')).toHaveValue('manual');await expect(page.locator('#form-month-toggle')).toBeDisabled();await expect(page.locator('#form-department-toggle')).toBeDisabled();
  await page.getByLabel('Gaji pokok',{exact:true}).fill('9999');await page.getByRole('link',{name:'Batal',exact:true}).click();await page.goto(savedUrl);await expect(page.getByLabel('Gaji pokok',{exact:true})).toHaveValue('1.000.000');
 });
 test('report dropdowns share employee search and submit the original NIP and filters',async({page})=>{
  await page.goto('/laporan?type=slip_karyawan&bulan=10&tahun=2026');
  await page.locator('#report-scope-toggle').click();await page.locator('#report-scope-options [data-select-value="tertentu"]').click();
  await page.getByLabel('Karyawan',{exact:true}).fill('Karyawan <Uji>');await expect(page.locator('#report-employee-options')).toBeVisible();
  await expect(page.locator('#report-employee-options .dropdown-item:visible')).toHaveCount(1);await page.locator('#report-employee-options [data-select-value="000000000000001"]').click();
  await expect(page.getByLabel('Karyawan',{exact:true})).toHaveValue('000000000000001');
  await page.getByRole('button',{name:'Tampilkan',exact:true}).click();await expect(page.locator('.slip')).toBeVisible();await expect(page.locator('.slip-identity')).toContainText('000000000000001');
 });
 test('all seven reports, cross-year range, explicit coverage and printable details',async({page})=>{
  test.setTimeout(60000);
  const types=['slip_karyawan','slip_bagian','rekap_bagian','rekap_periode','rekap_periode_bagian','absensi','gapok'];
  for(const type of types){await page.goto(`/laporan?type=${type}&bulan=9&tahun=2025&dari_bulan=12&dari_tahun=2024&sampai_bulan=2&sampai_tahun=2025&view=1`);await expect(page.getByRole('button',{name:'Cetak / Simpan PDF'})).toBeVisible();if(type.startsWith('slip'))await expect(page.locator('.slip').first()).toBeVisible();else await expect(page.locator('.report-table')).toBeVisible();}
  await page.goto('/laporan?type=rekap_periode&dari_bulan=12&dari_tahun=2025&sampai_bulan=1&sampai_tahun=2025&view=1');await expect(page.locator('.alert-danger')).toContainText('tidak boleh melewati');
  await page.goto('/laporan?type=rekap_bagian&bulan=9&tahun=2025&rincian=1&view=1');expect(await page.locator('.detailed-screen .report-table thead tr:last-child th').count()).toBe(35);await page.emulateMedia({media:'print'});await expect(page.locator('.sidebar')).toBeHidden();await page.pdf({path:'storage/qa/rekap-rinci.pdf',format:'A4',landscape:true,printBackground:true});
 });
 test('mobile layout, picker, empty state, CSRF and file protection',async({page})=>{
  await page.setViewportSize({width:390,height:844});await page.goto('/');await page.getByRole('button',{name:'Buka navigasi'}).click();await expect(page.locator('body')).toHaveClass(/sidebar-open/);await page.keyboard.press('Escape');await expect(page.locator('body')).not.toHaveClass(/sidebar-open/);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();await page.getByRole('button',{name:'Pilih bulan',exact:true}).click();await page.locator('.period-month-menu').getByRole('button',{name:'September',exact:true}).click();await page.locator('#period-year').fill('2025');await page.getByRole('button',{name:'Terapkan periode',exact:true}).click();await expect(page.getByText('Periode September 2025',{exact:true})).toBeVisible();await page.screenshot({path:'storage/qa/dashboard-mobile.png',fullPage:true});
  await page.goto('/transaksi/form');await page.getByRole('button',{name:'Cari',exact:true}).click();await page.getByLabel('Cari NIP atau nama').fill('000000000000001');await expect(page.locator('.picker-result')).toHaveCount(1);await page.locator('.picker-result').click();await expect(page.getByLabel('NIP karyawan',{exact:true})).toHaveValue('000000000000001');
  await page.goto('/?bulan=1&tahun=2099');await expect(page.getByText('Belum ada transaksi pada periode ini',{exact:true})).toBeVisible();
  const csrf=await page.request.post('/api/hitung',{form:{hefektif:'21'}});expect(csrf.status()).toBe(419);
  for(const path of ['/storage/legacy/export.json','/.env.example','/app/bootstrap.php','/scripts/install.php','/package.json']){const response=await page.request.get(path);expect(response.status()).toBe(403);}
 });
});
