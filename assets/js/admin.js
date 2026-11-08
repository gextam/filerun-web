/* Control panel: dashboard, users, groups, settings, metadata fields, activity log, links & shares. */
import { App } from './store.js';
import { api } from './api.js';
import { t, LANGUAGES } from './i18n.js';
import { h, $, $$, icon, replace, clear, dialog, confirm, prompt, toast, menu, fmtSize, fmtDate, fmtDateShort, relTime, field, formValues, copyText, emptyState, spinner, tabs, avatar, progressBar, alertDialog, debounce } from './ui.js';
import { actionLabel } from './files.js';

const SECTIONS = () => [
  { id: 'dashboard', label: t('Overview'), icon: 'bar-chart' },
  { id: 'users', label: t('Users'), icon: 'users' },
  { id: 'groups', label: t('Groups'), icon: 'briefcase' },
  { id: 'activity', label: t('Activity log'), icon: 'activity' },
  { id: 'links', label: t('Links & shares'), icon: 'link' },
  ...(App.isSuper ? [{ id: 'settings', label: t('Settings'), icon: 'settings' }, { id: 'metadata', label: t('Metadata fields'), icon: 'tag' }] : []),
];

export function render(main, section, id) {
  App.title(t('Control panel'));
  const nav = h('div.settings-nav', SECTIONS().map(s => h('a.tab', { href: '#/admin/' + s.id, class: s.id === section ? 'active' : '' }, icon(s.icon), h('span', s.label))));
  const body = h('div.settings-body.admin-body');
  replace(main, h('div.view.settings', h('div.toolbar', h('div.crumbs', h('span.crumb.current', icon('settings'), ' ', t('Control panel')))), h('div.content.page', h('div.settings-layout', nav, body))));
  const fn = { dashboard, users, groups, activity, links, settings, metadata }[section] || dashboard;
  fn(body, id).catch(e => replace(body, emptyState('alert', t('Error'), e.message)));
}

function card(title, ...children) { return h('section.card.settings-card', title ? h('h3', title) : null, ...children); }

// ---------------------------------------------------------------------------
async function dashboard(body) {
  if (!App.isSuper) { const u = await api.get('admin/users', { limit: 1 }); replace(body, card(t('Overview'), h('p', t('You manage {n} accounts.', { n: u.total })), h('a.btn.btn-primary', { href: '#/admin/users' }, t('Manage users')))); return; }
  const s = await api.get('admin/system');
  const c = s.counts;
  const stat = (label, value, sub) => h('div.stat', h('div.stat-value', value), h('div.stat-label', label), sub ? h('div.stat-sub', sub) : null);
  replace(body,
    h('div.stats', stat(t('Users'), c.active_users, c.guests ? t('{n} guests', { n: c.guests }) : null), stat(t('Files'), c.files, t('{n} folders', { n: c.folders })), stat(t('Storage used'), fmtSize(c.used_bytes), t('{free} free on disk', { free: fmtSize(s.disk_free) })), stat(t('Shares'), c.shares, t('{n} active links', { n: c.links })), stat(t('Sessions'), c.sessions, null), stat(t('Indexed documents'), c.indexed_text, c.jobs_pending ? t('{n} jobs pending', { n: c.jobs_pending }) : null)),
    card(t('Server'), h('dl.kv.two', h('dt', t('Version')), h('dd', s.version), h('dt', 'PHP'), h('dd', s.php + ' (' + s.sapi + ')'), h('dt', t('Database')), h('dd', s.db), h('dt', t('Data folder')), h('dd', s.data_dir, s.data_dir_writable ? '' : h('span.tag-chip.warn', t('not writable'))), h('dt', t('Upload chunk')), h('dd', fmtSize(s.php_limits.chunk_size) + ' (post_max_size ' + s.php_limits.post_max_size + ')'), h('dt', t('Maintenance (cron)')), h('dd', s.cron_last_run ? t('last run {when}', { when: relTime(s.cron_last_run) }) : h('span.tag-chip.warn', t('never ran')), h('div.hint', t('Add this cron job:'), ' ', h('code', s.cron_command))), h('dt', t('WebDAV')), h('dd', h('code', s.webdav_url))),
      h('div.tool-grid', ...Object.entries(s.extensions).map(([k, v]) => h('span.tool', { class: v ? 'ok' : 'missing' }, icon(v ? 'check' : 'x'), k)), ...Object.entries(s.binaries).map(([k, v]) => h('span.tool', { class: v ? 'ok' : 'missing' }, icon(v ? 'check' : 'x'), k))),
      h('div.form-actions', h('button.btn', { onClick: async () => { const r = await api.post('admin/run_cron'); toast(t('Maintenance done: {n} jobs processed', { n: r.jobs }), 'success'); dashboard(body); } }, icon('wrench'), t('Run maintenance now')), h('a.btn', { href: App.base + '/api/public/openapi', target: '_blank' }, icon('code'), t('OpenAPI description')))),
    s.log_tail ? card(t('Recent log'), h('pre.log', s.log_tail)) : null);
}

// ---------------------------------------------------------------------------
async function users(body, id) {
  if (id) return userEditor(body, id === 'new' ? null : Number(id));
  const filters = { q: '', role: '', active: '', sort: 'username', dir: 'asc', offset: 0, limit: 50 };
  const table = h('div');
  const search = h('input.input', { placeholder: t('Search users…'), style: { maxWidth: '260px' } });
  search.addEventListener('input', debounce(() => { filters.q = search.value; filters.offset = 0; load(); }, 250));
  const roleSel = h('select.input', { style: { maxWidth: '160px' }, onChange: e => { filters.role = e.target.value; filters.offset = 0; load(); } }, h('option', { value: '' }, t('All roles')), ['superuser', 'admin', 'user', 'guest'].map(r => h('option', { value: r }, t(r))));
  const activeSel = h('select.input', { style: { maxWidth: '160px' }, onChange: e => { filters.active = e.target.value; filters.offset = 0; load(); } }, h('option', { value: '' }, t('Active and inactive')), h('option', { value: '1' }, t('Active')), h('option', { value: '0' }, t('Inactive')));
  replace(body, card(null, h('div.row.gap', search, roleSel, activeSel, h('span.spacer'), h('a.btn.btn-primary', { href: '#/admin/users/new' }, icon('user-plus'), t('New user'))), table));
  async function load() {
    replace(table, spinner());
    const res = await api.get('admin/users', filters);
    const th = (k, l) => h('th', h('button.th-sort', { class: filters.sort === k ? 'active' : '', onClick: () => { if (filters.sort === k) filters.dir = filters.dir === 'asc' ? 'desc' : 'asc'; else { filters.sort = k; filters.dir = 'asc'; } load(); } }, l, filters.sort === k ? icon(filters.dir === 'asc' ? 'chevron-up' : 'chevron-down') : null));
    replace(table, h('table.table.users-table', h('thead', h('tr', th('username', t('User')), th('role', t('Role')), th('used_bytes', t('Storage')), th('last_login_at', t('Last sign-in')), th('created_at', t('Created')), h('th', ''))),
      h('tbody', res.users.map(u => h('tr', { class: u.active ? '' : 'muted' },
        h('td', h('a.user-cell', { href: '#/admin/users/' + u.id }, avatar(u, 'sm'), h('span', h('b', u.name || u.username), h('span.hint', u.username + (u.email ? ' · ' + u.email : '')))), !u.active ? h('span.tag-chip.warn', t('inactive')) : null, u.two_factor_totp || u.passkeys ? h('span.tag-chip', '2FA') : null),
        h('td', t(u.role)), h('td', fmtSize(u.used_bytes) + (u.quota_bytes ? ' / ' + fmtSize(u.quota_bytes) : ''), u.quota_bytes ? progressBar(u.used_bytes / u.quota_bytes * 100, 'thin') : null), h('td', u.last_login_at ? relTime(u.last_login_at) : t('never')), h('td', fmtDateShort(u.created_at)),
        h('td.actions', h('button.icon-btn', { onClick: e => menu(userMenu(u, load), e.currentTarget) }, icon('more-h'))))))),
      pager(res.total, filters, load));
  }
  load();
}

function pager(total, f, reload) {
  if (total <= f.limit) return h('div.pager', t('{n} total', { n: total }));
  const pages = Math.ceil(total / f.limit), cur = Math.floor(f.offset / f.limit) + 1;
  return h('div.pager', h('button.btn.btn-sm', { disabled: cur <= 1, onClick: () => { f.offset -= f.limit; reload(); } }, icon('chevron-left')), h('span', cur + ' / ' + pages + ' · ' + t('{n} total', { n: total })), h('button.btn.btn-sm', { disabled: cur >= pages, onClick: () => { f.offset += f.limit; reload(); } }, icon('chevron-right')));
}

function userMenu(u, reload) {
  return [
    { label: t('Edit'), icon: 'edit', onClick: () => App.navigate('#/admin/users/' + u.id) },
    App.isSuper && u.id !== App.user.id ? { label: t('Sign in as this user'), icon: 'log-in', onClick: async () => { if (await confirm(t('Sign in as {u}? This is recorded in the activity log and ends your own session.', { u: u.username }))) { await api.post('admin/impersonate', { id: u.id }); location.hash = '#/files/'; location.reload(); } } } : null,
    { label: u.active ? t('Deactivate') : t('Activate'), icon: u.active ? 'slash' : 'check', onClick: async () => { await api.post('admin/user_save', { id: u.id, active: !u.active }); reload(); } },
    { label: t('Sign out all sessions'), icon: 'log-out', onClick: async () => { await api.post('admin/user_revoke_sessions', { id: u.id }); toast(t('Done'), 'success'); } },
    { label: t('Reset two-factor'), icon: 'shield', onClick: async () => { if (await confirm(t('Remove the authenticator app and recovery codes of {u}?', { u: u.username }))) { await api.post('admin/user_reset_2fa', { id: u.id }); toast(t('Done'), 'success'); } } },
    { label: t('Recalculate storage'), icon: 'refresh', onClick: async () => { await api.post('admin/recalc_usage', { id: u.id }); reload(); } },
    { label: t('Rebuild search index'), icon: 'file-search', onClick: async () => { await api.post('admin/rescan', { id: u.id }); await api.post('admin/reindex', { id: u.id }); toast(t('Index rebuilt; content indexing continues in the background'), 'success'); } },
    { sep: true },
    { label: t('Delete'), icon: 'trash', danger: true, onClick: async () => {
      const files = field({ type: 'checkbox', name: 'files', label: t('Also delete the files of this account from the disk'), value: false });
      const ok = await dialog({ title: t('Delete {u}?', { u: u.username }), width: '440px', body: h('div', h('p.dialog-text', t('Shares, links and metadata of this account are removed. Activity log entries are kept.')), files), buttons: [{ label: t('Cancel'), value: false }, { label: t('Delete'), danger: true, value: true }] }).result;
      if (ok) { await api.post('admin/user_delete', { id: u.id, delete_files: files.input.checked }); toast(t('User deleted'), 'success'); reload(); }
    } },
  ];
}

async function userEditor(body, id) {
  const u = id ? await api.get('admin/user', { id }) : null;
  const groups = await api.get('admin/groups');
  const perms = u ? u.perms : (App.settings.default_perms || {});
  const permDefs = [['upload', t('Upload / create files')], ['download', t('Download')], ['share_users', t('Share with users and groups')], ['share_links', t('Create public links')], ['share_guests', t('Share with e-mail addresses (guests)')], ['file_history', t('Keep file versions')], ['webdav', t('WebDAV access')], ['api', t('API access')], ['web', t('Web interface access')], ['comments', t('Comments')], ['metadata', t('Edit metadata, tags and labels')], ['trash', t('Use the trash')], ['hide_users', t('Hide other users from this account')]];
  const f = {
    username: field({ label: t('Username'), name: 'username', value: u ? u.username : '', required: true }),
    email: field({ label: t('E-mail'), name: 'email', type: 'email', value: u ? (u.email || '') : '' }),
    name: field({ label: t('Name'), name: 'name', value: u ? u.name : '' }),
    password: field({ label: u ? t('New password (leave empty to keep)') : t('Password'), name: 'password', type: 'password', autocomplete: 'new-password' }),
    role: field({ label: t('Role'), name: 'role', type: 'select', value: u ? u.role : 'user', options: (App.isSuper ? ['superuser', 'admin', 'user', 'guest'] : ['user', 'guest']).map(r => ({ value: r, label: t(r) })), disabled: !App.isSuper }),
    language: field({ label: t('Language'), name: 'language', type: 'select', value: u ? (u.language || '') : '', options: [{ value: '', label: t('Server default') }, ...Object.entries(LANGUAGES).map(([v, l]) => ({ value: v, label: l }))] }),
    home: field({ label: t('Home folder (relative to the data folder, or absolute)'), name: 'home_path', value: u ? u.home_path : '', placeholder: 'users/{username}' }),
    quota: field({ label: t('Storage quota (e.g. 10G, 0 = unlimited)'), name: 'quota_bytes', value: u ? sizeStr(u.quota_bytes) : sizeStr(App.settings.default_quota || 0) }),
    traffic: field({ label: t('Monthly traffic limit (0 = unlimited)'), name: 'traffic_quota_bytes', value: u ? sizeStr(u.traffic_quota_bytes) : '0' }),
    maxUpload: field({ label: t('Maximum upload size (0 = unlimited)'), name: 'max_upload_bytes', value: u ? sizeStr(u.max_upload_bytes) : '0' }),
    allowed: field({ label: t('Allowed file types (comma separated, empty = all)'), name: 'allowed_extensions', value: u ? u.allowed_extensions : '' }),
    denied: field({ label: t('Blocked file types'), name: 'denied_extensions', value: u ? u.denied_extensions : '' }),
    expires: field({ label: t('Account end date'), name: 'expires_at', type: 'date', value: u && u.expires_at ? u.expires_at.slice(0, 10) : '' }),
    ip: field({ label: t('Allowed IP addresses (one per line: 1.2.3.4, 10.0.0.0/8, 192.168.*, a-b)'), name: 'ip_rules', type: 'textarea', rows: 2, value: u ? (u.ip_rules || '') : '' }),
    mustChange: field({ type: 'checkbox', name: 'must_change_password', label: t('Must change password at next sign-in'), value: u ? u.must_change_password : false }),
    active: field({ type: 'checkbox', name: 'active', label: t('Account is active'), value: u ? u.active : true }),
    email2fa: field({ type: 'checkbox', name: 'two_factor_email', label: t('E-mail code as second factor'), value: u ? u.two_factor_email : true }),
    welcome: u ? null : field({ type: 'checkbox', name: 'send_welcome', label: t('E-mail the credentials to the user'), value: false }),
  };
  const permFields = permDefs.map(([k, l]) => field({ type: 'checkbox', name: 'perm_' + k, label: l, value: !!perms[k] }));
  const groupFields = groups.map(g => field({ type: 'checkbox', name: 'group_' + g.id, label: g.name, value: u ? u.groups.some(x => x.id === g.id) : false }));
  const save = async () => {
    const data = { id: id || undefined, username: f.username.input.value.trim(), email: f.email.input.value.trim(), name: f.name.input.value.trim(), role: f.role.input.value, language: f.language.input.value, home_path: f.home.input.value.trim(), quota_bytes: parseSize(f.quota.input.value), traffic_quota_bytes: parseSize(f.traffic.input.value), max_upload_bytes: parseSize(f.maxUpload.input.value), allowed_extensions: f.allowed.input.value, denied_extensions: f.denied.input.value, expires_at: f.expires.input.value ? f.expires.input.value + ' 23:59:59' : '', ip_rules: f.ip.input.value, must_change_password: f.mustChange.input.checked, active: f.active.input.checked, two_factor_email: f.email2fa.input.checked, perms: {}, groups: [] };
    if (f.password.input.value) data.password = f.password.input.value;
    if (f.welcome) data.send_welcome = f.welcome.input.checked;
    permFields.forEach(pf => { data.perms[pf.input.name.slice(5)] = pf.input.checked; });
    groupFields.forEach(gf => { if (gf.input.checked) data.groups.push(Number(gf.input.name.slice(6))); });
    try { const saved = await api.post('admin/user_save', data); toast(t('Saved'), 'success'); if (!id) App.navigate('#/admin/users/' + saved.id); } catch (e) { toast(e.message, 'error'); }
  };
  replace(body,
    h('div.row.between', h('a.link', { href: '#/admin/users' }, icon('arrow-left'), ' ', t('Back to users')), u ? h('div.row.gap', h('button.btn', { onClick: e => menu(userMenu({ ...u, id }, () => userEditor(body, id)), e.currentTarget) }, icon('more-h'), t('Actions'))) : null),
    card(u ? t('Edit {u}', { u: u.username }) : t('New user'), h('div.form-grid', f.username, f.email, f.name, f.password, f.role, f.language), h('div.check-list', f.active, f.mustChange, f.email2fa, f.welcome)),
    u ? card(t('Status'), h('dl.kv.two', h('dt', t('Storage used')), h('dd', fmtSize(u.used_bytes) + (u.quota_bytes ? ' / ' + fmtSize(u.quota_bytes) : '')), h('dt', t('Traffic this month')), h('dd', fmtSize(u.traffic_bytes)), h('dt', t('Last sign-in')), h('dd', u.last_login_at ? fmtDate(u.last_login_at) : t('never')), h('dt', t('Created')), h('dd', fmtDate(u.created_at) + (u.created_by_name ? ' ' + t('by {name}', { name: u.created_by_name }) : '')), h('dt', t('Two-factor')), h('dd', [u.two_factor_totp ? t('authenticator') : null, u.two_factor_email ? t('e-mail') : null, u.passkeys ? t('{n} passkeys', { n: u.passkeys }) : null].filter(Boolean).join(', ') || t('none')), h('dt', t('Sessions')), h('dd', u.sessions.length), h('dt', t('Links / shares')), h('dd', u.links + ' / ' + u.shares), u.deactivated_reason ? h('dt', t('Deactivated')) : null, u.deactivated_reason ? h('dd', u.deactivated_reason) : null)) : null,
    card(t('Limits'), h('div.form-grid', f.home, f.quota, f.traffic, f.maxUpload, f.allowed, f.denied, f.expires, f.ip)),
    card(t('Permissions'), h('div.check-list.cols', permFields)),
    groups.length ? card(t('Groups'), h('div.check-list.cols', groupFields)) : null,
    h('div.form-actions.sticky', h('button.btn.btn-primary', { onClick: save }, icon('save'), u ? t('Save changes') : t('Create user'))));
}

function sizeStr(bytes) { bytes = Number(bytes) || 0; if (!bytes) return '0'; const u = ['', 'K', 'M', 'G', 'T']; let i = 0; while (bytes >= 1024 && bytes % 1024 === 0 && i < 4) { bytes /= 1024; i++; } return bytes + u[i]; }
function parseSize(s) { s = String(s || '').trim(); const m = s.match(/^([\d.]+)\s*([kmgt]?)b?$/i); if (!m) return Number(s) || 0; return Math.round(parseFloat(m[1]) * ({ '': 1, k: 1024, m: 1048576, g: 1073741824, t: 1099511627776 }[m[2].toLowerCase()])); }

// ---------------------------------------------------------------------------
async function groups(body) {
  const list = await api.get('admin/groups');
  const edit = async (g) => {
    const full = g ? await api.get('admin/group', { id: g.id }) : { name: '', description: '', members: [] };
    const name = field({ label: t('Name'), name: 'name', value: full.name, required: true });
    const desc = field({ label: t('Description'), name: 'description', value: full.description });
    const members = new Map(full.members.map(m => [m.id, m]));
    const chips = h('div.chips');
    const paint = () => replace(chips, [...members.values()].map(m => h('span.chip', m.name, h('button.chip-x', { type: 'button', onClick: () => { members.delete(m.id); paint(); } }, icon('x')))));
    paint();
    const search = h('input.input', { placeholder: t('Add members…') });
    const results = h('div.share-results', { hidden: true });
    search.addEventListener('input', debounce(async () => { const q = search.value.trim(); if (!q) { results.hidden = true; return; } const r = await api.get('admin/users', { q, limit: 10 }); replace(results, r.users.filter(x => !members.has(x.id)).map(x => h('button.share-result', { type: 'button', onClick: () => { members.set(x.id, { id: x.id, name: x.name || x.username }); search.value = ''; results.hidden = true; paint(); } }, avatar(x, 'sm'), h('span.sr-name', x.name || x.username), h('span.sr-sub', x.username)))); results.hidden = false; }, 200));
    await dialog({ title: g ? t('Edit group') : t('New group'), width: '520px', body: h('div.no-enter', name, desc, h('label.lbl', t('Members')), chips, h('div.share-search', search, results)), buttons: [g ? { label: t('Delete'), danger: true, cls: 'btn-ghost', onClick: async () => { if (!await confirm(t('Delete this group? Shares with the group are removed.'), { danger: true })) return false; await api.post('admin/group_delete', { id: g.id }); } } : null, { label: t('Cancel') }, { label: t('Save'), primary: true, onClick: async () => { await api.post('admin/group_save', { id: g ? g.id : 0, name: name.input.value, description: desc.input.value, members: [...members.keys()] }); } }].filter(Boolean) }).result;
    groups(body);
  };
  replace(body, card(null, h('div.row.between', h('h3', t('Groups')), h('button.btn.btn-primary', { onClick: () => edit(null) }, icon('plus'), t('New group'))),
    list.length ? h('table.table', h('thead', h('tr', h('th', t('Name')), h('th', t('Description')), h('th', t('Members')), h('th', ''))), h('tbody', list.map(g => h('tr', h('td', h('b', g.name)), h('td', g.description), h('td', g.members), h('td.actions', h('button.btn.btn-sm', { onClick: () => edit(g) }, t('Edit'))))))) : h('p.muted', t('No groups yet. Groups make sharing with whole teams a single click.'))));
}

// ---------------------------------------------------------------------------
async function activity(body) {
  const f = { user: '', action: '', status: '', client: '', from: '', to: '', limit: 100, offset: 0 };
  const table = h('div');
  const userIn = h('input.input', { placeholder: t('User'), style: { maxWidth: '160px' } });
  const actionSel = h('select.input', { style: { maxWidth: '180px' } }, h('option', { value: '' }, t('All actions')));
  const statusSel = h('select.input', { style: { maxWidth: '140px' } }, h('option', { value: '' }, t('OK and failed')), h('option', { value: 'ok' }, 'OK'), h('option', { value: 'failed' }, t('Failed')));
  const clientSel = h('select.input', { style: { maxWidth: '140px' } }, h('option', { value: '' }, t('All clients')), ['web', 'webdav', 'api', 'link', 'system', 'cron'].map(c => h('option', { value: c }, c)));
  const from = h('input.input', { type: 'date', style: { maxWidth: '160px' } });
  const to = h('input.input', { type: 'date', style: { maxWidth: '160px' } });
  const apply = debounce(() => { Object.assign(f, { user: userIn.value, action: actionSel.value, status: statusSel.value, client: clientSel.value, from: from.value, to: to.value, offset: 0 }); load(); }, 250);
  [userIn, actionSel, statusSel, clientSel, from, to].forEach(el => { el.addEventListener('input', apply); el.addEventListener('change', apply); });
  replace(body, card(null, h('div.row.gap.wrap', userIn, actionSel, statusSel, clientSel, from, to, h('span.spacer'), h('a.btn', { href: '#', onClick: e => { e.preventDefault(); location.href = api.url('admin/activity_csv', { user: f.user, action: f.action, status: f.status, client: f.client, from: f.from, to: f.to }); } }, icon('download'), 'CSV')), table));
  async function load() {
    replace(table, spinner());
    const res = await api.get('admin/activity', f);
    if (actionSel.options.length === 1) res.actions.forEach(a => actionSel.appendChild(h('option', { value: a }, a)));
    replace(table, h('table.table.activity-table', h('thead', h('tr', h('th', t('Time')), h('th', t('User')), h('th', t('Action')), h('th', t('Item')), h('th', t('Client')), h('th', t('IP address')), h('th', t('Status')))),
      h('tbody', res.items.map(r => h('tr', { class: r.status === 'failed' ? 'failed' : '' }, h('td', { title: r.created_at }, fmtDate(r.created_at)), h('td', r.user_name || h('span.muted', '—')), h('td', actionLabel({ ...r, path: '' }) || r.action), h('td.path-cell', { title: (r.path || '') + (r.path2 ? ' → ' + r.path2 : '') }, r.path || '', r.path2 ? h('span.muted', ' → ' + r.path2) : null, r.details && r.details.username && !r.path ? h('span.muted', r.details.username) : null), h('td', r.client, h('div.hint', r.client_name)), h('td', r.ip), h('td', r.status === 'failed' ? h('span.tag-chip.warn', t('failed')) : h('span.tag-chip.ok', 'OK'), r.reason ? h('div.hint', r.reason) : null))))),
      pager(res.total, f, load));
  }
  load();
}

// ---------------------------------------------------------------------------
async function links(body) {
  const [l, s] = await Promise.all([api.get('admin/links'), api.get('admin/shares')]);
  replace(body,
    card(t('Public links'), App.isSuper ? h('div.row.gap', field({ type: 'switch', name: 'links_enabled', label: t('Public links are enabled on this server (turn off to disable every link at once)'), value: l.links_enabled, attrs: { onChange: async e => { await api.post('admin/links_toggle_all', { disable: !e.target.checked }); toast(t('Saved'), 'success'); } } })) : null,
      l.items.length ? h('table.table', h('thead', h('tr', h('th', t('Owner')), h('th', t('Item')), h('th', t('Role')), h('th', t('Downloads')), h('th', t('Expires')), h('th', t('Status')), h('th', ''))), h('tbody', l.items.map(x => h('tr', h('td', x.owner_username), h('td', { title: x.path }, x.name), h('td', x.role_label), h('td', x.downloads), h('td', x.expires_at ? fmtDateShort(x.expires_at) : '—'), h('td', x.usable ? h('span.tag-chip.ok', t('active')) : h('span.tag-chip.warn', x.usable_reason)), h('td.actions', h('button.icon-btn', { title: t('Copy'), onClick: () => copyText(x.url).then(() => toast(t('Copied'), 'success')) }, icon('copy')), h('button.icon-btn', { title: t('Delete'), onClick: async () => { if (await confirm(t('Delete this link?'), { danger: true })) { await api.post('admin/link_delete', { id: x.id }); links(body); } } }, icon('trash'))))))) : h('p.muted', t('No links'))),
    card(t('Shares between users'), s.items.length ? h('table.table', h('thead', h('tr', h('th', t('Owner')), h('th', t('Item')), h('th', t('With')), h('th', t('Role')), h('th', t('Since')), h('th', ''))), h('tbody', s.items.map(x => h('tr', h('td', x.owner_username), h('td', { title: x.path }, x.path.split('/').pop()), h('td', x.target_name, x.target_type === 'group' ? h('span.tag-chip', t('group')) : null), h('td', x.role_label), h('td', fmtDateShort(x.created_at)), h('td.actions', h('button.icon-btn', { title: t('Remove'), onClick: async () => { await api.post('admin/share_delete', { id: x.id }); links(body); } }, icon('x'))))))) : h('p.muted', t('No shares'))));
}

// ---------------------------------------------------------------------------
const SETTINGS_SCHEMA = () => [
  { id: 'general', label: t('General'), fields: [
    ['app_name', t('Application name')], ['tagline', t('Tagline')], ['default_language', t('Default language'), 'select', Object.entries(LANGUAGES).map(([v, l]) => ({ value: v, label: l }))], ['timezone', t('Time zone')], ['login_message', t('Message on the sign-in page'), 'textarea'], ['announcement', t('Announcement banner (empty = none)')], ['maintenance_mode', t('Maintenance mode (only administrators can sign in)'), 'checkbox'], ['privacy_url', t('Privacy policy URL')], ['terms_url', t('Terms URL')], ['support_email', t('Support e-mail')],
  ] },
  { id: 'branding', label: t('Branding'), fields: [
    ['accent_color', t('Accent colour'), 'color'], ['default_theme', t('Default theme'), 'select', [{ value: 'auto', label: t('System') }, { value: 'light', label: t('Light') }, { value: 'dark', label: t('Dark') }]], ['custom_css', t('Custom CSS'), 'textarea'], ['custom_strings', t('Custom wording (JSON: {"English phrase": "Your phrase"})'), 'json'], ['email_footer', t('E-mail footer')],
  ] },
  { id: 'security', label: t('Security'), fields: [
    ['two_factor_email_default', t('E-mailed code as second factor by default for new accounts'), 'checkbox'], ['two_factor_required', t('Require a second factor for every account'), 'checkbox'], ['passkeys_enabled', t('Allow passkeys'), 'checkbox'], ['password_min_length', t('Minimum password length'), 'number'], ['password_require_mixed', t('Require upper case, lower case and digits'), 'checkbox'], ['session_lifetime_hours', t('Session lifetime (hours)'), 'number'], ['remember_me_days', t('"Keep me signed in" duration (days)'), 'number'], ['login_throttle', t('Slow down repeated failed sign-ins'), 'checkbox'], ['allow_password_reset', t('Allow password reset by e-mail'), 'checkbox'], ['signup_enabled', t('Allow visitors to create accounts'), 'checkbox'], ['signup_requires_approval', t('New sign-ups need approval'), 'checkbox'], ['trusted_proxies', t('Trusted proxies (one per line, CIDR allowed)'), 'list'], ['web_enabled', t('Web interface enabled'), 'checkbox'], ['webdav_enabled', t('WebDAV enabled'), 'checkbox'], ['webdav_require_app_password', t('WebDAV requires app passwords (never the account password)'), 'checkbox'], ['api_enabled', t('API enabled'), 'checkbox'], ['hide_users_from_everyone', t('Hide the user list from everybody except superusers'), 'checkbox'],
  ] },
  { id: 'sharing', label: t('Sharing'), fields: [
    ['links_enabled', t('Public links enabled (global switch)'), 'checkbox'], ['guest_sharing_enabled', t('Sharing with e-mail addresses (guest accounts) enabled'), 'checkbox'], ['link_max_days', t('Maximum link lifetime in days (0 = unlimited)'), 'number'], ['link_require_password', t('Links must have a password'), 'checkbox'], ['link_default_role', t('Default role for new links'), 'select', ['viewer', 'commenter', 'editor', 'contributor', 'content_manager', 'uploader'].map(r => ({ value: r, label: (App.features.roles || {})[r] || r }))], ['link_terms_default', t('Default terms text for links'), 'textarea'], ['share_notify_default', t('E-mail people when something is shared with them'), 'checkbox'],
  ] },
  { id: 'files', label: t('Files'), fields: [
    ['file_history_enabled', t('Keep previous versions when files are overwritten'), 'checkbox'], ['versions_keep', t('Versions to keep per file'), 'number'], ['trash_enabled', t('Use a trash for deleted files'), 'checkbox'], ['trash_retention_days', t('Empty trash entries after (days, 0 = never)'), 'number'], ['max_upload_size', t('Maximum upload size (e.g. 2G, 0 = unlimited)'), 'size'], ['allowed_extensions', t('Allowed file types (empty = all)')], ['denied_extensions', t('Blocked file types')], ['hidden_files', t('Hidden file names (comma separated)')], ['show_hidden_files', t('Show hidden files by default'), 'checkbox'], ['thumbnails_enabled', t('Thumbnails'), 'checkbox'], ['video_thumbnails', t('Video thumbnails (ffmpeg)'), 'checkbox'], ['pdf_thumbnails', t('PDF thumbnails'), 'checkbox'], ['preview_max_size', t('Do not preview files larger than (bytes or 200M)'), 'size'], ['exif_enabled', t('Read photo metadata (EXIF / IPTC / XMP) on upload'), 'checkbox'], ['text_index_enabled', t('Index document contents for search'), 'checkbox'], ['index_max_size', t('Do not index files larger than'), 'size'], ['ocr_enabled', t('OCR scanned PDFs and images (tesseract)'), 'checkbox'], ['ocr_languages', t('OCR languages (tesseract codes, e.g. eng+deu)')], ['search_max_results', t('Maximum search results'), 'number'],
  ] },
  { id: 'accounts', label: t('New accounts'), fields: [
    ['default_role', t('Default role'), 'select', ['user', 'admin', 'guest'].map(r => ({ value: r, label: t(r) }))], ['default_quota', t('Default storage quota (0 = unlimited)'), 'size'], ['default_traffic_quota', t('Default monthly traffic limit'), 'size'], ['default_max_upload', t('Default maximum upload size'), 'size'], ['default_home', t('Home folder pattern ({username} is replaced)')], ['default_perms', t('Default permissions (JSON)'), 'json'], ['inactive_deactivate_days', t('Deactivate accounts unused for (days, 0 = never)'), 'number'], ['inactive_delete_days', t('Delete deactivated-for-inactivity accounts after (days)'), 'number'], ['guest_cleanup', t('Delete guest accounts when their last share is removed'), 'checkbox'],
  ] },
  { id: 'email', label: t('E-mail'), fields: [
    ['mail_transport', t('Transport'), 'select', [{ value: 'mail', label: t('PHP mail() / sendmail') }, { value: 'smtp', label: 'SMTP' }, { value: 'disabled', label: t('Disabled') }]], ['mail_from', t('From address')], ['mail_from_name', t('From name')], ['smtp_host', t('SMTP host')], ['smtp_port', t('SMTP port'), 'number'], ['smtp_security', t('SMTP security'), 'select', [{ value: 'tls', label: 'STARTTLS' }, { value: 'ssl', label: 'SSL/TLS' }, { value: 'none', label: t('None') }]], ['smtp_user', t('SMTP user')], ['smtp_pass', t('SMTP password'), 'password'], ['notify_admin_email', t('Also send administrator notices to')],
  ] },
  { id: 'integrations', label: t('Integrations'), fields: [
    ['onlyoffice_url', t('ONLYOFFICE Document Server URL (empty = off)')], ['onlyoffice_secret', t('ONLYOFFICE JWT secret'), 'password'], ['google_viewer_enabled', t('Allow previewing office files with the Google Docs viewer (sends a temporary link to Google)'), 'checkbox'], ['oidc_enabled', t('OpenID Connect sign-in enabled'), 'checkbox'], ['oidc_issuer', t('OIDC issuer URL (Keycloak, Authentik, Entra ID, Google…)')], ['oidc_client_id', t('OIDC client id')], ['oidc_client_secret', t('OIDC client secret'), 'password'], ['oidc_scopes', t('OIDC scopes')], ['oidc_button_label', t('Sign-in button label')], ['oidc_auto_create', t('Create accounts automatically for new SSO users'), 'checkbox'],
  ] },
  { id: 'retention', label: t('Retention'), fields: [
    ['activity_retention_days', t('Keep activity log entries for (days, 0 = forever)'), 'number'], ['notification_retention_days', t('Keep notifications for (days)'), 'number'],
  ] },
];

async function settings(body, section = 'general') {
  const res = await api.get('admin/settings');
  const values = res.settings;
  const schema = SETTINGS_SCHEMA();
  const current = schema.find(s => s.id === section) ? section : 'general';
  const form = h('div');
  const tb = tabs(schema.map(s => ({ id: s.id, label: s.label })), current, id => { history.replaceState(null, '', '#/admin/settings/' + id); paintForm(id); });
  const inputs = {};
  function paintForm(id) {
    const sec = schema.find(s => s.id === id);
    clear(form);
    for (const [key, label, type, options] of sec.fields) {
      let v = values[key];
      let fld;
      if (type === 'checkbox') fld = field({ type: 'switch', name: key, label, value: !!v });
      else if (type === 'select') fld = field({ label, name: key, type: 'select', value: v, options });
      else if (type === 'textarea') fld = field({ label, name: key, type: 'textarea', rows: 4, value: v ?? '' });
      else if (type === 'json') fld = field({ label, name: key, type: 'textarea', rows: 4, value: typeof v === 'string' ? v : JSON.stringify(v ?? {}, null, 2), attrs: { spellcheck: false } });
      else if (type === 'list') fld = field({ label, name: key, type: 'textarea', rows: 3, value: Array.isArray(v) ? v.join('\n') : (v ?? '') });
      else if (type === 'size') fld = field({ label, name: key, value: sizeStr(v) });
      else if (type === 'number') fld = field({ label, name: key, type: 'number', value: v ?? 0 });
      else if (type === 'color') fld = field({ label, name: key, type: 'color', value: v || '#2563eb' });
      else if (type === 'password') fld = field({ label, name: key, type: 'password', value: v ?? '', attrs: { autocomplete: 'new-password' } });
      else fld = field({ label, name: key, value: v ?? '' });
      inputs[key] = { el: fld.input, type };
      form.appendChild(fld);
    }
    if (id === 'branding') {
      form.appendChild(h('div.field', h('label.lbl', t('Logo')), h('div.row.gap', values.logo_url ? h('img.brand-preview', { src: values.logo_url }) : h('span.muted', t('None')), uploadBtn('logo'), values.logo_url ? h('button.btn.btn-sm', { onClick: async () => { await api.post('admin/brand_remove', { kind: 'logo' }); settings(body, 'branding'); } }, t('Remove')) : null)));
      form.appendChild(h('div.field', h('label.lbl', t('Favicon')), h('div.row.gap', values.favicon_url ? h('img.brand-preview.small', { src: values.favicon_url }) : h('span.muted', t('None')), uploadBtn('favicon'))));
    }
    if (id === 'email') form.appendChild(h('div.form-actions', h('button.btn', { onClick: async () => { const to = await prompt(t('Send a test message to'), { value: App.user.email || '', required: true }); if (to) { const r = await api.post('admin/test_email', { to }); toast(r.ok ? t('Test message sent') : (r.error || t('Sending failed')), r.ok ? 'success' : 'error', 6000); } } }, icon('send'), t('Send test e-mail'))));
    if (id === 'integrations') form.appendChild(h('p.hint', t('OIDC redirect URI to register at your identity provider:'), ' ', h('code', location.origin + App.base + '/api/auth/oidc_callback')));
  }
  function uploadBtn(kind) {
    return h('label.btn.btn-sm', icon('upload'), t('Upload'), h('input', { type: 'file', accept: 'image/*', style: { display: 'none' }, onChange: async e => { const fd = new FormData(); fd.append('file', e.target.files[0]); fd.append('kind', kind); try { await api.form('admin/brand_upload', fd); toast(t('Uploaded'), 'success'); location.reload(); } catch (ex) { toast(ex.message, 'error'); } } }));
  }
  const save = async () => {
    const out = {};
    for (const [key, { el, type }] of Object.entries(inputs)) {
      if (!el.isConnected) continue;
      if (type === 'checkbox') out[key] = el.checked;
      else if (type === 'json') { try { out[key] = JSON.parse(el.value || '{}'); } catch (e) { toast(t('Invalid JSON in {f}', { f: key }), 'error'); return; } }
      else if (type === 'list') out[key] = el.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      else if (type === 'size') out[key] = parseSize(el.value);
      else if (type === 'number') out[key] = Number(el.value);
      else out[key] = el.value;
    }
    try { const r = await api.post('admin/settings_save', { values: out }); Object.assign(values, r.settings); toast(t('Settings saved'), 'success'); if (out.app_name || out.accent_color !== undefined || out.default_theme) { Object.assign(App.settings, out); (await import('./app.js')).applyTheme(); } } catch (e) { toast(e.message, 'error'); }
  };
  replace(body, card(null, tb.el, form, h('div.form-actions.sticky', h('button.btn.btn-primary', { onClick: save }, icon('save'), t('Save settings')))));
  paintForm(current);
}

// ---------------------------------------------------------------------------
async function metadata(body) {
  const fields = await api.get('admin/metadata_fields');
  const edit = async (f) => {
    const name = field({ label: t('Name'), name: 'name', value: f ? f.name : '', required: true });
    const key = field({ label: t('Key (letters, digits, underscore)'), name: 'key', value: f ? f.key : '', placeholder: t('generated from the name') });
    const type = field({ label: t('Type'), name: 'type', type: 'select', value: f ? f.type : 'text', options: ['text', 'textarea', 'number', 'date', 'select', 'boolean', 'url'].map(x => ({ value: x, label: t(x) })) });
    const options = field({ label: t('Options for "select" (one per line)'), name: 'options', type: 'textarea', rows: 3, value: f ? (f.options_list || []).join('\n') : '' });
    const types = field({ label: t('Applies to (extensions or kinds, comma separated; empty = all; "folder" for folders)'), name: 'file_types', value: f ? f.file_types : '', placeholder: 'jpg, png, image, pdf, folder' });
    const col = field({ type: 'checkbox', name: 'show_column', label: t('Show as a column in the file list'), value: f ? f.show_column == 1 : false });
    const req = field({ type: 'checkbox', name: 'required', label: t('Required'), value: f ? f.required == 1 : false });
    const order = field({ label: t('Sort order'), name: 'sort_order', type: 'number', value: f ? f.sort_order : 0 });
    await dialog({ title: f ? t('Edit field') : t('New metadata field'), width: '520px', body: h('div.form-grid', name, key, type, options, types, order, col, req), buttons: [f ? { label: t('Delete'), danger: true, cls: 'btn-ghost', onClick: async () => { if (!await confirm(t('Delete this field? Values stored on files are kept but no longer shown.'), { danger: true })) return false; await api.post('admin/metadata_field_delete', { id: f.id }); } } : null, { label: t('Cancel') }, { label: t('Save'), primary: true, onClick: async () => { await api.post('admin/metadata_field_save', { id: f ? f.id : 0, name: name.input.value, key: key.input.value, type: type.input.value, options: options.input.value, file_types: types.input.value, show_column: col.input.checked, required: req.input.checked, sort_order: Number(order.input.value) }); } }].filter(Boolean) }).result;
    const boot = await api.get('auth/status'); App.features = boot.features;
    metadata(body);
  };
  replace(body, card(null, h('div.row.between', h('h3', t('Custom metadata fields')), h('button.btn.btn-primary', { onClick: () => edit(null) }, icon('plus'), t('New field'))),
    h('p.muted', t('Define your own fields (client, project, invoice number, status…), choose which file types use them, show them as columns and search on them.')),
    fields.length ? h('table.table', h('thead', h('tr', h('th', t('Name')), h('th', t('Key')), h('th', t('Type')), h('th', t('Applies to')), h('th', t('Column')), h('th', ''))), h('tbody', fields.map(f => h('tr', h('td', h('b', f.name)), h('td', h('code', f.key)), h('td', t(f.type)), h('td', f.file_types || t('all')), h('td', f.show_column == 1 ? icon('check') : ''), h('td.actions', h('button.btn.btn-sm', { onClick: () => edit(f) }, t('Edit'))))))) : h('p.muted', t('No fields yet'))));
}
