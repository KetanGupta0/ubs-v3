// The logo every PDF carries, as a PNG: dompdf has neither the Sora typeface
// nor reliable SVG gradients, so the lockup BrandLogo.vue draws on screen is
// rendered once in Chromium, at eight times its size, with no background.
//
//     node resources/brand/render-pdf-logo.cjs
//
// Run it again if the mark or the wordmark changes; it writes
// public/brand/logo-pdf.png, and logo-pdf-light.png for a dark ground (the
// lockup in white, as BrandLogo's monochrome variant draws it in currentColor),
// which App\Support\PdfBrand reads.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const root = path.resolve(__dirname, '..', '..');
const font = (pkg, file) => 'file://' + path.join(root, 'node_modules', '@fontsource-variable', pkg, 'files', file);

// The same marks, colours and proportions as BrandLogo (horizontal, md) and BrandMark.
const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>
@font-face { font-family: 'Sora Variable'; font-weight: 100 800; src: url('${font('sora', 'sora-latin-wght-normal.woff2')}') format('woff2'); }
@font-face { font-family: 'Inter Variable'; font-weight: 100 900; src: url('${font('inter', 'inter-latin-wght-normal.woff2')}') format('woff2'); }
body { margin: 0; background: transparent; }
#logo { display: inline-flex; align-items: center; gap: 13px; padding: 2px 4px 2px 0; }
.title { font-family: 'Sora Variable'; font-weight: 600; font-size: 18px; line-height: 1; letter-spacing: -0.025em; color: #0B1020; }
.byte { background-image: linear-gradient(100deg, #6366F1, #22D3EE); -webkit-background-clip: text; background-clip: text; color: transparent; }
.tagline { margin-top: 2px; font-family: 'Inter Variable'; font-weight: 500; font-size: 9.92px; line-height: 1; letter-spacing: 0.22em; text-transform: uppercase; color: #5E6992; }
.light .title, .light .tagline { color: #FFFFFF; }
.light .byte { background-image: none; color: #FFFFFF; }
.light .tagline { opacity: .7; }
.light svg * { stroke: #FFFFFF; }
.light svg rect { fill: #FFFFFF; stroke: none; }
</style></head><body>
<div id="logo">
  <!-- The mark's own 64 box trimmed to its painted edges, so the logo lines up
       with the text beside and below it; drawn at the size the site shows it. -->
  <svg width="23.75" height="28.25" viewBox="10.5 1 47.5 56.5" fill="none">
    <defs><linearGradient id="g" x1="8" y1="58" x2="58" y2="4" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#4338CA"/><stop offset="0.55" stop-color="#4F46E5"/><stop offset="1" stop-color="#06B6D4"/>
    </linearGradient></defs>
    <path d="M16 8 L16 36 A16 16 0 0 0 48 36 L48 31" stroke="url(#g)" stroke-width="11" stroke-linecap="butt"/>
    <rect x="42.5" y="15" width="11" height="9" rx="1.5" fill="url(#g)"/>
    <rect x="47" y="1" width="11" height="9" rx="1.5" fill="#06B6D4"/>
  </svg>
  <div><div class="title">unbound<span class="byte">byte</span></div><div class="tagline">Solutions</div></div>
</div>
</body></html>`;

(async () => {
    const page = path.join(require('os').tmpdir(), 'ubs-pdf-logo.html');
    fs.writeFileSync(page, html);

    const browser = await chromium.launch();
    const tab = await (await browser.newContext({ deviceScaleFactor: 8 })).newPage();
    await tab.goto('file://' + page);
    await tab.evaluate(() => document.fonts.ready);

    for (const [file, light] of [['logo-pdf.png', false], ['logo-pdf-light.png', true]]) {
        await tab.evaluate((on) => document.body.classList.toggle('light', on), light);
        const out = path.join(root, 'public', 'brand', file);
        await tab.locator('#logo').screenshot({ path: out, omitBackground: true });
        console.log('Wrote', path.relative(root, out));
    }

    await browser.close();
})();
