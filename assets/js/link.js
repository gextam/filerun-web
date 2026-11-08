/* Public link mode: password / terms gate, then the normal browser with the link's permissions. */
import { App, decodePath } from './store.js';
import { api } from './api.js';
import { t } from './i18n.js';
import { h, icon, fileIcon, replace, field, toast, fmtSize, fmtDate, spinner, avatar } from './ui.js';
import * as Files from './files.js';
import * as Uploads from './upload.js';
import { openPreview } from './preview.js';

let root, main;

export async function start(rootEl) {
  root = rootEl;
  let info;
  try { info = await api.get('link/info'); } catch (e) { return gateShell(h('div.auth-card', h('h1.auth-title', t('Link unavailable')), h('p.auth-text', e.message))); }
  App.linkInfo = info;
  App.title(info.title);
  if (info.needs_password || info.needs_terms) return gate(info);
  enter(info);
}

function gateShell(...content) {
  const s = App.settings;
  replace(root, h('div.auth', h('div.auth-brand', s.logo_url ? h('img.auth-logo', { src: s.logo_url, alt: s.app_name }) : h('div.auth-logo-mark', icon('folder')), h('div.auth-name', s.app_name)), ...content));
}

function gate(info) {
  const pw = info.needs_password ? field({ label: t('Password'), name: 'password', type: 'password', attrs: { autofocus: true } }) : null;
  const terms = info.needs_terms ? h('div.terms', h('div.terms-text', info.terms), field({ type: 'checkbox', name: 'accept', label: t('I accept the terms'), required: true })) : null;
  const err = h('div.form-error', { hidden: true });
  const form = h('form.auth-form', { onSubmit: async e => {
    e.preventDefault(); err.hidden = true;
    if (terms && !terms.querySelector('input').checked) { err.textContent = t('Please accept the terms to continue'); err.hidden = false; return; }
    try { const res = await api.post('link/unlock', { password: pw ? pw.input.value : null, accept_terms: !!terms }); App.linkInfo = res; enter(res); } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  } }, pw, terms, err, h('button.btn.btn-primary.btn-block', { type: 'submit' }, t('Continue')));
  gateShell(h('div.auth-card', h('div.link-head', fileIcon({ is_dir: info.is_dir, kind: info.kind, ext: info.ext }, 'lg'), h('div', h('h1.auth-title', info.title), h('div.hint', t('shared by {name}', { name: info.owner_name })))), info.message ? h('p.auth-text', info.message) : null, info.needs_password ? h('p.auth-text', t('This link is protected by a password.')) : null, form));
}

function enter(info) {
  const s = App.settings;
  const topbar = h('header.topbar.link-topbar',
    h('a.brand', { href: '#/' }, s.logo_url ? h('img.brand-logo', { src: s.logo_url, alt: '' }) : h('span.brand-mark', icon('folder')), h('span.brand-name', s.app_name)),
    h('div.link-title', h('span.link-title-text', info.title), h('span.hint', t('shared by {name}', { name: info.owner_name }))),
    h('div.top-actions',
      info.is_dir && info.perms.download && !info.allow_preview_only ? h('button.btn', { onClick: () => api.download(['/']) }, icon('download'), h('span', t('Download all'))) : null,
      info.is_dir && info.perms.create ? h('button.btn.btn-primary', { onClick: e => Files.uploadMenu(e.currentTarget) }, icon('upload'), h('span', t('Upload'))) : null,
      info.user ? h('a.btn.btn-ghost', { href: App.base + '/', title: info.user.name }, avatar(info.user, 'sm')) : h('a.btn.btn-ghost', { href: App.base + '/' }, t('Sign in'))));
  main = h('main.main');
  const details = h('aside.details', { hidden: true });
  const layout = h('div.layout.link-layout', topbar, h('div.body', main, details));
  replace(root, layout);
  App.layout = { el: layout, main, details };
  Uploads.mount(root);
  if (!info.is_dir) return singleFile(info);
  if (info.role === 'uploader') return fileRequest(info);
  window.addEventListener('hashchange', routeLink);
  routeLink();
}

function routeLink() {
  const hash = location.hash || '#/';
  const path = '/' + decodePath(hash.replace(/^#\/?/, ''));
  Files.closePreviewIfOpen();
  Files.browse(main, path);
}

function singleFile(info) {
  const item = { name: info.name, path: '/', is_dir: false, size: info.size, mtime: info.mtime, kind: info.kind, ext: info.ext, mime: info.mime, thumb: ['image', 'video', 'pdf', 'doc', 'sheet', 'slide', 'font', 'audio'].includes(info.kind), perms: { ...info.perms, download: info.perms.download && !info.allow_preview_only } };
  const card = h('div.single-file',
    h('div.sf-preview', item.thumb ? h('img', { src: api.fileUrl('thumb', { p: '/', size: 'large' }), alt: '', onError: e => e.target.replaceWith(fileIcon(item, 'xl')) }) : fileIcon(item, 'xl')),
    h('div.sf-name', info.name), h('div.sf-sub', fmtSize(info.size) + ' · ' + fmtDate(info.mtime)),
    info.message ? h('p.sf-message', info.message) : null,
    info.downloads_left !== null && info.downloads_left !== undefined ? h('p.hint', t('{n} downloads left', { n: info.downloads_left })) : null,
    h('div.sf-actions', item.perms.download ? h('button.btn.btn-primary', { onClick: () => api.download(['/']) }, icon('download'), t('Download')) : null, h('button.btn', { onClick: () => openPreview([item], 0, { folderPerms: item.perms }) }, icon('eye'), t('Preview'))));
  replace(main, h('div.view', h('div.content.page.center', card)));
  if (['image', 'pdf', 'video', 'audio', 'text', 'code', 'markdown'].includes(info.kind)) setTimeout(() => openPreview([item], 0, { folderPerms: item.perms }), 50);
}

function fileRequest(info) {
  const drop = h('div.file-request', icon('cloud-upload', 'fr-ic'), h('h2', t('{name} is requesting files', { name: info.owner_name })), info.message ? h('p', info.message) : h('p', t('Drop files here or choose them from your device. You will not see files that others have uploaded.')),
    h('div.row.gap.center', h('button.btn.btn-primary', { onClick: () => Uploads.pickFiles('/', false) }, icon('upload'), t('Choose files')), h('button.btn', { onClick: () => Uploads.pickFiles('/', true) }, icon('folder-input'), t('Choose a folder'))));
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drop-active'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('drop-active'));
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('drop-active'); Uploads.handleDrop(e.dataTransfer, '/'); });
  replace(main, h('div.view', h('div.content.page.center', drop)));
}
