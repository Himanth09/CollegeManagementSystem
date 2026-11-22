/* Core helpers shared by every page: React/htm binding, API client, auth,
 * hash router, formatting, toasts, confirm dialogs and data hooks. */

const React = window.React;
export const { useState, useEffect, useMemo, useCallback, useRef, useContext, createContext, Fragment } = React;
export const html = window.htm.bind(React.createElement);
export { React };

/* ------------------------------------------------------------------ events */
const listeners = {};
export function on(evt, fn) {
  (listeners[evt] ||= new Set()).add(fn);
  return () => listeners[evt].delete(fn);
}
export function emit(evt, payload) {
  (listeners[evt] || []).forEach((fn) => fn(payload));
}

/* ------------------------------------------------------------------ API */
const API = 'api/index.php';
let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
export const setCsrf = (t) => { if (t) csrfToken = t; };

export function apiUrl(path, params = {}) {
  const q = new URLSearchParams({ r: path });
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined && v !== false) q.set(k, v);
  });
  return `${API}?${q.toString()}`;
}

export class ApiError extends Error {
  constructor(message, status, errors, data) {
    super(message);
    this.status = status;
    this.errors = errors || {};
    this.data = data || {};
  }
}

export async function api(path, { method = 'GET', params, body, form } = {}) {
  const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
  if (method !== 'GET') {
    opts.headers['X-CSRF-Token'] = csrfToken;
    if (form) {
      opts.body = form;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body || {});
    }
  }
  let res;
  try {
    res = await fetch(apiUrl(path, params), opts);
  } catch {
    throw new ApiError('Network error. Check your connection and try again.', 0);
  }
  let data;
  try {
    data = await res.json();
  } catch {
    data = { ok: false, message: `Unexpected server response (HTTP ${res.status}).` };
  }
  if (data && data.csrf) setCsrf(data.csrf);
  if (!res.ok || data.ok === false) {
    if (res.status === 401 && path !== '/auth/login') emit('unauthorized', data.message);
    throw new ApiError(data.message || 'Request failed.', res.status, data.errors, data);
  }
  return data;
}

export const post = (path, body) => api(path, { method: 'POST', body });

/** Opens a GET download (CSV, backup) in the browser using the session cookie. */
export function download(path, params) {
  window.location.href = apiUrl(path, params);
}
export function openPrint(path) {
  window.open(apiUrl(path), '_blank', 'noopener');
}

/* ------------------------------------------------------------------ auth */
export const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

/* ------------------------------------------------------------------ router (hash based: works on any host) */
function parseHash() {
  const raw = window.location.hash.replace(/^#/, '') || '/';
  const [path, qs] = raw.split('?');
  return { path: path || '/', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}
export function useRoute() {
  const [route, setRoute] = useState(parseHash());
  useEffect(() => {
    const h = () => { setRoute(parseHash()); window.scrollTo(0, 0); };
    window.addEventListener('hashchange', h);
    return () => window.removeEventListener('hashchange', h);
  }, []);
  return route;
}
export function navigate(to) {
  window.location.hash = to;
}
export function matchRoute(pattern, path) {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '$');
  const m = path.match(re);
  if (!m) return null;
  return Object.fromEntries(keys.map((k, i) => [k, decodeURIComponent(m[i + 1])]));
}

/* ------------------------------------------------------------------ formatting */
const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 });
const inr0 = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
export const money = (v) => inr.format(Number(v || 0));
export const moneyShort = (v) => {
  const n = Number(v || 0);
  if (Math.abs(n) >= 1e7) return '₹' + (n / 1e7).toFixed(2) + ' Cr';
  if (Math.abs(n) >= 1e5) return '₹' + (n / 1e5).toFixed(2) + ' L';
  return inr0.format(n);
};
export const num = (v) => new Intl.NumberFormat('en-IN').format(Number(v || 0));
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function fmtDate(v) {
  if (!v) return '-';
  const [y, m, d] = String(v).slice(0, 10).split('-');
  if (!d) return v;
  return `${d} ${MONTHS[Number(m) - 1]} ${y}`;
}
export function fmtDateTime(v) {
  if (!v) return '-';
  return `${fmtDate(v)}, ${String(v).slice(11, 16)}`;
}
export function fmtMonth(ym) {
  if (!ym) return '-';
  const [y, m] = ym.split('-');
  return `${MONTHS[Number(m) - 1]} ${y}`;
}
export const today = () => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
export const thisMonth = () => today().slice(0, 7);
export const initials = (name = '') => name.replace(/^(Dr|Mr|Ms|Mrs|Prof)\.?\s+/i, '').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();

/* ------------------------------------------------------------------ toasts & confirm */
export function toast(message, type = 'success') {
  emit('toast', { id: Math.random().toString(36).slice(2), message, type });
}
export const toastError = (e) => toast(e?.message || String(e), 'error');

/** Promise-based confirm dialog: `if (await confirmDialog({...})) ...` */
export function confirmDialog(opts) {
  return new Promise((resolve) => emit('confirm', { ...opts, resolve }));
}

/* ------------------------------------------------------------------ hooks */
/** Load data from a GET endpoint. */
export function useApi(path, params = null, deps = []) {
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const [tick, setTick] = useState(0);
  const key = JSON.stringify(params);
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return; }
    let alive = true;
    setState((s) => ({ ...s, loading: true, error: null }));
    api(path, { params: params || {} })
      .then((d) => alive && setState({ data: d, loading: false, error: null }))
      .catch((e) => alive && setState({ data: null, loading: false, error: e }));
    return () => { alive = false; };
    // eslint-disable-next-line
  }, [path, key, tick, ...deps]);
  return { ...state, reload: () => setTick((t) => t + 1) };
}

/** Server-side paginated / sorted / filtered list. */
export function useList(path, initialFilters = {}, initialSort = {}) {
  const [filters, setFilters] = useState(initialFilters);
  const [search, setSearch] = useState(initialFilters.q || '');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState(initialSort);
  useEffect(() => {
    if ((filters.q || '') === search) return undefined;
    const t = setTimeout(() => { setFilters((f) => ({ ...f, q: search })); setPage(1); }, 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line
  }, [search]);
  const params = { ...filters, page, sort: sort.sort, dir: sort.dir };
  const res = useApi(path, params);
  return {
    ...res,
    rows: res.data?.data || [],
    meta: res.data?.meta,
    filters,
    params,
    search,
    setSearch,
    setFilter: (k, v) => { setFilters((f) => ({ ...f, [k]: v })); setPage(1); },
    resetFilters: () => { setFilters(initialFilters); setSearch(''); setPage(1); },
    page,
    setPage,
    sort,
    toggleSort: (key) => setSort((s) => ({ sort: key, dir: s.sort === key && s.dir === 'asc' ? 'desc' : 'asc' })),
  };
}

/** Simple form state with server-side error mapping. */
export function useForm(initial) {
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (name) => (e) => {
    const v = e && e.target ? (e.target.type === 'checkbox' ? (e.target.checked ? '1' : '0') : e.target.value) : e;
    setValues((s) => ({ ...s, [name]: v }));
    setErrors((er) => (er[name] ? { ...er, [name]: undefined } : er));
  };
  /** Wraps a submit action: handles busy state, validation errors and toasts. */
  const submit = async (fn) => {
    setBusy(true);
    setErrors({});
    try {
      return await fn(values);
    } catch (e) {
      if (e.status === 422 && e.errors) setErrors(e.errors);
      throw e;
    } finally {
      setBusy(false);
    }
  };
  return { values, setValues, set, errors, setErrors, busy, submit };
}

/** Cached lookup lists (departments, courses, academic years). */
let metaCache = null;
export function useMeta() {
  const [meta, setMeta] = useState(metaCache);
  useEffect(() => {
    if (metaCache) return;
    api('/meta').then((d) => { metaCache = d; setMeta(d); }).catch(() => {});
  }, []);
  return meta || { departments: [], courses: [], academic_years: [], designations: [] };
}
export const clearMetaCache = () => { metaCache = null; };

/** Builds a FormData from an object (for multipart uploads). */
export function toFormData(values, file, fileField = 'photo') {
  const fd = new FormData();
  Object.entries(values).forEach(([k, v]) => fd.append(k, v === null || v === undefined ? '' : v));
  if (file) fd.append(fileField, file);
  return fd;
}
