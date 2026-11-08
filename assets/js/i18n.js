/* Translations. English sentences are the keys; language files map them to other
 * languages and administrators can override any phrase ("custom wording"). */
const BOOT = window.FR_BOOT || {};
let dict = {};
let custom = (BOOT.settings && BOOT.settings.custom_strings) || {};
let current = 'en';

export const LANGUAGES = {
  en: 'English', es: 'Español', de: 'Deutsch', fr: 'Français', pt: 'Português', it: 'Italiano', nl: 'Nederlands', pl: 'Polski', ru: 'Русский', tr: 'Türkçe', zh: '中文', ja: '日本語',
};

export function t(key, params) {
  let s = custom[key] !== undefined && custom[key] !== '' ? custom[key] : (dict[key] !== undefined ? dict[key] : key);
  if (params) for (const [k, v] of Object.entries(params)) s = s.split('{' + k + '}').join(String(v));
  return s;
}

export function lang() { return current; }

export async function setLanguage(code) {
  code = (code || 'en').toLowerCase().split(/[-_]/)[0];
  current = code;
  document.documentElement.lang = code;
  if (code === 'en') { dict = {}; return; }
  try {
    const res = await fetch((BOOT.asset_base || '') + '/assets/lang/' + code + '.json?v=' + (BOOT.version || '1'), { cache: 'force-cache' });
    dict = res.ok ? await res.json() : {};
  } catch (e) { dict = {}; }
}

export function setCustomStrings(map) { custom = map || {}; }
