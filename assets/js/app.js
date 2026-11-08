/* Application entry: boot, layout, routing, sign-in. */
import { App, decodePath } from './store.js';
import { api } from './api.js';
import { t, setLanguage, setCustomStrings } from './i18n.js';
import { h, $, $$, icon, replace, clear, dialog, toast, menu, avatar, field, formValues, spinner, confirm, copyText, kbd, fmtSize, relTime, loadScript } from './ui.js';
import * as Files from './files.js';
import * as Share from './share.js';
import * as Account from './account.js';
import * as Admin from './admin.js';
import * as Uploads from './upload.js';
import * as Link from './link.js';
import { openPreview } from './preview.js';

const root = $('#app');
let layout = null;

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------
export function applyTheme() {
  const pref = App.pref('theme', App.settings.default_theme || 'auto');
  const dark = pref === 'dark' || (pref === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.documentElement.dataset.themePref = pref;
  if (App.settings.accent_color) document.documentElement.style.setProperty('--accent', App.settings.accent_color);
}
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
async function boot() {
  applyTheme();
  setCustomStrings(App.settings.custom_strings || {});
  const langPref = (App.user && App.user.language) || App.settings.default_language || navigator.language;
  await setLanguage(langPref);
  App.title('');
  if (App.boot.error) toast(App.boot.error, 'error', 8000);
  document.addEventListener('fr:unauthenticated', () => { if (!App.isLinkMode) { App.user = null; renderLogin(); } });

  if (App.isLinkMode) {
    await Link.start(root);
    return;
  }
  if (App.boot.reset_token) { renderReset(App.boot.reset_token); return; }
  if (!App.user) {
    renderLogin(App.boot.pending || null);
    return;
  }
  await enterApp();
}

export async function enterApp() {
  if (App.user && App.user.must_change_password) {
    await Account.forcePasswordChange();
  }
  renderLayout();
  window.addEventListener('hashchange', route);
  await refreshSidebar();
  route();
  startNotificationPolling();
}

// ---------------------------------------------------------------------------
// Sign-in screens
// ---------------------------------------------------------------------------
function authShell(...content) {
  const s = App.settings;
  const brand = h('div.auth-brand', s.logo_url ? h('img.auth-logo', { src: s.logo_url, alt: s.app_name }) : h('div.auth-logo-mark', icon('folder')), h('div.auth-name', s.app_name));
  const links = [];
  if (s.privacy_url) links.push(h('a', { href: s.privacy_url, target: '_blank', rel: 'noopener' }, t('Privacy policy')));
  if (s.terms_url) links.push(h('a', { href: s.terms_url, target: '_blank', rel: 'noopener' }, t('Terms')));
  if (s.support_email) links.push(h('a', { href: 'mailto:' + s.support_email }, t('Support')));
  replace(root, h('div.auth', h('div.auth-card', brand, ...content, s.login_message ? h('div.auth-message', s.login_message) : null), h('div.auth-foot', ...links)));
}

export function renderLogin(pending = null) {
  App.title(t('Sign in'));
  if (pending) return renderSecondFactor(pending);
  const s = App.settings;
  const user = field({ label: t('Username or e-mail'), name: 'username', autocomplete: 'username', attrs: { autofocus: true, autocapitalize: 'none' } });
  const pass = field({ label: t('Password'), name: 'password', type: 'password', autocomplete: 'current-password' });
  const remember = field({ type: 'checkbox', name: 'remember', label: t('Keep me signed in') });
  const err = h('div.form-error', { hidden: true });
  const btn = h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Sign in'));
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault();
    err.hidden = true; btn.disabled = true;
    try {
      const res = await api.post('auth/login', { username: user.input.value.trim(), password: pass.input.value, remember: remember.input.checked });
      if (res.status === '2fa') { renderSecondFactor({ ...res, username: user.input.value.trim(), remember: remember.input.checked }); return; }
      App.user = res.user;
      await afterSignIn();
    } catch (ex) {
      err.textContent = ex.message; err.hidden = false; btn.disabled = false;
      pass.input.focus(); pass.input.select();
    }
  } }, user, pass, h('div.row.between', remember, s.allow_password_reset ? h('a.link', { href: '#', onClick: e => { e.preventDefault(); renderForgot(); } }, t('Forgot password?')) : null), err, btn);
  const extras = [];
  if (s.passkeys_enabled && window.PublicKeyCredential) {
    extras.push(h('button.btn.btn-block', { type: 'button', onClick: () => passkeyLogin(user.input.value.trim(), remember.input.checked, err) }, icon('key'), t('Sign in with a passkey')));
  }
  if (s.oidc_enabled) {
    extras.push(h('a.btn.btn-block', { href: App.base + '/api/auth/oidc' }, icon('shield'), s.oidc_button_label || t('Sign in with SSO')));
  }
  authShell(h('h1.auth-title', t('Sign in')), form, extras.length ? h('div.auth-or', h('span', t('or'))) : null, ...extras,
    s.signup_enabled ? h('p.auth-alt', t('No account yet?'), ' ', h('a.link', { href: '#', onClick: e => { e.preventDefault(); renderSignup(); } }, t('Create one'))) : null);
}

async function passkeyLogin(username, remember, errEl, options = null) {
  try {
    const opts = options || await api.post('auth/passkey_options', { username });
    const cred = await Account.webauthnGet(opts);
    const res = await api.post('auth/passkey_login', { credential: cred, challenge_id: opts.challenge_id, remember });
    App.user = res.user;
    await afterSignIn();
  } catch (ex) {
    if (ex && ex.name === 'NotAllowedError') return;
    if (errEl) { errEl.textContent = ex.message || String(ex); errEl.hidden = false; } else toast(ex.message || String(ex), 'error');
  }
}

function renderSecondFactor(p) {
  App.title(t('Two-step verification'));
  const methods = p.methods || [];
  let method = methods.includes('totp') ? 'totp' : (methods.includes('email') ? 'email' : methods[0]);
  const code = field({ label: t('Verification code'), name: 'code', attrs: { autofocus: true, inputmode: 'numeric', autocomplete: 'one-time-code', placeholder: '123456' } });
  const err = h('div.form-error', { hidden: true });
  const info = h('p.auth-text');
  const resend = h('button.btn.btn-ghost.btn-sm', { type: 'button', onClick: async () => { try { await api.post('auth/resend_code'); toast(t('A new code was sent'), 'success'); } catch (ex) { toast(ex.message, 'error'); } } }, t('Send a new code'));
  const setMethod = (m) => {
    method = m;
    info.textContent = m === 'totp' ? t('Enter the code from your authenticator app.') : m === 'email' ? t('We sent a code to {email}.', { email: p.email_hint || t('your e-mail address') }) : t('Enter one of your recovery codes.');
    resend.hidden = m !== 'email';
    if (m === 'email') api.post('auth/resend_code').catch(() => {});
    code.input.value = ''; code.input.focus();
  };
  const switcher = h('div.auth-methods');
  const names = { totp: t('Authenticator app'), email: t('E-mail code'), recovery: t('Recovery code') };
  for (const m of methods) switcher.appendChild(h('button.btn.btn-ghost.btn-sm', { type: 'button', onClick: () => setMethod(m) }, names[m] || m));
  if (p.passkeys > 0 && window.PublicKeyCredential) switcher.appendChild(h('button.btn.btn-ghost.btn-sm', { type: 'button', onClick: () => passkeyLogin(p.username, p.remember, err) }, icon('key'), t('Use a passkey')));
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault(); err.hidden = true;
    try {
      const res = await api.post('auth/two_factor', { code: code.input.value.trim(), method });
      App.user = res.user;
      await afterSignIn();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; code.input.select(); }
  } }, info, code, err, h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Continue')), resend);
  authShell(h('h1.auth-title', t('Two-step verification')), form, methods.length > 1 || p.passkeys > 0 ? switcher : null,
    h('p.auth-alt', h('a.link', { href: '#', onClick: async e => { e.preventDefault(); await api.post('auth/logout').catch(() => {}); renderLogin(); } }, t('Back to sign in'))));
  info.textContent = method === 'totp' ? t('Enter the code from your authenticator app.') : t('We sent a code to {email}.', { email: p.email_hint || t('your e-mail address') });
  resend.hidden = method !== 'email';
}

function renderForgot() {
  App.title(t('Reset password'));
  const id = field({ label: t('Username or e-mail'), name: 'identifier', attrs: { autofocus: true } });
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault();
    try { await api.post('auth/forgot', { identifier: id.input.value.trim() }); replace(form, h('p.auth-text', t('If the account exists and has an e-mail address, a message with a reset link is on its way.'))); } catch (ex) { toast(ex.message, 'error'); }
  } }, h('p.auth-text', t('Tell us your username or e-mail address and we will send you a link to choose a new password.')), id, h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Send reset link')));
  authShell(h('h1.auth-title', t('Reset password')), form, h('p.auth-alt', h('a.link', { href: '#', onClick: e => { e.preventDefault(); renderLogin(); } }, t('Back to sign in'))));
}

function renderReset(token) {
  App.title(t('Choose a new password'));
  const p1 = field({ label: t('New password'), name: 'password', type: 'password', autocomplete: 'new-password', attrs: { autofocus: true } });
  const p2 = field({ label: t('Repeat password'), name: 'password2', type: 'password', autocomplete: 'new-password' });
  const err = h('div.form-error', { hidden: true });
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault(); err.hidden = true;
    if (p1.input.value !== p2.input.value) { err.textContent = t('The passwords do not match'); err.hidden = false; return; }
    try { await api.post('auth/reset', { token, password: p1.input.value }); toast(t('Password changed. You can sign in now.'), 'success'); history.replaceState(null, '', App.base + '/'); App.boot.reset_token = null; renderLogin(); } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  } }, p1, p2, err, h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Change password')));
  authShell(h('h1.auth-title', t('Choose a new password')), form);
}

function renderSignup() {
  App.title(t('Create account'));
  const f = { username: field({ label: t('Username'), name: 'username', attrs: { autofocus: true } }), email: field({ label: t('E-mail'), name: 'email', type: 'email' }), name: field({ label: t('Name'), name: 'name' }), password: field({ label: t('Password'), name: 'password', type: 'password', autocomplete: 'new-password' }) };
  const err = h('div.form-error', { hidden: true });
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault(); err.hidden = true;
    try {
      const res = await api.post('auth/signup', { username: f.username.input.value.trim(), email: f.email.input.value.trim(), name: f.name.input.value.trim(), password: f.password.input.value });
      if (res.pending_approval) { replace(form, h('p.auth-text', t('Thank you. Your account will be reviewed by an administrator.'))); return; }
      App.user = res.user; await afterSignIn();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  } }, f.username, f.email, f.name, f.password, err, h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Create account')));
  authShell(h('h1.auth-title', t('Create account')), form, h('p.auth-alt', h('a.link', { href: '#', onClick: e => { e.preventDefault(); renderLogin(); } }, t('Back to sign in'))));
}

async function afterSignIn() {
  const boot = await api.get('auth/status');
  Object.assign(App, { user: boot.user, settings: boot.settings, features: boot.features, upload: boot.upload });
  App.boot.impersonated_by = boot.impersonated_by;
  await setLanguage(App.user.language || App.settings.default_language);
  applyTheme();
  if (!location.hash || location.hash === '#') App.navigate('#/files/', true);
  await enterApp();
}

export async function signOut() {
  try {
    const res = await api.post('auth/logout');
    if (res && res.redirect) { location.href = res.redirect; return; }
  } catch (e) { /* ignore */ }
  App.user = null;
  location.hash = '';
  location.reload();
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------
function renderLayout() {
  const s = App.settings;
  const search = h('input.search-input', { type: 'search', placeholder: t('Search files…'), 'aria-label': t('Search') });
  search.addEventListener('keydown', e => {
    if (e.key === 'Enter' && search.value.trim()) { App.navigate('#/search/' + encodeURIComponent(search.value.trim())); search.blur(); }
    if (e.key === 'Escape') { search.value = ''; search.blur(); }
  });
  const bell = h('button.icon-btn.bell', { 'aria-label': t('Notifications'), onClick: () => Account.notificationsPanel(bell) }, icon('bell'), h('span.bell-count', { hidden: true }));
  const userBtn = h('button.user-btn', { onClick: e => userMenu(e.currentTarget) }, avatar(App.user, 'sm'), h('span.user-name', App.user.name), icon('chevron-down'));
  const uploadBtn = h('button.btn.btn-primary.btn-upload', { onClick: e => Files.uploadMenu(e.currentTarget) }, icon('upload'), h('span', t('Upload')));
  const topbar = h('header.topbar',
    h('button.icon-btn.menu-toggle', { 'aria-label': t('Menu'), onClick: () => layout.el.classList.toggle('sidebar-open') }, icon('menu')),
    h('a.brand', { href: '#/files/' }, s.logo_url ? h('img.brand-logo', { src: s.logo_url, alt: '' }) : h('span.brand-mark', icon('folder')), h('span.brand-name', s.app_name)),
    h('div.search', icon('search'), search, h('button.search-adv', { title: t('Advanced search'), onClick: () => Files.advancedSearch(search.value) }, icon('sliders'))),
    h('div.top-actions', uploadBtn, bell, userBtn));
  const sidebar = h('nav.sidebar');
  const main = h('main.main');
  const details = h('aside.details', { hidden: true });
  const el = h('div.layout', topbar, h('div.body', sidebar, main, details));
  if (App.boot.impersonated_by) el.insertBefore(h('div.impersonation', icon('alert'), t('You are signed in as {user} (impersonation by {admin}).', { user: App.user.username, admin: App.boot.impersonated_by }), h('button.btn.btn-sm', { onClick: signOut }, t('Sign out'))), topbar);
  if (s.announcement) el.insertBefore(h('div.announcement', icon('info'), h('span', s.announcement)), el.querySelector('.body'));
  replace(root, el);
  layout = { el, topbar, sidebar, main, details, search, bell };
  App.layout = layout;
  Uploads.mount(root);
  document.addEventListener('keydown', globalKeys);
  main.addEventListener('click', e => { if (window.innerWidth < 900) layout.el.classList.remove('sidebar-open'); });
}

function userMenu(anchor) {
  const themePref = App.pref('theme', App.settings.default_theme || 'auto');
  const items = [
    { header: App.user.name + (App.user.email ? ' · ' + App.user.email : '') },
    { label: t('Account settings'), icon: 'user', onClick: () => App.navigate('#/account/profile') },
    { label: t('Security'), icon: 'shield', onClick: () => App.navigate('#/account/security') },
    { label: t('Connect apps (WebDAV)'), icon: 'smartphone', onClick: () => App.navigate('#/account/apps') },
    App.isAdmin ? { label: t('Control panel'), icon: 'settings', onClick: () => App.navigate('#/admin') } : null,
    { sep: true },
    { label: t('Theme'), icon: themePref === 'dark' ? 'moon' : themePref === 'light' ? 'sun' : 'monitor', submenu: [
      { label: t('System'), checked: themePref === 'auto', onClick: () => { App.setPref('theme', 'auto'); applyTheme(); } },
      { label: t('Light'), checked: themePref === 'light', onClick: () => { App.setPref('theme', 'light'); applyTheme(); } },
      { label: t('Dark'), checked: themePref === 'dark', onClick: () => { App.setPref('theme', 'dark'); applyTheme(); } },
    ] },
    { label: t('Keyboard shortcuts'), icon: 'help', onClick: shortcutsDialog },
    { sep: true },
    { label: t('Sign out'), icon: 'log-out', onClick: signOut },
  ];
  menu(items, anchor, { align: 'right' });
}

export function shortcutsDialog() {
  const rows = [['↑ ↓', t('Move selection')], ['Enter', t('Open')], ['Backspace', t('Go to parent folder')], ['Space', t('Quick preview')], ['Ctrl+A', t('Select all')], ['Delete', t('Delete')], ['F2', t('Rename')], ['Ctrl+C / Ctrl+X / Ctrl+V', t('Copy / cut / paste')], ['Ctrl+V', t('Paste an image from the clipboard to upload it')], ['Shift+N', t('New folder')], ['U', t('Upload files')], ['/', t('Search')], ['I', t('Toggle details panel')], ['1 / 2 / 3', t('List / grid / gallery view')], ['Esc', t('Clear selection / close')], ['← →', t('Previous / next in the viewer')], ['Ctrl+S', t('Save in the editor')]];
  dialog({ title: t('Keyboard shortcuts'), width: '520px', body: h('table.kbd-table', rows.map(r => h('tr', h('td', kbd(r[0])), h('td', r[1])))), buttons: [{ label: t('Close'), primary: true }] });
}

function globalKeys(e) {
  const tag = (e.target.tagName || '').toLowerCase();
  const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
  if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) { e.preventDefault(); layout.search.focus(); layout.search.select(); return; }
  if (!typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
    if (e.key === 'i' || e.key === 'I') { Files.toggleDetails(); }
  }
}

// ---------------------------------------------------------------------------
// Sidebar
// ---------------------------------------------------------------------------
export async function refreshSidebar() {
  try { App.sidebar = await api.get('files/sidebar'); } catch (e) { App.sidebar = App.sidebar || { home: [], shared: [], tags: [] }; }
  renderSidebar();
}

export function renderSidebar() {
  const sb = App.sidebar || { home: [], shared: [], tags: [] };
  const cur = location.hash;
  const navItem = (hash, ic, label, badge, extra) => h('a.nav-item', { href: hash, class: cur === hash || (hash !== '#/files/' && cur.startsWith(hash)) || (hash === '#/files/' && cur.startsWith('#/files/') && !cur.startsWith('#/files/@')) ? 'active' : '' }, icon(ic), h('span.nav-label', label), badge ? h('span.nav-badge', badge) : null, extra || null);
  const u = App.user;
  const used = sb.usage ? sb.usage.used : u.used_bytes, quota = sb.usage ? sb.usage.quota : u.quota_bytes;
  const pct = quota > 0 ? Math.min(100, used / quota * 100) : 0;
  const tree = h('div.tree');
  Files.renderTree(tree, sb.home);
  const sharedList = h('div.nav-sub');
  for (const s of (sb.shared || []).slice(0, 12)) {
    sharedList.appendChild(h('a.nav-item.nav-sub-item', { href: App.filesHash(s.vpath), class: cur.startsWith('#/files/' + encodeURIComponent(s.vpath)) ? 'active' : '', title: s.owner.name }, icon(s.is_dir ? 'folder' : 'file'), h('span.nav-label', s.name)));
  }
  const tagsList = h('div.nav-sub');
  for (const tg of (sb.tags || []).slice(0, 15)) tagsList.appendChild(h('a.nav-item.nav-sub-item', { href: '#/tag/' + tg.id }, h('span.tag-dot', { style: { background: tg.color || 'var(--muted)' } }), h('span.nav-label', tg.name), h('span.nav-badge', tg.files)));
  replace(layout.sidebar,
    h('div.nav-group',
      navItem('#/files/', 'home', t('My Files')),
      tree,
    ),
    h('div.nav-group',
      navItem('#/shared', 'users', t('Shared with me'), (sb.shared || []).length || null),
      (sb.shared || []).length ? sharedList : null,
      navItem('#/links', 'link', t('My links'), sb.links || null),
      navItem('#/recent', 'clock', t('Recent')),
      navItem('#/starred', 'star', t('Starred'), sb.starred || null),
      navItem('#/photos', 'image', t('Photos')),
      u.perms && u.perms.trash ? navItem('#/trash', 'trash', t('Trash'), sb.trash || null) : null,
    ),
    (sb.tags || []).length ? h('div.nav-group', h('div.nav-title', t('Tags')), tagsList) : null,
    h('div.nav-foot',
      h('div.usage', h('div.usage-text', quota > 0 ? t('{used} of {quota} used', { used: fmtSize(used), quota: fmtSize(quota) }) : t('{used} used', { used: fmtSize(used) })), quota > 0 ? h('div.progress', h('div.progress-bar', { class: pct > 90 ? 'danger' : '', style: { width: pct + '%' } })) : null),
      App.isAdmin ? h('a.nav-item', { href: '#/admin', class: cur.startsWith('#/admin') ? 'active' : '' }, icon('settings'), h('span.nav-label', t('Control panel'))) : null,
    ));
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------
export function route() {
  if (!layout) return;
  let hash = location.hash || '#/files/';
  if (hash === '#' || hash === '#/') hash = '#/files/';
  const path = hash.slice(1);
  const seg = path.split('/');
  Files.closePreviewIfOpen();
  let r;
  if (seg[1] === 'files') r = { name: 'files', path: decodePath(seg.slice(2).join('/')) || '/' };
  else if (seg[1] === 'search') r = { name: 'search', q: decodeURIComponent(seg.slice(2).join('/') || '') };
  else if (seg[1] === 'tag') r = { name: 'tag', id: seg[2] };
  else if (seg[1] === 'label') r = { name: 'label', label: seg[2] };
  else if (seg[1] === 'account') r = { name: 'account', tab: seg[2] || 'profile' };
  else if (seg[1] === 'admin') r = { name: 'admin', section: seg[2] || 'dashboard', id: seg[3] };
  else r = { name: seg[1] || 'files', path: '/' };
  if (r.name === 'files' && !r.path.startsWith('@') && !r.path.startsWith('/')) r.path = '/' + r.path;
  App.route = r;
  const main = layout.main;
  if (!['files', 'recent', 'starred', 'photos', 'search', 'tag', 'label'].includes(r.name)) layout.details.hidden = true;
  switch (r.name) {
    case 'files': Files.browse(main, r.path); break;
    case 'shared': Share.sharedWithMe(main); break;
    case 'links': Share.myLinks(main); break;
    case 'recent': Files.virtual(main, 'recent', t('Recent')); break;
    case 'starred': Files.virtual(main, 'starred', t('Starred')); break;
    case 'photos': Files.virtual(main, 'photos', t('Photos')); break;
    case 'trash': Files.trash(main); break;
    case 'search': Files.virtual(main, 'search', t('Search: {q}', { q: r.q }), { q: r.q }); break;
    case 'tag': Files.virtual(main, 'tag', t('Tag'), { id: r.id }); break;
    case 'label': Files.virtual(main, 'label', t('Label'), { label: r.label }); break;
    case 'account': Account.render(main, r.tab); break;
    case 'admin': Admin.render(main, r.section, r.id); break;
    default: Files.browse(main, '/');
  }
  renderSidebar();
  if (window.innerWidth < 900) layout.el.classList.remove('sidebar-open');
}

// ---------------------------------------------------------------------------
// Notifications badge polling
// ---------------------------------------------------------------------------
function startNotificationPolling() {
  const update = (n) => { const b = layout.bell.querySelector('.bell-count'); b.textContent = n > 99 ? '99+' : n; b.hidden = !n; };
  update(App.sidebar ? App.sidebar.notifications : 0);
  App.on('notifications', e => update(e.detail));
  setInterval(async () => {
    if (document.hidden) return;
    try { const r = await api.get('account/notifications', { unread: 1, limit: 1 }); update(r.unread); } catch (e) { /* ignore */ }
  }, 60000);
}

boot().catch(e => { console.error(e); replace(root, h('div.auth', h('div.auth-card', h('h1.auth-title', 'Error'), h('p', e.message)))); });
