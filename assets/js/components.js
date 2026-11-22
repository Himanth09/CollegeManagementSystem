/* Reusable UI components. */
import { html, useState, useEffect, useRef, on, money, num, fmtDate, initials, apiUrl } from './lib.js';

/* ------------------------------------------------------------------ icons (24x24 stroke) */
const ICONS = {
  dashboard: 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
  students: 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5',
  fees: 'M3 6h18v12H3zM3 10h18M7 15h3',
  staff: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  salary: 'M6 3h12M6 8h12M6 13l8.5 8M6 13h3a5 5 0 0 0 0-10',
  reports: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
  log: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8M8 9h2',
  database: 'M12 8c4.4 0 8-1.3 8-3s-3.6-3-8-3-8 1.3-8 3 3.6 3 8 3zM4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
  menu: 'M3 6h18M3 12h18M3 18h18',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
  plus: 'M12 5v14M5 12h14',
  edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  trash: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6',
  eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  print: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
  download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
  x: 'M18 6 6 18M6 6l12 12',
  check: 'M20 6 9 17l-5-5',
  alert: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  calendar: 'M3 4h18v18H3zM16 2v4M8 2v4M3 10h18',
  chevronLeft: 'M15 18l-6-6 6-6',
  chevronRight: 'M9 18l6-6-6-6',
  chevronDown: 'M6 9l6 6 6-6',
  key: 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4',
  rupee: 'M6 3h12M6 8h12M6 13l8.5 8M6 13h3a5 5 0 0 0 0-10',
  trend: 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
  building: 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1M14 9h1M9 13h1M14 13h1M9 17h1M14 17h1',
  refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15',
  upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
  sort: 'M7 15l5 5 5-5M7 9l5-5 5 5',
  layers: 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
};
export function Icon({ name, size = 18, className = '' }) {
  return html`<svg class=${'icon ' + className} width=${size} height=${size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d=${ICONS[name] || ICONS.alert} /></svg>`;
}

/* ------------------------------------------------------------------ basics */
export function Spinner({ label = 'Loading…' }) {
  return html`<div class="spinner-wrap"><span class="spinner"></span><span>${label}</span></div>`;
}
export function Empty({ title = 'Nothing here yet', text = '', icon = 'layers', children }) {
  return html`<div class="empty"><${Icon} name=${icon} size=${36} /><h4>${title}</h4>${text && html`<p>${text}</p>`}${children}</div>`;
}
export function ErrorBox({ error, onRetry }) {
  if (!error) return null;
  return html`<div class="alert alert-error"><${Icon} name="alert" /><div><strong>${error.status === 403 ? 'Access denied' : 'Something went wrong'}</strong>
    <div>${error.message}</div></div>${onRetry && html`<button class="btn btn-sm" onClick=${onRetry}>Retry</button>`}</div>`;
}
export function Alert({ type = 'info', children }) {
  return html`<div class=${'alert alert-' + type}><${Icon} name=${type === 'error' || type === 'warning' ? 'alert' : 'check'} /><div>${children}</div></div>`;
}

const BADGE = {
  Active: 'green', Inactive: 'grey', Graduated: 'blue', 'On Leave': 'amber', Resigned: 'grey', Retired: 'grey', Terminated: 'red',
  confirmed: 'green', pending_review: 'amber', rejected: 'red', cancelled: 'grey', paid: 'green', pending: 'amber', not_generated: 'grey',
  overdue: 'red', due: 'amber', cleared: 'green',
};
const LABEL = { pending_review: 'Pending review', not_generated: 'Not generated' };
export function Badge({ value, tone }) {
  if (value === null || value === undefined || value === '') return '-';
  return html`<span class=${'badge badge-' + (tone || BADGE[value] || 'blue')}>${LABEL[value] || (typeof value === 'string' ? value.charAt(0).toUpperCase() + value.slice(1) : value)}</span>`;
}

export function Avatar({ name, src, size = 40 }) {
  const [failed, setFailed] = useState(false);
  if (src && !failed) {
    return html`<img class="avatar" src=${src} alt="" width=${size} height=${size} style=${{ width: size + 'px', height: size + 'px' }} onError=${() => setFailed(true)} />`;
  }
  return html`<span class="avatar avatar-text" style=${{ width: size + 'px', height: size + 'px', fontSize: size * 0.38 + 'px' }}>${initials(name)}</span>`;
}

export function PageHeader({ title, subtitle, children, back }) {
  return html`<div class="page-header">
    <div>
      ${back && html`<a class="back-link" href=${back}><${Icon} name="chevronLeft" size=${16} /> Back</a>`}
      <h1>${title}</h1>${subtitle && html`<p>${subtitle}</p>`}
    </div>
    <div class="page-actions">${children}</div>
  </div>`;
}

export function Card({ title, actions, children, className = '', pad = true }) {
  return html`<section class=${'card ' + className}>
    ${(title || actions) && html`<div class="card-head"><h3>${title}</h3><div class="card-actions">${actions}</div></div>`}
    <div class=${pad ? 'card-body' : ''}>${children}</div>
  </section>`;
}

export function StatCard({ label, value, icon, tone = 'blue', hint, href }) {
  const body = html`<div class="stat-icon"><${Icon} name=${icon} size=${22} /></div>
    <div class="stat-text"><span class="stat-label">${label}</span><strong class="stat-value">${value}</strong>${hint && html`<span class="stat-hint">${hint}</span>`}</div>`;
  return href
    ? html`<a class=${'stat-card tone-' + tone} href=${href}>${body}</a>`
    : html`<div class=${'stat-card tone-' + tone}>${body}</div>`;
}

export function Button({ children, icon, variant = 'default', size, busy, type = 'button', className = '', ...rest }) {
  return html`<button ...${rest} type=${type} class=${`btn btn-${variant}${size ? ' btn-' + size : ''} ${className}`} disabled=${busy || rest.disabled}>
    ${busy ? html`<span class="spinner spinner-sm"></span>` : icon && html`<${Icon} name=${icon} size=${16} />`}${children && html`<span>${children}</span>`}
  </button>`;
}

export function IconButton({ icon, title, onClick, href, tone = '' , target}) {
  if (href) return html`<a class=${'icon-btn ' + tone} href=${href} title=${title} aria-label=${title} target=${target}><${Icon} name=${icon} size=${16} /></a>`;
  return html`<button type="button" class=${'icon-btn ' + tone} title=${title} aria-label=${title} onClick=${onClick}><${Icon} name=${icon} size=${16} /></button>`;
}

/* ------------------------------------------------------------------ forms */
export function Field({ label, error, required, hint, children, className = '' }) {
  return html`<label class=${'field ' + className + (error ? ' has-error' : '')}>
    ${label && html`<span class="field-label">${label}${required && html`<em class="req">*</em>`}</span>`}
    ${children}
    ${error ? html`<span class="field-error">${error}</span>` : hint && html`<span class="field-hint">${hint}</span>`}
  </label>`;
}

/** Text-like input bound to a useForm instance. */
export function Input({ form, name, label, required, hint, type = 'text', className, ...rest }) {
  return html`<${Field} label=${label} error=${form.errors[name]} required=${required} hint=${hint} className=${className}>
    <input class="input" type=${type} name=${name} value=${form.values[name] ?? ''} onInput=${form.set(name)} required=${required} ...${rest} />
  <//>`;
}
export function Select({ form, name, label, required, hint, options, placeholder = 'Select…', className, ...rest }) {
  return html`<${Field} label=${label} error=${form.errors[name]} required=${required} hint=${hint} className=${className}>
    <select class="input" name=${name} value=${form.values[name] ?? ''} onChange=${form.set(name)} required=${required} ...${rest}>
      ${placeholder !== false && html`<option value="">${placeholder}</option>`}
      ${options.map((o) => html`<option key=${o.value} value=${o.value}>${o.label}</option>`)}
    </select>
  <//>`;
}
export function Textarea({ form, name, label, required, hint, className, rows = 3, ...rest }) {
  return html`<${Field} label=${label} error=${form.errors[name]} required=${required} hint=${hint} className=${className}>
    <textarea class="input" name=${name} rows=${rows} value=${form.values[name] ?? ''} onInput=${form.set(name)} ...${rest}></textarea>
  <//>`;
}
export function MoneyInput({ form, name, label, required, hint, className, ...rest }) {
  return html`<${Field} label=${label} error=${form.errors[name]} required=${required} hint=${hint} className=${className}>
    <div class="input-prefix"><span>₹</span><input class="input" type="number" min="0" step="0.01" inputmode="decimal" name=${name}
      value=${form.values[name] ?? ''} onInput=${form.set(name)} required=${required} ...${rest} /></div>
  <//>`;
}

/** Filter dropdown not bound to a form. */
export function FilterSelect({ value, onChange, options, placeholder = 'All', label }) {
  return html`<select class="input input-sm" aria-label=${label || placeholder} value=${value ?? ''} onChange=${(e) => onChange(e.target.value)}>
    <option value="">${placeholder}</option>
    ${options.map((o) => html`<option key=${o.value} value=${o.value}>${o.label}</option>`)}
  </select>`;
}
export function SearchBox({ value, onChange, placeholder = 'Search…' }) {
  return html`<div class="search-box"><${Icon} name="search" size=${16} />
    <input class="input input-sm" type="search" placeholder=${placeholder} aria-label=${placeholder} value=${value} onInput=${(e) => onChange(e.target.value)} /></div>`;
}

export function PhotoInput({ current, onFile, onRemove, name }) {
  const [preview, setPreview] = useState(null);
  const ref = useRef();
  const pick = (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { alert('Please choose a JPG, PNG or WEBP image.'); e.target.value = ''; return; }
    if (f.size > 2 * 1024 * 1024) { alert('Photo must be smaller than 2 MB.'); e.target.value = ''; return; }
    setPreview(URL.createObjectURL(f));
    onFile(f);
  };
  return html`<div class="photo-input">
    <${Avatar} name=${name || '?'} src=${preview || current} size=${88} />
    <div>
      <input ref=${ref} type="file" accept="image/jpeg,image/png,image/webp" onChange=${pick} hidden />
      <${Button} type="button" icon="upload" size="sm" onClick=${() => ref.current.click()}>${current || preview ? 'Change photo' : 'Upload photo'}<//>
      ${(current || preview) && onRemove && html`<${Button} type="button" size="sm" variant="ghost" onClick=${() => { setPreview(null); ref.current.value = ''; onFile(null); onRemove(); }}>Remove<//>`}
      <p class="field-hint">JPG, PNG or WEBP, max 2 MB.</p>
    </div>
  </div>`;
}

/* ------------------------------------------------------------------ modal */
export function Modal({ title, onClose, children, footer, size = 'md' }) {
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose && onClose();
    document.addEventListener('keydown', h);
    document.body.classList.add('modal-open');
    return () => { document.removeEventListener('keydown', h); document.body.classList.remove('modal-open'); };
  }, []);
  return html`<div class="modal-backdrop" onMouseDown=${(e) => e.target === e.currentTarget && onClose && onClose()}>
    <div class=${'modal modal-' + size} role="dialog" aria-modal="true" aria-label=${title}>
      <div class="modal-head"><h3>${title}</h3><button class="icon-btn" aria-label="Close" onClick=${onClose}><${Icon} name="x" /></button></div>
      <div class="modal-body">${children}</div>
      ${footer && html`<div class="modal-foot">${footer}</div>`}
    </div>
  </div>`;
}

/** Global host for confirmDialog(). */
export function ConfirmHost() {
  const [req, setReq] = useState(null);
  const [text, setText] = useState('');
  useEffect(() => on('confirm', (r) => { setText(''); setReq(r); }), []);
  if (!req) return null;
  const done = (v) => { req.resolve(v === true && req.input ? text : v); setReq(null); };
  const blocked = req.input && req.input.required && text.trim().length < (req.input.minLength || 1);
  return html`<${Modal} title=${req.title || 'Please confirm'} onClose=${() => done(false)} size="sm"
    footer=${html`<${Button} onClick=${() => done(false)}>${req.cancelText || 'Cancel'}<//>
      <${Button} variant=${req.danger ? 'danger' : 'primary'} disabled=${blocked} onClick=${() => done(true)}>${req.confirmText || 'Confirm'}<//>`}>
    <p class="confirm-text">${req.message}</p>
    ${req.input && html`<label class="field"><span class="field-label">${req.input.label}</span>
      <textarea class="input" rows="2" value=${text} onInput=${(e) => setText(e.target.value)} autofocus></textarea></label>`}
  <//>`;
}

export function Toasts() {
  const [items, setItems] = useState([]);
  useEffect(() => on('toast', (t) => {
    setItems((s) => [...s, t]);
    setTimeout(() => setItems((s) => s.filter((x) => x.id !== t.id)), t.type === 'error' ? 6000 : 3500);
  }), []);
  return html`<div class="toasts" role="status" aria-live="polite">
    ${items.map((t) => html`<div key=${t.id} class=${'toast toast-' + t.type}>
      <${Icon} name=${t.type === 'error' ? 'alert' : 'check'} /><span>${t.message}</span>
      <button class="icon-btn" aria-label="Dismiss" onClick=${() => setItems((s) => s.filter((x) => x.id !== t.id))}><${Icon} name="x" size=${14} /></button>
    </div>`)}
  </div>`;
}

/* ------------------------------------------------------------------ data table */
export function renderCell(type, v) {
  switch (type) {
    case 'money': return money(v);
    case 'date': return fmtDate(v);
    case 'int': return v === null || v === undefined ? '-' : num(v);
    case 'percent': return `${Number(v || 0).toFixed(1)}%`;
    default: return v === null || v === undefined || v === '' ? '-' : v;
  }
}

/**
 * columns: [{ key, label, sort?: 'serverKey', render?: (row) => node, type?, className?, align? }]
 */
export function DataTable({ columns, rows, sort, onSort, loading, empty, rowKey = 'id', onRowClick, footer }) {
  return html`<div class="table-wrap">
    <table class="table">
      <thead><tr>${columns.map((c) => {
        const active = sort && c.sort && sort.sort === c.sort;
        return html`<th key=${c.key} class=${(c.align === 'right' || c.type === 'money' ? 'r ' : '') + (c.sort ? 'sortable ' : '') + (active ? 'sorted' : '')}
          onClick=${c.sort && onSort ? () => onSort(c.sort) : undefined} aria-sort=${active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
          ${c.label}${c.sort && html`<span class="sort-ind">${active ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}</span>`}</th>`;
      })}</tr></thead>
      <tbody>
        ${loading && !rows.length ? html`<tr><td colspan=${columns.length}><${Spinner} /></td></tr>`
        : !rows.length ? html`<tr><td colspan=${columns.length}><${Empty} title=${empty || 'No records found'} /></td></tr>`
        : rows.map((r, i) => html`<tr key=${r[rowKey] ?? i} class=${onRowClick ? 'clickable' : ''} onClick=${onRowClick ? () => onRowClick(r) : undefined}>
            ${columns.map((c) => html`<td key=${c.key} data-label=${c.label} class=${(c.align === 'right' || c.type === 'money' ? 'r num ' : '') + (c.className || '')}>
              ${c.render ? c.render(r) : renderCell(c.type, r[c.key])}</td>`)}
          </tr>`)}
      </tbody>
      ${footer && html`<tfoot>${footer}</tfoot>`}
    </table>
    ${loading && rows.length > 0 && html`<div class="table-loading"><span class="spinner"></span></div>`}
  </div>`;
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.total === 0) return null;
  const { page, pages, total, per_page } = meta;
  const from = (page - 1) * per_page + 1;
  const to = Math.min(total, page * per_page);
  const nums = [];
  for (let p = Math.max(1, page - 2); p <= Math.min(pages, page + 2); p++) nums.push(p);
  return html`<div class="pagination">
    <span class="muted">Showing ${num(from)}–${num(to)} of ${num(total)}</span>
    <div class="pager">
      <button class="icon-btn" disabled=${page <= 1} onClick=${() => onPage(page - 1)} aria-label="Previous page"><${Icon} name="chevronLeft" size=${16} /></button>
      ${nums[0] > 1 && html`<button class="page-btn" onClick=${() => onPage(1)}>1</button>${nums[0] > 2 && html`<span class="muted">…</span>`}`}
      ${nums.map((p) => html`<button key=${p} class=${'page-btn' + (p === page ? ' active' : '')} onClick=${() => onPage(p)}>${p}</button>`)}
      ${nums[nums.length - 1] < pages && html`${nums[nums.length - 1] < pages - 1 && html`<span class="muted">…</span>`}<button class="page-btn" onClick=${() => onPage(pages)}>${pages}</button>`}
      <button class="icon-btn" disabled=${page >= pages} onClick=${() => onPage(page + 1)} aria-label="Next page"><${Icon} name="chevronRight" size=${16} /></button>
    </div>
  </div>`;
}

export function Toolbar({ children }) {
  return html`<div class="toolbar">${children}</div>`;
}

export function Tabs({ tabs, active, onChange }) {
  return html`<div class="tabs" role="tablist">${tabs.map((t) => html`<button key=${t.key} role="tab" aria-selected=${active === t.key}
    class=${'tab' + (active === t.key ? ' active' : '')} onClick=${() => onChange(t.key)}>${t.label}${t.count ? html`<span class="tab-count">${t.count}</span>` : ''}</button>`)}</div>`;
}

export function DetailList({ items }) {
  return html`<dl class="detail-list">${items.filter(Boolean).map(([k, v]) => html`<div key=${k}><dt>${k}</dt><dd>${v === null || v === undefined || v === '' ? '-' : v}</dd></div>`)}</dl>`;
}

/* ------------------------------------------------------------------ charts (Chart.js) */
const PALETTE = ['#1d4ed8', '#0ea5e9', '#14b8a6', '#f59e0b', '#8b5cf6', '#ef4444', '#64748b', '#22c55e'];
export function ChartBox({ type = 'bar', labels, datasets, height = 260, isMoney = false, horizontal = false }) {
  const ref = useRef();
  useEffect(() => {
    if (!window.Chart || !ref.current) return undefined;
    const dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const grid = dark ? 'rgba(148,163,184,.15)' : 'rgba(100,116,139,.12)';
    const text = dark ? '#cbd5e1' : '#475569';
    const isPie = type === 'doughnut' || type === 'pie';
    const chart = new window.Chart(ref.current, {
      type,
      data: {
        labels,
        datasets: datasets.map((d, i) => ({
          borderWidth: isPie ? 2 : type === 'line' ? 2.5 : 0,
          borderRadius: type === 'bar' ? 6 : 0,
          maxBarThickness: 38,
          tension: 0.35,
          fill: type === 'line',
          pointRadius: type === 'line' ? 3 : 0,
          backgroundColor: isPie ? PALETTE : type === 'line' ? 'rgba(29,78,216,.10)' : (d.color || PALETTE[i]),
          borderColor: isPie ? (dark ? '#0f172a' : '#fff') : (d.color || PALETTE[i]),
          ...d,
        })),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        plugins: {
          legend: { display: isPie || datasets.length > 1, position: 'bottom', labels: { color: text, boxWidth: 12, usePointStyle: true } },
          tooltip: { callbacks: { label: (c) => `${c.dataset.label ? c.dataset.label + ': ' : isPie ? c.label + ': ' : ''}${isMoney ? money(c.raw) : num(c.raw)}` } },
        },
        scales: isPie ? {} : {
          // Only set a tick callback on the value axis: an explicit `undefined` would replace
          // Chart.js's default formatter and the category axis would show indexes instead of labels.
          x: { grid: { display: horizontal, color: grid }, ticks: { color: text, ...(horizontal && isMoney ? { callback: (v) => compact(v) } : {}) } },
          y: { grid: { display: !horizontal, color: grid }, beginAtZero: true, ticks: { color: text, ...(!horizontal && isMoney ? { callback: (v) => compact(v) } : {}) } },
        },
        cutout: type === 'doughnut' ? '62%' : undefined,
      },
    });
    return () => chart.destroy();
  }, [JSON.stringify(labels), JSON.stringify(datasets), type]);
  if (!window.Chart) return html`<p class="muted">Charts unavailable (Chart.js not loaded).</p>`;
  return html`<div class="chart-box" style=${{ height: height + 'px' }}><canvas ref=${ref}></canvas></div>`;
}
function compact(v) {
  const n = Number(v);
  if (Math.abs(n) >= 1e7) return '₹' + (n / 1e7).toFixed(1) + 'Cr';
  if (Math.abs(n) >= 1e5) return '₹' + (n / 1e5).toFixed(1) + 'L';
  if (Math.abs(n) >= 1e3) return '₹' + (n / 1e3).toFixed(0) + 'k';
  return '₹' + n;
}

export const photoUrl = (kind, id, hasPhoto, bust = '') => (hasPhoto ? apiUrl(`/${kind}/${id}/photo`, bust ? { v: bust } : {}) : null);
