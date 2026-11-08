#!/usr/bin/env node
/**
 * Takes the documentation screenshots with headless Chrome over the DevTools
 * protocol. No dependencies beyond Node 18+ and a Chrome/Chromium binary.
 *
 *   node tools/screenshots.mjs --url=https://files.example.com --user=demo --pass=secret [--out=docs/screenshots] [--chrome=/path/to/chrome] [--dark]
 *
 * The account should be a demo account: everything visible in its folders ends
 * up in the public images.
 */
import { spawn, execSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import http from 'node:http';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = a.match(/^--([^=]+)(?:=(.*))?$/); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const URL_ = (args.url || 'http://127.0.0.1:8090').replace(/\/$/, '');
const USER = args.user || 'demo';
const PASS = args.pass || '';
const OUT = resolve(args.out || 'docs/screenshots');
const WIDTH = Number(args.width || 1440), HEIGHT = Number(args.height || 900);
mkdirSync(OUT, { recursive: true });

function findChrome() {
  if (args.chrome) return args.chrome;
  const candidates = process.platform === 'win32'
    ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', process.env.LOCALAPPDATA + '/Google/Chrome/Application/chrome.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe']
    : process.platform === 'darwin' ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium']
      : ['google-chrome', 'chromium', 'chromium-browser', '/usr/bin/google-chrome-stable'];
  for (const c of candidates) {
    if (c.includes('/') || c.includes('\\')) { if (existsSync(c)) return c; } else { try { execSync(`which ${c}`, { stdio: 'ignore' }); return c; } catch { /* next */ } }
  }
  throw new Error('Chrome not found; pass --chrome=/path/to/chrome');
}

const port = 9000 + Math.floor(Math.random() * 1000);
const chrome = spawn(findChrome(), [`--remote-debugging-port=${port}`, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', `--window-size=${WIDTH},${HEIGHT}`, '--hide-scrollbars', '--force-device-scale-factor=1', `--user-data-dir=${resolve(OUT, '.chrome-profile')}`, 'about:blank'], { stdio: 'ignore' });
process.on('exit', () => { try { chrome.kill(); } catch { /* ignore */ } });

async function getJson(path) {
  return new Promise((res, rej) => http.get(`http://127.0.0.1:${port}${path}`, r => { let d = ''; r.on('data', c => d += c); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));
}
let targets;
for (let i = 0; i < 50; i++) { try { targets = await getJson('/json'); break; } catch { await sleep(200); } }
if (!targets) throw new Error('Chrome did not start');
const page = targets.find(t => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const events = [];
ws.addEventListener('message', e => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { const { res, rej } = pending.get(m.id); pending.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); } else if (m.method) events.push(m); });
const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' ' + (r.exceptionDetails.exception?.description || '')); return r.result.value; };
await send('Page.enable'); await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: false });
if (args.dark) await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'dark' }] });

async function goto(url) { await send('Page.navigate', { url }); await sleep(1200); await evaluate('new Promise(r => document.readyState === "complete" ? r() : addEventListener("load", r))'); }
async function shot(name, { selector, delay = 600, full = false } = {}) {
  await sleep(delay);
  let clip;
  if (selector) {
    const box = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height, scale: 1 }; })()`);
    if (box) clip = box;
  }
  const { data } = await send('Page.captureScreenshot', { format: 'png', clip, captureBeyondViewport: full });
  writeFileSync(resolve(OUT, name + '.png'), Buffer.from(data, 'base64'));
  console.log('✓', name + '.png');
}
async function api(path, body) {
  return evaluate(`fetch(${JSON.stringify(URL_ + '/api/' + path)}, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: ${JSON.stringify(JSON.stringify(body || {}))} }).then(r => r.json())`);
}
const click = (sel) => evaluate(`(() => { const el = document.querySelector(${JSON.stringify(sel)}); if (!el) throw new Error('missing ' + ${JSON.stringify(sel)}); el.click(); return true; })()`);
const waitFor = async (sel, ms = 8000) => { const t = Date.now(); while (Date.now() - t < ms) { if (await evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return true; await sleep(150); } throw new Error('timeout waiting for ' + sel); };

// 1. Sign-in page
await goto(URL_ + '/');
await waitFor('.auth-card');
await shot('01-login');

// 2. Sign in through the API (keeps the credentials out of the DOM) and reload
const login = await api('auth/login', { username: USER, password: PASS, remember: false });
if (!login.ok) throw new Error('Login failed: ' + login.error);
await goto(URL_ + '/#/files/');
await waitFor('.row, .tile, .empty');
await shot('02-files-list');

// 3. Grid and gallery views
await click('.view-switch .tbtn:nth-child(2)'); await shot('03-files-grid', { delay: 900 });
await evaluate(`location.hash = '#/photos'`); await sleep(1500); await shot('04-photos-gallery');

// 4. Details panel
await evaluate(`location.hash = '#/files/'`); await sleep(1200);
await click('.view-switch .tbtn:nth-child(1)');
await evaluate(`(() => { const r = document.querySelector('.row'); if (r) r.click(); })()`);
await evaluate(`(() => { if (document.querySelector('.details[hidden]')) document.querySelector('.toolbar-right .tbtn[title="Details"]').click(); })()`);
await sleep(1200); await shot('05-details-panel');

// 5. Preview of the first image/pdf
await evaluate(`(() => { const r = [...document.querySelectorAll('.row')].find(x => /\\.(jpg|jpeg|png|pdf)$/i.test(x.querySelector('.name-text')?.textContent || '')); if (r) r.dispatchEvent(new MouseEvent('dblclick', { bubbles: true })); })()`);
await sleep(2200); await shot('06-preview');
await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`); await sleep(400);

// 6. Share dialog on the first folder
await evaluate(`(() => { const r = [...document.querySelectorAll('.row')].find(x => x.querySelector('.kind-folder')); if (r) { r.click(); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 400, clientY: 300 })); } })()`);
await sleep(500);
await evaluate(`(() => { const b = [...document.querySelectorAll('.menu-item')].find(x => /Share…/.test(x.textContent)); if (b) b.click(); })()`);
await sleep(1500); await shot('07-share-dialog');
await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`); await sleep(300);

// 7. Link dialog
await evaluate(`(() => { const r = [...document.querySelectorAll('.row')].find(x => x.querySelector('.kind-folder')); if (r) { r.click(); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 400, clientY: 300 })); } })()`);
await sleep(500);
await evaluate(`(() => { const b = [...document.querySelectorAll('.menu-item')].find(x => /Get link/.test(x.textContent)); if (b) b.click(); })()`);
await sleep(1500); await shot('08-link-dialog');
await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`); await sleep(300);

// 8. Search, shared with me, trash, account, admin
await evaluate(`location.hash = '#/search/report'`); await sleep(1800); await shot('09-search');
await evaluate(`location.hash = '#/shared'`); await sleep(1500); await shot('10-shared-with-me');
await evaluate(`location.hash = '#/account/security'`); await sleep(1500); await shot('11-account-security');
await evaluate(`location.hash = '#/account/apps'`); await sleep(1500); await shot('12-webdav-apps');
await evaluate(`location.hash = '#/admin'`); await sleep(1800); await shot('13-admin-overview');
await evaluate(`location.hash = '#/admin/users'`); await sleep(1500); await shot('14-admin-users');
await evaluate(`location.hash = '#/admin/settings/sharing'`); await sleep(1500); await shot('15-admin-settings');
await evaluate(`location.hash = '#/admin/activity'`); await sleep(1500); await shot('16-admin-activity');

// 9. Dark theme
await evaluate(`(() => { localStorage.setItem('fr.prefs', JSON.stringify({ theme: 'dark' })); })()`);
await api('account/profile', { prefs: { theme: 'dark' } });
await goto(URL_ + '/#/files/'); await sleep(1500); await shot('17-dark-mode');
await api('account/profile', { prefs: { theme: 'auto' } });

await api('auth/logout');
console.log('done →', OUT);
ws.close(); chrome.kill(); process.exit(0);
