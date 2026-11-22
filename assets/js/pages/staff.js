import { html, useState, useEffect, useList, useApi, useAuth, useForm, useMeta, api, post, toast, toastError, navigate, confirmDialog, download, fmtDate, toFormData, clearMetaCache } from '../lib.js';
import {
  PageHeader, Card, DataTable, Pagination, Toolbar, SearchBox, FilterSelect, Button, IconButton, Badge, Avatar,
  Input, Select, Textarea, PhotoInput, Spinner, ErrorBox, DetailList, photoUrl,
} from '../components.js';

export const STAFF_STATUSES = ['Active', 'On Leave', 'Resigned', 'Retired', 'Terminated'].map((s) => ({ value: s, label: s }));

export function StaffList() {
  const auth = useAuth();
  const meta = useMeta();
  const list = useList('/staff', { department_id: '', status: '', designation: '' }, { sort: 'full_name', dir: 'asc' });
  const depts = useApi('/staff/departments');
  const f = list.filters;
  return html`<div>
    <${PageHeader} title="Staff" subtitle=${list.meta ? `${list.meta.total} employee(s)` : ''}>
      <${Button} icon="download" onClick=${() => download('/staff/export', list.params)}>Export CSV<//>
      ${auth.can('staff.manage') && html`<a class="btn btn-primary" href="#/staff/new">+ Add Staff</a>`}
    <//>
    ${depts.data && html`<div class="chip-row" role="group" aria-label="Departments">
      <button class=${'chip' + (!f.department_id ? ' active' : '')} onClick=${() => list.setFilter('department_id', '')}>All departments</button>
      ${depts.data.data.filter((d) => Number(d.total) > 0).map((d) => html`<button key=${d.id} class=${'chip' + (String(f.department_id) === String(d.id) ? ' active' : '')}
        onClick=${() => list.setFilter('department_id', d.id)}>${d.name} <span class="chip-count">${d.total}</span></button>`)}
    </div>`}
    <${Card} pad=${false}>
      <${Toolbar}>
        <${SearchBox} value=${list.search} onChange=${list.setSearch} placeholder="Search name, employee ID, designation…" />
        <${FilterSelect} placeholder="All designations" value=${f.designation} onChange=${(v) => list.setFilter('designation', v)} options=${meta.designations.map((d) => ({ value: d, label: d }))} />
        <${FilterSelect} placeholder="All statuses" value=${f.status} onChange=${(v) => list.setFilter('status', v)} options=${STAFF_STATUSES} />
        <${Button} variant="ghost" size="sm" onClick=${list.resetFilters}>Reset<//>
      <//>
      ${list.error && html`<${ErrorBox} error=${list.error} onRetry=${list.reload} />`}
      <${DataTable} rows=${list.rows} loading=${list.loading} sort=${list.sort} onSort=${list.toggleSort} onRowClick=${(r) => navigate(`/staff/${r.id}`)} columns=${[
        { key: 'full_name', label: 'Employee', sort: 'full_name', render: (r) => html`<div class="cell-user">
          <${Avatar} name=${r.full_name} src=${photoUrl('staff', r.id, Number(r.has_photo), r.updated_at)} size=${36} />
          <div class="cell-title">${r.full_name}<small>${r.email || ''}</small></div></div>` },
        { key: 'employee_id', label: 'Emp ID', sort: 'employee_id' },
        { key: 'designation', label: 'Designation', sort: 'designation' },
        { key: 'department_name', label: 'Department', sort: 'department', className: 'hide-md' },
        { key: 'date_of_joining', label: 'Joined', sort: 'date_of_joining', type: 'date', className: 'hide-md' },
        { key: 'employment_status', label: 'Status', sort: 'status', render: (r) => html`<${Badge} value=${r.employment_status} />` },
        { key: 'act', label: '', render: (r) => html`<div class="row-actions" onClick=${(e) => e.stopPropagation()}>
          <${IconButton} icon="eye" title="View" href=${`#/staff/${r.id}`} />
          ${auth.can('staff.manage') && html`<${IconButton} icon="edit" title="Edit" href=${`#/staff/${r.id}/edit`} />`}
        </div>` },
      ]} />
      <${Pagination} meta=${list.meta} onPage=${list.setPage} />
    <//>
  </div>`;
}

const EMPTY = { employee_id: '', full_name: '', designation: '', department_id: '', contact_number: '', email: '', address: '', date_of_joining: '', employment_status: 'Active', qualification: '' };

export function StaffForm({ params }) {
  const id = params.id;
  const meta = useMeta();
  const form = useForm(EMPTY);
  const [loading, setLoading] = useState(!!id);
  const [photo, setPhoto] = useState(null);
  const [currentPhoto, setCurrentPhoto] = useState(null);
  const [removePhoto, setRemovePhoto] = useState(false);
  useEffect(() => {
    if (!id) return;
    api(`/staff/${id}`).then((d) => {
      const s = d.staff;
      form.setValues(Object.fromEntries(Object.keys(EMPTY).map((k) => [k, s[k] === null ? '' : String(s[k])])));
      setCurrentPhoto(photoUrl('staff', s.id, Number(s.has_photo), s.updated_at));
      setLoading(false);
    }).catch((e) => { toastError(e); navigate('/staff'); });
  }, [id]);
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => api(id ? `/staff/${id}` : '/staff', { method: 'POST', form: toFormData({ ...v, remove_photo: removePhoto ? '1' : '0' }, photo) }))
      .then((d) => { toast(d.message); clearMetaCache(); navigate(`/staff/${d.id}`); })
      .catch(toastError);
  };
  if (loading) return html`<${Spinner} />`;
  return html`<div>
    <${PageHeader} title=${id ? 'Edit Staff' : 'Add Staff'} back=${id ? `#/staff/${id}` : '#/staff'} />
    <form onSubmit=${save} novalidate>
      <${Card} title="Employee Details">
        <${PhotoInput} name=${form.values.full_name} current=${removePhoto ? null : currentPhoto} onFile=${setPhoto} onRemove=${() => setRemovePhoto(true)} />
        <div class="form-grid">
          <${Input} form=${form} name="employee_id" label="Employee ID" required maxlength="30" />
          <${Input} form=${form} name="full_name" label="Full name" required maxlength="150" />
          <${Input} form=${form} name="designation" label="Designation" required maxlength="100" list="designations" />
          <datalist id="designations">${meta.designations.map((d) => html`<option key=${d} value=${d} />`)}</datalist>
          <${Select} form=${form} name="department_id" label="Department" options=${meta.departments.map((d) => ({ value: String(d.id), label: d.name }))} placeholder="— None —" />
          <${Input} form=${form} name="qualification" label="Qualification" maxlength="255" />
          <${Input} form=${form} name="date_of_joining" label="Date of joining" type="date" />
          <${Select} form=${form} name="employment_status" label="Employment status" required options=${STAFF_STATUSES} placeholder=${false} />
        </div>
      <//>
      <${Card} title="Contact">
        <div class="form-grid">
          <${Input} form=${form} name="contact_number" label="Contact number" type="tel" maxlength="20" />
          <${Input} form=${form} name="email" label="Email address" type="email" maxlength="150" />
          <${Textarea} form=${form} name="address" label="Address" className="span-2" maxlength="500" />
        </div>
      <//>
      <div class="form-actions">
        <a class="btn" href=${id ? `#/staff/${id}` : '#/staff'}>Cancel</a>
        <${Button} type="submit" variant="primary" busy=${form.busy}>${id ? 'Save changes' : 'Add staff'}<//>
      </div>
    </form>
  </div>`;
}

export function StaffProfile({ params }) {
  const auth = useAuth();
  const { data, loading, error, reload } = useApi(`/staff/${params.id}`);
  if (loading && !data) return html`<${Spinner} />`;
  if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
  const s = data.staff;
  const remove = async () => {
    if (!(await confirmDialog({ title: 'Delete staff member?', message: `Permanently delete ${s.full_name}?`, danger: true, confirmText: 'Delete' }))) return;
    post(`/staff/${s.id}/delete`).then((d) => { toast(d.message); navigate('/staff'); }).catch(toastError);
  };
  return html`<div>
    <${PageHeader} title="Staff Profile" back=${auth.can('staff.view') ? '#/staff' : '#/'}>
      ${auth.isAdmin && html`<a class="btn" href="#/salary/structures">Salary details</a>`}
      ${auth.can('staff.manage') && html`<a class="btn btn-primary" href=${`#/staff/${s.id}/edit`}>Edit</a>`}
      ${auth.isAdmin && html`<${Button} variant="danger-ghost" icon="trash" onClick=${remove}>Delete<//>`}
    <//>
    <div class="profile-layout">
      <${Card} className="profile-card">
        <div class="profile-hero">
          <${Avatar} name=${s.full_name} src=${photoUrl('staff', s.id, Number(s.has_photo), s.updated_at)} size=${96} />
          <h2>${s.full_name}</h2><p class="muted">${s.designation}</p><${Badge} value=${s.employment_status} />
        </div>
      <//>
      <${Card} title="Details" className="profile-main">
        <${DetailList} items=${[
          ['Employee ID', s.employee_id], ['Department', s.department_name], ['Designation', s.designation],
          ['Qualification', s.qualification], ['Date of joining', fmtDate(s.date_of_joining)], ['Contact number', s.contact_number],
          ['Email', s.email], ['Address', s.address], ['Portal username', s.username],
        ]} />
      <//>
    </div>
  </div>`;
}
