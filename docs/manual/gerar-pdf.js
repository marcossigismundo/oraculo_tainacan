const puppeteer = require('puppeteer-core');
const path = require('path');
(async () => {
  const src = process.argv[2], out = process.argv[3];
  const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new' });
  const page = await browser.newPage();
  await page.goto('file:///' + src, { waitUntil: 'networkidle0' });
  await page.pdf({ path: out, format: 'A4', printBackground: true, displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: '<div style="font-size:8px;color:#5b6b69;width:100%;padding:0 16mm;display:flex;justify-content:space-between;font-family:Segoe UI,Arial"><span>Oráculo Tainacan 2.13.1 — como funciona</span><span><span class="pageNumber"></span>/<span class="totalPages"></span></span></div>',
    margin: { top: '16mm', bottom: '18mm', left: '16mm', right: '16mm' } });
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
