'use strict';
const { chromium, expect } = require('@playwright/test');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const output = path.join(root, 'storage/qa/print');
const database = 'penggajian_print_test_' + randomBytes(4).toString('hex');
const fixtureScript = path.join(__dirname, 'print-fixtures.php');
const currency = value => {
  const text = String(value);return (text.startsWith('-') ? '-' : '') + text.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  let browser, server, created = false;
  fs.mkdirSync(output, { recursive: true });
  try {
    const setup = spawnSync('php', [fixtureScript, database], { cwd: root, encoding: 'utf8', windowsHide: true });
    if (setup.status !== 0) throw new Error(setup.stderr || setup.stdout);
    created = true;
    const fixture = JSON.parse(setup.stdout);
    fs.writeFileSync(path.join(output, 'fixture.json'), JSON.stringify(fixture, null, 2));
    const portServer = net.createServer();await new Promise(resolve => portServer.listen(0, '127.0.0.1', resolve));
    const port = portServer.address().port;await new Promise(resolve => portServer.close(resolve));
    server = spawn('php', ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${port}`, '-t', root, path.join(root, 'scripts/router.php')], { cwd: root, env: { ...process.env, DB_DATABASE: database }, stdio: 'ignore', windowsHide: true });
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) { try { ready = (await fetch(base + '/login')).ok; } catch {} if (ready) break; await delay(100); }
    if (!ready) throw new Error('Server uji cetak belum siap.');
    browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', args: ['--disable-gpu'] });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
    const errors = [];page.on('pageerror', error => errors.push(error.message));
    await page.clock.setFixedTime(new Date('2026-10-31T09:30:25Z'));
    await page.goto(base + '/login');
    await page.getByLabel('ID pengguna', { exact: true }).fill('qa_print');
    await page.getByLabel('Kata sandi', { exact: true }).fill('Qa!Print-2026-Local');
    await page.getByRole('button', { name: 'Masuk ke ruang kerja', exact: true }).click();
    const report = async (query, filename) => {
      await page.goto(base + '/laporan?' + new URLSearchParams({ bulan: '10', tahun: '2026', view: '1', ...query }));
      await expect(page.getByRole('button', { name: 'Cetak / Simpan PDF', exact: true })).toBeVisible();
      await expect(page.locator('[data-print-timestamp]').first()).toContainText(/\d{2}-[A-Z][a-z]{2}-\d{4}, \d{2}:\d{2}:\d{2} WIB/);
      await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
      for (const stamp of await page.locator('[data-print-timestamp]').all()) await expect(stamp).toHaveText('31-Oct-2026, 16:30:25 WIB');
      await page.pdf({ path: path.join(output, filename + '.pdf'), preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false, scale: 1 });
    };
    await report({ type: 'slip_karyawan', nip: fixture.first.nip }, 'single-slip');
    await expect(page.locator('.slip')).toHaveCount(1);
    await expect(page.locator('.legacy-slip-accounting')).toContainText(fixture.officer);
    await expect(page.locator('.slip-identity')).toContainText(fixture.first.name);
    for (const [field, value] of Object.entries(fixture.first.numbers)) {
      const amount = page.locator(`[data-slip-field="${field}"]`);
      if (await amount.count()) await expect(amount).toHaveText(currency(value));
    }
    await expect(page.locator('.legacy-slip-components tbody tr:nth-child(9) td').first()).toHaveText('Tunjangan Lain');
    await expect(page.locator('.legacy-slip-components tbody tr:nth-child(10) td').first()).toHaveText('Lebih Jam Mengajar');
    await page.screenshot({ path: path.join(output, 'slip-screen.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.setViewportSize({ width: 1600, height: 1000 });
    await report({ type: 'slip_karyawan' }, 'all-slips');await expect(page.locator('.slip')).toHaveCount(2);
    await expect(page.locator('.slip').nth(1).locator('[data-slip-field=tbersih]')).toHaveText('-1.000');
    await report({ type: 'slip_bagian', bagian: 'PA001' }, 'department-slips');await expect(page.locator('.slip')).toHaveCount(1);
    for (const type of ['rekap_bagian', 'rekap_periode', 'rekap_periode_bagian', 'absensi', 'gapok']) {
      await report({ type, dari_bulan: '1', dari_tahun: '2010', sampai_bulan: '10', sampai_tahun: '2026' }, type);
      await expect(page.locator('.report-table')).toBeVisible();
      if (type.startsWith('rekap_')) {
        const totals = type === 'rekap_bagian' ? fixture.monthly_totals : fixture.range_totals;
        for (const value of Object.values(totals)) await expect(page.locator('.report-table tfoot')).toContainText('Rp ' + currency(value));
      }
    }
    await report({ type: 'rekap_periode', dari_bulan: '1', dari_tahun: '2010', sampai_bulan: '10', sampai_tahun: '2026', rincian: '1' }, 'detailed');
    await expect(page.locator('.detailed-print .print-report-section')).toHaveCount(4);
    await page.goto(base + '/transaksi?bulan=10&tahun=2026');
    await page.getByRole('link', { name: 'Slip ' + fixture.first.nip, exact: true }).click();
    await expect(page.locator('.slip')).toHaveCount(1);
    await page.goto(base + '/transaksi/form?id=' + fixture.first.id);
    await page.getByRole('link', { name: 'Pratinjau slip', exact: true }).click();
    await expect(page.locator('.slip')).toHaveCount(1);
    await page.goto(base + '/laporan?type=slip_karyawan&bulan=1&tahun=2099&view=1');
    await expect(page.getByRole('button', { name: 'Cetak / Simpan PDF', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
    console.log('PASS: tujuh laporan, semua pintasan slip, nilai komponen, nama petugas, jam WIB, batch, data kosong dan pratinjau mobile.');
  } finally {
    await browser?.close();
    if (server) { server.kill();if (server.exitCode === null) await new Promise(resolve => server.once('exit', resolve)); }
    if (created) {
      const cleanup = spawnSync('php', [fixtureScript, database, 'cleanup'], { cwd: root, encoding: 'utf8', windowsHide: true });
      if (cleanup.status !== 0) throw new Error('Pembersihan database uji gagal: ' + cleanup.stderr);
    }
  }
})().catch(error => { console.error(error);process.exitCode = 1; });
