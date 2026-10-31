'use strict';
// Run after the browser suite has prepared the isolated penggajian_test database.
const { chromium, expect } = require('@playwright/test');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const masterItems = [
  ['bagian', 'Data Bagian'], ['tunj_harian', 'Data Harian'],
  ['tunj_konsumsi', 'Data Konsumsi'], ['tunj_infal', 'Data Infal'],
  ['tunj_overtime', 'Data Overtime'], ['tunj_jam', 'Data Kelebihan Mengajar'],
  ['p_anak', 'Potongan Anak'], ['p_bjbs', 'Potongan BPJS'],
  ['p_lunak', 'Potongan Pinjaman Lunak'], ['p_bank', 'Potongan Pinjaman Bank'],
  ['p_lain', 'Potongan Lain-lain']
];
const reportItems = [
  ['slip_karyawan', 'Slip Gaji'], ['slip_bagian', 'Slip Gaji per Bagian'],
  ['rekap_bagian', 'Rekap Gaji per Bagian'], ['rekap_periode', 'Rekap Gaji per Periode'],
  ['rekap_periode_bagian', 'Rekap Gaji per Periode per Bagian'],
  ['absensi', 'Rekap Absensi'], ['gapok', 'Rekap Gapok']
];

async function verifySidebar(page, base, folder) {
  const workspace = page.getByRole('navigation', { name: 'Ruang kerja', exact: true });
  const master = workspace.locator('[data-nav-group=master]');
  const transactions = workspace.locator('[data-nav-group=transaksi]');
  const reports = workspace.locator('[data-nav-group=laporan]');
  await expect(workspace.locator(':scope > a, :scope > details > summary')).toHaveText(['Dashboard', 'Master', 'Transaksi', 'Laporan']);
  await expect(master.locator('.nav-submenu a')).toHaveText(masterItems.map(item => item[1]));
  await expect(transactions.locator('.nav-submenu a')).toHaveText(['Karyawan', 'Penggajian']);
  await expect(reports.locator('.nav-submenu a')).toHaveText(reportItems.map(item => item[1]));
  await expect(workspace.locator('a[href$="/master/tunj_lembur"]')).toHaveCount(0);
  await master.locator('summary').focus();
  await page.keyboard.press('Enter');
  await expect(master).toHaveJSProperty('open', true);
  await page.keyboard.press('Space');
  await expect(master).toHaveJSProperty('open', false);
  await master.locator('summary').click();
  await transactions.locator('summary').click();
  await expect(master).toHaveJSProperty('open', true);
  await expect(transactions).toHaveJSProperty('open', true);
  for (const [key, label] of masterItems) {
    await master.getByRole('link', { name: label, exact: true }).click();
    await expect(page).toHaveURL(base + '/master/' + key);
    await expect(master).toHaveJSProperty('open', true);
    await expect(workspace.locator('a[aria-current=page]')).toHaveText(label);
    await expect(page.locator('.breadcrumb-area strong')).toHaveText('Master');
  }
  await transactions.locator('summary').click();
  await transactions.getByRole('link', { name: 'Karyawan', exact: true }).click();
  await expect(page).toHaveURL(base + '/master/karyawan');
  await expect(transactions).toHaveJSProperty('open', true);
  await expect(page.locator('.breadcrumb-area strong')).toHaveText('Transaksi');
  for (const suffix of ['?new=1', '?edit=1']) {
    await page.goto(base + '/master/karyawan' + suffix);
    await expect(transactions).toHaveJSProperty('open', true);
    await expect(workspace.locator('a[aria-current=page]')).toHaveText('Karyawan');
  }
  await reports.locator('summary').click();
  for (const [type, label] of reportItems) {
    await reports.getByRole('link', { name: label, exact: true }).click();
    await expect(page).toHaveURL(base + '/laporan?type=' + type);
    await expect(page.locator('.filter-bar input[name=type]')).toHaveValue(type);
    await expect(reports).toHaveJSProperty('open', true);
    await expect(workspace.locator('a[aria-current=page]')).toHaveText(label);
    await expect(page.locator('.breadcrumb-area strong')).toHaveText('Laporan');
    await expect(page.locator('.report-document')).toHaveCount(0);
    await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
    await expect(workspace.locator('a[aria-current=page]')).toHaveText(label);
    await expect(page.locator('.report-toolbar')).toBeVisible();
  }
  for (const suffix of ['', '?type=tidak_valid']) {
    await page.goto(base + '/laporan' + suffix);
    await expect(workspace.locator('a[aria-current=page]')).toHaveText('Slip Gaji');
  }
  await page.goto(base + '/master/tunj_lembur');
  await expect(page.locator('h1')).toContainText('Lembur');
  const settings = page.getByRole('navigation', { name: 'Pengaturan', exact: true });
  for (const [label, route] of [['Data & Migrasi', '/migrasi'], ['Profil Saya', '/profil']]) {
    await settings.getByRole('link', { name: label, exact: true }).click();
    await expect(page).toHaveURL(base + route);
  }
  const qaPath = path.join(root, 'storage/qa');
  fs.mkdirSync(qaPath, { recursive: true });
  for (const width of [1600, 768, 390]) {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
    await page.goto(base + '/master/p_lunak');
    if (width === 390) await page.getByRole('button', { name: 'Buka navigasi', exact: true }).click();
    await transactions.locator('summary').click();
    await reports.locator('summary').click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    expect(await page.locator('.sidebar').evaluate(element => element.scrollWidth <= element.clientWidth)).toBeTruthy();
    for (const link of await workspace.locator('.nav-submenu a').all()) {
      expect(await link.evaluate(element => element.scrollWidth <= element.clientWidth && element.scrollHeight <= element.clientHeight)).toBeTruthy();
    }
    await page.locator('.sidebar').evaluate(element => { element.scrollTop = 0; });
    await page.screenshot({ path: path.join(qaPath, `sidebar-${folder ? 'folder' : 'root'}-${width}.png`) });
    await workspace.getByRole('link', { name: 'Rekap Gaji per Periode per Bagian', exact: true }).scrollIntoViewIfNeeded();
    await expect(workspace.getByRole('link', { name: 'Rekap Gaji per Periode per Bagian', exact: true })).toBeInViewport();
    if (width === 390) await page.screenshot({ path: path.join(qaPath, `sidebar-${folder ? 'folder' : 'root'}-mobile-reports.png`) });
    if (width === 390) {
      await page.keyboard.press('Escape');
      await expect(page.locator('body')).not.toHaveClass(/sidebar-open/);
      await expect(page.getByRole('button', { name: 'Buka navigasi', exact: true })).toHaveAttribute('aria-expanded', 'false');
    }
  }
  await page.setViewportSize({ width: 1600, height: 1000 });
  await page.goto(base + '/');
  await transactions.locator('summary').click();
  await transactions.getByRole('link', { name: 'Penggajian', exact: true }).click();
  await expect(page).toHaveURL(base + '/transaksi');
  await expect(workspace.locator('a[aria-current=page]')).toHaveText('Penggajian');
}

async function availablePort() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

async function verify(browser, folder) {
  const port = await availablePort();
  const prefix = folder ? '/' + path.basename(root) : '';
  const base = `http://127.0.0.1:${port}${prefix}`;
  const server = spawn('php', ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${port}`, '-t', folder ? path.dirname(root) : root, path.join(root, 'scripts/router.php')], {
    cwd: root, env: { ...process.env, DB_DATABASE: 'penggajian_test' }, stdio: 'ignore', windowsHide: true
  });
  let context;
  try {
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
      try { ready = (await fetch(base + '/login')).ok; } catch {}
      if (ready) break;
      if (server.exitCode !== null) throw new Error('The isolated PHP test server exited.');
      await delay(100);
    }
    if (!ready) throw new Error('The isolated login page is unavailable.');
    context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
    const page = await context.newPage();
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('response', response => { if (response.status() >= 400) failures.push(`${response.status()} ${response.url()}`); });
    page.on('request', request => {
      const address = new URL(request.url());
      if (address.origin === new URL(base).origin && !address.pathname.startsWith(prefix + '/')) failures.push('Request escaped the application folder: ' + address.pathname);
    });
    await page.goto(base + '/');
    await expect(page).toHaveURL(base + '/login');
    await expect(page.locator('.auth-form')).toHaveAttribute('action', prefix + '/login');
    await page.getByLabel('ID pengguna', { exact: true }).fill('qa_penggajian');
    await page.getByLabel('Kata sandi', { exact: true }).fill('Qa!Payroll-2026-Local');
    await page.getByRole('button', { name: 'Masuk ke ruang kerja' }).click();
    await expect(page).toHaveURL(base + '/');
    await expect(page.getByRole('heading', { name: 'Dashboard Penggajian.' })).toBeVisible();
    await expect(page.locator('.main-nav > .nav-item-link.active')).toHaveAttribute('href', prefix + '/');
    await verifySidebar(page, base, folder);
    await expect(page).toHaveURL(base + '/transaksi');
    await page.getByRole('link', { name: 'Transaksi baru', exact: true }).click();
    await expect(page.locator('#payroll-form')).toHaveAttribute('action', prefix + '/transaksi/form');
    await expect(page.locator('[data-nav-group=transaksi]')).toHaveJSProperty('open', true);
    await expect(page.getByRole('navigation', { name: 'Ruang kerja', exact: true }).locator('a[aria-current=page]')).toHaveText('Penggajian');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();
    await expect(page.locator('.picker-result').first()).toBeVisible();
    await page.locator('.picker-result').first().click();
    await page.getByLabel('Hari efektif', { exact: true }).fill('21');
    await page.getByLabel('Masuk kerja', { exact: true }).fill('21');
    const calculation = page.waitForResponse(response => response.url() === base + '/api/hitung' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Muat komponen master' }).click();
    expect((await calculation).status()).toBe(200);
    await page.locator('#form-month').selectOption('1');
    await page.locator('#form-year').fill('2098');
    await page.getByRole('button', { name: 'Simpan penghasilan', exact: true }).click();
    await expect(page).toHaveURL(/\/transaksi\/form\?id=\d+$/);
    const savedId = await page.locator('#payroll-form input[name=id]').inputValue();
    try {
      await page.reload();
      await expect(page.locator('[data-nav-group=transaksi]')).toHaveJSProperty('open', true);
      await expect(page.getByRole('navigation', { name: 'Ruang kerja', exact: true }).locator('a[aria-current=page]')).toHaveText('Penggajian');
      await expect(page.locator('.breadcrumb-area strong')).toHaveText('Transaksi');
    } finally {
      const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
      const deleted = await page.request.post(base + '/transaksi', { form: { _csrf: token, id: savedId } });
      expect(deleted.ok()).toBeTruthy();
    }
    await page.goto(base + '/master/karyawan?new=1');
    await expect(page.locator('.master-form-panel form')).toHaveAttribute('action', prefix + '/master/karyawan');
    await page.getByRole('link', { name: 'Batal', exact: true }).click();
    await expect(page).toHaveURL(base + '/master/karyawan');
    await page.locator('[data-nav-group=laporan] > summary').click();
    await page.getByRole('navigation', { name: 'Ruang kerja', exact: true }).getByRole('link', { name: 'Slip Gaji', exact: true }).click();
    await expect(page).toHaveURL(base + '/laporan?type=slip_karyawan');
    const csrf = await page.request.post(base + '/api/hitung', { form: { hefektif: '21' } });
    expect(csrf.status()).toBe(419);
    expect(csrf.headers()['content-type']).toContain('application/json');
    const archive = await page.request.get(base + '/storage/legacy/export.json');
    expect(archive.status()).toBe(403);
    await page.getByRole('button', { name: 'Keluar akun', exact: true }).click();
    await expect(page).toHaveURL(base + '/login');
    expect(failures).toEqual([]);
    console.log(`PASS: ${folder ? 'folder' : 'domain root'} login, assets, navigation, forms, API, CSRF, archive protection, and logout.`);
  } finally {
    await context?.close();
    server.kill();
    if (server.exitCode === null) await new Promise(resolve => server.once('exit', resolve));
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', args: ['--disable-gpu'] });
  try { await verify(browser, false); await verify(browser, true); }
  finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
