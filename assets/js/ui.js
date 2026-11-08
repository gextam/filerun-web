/* UI helpers: DOM building, icons, dialogs, menus, toasts, formatting. */
import { t } from './i18n.js';

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** h('div.cls#id', {attr, onClick, style:{}, dataset:{}}, ...children) */
export function h(tag, attrs = {}, ...children) {
  if (typeof attrs !== 'object' || attrs === null || attrs instanceof Node || Array.isArray(attrs)) {
    children.unshift(attrs);
    attrs = {};
  }
  const m = tag.match(/^([a-z0-9-]+)?((?:[.#][\w-]+)*)$/i);
  const el = document.createElement((m && m[1]) || 'div');
  if (m && m[2]) {
    for (const part of m[2].match(/[.#][\w-]+/g) || []) {
      if (part[0] === '.') el.classList.add(part.slice(1)); else el.id = part.slice(1);
    }
  }
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class' || k === 'className') { if (v) el.className += (el.className ? ' ' : '') + v; }
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'text') el.textContent = v;
    else if (k === 'value' || k === 'checked' || k === 'selected' || k === 'disabled' || k === 'readOnly' || k === 'indeterminate') el[k] = v;
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  append(el, children);
  return el;
}

export function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false || c === true) continue;
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
export function replace(el, ...children) { clear(el); append(el, children); return el; }

// ---------------------------------------------------------------------------
// Icons (Lucide-style, 24x24, stroke based)
// ---------------------------------------------------------------------------
const P = {
  folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
  'folder-open': '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
  'folder-plus': '<path d="M12 10v6"/><path d="M9 13h6"/><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
  'folder-input': '<path d="M2 9V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H20a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1"/><path d="M2 13h10"/><path d="m9 16 3-3-3-3"/>',
  file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
  'file-plus': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M9 15h6"/><path d="M12 18v-6"/>',
  'file-text': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
  image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
  film: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/>',
  music: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
  code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
  archive: '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
  'cloud-upload': '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
  share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  'link-off': '<path d="M9 17H7A5 5 0 0 1 7 7"/><path d="M15 7h2a5 5 0 0 1 4 8"/><line x1="8" x2="12" y1="12" y2="12"/><line x1="2" x2="22" y1="2" y2="22"/>',
  star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
  trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
  'more-h': '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
  'more-v': '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
  grid: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
  list: '<line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/>',
  gallery: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
  user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
  settings: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
  lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  unlock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
  message: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
  info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
  history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
  'chevron-right': '<path d="m9 18 6-6-6-6"/>',
  'chevron-down': '<path d="m6 9 6 6 6-6"/>',
  'chevron-left': '<path d="m15 18-6-6 6-6"/>',
  'chevron-up': '<path d="m18 15-6-6-6 6"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
  minus: '<path d="M5 12h14"/>',
  edit: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
  copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
  scissors: '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
  clipboard: '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
  refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
  eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
  'eye-off': '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>',
  external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  home: '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
  clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
  filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
  sort: '<path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="m21 8-4-4-4 4"/><path d="M17 4v16"/>',
  menu: '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
  'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
  shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
  key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
  smartphone: '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
  mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
  save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
  'rotate-cw': '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
  'rotate-ccw': '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
  'flip-h': '<path d="M8 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h3"/><path d="M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3"/><path d="M12 20v2"/><path d="M12 14v2"/><path d="M12 8v2"/><path d="M12 2v2"/>',
  'flip-v': '<path d="M21 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3"/><path d="M21 16v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3"/><path d="M4 12H2"/><path d="M10 12H8"/><path d="M16 12h-2"/><path d="M22 12h-2"/>',
  crop: '<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/>',
  maximize: '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>',
  'zoom-in': '<circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="11" x2="11" y1="8" y2="14"/><line x1="8" x2="14" y1="11" y2="11"/>',
  'zoom-out': '<circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="8" x2="14" y1="11" y2="11"/>',
  play: '<polygon points="6 3 20 12 6 21 6 3"/>',
  pause: '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
  monitor: '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
  alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  help: '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
  calendar: '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
  activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
  database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
  server: '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
  'bar-chart': '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
  inbox: '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
  send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  'arrow-up': '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
  'arrow-left': '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
  'arrow-right': '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
  'arrow-down': '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
  'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
  'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
  columns: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
  layout: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
  terminal: '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
  wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
  briefcase: '<rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
  flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
  type: '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" x2="15" y1="20" y2="20"/><line x1="12" x2="12" y1="4" y2="20"/>',
  box: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  book: '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
  'hard-drive': '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
  'log-in': '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
  pin: '<line x1="12" x2="12" y1="17" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/>',
  slash: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
  printer: '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
  compass: '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
  'map-pin': '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
  camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
  sliders: '<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>',
  'panel-right': '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M15 3v18"/>',
  'panel-left': '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
  expand: '<path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 16.2V21m0 0h4.8M3 21l6-6"/><path d="M21 7.8V3m0 0h-4.8M21 3l-6 6"/><path d="M3 7.8V3m0 0h4.8M3 3l6 6"/>',
  undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
  'file-search': '<path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M4.268 21a2 2 0 0 0 1.727 1H18a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v3"/><path d="m9 18-1.5-1.5"/><circle cx="5" cy="14" r="3"/>',
};

export function icon(name, cls = '') {
  const path = P[name] || P.file;
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '2');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  svg.classList.add('ic');
  if (cls) svg.classList.add(...cls.split(' '));
  svg.innerHTML = path;
  return svg;
}

const KIND_ICONS = { folder: 'folder', image: 'image', raw: 'camera', video: 'film', audio: 'music', pdf: 'file-text', text: 'file-text', code: 'code', markdown: 'file-text', doc: 'file-text', sheet: 'layout', slide: 'monitor', archive: 'archive', font: 'type', ebook: 'book', model: 'box', exe: 'terminal', other: 'file' };
const KIND_COLORS = { folder: 'var(--accent)', image: '#0ea5e9', raw: '#0369a1', video: '#8b5cf6', audio: '#ec4899', pdf: '#ef4444', text: '#64748b', code: '#10b981', markdown: '#475569', doc: '#2563eb', sheet: '#16a34a', slide: '#ea580c', archive: '#a16207', font: '#7c3aed', ebook: '#b45309', model: '#0d9488', exe: '#334155', other: '#94a3b8' };

/** Coloured file-type icon. */
export function fileIcon(item, size = 'md') {
  const kind = item.is_dir ? 'folder' : (item.kind || 'other');
  const el = h('span.ficon.ficon-' + size + ' kind-' + kind, { style: { color: KIND_COLORS[kind] || KIND_COLORS.other } });
  el.appendChild(icon(KIND_ICONS[kind] || 'file'));
  if (!item.is_dir && item.ext && size !== 'sm') el.appendChild(h('span.ficon-ext', item.ext.slice(0, 4)));
  return el;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------
export function fmtSize(bytes, decimals = 1) {
  bytes = Number(bytes) || 0;
  const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let i = 0;
  while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
  return (i === 0 ? Math.round(bytes) : bytes.toFixed(decimals)) + ' ' + u[i];
}

export function toDate(v) {
  if (v === null || v === undefined || v === '') return null;
  if (v instanceof Date) return v;
  if (typeof v === 'number') return new Date(v * 1000);
  const s = String(v);
  // MySQL "Y-m-d H:i:s" is UTC.
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s)) return new Date(s.replace(' ', 'T') + 'Z');
  return new Date(s);
}

export function fmtDate(v, opts = {}) {
  const d = toDate(v);
  if (!d || isNaN(d)) return '';
  return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', ...opts });
}

export function fmtDateShort(v) {
  const d = toDate(v);
  if (!d || isNaN(d)) return '';
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function relTime(v) {
  const d = toDate(v);
  if (!d || isNaN(d)) return '';
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 45) return t('just now');
  const units = [[60, 'minute'], [3600, 'hour'], [86400, 'day'], [604800, 'week'], [2592000, 'month'], [31536000, 'year']];
  for (let i = 0; i < units.length; i++) {
    const [sec, name] = units[i];
    const next = units[i + 1];
    if (!next || diff < next[0]) {
      const n = Math.max(1, Math.floor(diff / (i === 0 ? 60 : sec)));
      const unit = i === 0 ? 'minute' : name;
      return t('{n} {unit} ago', { n, unit: n === 1 ? t(unit) : t(unit + 's') });
    }
  }
  return fmtDate(d);
}

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function debounce(fn, ms = 250) {
  let tm;
  return (...a) => { clearTimeout(tm); tm = setTimeout(() => fn(...a), ms); };
}

export function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(() => true, () => fallbackCopy(text));
  return Promise.resolve(fallbackCopy(text));
}
function fallbackCopy(text) {
  const ta = h('textarea', { value: text, style: { position: 'fixed', opacity: 0 } });
  document.body.appendChild(ta); ta.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  ta.remove();
  return ok;
}

// ---------------------------------------------------------------------------
// Toasts
// ---------------------------------------------------------------------------
let toastHost;
export function toast(message, type = 'info', ms = 4000) {
  if (!toastHost) { toastHost = h('div.toasts'); document.body.appendChild(toastHost); }
  const el = h('div.toast.toast-' + type, icon(type === 'error' ? 'x-circle' : type === 'success' ? 'check-circle' : type === 'warn' ? 'alert' : 'info'), h('span', message), h('button.toast-x', { onClick: () => el.remove(), 'aria-label': t('Close') }, icon('x')));
  toastHost.appendChild(el);
  if (ms > 0) setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, ms);
  return el;
}

// ---------------------------------------------------------------------------
// Dialogs
// ---------------------------------------------------------------------------
const dialogStack = [];

/**
 * dialog({title, body (node|fn(dlg)), buttons:[{label, primary, danger, onClick(dlg)=>bool|Promise}], width, onClose, closable})
 * Returns the dialog element; dlg.close(result) resolves dlg.result promise.
 */
export function dialog(o = {}) {
  const overlay = h('div.overlay');
  const dlg = h('div.dialog', { role: 'dialog', 'aria-modal': 'true', style: o.width ? { width: o.width, maxWidth: '96vw' } : {} });
  let resolve;
  dlg.result = new Promise(r => { resolve = r; });
  dlg.close = (result) => {
    if (!overlay.parentNode) return;
    overlay.classList.add('out');
    setTimeout(() => overlay.remove(), 150);
    const i = dialogStack.indexOf(dlg); if (i >= 0) dialogStack.splice(i, 1);
    o.onClose && o.onClose(result);
    resolve(result);
  };
  const head = h('div.dialog-head', h('div.dialog-title', o.title || ''), o.closable !== false ? h('button.icon-btn', { onClick: () => dlg.close(null), 'aria-label': t('Close') }, icon('x')) : null);
  const body = h('div.dialog-body');
  const content = typeof o.body === 'function' ? o.body(dlg) : o.body;
  if (content) append(body, [content]);
  dlg.body = body;
  const foot = h('div.dialog-foot');
  for (const b of o.buttons || []) {
    const btn = h('button', { class: 'btn' + (b.primary ? ' btn-primary' : '') + (b.danger ? ' btn-danger' : '') + (b.cls ? ' ' + b.cls : ''), type: 'button', disabled: b.disabled }, b.icon ? icon(b.icon) : null, b.label);
    btn.addEventListener('click', async () => {
      if (!b.onClick) return dlg.close(b.value !== undefined ? b.value : true);
      btn.disabled = true; btn.classList.add('busy');
      try {
        const r = await b.onClick(dlg, btn);
        if (r !== false) dlg.close(r === undefined ? true : r);
      } catch (e) {
        toast(e.message || String(e), 'error');
      } finally { btn.disabled = false; btn.classList.remove('busy'); }
    });
    if (b.primary) dlg.primaryBtn = btn;
    foot.appendChild(btn);
  }
  append(dlg, [head, body, (o.buttons && o.buttons.length) ? foot : null]);
  overlay.appendChild(dlg);
  overlay.addEventListener('mousedown', e => { if (e.target === overlay && o.closable !== false && !o.modal) dlg.close(null); });
  document.body.appendChild(overlay);
  dialogStack.push(dlg);
  dlg.addEventListener('keydown', e => {
    if (e.key === 'Enter' && dlg.primaryBtn && !e.shiftKey && !(e.target.tagName === 'TEXTAREA') && !e.target.closest('.no-enter')) { e.preventDefault(); dlg.primaryBtn.click(); }
  });
  setTimeout(() => { const f = dlg.querySelector('[autofocus],input:not([type=checkbox]),textarea,select') || dlg.primaryBtn || dlg.querySelector('button.btn'); if (f) f.focus(); if (f && f.select && f.tagName === 'INPUT' && f.type === 'text') f.select(); }, 30);
  return dlg;
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const top = dialogStack[dialogStack.length - 1];
    if (top) { e.stopPropagation(); top.close(null); }
    closeMenus();
  }
}, true);

export function confirm(message, o = {}) {
  return dialog({
    title: o.title || t('Please confirm'),
    body: h('p.dialog-text', message),
    width: '420px',
    buttons: [{ label: o.cancelLabel || t('Cancel'), value: false }, { label: o.okLabel || t('OK'), primary: true, danger: !!o.danger, value: true }],
  }).result.then(r => r === true);
}

export function prompt(title, o = {}) {
  const input = h(o.multiline ? 'textarea.input' : 'input.input', { value: o.value || '', placeholder: o.placeholder || '', type: o.type || 'text', rows: o.rows || 6 });
  const dlg = dialog({
    title,
    body: h('div', o.label ? h('label.lbl', o.label) : null, input, o.hint ? h('div.hint', o.hint) : null),
    width: o.width || '440px',
    buttons: [{ label: t('Cancel'), value: null }, { label: o.okLabel || t('OK'), primary: true, onClick: () => { const v = input.value; if (o.required && !v.trim()) { input.focus(); return false; } return v; } }],
  });
  if (o.selectBase && input.tagName === 'INPUT') {
    setTimeout(() => { const v = input.value; const dot = v.lastIndexOf('.'); input.focus(); input.setSelectionRange(0, dot > 0 ? dot : v.length); }, 40);
  }
  return dlg.result;
}

export function alertDialog(title, message) {
  return dialog({ title, body: typeof message === 'string' ? h('p.dialog-text', message) : message, width: '460px', buttons: [{ label: t('OK'), primary: true }] }).result;
}

// ---------------------------------------------------------------------------
// Menus (context menus and dropdowns)
// ---------------------------------------------------------------------------
let openMenu = null;
export function closeMenus() { if (openMenu) { openMenu.remove(); openMenu = null; } }
document.addEventListener('mousedown', e => { if (openMenu && !openMenu.contains(e.target)) closeMenus(); });
window.addEventListener('blur', closeMenus);
window.addEventListener('resize', closeMenus);

/**
 * items: [{label, icon, onClick, disabled, danger, checked, submenu:[...], sep:true, hint}]
 * anchor: {x, y} or an element.
 */
export function menu(items, anchor, o = {}) {
  closeMenus();
  const m = h('div.menu', { role: 'menu' });
  const build = (list, host) => {
    for (const it of list) {
      if (!it) continue;
      if (it.sep) { host.appendChild(h('div.menu-sep')); continue; }
      if (it.header) { host.appendChild(h('div.menu-header', it.header)); continue; }
      const row = h('button.menu-item', { type: 'button', disabled: !!it.disabled, class: (it.danger ? 'danger' : '') + (it.checked ? ' checked' : '') },
        h('span.menu-ic', it.checked ? icon('check') : (it.icon ? icon(it.icon) : null), it.color ? h('span.label-dot', { style: { background: it.color } }) : null),
        h('span.menu-label', it.label), it.hint ? h('span.menu-hint', it.hint) : null, it.submenu ? icon('chevron-right', 'menu-arrow') : null);
      if (it.submenu) {
        row.addEventListener('mouseenter', () => {
          $$('.submenu', m).forEach(s => s.remove());
          const sub = h('div.menu.submenu');
          build(it.submenu, sub);
          m.appendChild(sub);
          const r = row.getBoundingClientRect();
          position(sub, { x: r.right - 2, y: r.top - 6 }, true);
        });
      } else {
        row.addEventListener('mouseenter', () => $$('.submenu', m).forEach(s => s.remove()));
        row.addEventListener('click', e => { e.stopPropagation(); closeMenus(); it.onClick && it.onClick(e); });
      }
      host.appendChild(row);
    }
  };
  build(items, m);
  document.body.appendChild(m);
  openMenu = m;
  let pt;
  if (anchor instanceof Element) {
    const r = anchor.getBoundingClientRect();
    pt = { x: o.align === 'right' ? r.right : r.left, y: r.bottom + 4, alignRight: o.align === 'right' };
  } else pt = anchor;
  position(m, pt);
  m.querySelector('.menu-item:not([disabled])')?.focus({ preventScroll: true });
  m.addEventListener('keydown', e => {
    const items = $$('.menu-item:not([disabled])', m);
    const i = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
  });
  return m;
}

function position(el, pt, isSub = false) {
  const vw = window.innerWidth, vh = window.innerHeight;
  const w = el.offsetWidth, hgt = el.offsetHeight;
  let x = pt.alignRight ? pt.x - w : pt.x, y = pt.y;
  if (x + w > vw - 8) x = isSub ? pt.x - w - (el.previousSibling ? 0 : 0) - 4 : vw - w - 8;
  if (x < 8) x = 8;
  if (y + hgt > vh - 8) y = Math.max(8, vh - hgt - 8);
  el.style.left = x + 'px';
  el.style.top = y + 'px';
}

/** Simple dropdown button helper. */
export function dropdown(button, itemsFn, o = {}) {
  button.addEventListener('click', e => { e.stopPropagation(); if (openMenu) { closeMenus(); return; } menu(itemsFn(), button, o); });
  return button;
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------
/** field({label, name, type, value, options:[{value,label}], hint, placeholder, required, attrs}) */
export function field(f) {
  let input;
  const common = { name: f.name, id: 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 7), placeholder: f.placeholder || '', required: !!f.required, disabled: !!f.disabled, ...(f.attrs || {}) };
  if (f.type === 'select') {
    input = h('select.input', common, (f.options || []).map(op => h('option', { value: op.value, selected: String(op.value) === String(f.value ?? '') }, op.label)));
  } else if (f.type === 'textarea') {
    input = h('textarea.input', { ...common, rows: f.rows || 4 }, f.value ?? '');
  } else if (f.type === 'checkbox' || f.type === 'switch') {
    input = h('input', { ...common, type: 'checkbox', checked: !!f.value });
    const wrap = h('label.check' + (f.type === 'switch' ? '.switch' : ''), input, h('span.check-ui'), h('span.check-label', f.label, f.hint ? h('span.hint', f.hint) : null));
    wrap.input = input;
    return wrap;
  } else {
    input = h('input.input', { ...common, type: f.type || 'text', value: f.value ?? '', autocomplete: f.autocomplete || 'off', step: f.step, min: f.min, max: f.max });
  }
  const wrap = h('div.field' + (f.cls ? '.' + f.cls : ''), f.label ? h('label.lbl', { for: common.id }, f.label) : null, input, f.hint ? h('div.hint', f.hint) : null);
  wrap.input = input;
  return wrap;
}

/** Collect values from inputs with name attributes inside a root. */
export function formValues(root) {
  const out = {};
  for (const el of $$('[name]', root)) {
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'number') out[el.name] = el.value === '' ? '' : Number(el.value);
    else out[el.name] = el.value;
  }
  return out;
}

export function spinner(cls = '') { return h('span.spinner' + (cls ? '.' + cls : '')); }

export function emptyState(iconName, title, text, action) {
  return h('div.empty', icon(iconName, 'empty-ic'), h('div.empty-title', title), text ? h('div.empty-text', text) : null, action || null);
}

/** Tabs: tabs=[{id,label,icon}], onChange(id). Returns {el, set}. */
export function tabs(list, active, onChange) {
  const el = h('div.tabs', { role: 'tablist' });
  const set = (id) => { $$('.tab', el).forEach(b => b.classList.toggle('active', b.dataset.id === id)); };
  for (const tb of list) {
    el.appendChild(h('button.tab', { type: 'button', dataset: { id: tb.id }, class: tb.id === active ? 'active' : '', onClick: () => { set(tb.id); onChange(tb.id); } }, tb.icon ? icon(tb.icon) : null, h('span', tb.label), tb.badge ? h('span.badge', tb.badge) : null));
  }
  return { el, set };
}

export function avatar(user, size = 'md') {
  const name = (user && (user.name || user.username)) || '?';
  const initials = name.trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase();
  let hash = 0; for (const c of name) hash = (hash * 31 + c.charCodeAt(0)) >>> 0;
  const hue = hash % 360;
  const el = h('span.avatar.avatar-' + size, { style: { background: `hsl(${hue} 45% 45%)` }, title: name }, initials);
  if (user && user.avatar) { const img = h('img', { src: user.avatar, alt: name }); el.textContent = ''; el.appendChild(img); }
  return el;
}

export function labelDot(label, size = '') {
  const colors = { red: '#ef4444', orange: '#f97316', yellow: '#eab308', green: '#22c55e', blue: '#3b82f6', purple: '#a855f7', gray: '#9ca3af' };
  return h('span.label-dot' + (size ? '.' + size : ''), { style: { background: colors[label] || '#9ca3af' }, title: t(label) });
}

export function stars(value, onChange) {
  const el = h('span.stars', { role: onChange ? 'radiogroup' : null });
  for (let i = 1; i <= 5; i++) {
    const s = h('button.star-btn', { type: 'button', class: i <= (value || 0) ? 'on' : '', disabled: !onChange, 'aria-label': i + '/5' }, icon('star'));
    if (onChange) s.addEventListener('click', () => onChange(i === value ? 0 : i));
    el.appendChild(s);
  }
  return el;
}

export function progressBar(pct, cls = '') {
  return h('div.progress' + (cls ? '.' + cls : ''), h('div.progress-bar', { style: { width: Math.max(0, Math.min(100, pct)) + '%' } }));
}

/** Load a script once. */
const loaded = {};
export function loadScript(src) {
  if (loaded[src]) return loaded[src];
  loaded[src] = new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = src; s.onload = () => res(); s.onerror = () => { delete loaded[src]; rej(new Error('Failed to load ' + src)); };
    document.head.appendChild(s);
  });
  return loaded[src];
}
export function loadCss(href) {
  if (loaded[href]) return loaded[href];
  loaded[href] = new Promise((res) => { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; l.onload = () => res(); l.onerror = () => res(); document.head.appendChild(l); });
  return loaded[href];
}

export function kbd(keys) { return h('span.kbds', keys.split('+').map(k => h('kbd', k))); }
