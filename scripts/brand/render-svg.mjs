// Render an SVG to PNG with Chromium. Usage: node scripts/brand/render-svg.mjs <svg> <png> <width> [background|transparent]
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
const [svg, png, width, bg = '#ffffff'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage();
const src = readFileSync(svg, 'utf8');
const m = src.match(/viewBox="[-\d.]+ [-\d.]+ ([\d.]+) ([\d.]+)"/);
const w = +width, h = Math.round(w * m[2] / m[1]);
await page.setViewportSize({ width: w, height: h });
await page.setContent(`<html><body style="margin:0;background:${bg}">${src.replace(/width="[\d.]+" height="[\d.]+"/, `width="${w}" height="${h}"`)}</body></html>`);
await page.screenshot({ path: png, omitBackground: bg === 'transparent' });
await browser.close();
