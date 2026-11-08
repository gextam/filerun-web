/* Thin client for the JSON API. */
const BOOT = window.FR_BOOT || {};
const BASE = BOOT.base || '';
export const state = { link: BOOT.link || null };

export class ApiError extends Error {
  constructor(message, code, status, extra) { super(message); this.code = code; this.status = status; this.extra = extra || {}; }
}

function url(path, params) {
  const u = new URL(BASE + '/api/' + path.replace(/^\//, ''), window.location.origin);
  if (state.link) u.searchParams.set('link', state.link);
  if (params) for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null) continue;
    u.searchParams.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
  }
  return u.toString();
}

async function handle(res) {
  let data = null;
  const ct = res.headers.get('content-type') || '';
  if (ct.includes('application/json')) {
    try { data = await res.json(); } catch (e) { data = null; }
  }
  if (!data) {
    if (res.ok) return null;
    throw new ApiError(res.status === 413 ? 'Request too large for the server' : ('HTTP ' + res.status), 'http', res.status);
  }
  if (data.ok === false) {
    const err = new ApiError(data.error || 'Error', data.code || 'error', res.status, data);
    if ((res.status === 401 || data.code === 'unauthenticated') && !state.link) document.dispatchEvent(new CustomEvent('fr:unauthenticated'));
    throw err;
  }
  return data.data;
}

export const api = {
  url,
  get(path, params) {
    return fetch(url(path, params), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(handle);
  },
  post(path, body = {}, params) {
    return fetch(url(path, params), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    }).then(handle);
  },
  /** multipart form upload */
  form(path, formData, params) {
    return fetch(url(path, params), { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }).then(handle);
  },
  /** raw body (chunk upload) with progress */
  raw(path, blob, params, onProgress, signal) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url(path, params), true);
      xhr.setRequestHeader('Content-Type', 'application/octet-stream');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.withCredentials = true;
      if (onProgress) xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded, e.total); };
      xhr.onload = () => {
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
        if (!data) return reject(new ApiError(xhr.status === 413 ? 'Chunk too large for the server' : 'HTTP ' + xhr.status, 'http', xhr.status));
        if (data.ok === false) return reject(new ApiError(data.error, data.code, xhr.status, data));
        resolve(data.data);
      };
      xhr.onerror = () => reject(new ApiError('Network error', 'network', 0));
      xhr.onabort = () => reject(new ApiError('Cancelled', 'abort', 0));
      if (signal) signal.addEventListener('abort', () => xhr.abort());
      xhr.send(blob);
    });
  },
  /** URL for downloads / previews (GET with cookies) */
  fileUrl(action, params) { return url('files/' + action, params); },
  download(paths) {
    const a = document.createElement('a');
    a.href = paths.length === 1 ? url('files/download', { p: paths[0] }) : url('files/download', { paths });
    a.download = '';
    document.body.appendChild(a); a.click(); a.remove();
  },
};
