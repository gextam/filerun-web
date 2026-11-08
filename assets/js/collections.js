/* Collections: virtual folders that gather files from anywhere without moving them. */
import { App } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, icon, replace, dialog, confirm, prompt, toast, menu, field, emptyState, spinner, relTime, fmtSize } from './ui.js';

const COLORS = ['#2563eb', '#16a34a', '#ea580c', '#dc2626', '#9333ea', '#0891b2', '#ca8a04', '#64748b'];

export function colorOf(c) { return c.color || COLORS[c.id % COLORS.length]; }

async function refreshSidebar() { (await import('./app.js')).refreshSidebar(); }

/** Dialog to create (or rename) a collection. Resolves with the collection or null. */
export function editDialog(existing = null, paths = []) {
  const name = field({ label: t('Name'), name: 'name', value: existing ? existing.name : '', required: true, attrs: { autofocus: true } });
  const desc = field({ label: t('Description (optional)'), name: 'description', value: existing ? (existing.description || '') : '' });
  let color = existing ? existing.color : null;
  const swatches = h('div.label-picker', COLORS.map(c => h('button.label-swatch', { type: 'button', class: color === c ? 'on' : '', style: { background: c }, onClick: e => { color = c; swatches.querySelectorAll('.label-swatch').forEach(b => b.classList.toggle('on', b === e.currentTarget)); } })));
  return dialog({
    title: existing ? t('Edit collection') : t('New collection'), width: '440px',
    body: h('div', name, desc, h('div.field', h('label.lbl', t('Colour')), swatches), paths.length ? h('p.hint', t('{n} items will be added to it.', { n: paths.length })) : null),
    buttons: [{ label: t('Cancel'), value: null }, { label: existing ? t('Save') : t('Create'), primary: true, onClick: async () => {
      if (!name.input.value.trim()) { name.input.focus(); return false; }
      const body = { name: name.input.value.trim(), description: desc.input.value, color };
      const res = existing ? await api.post('collections/update', { id: existing.id, ...body }) : await api.post('collections/create', { ...body, paths });
      await refreshSidebar();
      return existing ? existing : res;
    } }],
  }).result;
}

/** Context-menu entries: "Add to collection ▸". */
export function menuItems(selection, onDone) {
  const cols = (App.sidebar && App.sidebar.collections) || [];
  const paths = selection.map(i => i.path);
  const sub = cols.map(c => ({ label: c.name, color: colorOf(c), onClick: async () => { try { const r = await api.post('collections/add', { id: c.id, paths }); toast(t('{n} items added to "{name}"', { n: r.added, name: c.name }), 'success'); await refreshSidebar(); onDone && onDone(); } catch (e) { toast(e.message, 'error'); } } }));
  sub.push(cols.length ? { sep: true } : null, { label: t('New collection…'), icon: 'plus', onClick: async () => { const c = await editDialog(null, paths); if (c) { toast(t('Collection "{name}" created', { name: c.name }), 'success'); onDone && onDone(); } } });
  return { label: t('Add to collection'), icon: 'book', submenu: sub.filter(Boolean) };
}

/** The Collections overview page. */
export async function list(main) {
  App.title(t('Collections'));
  replace(main, h('div.view', h('div.toolbar', h('div.crumbs', h('span.crumb.current', icon('book'), ' ', t('Collections'))), h('div.toolbar-right', h('button.btn.btn-primary.btn-sm', { onClick: async () => { const c = await editDialog(); if (c) list(main); } }, icon('plus'), t('New collection')))), h('div.content.page', spinner())));
  const content = main.querySelector('.content');
  try {
    const cols = await api.get('collections/list');
    if (!cols.length) { replace(content, emptyState('book', t('No collections yet'), t('Collections gather files from any folder in one place without moving them. Right-click a file and choose "Add to collection".'), h('button.btn.btn-primary', { onClick: async () => { const c = await editDialog(); if (c) list(main); } }, icon('plus'), t('New collection')))); return; }
    replace(content, h('div.cards', cols.map(c => h('div.card.share-card', { onClick: () => App.navigate('#/collection/' + c.id) },
      h('div.card-icon', h('span.collection-mark', { style: { background: colorOf(c) } }, icon('book'))),
      h('div.card-body', h('div.card-title', c.name), h('div.card-sub', t('{n} items', { n: c.count }) + (c.description ? ' · ' + c.description : '')), h('div.card-sub', c.updated_at ? t('updated {when}', { when: relTime(c.updated_at) }) : t('created {when}', { when: relTime(c.created_at) }))),
      h('button.icon-btn.card-menu', { onClick: e => { e.stopPropagation(); menu(cardMenu(c, () => list(main)), e.currentTarget); } }, icon('more-v'))))));
  } catch (e) { replace(content, emptyState('alert', t('Error'), e.message)); }
}

export function cardMenu(c, onChange) {
  return [
    { label: t('Open'), icon: 'folder-open', onClick: () => App.navigate('#/collection/' + c.id) },
    { label: t('Rename / colour'), icon: 'edit', onClick: async () => { await editDialog(c); onChange && onChange(); } },
    { sep: true },
    { label: t('Delete collection'), icon: 'trash', danger: true, onClick: async () => { if (await confirm(t('Delete the collection "{name}"? The files themselves are not touched.', { name: c.name }), { danger: true, okLabel: t('Delete') })) { await api.post('collections/delete', { id: c.id }); await refreshSidebar(); onChange ? onChange() : App.navigate('#/collections'); } } },
  ];
}
