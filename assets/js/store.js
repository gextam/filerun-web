/* Shared application state and event bus. */
const BOOT = window.FR_BOOT || { settings: {}, features: {} };

const bus = new EventTarget();
const LS_KEY = 'fr.prefs';
let localPrefs = {};
try { localPrefs = JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch (e) { localPrefs = {}; }

export const App = {
  boot: BOOT,
  user: BOOT.user || null,
  settings: BOOT.settings || {},
  features: BOOT.features || {},
  upload: BOOT.upload || {},
  link: BOOT.link || null,        // public link hash when in link mode
  linkInfo: null,
  base: BOOT.base || '',
  assetBase: BOOT.asset_base || '',
  route: { name: 'files', path: '/', params: {} },
  sidebar: null,                  // cached sidebar payload
  clipboard: null,                // {op:'copy'|'cut', paths:[]}

  get isLinkMode() { return !!this.link; },
  get isAdmin() { return !!(this.user && this.user.is_admin); },
  get isSuper() { return !!(this.user && this.user.is_super); },

  on(name, fn) { bus.addEventListener(name, fn); return () => bus.removeEventListener(name, fn); },
  emit(name, detail) { bus.dispatchEvent(new CustomEvent(name, { detail })); },

  /** User preferences: server-side when signed in, local storage otherwise. */
  pref(key, def) {
    const p = (this.user && this.user.prefs) || {};
    if (p[key] !== undefined) return p[key];
    if (localPrefs[key] !== undefined) return localPrefs[key];
    return def;
  },
  setPref(key, value, persist = true) {
    localPrefs[key] = value;
    try { localStorage.setItem(LS_KEY, JSON.stringify(localPrefs)); } catch (e) { /* ignore */ }
    if (this.user) {
      this.user.prefs = this.user.prefs || {};
      this.user.prefs[key] = value;
      if (persist) {
        clearTimeout(this._prefTimer);
        this._prefTimer = setTimeout(() => {
          import('./api.js').then(({ api }) => api.post('account/profile', { prefs: this.user.prefs }).catch(() => {}));
        }, 800);
      }
    }
    this.emit('pref', { key, value });
  },

  navigate(hash, replaceState = false) {
    if (!hash.startsWith('#')) hash = '#' + hash;
    if (replaceState) history.replaceState(null, '', hash); else location.hash = hash;
    if (replaceState) window.dispatchEvent(new HashChangeEvent('hashchange'));
  },
  /** Hash for a browse route of a virtual path. */
  filesHash(vpath) {
    if (this.isLinkMode) return '#/' + encodePath(vpath.replace(/^\//, ''));
    if (!vpath || vpath === '/') return '#/files/';
    return '#/files/' + encodePath(vpath.replace(/^\//, ''));
  },
  openFolder(vpath) { this.navigate(this.filesHash(vpath)); },
  title(s) { document.title = (s ? s + ' – ' : '') + (this.settings.app_name || 'Files'); },
};

export function encodePath(p) {
  return p.split('/').map(s => encodeURIComponent(s)).join('/');
}
export function decodePath(p) {
  return p.split('/').map(s => { try { return decodeURIComponent(s); } catch (e) { return s; } }).join('/');
}

export function parentPath(vpath) {
  if (!vpath || vpath === '/') return null;
  if (vpath.startsWith('@')) {
    const i = vpath.lastIndexOf('/');
    return i > 0 ? vpath.slice(0, i) : null;
  }
  const i = vpath.lastIndexOf('/');
  return i <= 0 ? '/' : vpath.slice(0, i);
}
export function joinPath(dir, name) {
  if (!dir || dir === '/') return '/' + name;
  return dir.replace(/\/$/, '') + '/' + name;
}
export function baseName(vpath) {
  const i = vpath.lastIndexOf('/');
  return i < 0 ? vpath : vpath.slice(i + 1);
}
