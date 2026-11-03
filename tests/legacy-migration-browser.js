'use strict';
const {chromium,expect}=require('@playwright/test');
const {spawn,spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const fs=require('node:fs');
const path=require('node:path');
const net=require('node:net');
const root=path.resolve(__dirname,'..');
const database='penggajian_migration_browser_'+randomBytes(4).toString('hex');
const fixtureScript=path.join(__dirname,'legacy-migration-browser-fixtures.php');
const output=path.join(root,'storage/qa/migration');
const php=process.env.PHP_BINARY||'php';
const fixture=mode=>{
  const result=spawnSync(php,[fixtureScript,database,mode],{cwd:root,encoding:'utf8',windowsHide:true});
  if(result.status!==0)throw new Error(result.stderr||result.stdout||'Fixture failed');
  return result.stdout?JSON.parse(result.stdout):null;
};
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function startServer(folder){
  const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
  const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
  const server=spawn(php,['-d','variables_order=EGPCS','-S',`127.0.0.1:${port}`,'-t',folder?path.dirname(root):root,path.join(root,'scripts/router.php')],{cwd:root,env:{...process.env,DB_DATABASE:database},stdio:'ignore',windowsHide:true});
  const base=`http://127.0.0.1:${port}`+(folder?'/'+path.basename(root):'');
  for(let attempt=0;attempt<60;attempt++){
    try{if((await fetch(base+'/login')).ok)return {base,server};}catch{}
    await wait(100);
  }
  server.kill();throw new Error('Server fixture belum siap.');
}
(async()=>{
  let browser,created=false,activeServer;
  fs.mkdirSync(output,{recursive:true});
  try{
    const data=fixture('setup');created=true;
    browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',args:['--disable-gpu']});
    for(const folder of [false,true]){
      const {base,server}=await startServer(folder);activeServer=server;
      const context=await browser.newContext({viewport:{width:1600,height:1000}});const page=await context.newPage();
      const errors=[];page.on('pageerror',error=>errors.push(error.message));
      page.on('response',response=>{if(response.status()>=400)errors.push(response.status()+' '+response.url());});
      await page.goto(base+'/login');await page.getByLabel('ID pengguna',{exact:true}).fill(data.username);await page.getByLabel('Kata sandi',{exact:true}).fill(data.password);
      await page.getByRole('button',{name:'Masuk ke ruang kerja',exact:true}).click();await expect(page).toHaveURL(base+'/');
      const baseline=fixture('snapshot');
      await page.getByRole('link',{name:'Data & Migrasi',exact:true}).click();
      await expect(page.locator('.migration-inventory-table tbody tr')).toHaveCount(19);
      await expect(page.locator('.migration-inventory-table')).toContainText('Kosong pada sumber');
      await expect(page.locator('.migration-inventory-table')).toContainText('Hanya arsip');
      await expect(page.locator('.migration-evidence')).toContainText('belum dinyatakan lengkap');
      await expect(page.locator('.migration-run').first()).toContainText('Perlu diperiksa');
      const search=page.locator('#migration-employee');await search.focus();
      await expect(page.locator('#migration-employee-options')).toBeVisible();
      await expect(page.locator('.employee-option-name')).toHaveCount(3);
      await search.fill('<Uji>');await search.press('ArrowDown');await page.keyboard.press('Enter');
      await expect(search).toHaveValue(data.nip);await expect(page.locator('#migration-employee-options')).toBeHidden();
      await page.getByRole('button',{name:'Telusuri',exact:true}).click();
      await expect(page.locator('#migration-employee-heading')).toHaveText(data.name);
      await expect(page.locator('.migration-master-list > details')).toHaveCount(14);
      await expect(page.locator('.migration-history-record')).toHaveCount(1);
      await expect(page.locator('.migration-history-record tbody tr')).toHaveCount(29);
      await expect(page.locator('.migration-employee-detail')).toContainText('Tarif harian untuk perhitungan aplikasi berasal dari karyawan.t_harian');
      await expect(page.locator('.migration-employee-detail')).toContainText('Tersedia di histori');
      await expect(page.locator('.migration-employee-detail')).toContainText('-987654321');
      await expect(page.locator('.migration-employee-detail')).toContainText('NULL');
      await expect(page.locator('.migration-employee-detail')).toContainText('Perubahan lokal dipertahankan');
      await expect(page.locator('.migration-employee-detail')).toContainText('Penghapusan lokal dipertahankan');
      await expect(page.locator('#migration-employee-heading > *')).toHaveCount(0);
      const history=page.locator('.migration-history-record');await history.locator('summary').focus();await page.keyboard.press('Enter');await expect(history).toHaveJSProperty('open',true);
      await history.locator('summary').focus();await page.keyboard.press('Space');await expect(history).toHaveJSProperty('open',false);
      const urls=[...new Set(await page.locator('.migration-trace a[href]').evaluateAll(anchors=>anchors.map(anchor=>anchor.href)))];
      for(const href of urls){const response=await page.request.get(href);expect(response.status(),'Migration evidence link '+href).toBe(200);}
      for(const width of [1600,1024,390,320]){
        await page.setViewportSize({width,height:1000});
        await page.goto(base+'/migrasi?nip='+data.nip);await page.locator('.migration-history-record summary').click();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
        const viewport=await page.locator('.migration-record[open] .migration-comparison-table').first().evaluate(table=>({scroll:table.parentElement.scrollWidth,client:table.parentElement.clientWidth}));
        if(width<761)expect(viewport.scroll).toBeGreaterThan(viewport.client);
        await page.evaluate(()=>{document.activeElement?.blur();window.scrollTo(0,0);});
        await page.screenshot({path:path.join(output,`trace-${folder?'folder':'root'}-${width}.png`),fullPage:true});
      }
      await page.setViewportSize({width:1600,height:1000});await page.goto(base+'/migrasi?nip=Karyawan');
      await expect(page.locator('.migration-match-list li')).toHaveCount(3);
      await page.locator('.migration-match-list a').filter({hasText:data.name}).click();await expect(page.locator('#migration-employee-heading')).toHaveText(data.name);
      await page.goto(base+'/migrasi?nip='+data.other_nip);await expect(page.locator('.migration-history-record')).toContainText('2024');
      await page.goto(base+'/migrasi?nip='+data.local_nip);await expect(page.locator('.migration-employee-detail')).toContainText('hanya tersedia pada data lokal');await expect(page.locator('.migration-history-record')).toHaveCount(0);
      await page.goto(base+'/migrasi?nip=NOT_FOUND');await expect(page.locator('.migration-search-results')).toContainText('Tidak ada karyawan');
      await page.getByRole('link',{name:'Reset',exact:true}).click();await expect(page).toHaveURL(base+'/migrasi');
      await search.focus();await expect(page.locator('#migration-employee-options')).toBeVisible();await search.press('Escape');await expect(page.locator('#migration-employee-options')).toBeHidden();
      expect(fixture('snapshot')).toEqual(baseline);expect(errors).toEqual([]);
      await context.close();server.kill();if(server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));activeServer=null;
    }
    console.log('PASS: isolated migration trace, source-empty/history distinction, all field/record links, manual and keyboard employee search, root/subfolder, four widths, and zero GET database writes.');
  }finally{
    await browser?.close();if(activeServer){activeServer.kill();if(activeServer.exitCode===null)await new Promise(resolve=>activeServer.once('exit',resolve));}
    if(created)fixture('cleanup');
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
