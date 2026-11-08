/* The viewer: images, video, audio, PDF, text/code editor, markdown, office
 * documents, archives, fonts and an image editor. */
import { App, parentPath } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, $, $$, icon, fileIcon, replace, clear, dialog, confirm, toast, menu, fmtSize, fmtDate, spinner, loadScript, loadCss, prompt, escapeHtml, kbd } from './ui.js';

let overlay = null;
let state = null;

export function isPreviewOpen() { return !!overlay; }
export function canPreview(item) { return !item.is_dir; }

const CM_MODES = { js: 'javascript', mjs: 'javascript', jsx: 'jsx', ts: 'javascript', tsx: 'jsx', json: { name: 'javascript', json: true }, html: 'htmlmixed', htm: 'htmlmixed', xml: 'xml', svg: 'xml', css: 'css', scss: 'css', less: 'css', php: 'php', py: 'python', md: 'markdown', markdown: 'markdown', sh: 'shell', bash: 'shell', sql: 'sql', yml: 'yaml', yaml: 'yaml', c: 'text/x-csrc', h: 'text/x-csrc', cpp: 'text/x-c++src', java: 'text/x-java', cs: 'text/x-csharp', go: 'go', rs: 'rust', rb: 'ruby', ini: 'properties', conf: 'properties', toml: 'toml', dockerfile: 'dockerfile', vue: 'htmlmixed', txt: null };

/**
 * openPreview(items, index, {edit, imageEdit, office, folderPerms, onChange, link})
 */
export function openPreview(items, index, opts = {}) {
  closePreview();
  state = { items, index, opts, dirty: false, editor: null, zoom: 1 };
  overlay = h('div.viewer', { role: 'dialog', 'aria-modal': 'true' });
  document.body.appendChild(overlay);
  document.body.classList.add('viewer-open');
  document.addEventListener('keydown', onKey, true);
  render();
}

export function closePreview() {
  if (!overlay) return;
  if (state && state.dirty && !window.confirm(t('Discard unsaved changes?'))) return;
  if (state && state.cleanup) { try { state.cleanup(); } catch (e) { /* ignore */ } }
  overlay.remove(); overlay = null; state = null;
  document.body.classList.remove('viewer-open');
  document.removeEventListener('keydown', onKey, true);
}

function onKey(e) {
  if (!overlay) return;
  if (e.target.closest('.CodeMirror, textarea, input, select') && !(e.ctrlKey || e.metaKey)) return;
  if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if ($('.overlay')) return; closePreview(); }
  else if (e.key === 'ArrowRight' && !state.editable && !e.target.closest('input,textarea,.CodeMirror')) { e.preventDefault(); nav(1); }
  else if (e.key === 'ArrowLeft' && !state.editable && !e.target.closest('input,textarea,.CodeMirror')) { e.preventDefault(); nav(-1); }
  else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && state.save) { e.preventDefault(); e.stopPropagation(); state.save(); }
}

function nav(dir) {
  if (!state || state.items.length < 2) return;
  if (state.dirty && !window.confirm(t('Discard unsaved changes?'))) return;
  state.dirty = false;
  if (state.cleanup) { try { state.cleanup(); } catch (e) { /* ignore */ } state.cleanup = null; }
  state.index = (state.index + dir + state.items.length) % state.items.length;
  state.opts = { ...state.opts, edit: false, imageEdit: false, office: false };
  render();
}

function current() { return state.items[state.index]; }

function render() {
  const it = current();
  state.editor = null; state.save = null; state.dirty = false; state.editable = false;
  const title = h('div.viewer-title', fileIcon(it, 'sm'), h('span.viewer-name', { title: it.path }, it.name), h('span.viewer-sub', fmtSize(it.size) + ' · ' + fmtDate(it.mtime)));
  const actions = h('div.viewer-actions');
  const perms = state.opts.folderPerms || it.perms || {};
  const counter = state.items.length > 1 ? h('span.viewer-counter', (state.index + 1) + ' / ' + state.items.length) : null;
  if (['text', 'code', 'markdown'].includes(it.kind) && perms.edit && !state.opts.edit) actions.appendChild(btn('edit', t('Edit'), () => { state.opts.edit = true; render(); }));
  if (it.kind === 'image' && perms.edit && !state.opts.imageEdit) actions.appendChild(btn('crop', t('Edit image'), () => { state.opts.imageEdit = true; render(); }));
  if (App.features.onlyoffice && ['doc', 'sheet', 'slide', 'pdf'].includes(it.kind) && !state.opts.office) actions.appendChild(btn('external', t('Open in office editor'), () => { state.opts.office = true; render(); }));
  if (perms.download !== false) actions.appendChild(btn('download', t('Download'), () => api.download([it.path])));
  if (perms.share && !App.isLinkMode && App.user) actions.appendChild(btn('share', t('Share'), () => import('./share.js').then(m => m.shareDialog(it))));
  if (perms.download !== false) actions.appendChild(btn('printer', t('Print'), () => printItem(it)));
  if (['image', 'video', 'pdf'].includes(it.kind)) actions.appendChild(btn('maximize', t('Full screen'), () => { if (document.fullscreenElement) document.exitFullscreen(); else overlay.requestFullscreen && overlay.requestFullscreen(); }));
  actions.appendChild(btn('info', t('Details'), () => infoDialog(it)));
  actions.appendChild(btn('x', t('Close'), closePreview));
  const body = h('div.viewer-body');
  replace(overlay, h('div.viewer-top', title, counter, actions), body,
    state.items.length > 1 && !state.opts.edit ? h('button.viewer-nav.prev', { onClick: () => nav(-1), 'aria-label': t('Previous') }, icon('chevron-left')) : null,
    state.items.length > 1 && !state.opts.edit ? h('button.viewer-nav.next', { onClick: () => nav(1), 'aria-label': t('Next') }, icon('chevron-right')) : null);
  renderBody(body, it, perms).catch(e => replace(body, fallback(it, e.message)));
}

function btn(ic, label, onClick) { return h('button.icon-btn.viewer-btn', { title: label, 'aria-label': label, onClick }, icon(ic)); }

async function renderBody(body, it, perms) {
  const url = api.fileUrl('raw', { p: it.path, v: it.mtime });
  if (state.opts.office && App.features.onlyoffice) return office(body, it);
  if (state.opts.imageEdit) return imageEditor(body, it);
  if (it.ext === 'csv' && !state.opts.edit) return sheet(body, it, url, perms);
  if (state.opts.edit || ((it.kind === 'text' || it.kind === 'code') && it.size < 5 * 1024 * 1024)) return textEditor(body, it, perms, state.opts.edit && perms.edit);
  switch (it.kind) {
    case 'image': return image(body, it, url);
    case 'raw': return rawImage(body, it);
    case 'video': replace(body, h('div.viewer-media', h('video', { src: url, controls: true, autoplay: true, playsinline: true, controlsList: perms.download === false ? 'nodownload' : '' }))); return;
    case 'audio': replace(body, h('div.viewer-media.audio', h('div.audio-art', it.thumb ? h('img', { src: api.fileUrl('thumb', { p: it.path, size: 'medium', v: it.mtime }), alt: '', onError: e => e.target.replaceWith(icon('music', 'audio-ic')) }) : icon('music', 'audio-ic')), h('div.audio-name', it.name), h('audio', { src: url, controls: true, autoplay: true }))); return;
    case 'pdf': return pdfJs(body, it, url);
    case 'markdown': return markdown(body, it, perms);
    case 'doc': return docx(body, it, url);
    case 'sheet': return sheet(body, it, url);
    case 'archive': return archive(body, it);
    case 'font': return fontPreview(body, it, url);
    case 'slide':
    case 'ebook':
    case 'model':
    default:
      if (['doc', 'sheet', 'slide', 'pdf'].includes(it.kind) && App.features.google_viewer) return googleViewer(body, it);
      replace(body, fallback(it));
  }
}

function fallback(it, message) {
  const perms = state.opts.folderPerms || {};
  return h('div.viewer-fallback', fileIcon(it, 'xl'), h('div.fb-name', it.name), h('div.fb-sub', fmtSize(it.size)), message ? h('div.fb-err', message) : h('div.fb-sub', t('No preview is available for this type of file.')),
    h('div.fb-actions', perms.download !== false ? h('button.btn.btn-primary', { onClick: () => api.download([it.path]) }, icon('download'), t('Download')) : null,
      App.features.google_viewer && ['doc', 'sheet', 'slide'].includes(it.kind) ? h('button.btn', { onClick: () => googleViewer(overlay.querySelector('.viewer-body'), it) }, icon('external'), t('Open with Google viewer')) : null));
}

// ---------------------------------------------------------------------------
function image(body, it, url) {
  const big = it.size > 40 * 1024 * 1024 || ['tif', 'tiff', 'psd', 'heic', 'heif', 'bmp'].includes(it.ext);
  const src = big || it.ext !== 'svg' && it.ext !== 'gif' ? api.fileUrl('thumb', { p: it.path, size: 'large', v: it.mtime }) : url;
  const img = h('img.viewer-img', { src, alt: it.name, draggable: false });
  img.addEventListener('error', () => { if (img.src !== url) img.src = url; else replace(body, fallback(it, t('The image could not be displayed.'))); });
  const wrap = h('div.viewer-media.image', img);
  let zoom = 1, ox = 0, oy = 0, dragging = null;
  const apply = () => { img.style.transform = `translate(${ox}px, ${oy}px) scale(${zoom})`; wrap.classList.toggle('zoomed', zoom > 1); };
  wrap.addEventListener('wheel', e => { if (!e.ctrlKey && zoom === 1 && Math.abs(e.deltaY) < 1) return; e.preventDefault(); zoom = Math.min(8, Math.max(1, zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15))); if (zoom === 1) { ox = oy = 0; } apply(); }, { passive: false });
  img.addEventListener('dblclick', () => { zoom = zoom > 1 ? 1 : 2.5; if (zoom === 1) { ox = oy = 0; } apply(); });
  img.addEventListener('mousedown', e => { if (zoom === 1) return; dragging = { x: e.clientX - ox, y: e.clientY - oy }; e.preventDefault(); });
  window.addEventListener('mousemove', e => { if (dragging) { ox = e.clientX - dragging.x; oy = e.clientY - dragging.y; apply(); } });
  window.addEventListener('mouseup', () => { dragging = null; });
  let tx = null;
  wrap.addEventListener('touchstart', e => { if (e.touches.length === 1) tx = e.touches[0].clientX; }, { passive: true });
  wrap.addEventListener('touchend', e => { if (tx !== null && zoom === 1) { const dx = e.changedTouches[0].clientX - tx; if (Math.abs(dx) > 60) nav(dx < 0 ? 1 : -1); } tx = null; });
  const tools = h('div.viewer-tools', h('button.icon-btn', { title: t('Zoom out'), onClick: () => { zoom = Math.max(1, zoom / 1.3); if (zoom === 1) { ox = oy = 0; } apply(); } }, icon('zoom-out')), h('button.icon-btn', { title: t('Zoom in'), onClick: () => { zoom = Math.min(8, zoom * 1.3); apply(); } }, icon('zoom-in')), h('button.icon-btn', { title: t('Original size'), onClick: () => { img.src = url; zoom = 1; ox = oy = 0; apply(); } }, icon('expand')), state.items.filter(i => i.kind === 'image').length > 1 ? h('button.icon-btn', { title: t('Slideshow'), onClick: slideshow }, icon('play')) : null);
  replace(body, wrap, tools);
}

function rawImage(body, it) {
  const img = h('img.viewer-img', { src: api.fileUrl('thumb', { p: it.path, size: 'large', v: it.mtime }), alt: it.name });
  img.addEventListener('error', () => replace(body, fallback(it, t('RAW preview is not available on this server.'))));
  replace(body, h('div.viewer-media.image', img));
}

let slideTimer = null;
function slideshow() {
  if (slideTimer) { clearInterval(slideTimer); slideTimer = null; toast(t('Slideshow stopped')); return; }
  toast(t('Slideshow started. Press Esc to stop.'));
  slideTimer = setInterval(() => { if (!overlay) { clearInterval(slideTimer); slideTimer = null; return; } nav(1); }, 4000);
  const stop = () => { if (slideTimer) { clearInterval(slideTimer); slideTimer = null; } document.removeEventListener('keydown', stop, true); };
  document.addEventListener('keydown', stop, true);
}

// ---------------------------------------------------------------------------
async function textEditor(body, it, perms, editable) {
  replace(body, h('div.loading', spinner()));
  const res = await api.get('files/text', { p: it.path });
  const mode = CM_MODES[it.ext] ?? null;
  const wrap = h('div.editor-wrap');
  const status = h('div.editor-status');
  const ta = h('textarea.editor-ta', { spellcheck: false, readOnly: !editable }, res.content);
  let cm = null;
  replace(body, wrap);
  wrap.appendChild(ta);
  wrap.appendChild(status);
  const readOnly = !editable;
  try {
    const base = App.assetBase + '/assets/vendor/codemirror/';
    await loadCss(base + 'codemirror.min.css');
    await loadCss(base + 'theme.min.css');
    await loadScript(base + 'codemirror.min.js');
    const mName = mode && typeof mode === 'object' ? mode.name : mode;
    const files = { javascript: 'javascript', jsx: 'jsx', htmlmixed: 'htmlmixed', xml: 'xml', css: 'css', php: 'php', python: 'python', markdown: 'markdown', shell: 'shell', sql: 'sql', yaml: 'yaml', 'text/x-csrc': 'clike', 'text/x-c++src': 'clike', 'text/x-java': 'clike', 'text/x-csharp': 'clike', go: 'go', rust: 'rust', ruby: 'ruby', properties: 'properties', toml: 'toml', dockerfile: 'dockerfile' };
    const deps = { htmlmixed: ['xml', 'javascript', 'css'], jsx: ['xml', 'javascript'], php: ['htmlmixed', 'xml', 'javascript', 'css', 'clike'], markdown: ['xml'] };
    if (mName && files[mName]) {
      for (const d of deps[mName] || []) await loadScript(base + 'mode/' + d + '.min.js').catch(() => {});
      await loadScript(base + 'mode/' + files[mName] + '.min.js').catch(() => {});
    }
    if (window.CodeMirror) {
      cm = window.CodeMirror.fromTextArea(ta, { mode: mode || null, lineNumbers: true, lineWrapping: it.kind !== 'code', readOnly, theme: document.documentElement.dataset.theme === 'dark' ? 'material-darker' : 'default', indentUnit: 2, tabSize: 4, viewportMargin: 50, extraKeys: { 'Ctrl-S': () => save(), 'Cmd-S': () => save(), 'Ctrl-F': 'findPersistent' } });
      cm.on('change', () => { if (editable) markDirty(true); });
      setTimeout(() => cm.refresh(), 50);
      state.cleanup = () => { try { cm.toTextArea(); } catch (e) { /* ignore */ } };
    }
  } catch (e) { console.warn('CodeMirror unavailable, using a plain editor', e); }
  if (!cm) ta.addEventListener('input', () => markDirty(true));
  const getValue = () => cm ? cm.getValue() : ta.value;
  const markDirty = (d) => { state.dirty = d; status.textContent = d ? t('Unsaved changes · Ctrl+S to save') : ''; saveBtn && (saveBtn.disabled = !d); };
  let saveBtn = null;
  let mtime = res.mtime;
  const save = async () => {
    if (!editable) return;
    try {
      const item = await api.post('files/save_text', { p: it.path, content: getValue(), mtime });
      mtime = item.mtime; it.size = item.size; it.mtime = item.mtime;
      markDirty(false); toast(t('Saved'), 'success', 1500);
      state.opts.onChange && state.opts.onChange();
    } catch (e) {
      if (e.code === 'conflict') { if (await confirm(t('Somebody else changed this file. Overwrite their version?'), { danger: true, okLabel: t('Overwrite') })) { await api.post('files/save_text', { p: it.path, content: getValue(), force: 1 }); markDirty(false); } }
      else toast(e.message, 'error');
    }
  };
  state.save = save;
  const bar = h('div.editor-bar',
    res.locked_by && res.locked_by.user_id !== (App.user && App.user.id) ? h('span.editor-lock', icon('lock'), t('Locked by {name}', { name: res.locked_by.user_name })) : null,
    h('span.editor-mode', (modeName(mode) || 'text') + ' · ' + fmtSize(res.size)),
    h('span.spacer'),
    it.kind === 'markdown' ? h('button.btn.btn-sm', { onClick: () => { state.opts.edit = false; render(); } }, icon('eye'), t('Preview')) : null,
    editable ? (saveBtn = h('button.btn.btn-primary.btn-sm', { disabled: true, onClick: save }, icon('save'), t('Save'))) : (perms.edit ? h('button.btn.btn-sm', { onClick: () => { state.opts.edit = true; render(); } }, icon('edit'), t('Edit')) : null));
  wrap.insertBefore(bar, wrap.firstChild);
  state.editor = cm || ta;
  state.editable = !!editable;
  if (editable) (cm || ta).focus();
}
function modeName(m) { return m ? (typeof m === 'object' ? m.name : String(m).replace('text/x-', '')) : ''; }

// ---------------------------------------------------------------------------
async function markdown(body, it, perms) {
  replace(body, h('div.loading', spinner()));
  const res = await api.get('files/text', { p: it.path });
  const html = renderMarkdown(res.content);
  replace(body, h('div.doc-view', h('div.editor-bar', h('span.editor-mode', 'Markdown · ' + fmtSize(res.size)), h('span.spacer'), perms.edit ? h('button.btn.btn-sm', { onClick: () => { state.opts.edit = true; render(); } }, icon('edit'), t('Edit')) : null), h('article.markdown', { html })));
}

/** Small Markdown renderer (headings, emphasis, code, lists, links, images, quotes, tables, hr). */
export function renderMarkdown(src) {
  const esc = escapeHtml;
  const lines = src.replace(/\r\n?/g, '\n').split('\n');
  let out = '', i = 0, inList = null, para = [];
  const inline = (s) => esc(s)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, a, u) => `<img alt="${a}" src="${safeUrl(u)}">`)
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, a, u) => `<a href="${safeUrl(u)}" target="_blank" rel="noopener">${a}</a>`)
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_]+)__/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>').replace(/(^|[^_\w])_([^_\n]+)_/g, '$1<em>$2</em>')
    .replace(/~~([^~]+)~~/g, '<del>$1</del>');
  const flushPara = () => { if (para.length) { out += '<p>' + inline(para.join(' ')) + '</p>'; para = []; } };
  const closeList = () => { if (inList) { out += `</${inList}>`; inList = null; } };
  while (i < lines.length) {
    const l = lines[i];
    if (/^```/.test(l)) { flushPara(); closeList(); const lang = l.slice(3).trim(); const buf = []; i++; while (i < lines.length && !/^```/.test(lines[i])) buf.push(lines[i++]); i++; out += `<pre><code class="lang-${esc(lang)}">${esc(buf.join('\n'))}</code></pre>`; continue; }
    const hm = l.match(/^(#{1,6})\s+(.*)$/);
    if (hm) { flushPara(); closeList(); out += `<h${hm[1].length}>${inline(hm[2])}</h${hm[1].length}>`; i++; continue; }
    if (/^(\s*[-*_]){3,}\s*$/.test(l)) { flushPara(); closeList(); out += '<hr>'; i++; continue; }
    if (/^>\s?/.test(l)) { flushPara(); closeList(); const buf = []; while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, '')); out += '<blockquote>' + renderMarkdown(buf.join('\n')) + '</blockquote>'; continue; }
    const lm = l.match(/^(\s*)([-*+]|\d+\.)\s+(.*)$/);
    if (lm) { flushPara(); const type = /\d/.test(lm[2]) ? 'ol' : 'ul'; if (inList !== type) { closeList(); out += `<${type}>`; inList = type; } let txt = lm[3]; const task = txt.match(/^\[([ xX])\]\s+(.*)$/); out += '<li>' + (task ? `<input type="checkbox" disabled ${task[1] !== ' ' ? 'checked' : ''}> ` + inline(task[2]) : inline(txt)) + '</li>'; i++; continue; }
    if (/^\|.*\|\s*$/.test(l) && i + 1 < lines.length && /^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(lines[i + 1])) {
      flushPara(); closeList();
      const cells = (s) => s.trim().replace(/^\||\|$/g, '').split('|').map(c => inline(c.trim()));
      out += '<table><thead><tr>' + cells(l).map(c => `<th>${c}</th>`).join('') + '</tr></thead><tbody>';
      i += 2;
      while (i < lines.length && /^\|.*\|\s*$/.test(lines[i])) { out += '<tr>' + cells(lines[i]).map(c => `<td>${c}</td>`).join('') + '</tr>'; i++; }
      out += '</tbody></table>'; continue;
    }
    if (l.trim() === '') { flushPara(); closeList(); i++; continue; }
    para.push(l.trim()); i++;
  }
  flushPara(); closeList();
  return out;
}
function safeUrl(u) { return /^(https?:|mailto:|\/|#|data:image\/)/i.test(u) ? escapeHtml(u) : '#'; }

// ---------------------------------------------------------------------------
async function docx(body, it, url) {
  if (it.ext !== 'docx') { if (App.features.google_viewer) return googleViewer(body, it); return replace(body, fallback(it)); }
  replace(body, h('div.loading', spinner()));
  try {
    await loadScript(App.assetBase + '/assets/vendor/mammoth.browser.min.js');
    const buf = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
    const res = await window.mammoth.convertToHtml({ arrayBuffer: buf });
    replace(body, h('div.doc-view', h('article.docx', { html: res.value })));
  } catch (e) {
    if (App.features.google_viewer) return googleViewer(body, it);
    replace(body, fallback(it, e.message));
  }
}

async function sheet(body, it, url, perms = null) {
  replace(body, h('div.loading', spinner()));
  try {
    await loadScript(App.assetBase + '/assets/vendor/xlsx.full.min.js');
    const buf = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
    const wb = window.XLSX.read(buf, { type: 'array' });
    const host = h('div.sheet-view');
    if (it.ext === 'csv') host.appendChild(h('div.editor-bar', h('span.editor-mode', 'CSV · ' + fmtSize(it.size)), h('span.spacer'), perms && perms.edit ? h('button.btn.btn-sm', { onClick: () => { state.opts.edit = true; render(); } }, icon('edit'), t('Edit as text')) : null));
    const tableHost = h('div.sheet-table');
    const tabsEl = h('div.sheet-tabs');
    const show = (name) => { const ws = wb.Sheets[name]; tableHost.innerHTML = window.XLSX.utils.sheet_to_html(ws, { editable: false }); $$('.sheet-tabs button', host).forEach(b => b.classList.toggle('active', b.textContent === name)); };
    for (const name of wb.SheetNames) tabsEl.appendChild(h('button', { onClick: () => show(name) }, name));
    host.appendChild(tableHost); host.appendChild(tabsEl);
    replace(body, host);
    show(wb.SheetNames[0]);
  } catch (e) {
    if (App.features.google_viewer) return googleViewer(body, it);
    replace(body, fallback(it, e.message));
  }
}

/** Render a PDF with pdf.js when the browser has no built-in viewer. */
async function pdfJs(body, it, url) {
  replace(body, h('div.loading', spinner()));
  try {
    const base = App.assetBase + '/assets/vendor/pdfjs/';
    const pdfjs = await import(base + 'pdf.min.mjs');
    pdfjs.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.mjs';
    const data = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
    const doc = await pdfjs.getDocument({ data }).promise;
    const host = h('div.pdf-pages');
    replace(body, host);
    const width = Math.min(1000, host.clientWidth - 40);
    for (let n = 1; n <= doc.numPages; n++) {
      const page = await doc.getPage(n);
      const vp0 = page.getViewport({ scale: 1 });
      const scale = width / vp0.width;
      const vp = page.getViewport({ scale: scale * (window.devicePixelRatio || 1) });
      const canvas = h('canvas.pdf-page', { width: vp.width, height: vp.height, style: { width: Math.floor(vp.width / (window.devicePixelRatio || 1)) + 'px' } });
      host.appendChild(canvas);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }
  } catch (e) {
    if (navigator.pdfViewerEnabled !== false) { replace(body, h('iframe.viewer-frame', { src: url + '#toolbar=1', title: it.name })); return; }
    replace(body, fallback(it, e.message));
  }
}

async function archive(body, it) {
  replace(body, h('div.loading', spinner()));
  try {
    const entries = await api.get('files/archive_list', { p: it.path });
    const perms = state.opts.folderPerms || {};
    const total = entries.reduce((a, e) => a + (e.size || 0), 0);
    replace(body, h('div.doc-view.archive-view',
      h('div.editor-bar', h('span.editor-mode', t('{n} entries', { n: entries.length }) + ' · ' + fmtSize(total) + ' ' + t('uncompressed')), h('span.spacer'), perms.create ? h('button.btn.btn-sm', { onClick: async () => { const tst = toast(t('Extracting…'), 'info', 0); try { await api.post('files/extract', { p: it.path, to: parentPath(it.path) || '/' }); tst.remove(); toast(t('Archive extracted'), 'success'); state.opts.onChange && state.opts.onChange(); closePreview(); } catch (e) { tst.remove(); toast(e.message, 'error'); } } }, icon('archive'), t('Extract here')) : null),
      h('table.archive-table', h('thead', h('tr', h('th', t('Name')), h('th', t('Size')), h('th', t('Modified')))), h('tbody', entries.map(e => h('tr', h('td', icon(e.is_dir ? 'folder' : 'file'), ' ', e.name), h('td', e.is_dir ? '' : fmtSize(e.size)), h('td', e.mtime ? fmtDate(e.mtime) : '')))))));
  } catch (e) { replace(body, fallback(it, e.message)); }
}

function fontPreview(body, it, url) {
  const family = 'fr_preview_' + Math.random().toString(36).slice(2);
  const style = h('style', `@font-face{font-family:"${family}";src:url("${url}")}`);
  const sample = t('The quick brown fox jumps over the lazy dog');
  replace(body, style, h('div.doc-view.font-view', { style: { fontFamily: `"${family}", sans-serif` } }, [72, 48, 32, 24, 18, 14].map(s => h('p', { style: { fontSize: s + 'px' } }, sample)), h('p.font-chars', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 0123456789 !?&@#%')));
}

async function googleViewer(body, it) {
  replace(body, h('div.loading', spinner()));
  try {
    const r = await api.get('files/viewer_url', { p: it.path });
    replace(body, h('iframe.viewer-frame', { src: r.viewer, title: it.name }));
  } catch (e) { replace(body, fallback(it, e.message)); }
}

async function office(body, it) {
  replace(body, h('div.loading', spinner()));
  try {
    const cfg = await api.get('files/office_config', { p: it.path });
    const scriptUrl = cfg.scriptUrl || (cfg.documentServerUrl.replace(/\/$/, '') + '/web-apps/apps/api/documents/api.js');
    await loadScript(scriptUrl);
    const host = h('div.office-host', { id: 'office_' + Date.now() });
    replace(body, host);
    const config = cfg.config;
    config.width = '100%'; config.height = '100%';
    config.events = { onRequestClose: closePreview, onError: (e) => toast(t('Office editor error: {msg}', { msg: (e && e.data) || '' }), 'error') };
    const editor = new window.DocsAPI.DocEditor(host.id, config);
    state.cleanup = () => { try { editor.destroyEditor(); } catch (e) { /* ignore */ } state.opts.onChange && state.opts.onChange(); };
  } catch (e) { replace(body, fallback(it, e.message)); }
}

// ---------------------------------------------------------------------------
async function imageEditor(body, it) {
  const url = api.fileUrl('raw', { p: it.path, v: it.mtime });
  const ops = [];
  const img = h('img.edit-img', { src: url, alt: it.name });
  const canvas = h('canvas.edit-canvas');
  const stage = h('div.edit-stage', img);
  let crop = null;
  let natural = { w: 0, h: 0 };
  img.addEventListener('load', () => { natural = { w: img.naturalWidth, h: img.naturalHeight }; info.textContent = natural.w + ' × ' + natural.h; });
  const info = h('span.editor-mode');
  const previewOps = () => {
    // Client-side preview of rotate/flip using CSS; crop/resize shown as a rectangle / dimensions.
    let rot = 0, sx = 1, sy = 1;
    for (const o of ops) { if (o.type === 'rotate') rot += o.degrees; if (o.type === 'flip') { if (o.direction === 'h') sx *= -1; else sy *= -1; } }
    img.style.transform = `rotate(${rot}deg) scale(${sx}, ${sy})`;
    pending.textContent = ops.length ? t('{n} pending changes', { n: ops.length }) : '';
  };
  const pending = h('span.editor-mode');
  const push = (op) => { ops.push(op); state.dirty = true; previewOps(); };
  const startCrop = () => {
    if (crop) { crop.remove(); crop = null; return; }
    const r = img.getBoundingClientRect();
    crop = h('div.crop-box', { style: { left: '10%', top: '10%', width: '80%', height: '80%' } });
    stage.appendChild(crop);
    let drag = null;
    crop.addEventListener('mousedown', e => { drag = { x: e.clientX, y: e.clientY, l: crop.offsetLeft, t: crop.offsetTop, w: crop.offsetWidth, h: crop.offsetHeight, resize: e.offsetX > crop.offsetWidth - 16 && e.offsetY > crop.offsetHeight - 16 }; e.preventDefault(); });
    window.addEventListener('mousemove', e => { if (!drag) return; const dx = e.clientX - drag.x, dy = e.clientY - drag.y; if (drag.resize) { crop.style.width = Math.max(20, drag.w + dx) + 'px'; crop.style.height = Math.max(20, drag.h + dy) + 'px'; } else { crop.style.left = Math.max(0, drag.l + dx) + 'px'; crop.style.top = Math.max(0, drag.t + dy) + 'px'; } });
    window.addEventListener('mouseup', () => { drag = null; });
  };
  const applyCrop = () => {
    if (!crop) return;
    const ir = img.getBoundingClientRect(), cr = crop.getBoundingClientRect();
    const scale = natural.w / ir.width;
    push({ type: 'crop', x: Math.round((cr.left - ir.left) * scale), y: Math.round((cr.top - ir.top) * scale), width: Math.round(cr.width * scale), height: Math.round(cr.height * scale) });
    crop.remove(); crop = null;
  };
  const resize = async () => {
    const v = await prompt(t('Resize'), { label: t('New width in pixels (height keeps the ratio)'), value: String(natural.w), required: true });
    if (!v) return;
    const w = parseInt(v, 10); if (!w) return;
    push({ type: 'resize', width: w, height: Math.round(natural.h * w / natural.w) });
  };
  const save = async (saveAs) => {
    if (!ops.length) { toast(t('Nothing to save')); return; }
    let name = '';
    if (saveAs) { name = await prompt(t('Save as'), { value: it.name.replace(/(\.[^.]+)$/, '-edited$1'), required: true, selectBase: true }); if (!name) return; }
    try {
      const item = await api.post('files/image_edit', { p: it.path, ops, save_as: name });
      state.dirty = false;
      toast(t('Image saved'), 'success');
      state.opts.onChange && state.opts.onChange();
      state.items[state.index] = { ...it, ...item };
      state.opts.imageEdit = false; render();
    } catch (e) { toast(e.message, 'error'); }
  };
  state.save = () => save(false);
  replace(body, h('div.editor-wrap.image-editor',
    h('div.editor-bar',
      h('button.btn.btn-sm', { onClick: () => push({ type: 'rotate', degrees: -90 }) }, icon('rotate-ccw'), t('Rotate left')),
      h('button.btn.btn-sm', { onClick: () => push({ type: 'rotate', degrees: 90 }) }, icon('rotate-cw'), t('Rotate right')),
      h('button.btn.btn-sm', { onClick: () => push({ type: 'flip', direction: 'h' }) }, icon('flip-h'), t('Flip')),
      h('button.btn.btn-sm', { onClick: () => push({ type: 'flip', direction: 'v' }) }, icon('flip-v'), t('Flip vertical')),
      h('button.btn.btn-sm', { onClick: startCrop }, icon('crop'), t('Crop')),
      h('button.btn.btn-sm', { onClick: applyCrop }, icon('check'), t('Apply crop')),
      h('button.btn.btn-sm', { onClick: resize }, icon('maximize'), t('Resize')),
      h('button.btn.btn-sm', { onClick: () => { ops.length = 0; state.dirty = false; previewOps(); } }, icon('undo'), t('Reset')),
      info, pending, h('span.spacer'),
      h('button.btn.btn-sm', { onClick: () => save(true) }, t('Save as copy')),
      h('button.btn.btn-primary.btn-sm', { onClick: () => save(false) }, icon('save'), t('Save'))),
    stage));
}

// ---------------------------------------------------------------------------
function printItem(it) {
  const url = api.fileUrl('raw', { p: it.path });
  if (it.kind === 'image' || it.kind === 'pdf' || it.kind === 'text' || it.kind === 'code' || it.kind === 'markdown') {
    const w = window.open(url, '_blank');
    if (w) w.addEventListener('load', () => { try { w.print(); } catch (e) { /* ignore */ } });
  } else toast(t('Printing is only available for images, PDFs and text files'));
}

async function infoDialog(it) {
  try {
    const info = await api.get('files/info', { p: it.path });
    const rows = [[t('Name'), info.name], [t('Size'), fmtSize(info.size)], [t('Type'), info.mime], [t('Modified'), fmtDate(info.mtime)], [t('Path'), info.path]];
    if (info.exif && info.exif.Camera) rows.push([t('Camera'), info.exif.Camera]);
    if (info.exif && info.exif.DateTimeOriginal) rows.push([t('Taken'), info.exif.DateTimeOriginal]);
    if (info.exif && info.exif.Width) rows.push([t('Dimensions'), info.exif.Width + ' × ' + info.exif.Height]);
    dialog({ title: t('Details'), width: '460px', body: h('dl.kv', rows.map(([k, v]) => [h('dt', k), h('dd', v)])), buttons: [{ label: t('Close'), primary: true }] });
  } catch (e) { toast(e.message, 'error'); }
}
