'use strict';
const { expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function verifyCleanHeadings(page, base, fixture, output) {
  const destination = path.join(output, '../clean-headings');
  fs.mkdirSync(destination, { recursive: true });
  const checkHeading = async () => {
    await expect(page.locator('.page-heading h1')).toHaveCount(1);
    await expect(page.locator('.page-heading .eyebrow')).toHaveCount(0);
    await expect(page.locator('.page-heading')).not.toContainText(/Telusuri, periksa|Fondasi yang rapi|Pantau penghasilan|Perbarui identitas|Atur komponen sebagai acuan/);
  };
  const reports = [
    ['slip_karyawan', 'Slip Gaji', 'Slip per Karyawan'],
    ['slip_bagian', 'Slip Gaji per Bagian', 'Slip per Bagian'],
    ['rekap_bagian', 'Rekap Gaji per Bagian', 'Rekap Gaji per Bagian'],
    ['rekap_periode', 'Rekap Gaji per Periode', 'Rekap Rentang Periode'],
    ['rekap_periode_bagian', 'Rekap Gaji per Periode per Bagian', 'Rekap Periode per Bagian'],
    ['absensi', 'Rekap Absensi', 'Rekap Absensi'],
    ['gapok', 'Rekap Gapok', 'Rekap Gaji Pokok'],
  ];
  await page.goto(base + '/laporan');
  const sidebar = page.getByRole('navigation', { name: 'Ruang kerja', exact: true });
  for (const [type, label, title] of reports) {
    await sidebar.getByRole('link', { name: label, exact: true }).click();
    await expect(page).toHaveURL(base + '/laporan?type=' + type);
    await checkHeading();
    await expect(page.locator('.page-heading h1')).toHaveText(title + '.');
    await expect(page.locator('.report-types,.report-type')).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Jenis laporan', exact: true })).toHaveCount(0);
    await expect(page.getByText('7 jenis laporan', { exact: true })).toHaveCount(0);
    await expect(sidebar.locator('a[aria-current=page]')).toHaveText(label);
    await expect(page.locator('.surface.no-print .panel-header h2')).toHaveText('Filter laporan');
    await expect(page.locator('.report-toolbar')).toHaveCount(0);
    await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
    await expect(page.locator('.report-toolbar h2')).toHaveText('Pratinjau');
    await expect(page.locator('.report-toolbar .muted-small')).toContainText(/\d+ (transaksi|karyawan) ditampilkan/);
    await expect(page.getByRole('button', { name: 'Cetak / Simpan PDF', exact: true })).toBeVisible();
    await page.locator('.report-filters').getByRole('link', { name: 'Reset', exact: true }).click();
    await expect(page).toHaveURL(base + '/laporan?type=' + type);
    await expect(sidebar.locator('a[aria-current=page]')).toHaveText(label);
    await expect(page.locator('.report-toolbar')).toHaveCount(0);
  }
  for (const suffix of ['', '?type=tidak_valid']) {
    await page.goto(base + '/laporan' + suffix);
    await expect(page.locator('.page-heading h1')).toHaveText('Slip per Karyawan.');
    await expect(sidebar.locator('a[aria-current=page]')).toHaveText('Slip Gaji');
  }
  const modules = ['bagian', 'karyawan', 'tunj_harian', 'tunj_konsumsi', 'tunj_lembur', 'tunj_infal', 'tunj_overtime', 'tunj_jam', 'p_anak', 'p_bjbs', 'p_bank', 'p_lunak', 'p_lain'];
  for (const module of modules) {
    await page.goto(base + '/master/' + module);
    await checkHeading();
    await expect(page.locator('.page-heading p')).toHaveCount(0);
    await expect(page.locator('.page-heading > a')).toBeVisible();
    if (module === 'tunj_harian') await expect(page.locator('.alert-info')).toContainText('master karyawan');
    if (module === 'p_lain') await expect(page.locator('.alert-info')).toContainText('historis');
  }
  for (const route of ['/', '/transaksi', '/transaksi/form', '/transaksi/form?id=' + fixture.first.id, '/profil', '/migrasi', '/arsip?table=qa_print_archive']) {
    await page.goto(base + route);
    await checkHeading();
    if (route.startsWith('/arsip')) await expect(page.locator('.page-heading')).toContainText('Arsip hanya baca');
    else await expect(page.locator('.page-heading p')).toHaveCount(0);
  }
  await expect(page.locator('.sidebar-note')).toContainText('Ruang kerja Anda');
  await expect(page.locator('.page-footer')).toContainText('Dikelola dengan cermat');
  for (const width of [1600, 1024, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const [type] of reports) {
      await page.goto(base + '/laporan?type=' + type);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
      const heading = await page.locator('.page-heading').boundingBox();
      const filter = await page.locator('.surface.no-print').boundingBox();
      expect(filter.y - heading.y - heading.height).toBeGreaterThanOrEqual(0);
      expect(filter.y - heading.y - heading.height).toBeLessThan(40);
    }
    await page.goto(base + '/laporan?type=rekap_periode');
    await page.screenshot({ path: path.join(destination, `reports-${width}.png`), fullPage: true });
    await page.goto(base + '/master/karyawan');
    await page.screenshot({ path: path.join(destination, `master-${width}.png`), fullPage: true });
    if (width < 761) {
      await page.getByRole('button', { name: 'Buka navigasi', exact: true }).click();
      await page.locator('[data-nav-group=laporan] > summary').click();
      await sidebar.getByRole('link', { name: 'Rekap Absensi', exact: true }).click();
      await expect(page.locator('.page-heading h1')).toHaveText('Rekap Absensi.');
      await page.screenshot({ path: path.join(destination, `sidebar-report-${width}.png`), fullPage: true });
    }
  }
  await page.setViewportSize({ width: 1600, height: 1000 });
  console.log('PASS: sidebar-only report navigation, seven report headings/Reset/preview, clean page headings, retained functional information, and four responsive widths.');
};
