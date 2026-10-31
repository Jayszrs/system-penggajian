'use strict';
// Run after the browser suite has prepared the isolated penggajian_test database.
const { chromium, expect } = require('@playwright/test');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

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
    context = await browser.newContext();
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
    await page.getByRole('link', { name: 'Transaksi Penghasilan', exact: true }).click();
    await expect(page).toHaveURL(base + '/transaksi');
    await page.getByRole('link', { name: 'Transaksi baru', exact: true }).click();
    await expect(page.locator('#payroll-form')).toHaveAttribute('action', prefix + '/transaksi/form');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();
    await expect(page.locator('.picker-result').first()).toBeVisible();
    await page.locator('.picker-result').first().click();
    await page.getByLabel('Hari efektif', { exact: true }).fill('21');
    await page.getByLabel('Masuk kerja', { exact: true }).fill('21');
    const calculation = page.waitForResponse(response => response.url() === base + '/api/hitung' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Muat komponen master' }).click();
    expect((await calculation).status()).toBe(200);
    await page.goto(base + '/master/karyawan?new=1');
    await expect(page.locator('.master-form-panel form')).toHaveAttribute('action', prefix + '/master/karyawan');
    await page.getByRole('link', { name: 'Batal', exact: true }).click();
    await expect(page).toHaveURL(base + '/master/karyawan');
    await page.getByRole('link', { name: 'Slip & Laporan', exact: true }).click();
    await expect(page).toHaveURL(base + '/laporan');
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
