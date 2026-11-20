// The ornaments the premium certificate layouts use, as PNGs: a gold seal (on
// ribbons, and plain), a gold corner flourish in its four turns, and a laurel
// wreath. dompdf draws neither SVG gradients nor text on a curve, so they are
// drawn once in Chromium, on a transparent background, at print resolution
// for the size each layout prints them.
//
//     node resources/certificates/render-art.cjs
//
// Writes resources/certificates/art/*.png, which App\Support\CertificateArt reads.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const os = require('os');

const root = path.resolve(__dirname, '..', '..');
const out = path.join(__dirname, 'art');
const cinzel = 'file://' + path.join(root, 'resources', 'fonts', 'certificates', 'Cinzel-SemiBold.ttf');

const gold = `
  <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#7A5A17"/><stop offset=".3" stop-color="#D9B456"/>
    <stop offset=".55" stop-color="#A97E27"/><stop offset=".8" stop-color="#F1D88A"/><stop offset="1" stop-color="#8C6A22"/>
  </linearGradient>`;

/* ------------------------------------------------------------- the seal */
// The brand mark (BrandMark.vue's paths) in ivory on a gold medal, the name
// along the top of the ring and "Certified" upright along the bottom.
function seal(ribbons) {
    const cx = 200, cy = 190, points = [];
    for (let i = 0; i < 72; i++) {
        const r = i % 2 === 0 ? 168 : 153;
        const a = (Math.PI * 2 * i) / 72 - Math.PI / 2;
        points.push(`${(cx + r * Math.cos(a)).toFixed(2)},${(cy + r * Math.sin(a)).toFixed(2)}`);
    }
    const mark = `<g transform="translate(${cx - 44.5} ${cy - 44}) scale(1.5)" fill="none">
        <path d="M16 8 L16 36 A16 16 0 0 0 48 36 L48 31" stroke="#FFF8E6" stroke-width="11"/>
        <rect x="42.5" y="15" width="11" height="9" rx="1.5" fill="#FFF8E6"/>
        <rect x="47" y="1" width="11" height="9" rx="1.5" fill="#FFF8E6"/></g>`;
    const ribbon = (d) => `<polygon points="${d}" fill="url(#ribbon)"/><polygon points="${d}" fill="none" stroke="#C9A44C" stroke-width="2.5"/>`;
    const diamond = (x) => `<rect x="${x - 5}" y="${cy - 5}" width="10" height="10" transform="rotate(45 ${x} ${cy})" fill="#5A4210"/>`;

    return `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="${ribbons ? 500 : 380}" viewBox="0 0 400 ${ribbons ? 500 : 380}">
      <defs>${gold}
        <linearGradient id="ribbon" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#26306B"/><stop offset="1" stop-color="#141A3F"/></linearGradient>
        <radialGradient id="face" cx=".38" cy=".32" r=".8"><stop offset="0" stop-color="#F7E6A8"/><stop offset=".55" stop-color="#CFA144"/><stop offset="1" stop-color="#8C6A22"/></radialGradient>
        <path id="top" d="M ${cx - 109} ${cy} A 109 109 0 0 1 ${cx + 109} ${cy}"/>
        <path id="bottom" d="M ${cx - 127} ${cy} A 127 127 0 0 0 ${cx + 127} ${cy}"/>
      </defs>
      ${ribbons ? ribbon('148,250 102,478 136,456 160,492 198,290') + ribbon('252,250 298,478 264,456 240,492 202,290') : ''}
      <polygon points="${points.join(' ')}" fill="url(#gold)"/>
      <circle cx="${cx}" cy="${cy}" r="147" fill="url(#face)" stroke="#7A5A17" stroke-width="2"/>
      <circle cx="${cx}" cy="${cy}" r="139" fill="none" stroke="#FFF1C2" stroke-opacity=".75" stroke-width="1.5"/>
      <circle cx="${cx}" cy="${cy}" r="97" fill="none" stroke="#7A5A17" stroke-width="2.5"/>
      <circle cx="${cx}" cy="${cy}" r="91" fill="none" stroke="#FFF1C2" stroke-opacity=".7" stroke-width="1"/>
      <text font-family="Cinzel" font-size="19" fill="#4E3A0E" text-anchor="middle">
        <textPath href="#top" startOffset="50%" textLength="300" lengthAdjust="spacing">UNBOUNDBYTE SOLUTIONS</textPath>
      </text>
      <text font-family="Cinzel" font-size="19" fill="#4E3A0E" text-anchor="middle" letter-spacing="6">
        <textPath href="#bottom" startOffset="50%">CERTIFIED</textPath>
      </text>
      ${diamond(cx - 118)}${diamond(cx + 118)}
      ${mark}
    </svg>`;
}

/* ------------------------------------------------------- a gold corner */
// Sits with the diamond on the corner of a layout's outer rule; the scrolls
// reach in past the inner rule. The rules themselves are the layout's own.
function corner(turn) {
    const flip = { tl: '', tr: 'translate(200 0) scale(-1 1)', bl: 'translate(0 200) scale(1 -1)', br: 'translate(200 200) scale(-1 -1)' }[turn];
    return `<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
      <defs>${gold}</defs>
      <g transform="${flip}">
        <rect x="5" y="5" width="18" height="18" transform="rotate(45 14 14)" fill="url(#gold)"/>
        <circle cx="39" cy="39" r="4.5" fill="url(#gold)"/>
        <path d="M47 47 C 72 52, 92 72, 98 98 C 72 92, 52 72, 47 47 Z" fill="url(#gold)"/>
        <g fill="none" stroke="url(#gold)" stroke-linecap="round" stroke-width="3">
          <path d="M 50 39 C 82 39, 108 44, 117 62 C 124 77, 109 89, 99 79 C 92 71, 101 63, 107 69"/>
          <path d="M 39 50 C 39 82, 44 108, 62 117 C 77 124, 89 109, 79 99 C 71 92, 63 101, 69 107"/>
        </g>
        <circle cx="104" cy="104" r="3.5" fill="url(#gold)"/>
      </g>
    </svg>`;
}

/* ------------------------------------------------------ a laurel wreath */
function laurel() {
    const leaves = [];
    const branch = (side) => {
        // A quadratic curve from the bottom centre up and out to one side.
        const p0 = [250, 370], p1 = [side * -210 + 250, 340], p2 = [side * -175 + 250, 95];
        for (let i = 1; i <= 15; i++) {
            const t = i / 16;
            const x = (1 - t) ** 2 * p0[0] + 2 * (1 - t) * t * p1[0] + t ** 2 * p2[0];
            const y = (1 - t) ** 2 * p0[1] + 2 * (1 - t) * t * p1[1] + t ** 2 * p2[1];
            const dx = 2 * (1 - t) * (p1[0] - p0[0]) + 2 * t * (p2[0] - p1[0]);
            const dy = 2 * (1 - t) * (p1[1] - p0[1]) + 2 * t * (p2[1] - p1[1]);
            const angle = (Math.atan2(dy, dx) * 180) / Math.PI;
            const size = 1 - t * 0.35;
            for (const lean of [38, -38]) {
                leaves.push(`<ellipse cx="0" cy="0" rx="${(25 * size).toFixed(1)}" ry="${(9 * size).toFixed(1)}" fill="url(#gold)"
                    transform="translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${(angle + lean * side).toFixed(1)}) translate(${(20 * size).toFixed(1)} 0)"/>`);
            }
        }
        return `<path d="M${p0} Q${p1} ${p2}" fill="none" stroke="url(#gold)" stroke-width="3"/>`;
    };
    const stems = branch(1) + branch(-1);
    return `<svg xmlns="http://www.w3.org/2000/svg" width="500" height="320" viewBox="0 80 500 320">
      <defs>${gold}</defs>${stems}${leaves.join('')}
      <path d="M232 372 C 214 392, 196 394, 186 384 M268 372 C 286 392, 304 394, 314 384" fill="none" stroke="url(#gold)" stroke-width="5" stroke-linecap="round"/>
      <circle cx="250" cy="370" r="9" fill="url(#gold)"/>
    </svg>`;
}

(async () => {
    fs.mkdirSync(out, { recursive: true });
    // name: [svg, scale]. Each is printed at most this many CSS pixels across
    // at about 300 dpi: the seal some 34 mm wide, a corner 28 mm, the wreath 46 mm.
    const art = {
        'seal-ribbons': [seal(true), 1],
        'seal': [seal(false), 1],
        'corner-tl': [corner('tl'), 2], 'corner-tr': [corner('tr'), 2], 'corner-bl': [corner('bl'), 2], 'corner-br': [corner('br'), 2],
        'laurel': [laurel(), 1.2],
    };

    for (const old of fs.readdirSync(out)) if (old.endsWith('.png')) fs.unlinkSync(path.join(out, old));

    const browser = await chromium.launch();
    for (const [name, [svg, scale]] of Object.entries(art)) {
        const page = await (await browser.newContext({ deviceScaleFactor: scale })).newPage();
        const file = path.join(os.tmpdir(), `ubs-art-${name}.html`);
        fs.writeFileSync(file, `<!DOCTYPE html><html><head><style>
            @font-face { font-family: 'Cinzel'; src: url('${cinzel}') format('truetype'); }
            body { margin: 0; background: transparent; } svg { display: block; }
        </style></head><body>${svg}</body></html>`);
        await page.goto('file://' + file);
        await page.evaluate(() => document.fonts.ready);
        await page.locator('svg').screenshot({ path: path.join(out, `${name}.png`), omitBackground: true });
        await page.close();
        console.log('Wrote', path.relative(root, path.join(out, `${name}.png`)));
    }
    await browser.close();
})();
