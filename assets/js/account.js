/* Account settings: profile, security (password, 2FA, passkeys, sessions), apps & WebDAV, notifications, usage. */
import { App } from './store.js';
import { api } from './api.js';
import { t, LANGUAGES, setLanguage } from './i18n.js';
import { h, $, $$, icon, replace, clear, dialog, confirm, prompt, toast, menu, fmtSize, fmtDate, fmtDateShort, relTime, field, formValues, copyText, emptyState, spinner, tabs, loadScript, progressBar, alertDialog } from './ui.js';

// ---------------------------------------------------------------------------
// WebAuthn helpers (base64url <-> ArrayBuffer)
// ---------------------------------------------------------------------------
const b64u = {
  enc: (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
  dec: (s) => { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; return Uint8Array.from(atob(s), c => c.charCodeAt(0)).buffer; },
};

export async function webauthnCreate(opts) {
  const pk = { ...opts };
  pk.challenge = b64u.dec(opts.challenge);
  pk.user = { ...opts.user, id: b64u.dec(opts.user.id) };
  pk.excludeCredentials = (opts.excludeCredentials || []).map(c => ({ ...c, id: b64u.dec(c.id) }));
  delete pk.challenge_id;
  const cred = await navigator.credentials.create({ publicKey: pk });
  return { id: cred.id, rawId: b64u.enc(cred.rawId), type: cred.type, response: { clientDataJSON: b64u.enc(cred.response.clientDataJSON), attestationObject: b64u.enc(cred.response.attestationObject), transports: cred.response.getTransports ? cred.response.getTransports() : [] } };
}

export async function webauthnGet(opts) {
  const pk = { ...opts };
  pk.challenge = b64u.dec(opts.challenge);
  pk.allowCredentials = (opts.allowCredentials || []).map(c => ({ ...c, id: b64u.dec(c.id) }));
  delete pk.challenge_id;
  const cred = await navigator.credentials.get({ publicKey: pk });
  return { id: cred.id, rawId: b64u.enc(cred.rawId), type: cred.type, response: { clientDataJSON: b64u.enc(cred.response.clientDataJSON), authenticatorData: b64u.enc(cred.response.authenticatorData), signature: b64u.enc(cred.response.signature), userHandle: cred.response.userHandle ? b64u.enc(cred.response.userHandle) : null } };
}

// ---------------------------------------------------------------------------
// Forced password change (must_change_password)
// ---------------------------------------------------------------------------
export function forcePasswordChange() {
  return new Promise(resolve => {
    const cur = field({ label: t('Current password'), name: 'current', type: 'password', autocomplete: 'current-password' });
    const p1 = field({ label: t('New password'), name: 'password', type: 'password', autocomplete: 'new-password' });
    const p2 = field({ label: t('Repeat new password'), name: 'password2', type: 'password', autocomplete: 'new-password' });
    dialog({
      title: t('Choose a new password'), width: '440px', closable: false, modal: true,
      body: h('div', h('p.dialog-text', t('You must change your password before continuing.')), cur, p1, p2),
      buttons: [{ label: t('Sign out'), cls: 'btn-ghost', onClick: async () => { await api.post('auth/logout').catch(() => {}); location.reload(); return false; } }, { label: t('Change password'), primary: true, onClick: async () => {
        if (p1.input.value !== p2.input.value) throw new Error(t('The passwords do not match'));
        const u = await api.post('account/password', { current: cur.input.value, password: p1.input.value });
        App.user = u; toast(t('Password changed'), 'success');
      } }],
      onClose: () => resolve(),
    });
  });
}

// ---------------------------------------------------------------------------
// Notifications dropdown
// ---------------------------------------------------------------------------
export async function notificationsPanel(anchor) {
  const existing = $('.notif-panel');
  if (existing) { existing.remove(); return; }
  const panel = h('div.notif-panel', spinner());
  document.body.appendChild(panel);
  const r = anchor.getBoundingClientRect();
  panel.style.top = (r.bottom + 6) + 'px';
  panel.style.right = (window.innerWidth - r.right) + 'px';
  const close = (e) => { if (!panel.contains(e.target) && e.target !== anchor && !anchor.contains(e.target)) { panel.remove(); document.removeEventListener('mousedown', close); } };
  setTimeout(() => document.addEventListener('mousedown', close), 0);
  try {
    const res = await api.get('account/notifications', { limit: 30 });
    const list = res.items;
    replace(panel, h('div.notif-head', h('b', t('Notifications')), list.some(n => !n.read) ? h('button.btn.btn-sm.btn-ghost', { onClick: async () => { const r2 = await api.post('account/notifications_read', {}); App.emit('notifications', r2.unread); notificationsPanel(anchor); setTimeout(() => notificationsPanel(anchor), 0); } }, t('Mark all read')) : null),
      list.length ? h('div.notif-list', list.map(n => h('a.notif', { href: n.data && n.data.url ? n.data.url.replace(/^https?:\/\/[^/]+/, '') : '#', class: n.read ? 'read' : '', onClick: async () => { if (!n.read) { const r2 = await api.post('account/notifications_read', { ids: [n.id] }); App.emit('notifications', r2.unread); } panel.remove(); } },
        h('span.notif-ic', icon({ share: 'users', link: 'link', upload: 'upload', download: 'download', comment: 'message', delete: 'trash', edit: 'edit', rename: 'edit', system: 'info', request: 'inbox' }[n.type] || 'bell')),
        h('span.notif-body', h('span.notif-title', n.title), n.body ? h('span.notif-text', n.body) : null, h('span.notif-time', relTime(n.created_at)))))) : h('div.notif-empty', t('No notifications')),
      h('div.notif-foot', h('a.link', { href: '#/account/notifications', onClick: () => panel.remove() }, t('Notification settings'))));
  } catch (e) { replace(panel, h('div.notif-empty', e.message)); }
}

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------
const TABS = () => [
  { id: 'profile', label: t('Profile'), icon: 'user' },
  { id: 'security', label: t('Security'), icon: 'shield' },
  { id: 'apps', label: t('Apps & devices'), icon: 'smartphone' },
  { id: 'notifications', label: t('Notifications'), icon: 'bell' },
  { id: 'usage', label: t('Storage'), icon: 'hard-drive' },
];

export function render(main, tab) {
  App.title(t('Account settings'));
  const body = h('div.settings-body');
  const tb = tabs(TABS(), tab, id => { history.replaceState(null, '', '#/account/' + id); paintTab(body, id); });
  replace(main, h('div.view.settings', h('div.toolbar', h('div.crumbs', h('span.crumb.current', icon('user'), ' ', t('Account settings')))), h('div.content.page', h('div.settings-layout', h('div.settings-nav', tb.el), body))));
  paintTab(body, tab);
}

function section(title, ...children) { return h('section.card.settings-card', h('h3', title), ...children); }

async function paintTab(body, tab) {
  clear(body);
  const u = App.user;
  if (tab === 'profile') {
    const f = { name: field({ label: t('Name'), name: 'name', value: u.name }), email: field({ label: t('E-mail'), name: 'email', type: 'email', value: u.email || '' }), language: field({ label: t('Language'), name: 'language', type: 'select', value: u.language || 'en', options: Object.entries(LANGUAGES).map(([v, l]) => ({ value: v, label: l })) }) };
    body.appendChild(section(t('Profile'), h('div.form-grid', f.name, f.email, f.language), h('div.kv-inline', h('span.muted', t('Username')), h('b', u.username), h('span.muted', t('Role')), h('b', u.role)), h('div.form-actions', h('button.btn.btn-primary', { onClick: async () => { try { App.user = await api.post('account/profile', { name: f.name.input.value, email: f.email.input.value, language: f.language.input.value }); await setLanguage(App.user.language); toast(t('Saved'), 'success'); } catch (e) { toast(e.message, 'error'); } } }, t('Save')))));
    const theme = App.pref('theme', 'auto');
    body.appendChild(section(t('Appearance'), h('div.form-grid', field({ label: t('Theme'), name: 'theme', type: 'select', value: theme, options: [{ value: 'auto', label: t('System') }, { value: 'light', label: t('Light') }, { value: 'dark', label: t('Dark') }], attrs: { onChange: async e => { App.setPref('theme', e.target.value); (await import('./app.js')).applyTheme(); } } }), field({ label: t('Default view'), name: 'view', type: 'select', value: App.pref('view', 'list'), options: [{ value: 'list', label: t('List') }, { value: 'grid', label: t('Grid') }, { value: 'gallery', label: t('Gallery') }], attrs: { onChange: e => App.setPref('view', e.target.value) } }))));
  } else if (tab === 'security') {
    const cur = field({ label: t('Current password'), name: 'current', type: 'password', autocomplete: 'current-password' });
    const p1 = field({ label: t('New password'), name: 'password', type: 'password', autocomplete: 'new-password' });
    const p2 = field({ label: t('Repeat new password'), name: 'password2', type: 'password', autocomplete: 'new-password' });
    body.appendChild(section(t('Password'), h('div.form-grid', cur, p1, p2), h('p.hint', t('Changing your password signs out every other session.')), h('div.form-actions', h('button.btn.btn-primary', { onClick: async () => { if (p1.input.value !== p2.input.value) return toast(t('The passwords do not match'), 'error'); try { App.user = await api.post('account/password', { current: cur.input.value, password: p1.input.value }); toast(t('Password changed'), 'success'); cur.input.value = p1.input.value = p2.input.value = ''; } catch (e) { toast(e.message, 'error'); } } }, t('Change password')))));
    body.appendChild(await twoFactorSection());
    if (App.features.passkeys) body.appendChild(await passkeysSection());
    body.appendChild(await sessionsSection());
  } else if (tab === 'apps') {
    body.appendChild(section(t('Connect a WebDAV client or the mobile app'),
      h('p', t('Mount your files as a network drive or sync them with any WebDAV client (Windows Explorer, macOS Finder, rclone, Cyberduck, Mountain Duck, GoodSync, Documents by Readdle…). Use an app password instead of your account password; you can revoke it any time.')),
      h('div.kv-inline', h('span.muted', t('WebDAV address')), h('code.copyable', { onClick: e => copyText(e.target.textContent).then(() => toast(t('Copied'), 'success')) }, App.features.webdav_url || (location.origin + App.base + '/dav/')), h('span.muted', t('Username')), h('code', u.username)),
      h('p.hint', t('Windows: This PC → Map network drive → paste the address. macOS: Finder → Go → Connect to Server. Add this site to your phone\'s home screen to use it like an app.'))));
    body.appendChild(await appPasswordsSection());
    body.appendChild(section(t('API'), h('p', t('Your own programs can use the JSON API with an app password as a Bearer token. The OpenAPI description is at:')), h('code.copyable', { onClick: e => copyText(e.target.textContent).then(() => toast(t('Copied'), 'success')) }, location.origin + App.base + '/api/public/openapi'), h('pre.code-sample', 'curl -H "Authorization: Bearer <app password>" "' + location.origin + App.base + '/api/files/list?p=/"')));
  } else if (tab === 'notifications') {
    const prefs = u.prefs || {};
    const types = [['share', t('Somebody shares something with me')], ['upload', t('Files are uploaded to folders I follow or own')], ['download', t('Files are downloaded (links, shared folders)')], ['comment', t('Comments')], ['delete', t('Files are deleted in folders I follow')], ['edit', t('Files are edited in folders I follow')], ['system', t('System messages')]];
    const master = field({ type: 'switch', name: 'email_notifications', label: t('Send me e-mails about notifications'), value: prefs.email_notifications !== false });
    const perType = types.map(([k, l]) => field({ type: 'checkbox', name: k, label: l, value: prefs.email_notify_types ? prefs.email_notify_types[k] !== false : true }));
    const save = async () => { const typesMap = {}; perType.forEach(f => { typesMap[f.input.name] = f.input.checked; }); try { App.user = await api.post('account/profile', { prefs: { ...prefs, email_notifications: master.input.checked, email_notify_types: typesMap } }); toast(t('Saved'), 'success', 1500); } catch (e) { toast(e.message, 'error'); } };
    master.input.addEventListener('change', save); perType.forEach(f => f.input.addEventListener('change', save));
    body.appendChild(section(t('E-mail notifications'), master, h('div.check-list', perType)));
    const follows = await api.get('account/follows');
    body.appendChild(section(t('Folders you follow'), follows.length ? h('ul.plain-list', follows.map(f => h('li', icon('folder'), h('span', f.owner_id === u.id ? '/' + f.path : f.owner + ': /' + f.path), h('button.icon-btn.sm', { title: t('Unfollow'), onClick: async () => { await api.post('account/unfollow', { owner_id: f.owner_id, path: f.path }); paintTab(body, tab); } }, icon('x'))))) : h('p.muted', t('You are not following any folder. Right-click a folder and choose "Follow" to be told when something changes inside it.'))));
  } else if (tab === 'usage') {
    const us = await api.get('account/usage');
    const pct = us.quota ? Math.min(100, us.used / us.quota * 100) : 0;
    body.appendChild(section(t('Storage'), h('div.usage-big', h('b', fmtSize(us.used)), us.quota ? h('span', ' ' + t('of {quota}', { quota: fmtSize(us.quota) })) : h('span', ' ' + t('used (no limit)'))), us.quota ? progressBar(pct, pct > 90 ? 'danger' : '') : null,
      h('dl.kv', h('dt', t('Files')), h('dd', us.index.files), h('dt', t('Folders')), h('dd', us.index.dirs), h('dt', t('Trash')), h('dd', fmtSize(us.trash)), h('dt', t('File versions')), h('dd', fmtSize(us.versions)), h('dt', t('Traffic this month')), h('dd', fmtSize(us.traffic) + (us.traffic_quota ? ' / ' + fmtSize(us.traffic_quota) : ''))),
      h('div.form-actions', h('button.btn', { onClick: async () => { await api.get('account/usage', { recalc: 1 }); paintTab(body, tab); } }, icon('refresh'), t('Recalculate')))));
    if (us.index.kinds && us.index.kinds.length) body.appendChild(section(t('By type'), h('table.table', h('tbody', us.index.kinds.map(k => h('tr', h('td', k.kind), h('td', k.n), h('td', fmtSize(k.size))))))));
  }
}

async function twoFactorSection() {
  const u = App.user;
  const tf = u.two_factor;
  const wrap = section(t('Two-step verification'));
  wrap.appendChild(h('p', t('A second step protects your account even if the password leaks. Codes can come from an authenticator app or from your e-mail.')));
  const rows = h('div.tf-rows');
  rows.appendChild(h('div.tf-row', icon('smartphone'), h('div.tf-text', h('b', t('Authenticator app')), h('div.hint', tf.totp ? t('Enabled') : t('Google Authenticator, Authy, 1Password, Microsoft Authenticator…'))),
    tf.totp ? h('div.row', h('button.btn.btn-sm', { onClick: recoveryCodesDialog }, t('Recovery codes')), h('button.btn.btn-sm', { onClick: disableTotp, disabled: tf.required && !tf.email }, t('Disable'))) : h('button.btn.btn-primary.btn-sm', { onClick: setupTotp }, t('Set up'))));
  rows.appendChild(h('div.tf-row', icon('mail'), h('div.tf-text', h('b', t('E-mail code')), h('div.hint', u.email ? t('A code is sent to {email} when you sign in', { email: u.email }) : t('Add an e-mail address to your profile first'))),
    field({ type: 'switch', name: 'email_2fa', label: '', value: tf.email, attrs: { onChange: async e => { try { App.user = await api.post('account/email_2fa', { on: e.target.checked }); toast(t('Saved'), 'success', 1500); } catch (ex) { toast(ex.message, 'error'); e.target.checked = !e.target.checked; } }, disabled: !u.email } })));
  if (tf.required) rows.appendChild(h('p.hint', icon('info'), ' ', t('A second factor is required on this server and cannot be turned off completely.')));
  wrap.appendChild(rows);
  return wrap;
}

async function setupTotp() {
  const s = await api.post('account/totp_setup');
  const qr = h('div.qr-box');
  const code = field({ label: t('Code from the app'), name: 'code', attrs: { inputmode: 'numeric', autocomplete: 'one-time-code' } });
  const dlg = dialog({
    title: t('Set up an authenticator app'), width: '440px',
    body: h('div', h('p.dialog-text', t('Scan this code with your authenticator app, then enter the 6-digit code it shows.')), qr, h('p.dialog-text.muted', t('Or enter the key manually:'), ' ', h('code', s.secret)), code),
    buttons: [{ label: t('Cancel') }, { label: t('Enable'), primary: true, onClick: async () => { const r = await api.post('account/totp_enable', { code: code.input.value }); App.user = r.user; await showRecovery(r.recovery_codes); } }],
    onClose: () => rerender(),
  });
  try { await loadScript(App.assetBase + '/assets/vendor/qrcode.min.js'); const q = window.qrcode(0, 'M'); q.addData(s.uri); q.make(); qr.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2 }); } catch (e) { qr.textContent = s.uri; }
  return dlg.result;
}

function showRecovery(codes) {
  return alertDialog(t('Recovery codes'), h('div', h('p.dialog-text', t('Store these codes somewhere safe. Each one signs you in once if you lose your device.')), h('pre.codes', codes.join('\n')), h('button.btn.btn-sm', { onClick: () => copyText(codes.join('\n')).then(() => toast(t('Copied'), 'success')) }, icon('copy'), t('Copy'))));
}

async function recoveryCodesDialog() {
  if (!await confirm(t('Generate new recovery codes? The old ones stop working.'))) return;
  const r = await api.post('account/recovery_regenerate');
  await showRecovery(r.recovery_codes);
}

async function disableTotp() {
  const pw = await prompt(t('Disable the authenticator app'), { label: t('Confirm with your password'), type: 'password', required: true, okLabel: t('Disable') });
  if (pw === null) return;
  try { App.user = await api.post('account/totp_disable', { password: pw }); toast(t('Authenticator disabled'), 'success'); rerender(); } catch (e) { toast(e.message, 'error'); }
}

async function passkeysSection() {
  const list = await api.get('account/passkeys');
  const wrap = section(t('Passkeys'));
  wrap.appendChild(h('p', t('Sign in with your fingerprint, face, device PIN or a security key instead of a password.')));
  if (!window.PublicKeyCredential) wrap.appendChild(h('p.hint', t('This browser does not support passkeys.')));
  wrap.appendChild(list.length ? h('ul.plain-list', list.map(p => h('li', icon('key'), h('span', h('b', p.name), h('span.hint', ' · ' + t('added {date}', { date: fmtDateShort(p.created_at) }), p.last_used_at ? ' · ' + t('last used {date}', { date: relTime(p.last_used_at) }) : '')), h('button.icon-btn.sm', { title: t('Rename'), onClick: async () => { const n = await prompt(t('Rename passkey'), { value: p.name, required: true }); if (n) { await api.post('account/passkey_rename', { id: p.id, name: n }); rerender(); } } }, icon('edit')), h('button.icon-btn.sm', { title: t('Remove'), onClick: async () => { if (await confirm(t('Remove this passkey?'), { danger: true, okLabel: t('Remove') })) { await api.post('account/passkey_delete', { id: p.id }); rerender(); } } }, icon('trash'))))) : h('p.muted', t('No passkeys yet')));
  wrap.appendChild(h('div.form-actions', h('button.btn.btn-primary', { disabled: !window.PublicKeyCredential, onClick: async () => {
    try {
      const opts = await api.post('account/passkey_register_options');
      const cred = await webauthnCreate(opts);
      const name = await prompt(t('Name this passkey'), { value: /iPhone|iPad/.test(navigator.userAgent) ? 'iPhone' : /Android/.test(navigator.userAgent) ? 'Android' : /Mac/.test(navigator.userAgent) ? 'Mac' : 'Windows', required: true });
      await api.post('account/passkey_register', { credential: cred, name: name || 'Passkey' });
      toast(t('Passkey added'), 'success'); rerender();
    } catch (e) { if (e.name !== 'NotAllowedError') toast(e.message, 'error'); }
  } }, icon('plus'), t('Add a passkey'))));
  return wrap;
}

async function sessionsSection() {
  const list = await api.get('account/sessions');
  const wrap = section(t('Active sessions'));
  wrap.appendChild(h('table.table', h('thead', h('tr', h('th', t('Device')), h('th', t('IP address')), h('th', t('Signed in')), h('th', t('Last activity')), h('th', ''))), h('tbody', list.map(s => h('tr', { class: s.current ? 'current' : '' }, h('td', s.client, s.current ? h('span.tag-chip', t('this session')) : null, s.remember ? h('span.tag-chip', t('remembered')) : null), h('td', s.ip), h('td', fmtDate(s.created_at)), h('td', relTime(s.last_seen_at)), h('td.actions', s.current ? null : h('button.btn.btn-sm', { onClick: async () => { await api.post('account/revoke_session', { id: s.full_id }); rerender(); } }, t('Sign out'))))))));
  if (list.length > 1) wrap.appendChild(h('div.form-actions', h('button.btn', { onClick: async () => { await api.post('account/revoke_session', { id: 'all' }); toast(t('Other sessions signed out'), 'success'); rerender(); } }, t('Sign out everywhere else'))));
  return wrap;
}

async function appPasswordsSection() {
  const list = await api.get('account/app_passwords');
  const wrap = section(t('App passwords'));
  wrap.appendChild(h('p', t('Each device or program gets its own password. Lose a laptop, revoke only that one.')));
  wrap.appendChild(list.length ? h('table.table', h('thead', h('tr', h('th', t('Name')), h('th', t('Scope')), h('th', t('Created')), h('th', t('Last used')), h('th', ''))), h('tbody', list.map(p => h('tr', h('td', h('b', p.name), ' ', h('code.muted', p.prefix + '…')), h('td', { all: t('WebDAV + API'), webdav: 'WebDAV', api: 'API' }[p.scope]), h('td', fmtDateShort(p.created_at)), h('td', p.last_used_at ? relTime(p.last_used_at) + (p.last_ip ? ' · ' + p.last_ip : '') : t('never')), h('td.actions', h('button.icon-btn', { title: t('Revoke'), onClick: async () => { if (await confirm(t('Revoke "{name}"? The device using it will be disconnected.', { name: p.name }), { danger: true, okLabel: t('Revoke') })) { await api.post('account/app_password_delete', { id: p.id }); rerender(); } } }, icon('trash'))))))) : h('p.muted', t('No app passwords yet')));
  wrap.appendChild(h('div.form-actions', h('button.btn.btn-primary', { onClick: async () => {
    const name = field({ label: t('Name (e.g. "Laptop", "Phone")'), name: 'name', value: '' });
    const scope = field({ label: t('Allowed for'), name: 'scope', type: 'select', value: 'all', options: [{ value: 'all', label: t('WebDAV and API') }, { value: 'webdav', label: t('WebDAV only') }, { value: 'api', label: t('API only') }] });
    await dialog({ title: t('New app password'), width: '440px', body: h('div', name, scope), buttons: [{ label: t('Cancel') }, { label: t('Create'), primary: true, onClick: async () => {
      const r = await api.post('account/app_password_create', { name: name.input.value || 'App', scope: scope.input.value });
      await alertDialog(t('Your new app password'), h('div', h('p.dialog-text', t('Copy it now; it will not be shown again.')), h('div.link-url', h('input.input', { readOnly: true, value: r.password, onClick: e => e.target.select() }), h('button.btn', { onClick: () => copyText(r.password).then(() => toast(t('Copied'), 'success')) }, icon('copy'), t('Copy'))), h('p.dialog-text.muted', t('Use it as the password together with your username {u}.', { u: App.user.username }))));
    } }] }).result;
    rerender();
  } }, icon('plus'), t('Create app password'))));
  return wrap;
}

function rerender() { const main = App.layout && App.layout.main; if (main) render(main, (App.route && App.route.tab) || 'security'); }
