/* The file browser: listings, selection, actions, drag & drop, details panel. */
import { App, parentPath, joinPath, baseName } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, $, $$, icon, fileIcon, replace, clear, dialog, confirm, prompt, toast, menu, fmtSize, fmtDate, fmtDateShort, relTime, labelDot, stars, emptyState, tabs, avatar, debounce, copyText, field, spinner, escapeHtml, progressBar, dropdown } from './ui.js';
import { openPreview, closePreview, isPreviewOpen, canPreview } from './preview.js';
import * as Uploads from './upload.js';
import * as Share from './share.js';
import * as Collections from './collections.js';

export const S = {
  main: null, mode: 'browse', kind: null, path: '/', folder: null, items: [], total: 0, params: {},
  selection: new Set(), anchor: -1, focus: -1,
  view: 'list', sort: { by: 'name', dir: 'asc' }, filter: '', showHidden: false, typeFilter: 'all',
  detailsOpen: false, detailsItem: null, loading: false, columns: null,
  renderGen: 0,
};

const LABEL_COLORS = { red: '#ef4444', orange: '#f97316', yellow: '#eab308', green: '#22c55e', blue: '#3b82f6', purple: '#a855f7', gray: '#9ca3af' };

function loadPrefs() {
  S.view = App.pref('view', 'list');
  S.sort = App.pref('sort', { by: 'name', dir: 'asc' });
  S.detailsOpen = App.pref('details', false);
  S.showHidden = App.pref('show_hidden', false);
}

export function closePreviewIfOpen() { if (isPreviewOpen()) closePreview(); }

// ---------------------------------------------------------------------------
// Entry points
// ---------------------------------------------------------------------------
export async function browse(main, vpath) {
  loadPrefs();
  S.main = main; S.mode = 'browse'; S.kind = null; S.path = vpath || '/'; S.params = {};
  S.selection.clear(); S.anchor = -1; S.focus = -1; S.filter = '';
  renderSkeleton();
  await load();
}

export async function virtual(main, kind, title, params = {}) {
  loadPrefs();
  S.main = main; S.mode = 'virtual'; S.kind = kind; S.path = '/'; S.params = params; S.title = title;
  if (kind === 'photos' || (kind === 'media' && (params.cat === 'photos' || params.cat === 'videos'))) S.view = App.pref('view_photos', 'gallery');
  S.typeFilter = 'all';
  S.selection.clear(); S.anchor = -1; S.focus = -1; S.filter = '';
  renderSkeleton();
  await load();
}

export async function refresh(keepSelection = true) {
  const sel = new Set(S.selection);
  await load(true);
  if (keepSelection) { S.selection = new Set([...sel].filter(p => S.items.some(i => i.path === p))); paintSelection(); }
}

async function load(silent = false) {
  const gen = ++S.renderGen;
  S.loading = true;
  if (!silent) setContent(h('div.loading', spinner()));
  try {
    let res;
    if (S.mode === 'browse') {
      res = await api.get('files/list', { p: S.path, sort: S.sort.by, dir: S.sort.dir, show_hidden: S.showHidden ? 1 : 0 });
      if (gen !== S.renderGen) return;
      S.folder = res.folder; S.items = res.items; S.total = res.total;
      App.title(S.folder.name);
    } else {
      const k = S.kind;
      const ep = { recent: 'files/recent', starred: 'files/starred', photos: 'files/photos', search: 'files/search', tag: 'files/by_tag', label: 'files/by_label', media: 'files/media', collection: 'collections/items' }[k];
      const params = k === 'search' ? { q: S.params.q, p: S.params.p || '/', ...S.params.filters } : k === 'tag' ? { id: S.params.id } : k === 'label' ? { label: S.params.label } : k === 'media' ? { cat: S.params.cat, sub: S.params.sub || '', limit: 2000 } : k === 'collection' ? { id: S.params.id } : { limit: k === 'photos' ? 2000 : 200 };
      res = await api.get(ep, params);
      if (gen !== S.renderGen) return;
      if (k === 'collection' && res.collection) { S.title = res.collection.name; S.collection = res.collection; S.params.crumbs = [{ name: t('Collections'), hash: '#/collections' }, { name: res.collection.name }]; }
      let items = res.items || [];
      if (k === 'search') { S.contentHits = res.content || []; S.metaHits = res.metadata || []; items = items.concat(S.contentHits.map(i => ({ ...i, _content: true })), S.metaHits.map(i => ({ ...i, _meta: true }))); }
      S.items = items; S.total = items.length;
      S.folder = { name: S.title, path: '/', perms: { read: true, download: true, create: false, delete: true, rename: true, share: true, meta: true, comment: true, edit: true, lock: true }, role: 'owner', root: 'virtual', breadcrumbs: [] };
      sortLocal();
      App.title(S.title);
    }
  } catch (e) {
    if (gen !== S.renderGen) return;
    S.items = []; S.folder = S.folder || { name: '', perms: {}, breadcrumbs: [] };
    setContent(emptyState('alert', t('Cannot open this folder'), e.message, h('button.btn', { onClick: () => App.navigate('#/files/') }, t('Go to My Files'))));
    renderToolbar();
    S.loading = false;
    return;
  }
  S.loading = false;
  renderToolbar();
  renderItems();
  renderStatus();
  if (S.detailsOpen) renderDetails();
  App.emit('listing', { path: S.path, items: S.items });
}

function sortLocal() {
  const { by, dir } = S.sort;
  const mul = dir === 'desc' ? -1 : 1;
  S.items.sort((a, b) => {
    if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
    let c = 0;
    if (by === 'size') c = a.size - b.size; else if (by === 'mtime') c = a.mtime - b.mtime; else if (by === 'kind') c = a.kind.localeCompare(b.kind); else if (by === 'ext') c = (a.ext || '').localeCompare(b.ext || '');
    if (c === 0) c = a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
    return c * mul;
  });
}

// ---------------------------------------------------------------------------
// Skeleton & toolbar
// ---------------------------------------------------------------------------
function renderSkeleton() {
  const toolbar = h('div.toolbar');
  const content = h('div.content', { tabindex: 0, 'aria-label': t('Files') });
  const status = h('div.statusbar');
  replace(S.main, h('div.view', toolbar, content, status));
  S.el = { toolbar, content, status };
  wireContent(content);
}

function setContent(node) { if (S.el) replace(S.el.content, node); }

export function perms() { return (S.folder && S.folder.perms) || {}; }
const can = (p) => !!perms()[p];

function renderToolbar() {
  const tb = S.el.toolbar;
  const crumbs = h('div.crumbs');
  const list = S.mode === 'browse' ? (S.folder.breadcrumbs || []) : (S.params.crumbs || [{ name: S.title, path: null }]);
  const shown = list.length > 4 ? [list[0], { name: '…', path: null, menu: list.slice(1, -2) }, ...list.slice(-2)] : list;
  shown.forEach((c, i) => {
    if (i > 0) crumbs.appendChild(icon('chevron-right', 'crumb-sep'));
    const el = h('button.crumb', { class: i === shown.length - 1 ? 'current' : '', title: c.name }, c.name);
    if (c.menu) el.addEventListener('click', () => menu(c.menu.map(m => ({ label: m.name, icon: 'folder', onClick: () => goCrumb(m.path) })), el));
    else if (c.hash) el.addEventListener('click', () => App.navigate(c.hash));
    else if (c.path !== null && c.path !== undefined && i < shown.length - 1) { el.addEventListener('click', () => goCrumb(c.path)); makeDropTarget(el, c.path); }
    else if (i === shown.length - 1 && S.mode === 'virtual' && S.kind === 'collection' && S.collection) el.addEventListener('click', e => menu(Collections.cardMenu(S.collection, () => import('./app.js').then(m => m.route())), e.currentTarget));
    else if (i === shown.length - 1 && S.mode === 'browse') el.addEventListener('click', e => folderMenu(e.currentTarget));
    crumbs.appendChild(el);
  });
  if (S.mode === 'browse' && S.folder.root === 'share') crumbs.appendChild(h('span.role-badge', { title: t('Shared by {name}', { name: S.folder.owner.name }) }, icon('users'), S.folder.role_label));
  const sel = selectedItems();
  const actions = h('div.toolbar-actions');
  if (sel.length) {
    actions.appendChild(h('span.sel-count', t('{n} selected', { n: sel.length })));
    for (const a of primaryActions(sel)) actions.appendChild(h('button.tbtn', { title: a.label, onClick: a.onClick, disabled: a.disabled }, icon(a.icon), h('span.tbtn-label', a.label)));
    actions.appendChild(h('button.tbtn', { title: t('More'), onClick: e => menu(itemActions(sel), e.currentTarget) }, icon('more-h')));
    actions.appendChild(h('button.tbtn', { title: t('Clear selection'), onClick: () => clearSelection() }, icon('x')));
  } else if (S.mode === 'browse') {
    if (can('create')) {
      actions.appendChild(dropdown(h('button.tbtn.tbtn-new', icon('plus'), h('span.tbtn-label', t('New'))), () => newMenuItems()));
      actions.appendChild(h('button.tbtn', { onClick: e => uploadMenu(e.currentTarget) }, icon('upload'), h('span.tbtn-label', t('Upload'))));
    }
    if (App.clipboard && can('create')) actions.appendChild(h('button.tbtn', { onClick: pasteClipboard }, icon('clipboard'), h('span.tbtn-label', t('Paste'))));
  } else if (S.kind === 'search') {
    actions.appendChild(h('button.tbtn', { onClick: () => advancedSearch(S.params.q) }, icon('sliders'), h('span.tbtn-label', t('Filters'))));
  } else if (S.kind === 'collection' && S.collection) {
    actions.appendChild(h('button.tbtn', { onClick: e => menu(Collections.cardMenu(S.collection, () => import('./app.js').then(m => m.route())), e.currentTarget) }, icon('more-h'), h('span.tbtn-label', t('Collection'))));
  }
  const right = h('div.toolbar-right');
  right.appendChild(dropdown(h('button.tbtn', { class: S.typeFilter !== 'all' ? 'active' : '', title: t('Filter by type') }, icon('filter'), h('span.tbtn-label', S.typeFilter === 'all' ? t('Type') : TYPE_FILTERS[S.typeFilter].label)), () => [
    { header: t('Show only') },
    ...Object.entries(TYPE_FILTERS).map(([k, f]) => ({ label: f.label, icon: f.icon, checked: S.typeFilter === k, onClick: () => { S.typeFilter = k; renderToolbar(); renderItems(); renderStatus(); } })),
  ]));
  const filter = h('input.filter-input', { type: 'search', placeholder: t('Filter'), value: S.filter, 'aria-label': t('Filter list') });
  filter.addEventListener('input', debounce(() => { S.filter = filter.value; renderItems(); renderStatus(); }, 120));
  right.appendChild(filter);
  const sortBtn = dropdown(h('button.tbtn', { title: t('Sort') }, icon('sort')), () => [
    { header: t('Sort by') },
    ...[['name', t('Name')], ['mtime', t('Modified')], ['size', t('Size')], ['kind', t('Kind')], ['ext', t('Extension')]].map(([k, l]) => ({ label: l, checked: S.sort.by === k, onClick: () => setSort(k) })),
    { sep: true },
    { label: t('Ascending'), checked: S.sort.dir === 'asc', onClick: () => setSort(S.sort.by, 'asc') },
    { label: t('Descending'), checked: S.sort.dir === 'desc', onClick: () => setSort(S.sort.by, 'desc') },
    { sep: true },
    { label: t('Show hidden files'), checked: S.showHidden, onClick: () => { S.showHidden = !S.showHidden; App.setPref('show_hidden', S.showHidden); load(true); } },
  ]);
  right.appendChild(sortBtn);
  const views = h('div.view-switch');
  for (const [v, ic, l] of [['list', 'list', t('List')], ['grid', 'grid', t('Grid')], ['gallery', 'gallery', t('Gallery')]]) {
    views.appendChild(h('button.tbtn', { class: S.view === v ? 'active' : '', title: l, onClick: () => setView(v) }, icon(ic)));
  }
  right.appendChild(views);
  right.appendChild(h('button.tbtn', { class: S.detailsOpen ? 'active' : '', title: t('Details'), onClick: toggleDetails }, icon('panel-right')));
  if (S.mode === 'browse') right.appendChild(h('button.tbtn', { title: t('Refresh'), onClick: () => refresh() }, icon('refresh')));
  replace(tb, crumbs, actions, right);
}

function goCrumb(path) {
  if (path === '~shared') return App.navigate('#/shared');
  App.openFolder(path);
}

function setSort(by, dir) {
  if (dir === undefined) dir = S.sort.by === by && S.sort.dir === 'asc' ? 'desc' : 'asc';
  S.sort = { by, dir };
  App.setPref('sort', S.sort);
  if (S.mode === 'browse') load(true); else { sortLocal(); renderItems(); }
  renderToolbar();
}

function setView(v) {
  S.view = v;
  App.setPref(S.kind === 'photos' ? 'view_photos' : 'view', v);
  renderToolbar(); renderItems();
}

export function toggleDetails() {
  S.detailsOpen = !S.detailsOpen;
  App.setPref('details', S.detailsOpen);
  renderToolbar();
  renderDetails();
}

function newMenuItems() {
  const items = [
    { label: t('Folder'), icon: 'folder-plus', onClick: newFolder },
    { label: t('Text file'), icon: 'file-plus', onClick: () => newFile('txt') },
    { label: t('Markdown note'), icon: 'file-text', onClick: () => newFile('md') },
  ];
  if (App.features.onlyoffice) {
    items.push({ sep: true }, { label: t('Word document'), icon: 'file-text', onClick: () => newFile('docx') }, { label: t('Spreadsheet'), icon: 'layout', onClick: () => newFile('xlsx') });
  }
  return items;
}

export function uploadMenu(anchor) {
  if (S.mode !== 'browse' || !can('create')) { toast(t('Open a folder you can add files to first'), 'warn'); return; }
  menu([
    { label: t('Upload files'), icon: 'upload', onClick: () => Uploads.pickFiles(S.path, false) },
    { label: t('Upload folder'), icon: 'folder-input', onClick: () => Uploads.pickFiles(S.path, true) },
    { sep: true },
    { label: t('Paste image from clipboard'), icon: 'clipboard', onClick: () => Uploads.pasteFromClipboard(S.path) },
  ], anchor);
}

async function newFolder() {
  const name = await prompt(t('New folder'), { value: t('New folder'), required: true, okLabel: t('Create') });
  if (name === null) return;
  try {
    const item = await api.post('files/mkdir', { p: S.path, name });
    toast(t('Folder created'), 'success');
    await refresh(false);
    select(item.path);
    import('./app.js').then(m => m.refreshSidebar());
  } catch (e) { toast(e.message, 'error'); }
}

async function newFile(ext) {
  const def = { txt: t('New text file'), md: t('New note'), docx: t('New document'), xlsx: t('New spreadsheet'), pptx: t('New presentation') }[ext] || t('New file');
  const name = await prompt(t('New file'), { value: def + '.' + ext, required: true, okLabel: t('Create'), selectBase: true });
  if (name === null) return;
  try {
    const item = await api.post('files/create_file', { p: S.path, name, template: ext });
    await refresh(false);
    select(item.path);
    openItem(item);
  } catch (e) { toast(e.message, 'error'); }
}

// ---------------------------------------------------------------------------
// Items rendering
// ---------------------------------------------------------------------------
const TYPE_FILTERS = {
  all: { label: t('Everything'), icon: 'layout', test: () => true },
  folders: { label: t('Folders'), icon: 'folder', test: i => i.is_dir },
  images: { label: t('Images'), icon: 'image', test: i => i.kind === 'image' || i.kind === 'raw' },
  videos: { label: t('Videos'), icon: 'film', test: i => i.kind === 'video' },
  audio: { label: t('Audio'), icon: 'music', test: i => i.kind === 'audio' },
  documents: { label: t('Documents'), icon: 'file-text', test: i => ['doc', 'sheet', 'slide', 'pdf', 'ebook'].includes(i.kind) },
  text: { label: t('Text & code'), icon: 'code', test: i => ['text', 'markdown', 'code'].includes(i.kind) },
  archives: { label: t('Archives'), icon: 'archive', test: i => i.kind === 'archive' },
  other: { label: t('Other files'), icon: 'file', test: i => !i.is_dir && ['other', 'font', 'model', 'exe'].includes(i.kind) },
};

function visibleItems() {
  let items = S.items;
  if (S.typeFilter !== 'all' && TYPE_FILTERS[S.typeFilter]) items = items.filter(TYPE_FILTERS[S.typeFilter].test);
  if (!S.filter) return items;
  const f = S.filter.toLowerCase();
  return items.filter(i => i.name.toLowerCase().includes(f));
}

export function renderItems() {
  const items = visibleItems();
  const content = S.el.content;
  clear(content);
  content.className = 'content view-' + S.view + (S.folder && S.folder.root === 'link' ? ' link-ctx' : '');
  if (!items.length) {
    const canUp = S.mode === 'browse' && can('create');
    if (S.mode === 'browse' && !can('read')) content.appendChild(emptyState('upload', t('Drop files here to upload'), t('You can add files to this folder but not see its contents.')));
    else if (S.kind === 'search') content.appendChild(emptyState('search', t('Nothing found'), t('Try another word, or use the filters to search by type, date or size.')));
    else if (S.kind === 'starred') content.appendChild(emptyState('star', t('No starred items'), t('Star files and folders to find them quickly here.')));
    else if (S.kind === 'photos') content.appendChild(emptyState('image', t('No photos yet'), t('Images and videos you upload show up here.')));
    else content.appendChild(emptyState(S.filter ? 'filter' : 'folder-open', S.filter ? t('No matches') : t('This folder is empty'), canUp ? t('Drag files here or use the Upload button.') : '', canUp ? h('button.btn.btn-primary', { onClick: e => uploadMenu(e.currentTarget) }, icon('upload'), t('Upload')) : null));
    return;
  }
  if (S.view === 'list') renderList(content, items); else renderGrid(content, items);
  observeThumbs(content);
  paintSelection();
}

function metaColumns() {
  return (App.features.metadata_fields || []).filter(f => f.show_column);
}

function renderList(content, items) {
  const cols = metaColumns();
  const showLocation = S.mode === 'virtual';
  const head = h('div.list-head',
    h('div.col-check'),
    headCell('name', t('Name'), 'col-name'),
    showLocation ? h('div.col-loc', t('Location')) : null,
    headCell('size', t('Size'), 'col-size'),
    headCell('mtime', t('Modified'), 'col-date'),
    headCell('kind', t('Kind'), 'col-kind'),
    ...cols.map(c => h('div.col-meta', c.name)));
  content.appendChild(head);
  const body = h('div.list-body');
  items.forEach((it, idx) => body.appendChild(renderRow(it, idx, cols, showLocation)));
  content.appendChild(body);
}

function headCell(key, label, cls) {
  const active = S.sort.by === key;
  return h('button.' + cls, { class: 'head-cell' + (active ? ' active' : ''), onClick: () => setSort(key) }, label, active ? icon(S.sort.dir === 'asc' ? 'chevron-up' : 'chevron-down') : null);
}

function badges(it) {
  const b = [];
  if (it.shared) b.push(h('span.badge-ic', { title: it.shared === 'link' ? t('Shared by link') : t('Shared') }, icon(it.shared === 'link' ? 'link' : 'users')));
  if (it.locked_by) b.push(h('span.badge-ic.lock', { title: t('Locked by {name}', { name: it.locked_by_name }) }, icon('lock')));
  if (it.starred) b.push(h('span.badge-ic.star', icon('star')));
  if (it.comments) b.push(h('span.badge-ic', { title: t('{n} comments', { n: it.comments }) }, icon('message'), h('span', it.comments)));
  if (it.label) b.push(labelDot(it.label));
  if (it.rating) b.push(h('span.badge-rating', '★' + it.rating));
  return b;
}

function thumbOrIcon(it, size = 'md', thumbSize = 'small') {
  const wrap = h('span.thumb-wrap.thumb-' + size);
  wrap.appendChild(fileIcon(it, size));
  if (it.thumb && !it.is_dir) {
    const img = h('img.thumb', { alt: '', loading: 'lazy', dataset: { src: api.fileUrl('thumb', { p: it.path, size: thumbSize, v: it.mtime }) } });
    img.addEventListener('load', () => wrap.classList.add('has-thumb'));
    img.addEventListener('error', () => img.remove());
    wrap.appendChild(img);
  }
  return wrap;
}

function renderRow(it, idx, cols, showLocation) {
  const row = h('div.row', { dataset: { idx, path: it.path }, draggable: true, role: 'row' },
    h('div.col-check', h('span.check-box', icon('check'))),
    h('div.col-name', thumbOrIcon(it, 'sm'), h('span.name-text', { title: it.name }, it.name), h('span.badges', badges(it)), it.tags && it.tags.length ? h('span.tags-inline', it.tags.slice(0, 3).map(tg => h('span.tag-chip', { style: tg.color ? { '--tag': tg.color } : {} }, tg.name))) : null, it._content ? h('span.hit-badge', t('in content')) : null, it._meta ? h('span.hit-badge', t('in metadata')) : null),
    showLocation ? h('div.col-loc', { title: locationOf(it) }, locationOf(it)) : null,
    h('div.col-size', it.is_dir ? '—' : fmtSize(it.size)),
    h('div.col-date', { title: fmtDate(it.mtime) }, fmtDateShort(it.mtime)),
    h('div.col-kind', kindLabel(it)),
    ...cols.map(c => h('div.col-meta', fmtMeta(c, it.custom && it.custom[c.key]))));
  if (it.snippet) row.appendChild(h('div.snippet', it.snippet));
  wireItem(row, it, idx);
  return row;
}

function locationOf(it) {
  const p = parentPath(it.path);
  if (!p) return t('My Files');
  if (p === '/') return t('My Files');
  return p.startsWith('@') ? t('Shared') + p.replace(/^@\d+/, '') : decodeURIComponent(p);
}

function fmtMeta(field, v) {
  if (v === undefined || v === null || v === '') return '';
  if (field.type === 'boolean') return v ? t('Yes') : t('No');
  return String(v);
}

export function kindLabel(it) {
  if (it.is_dir) return t('Folder');
  const map = { image: t('Image'), raw: t('RAW image'), video: t('Video'), audio: t('Audio'), pdf: 'PDF', text: t('Text'), code: t('Code'), markdown: 'Markdown', doc: t('Document'), sheet: t('Spreadsheet'), slide: t('Presentation'), archive: t('Archive'), font: t('Font'), ebook: t('E-book'), model: t('3D model'), exe: t('Program'), other: t('File') };
  return (it.ext ? it.ext.toUpperCase() + ' ' : '') + (map[it.kind] || t('File'));
}

function renderGrid(content, items) {
  const gallery = S.view === 'gallery';
  const folders = items.filter(i => i.is_dir);
  const files = items.filter(i => !i.is_dir);
  const wrap = h('div.grid-wrap');
  if (folders.length) {
    const chips = h('div.folder-chips');
    for (const it of folders) {
      const idx = items.indexOf(it);
      const chip = h('div.tile.tile-folder', { dataset: { idx, path: it.path }, draggable: true, title: it.name },
        h('span.check-box', icon('check')), fileIcon(it, 'sm'), h('span.name-text', it.name), h('span.badges', badges(it)));
      wireItem(chip, it, idx);
      chips.appendChild(chip);
    }
    wrap.appendChild(chips);
  }
  const grid = h('div.grid', { class: gallery ? 'gallery' : '' });
  for (const it of files) {
    const idx = items.indexOf(it);
    const textKind = ['text', 'code', 'markdown'].includes(it.kind);
    const preview = h('div.tile-thumb', h('span.check-box', icon('check')));
    if (it.thumb) preview.appendChild(thumbOrIcon(it, 'lg', gallery ? 'medium' : 'small'));
    else if (textKind && !gallery) preview.appendChild(h('div.tile-snippet', { dataset: { path: it.path } }, it._snippet || ''));
    else preview.appendChild(h('div.tile-placeholder', fileIcon(it, 'lg'), h('div.tile-kind', kindLabel(it)), h('div.tile-size', fmtSize(it.size))));
    const tile = gallery
      ? h('div.tile', { dataset: { idx, path: it.path }, draggable: true, title: it.name + ' · ' + fmtSize(it.size) }, preview, h('div.tile-name', h('span.name-text', it.name)), h('div.tile-meta', h('span.badges', badges(it)), h('span.tile-info', fmtSize(it.size))))
      : h('div.tile.tile-card', { dataset: { idx, path: it.path }, draggable: true, title: it.name + ' · ' + fmtSize(it.size) },
        h('div.tile-head', fileIcon(it, 'sm'), h('span.name-text', it.name), it.locked_by ? h('span.tile-lock', { title: t('Locked by {name}', { name: it.locked_by_name }) }, icon('lock')) : null),
        preview,
        h('div.tile-meta', h('span.badges', badges(it)), h('span.tile-info', S.mode === 'virtual' ? locationOf(it) : fmtSize(it.size))));
    wireItem(tile, it, idx);
    grid.appendChild(tile);
  }
  wrap.appendChild(grid);
  content.appendChild(wrap);
  if (!gallery) loadSnippets(files.filter(it => ['text', 'code', 'markdown'].includes(it.kind) && !it.thumb && it._snippet === undefined));
}

/** Fetch the first lines of text files for the grid cards (one request for the whole listing). */
async function loadSnippets(items) {
  if (!items.length) return;
  const gen = S.renderGen;
  try {
    const res = await api.post('files/snippets', { paths: items.slice(0, 100).map(i => i.path) });
    if (gen !== S.renderGen) return;
    for (const it of items) {
      it._snippet = res[it.path] !== undefined ? res[it.path] : '';
      const el = S.el.content.querySelector('.tile-snippet[data-path="' + CSS.escape(it.path) + '"]');
      if (el) { if (it._snippet) el.textContent = it._snippet; else el.replaceWith(h('div.tile-placeholder', fileIcon(it, 'lg'), h('div.tile-kind', kindLabel(it)), h('div.tile-size', fmtSize(it.size)))); }
    }
  } catch (e) { /* previews are optional */ }
}

function observeThumbs(root) {
  const imgs = $$('img.thumb[data-src]', root);
  if (!imgs.length) return;
  const io = new IntersectionObserver(entries => {
    for (const en of entries) {
      if (en.isIntersecting) { const img = en.target; img.src = img.dataset.src; delete img.dataset.src; io.unobserve(img); }
    }
  }, { root: root, rootMargin: '300px' });
  imgs.forEach(i => io.observe(i));
}

// ---------------------------------------------------------------------------
// Selection
// ---------------------------------------------------------------------------
function itemEls() { return $$('.row, .tile', S.el.content); }
export function selectedItems() { return S.items.filter(i => S.selection.has(i.path)); }
function itemByPath(p) { return S.items.find(i => i.path === p); }

function paintSelection() {
  for (const el of itemEls()) {
    el.classList.toggle('selected', S.selection.has(el.dataset.path));
    el.classList.toggle('focused', Number(el.dataset.idx) === S.focus);
  }
  renderToolbar();
  renderStatus();
  if (S.detailsOpen) renderDetails();
}

export function select(path, opts = {}) {
  if (!opts.add) S.selection.clear();
  S.selection.add(path);
  const vis = visibleItems();
  S.focus = vis.findIndex(i => i.path === path); S.anchor = S.focus;
  paintSelection();
  const el = itemEls().find(e => e.dataset.path === path);
  if (el) el.scrollIntoView({ block: 'nearest' });
}

export function clearSelection() { S.selection.clear(); S.focus = -1; paintSelection(); }

function wireItem(el, it, idx) {
  el.addEventListener('click', e => {
    if (e.target.closest('.check-box')) { toggle(it, idx); return; }
    const vis = visibleItems();
    if (e.shiftKey && S.anchor >= 0) {
      const [a, b] = [Math.min(S.anchor, idx), Math.max(S.anchor, idx)];
      if (!e.ctrlKey && !e.metaKey) S.selection.clear();
      for (let i = a; i <= b; i++) S.selection.add(vis[i].path);
      S.focus = idx;
    } else if (e.ctrlKey || e.metaKey) {
      toggle(it, idx); return;
    } else {
      if (window.matchMedia('(pointer: coarse)').matches && !S.selection.size) { openItem(it); return; }
      S.selection.clear(); S.selection.add(it.path); S.anchor = idx; S.focus = idx;
    }
    paintSelection();
  });
  el.addEventListener('dblclick', e => { if (!e.target.closest('.check-box')) openItem(it); });
  el.addEventListener('contextmenu', e => {
    e.preventDefault();
    if (!S.selection.has(it.path)) { S.selection.clear(); S.selection.add(it.path); S.anchor = idx; S.focus = idx; paintSelection(); }
    menu(itemActions(selectedItems()), { x: e.clientX, y: e.clientY });
  });
  el.addEventListener('dragstart', e => {
    if (!S.selection.has(it.path)) { S.selection.clear(); S.selection.add(it.path); paintSelection(); }
    const paths = [...S.selection];
    e.dataTransfer.setData('application/x-fr-paths', JSON.stringify(paths));
    e.dataTransfer.setData('text/plain', paths.map(p => baseName(p)).join('\n'));
    e.dataTransfer.effectAllowed = 'copyMove';
    const ghost = h('div.drag-ghost', icon(it.is_dir ? 'folder' : 'file'), paths.length > 1 ? t('{n} items', { n: paths.length }) : it.name);
    document.body.appendChild(ghost);
    e.dataTransfer.setDragImage(ghost, 10, 10);
    setTimeout(() => ghost.remove(), 0);
  });
  if (it.is_dir) makeDropTarget(el, it.path);
}

function toggle(it, idx) {
  if (S.selection.has(it.path)) S.selection.delete(it.path); else S.selection.add(it.path);
  S.anchor = idx; S.focus = idx;
  paintSelection();
}

export function openItem(it) {
  if (it.is_dir) { App.openFolder(it.path); return; }
  if (!perms().read && S.folder.root !== 'virtual') return;
  const vis = visibleItems().filter(i => !i.is_dir);
  openPreview(vis, vis.findIndex(i => i.path === it.path), { folderPerms: perms(), onChange: () => refresh() });
}

// ---------------------------------------------------------------------------
// Drag & drop + keyboard on the content area
// ---------------------------------------------------------------------------
function makeDropTarget(el, folderPath) {
  el.addEventListener('dragover', e => {
    const hasPaths = e.dataTransfer.types.includes('application/x-fr-paths');
    const hasFiles = e.dataTransfer.types.includes('Files');
    if (!hasPaths && !hasFiles) return;
    if (hasPaths && S.selection.has(folderPath)) return;
    e.preventDefault(); e.stopPropagation();
    e.dataTransfer.dropEffect = hasFiles ? 'copy' : (e.ctrlKey || e.altKey ? 'copy' : 'move');
    el.classList.add('drop-target');
  });
  el.addEventListener('dragleave', () => el.classList.remove('drop-target'));
  el.addEventListener('drop', e => {
    el.classList.remove('drop-target');
    const raw = e.dataTransfer.getData('application/x-fr-paths');
    if (raw) {
      e.preventDefault(); e.stopPropagation();
      const paths = JSON.parse(raw).filter(p => p !== folderPath && !folderPath.startsWith(p + '/'));
      if (paths.length) transfer(e.ctrlKey || e.altKey ? 'copy' : 'move', paths, folderPath);
      return;
    }
    if (e.dataTransfer.files && e.dataTransfer.files.length) {
      e.preventDefault(); e.stopPropagation();
      Uploads.handleDrop(e.dataTransfer, folderPath);
    }
  });
}

function wireContent(content) {
  // Dropping OS files anywhere in the listing uploads into the current folder.
  content.addEventListener('dragover', e => {
    if (!e.dataTransfer.types.includes('Files') || S.mode !== 'browse' || !can('create')) return;
    e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; content.classList.add('drop-active');
  });
  content.addEventListener('dragleave', e => { if (e.target === content) content.classList.remove('drop-active'); });
  content.addEventListener('drop', e => {
    content.classList.remove('drop-active');
    if (e.dataTransfer.files && e.dataTransfer.files.length && S.mode === 'browse' && can('create')) { e.preventDefault(); Uploads.handleDrop(e.dataTransfer, S.path); }
  });
  content.addEventListener('mousedown', e => { if (e.target === content || e.target.classList.contains('grid') || e.target.classList.contains('list-body')) { clearSelection(); } });
  content.addEventListener('contextmenu', e => {
    if (e.target.closest('.row, .tile')) return;
    e.preventDefault();
    if (S.mode === 'browse') menu(folderMenuItems(), { x: e.clientX, y: e.clientY });
  });
  content.addEventListener('keydown', onKey);
  // Shortcuts also work when nothing in particular has the focus (e.g. after a dialog closed).
  if (!S.docKeys) {
    S.docKeys = true;
    document.addEventListener('keydown', e => {
      if (!S.el || !S.el.content || !S.el.content.isConnected) return;
      if (e.target !== document.body && e.target !== document.documentElement) return;
      if (document.querySelector('.overlay, .menu, .viewer')) return;
      onKey(e);
    });
  }
  content.addEventListener('paste', e => {
    if (S.mode !== 'browse' || !can('create')) return;
    const files = [...(e.clipboardData.files || [])];
    if (files.length) { e.preventDefault(); Uploads.uploadFiles(files.map(f => ({ file: f, relpath: f.name })), S.path); }
  });
}

function onKey(e) {
  const vis = visibleItems();
  const n = vis.length;
  const key = e.key;
  const cols = S.view === 'list' ? 1 : columnsCount();
  const move = (to) => {
    if (!n) return;
    to = Math.max(0, Math.min(n - 1, to));
    if (e.shiftKey && S.anchor >= 0) { S.selection.clear(); const [a, b] = [Math.min(S.anchor, to), Math.max(S.anchor, to)]; for (let i = a; i <= b; i++) S.selection.add(vis[i].path); }
    else { S.selection.clear(); S.selection.add(vis[to].path); S.anchor = to; }
    S.focus = to; paintSelection();
    const el = itemEls()[to]; if (el) el.scrollIntoView({ block: 'nearest' });
  };
  if (key === 'ArrowDown') { e.preventDefault(); move(S.focus < 0 ? 0 : S.focus + cols); }
  else if (key === 'ArrowUp') { e.preventDefault(); move(S.focus < 0 ? 0 : S.focus - cols); }
  else if (key === 'ArrowRight' && cols > 1) { e.preventDefault(); move(S.focus + 1); }
  else if (key === 'ArrowLeft' && cols > 1) { e.preventDefault(); move(S.focus - 1); }
  else if (key === 'Home') { e.preventDefault(); move(0); }
  else if (key === 'End') { e.preventDefault(); move(n - 1); }
  else if (key === 'Enter') { e.preventDefault(); const sel = selectedItems(); if (sel.length === 1) openItem(sel[0]); }
  else if (key === ' ') { e.preventDefault(); const sel = selectedItems(); if (sel.length === 1 && !sel[0].is_dir) openItem(sel[0]); }
  else if (key === 'Backspace' && S.mode === 'browse') { const p = parentPath(S.path); if (p) App.openFolder(p); else if (S.path.startsWith('@')) App.navigate('#/shared'); }
  else if ((e.ctrlKey || e.metaKey) && key.toLowerCase() === 'a') { e.preventDefault(); vis.forEach(i => S.selection.add(i.path)); paintSelection(); }
  else if (key === 'Escape') { clearSelection(); }
  else if (key === 'Delete') { const sel = selectedItems(); if (sel.length) deleteItems(sel); }
  else if (key === 'F2') { const sel = selectedItems(); if (sel.length === 1) renameItem(sel[0]); }
  else if ((e.ctrlKey || e.metaKey) && key.toLowerCase() === 'c') { const sel = selectedItems(); if (sel.length) { App.clipboard = { op: 'copy', paths: sel.map(i => i.path) }; toast(t('{n} items copied', { n: sel.length })); renderToolbar(); } }
  else if ((e.ctrlKey || e.metaKey) && key.toLowerCase() === 'x') { const sel = selectedItems(); if (sel.length) { App.clipboard = { op: 'cut', paths: sel.map(i => i.path) }; toast(t('{n} items cut', { n: sel.length })); renderToolbar(); } }
  else if ((e.ctrlKey || e.metaKey) && key.toLowerCase() === 'v') { if (App.clipboard) { pasteClipboard(); } }
  else if (!e.ctrlKey && !e.metaKey && !e.altKey) {
    if (key === 'N' && e.shiftKey && S.mode === 'browse' && can('create')) { e.preventDefault(); newFolder(); }
    else if ((key === 'u' || key === 'U') && S.mode === 'browse' && can('create')) { e.preventDefault(); Uploads.pickFiles(S.path, false); }
    else if (key === '1') setView('list'); else if (key === '2') setView('grid'); else if (key === '3') setView('gallery');
    else if (key.length === 1 && /\S/.test(key)) typeAhead(key);
  }
}

let typeBuf = '', typeTimer;
function typeAhead(ch) {
  clearTimeout(typeTimer); typeBuf += ch.toLowerCase();
  typeTimer = setTimeout(() => { typeBuf = ''; }, 800);
  const vis = visibleItems();
  const idx = vis.findIndex(i => i.name.toLowerCase().startsWith(typeBuf));
  if (idx >= 0) { S.selection.clear(); S.selection.add(vis[idx].path); S.focus = idx; S.anchor = idx; paintSelection(); itemEls()[idx]?.scrollIntoView({ block: 'nearest' }); }
}

function columnsCount() {
  const tiles = itemEls();
  if (tiles.length < 2) return 1;
  const top = tiles[0].offsetTop;
  let n = 0;
  for (const tl of tiles) { if (tl.offsetTop !== top) break; n++; }
  return Math.max(1, n);
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
function primaryActions(sel) {
  const p = perms();
  const one = sel.length === 1 ? sel[0] : null;
  const out = [];
  if (p.download) out.push({ icon: 'download', label: t('Download'), onClick: () => api.download(sel.map(i => i.path)) });
  if (p.share && S.mode === 'browse') out.push({ icon: 'share', label: t('Share'), onClick: () => Share.shareDialog(one || sel[0]).then(() => refresh()), disabled: sel.length !== 1 });
  if (p.rename && one && S.mode === 'browse') out.push({ icon: 'edit', label: t('Rename'), onClick: () => renameItem(one) });
  if (p.rename && S.mode === 'browse') out.push({ icon: 'folder-input', label: t('Move'), onClick: () => moveCopyDialog('move', sel) });
  if (p.delete) out.push({ icon: 'trash', label: t('Delete'), onClick: () => deleteItems(sel) });
  return out;
}

export function itemActions(sel, ctxPerms = null) {
  const p = ctxPerms || perms();
  const one = sel.length === 1 ? sel[0] : null;
  const allStarred = sel.every(i => i.starred);
  const items = [];
  if (one) items.push({ label: one.is_dir ? t('Open') : t('Preview'), icon: one.is_dir ? 'folder-open' : 'eye', onClick: () => openItem(one) });
  if (one && !one.is_dir && (one.kind === 'text' || one.kind === 'code' || one.kind === 'markdown') && p.edit) items.push({ label: t('Edit'), icon: 'edit', onClick: () => openPreview([one], 0, { edit: true, folderPerms: p, onChange: () => refresh() }) });
  if (one && !one.is_dir && one.kind === 'image' && p.edit) items.push({ label: t('Edit image'), icon: 'crop', onClick: () => openPreview([one], 0, { imageEdit: true, folderPerms: p, onChange: () => refresh() }) });
  if (one && !one.is_dir && App.features.onlyoffice && ['doc', 'sheet', 'slide', 'pdf'].includes(one.kind)) items.push({ label: t('Open in office editor'), icon: 'external', onClick: () => openPreview([one], 0, { office: true, folderPerms: p, onChange: () => refresh() }) });
  if (p.download) items.push({ label: sel.length > 1 || (one && one.is_dir) ? t('Download as ZIP') : t('Download'), icon: 'download', onClick: () => api.download(sel.map(i => i.path)) });
  items.push({ sep: true });
  if (p.share && one && S.mode !== 'virtual' && !App.isLinkMode) {
    items.push({ label: t('Share…'), icon: 'users', onClick: () => Share.shareDialog(one).then(() => refresh()) });
    if (App.user && App.user.perms.share_links && App.settings.links_enabled) items.push({ label: t('Get link…'), icon: 'link', onClick: () => Share.linkDialog(one).then(() => refresh()) });
  }
  if (App.user) items.push({ label: allStarred ? t('Remove star') : t('Star'), icon: 'star', onClick: () => starItems(sel, !allStarred) });
  if (p.meta) {
    items.push({ label: t('Label'), icon: 'flag', submenu: [...Object.keys(LABEL_COLORS).map(l => ({ label: t(l), color: LABEL_COLORS[l], checked: one && one.label === l, onClick: () => setLabel(sel, l) })), { sep: true }, { label: t('None'), onClick: () => setLabel(sel, '') }] });
    items.push({ label: t('Tags…'), icon: 'tag', onClick: () => tagsDialog(sel) });
  }
  if (App.user && !App.isLinkMode) {
    items.push(Collections.menuItems(sel, () => { if (S.kind === 'collection') refresh(); }));
    if (S.kind === 'collection' && S.params.id) items.push({ label: t('Remove from collection'), icon: 'x', onClick: async () => { try { await api.post('collections/remove', { id: S.params.id, paths: sel.map(i => i.path) }); await refresh(false); import('./app.js').then(m => m.refreshSidebar()); } catch (e) { toast(e.message, 'error'); } } });
  }
  if (one && p.lock && !one.is_dir && App.user) items.push(one.locked_by ? { label: t('Unlock'), icon: 'unlock', onClick: () => lockItem(one, false) } : { label: t('Lock'), icon: 'lock', onClick: () => lockItem(one, true) });
  if (one && App.user && !App.isLinkMode) items.push({ label: one.following ? t('Unfollow') : t('Follow (get notified)'), icon: 'bell', onClick: () => followItem(one, !one.following) });
  items.push({ sep: true });
  if (p.rename && one) items.push({ label: t('Rename'), icon: 'edit', hint: 'F2', onClick: () => renameItem(one) });
  if (p.rename && S.mode === 'browse') items.push({ label: t('Move to…'), icon: 'folder-input', onClick: () => moveCopyDialog('move', sel) });
  if (p.download && App.user) items.push({ label: t('Copy to…'), icon: 'copy', onClick: () => moveCopyDialog('copy', sel) });
  if (App.user && S.mode === 'browse') {
    items.push({ label: t('Copy'), icon: 'copy', hint: 'Ctrl+C', onClick: () => { App.clipboard = { op: 'copy', paths: sel.map(i => i.path) }; renderToolbar(); } });
    if (p.rename) items.push({ label: t('Cut'), icon: 'scissors', hint: 'Ctrl+X', onClick: () => { App.clipboard = { op: 'cut', paths: sel.map(i => i.path) }; renderToolbar(); } });
  }
  if (one && !one.is_dir && one.kind === 'archive' && p.create) items.push({ label: t('Extract here'), icon: 'archive', onClick: () => extractItem(one) });
  if (p.delete) items.push({ label: t('Delete'), icon: 'trash', danger: true, hint: 'Del', onClick: () => deleteItems(sel) });
  items.push({ sep: true });
  if (one) items.push({ label: t('Details'), icon: 'info', onClick: () => { S.detailsOpen = true; App.setPref('details', true); renderToolbar(); renderDetails(); } });
  if (one && S.mode === 'virtual' && !one.is_dir) items.push({ label: t('Show in folder'), icon: 'folder', onClick: () => { App.openFolder(parentPath(one.path) || '/'); } });
  if (one) items.push({ label: t('Copy path'), icon: 'clipboard', onClick: () => copyText(one.path).then(() => toast(t('Copied'), 'success')) });
  return items.filter((it, i, arr) => !(it.sep && (i === 0 || i === arr.length - 1 || (arr[i - 1] && arr[i - 1].sep))));
}

function folderMenuItems() {
  const items = [];
  if (can('create')) items.push(...newMenuItems(), { sep: true }, { label: t('Upload files'), icon: 'upload', onClick: () => Uploads.pickFiles(S.path, false) }, { label: t('Upload folder'), icon: 'folder-input', onClick: () => Uploads.pickFiles(S.path, true) });
  if (App.clipboard && can('create')) items.push({ label: t('Paste'), icon: 'clipboard', hint: 'Ctrl+V', onClick: pasteClipboard });
  items.push({ sep: true }, { label: t('Select all'), icon: 'check', hint: 'Ctrl+A', onClick: () => { visibleItems().forEach(i => S.selection.add(i.path)); paintSelection(); } }, { label: t('Refresh'), icon: 'refresh', onClick: () => refresh() });
  if (S.mode === 'browse' && S.folder.root !== 'link') items.push({ label: S.folder.following ? t('Unfollow this folder') : t('Follow this folder'), icon: 'bell', onClick: () => followPath(S.path, !S.folder.following) });
  if (S.mode === 'browse' && can('share') && S.path !== '/' && !App.isLinkMode) items.push({ label: t('Share this folder…'), icon: 'users', onClick: () => Share.shareDialog({ path: S.path, name: S.folder.name, is_dir: true }) });
  items.push({ label: t('Folder details'), icon: 'info', onClick: () => { S.selection.clear(); S.detailsOpen = true; App.setPref('details', true); renderToolbar(); renderDetails(); } });
  return items;
}

function folderMenu(anchor) { menu(folderMenuItems(), anchor); }

async function pasteClipboard() {
  const cb = App.clipboard;
  if (!cb || S.mode !== 'browse') return;
  await transfer(cb.op === 'cut' ? 'move' : 'copy', cb.paths, S.path);
  if (cb.op === 'cut') App.clipboard = null;
  renderToolbar();
}

async function transfer(op, paths, to) {
  try {
    const res = await api.post('files/' + op, { paths, to, conflict: 'rename' });
    if (res.errors && res.errors.length) {
      const conflicts = res.errors.filter(e => /exists/i.test(e.error));
      toast(res.errors[0].error + (res.errors.length > 1 ? ' (+' + (res.errors.length - 1) + ')' : ''), 'error', 6000);
      if (conflicts.length) { /* nothing */ }
    } else toast(op === 'move' ? t('{n} items moved', { n: res.done.length }) : t('{n} items copied', { n: res.done.length }), 'success');
    await refresh(false);
    import('./app.js').then(m => m.refreshSidebar());
  } catch (e) { toast(e.message, 'error'); }
}

async function renameItem(it) {
  // Inline rename in the current row/tile when visible, else a prompt.
  const el = itemEls().find(e => e.dataset.path === it.path);
  const nameEl = el && el.querySelector('.name-text');
  const commit = async (name) => {
    name = (name || '').trim();
    if (!name || name === it.name) return;
    try {
      const updated = await api.post('files/rename', { p: it.path, name });
      await refresh(false);
      select(updated.path);
      if (it.is_dir) import('./app.js').then(m => m.refreshSidebar());
    } catch (e) { toast(e.message, 'error'); }
  };
  if (nameEl) {
    const input = h('input.rename-input', { value: it.name });
    nameEl.replaceWith(input);
    let done = false;
    const finish = (save) => { if (done) return; done = true; const v = input.value; input.replaceWith(nameEl); if (S.el && S.el.content) S.el.content.focus({ preventScroll: true }); if (save) commit(v); };
    input.addEventListener('keydown', e => { e.stopPropagation(); if (e.key === 'Enter') finish(true); if (e.key === 'Escape') finish(false); });
    input.addEventListener('blur', () => finish(true));
    input.addEventListener('click', e => e.stopPropagation());
    input.addEventListener('dblclick', e => e.stopPropagation());
    input.focus();
    const dot = it.is_dir ? -1 : it.name.lastIndexOf('.');
    input.setSelectionRange(0, dot > 0 ? dot : it.name.length);
  } else {
    const name = await prompt(t('Rename'), { value: it.name, required: true, selectBase: !it.is_dir, okLabel: t('Rename') });
    if (name !== null) commit(name);
  }
}

export async function deleteItems(sel, permanent = false) {
  const inTrash = S.mode === 'browse' && App.user && App.user.perms.trash && App.settings && !permanent && S.folder.root !== 'link';
  const msg = sel.length === 1 ? t('Delete "{name}"?', { name: sel[0].name }) : t('Delete {n} items?', { n: sel.length });
  const ok = await confirm(msg + (inTrash ? ' ' + t('You can restore them from the trash.') : ' ' + t('This cannot be undone.')), { okLabel: t('Delete'), danger: true });
  if (!ok) return;
  try {
    const res = await api.post('files/delete', { paths: sel.map(i => i.path), permanent });
    if (res.errors.length) toast(res.errors[0].error, 'error', 6000); else toast(t('{n} items deleted', { n: res.done.length }), 'success');
    S.selection.clear();
    await refresh(false);
    import('./app.js').then(m => m.refreshSidebar());
  } catch (e) { toast(e.message, 'error'); }
}

async function starItems(sel, on) {
  try { await api.post('files/star', { paths: sel.map(i => i.path), on }); sel.forEach(i => { i.starred = on; }); renderItems(); import('./app.js').then(m => m.refreshSidebar()); } catch (e) { toast(e.message, 'error'); }
}

async function setLabel(sel, label) {
  try { await api.post('files/label', { paths: sel.map(i => i.path), label }); sel.forEach(i => { i.label = label || null; }); renderItems(); if (S.detailsOpen) renderDetails(); } catch (e) { toast(e.message, 'error'); }
}

async function lockItem(it, on) {
  try { await api.post(on ? 'files/lock' : 'files/unlock', { p: it.path }); await refresh(); } catch (e) { toast(e.message, 'error'); }
}

async function followItem(it, on) {
  try { await api.post('files/follow', { p: it.path, on }); it.following = on; toast(on ? t('You will be notified about changes') : t('Unfollowed'), 'success'); if (S.detailsOpen) renderDetails(); } catch (e) { toast(e.message, 'error'); }
}
async function followPath(p, on) {
  try { await api.post('files/follow', { p, on }); S.folder.following = on; toast(on ? t('You will be notified about changes in this folder') : t('Unfollowed'), 'success'); } catch (e) { toast(e.message, 'error'); }
}

async function extractItem(it) {
  const tst = toast(t('Extracting…'), 'info', 0);
  try { await api.post('files/extract', { p: it.path, to: S.path }); tst.remove(); toast(t('Archive extracted'), 'success'); await refresh(false); } catch (e) { tst.remove(); toast(e.message, 'error'); }
}

async function tagsDialog(sel) {
  const current = sel.length === 1 ? (sel[0].tags || []).map(x => x.name) : [];
  let all = [];
  try { all = App.user ? await api.get('files/tags_list') : []; } catch (e) { all = []; }
  const chips = h('div.chips');
  const input = h('input.input', { placeholder: t('Add a tag and press Enter'), list: 'taglist' });
  const dl = h('datalist#taglist', all.map(tg => h('option', { value: tg.name })));
  const tagsSet = new Set(current);
  const paint = () => replace(chips, [...tagsSet].map(n => h('span.chip', n, h('button.chip-x', { type: 'button', onClick: () => { tagsSet.delete(n); paint(); } }, icon('x')))));
  paint();
  input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const v = input.value.trim(); if (v) { tagsSet.add(v); input.value = ''; paint(); } } });
  await dialog({
    title: t('Tags'), width: '460px',
    body: h('div.no-enter', chips, input, dl, all.length ? h('div.tag-suggest', all.slice(0, 20).map(tg => h('button.chip.chip-btn', { type: 'button', onClick: () => { tagsSet.add(tg.name); paint(); } }, tg.name))) : null),
    buttons: [{ label: t('Cancel') }, { label: t('Save'), primary: true, onClick: async () => { await api.post('files/tags', { paths: sel.map(i => i.path), tags: [...tagsSet] }); } }],
  }).result;
  await refresh();
  import('./app.js').then(m => m.refreshSidebar());
}

// ---------------------------------------------------------------------------
// Folder picker (move / copy / restore to)
// ---------------------------------------------------------------------------
export function folderPicker(title, opts = {}) {
  return new Promise(resolve => {
    let current = opts.start && !opts.start.startsWith('~') ? opts.start : '/';
    const pathEl = h('div.picker-path');
    const list = h('div.picker-list');
    const load = async (p) => {
      current = p;
      replace(pathEl, icon('folder'), h('span', p.startsWith('@') ? t('Shared') + p.slice(p.indexOf('/') >= 0 ? p.indexOf('/') : p.length) : (p === '/' ? t('My Files') : p)));
      replace(list, spinner());
      try {
        const subs = await api.get('files/tree', { p });
        clear(list);
        const up = parentPath(p);
        if (up || p.startsWith('@')) list.appendChild(h('button.picker-row', { onClick: () => up ? load(up) : loadRoots() }, icon('arrow-up'), t('Up')));
        for (const s of subs) {
          const disabled = (opts.exclude || []).some(x => s.path === x || s.path.startsWith(x + '/'));
          list.appendChild(h('button.picker-row', { disabled, onClick: () => load(s.path) }, icon('folder'), h('span', s.name), icon('chevron-right', 'picker-arrow')));
        }
        if (!subs.length) list.appendChild(h('div.picker-empty', t('No sub folders')));
      } catch (e) { replace(list, h('div.picker-empty', e.message)); }
    };
    const loadRoots = async () => {
      current = '~roots';
      replace(pathEl, icon('home'), h('span', t('Choose a location')));
      replace(list, h('button.picker-row', { onClick: () => load('/') }, icon('home'), h('span', t('My Files')), icon('chevron-right', 'picker-arrow')));
      const shared = (App.sidebar && App.sidebar.shared) || [];
      for (const s of shared) if (s.is_dir && s.perms.create) list.appendChild(h('button.picker-row', { onClick: () => load(s.vpath) }, icon('users'), h('span', s.name), icon('chevron-right', 'picker-arrow')));
    };
    const dlg = dialog({
      title, width: '480px',
      body: h('div.picker', pathEl, list),
      buttons: [
        { label: t('New folder'), cls: 'btn-ghost', onClick: async () => { const name = await prompt(t('New folder'), { value: t('New folder'), required: true }); if (name) { await api.post('files/mkdir', { p: current, name }); load(current); } return false; } },
        { label: t('Cancel'), value: null },
        { label: opts.okLabel || t('Choose'), primary: true, onClick: () => current.startsWith('~') ? false : current },
      ],
      onClose: v => resolve(v || null),
    });
    if (opts.roots) loadRoots(); else load(current);
  });
}

async function moveCopyDialog(op, sel) {
  const dest = await folderPicker(op === 'move' ? t('Move {n} items to…', { n: sel.length }) : t('Copy {n} items to…', { n: sel.length }), { start: S.mode === 'browse' ? S.path : '/', exclude: sel.filter(i => i.is_dir).map(i => i.path), okLabel: op === 'move' ? t('Move here') : t('Copy here'), roots: S.mode !== 'browse' });
  if (dest) transfer(op, sel.map(i => i.path), dest);
}

// ---------------------------------------------------------------------------
// Status bar
// ---------------------------------------------------------------------------
function renderStatus() {
  if (!S.el) return;
  const vis = visibleItems();
  const sel = selectedItems();
  const size = (sel.length ? sel : vis).reduce((a, i) => a + (i.is_dir ? 0 : i.size), 0);
  const dirs = vis.filter(i => i.is_dir).length;
  const parts = [];
  if (sel.length) parts.push(t('{n} selected', { n: sel.length }) + ' · ' + fmtSize(size));
  else parts.push([dirs ? t('{n} folders', { n: dirs }) : null, t('{n} files', { n: vis.length - dirs })].filter(Boolean).join(', ') + (size ? ' · ' + fmtSize(size) : ''));
  const right = [];
  if (S.mode === 'browse' && S.folder.root === 'share') right.push(h('span', t('Shared by {name} · your role: {role}', { name: S.folder.owner.name, role: S.folder.role_label })));
  if (S.mode === 'browse' && S.folder.root === 'link') right.push(h('span', t('Public link · {role}', { role: S.folder.role_label })));
  replace(S.el.status, h('span', parts.join(' ')), h('span.status-right', ...right));
}

// ---------------------------------------------------------------------------
// Sidebar tree
// ---------------------------------------------------------------------------
export function renderTree(container, nodes, parentPathStr = '/', depth = 0) {
  for (const n of nodes) {
    const row = h('div.tree-row', { style: { paddingLeft: (10 + depth * 14) + 'px' } },
      h('button.tree-toggle', { class: n.has_children ? '' : 'leaf', 'aria-label': t('Expand') }, icon('chevron-right')),
      h('a.tree-link', { href: App.filesHash(n.path), class: S.mode === 'browse' && S.path === n.path ? 'active' : '', title: n.name }, icon('folder'), h('span', n.name)));
    const children = h('div.tree-children', { hidden: true });
    let loaded = false;
    const toggle = async () => {
      if (!n.has_children) return;
      const open = children.hidden;
      row.classList.toggle('open', open);
      children.hidden = !open;
      if (open && !loaded) {
        loaded = true;
        try { const subs = await api.get('files/tree', { p: n.path }); clear(children); renderTree(children, subs, n.path, depth + 1); } catch (e) { loaded = false; }
      }
    };
    row.querySelector('.tree-toggle').addEventListener('click', e => { e.preventDefault(); toggle(); });
    makeDropTarget(row, n.path);
    container.appendChild(row); container.appendChild(children);
    if (S.mode === 'browse' && S.path.startsWith(n.path + '/') && n.has_children) toggle();
  }
}

// ---------------------------------------------------------------------------
// Details panel
// ---------------------------------------------------------------------------
export async function renderDetails() {
  const aside = App.layout ? App.layout.details : $('.details');
  if (!aside) return;
  aside.hidden = !S.detailsOpen;
  if (!S.detailsOpen) return;
  const sel = selectedItems();
  if (sel.length > 1) { replace(aside, multiDetails(sel)); return; }
  const target = sel[0] || (S.mode === 'browse' ? { path: S.path, name: S.folder.name, is_dir: true, _folder: true } : null);
  if (!target) { replace(aside, h('div.details-empty', icon('info'), t('Select an item to see its details'))); return; }
  const key = target.path;
  if (S.detailsKey === key && S.detailsTab !== 'details' && aside.dataset.path === key) return; // keep tab content while browsing
  aside.dataset.path = key;
  S.detailsKey = key;
  replace(aside, h('div.details-loading', spinner()));
  let item;
  try { item = await api.get('files/info', { p: target.path }); } catch (e) { replace(aside, h('div.details-empty', e.message)); return; }
  if (aside.dataset.path !== key) return;
  S.detailsItem = item;
  const head = h('div.details-head',
    h('div.details-preview', item.thumb ? h('img', { src: api.fileUrl('thumb', { p: item.path, size: 'medium', v: item.mtime }), alt: '', onError: e => e.target.replaceWith(fileIcon(item, 'xl')) }) : fileIcon(item, 'xl')),
    h('div.details-title', { title: item.name }, item.name),
    h('button.icon-btn.details-close', { onClick: toggleDetails, 'aria-label': t('Close') }, icon('x')));
  const body = h('div.details-body');
  const tabList = [{ id: 'details', label: t('Details'), icon: 'info' }, { id: 'activity', label: t('Activity'), icon: 'activity' }];
  if (item.perms.comment || item.comments) tabList.push({ id: 'comments', label: t('Comments'), icon: 'message', badge: item.comments || null });
  if (!item.is_dir && App.user) tabList.push({ id: 'versions', label: t('Versions'), icon: 'history', badge: item.versions || null });
  if (item.perms.share && !App.isLinkMode && !item._folder) tabList.push({ id: 'sharing', label: t('Sharing'), icon: 'share' });
  const tb = tabs(tabList, S.detailsTab && tabList.some(x => x.id === S.detailsTab) ? S.detailsTab : 'details', id => { S.detailsTab = id; renderDetailsTab(body, item, id); });
  replace(aside, head, tb.el, body);
  renderDetailsTab(body, item, S.detailsTab && tabList.some(x => x.id === S.detailsTab) ? S.detailsTab : 'details');
}

function multiDetails(sel) {
  const size = sel.reduce((a, i) => a + (i.is_dir ? 0 : i.size), 0);
  return h('div.details-multi', h('div.details-head', h('div.details-preview', icon('layout')), h('div.details-title', t('{n} items', { n: sel.length }))),
    h('div.details-body', h('dl.kv', h('dt', t('Total size')), h('dd', fmtSize(size)), h('dt', t('Folders')), h('dd', sel.filter(i => i.is_dir).length), h('dt', t('Files')), h('dd', sel.filter(i => !i.is_dir).length)),
      perms().meta ? h('div.details-section', h('h4', t('Label')), labelPicker(null, l => setLabel(sel, l))) : null,
      h('div.details-actions', ...primaryActions(sel).map(a => h('button.btn.btn-sm', { onClick: a.onClick }, icon(a.icon), a.label)))));
}

function labelPicker(current, onPick) {
  return h('div.label-picker', ...Object.keys(LABEL_COLORS).map(l => h('button.label-swatch', { class: current === l ? 'on' : '', title: t(l), style: { background: LABEL_COLORS[l] }, onClick: () => onPick(current === l ? '' : l) })), h('button.label-swatch.none', { title: t('None'), onClick: () => onPick('') }, icon('x')));
}

async function renderDetailsTab(body, item, tab) {
  clear(body);
  if (tab === 'details') return detailsTab(body, item);
  body.appendChild(spinner());
  try {
    if (tab === 'activity') {
      const rows = await api.get('files/activity', { p: item.path });
      replace(body, rows.length ? h('ul.activity-list', rows.map(r => h('li', h('div.act-main', h('b', r.user_name), ' ', actionLabel(r)), h('div.act-sub', relTime(r.created_at), r.ip ? ' · ' + r.ip : '', r.client !== 'web' ? ' · ' + r.client : '', r.status === 'failed' ? h('span.act-failed', ' · ' + t('failed') + (r.reason ? ': ' + r.reason : '')) : null)))) : emptyState('activity', t('No activity yet')));
    } else if (tab === 'comments') {
      await commentsTab(body, item);
    } else if (tab === 'versions') {
      await versionsTab(body, item);
    } else if (tab === 'sharing') {
      await Share.sharingPanel(body, item, () => refresh());
    }
  } catch (e) { replace(body, h('div.details-empty', e.message)); }
}

export function actionLabel(r) {
  const map = { upload: t('uploaded'), download: t('downloaded'), preview: t('previewed'), create_folder: t('created the folder'), create_file: t('created'), edit: t('edited'), rename: t('renamed'), move: t('moved'), copy: t('copied'), delete: t('deleted'), restore: t('restored'), purge: t('permanently deleted'), version_restore: t('restored a version'), lock: t('locked'), unlock: t('unlocked'), share_create: t('shared'), share_update: t('changed a share'), share_delete: t('removed a share'), link_create: t('created a link'), link_update: t('changed a link'), link_delete: t('deleted a link'), link_access: t('opened a link'), link_download: t('downloaded via link'), link_upload: t('uploaded via link'), comment: t('commented'), tag: t('tagged'), star: t('starred'), metadata: t('changed metadata'), follow: t('followed'), unfollow: t('unfollowed'), zip: t('downloaded as zip'), search: t('searched'), login: t('signed in'), logout: t('signed out') };
  const base = map[r.action] || r.action;
  const name = r.path ? r.path.split('/').pop() : '';
  return r.path2 ? base + ' ' + name + ' → ' + r.path2.split('/').pop() : (name ? base + ' ' + name : base);
}

function detailsTab(body, item) {
  const p = item.perms || {};
  const kv = [[t('Type'), kindLabel(item)]];
  if (!item.is_dir) kv.push([t('Size'), fmtSize(item.size)]); else if (item.children !== undefined) kv.push([t('Contains'), t('{n} items', { n: item.children })]);
  if (item.is_dir) {
    const sizeText = h('span', item._size !== undefined ? item._size : '—');
    kv.push([t('Size'), h('span.size-calc', sizeText, h('button.icon-btn.sm', { title: t('Calculate size'), onClick: async e => { e.currentTarget.disabled = true; try { const r = await api.get('files/size', { p: item.path }); item._size = fmtSize(r.size) + ' · ' + t('{n} files', { n: r.files }); sizeText.textContent = item._size; } catch (ex) { toast(ex.message, 'error'); } e.currentTarget.disabled = false; } }, icon('refresh')))]);
  }
  kv.push([t('Modified'), fmtDate(item.mtime)]);
  if (item.created_by) kv.push([t('Added by'), item.created_by.name]);
  kv.push([t('Location'), item.path.startsWith('@') ? t('Shared with me') : (parentPath(item.path) || '/')]);
  if (item.owner_id && S.folder && S.folder.owner && item.owner_id !== (App.user && App.user.id)) kv.push([t('Owner'), S.folder.owner.name]);
  if (item.downloads) kv.push([t('Downloads'), item.downloads]);
  if (item.locked_by) kv.push([t('Locked by'), item.locked_by_name + (item.locked_at ? ' · ' + relTime(item.locked_at) : '')]);
  const dl = h('dl.kv', kv.map(([k, v]) => [h('dt', k), h('dd', v)]));
  body.appendChild(dl);
  if (App.user && !App.isLinkMode && p.share && !item._folder) {
    const box = h('div.details-section.access', h('h4', t('Who has access')), spinner());
    body.appendChild(box);
    api.get('shares/item', { p: item.path }).then(info => {
      const faces = [avatar(App.user, 'sm')];
      for (const sh of info.shares.slice(0, 7)) faces.push(sh.target_type === 'group' ? h('span.avatar.avatar-sm.group', { title: sh.target_name }, icon('users')) : avatar({ name: sh.target_name }, 'sm'));
      if (info.links.length) faces.push(h('span.avatar.avatar-sm.group', { title: t('{n} links', { n: info.links.length }) }, icon('link')));
      const summary = !info.shares.length && !info.links.length ? t('Private to you') : [info.shares.length ? t('{n} people or groups', { n: info.shares.length }) : null, info.links.length ? t('{n} links', { n: info.links.length }) : null].filter(Boolean).join(' · ');
      replace(box, h('h4', t('Who has access')), h('div.access-row', ...faces), h('div.hint', summary), info.can_share ? h('button.btn.btn-sm', { onClick: () => Share.shareDialog(item).then(() => { refresh(); renderDetails(); }) }, icon('users'), t('Manage access')) : null);
    }).catch(() => box.remove());
  } else if (item.shared) body.appendChild(h('div.shared-note', icon(item.shared === 'link' ? 'link' : 'users'), item.shared === 'link' ? t('Shared by link') : item.shared === 'both' ? t('Shared with users and by link') : t('Shared with users')));
  // Quick actions
  const acts = h('div.details-actions');
  if (p.download && !item.is_dir) acts.appendChild(h('button.btn.btn-sm', { onClick: () => api.download([item.path]) }, icon('download'), t('Download')));
  if (p.share && !App.isLinkMode && !item._folder) acts.appendChild(h('button.btn.btn-sm', { onClick: () => Share.shareDialog(item).then(() => refresh()) }, icon('users'), t('Share')));
  if (App.user && !item._folder) acts.appendChild(h('button.btn.btn-sm', { onClick: () => starItems([item], !item.starred) }, icon('star'), item.starred ? t('Unstar') : t('Star')));
  if (App.user && !App.isLinkMode) acts.appendChild(h('button.btn.btn-sm', { onClick: async () => { await followItem(item, !item.following); item.following = !item.following; renderDetails(); } }, icon('bell'), item.following ? t('Unfollow') : t('Follow')));
  if (p.lock && !item.is_dir && App.user) acts.appendChild(h('button.btn.btn-sm', { onClick: () => lockItem(item, !item.locked_by) }, icon(item.locked_by ? 'unlock' : 'lock'), item.locked_by ? t('Unlock') : t('Lock')));
  body.appendChild(acts);
  if (p.meta) {
    body.appendChild(h('div.details-section', h('h4', t('Label')), labelPicker(item.label, async l => { await setLabel([item], l); item.label = l || null; renderDetails(); })));
    body.appendChild(h('div.details-section', h('h4', t('Rating')), stars(item.rating, async v => { try { await api.post('files/rating', { p: item.path, rating: v }); item.rating = v || null; renderDetails(); refresh(); } catch (e) { toast(e.message, 'error'); } })));
    const tagInput = h('input.input.tag-input', { placeholder: t('Add tag…') });
    const saveTags = async (names) => { try { item.tags = await api.post('files/tags', { paths: [item.path], tags: names }).then(r => r.tags); renderDetails(); refresh(); import('./app.js').then(m => m.refreshSidebar()); } catch (e) { toast(e.message, 'error'); } };
    tagInput.addEventListener('keydown', e => { if (e.key === 'Enter' && tagInput.value.trim()) { e.preventDefault(); saveTags([...(item.tags || []).map(x => x.name), tagInput.value.trim()]); } });
    const tagWrap = h('div.details-section', h('h4', t('Tags')), h('div.chips', (item.tags || []).map(tg => h('span.chip', { style: tg.color ? { '--tag': tg.color } : {} }, tg.name, h('button.chip-x', { type: 'button', title: t('Remove'), onClick: () => saveTags((item.tags || []).filter(x => x.name !== tg.name).map(x => x.name)) }, icon('x'))))), tagInput);
    body.appendChild(tagWrap);
    const desc = h('textarea.input.desc', { placeholder: t('Add a description…'), rows: 2 }, item.description || '');
    desc.addEventListener('change', async () => { try { await api.post('files/describe', { p: item.path, description: desc.value }); toast(t('Saved'), 'success', 1500); } catch (e) { toast(e.message, 'error'); } });
    body.appendChild(h('div.details-section', h('h4', t('Description')), desc));
    const fields = item.metadata_fields || [];
    if (fields.length) {
      const form = h('div.meta-form');
      for (const f of fields) {
        const v = item.custom && item.custom[f.key];
        let inp;
        if (f.type === 'select') inp = h('select.input', { name: f.key }, h('option', { value: '' }, '—'), (f.options_list || []).map(o => h('option', { value: o, selected: String(v ?? '') === String(o) }, o)));
        else if (f.type === 'boolean') inp = h('select.input', { name: f.key }, h('option', { value: '' }, '—'), h('option', { value: '1', selected: v === true }, t('Yes')), h('option', { value: '0', selected: v === false }, t('No')));
        else if (f.type === 'textarea') inp = h('textarea.input', { name: f.key, rows: 2 }, v ?? '');
        else inp = h('input.input', { name: f.key, type: f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'url' ? 'url' : 'text', value: v ?? '' });
        inp.addEventListener('change', async () => {
          const values = {}; values[f.key] = inp.value;
          try { const r = await api.post('files/metadata', { p: item.path, values }); item.custom = r.custom; toast(t('Saved'), 'success', 1500); } catch (e) { toast(e.message, 'error'); }
        });
        form.appendChild(h('div.field', h('label.lbl', f.name + (f.required ? ' *' : '')), inp));
      }
      body.appendChild(h('div.details-section', h('h4', t('Metadata')), form));
    }
  }
  if (item.exif && Object.keys(item.exif).filter(k => k !== '_none').length) {
    const ex = item.exif;
    const nice = { Title: t('Title'), Artist: t('Artist'), Album: t('Album'), Genre: t('Genre'), Year: t('Year'), Track: t('Track'), Duration: t('Duration'), Bitrate: t('Bitrate'), Codec: t('Video codec'), AudioCodec: t('Audio codec'), FrameRate: t('Frame rate'), SampleRate: t('Sample rate'), Camera: t('Camera'), LensModel: t('Lens'), DateTimeOriginal: t('Taken'), ExposureTime: t('Exposure'), FNumber: t('Aperture'), ISO: 'ISO', FocalLength: t('Focal length'), FocalLength35mm: t('Focal length (35mm)'), Flash: t('Flash'), ExposureProgram: t('Program'), WhiteBalance: t('White balance'), Width: t('Width'), Height: t('Height'), Software: t('Software'), Artist: t('Artist'), Copyright: t('Copyright'), Title: t('Title'), Description: t('Description'), Keywords: t('Keywords'), City: t('City'), Country: t('Country'), GPSAltitude: t('Altitude') };
    const rows = [];
    for (const [k, label] of Object.entries(nice)) if (ex[k] !== undefined && ex[k] !== '') rows.push([label, Array.isArray(ex[k]) ? ex[k].join(', ') : String(ex[k])]);
    if (ex.GPSLatitude !== undefined) rows.push([t('Location'), h('a', { href: 'https://www.openstreetmap.org/?mlat=' + ex.GPSLatitude + '&mlon=' + ex.GPSLongitude + '#map=15/' + ex.GPSLatitude + '/' + ex.GPSLongitude, target: '_blank', rel: 'noopener' }, ex.GPSLatitude + ', ' + ex.GPSLongitude)]);
    if (rows.length) body.appendChild(h('details.details-section.exif', { open: ['audio', 'video'].includes(item.kind) }, h('summary', h('h4', ['audio', 'video'].includes(item.kind) ? t('Media information') : t('Photo information'))), h('dl.kv', rows.map(([k, v]) => [h('dt', k), h('dd', v)]))));
  }
}

async function commentsTab(body, item) {
  const list = h('ul.comments');
  const paint = (rows) => replace(list, rows.length ? rows.map(c => h('li', avatar({ name: c.user_name }, 'sm'), h('div.comment-body', h('div.comment-head', h('b', c.user_name), h('span.comment-time', relTime(c.created_at)), (App.user && (c.user_id === App.user.id || item.owner_id === App.user.id || App.isSuper)) ? h('button.icon-btn.sm', { title: t('Delete'), onClick: async () => { try { paint(await api.post('files/delete_comment', { p: item.path, id: c.id })); } catch (e) { toast(e.message, 'error'); } } }, icon('x')) : null), h('div.comment-text', c.body)))) : h('li.comment-empty', t('No comments yet')));
  paint(await api.get('files/comments', { p: item.path }));
  const ta = h('textarea.input', { rows: 2, placeholder: t('Write a comment…') });
  const nameInput = App.user ? null : h('input.input', { placeholder: t('Your name (optional)') });
  const send = h('button.btn.btn-primary.btn-sm', { onClick: async () => {
    if (!ta.value.trim()) return;
    try { await api.post('files/comment', { p: item.path, body: ta.value, name: nameInput ? nameInput.value : '' }); ta.value = ''; paint(await api.get('files/comments', { p: item.path })); } catch (e) { toast(e.message, 'error'); }
  } }, icon('send'), t('Comment'));
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send.click(); });
  replace(body, list, item.perms.comment ? h('div.comment-form', nameInput, ta, send) : null);
}

async function versionsTab(body, item) {
  const rows = await api.get('files/versions', { p: item.path });
  const list = h('ul.versions', rows.length ? rows.map(v => h('li', h('div.ver-main', h('b', fmtDate(v.created_at)), h('span.ver-sub', ' · ' + fmtSize(v.size) + ' · ' + (v.created_by || '') + ' · ' + t(v.reason))),
    h('div.ver-actions',
      h('a.icon-btn.sm', { href: api.fileUrl('version_download', { p: item.path, id: v.id }), title: t('Download') }, icon('download')),
      item.perms.edit ? h('button.icon-btn.sm', { title: t('Restore this version'), onClick: async () => { if (await confirm(t('Restore this version? The current content is kept as a new version.'))) { try { await api.post('files/version_restore', { p: item.path, id: v.id }); toast(t('Version restored'), 'success'); refresh(); renderDetails(); } catch (e) { toast(e.message, 'error'); } } } }, icon('undo')) : null,
      item.perms.delete ? h('button.icon-btn.sm', { title: t('Delete'), onClick: async () => { await api.post('files/version_delete', { p: item.path, id: v.id }); versionsTab(body, item); } }, icon('trash')) : null))) : h('li.comment-empty', t('No previous versions. A version is kept every time the file is overwritten or edited.')));
  replace(body, list);
}

// ---------------------------------------------------------------------------
// Trash view
// ---------------------------------------------------------------------------
export async function trash(main) {
  loadPrefs();
  S.main = main; S.mode = 'trash'; S.kind = 'trash'; S.selection.clear();
  App.title(t('Trash'));
  const content = h('div.content.view-list.trash-view', { tabindex: 0 });
  const toolbar = h('div.toolbar');
  replace(main, h('div.view', toolbar, content, h('div.statusbar')));
  S.el = { toolbar, content, status: main.querySelector('.statusbar') };
  const paint = async () => {
    replace(content, h('div.loading', spinner()));
    const res = await api.get('files/trash');
    const items = res.items;
    replace(toolbar, h('div.crumbs', h('span.crumb.current', icon('trash'), ' ', t('Trash'))), h('div.toolbar-actions'), h('div.toolbar-right', items.length ? h('button.btn.btn-sm.btn-danger', { onClick: async () => { if (await confirm(t('Permanently delete everything in the trash?'), { danger: true, okLabel: t('Empty trash') })) { await api.post('files/trash_purge', {}); paint(); import('./app.js').then(m => m.refreshSidebar()); } } }, icon('trash'), t('Empty trash')) : null));
    if (!items.length) { replace(content, emptyState('trash', t('The trash is empty'), t('Deleted files are kept here for {n} days.', { n: 30 }))); return; }
    const rows = items.map(it => h('div.row', { dataset: { path: it.path } },
      h('div.col-check'), h('div.col-name', fileIcon(it, 'sm'), h('span.name-text', it.name)), h('div.col-loc', { title: it.original_path }, '/' + it.original_path.split('/').slice(0, -1).join('/')),
      h('div.col-size', it.is_dir ? '—' : fmtSize(it.size)), h('div.col-date', { title: fmtDate(it.deleted_at) }, relTime(it.deleted_at) + (it.deleted_by ? ' · ' + it.deleted_by : '')),
      h('div.col-actions',
        h('button.btn.btn-sm', { onClick: async () => { try { const r = await api.post('files/trash_restore', { ids: [it.id] }); if (r.errors.length) toast(r.errors[0].error, 'error'); else toast(t('Restored to {path}', { path: r.done[0].path }), 'success'); paint(); import('./app.js').then(m => m.refreshSidebar()); } catch (e) { toast(e.message, 'error'); } } }, icon('undo'), t('Restore')),
        h('button.icon-btn', { title: t('Restore to…'), onClick: async () => { const dest = await folderPicker(t('Restore to…')); if (dest) { await api.post('files/trash_restore', { ids: [it.id], to: dest.replace(/^\//, '') }); paint(); } } }, icon('folder-input')),
        h('button.icon-btn', { title: t('Delete permanently'), onClick: async () => { if (await confirm(t('Permanently delete "{name}"?', { name: it.name }), { danger: true, okLabel: t('Delete') })) { await api.post('files/trash_purge', { ids: [it.id] }); paint(); } } }, icon('x')))));
    replace(content, h('div.list-head', h('div.col-check'), h('div.col-name', t('Name')), h('div.col-loc', t('Original location')), h('div.col-size', t('Size')), h('div.col-date', t('Deleted')), h('div.col-actions')), h('div.list-body', rows));
    replace(S.el.status, h('span', t('{n} items', { n: items.length }) + ' · ' + fmtSize(items.reduce((a, i) => a + i.size, 0))));
  };
  await paint();
}

// ---------------------------------------------------------------------------
// Advanced search dialog
// ---------------------------------------------------------------------------
export function advancedSearch(q = '') {
  const f = S.params.filters || {};
  const fields = {
    q: field({ label: t('Words'), name: 'q', value: q || S.params.q || '' }),
    kind: field({ label: t('Type'), name: 'kind', type: 'select', value: f.kind || '', options: [{ value: '', label: t('Any') }, ...['image', 'video', 'audio', 'pdf', 'doc', 'sheet', 'slide', 'text', 'code', 'archive'].map(k => ({ value: k, label: kindLabel({ kind: k, ext: '' }) }))] }),
    ext: field({ label: t('Extension'), name: 'ext', value: f.ext || '', placeholder: 'jpg' }),
    from: field({ label: t('Modified after'), name: 'from', type: 'date', value: f.from || '' }),
    to: field({ label: t('Modified before'), name: 'to', type: 'date', value: f.to || '' }),
    min: field({ label: t('Min size (MB)'), name: 'min', type: 'number', value: f.min_size ? f.min_size / 1048576 : '' }),
    max: field({ label: t('Max size (MB)'), name: 'max', type: 'number', value: f.max_size ? f.max_size / 1048576 : '' }),
    content: field({ type: 'checkbox', name: 'content', label: t('Search inside documents'), value: f.content !== 0 }),
    where: field({ label: t('Search in'), name: 'where', type: 'select', value: S.mode === 'browse' ? S.path : '/', options: [{ value: '/', label: t('Everywhere in My Files') }, ...(S.mode === 'browse' && S.path !== '/' ? [{ value: S.path, label: t('Current folder') }] : [])] }),
  };
  dialog({
    title: t('Advanced search'), width: '560px',
    body: h('div.form-grid', fields.q, fields.where, fields.kind, fields.ext, fields.from, fields.to, fields.min, fields.max, fields.content),
    buttons: [{ label: t('Cancel') }, { label: t('Search'), primary: true, onClick: () => {
      const filters = { kind: fields.kind.input.value, ext: fields.ext.input.value, from: fields.from.input.value, to: fields.to.input.value, min_size: fields.min.input.value ? Math.round(Number(fields.min.input.value) * 1048576) : 0, max_size: fields.max.input.value ? Math.round(Number(fields.max.input.value) * 1048576) : 0, content: fields.content.input.checked ? 1 : 0 };
      S.params = { q: fields.q.input.value.trim(), p: fields.where.input.value, filters };
      const hash = '#/search/' + encodeURIComponent(S.params.q || '*');
      if (location.hash === hash) virtual(S.main || App.layout.main, 'search', t('Search: {q}', { q: S.params.q }), S.params); else { const keep = S.params; App.navigate(hash); setTimeout(() => { S.params = { ...keep }; load(true); }, 0); }
    } }],
  });
}
