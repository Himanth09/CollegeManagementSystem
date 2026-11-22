import { html, useState, useApi, useAuth, useForm, useList, post, toast, toastError, confirmDialog, download, fmtDate, fmtDateTime, clearMetaCache } from '../lib.js';
import { PageHeader, Card, DataTable, Pagination, Toolbar, SearchBox, FilterSelect, Button, IconButton, Badge, Modal, Input, Select, Spinner, ErrorBox, Alert, Tabs, Field } from '../components.js';

/* ================================================================== users */
export function Users() {
  const auth = useAuth();
  const { data, loading, error, reload } = useApi('/users');
  const [edit, setEdit] = useState(null);
  return html`<div>
    <${PageHeader} title="User Accounts" subtitle="Who can sign in, their role and any extra permissions.">
      <${Button} variant="primary" icon="plus" onClick=${() => setEdit({})}>New User<//>
    <//>
    ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
    <${Card} pad=${false}>
      <${DataTable} rows=${data?.data || []} loading=${loading} columns=${[
        { key: 'full_name', label: 'User', render: (u) => html`<div class="cell-title">${u.full_name}<small>${u.username} · ${u.email}</small></div>` },
        { key: 'role_name', label: 'Role', render: (u) => html`<${Badge} value=${u.role_name} tone=${u.role === 'super_admin' ? 'indigo' : u.role === 'accountant' ? 'teal' : 'blue'} />` },
        { key: 'staff_name', label: 'Linked staff', className: 'hide-md', render: (u) => (u.staff_name ? `${u.staff_name} (${u.employee_id})` : '-') },
        { key: 'granted', label: 'Extra permissions', className: 'hide-md', render: (u) => (u.granted.length ? u.granted.join(', ') : '-') },
        { key: 'last_login_at', label: 'Last login', render: (u) => fmtDateTime(u.last_login_at) },
        { key: 'is_active', label: 'Status', render: (u) => html`${Number(u.is_active) ? html`<${Badge} value="Active" />` : html`<${Badge} value="Inactive" />`}${!Number(u.has_password) ? html` <${Badge} value="No password" tone="amber" />` : ''}` },
        { key: 'act', label: '', render: (u) => html`<${IconButton} icon="edit" title="Edit" onClick=${() => setEdit(u)} />` },
      ]} />
    <//>
    ${edit && data && html`<${UserModal} user=${edit} lookups=${data} selfId=${auth.user.id} onClose=${() => setEdit(null)} onDone=${() => { setEdit(null); reload(); }} />`}
  </div>`;
}

function UserModal({ user: u, lookups, selfId, onClose, onDone }) {
  const form = useForm({
    username: u.username || '', email: u.email || '', full_name: u.full_name || '', role_id: u.role_id ? String(u.role_id) : '3',
    staff_id: u.staff_id ? String(u.staff_id) : '', is_active: u.id ? String(u.is_active) : '1', password: '',
  });
  const [perms, setPerms] = useState(new Set(u.granted || []));
  const role = lookups.roles.find((r) => String(r.id) === form.values.role_id);
  const rolePerms = new Set((role?.permissions || '').split(',').filter(Boolean));
  const isAdminRole = role?.slug === 'super_admin';
  const toggle = (slug) => setPerms((s) => { const n = new Set(s); n.has(slug) ? n.delete(slug) : n.add(slug); return n; });
  const pickStaff = (e) => {
    const st = lookups.staff.find((x) => String(x.id) === e.target.value);
    form.setValues((v) => ({ ...v, staff_id: e.target.value, full_name: v.full_name || st?.full_name || '', email: v.email || st?.email || '' }));
  };
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => post(u.id ? `/users/${u.id}` : '/users', { ...v, permissions: [...perms] }))
      .then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title=${u.id ? `Edit User — ${u.username}` : 'New User'} onClose=${onClose} size="lg"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Save user<//>`}>
    <form onSubmit=${save} novalidate>
      <div class="form-grid">
        <${Select} form=${form} name="role_id" label="Role" required placeholder=${false} options=${lookups.roles.map((r) => ({ value: String(r.id), label: r.name }))} disabled=${u.id === selfId} />
        <${Field} label="Linked staff record" error=${form.errors.staff_id}>
          <select class="input" value=${form.values.staff_id} onChange=${pickStaff}>
            <option value="">— Not linked —</option>
            ${lookups.staff.filter((s) => !s.user_id || String(s.user_id) === String(u.id)).map((s) => html`<option key=${s.id} value=${s.id}>${s.full_name} (${s.employee_id})</option>`)}
          </select>
        <//>
        <${Input} form=${form} name="full_name" label="Full name" required maxlength="150" />
        <${Input} form=${form} name="username" label="Username" required maxlength="60" autocomplete="off" />
        <${Input} form=${form} name="email" label="Email" type="email" required maxlength="150" />
        <${Input} form=${form} name="password" label=${u.id ? 'New password' : 'Password'} type="password" required=${!u.id} autocomplete="new-password"
          hint=${u.id ? 'Leave blank to keep the current password' : 'Min 8 chars incl. upper, lower, number & symbol'} />
        <${Select} form=${form} name="is_active" label="Status" placeholder=${false} options=${[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]} disabled=${u.id === selfId} />
      </div>
      <h4 class="form-section">Permissions</h4>
      ${isAdminRole ? html`<${Alert} type="info">Super Admins have full access to every module.<//>` : html`<div>
        <p class="muted small">Ticked & locked = included in the ${role?.name} role. Tick others to grant extra access. Salary, user management, audit, backups and fee configuration can never be granted.</p>
        <div class="perm-grid">${lookups.permissions.map((p) => {
          const inRole = rolePerms.has(p.slug);
          const locked = inRole || !Number(p.grantable);
          return html`<label key=${p.slug} class=${'perm' + (locked && !inRole ? ' perm-disabled' : '')}>
            <input type="checkbox" checked=${inRole || perms.has(p.slug)} disabled=${locked} onChange=${() => toggle(p.slug)} />
            <span>${p.name}${!Number(p.grantable) && html` <em class="muted">(admin only)</em>`}</span></label>`;
        })}</div>
      </div>`}
    </form>
  <//>`;
}

/* ================================================================== masters */
export function Masters() {
  const [tab, setTab] = useState('years');
  return html`<div>
    <${PageHeader} title="Academic Setup" subtitle="Academic years, departments and courses." />
    <${Tabs} active=${tab} onChange=${setTab} tabs=${[{ key: 'years', label: 'Academic Years' }, { key: 'departments', label: 'Departments' }, { key: 'courses', label: 'Courses' }]} />
    ${tab === 'years' && html`<${MasterTable} key="y" endpoint="/academic-years" noun="Academic Year"
      empty=${{ name: '', start_date: '', end_date: '', is_current: '0' }}
      columns=${[
        { key: 'name', label: 'Name' }, { key: 'start_date', label: 'Start', type: 'date' }, { key: 'end_date', label: 'End', type: 'date' },
        { key: 'student_count', label: 'Students', type: 'int' },
        { key: 'is_current', label: '', render: (r) => (Number(r.is_current) ? html`<${Badge} value="Current" tone="green" />` : '') },
      ]}
      fields=${(form) => html`
        <${Input} form=${form} name="name" label="Name" required placeholder="2027-28" maxlength="20" />
        <${Select} form=${form} name="is_current" label="Current year?" placeholder=${false} options=${[{ value: '0', label: 'No' }, { value: '1', label: 'Yes — make this the current year' }]} />
        <${Input} form=${form} name="start_date" label="Start date" type="date" required />
        <${Input} form=${form} name="end_date" label="End date" type="date" required />`} />`}
    ${tab === 'departments' && html`<${MasterTable} key="d" endpoint="/departments" noun="Department"
      empty=${{ code: '', name: '', is_active: '1' }}
      columns=${[
        { key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }, { key: 'course_count', label: 'Courses', type: 'int' },
        { key: 'student_count', label: 'Students', type: 'int' }, { key: 'staff_count', label: 'Staff', type: 'int' },
        { key: 'is_active', label: 'Status', render: (r) => html`<${Badge} value=${Number(r.is_active) ? 'Active' : 'Inactive'} />` },
      ]}
      fields=${(form) => html`
        <${Input} form=${form} name="code" label="Code" required maxlength="20" />
        <${Input} form=${form} name="name" label="Name" required maxlength="120" />
        <${Select} form=${form} name="is_active" label="Status" placeholder=${false} options=${[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]} />`} />`}
    ${tab === 'courses' && html`<${CoursesTab} />`}
  </div>`;
}

function CoursesTab() {
  const depts = useApi('/departments');
  const options = (depts.data?.data || []).map((d) => ({ value: String(d.id), label: d.name }));
  return html`<${MasterTable} endpoint="/courses" noun="Course"
    empty=${{ department_id: '', code: '', name: '', duration_years: '4', total_semesters: '8', is_active: '1' }}
    columns=${[
      { key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }, { key: 'department_name', label: 'Department', className: 'hide-md' },
      { key: 'duration_years', label: 'Years', type: 'int' }, { key: 'total_semesters', label: 'Semesters', type: 'int' },
      { key: 'student_count', label: 'Students', type: 'int' },
      { key: 'is_active', label: 'Status', render: (r) => html`<${Badge} value=${Number(r.is_active) ? 'Active' : 'Inactive'} />` },
    ]}
    fields=${(form) => html`
      <${Select} form=${form} name="department_id" label="Department" required options=${options} />
      <${Input} form=${form} name="code" label="Code" required maxlength="20" />
      <${Input} form=${form} name="name" label="Name" required maxlength="150" className="span-2" />
      <${Input} form=${form} name="duration_years" label="Duration (years)" type="number" min="1" max="8" required />
      <${Input} form=${form} name="total_semesters" label="Total semesters" type="number" min="1" max="16" required />
      <${Select} form=${form} name="is_active" label="Status" placeholder=${false} options=${[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]} />`} />`;
}

function MasterTable({ endpoint, noun, columns, fields, empty }) {
  const { data, loading, error, reload } = useApi(endpoint);
  const [edit, setEdit] = useState(null);
  const remove = async (r) => {
    if (!(await confirmDialog({ title: `Delete ${noun.toLowerCase()}?`, message: `Delete "${r.name}"? Records linked to it will block deletion.`, danger: true, confirmText: 'Delete' }))) return;
    post(`${endpoint}/${r.id}/delete`).then((d) => { toast(d.message); clearMetaCache(); reload(); }).catch(toastError);
  };
  return html`<${Card} pad=${false} title=${`${noun}s`} actions=${html`<${Button} size="sm" variant="primary" icon="plus" onClick=${() => setEdit({})}>Add ${noun}<//>`}>
    ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
    <${DataTable} rows=${data?.data || []} loading=${loading} columns=${[...columns, { key: 'act', label: '', render: (r) => html`<div class="row-actions">
      <${IconButton} icon="edit" title="Edit" onClick=${() => setEdit(r)} />
      <${IconButton} icon="trash" tone="danger" title="Delete" onClick=${() => remove(r)} /></div>` }]} />
    ${edit && html`<${MasterModal} row=${edit} endpoint=${endpoint} noun=${noun} fields=${fields} empty=${empty}
      onClose=${() => setEdit(null)} onDone=${() => { setEdit(null); clearMetaCache(); reload(); }} />`}
  <//>`;
}

function MasterModal({ row, endpoint, noun, fields, empty, onClose, onDone }) {
  const form = useForm(Object.fromEntries(Object.keys(empty).map((k) => [k, row[k] !== undefined && row[k] !== null ? String(row[k]) : empty[k]])));
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => post(row.id ? `${endpoint}/${row.id}` : endpoint, v)).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title=${`${row.id ? 'Edit' : 'Add'} ${noun}`} onClose=${onClose}
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Save<//>`}>
    <form onSubmit=${save} novalidate class="form-grid">${fields(form)}</form>
  <//>`;
}

/* ================================================================== audit */
export function AuditLogs() {
  const list = useList('/audit', { from: '', to: '', entity_type: '', financial: '' });
  const [open, setOpen] = useState(null);
  const f = list.filters;
  const ENTITIES = ['fee_payment', 'fee_assignment', 'fee_structure', 'salary_structure', 'salary_payment', 'payroll', 'student', 'staff', 'user', 'backup', 'report'];
  return html`<div>
    <${PageHeader} title="Audit Logs" subtitle="Append-only record of logins and every data / financial change." />
    <${Card} pad=${false}>
      <${Toolbar}>
        <${SearchBox} value=${list.search} onChange=${list.setSearch} placeholder="Action, entity, user…" />
        <${FilterSelect} placeholder="All entities" value=${f.entity_type} onChange=${(v) => list.setFilter('entity_type', v)} options=${ENTITIES.map((e) => ({ value: e, label: e }))} />
        <label class="inline-field">From <input class="input input-sm" type="date" value=${f.from} onChange=${(e) => list.setFilter('from', e.target.value)} /></label>
        <label class="inline-field">To <input class="input input-sm" type="date" value=${f.to} onChange=${(e) => list.setFilter('to', e.target.value)} /></label>
        <label class="check-field"><input type="checkbox" checked=${f.financial === '1'} onChange=${(e) => list.setFilter('financial', e.target.checked ? '1' : '')} /> Financial only</label>
      <//>
      ${list.error && html`<${ErrorBox} error=${list.error} onRetry=${list.reload} />`}
      <${DataTable} rows=${list.rows} loading=${list.loading} sort=${list.sort} onSort=${list.toggleSort} onRowClick=${setOpen} columns=${[
        { key: 'created_at', label: 'When', sort: 'created_at', render: (r) => fmtDateTime(r.created_at) },
        { key: 'username', label: 'User', render: (r) => r.full_name || html`<em class="muted">system</em>` },
        { key: 'action', label: 'Action', sort: 'action', render: (r) => html`<code>${r.action}</code>` },
        { key: 'entity_type', label: 'Entity', sort: 'entity_type', render: (r) => `${r.entity_type}${r.entity_id ? ' #' + r.entity_id : ''}` },
        { key: 'ip_address', label: 'IP', className: 'hide-md' },
      ]} />
      <${Pagination} meta=${list.meta} onPage=${list.setPage} />
    <//>
    ${open && html`<${Modal} title=${`Audit #${open.id} — ${open.action}`} onClose=${() => setOpen(null)} size="lg">
      <p class="muted small">${fmtDateTime(open.created_at)} · ${open.full_name || 'system'} · ${open.ip_address}</p>
      <div class="grid-2">
        <div><h4 class="form-section">Before</h4><pre class="json">${open.old_values ? JSON.stringify(open.old_values, null, 2) : '—'}</pre></div>
        <div><h4 class="form-section">After</h4><pre class="json">${open.new_values ? JSON.stringify(open.new_values, null, 2) : '—'}</pre></div>
      </div>
    <//>`}
  </div>`;
}

/* ================================================================== backups */
export function Backups() {
  const { data, loading, error, reload } = useApi('/backups');
  const [busy, setBusy] = useState(false);
  const create = () => {
    setBusy(true);
    post('/backups').then((d) => { toast(d.message); reload(); }).catch(toastError).finally(() => setBusy(false));
  };
  const remove = async (f) => {
    if (!(await confirmDialog({ title: 'Delete backup?', message: `Delete ${f.name}? This cannot be undone.`, danger: true, confirmText: 'Delete' }))) return;
    post('/backups/delete', { name: f.name }).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(2) + ' MB' : (b / 1024).toFixed(1) + ' KB');
  return html`<div>
    <${PageHeader} title="Database Backup" subtitle="Full SQL backups stored privately on the server.">
      <${Button} variant="primary" icon="database" busy=${busy} onClick=${create}>Create backup now<//>
    <//>
    <${Alert} type="info">Download backups and keep copies off the server. To restore, import the .sql (or extracted .sql.gz) file in phpMyAdmin. Your hosting control panel's own backups are a good second layer.<//>
    <${Card} pad=${false}>
      ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
      <${DataTable} rows=${data?.data || []} rowKey="name" loading=${loading} empty="No backups yet" columns=${[
        { key: 'name', label: 'File', render: (f) => html`<code>${f.name}</code>` },
        { key: 'created_at', label: 'Created', render: (f) => fmtDateTime(f.created_at) },
        { key: 'size', label: 'Size', align: 'right', render: (f) => size(f.size) },
        { key: 'act', label: '', render: (f) => html`<div class="row-actions">
          <${IconButton} icon="download" title="Download" onClick=${() => download('/backups/download', { name: f.name })} />
          <${IconButton} icon="trash" tone="danger" title="Delete" onClick=${() => remove(f)} /></div>` },
      ]} />
    <//>
  </div>`;
}
