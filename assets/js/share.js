/* Sharing with people, public links, "Shared with me" and "My links". */
import { App } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, $, $$, icon, fileIcon, replace, clear, dialog, confirm, prompt, toast, menu, fmtSize, fmtDate, fmtDateShort, relTime, field, avatar, copyText, emptyState, debounce, spinner, loadScript, tabs } from './ui.js';

function roleOptions(roles, descriptions = true) {
  const labels = App.features.roles || {};
  const desc = App.features.role_descriptions || {};
  return roles.map(r => ({ value: r, label: labels[r] || r, description: descriptions ? desc[r] : '' }));
}

function roleSelect(roles, value, onChange) {
  const sel = h('select.input.role-select', roleOptions(roles).map(o => h('option', { value: o.value, selected: o.value === value, title: o.description }, o.label)));
  if (onChange) sel.addEventListener('change', () => onChange(sel.value));
  return sel;
}

// ---------------------------------------------------------------------------
// Share dialog (people + groups + guests) with a summary of links
// ---------------------------------------------------------------------------
export async function shareDialog(item) {
  let info;
  try { info = await api.get('shares/item', { p: item.path }); } catch (e) { toast(e.message, 'error'); return; }
  if (!info.can_share) { toast(t('You cannot share this item'), 'warn'); return; }
  const roles = info.roles.map(r => r.id);
  let role = roles.includes('viewer') ? 'viewer' : roles[0];
  const picked = []; // {type:'user'|'group'|'email', id, name}
  const search = h('input.input', { placeholder: info.can_share_guests ? t('Name, username or e-mail address…') : t('Name or username…'), autocomplete: 'off' });
  const results = h('div.share-results', { hidden: true });
  const chips = h('div.chips');
  const message = h('textarea.input', { rows: 2, placeholder: t('Message (optional)') });
  const notify = field({ type: 'checkbox', name: 'notify', label: t('Notify me when they change something') });
  const existing = h('div.share-list');
  const linksBox = h('div.share-links');
  const paintChips = () => replace(chips, picked.map(p => h('span.chip', icon(p.type === 'group' ? 'users' : p.type === 'email' ? 'mail' : 'user'), p.name, h('button.chip-x', { type: 'button', onClick: () => { picked.splice(picked.indexOf(p), 1); paintChips(); } }, icon('x')))));
  const doSearch = debounce(async () => {
    const q = search.value.trim();
    if (!q) { results.hidden = true; return; }
    try {
      const r = await api.get('shares/users', { q });
      const rows = [];
      for (const u of r.users) if (!picked.some(p => p.type === 'user' && p.id === u.id)) rows.push(h('button.share-result', { type: 'button', onClick: () => { picked.push({ type: 'user', id: u.id, name: u.name }); search.value = ''; results.hidden = true; paintChips(); } }, avatar(u, 'sm'), h('span.sr-name', u.name), h('span.sr-sub', u.username + (u.email ? ' · ' + u.email : ''))));
      for (const g of r.groups.filter(g => g.name.toLowerCase().includes(q.toLowerCase()))) if (!picked.some(p => p.type === 'group' && p.id === g.id)) rows.push(h('button.share-result', { type: 'button', onClick: () => { picked.push({ type: 'group', id: g.id, name: g.name }); search.value = ''; results.hidden = true; paintChips(); } }, h('span.avatar.avatar-sm.group', icon('users')), h('span.sr-name', g.name), h('span.sr-sub', t('{n} members', { n: g.members }))));
      if (info.can_share_guests && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(q)) rows.push(h('button.share-result', { type: 'button', onClick: () => { picked.push({ type: 'email', id: q, name: q }); search.value = ''; results.hidden = true; paintChips(); } }, h('span.avatar.avatar-sm.group', icon('mail')), h('span.sr-name', q), h('span.sr-sub', t('Invite as a guest'))));
      replace(results, rows.length ? rows : h('div.share-result.none', t('No matches')));
      results.hidden = false;
    } catch (e) { results.hidden = true; }
  }, 200);
  search.addEventListener('input', doSearch);
  search.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const first = results.querySelector('.share-result:not(.none)'); if (first && !results.hidden) first.click(); } });
  const paintExisting = () => {
    replace(existing, info.shares.length ? info.shares.map(s => h('div.share-row',
      s.target_type === 'group' ? h('span.avatar.avatar-sm.group', icon('users')) : avatar({ name: s.target_name }, 'sm'),
      h('div.share-who', h('div.share-name', s.target_name, s.target_is_guest ? h('span.tag-chip', t('guest')) : null), h('div.share-sub', s.target_type === 'group' ? t('Group') : (s.target_email || s.target_username || ''), s.expires_at ? ' · ' + t('until {date}', { date: fmtDateShort(s.expires_at) }) : '')),
      roleSelect(roles.includes(s.role) ? roles : [...roles, s.role], s.role, async (r) => { try { await api.post('shares/update', { id: s.id, role: r }); toast(t('Role updated'), 'success', 1500); } catch (e) { toast(e.message, 'error'); } }),
      h('button.icon-btn', { title: t('Remove'), onClick: async () => { try { await api.post('shares/delete', { id: s.id }); info.shares = info.shares.filter(x => x.id !== s.id); paintExisting(); } catch (e) { toast(e.message, 'error'); } } }, icon('x')))) : h('div.share-empty', t('Not shared with anybody yet')));
  };
  const paintLinks = () => {
    replace(linksBox, h('div.share-links-head', h('h4', t('Links')), info.can_share_links ? h('button.btn.btn-sm', { onClick: async () => { await linkDialog(item, null, info); info = await api.get('shares/item', { p: item.path }); paintLinks(); } }, icon('plus'), t('Create link')) : null),
      info.links.length ? info.links.map(l => h('div.share-row.link-row', icon(l.active && l.usable ? 'link' : 'link-off'), h('div.share-who', h('div.share-name', l.title || (l.role === 'uploader' ? t('File request') : t('Public link')), !l.usable ? h('span.tag-chip.warn', l.usable_reason) : null), h('div.share-sub', l.role_label, l.has_password ? ' · ' + t('password') : '', l.expires_at ? ' · ' + t('until {date}', { date: fmtDateShort(l.expires_at) }) : '', ' · ', t('{n} downloads', { n: l.downloads }))),
        h('button.btn.btn-sm', { onClick: () => copyText(l.url).then(() => toast(t('Link copied'), 'success')) }, icon('copy'), t('Copy')),
        h('button.icon-btn', { title: t('Settings'), onClick: async () => { await linkDialog(item, l, info); info = await api.get('shares/item', { p: item.path }); paintLinks(); } }, icon('settings')))) : (info.can_share_links ? h('div.share-empty', t('No links yet')) : h('div.share-empty', t('Link sharing is not enabled for your account'))));
  };
  paintExisting(); paintLinks();
  const roleSel = roleSelect(roles, role, r => { role = r; });
  const roleHelp = h('div.hint', (App.features.role_descriptions || {})[role] || '');
  roleSel.addEventListener('change', () => { roleHelp.textContent = (App.features.role_descriptions || {})[roleSel.value] || ''; });
  const dlg = dialog({
    title: t('Share "{name}"', { name: item.name }), width: '600px',
    body: h('div.share-dialog.no-enter',
      info.can_share_users ? h('div.share-add', h('div.share-search', search, results), chips, h('div.share-opts', h('label.lbl', t('Role')), roleSel, roleHelp), h('div.share-extra', { hidden: true }, message, notify)) : h('div.share-empty', t('Sharing with users is not enabled for your account')),
      h('h4', t('People with access')), existing, linksBox),
    buttons: [{ label: t('Close') }, info.can_share_users ? { label: t('Share'), primary: true, onClick: async () => {
      if (!picked.length) { search.focus(); return false; }
      const targets = picked.filter(p => p.type !== 'email').map(p => ({ type: p.type, id: p.id }));
      const emails = picked.filter(p => p.type === 'email').map(p => p.id);
      try {
        if (targets.length) await api.post('shares/create', { p: item.path, targets, role, notify: notify.input.checked, alias: '' });
        if (emails.length) await api.post('shares/invite', { p: item.path, emails, role, message: message.value });
        toast(t('Shared'), 'success');
        picked.length = 0; paintChips();
        info = await api.get('shares/item', { p: item.path }); paintExisting();
      } catch (e) { toast(e.message, 'error'); }
      return false;
    } } : null].filter(Boolean),
  });
  const extra = dlg.body.querySelector('.share-extra');
  const obs = new MutationObserver(() => { if (extra) extra.hidden = picked.length === 0; });
  obs.observe(chips, { childList: true });
  return dlg.result;
}

// ---------------------------------------------------------------------------
// Link dialog (create / edit)
// ---------------------------------------------------------------------------
export async function linkDialog(item, link = null, info = null) {
  if (!info) { try { info = await api.get('shares/item', { p: item.path }); } catch (e) { toast(e.message, 'error'); return; } }
  if (!info.can_share_links) { toast(t('Link sharing is not enabled for your account'), 'warn'); return; }
  const roles = info.link_roles;
  const isNew = !link;
  const f = {
    role: field({ label: t('What can people do with the link?'), name: 'role', type: 'select', value: link ? link.role : 'viewer', options: roleOptions(roles) }),
    title: field({ label: t('Title (shown to visitors)'), name: 'title', value: link ? (link.title || '') : item.name }),
    message: field({ label: t('Message for visitors'), name: 'message', type: 'textarea', rows: 2, value: link ? (link.message || '') : '' }),
    password: field({ label: t('Password'), name: 'password', type: 'password', value: '', placeholder: link && link.has_password ? t('(unchanged)') : t('None'), attrs: { autocomplete: 'new-password' } }),
    expires: field({ label: t('Expires on'), name: 'expires_at', type: 'date', value: link && link.expires_at ? link.expires_at.slice(0, 10) : '' }),
    max: field({ label: t('Download limit (0 = unlimited)'), name: 'max_downloads', type: 'number', value: link ? link.max_downloads : 0, min: 0 }),
    terms: field({ label: t('Terms to accept before downloading'), name: 'terms', type: 'textarea', rows: 2, value: link ? (link.terms || '') : '' }),
    notify: field({ type: 'checkbox', name: 'notify', label: t('Notify me about downloads and uploads'), value: link ? link.notify : false }),
    previewOnly: field({ type: 'checkbox', name: 'allow_preview_only', label: t('Preview only (no downloads)'), value: link ? link.allow_preview_only : false }),
    active: field({ type: 'checkbox', name: 'active', label: t('Link is active'), value: link ? link.active : true }),
  };
  const roleHelp = h('div.hint', (App.features.role_descriptions || {})[f.role.input.value] || '');
  f.role.input.addEventListener('change', () => { roleHelp.textContent = (App.features.role_descriptions || {})[f.role.input.value] || ''; });
  if (info.link_max_days) f.expires.appendChild(h('div.hint', t('Links on this server expire after at most {n} days', { n: info.link_max_days })));
  if (info.link_require_password) f.password.appendChild(h('div.hint', t('A password is required on this server')));
  const urlBox = h('div.link-url', { hidden: isNew });
  const paintUrl = (l) => replace(urlBox, h('input.input', { readOnly: true, value: l.url, onClick: e => e.target.select() }), h('button.btn', { onClick: () => copyText(l.url).then(() => toast(t('Link copied'), 'success')) }, icon('copy'), t('Copy')), h('button.btn', { title: t('Send by e-mail'), onClick: () => sendLinkDialog(l) }, icon('mail')), h('button.btn', { title: t('QR code'), onClick: () => qrDialog(l.url) }, icon('grid')));
  if (link) paintUrl(link);
  const collect = () => ({ role: f.role.input.value, title: f.title.input.value, message: f.message.input.value, password: f.password.input.value, expires_at: f.expires.input.value ? f.expires.input.value + ' 23:59:59' : '', max_downloads: Number(f.max.input.value) || 0, terms: f.terms.input.value, notify: f.notify.input.checked, allow_preview_only: f.previewOnly.input.checked, active: f.active.input.checked });
  const buttons = [{ label: t('Close') }];
  if (link) buttons.unshift({ label: t('Delete link'), danger: true, cls: 'btn-ghost', onClick: async () => { if (!await confirm(t('Delete this link? People who have it will no longer be able to use it.'), { danger: true, okLabel: t('Delete') })) return false; await api.post('shares/link_delete', { id: link.id }); toast(t('Link deleted'), 'success'); } });
  buttons.push({ label: isNew ? t('Create link') : t('Save'), primary: true, onClick: async (dlg) => {
    const body = collect();
    if (!isNew && body.password === '') delete body.password;
    const l = isNew ? await api.post('shares/link_create', { p: item.path, ...body }) : await api.post('shares/link_update', { id: link.id, ...body });
    if (isNew) { link = l; urlBox.hidden = false; paintUrl(l); copyText(l.url).then(() => toast(t('Link created and copied to the clipboard'), 'success')); dlg.querySelector('.dialog-foot .btn-primary').textContent = t('Save'); dlg.querySelector('.dialog-title').textContent = t('Link settings'); return false; }
    toast(t('Saved'), 'success');
  } });
  return dialog({
    title: isNew ? t('Create a link to "{name}"', { name: item.name }) : t('Link settings'), width: '560px',
    body: h('div.no-enter', urlBox, h('div.form-grid', f.role, roleHelp, f.title, f.message, f.password, f.expires, f.max, f.terms, f.notify, f.previewOnly, isNew ? null : f.active)),
    buttons,
  }).result;
}

function sendLinkDialog(link) {
  const emails = field({ label: t('E-mail addresses (comma separated)'), name: 'emails', placeholder: 'name@example.com' });
  const msg = field({ label: t('Message'), name: 'message', type: 'textarea', rows: 3 });
  return dialog({ title: t('Send link by e-mail'), width: '460px', body: h('div', emails, msg), buttons: [{ label: t('Cancel') }, { label: t('Send'), primary: true, onClick: async () => { const list = emails.input.value.split(/[,;\s]+/).filter(Boolean); const r = await api.post('shares/link_send', { id: link.id, emails: list, message: msg.input.value }); toast(t('{n} messages sent', { n: r.sent }), 'success'); } }] }).result;
}

async function qrDialog(url) {
  const box = h('div.qr-box');
  const dlg = dialog({ title: t('QR code'), width: '340px', body: h('div', box, h('p.dialog-text.muted', { style: { wordBreak: 'break-all' } }, url)), buttons: [{ label: t('Close'), primary: true }] });
  try {
    await loadScript(App.assetBase + '/assets/vendor/qrcode.min.js');
    const qr = window.qrcode(0, 'M'); qr.addData(url); qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2 });
  } catch (e) { box.textContent = t('QR code library not available'); }
  return dlg.result;
}

/** After pasting a screenshot: offer a link right away. */
export async function shareLinkForPaste(item) {
  if (!App.user || !App.user.perms.share_links || !App.settings.links_enabled) return;
  try {
    const l = await api.post('shares/link_create', { p: item.path, role: 'viewer', title: item.name });
    await copyText(l.url);
    toast(t('Screenshot uploaded. A link to it was copied to the clipboard.'), 'success', 6000);
  } catch (e) { /* ignore */ }
}

// ---------------------------------------------------------------------------
// Details panel "Sharing" tab
// ---------------------------------------------------------------------------
export async function sharingPanel(body, item, onChange) {
  const info = await api.get('shares/item', { p: item.path });
  const rows = [];
  for (const s of info.shares) rows.push(h('div.share-row', s.target_type === 'group' ? h('span.avatar.avatar-sm.group', icon('users')) : avatar({ name: s.target_name }, 'sm'), h('div.share-who', h('div.share-name', s.target_name), h('div.share-sub', s.role_label))));
  for (const l of info.links) rows.push(h('div.share-row', icon(l.usable ? 'link' : 'link-off'), h('div.share-who', h('div.share-name', l.title || t('Public link')), h('div.share-sub', l.role_label + ' · ' + t('{n} downloads', { n: l.downloads }))), h('button.icon-btn.sm', { title: t('Copy link'), onClick: () => copyText(l.url).then(() => toast(t('Link copied'), 'success')) }, icon('copy'))));
  replace(body, rows.length ? h('div.share-list', rows) : h('div.share-empty', t('Not shared')),
    h('div.details-actions', info.can_share_users || info.can_share_guests ? h('button.btn.btn-sm', { onClick: () => shareDialog(item).then(() => { onChange && onChange(); sharingPanel(body, item, onChange); }) }, icon('users'), t('Share with people')) : null, info.can_share_links ? h('button.btn.btn-sm', { onClick: () => linkDialog(item, null, info).then(() => { onChange && onChange(); sharingPanel(body, item, onChange); }) }, icon('link'), t('Create link')) : null));
}

// ---------------------------------------------------------------------------
// "Shared with me" page
// ---------------------------------------------------------------------------
export async function sharedWithMe(main) {
  App.title(t('Shared with me'));
  replace(main, h('div.view', h('div.toolbar', h('div.crumbs', h('span.crumb.current', icon('users'), ' ', t('Shared with me')))), h('div.content.page', spinner())));
  const content = main.querySelector('.content');
  try {
    const res = await api.get('shares/with_me');
    const items = res.items;
    if (!items.length) { replace(content, emptyState('users', t('Nothing has been shared with you yet'), t('Folders and files other people share with you appear here.'))); return; }
    const groups = {};
    for (const s of items) (groups[s.owner.name] = groups[s.owner.name] || []).push(s);
    const sections = Object.entries(groups).map(([owner, list]) => h('section.share-section', h('h3', avatar({ name: owner }, 'sm'), ' ', t('Shared by {name}', { name: owner })),
      h('div.cards', list.map(s => h('div.card.share-card', { onClick: () => s.exists ? (s.is_dir ? App.openFolder(s.vpath) : App.openFolder(s.vpath)) : toast(t('This item no longer exists'), 'warn') },
        h('div.card-icon', fileIcon({ is_dir: s.is_dir, kind: s.kind, ext: s.ext }, 'lg')),
        h('div.card-body', h('div.card-title', s.name), h('div.card-sub', s.role_label + (s.via_group ? ' · ' + t('via group {g}', { g: s.via_group }) : '') + (s.is_dir ? '' : ' · ' + fmtSize(s.size))), h('div.card-sub', t('since {date}', { date: fmtDateShort(s.created_at) }), s.expires_at ? ' · ' + t('until {date}', { date: fmtDateShort(s.expires_at) }) : '')),
        h('button.icon-btn.card-menu', { onClick: e => { e.stopPropagation(); menu([
          { label: t('Open'), icon: 'folder-open', onClick: () => App.openFolder(s.vpath) },
          s.perms.download ? { label: t('Download'), icon: 'download', onClick: () => api.download([s.vpath]) } : null,
          { sep: true },
          { label: t('Remove from my list'), icon: 'x', danger: true, onClick: async () => { if (await confirm(t('Remove "{name}" from Shared with me? The owner can share it again later.', { name: s.name }))) { await api.post('shares/leave', { id: s.id }); sharedWithMe(main); import('./app.js').then(m => m.refreshSidebar()); } } },
        ], e.currentTarget); } }, icon('more-v')))))));
    replace(content, ...sections);
  } catch (e) { replace(content, emptyState('alert', t('Error'), e.message)); }
}

// ---------------------------------------------------------------------------
// "My links" page
// ---------------------------------------------------------------------------
export async function myLinks(main) {
  App.title(t('My links'));
  replace(main, h('div.view', h('div.toolbar', h('div.crumbs', h('span.crumb.current', icon('link'), ' ', t('My links')))), h('div.content.page', spinner())));
  const content = main.querySelector('.content');
  const paint = async () => {
    try {
      const [res, shared] = await Promise.all([api.get('shares/links_mine'), api.get('shares/by_me')]);
      const links = res.items;
      const parts = [];
      parts.push(h('h3', t('Public links')));
      if (!links.length) parts.push(emptyState('link', t('You have not created any links'), t('Select a file or folder and choose "Get link" to share it with people outside.')));
      else parts.push(h('table.table', h('thead', h('tr', h('th', t('Item')), h('th', t('Role')), h('th', t('Protection')), h('th', t('Expires')), h('th', t('Downloads')), h('th', t('Created')), h('th', ''))),
        h('tbody', links.map(l => h('tr', { class: l.usable ? '' : 'muted' },
          h('td', h('a.link', { href: App.filesHash('/' + l.path.split('/').slice(0, -1).join('/')) }, icon(l.is_dir ? 'folder' : 'file'), ' ', l.title && l.title !== l.name ? l.title + ' (' + l.name + ')' : l.name), !l.usable ? h('div.hint', l.usable_reason) : null),
          h('td', l.role_label), h('td', [l.has_password ? t('password') : null, l.terms ? t('terms') : null, l.allow_preview_only ? t('preview only') : null].filter(Boolean).join(', ') || '—'),
          h('td', l.expires_at ? fmtDateShort(l.expires_at) : '—'), h('td', l.downloads + (l.max_downloads ? ' / ' + l.max_downloads : '')), h('td', fmtDateShort(l.created_at)),
          h('td.actions', h('button.icon-btn', { title: t('Copy link'), onClick: () => copyText(l.url).then(() => toast(t('Link copied'), 'success')) }, icon('copy')), h('button.icon-btn', { title: t('Settings'), onClick: async () => { await linkDialog({ path: '/' + l.path, name: l.name }, l); paint(); } }, icon('settings')), h('button.icon-btn', { title: l.active ? t('Disable') : t('Enable'), onClick: async () => { await api.post('shares/link_update', { id: l.id, active: !l.active }); paint(); } }, icon(l.active ? 'link-off' : 'link')), h('button.icon-btn', { title: t('Delete'), onClick: async () => { if (await confirm(t('Delete this link?'), { danger: true, okLabel: t('Delete') })) { await api.post('shares/link_delete', { id: l.id }); paint(); import('./app.js').then(m => m.refreshSidebar()); } } }, icon('trash'))))))));
      parts.push(h('h3', t('Shared with people')));
      if (!shared.shares.length) parts.push(h('p.muted', t('You have not shared anything with other accounts.')));
      else parts.push(h('table.table', h('thead', h('tr', h('th', t('Item')), h('th', t('With')), h('th', t('Role')), h('th', t('Since')), h('th', ''))), h('tbody', shared.shares.map(s => h('tr', h('td', h('a.link', { href: App.filesHash('/' + s.path.split('/').slice(0, -1).join('/')) }, icon(s.is_dir ? 'folder' : 'file'), ' ', s.path.split('/').pop())), h('td', s.target_name, s.target_type === 'group' ? h('span.tag-chip', t('group')) : null, s.target_is_guest ? h('span.tag-chip', t('guest')) : null), h('td', roleSelect(Object.keys(App.features.roles || {}).filter(r => r !== 'owner'), s.role, async r => { await api.post('shares/update', { id: s.id, role: r }); toast(t('Role updated'), 'success', 1500); })), h('td', fmtDateShort(s.created_at)), h('td.actions', h('button.icon-btn', { title: t('Remove'), onClick: async () => { await api.post('shares/delete', { id: s.id }); paint(); } }, icon('x'))))))));
      replace(content, ...parts);
    } catch (e) { replace(content, emptyState('alert', t('Error'), e.message)); }
  };
  await paint();
}
