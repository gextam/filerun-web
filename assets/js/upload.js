/* Upload manager: resumable chunked uploads, folder drops, clipboard paste. */
import { App, joinPath } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, icon, replace, clear, dialog, toast, fmtSize, progressBar } from './ui.js';

const Q = { items: [], active: 0, panel: null, collapsed: false, id: 0 };
const CONCURRENCY = 2;

export function mount(root) {
  if (Q.panel) return;
  Q.panel = h('div.upload-panel', { hidden: true });
  root.appendChild(Q.panel);
}

export function pickFiles(path, folder = false) {
  const input = h('input', { type: 'file', multiple: true, style: { display: 'none' } });
  if (folder) { input.webkitdirectory = true; input.directory = true; }
  input.addEventListener('change', () => {
    const files = [...input.files].map(f => ({ file: f, relpath: f.webkitRelativePath || f.name }));
    uploadFiles(files, path);
    input.remove();
  });
  document.body.appendChild(input);
  input.click();
}

/** Drop of OS files / folders (uses the File System Entries API when available). */
export async function handleDrop(dt, path) {
  const items = dt.items ? [...dt.items] : [];
  const entries = items.map(i => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null)).filter(Boolean);
  if (!entries.length) { uploadFiles([...dt.files].map(f => ({ file: f, relpath: f.name })), path); return; }
  const out = [];
  const walk = (entry, prefix) => new Promise(resolve => {
    if (entry.isFile) {
      entry.file(f => { out.push({ file: f, relpath: prefix + f.name }); resolve(); }, () => resolve());
    } else if (entry.isDirectory) {
      const reader = entry.createReader();
      const all = [];
      const readBatch = () => reader.readEntries(async batch => {
        if (!batch.length) { for (const e of all) await walk(e, prefix + entry.name + '/'); if (!all.length) out.push({ dir: prefix + entry.name }); resolve(); return; }
        all.push(...batch); readBatch();
      }, () => resolve());
      readBatch();
    } else resolve();
  });
  const tst = entries.some(e => e.isDirectory) ? toast(t('Reading folders…'), 'info', 0) : null;
  for (const e of entries) await walk(e, '');
  if (tst) tst.remove();
  uploadFiles(out, path);
}

export async function pasteFromClipboard(path) {
  try {
    if (!navigator.clipboard || !navigator.clipboard.read) throw new Error(t('Clipboard access is not available in this browser. Press Ctrl+V in the file list instead.'));
    const items = await navigator.clipboard.read();
    const files = [];
    for (const it of items) {
      const type = it.types.find(tp => tp.startsWith('image/'));
      if (type) { const blob = await it.getType(type); const ext = type.split('/')[1].replace('jpeg', 'jpg'); files.push({ file: new File([blob], 'Screenshot ' + new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-') + '.' + ext, { type }), relpath: null }); }
    }
    if (!files.length) { toast(t('No image in the clipboard'), 'warn'); return; }
    files.forEach(f => { f.relpath = f.file.name; });
    uploadFiles(files, path, { afterOne: async (item) => { const { shareLinkForPaste } = await import('./share.js'); shareLinkForPaste(item); } });
  } catch (e) { toast(e.message, 'error'); }
}

/**
 * entries: [{file, relpath}] or [{dir}] (empty folders). path: destination virtual path.
 */
export async function uploadFiles(entries, path, opts = {}) {
  const files = entries.filter(e => e.file);
  const dirs = entries.filter(e => e.dir);
  const max = App.upload && App.upload.max_upload;
  const tooBig = max ? files.filter(f => f.file.size > max) : [];
  if (tooBig.length) toast(t('{n} files exceed the maximum size of {max} and were skipped', { n: tooBig.length, max: fmtSize(max) }), 'warn', 6000);
  const ok = files.filter(f => !max || f.file.size <= max);
  // Folder sync: skip files that already exist with the same size and time.
  let mode = 'rename';
  let skip = new Set();
  if (ok.length) {
    try {
      const existing = [];
      for (let i = 0; i < ok.length; i += 500) {
        const batch = ok.slice(i, i + 500).map(f => ({ path: f.relpath, size: f.file.size, mtime: Math.round(f.file.lastModified / 1000) }));
        const res = await api.post('files/check_sync', { p: path, files: batch });
        for (const r of res) if (r.reason !== 'new') existing.push(r);
      }
      if (existing.length) {
        const same = existing.filter(r => r.reason === 'same').length;
        const changed = existing.length - same;
        mode = await conflictDialog(existing.length, same, changed);
        if (mode === null) return;
        if (mode === 'skip') existing.forEach(r => skip.add(r.path));
        if (mode === 'sync') { existing.filter(r => r.reason === 'same').forEach(r => skip.add(r.path)); mode = 'overwrite'; }
      }
    } catch (e) { /* server may not allow check (e.g. uploader role): proceed */ }
  }
  for (const d of dirs) {
    try { await api.post('files/mkdir', { p: path, name: d.dir.split('/')[0] }).catch(() => {}); } catch (e) { /* ignore */ }
  }
  for (const f of ok) {
    if (skip.has(f.relpath)) continue;
    Q.items.push({ id: ++Q.id, file: f.file, relpath: f.relpath, path, size: f.file.size, sent: 0, status: 'queued', overwrite: mode === 'overwrite', afterOne: opts.afterOne });
  }
  if (!Q.items.some(i => i.status === 'queued' || i.status === 'uploading')) { if (ok.length) { toast(t('Nothing to upload: the files are already up to date'), 'info'); refreshListing(path); } return; }
  show();
  pump();
}

function conflictDialog(total, same, changed) {
  return dialog({
    title: t('Some files exist already'), width: '480px',
    body: h('div', h('p.dialog-text', t('{n} of the files you are uploading exist in this folder ({same} identical, {changed} different).', { n: total, same, changed })), h('p.dialog-text.muted', t('Replacing keeps the previous content in the file history.'))),
    buttons: [
      { label: t('Cancel'), value: null },
      { label: t('Skip existing'), value: 'skip' },
      { label: t('Keep both'), value: 'rename' },
      { label: t('Upload changed only'), value: 'sync', primary: changed > 0 },
      { label: t('Replace all'), value: 'overwrite', primary: changed === 0 },
    ],
  }).result;
}

function pump() {
  while (Q.active < CONCURRENCY) {
    const next = Q.items.find(i => i.status === 'queued');
    if (!next) break;
    Q.active++;
    run(next).finally(() => { Q.active--; pump(); checkDone(); });
  }
  paint();
}

async function run(item) {
  item.status = 'uploading'; item.ctrl = new AbortController(); paint();
  const chunkSize = (App.upload && App.upload.chunk_size) || 4 * 1024 * 1024;
  let attempt = 0;
  while (true) {
    try {
      if (!item.uploadId) {
        const s = await api.post('upload/start', { p: item.path, relpath: item.relpath, name: item.file.name, size: item.size, mtime: Math.round(item.file.lastModified / 1000), overwrite: item.overwrite });
        item.uploadId = s.upload_id; item.finalName = s.name; item.sent = s.received || 0;
      } else {
        const st = await api.get('upload/status', { id: item.uploadId });
        item.sent = st.received;
      }
      while (item.sent < item.size) {
        if (item.status === 'cancelled') throw new Error('cancelled');
        const end = Math.min(item.size, item.sent + chunkSize);
        const blob = item.file.slice(item.sent, end);
        const r = await api.raw('upload/chunk', blob, { id: item.uploadId, offset: item.sent }, (loaded) => { item.progress = item.sent + loaded; paintItem(item); }, item.ctrl.signal);
        if (r.resume) { item.sent = r.received; continue; }
        item.sent = r.received;
        item.progress = item.sent;
        paintItem(item);
      }
      const result = await api.post('upload/finish', { id: item.uploadId });
      item.status = 'done'; item.result = result; item.progress = item.size;
      paintItem(item);
      if (item.afterOne) { try { await item.afterOne(result); } catch (e) { /* ignore */ } }
      return;
    } catch (e) {
      if (item.status === 'cancelled' || (e && e.code === 'abort')) { item.status = 'cancelled'; api.post('upload/cancel', { id: item.uploadId }).catch(() => {}); paintItem(item); return; }
      attempt++;
      const retryable = e.code === 'network' || e.code === 'http' || e.status >= 500 || e.status === 0;
      if (attempt < 4 && retryable) { item.status = 'retrying'; paintItem(item); await new Promise(r => setTimeout(r, 1500 * attempt)); item.status = 'uploading'; continue; }
      item.status = 'error'; item.error = e.message || String(e); paintItem(item);
      return;
    }
  }
}

function checkDone() {
  if (Q.items.some(i => ['queued', 'uploading', 'retrying'].includes(i.status))) return;
  const done = Q.items.filter(i => i.status === 'done');
  const failed = Q.items.filter(i => i.status === 'error');
  if (done.length) {
    const paths = [...new Set(done.map(i => i.path))];
    paths.forEach(p => refreshListing(p));
    import('./app.js').then(m => m.refreshSidebar && m.refreshSidebar()).catch(() => {});
    if (!failed.length) toast(t('{n} files uploaded', { n: done.length }), 'success');
  }
  if (failed.length) toast(t('{n} uploads failed', { n: failed.length }), 'error', 6000);
  paint();
  if (!failed.length) setTimeout(() => { if (!Q.items.some(i => ['queued', 'uploading', 'retrying', 'error'].includes(i.status))) hide(); }, 4000);
}

async function refreshListing(path) {
  try {
    const F = await import('./files.js');
    if (F.S && F.S.mode === 'browse' && (F.S.path === path || path.startsWith(F.S.path + '/') || (F.S.path === '/' && !path.startsWith('@')))) F.refresh();
  } catch (e) { /* ignore */ }
}

// ---------------------------------------------------------------------------
// Panel
// ---------------------------------------------------------------------------
function show() { if (!Q.panel) mount(document.body); Q.panel.hidden = false; Q.collapsed = false; paint(); }
function hide() { if (Q.panel) { Q.panel.hidden = true; Q.items = Q.items.filter(i => ['queued', 'uploading', 'retrying'].includes(i.status)); } }

function paint() {
  if (!Q.panel || Q.panel.hidden) return;
  const total = Q.items.length;
  const done = Q.items.filter(i => i.status === 'done').length;
  const bytes = Q.items.reduce((a, i) => a + i.size, 0);
  const sent = Q.items.reduce((a, i) => a + (i.status === 'done' ? i.size : (i.progress || 0)), 0);
  const pct = bytes ? Math.round(sent / bytes * 100) : 0;
  const busy = Q.items.some(i => ['queued', 'uploading', 'retrying'].includes(i.status));
  const head = h('div.upload-head', { onClick: () => { Q.collapsed = !Q.collapsed; paint(); } },
    h('div.upload-head-text', busy ? t('Uploading {done} of {total} · {pct}%', { done: Math.min(total, done + 1), total, pct }) : t('{done} of {total} uploaded', { done, total })),
    h('div.upload-head-actions', h('button.icon-btn', { title: Q.collapsed ? t('Expand') : t('Collapse'), onClick: e => { e.stopPropagation(); Q.collapsed = !Q.collapsed; paint(); } }, icon(Q.collapsed ? 'chevron-up' : 'chevron-down')),
      h('button.icon-btn', { title: busy ? t('Cancel all') : t('Close'), onClick: e => { e.stopPropagation(); if (busy) cancelAll(); else hide(); } }, icon('x'))));
  const bar = progressBar(pct, busy ? '' : 'done');
  const list = h('div.upload-list', { hidden: Q.collapsed });
  for (const it of Q.items) list.appendChild(renderItem(it));
  replace(Q.panel, head, bar, list);
}

function renderItem(it) {
  const row = h('div.upload-item', { dataset: { id: it.id }, class: 'st-' + it.status });
  fillItem(row, it);
  return row;
}

function fillItem(row, it) {
  const pct = it.size ? Math.round(((it.status === 'done' ? it.size : (it.progress || 0)) / it.size) * 100) : (it.status === 'done' ? 100 : 0);
  const statusText = { queued: t('Waiting'), uploading: pct + '%', retrying: t('Retrying…'), done: t('Done'), error: it.error || t('Failed'), cancelled: t('Cancelled') }[it.status];
  replace(row,
    h('div.upload-item-main', h('span.upload-name', { title: it.relpath }, it.relpath || it.file.name), h('span.upload-status', statusText)),
    it.status === 'uploading' || it.status === 'retrying' || it.status === 'queued' ? progressBar(pct, 'thin') : null,
    h('div.upload-item-actions',
      it.status === 'error' ? h('button.icon-btn.sm', { title: t('Retry'), onClick: () => { it.status = 'queued'; it.error = null; pump(); } }, icon('refresh')) : null,
      ['queued', 'uploading', 'retrying'].includes(it.status) ? h('button.icon-btn.sm', { title: t('Cancel'), onClick: () => cancel(it) }, icon('x')) : null));
  row.className = 'upload-item st-' + it.status;
}

function paintItem(it) {
  if (!Q.panel || Q.panel.hidden) return;
  const row = Q.panel.querySelector('.upload-item[data-id="' + it.id + '"]');
  if (row) fillItem(row, it);
  const bytes = Q.items.reduce((a, i) => a + i.size, 0);
  const sent = Q.items.reduce((a, i) => a + (i.status === 'done' ? i.size : (i.progress || 0)), 0);
  const bar = Q.panel.querySelector('.progress-bar');
  if (bar) bar.style.width = (bytes ? sent / bytes * 100 : 0) + '%';
  const txt = Q.panel.querySelector('.upload-head-text');
  if (txt) { const total = Q.items.length, done = Q.items.filter(i => i.status === 'done').length; txt.textContent = t('Uploading {done} of {total} · {pct}%', { done: Math.min(total, done + 1), total, pct: bytes ? Math.round(sent / bytes * 100) : 0 }); }
}

function cancel(it) {
  if (it.status === 'queued') { it.status = 'cancelled'; paintItem(it); return; }
  it.status = 'cancelled';
  if (it.ctrl) it.ctrl.abort();
}
function cancelAll() { Q.items.forEach(i => { if (['queued', 'uploading', 'retrying'].includes(i.status)) cancel(i); }); }

window.addEventListener('beforeunload', e => {
  if (Q.items.some(i => ['queued', 'uploading', 'retrying'].includes(i.status))) { e.preventDefault(); e.returnValue = ''; }
});
