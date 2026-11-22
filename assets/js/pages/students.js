import {
  html, useState, useEffect, useList, useApi, useAuth, useForm, useMeta, api, post, toast, toastError, navigate,
  confirmDialog, download, openPrint, money, fmtDate, toFormData, today,
} from '../lib.js';
import {
  PageHeader, Card, DataTable, Pagination, Toolbar, SearchBox, FilterSelect, Button, IconButton, Badge, Avatar,
  Input, Select, Textarea, PhotoInput, Spinner, ErrorBox, DetailList, Tabs, StatCard, Empty, photoUrl,
} from '../components.js';
import { AssignFeeModal, PaymentModal, EditAssignmentModal, paymentActions } from './fees.js';

const STATUS_OPTS = ['Active', 'Inactive', 'Graduated'].map((s) => ({ value: s, label: s }));
const semOpts = (n = 8) => Array.from({ length: n }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }));

/* ================================================================== list */
export function StudentsList({ query }) {
  const auth = useAuth();
  const meta = useMeta();
  const list = useList('/students', { status: query.status || '', academic_year_id: '', semester: '', department_id: '', course_id: '' }, { sort: 'full_name', dir: 'asc' });
  const f = list.filters;
  return html`<div>
    <${PageHeader} title="Students" subtitle=${list.meta ? `${list.meta.total} student record(s)` : ''}>
      <${Button} icon="download" onClick=${() => download('/students/export', list.params)}>Export CSV<//>
      ${auth.can('students.manage') && html`<a class="btn btn-primary" href="#/students/new">+ Add Student</a>`}
    <//>
    <${Card} pad=${false}>
      <${Toolbar}>
        <${SearchBox} value=${list.search} onChange=${list.setSearch} placeholder="Search name, ID, course, department…" />
        <${FilterSelect} label="Academic year" placeholder="All years" value=${f.academic_year_id} onChange=${(v) => list.setFilter('academic_year_id', v)}
          options=${meta.academic_years.map((a) => ({ value: a.id, label: a.name }))} />
        <${FilterSelect} label="Department" placeholder="All departments" value=${f.department_id} onChange=${(v) => list.setFilter('department_id', v)}
          options=${meta.departments.map((d) => ({ value: d.id, label: d.name }))} />
        <${FilterSelect} label="Course" placeholder="All courses" value=${f.course_id} onChange=${(v) => list.setFilter('course_id', v)}
          options=${meta.courses.filter((c) => !f.department_id || String(c.department_id) === String(f.department_id)).map((c) => ({ value: c.id, label: c.code }))} />
        <${FilterSelect} label="Semester" placeholder="All semesters" value=${f.semester} onChange=${(v) => list.setFilter('semester', v)} options=${semOpts(10)} />
        <${FilterSelect} label="Status" placeholder="All statuses" value=${f.status} onChange=${(v) => list.setFilter('status', v)} options=${STATUS_OPTS} />
        <${Button} variant="ghost" size="sm" onClick=${list.resetFilters}>Reset<//>
      <//>
      ${list.error && html`<${ErrorBox} error=${list.error} onRetry=${list.reload} />`}
      <${DataTable} rows=${list.rows} loading=${list.loading} sort=${list.sort} onSort=${list.toggleSort}
        onRowClick=${(r) => navigate(`/students/${r.id}`)} columns=${[
          { key: 'full_name', label: 'Student', sort: 'full_name', render: (r) => html`<div class="cell-user">
            <${Avatar} name=${r.full_name} src=${photoUrl('students', r.id, Number(r.has_photo), r.updated_at)} size=${36} />
            <div class="cell-title">${r.full_name}<small>${r.email || r.contact_number || ''}</small></div></div>` },
          { key: 'admission_no', label: 'Admission No', sort: 'admission_no' },
          { key: 'course_code', label: 'Course', sort: 'course', render: (r) => html`<span title=${r.course_name}>${r.course_code}</span>` },
          { key: 'department_name', label: 'Department', sort: 'department', className: 'hide-md' },
          { key: 'current_semester', label: 'Sem', sort: 'semester', render: (r) => `Y${r.current_year} / S${r.current_semester}` },
          { key: 'academic_year', label: 'Year', sort: 'academic_year', className: 'hide-md' },
          { key: 'status', label: 'Status', sort: 'status', render: (r) => html`<${Badge} value=${r.status} />` },
          { key: 'act', label: '', render: (r) => html`<div class="row-actions" onClick=${(e) => e.stopPropagation()}>
            <${IconButton} icon="eye" title="View profile" href=${`#/students/${r.id}`} />
            ${auth.can('students.manage') && html`<${IconButton} icon="edit" title="Edit" href=${`#/students/${r.id}/edit`} />`}
          </div>` },
        ]} />
      <${Pagination} meta=${list.meta} onPage=${list.setPage} />
    <//>
  </div>`;
}

/* ================================================================== form */
const EMPTY = {
  admission_no: '', full_name: '', father_name: '', mother_name: '', date_of_birth: '', gender: '', contact_number: '',
  parent_contact_number: '', email: '', address: '', course_id: '', current_year: '', current_semester: '1',
  admission_date: today(), academic_year_id: '', status: 'Active',
};

export function StudentForm({ params }) {
  const id = params.id;
  const meta = useMeta();
  const form = useForm(EMPTY);
  const [loading, setLoading] = useState(!!id);
  const [photo, setPhoto] = useState(null);
  const [currentPhoto, setCurrentPhoto] = useState(null);
  const [removePhoto, setRemovePhoto] = useState(false);

  useEffect(() => {
    if (!id) return;
    api(`/students/${id}`).then((d) => {
      const s = d.student;
      const v = {};
      Object.keys(EMPTY).forEach((k) => { v[k] = s[k] === null || s[k] === undefined ? '' : String(s[k]); });
      form.setValues(v);
      setCurrentPhoto(photoUrl('students', s.id, Number(s.has_photo), s.updated_at));
      setLoading(false);
    }).catch((e) => { toastError(e); navigate('/students'); });
  }, [id]);

  useEffect(() => {
    if (!id && !form.values.academic_year_id && meta.academic_years.length) {
      const cur = meta.academic_years.find((a) => Number(a.is_current)) || meta.academic_years[0];
      form.setValues((v) => ({ ...v, academic_year_id: String(cur.id) }));
    }
  }, [meta.academic_years.length]);

  const course = meta.courses.find((c) => String(c.id) === String(form.values.course_id));
  const save = (e) => {
    e.preventDefault();
    const values = { ...form.values, remove_photo: removePhoto ? '1' : '0' };
    form.submit(() => api(id ? `/students/${id}` : '/students', { method: 'POST', form: toFormData(values, photo) }))
      .then((d) => { toast(d.message); navigate(`/students/${d.id}`); })
      .catch(toastError);
  };
  if (loading) return html`<${Spinner} />`;
  return html`<div>
    <${PageHeader} title=${id ? 'Edit Student' : 'Add New Student'} back=${id ? `#/students/${id}` : '#/students'} />
    <form onSubmit=${save} novalidate>
      <${Card} title="Personal Information">
        <${PhotoInput} name=${form.values.full_name} current=${removePhoto ? null : currentPhoto} onFile=${setPhoto} onRemove=${() => setRemovePhoto(true)} />
        <div class="form-grid">
          <${Input} form=${form} name="full_name" label="Full name" required maxlength="150" />
          <${Input} form=${form} name="admission_no" label="Admission number" maxlength="30" hint=${id ? '' : 'Leave blank to auto-generate'} />
          <${Input} form=${form} name="father_name" label="Father's name" maxlength="150" />
          <${Input} form=${form} name="mother_name" label="Mother's name" maxlength="150" />
          <${Input} form=${form} name="date_of_birth" label="Date of birth" type="date" max=${today()} />
          <${Select} form=${form} name="gender" label="Gender" required options=${['Male', 'Female', 'Other'].map((g) => ({ value: g, label: g }))} />
        </div>
      <//>
      <${Card} title="Contact Details">
        <div class="form-grid">
          <${Input} form=${form} name="contact_number" label="Contact number" type="tel" maxlength="20" />
          <${Input} form=${form} name="parent_contact_number" label="Parent contact number" type="tel" maxlength="20" />
          <${Input} form=${form} name="email" label="Email address" type="email" maxlength="150" />
          <${Textarea} form=${form} name="address" label="Residential address" className="span-2" maxlength="500" />
        </div>
      <//>
      <${Card} title="Academic Details">
        <div class="form-grid">
          <${Select} form=${form} name="course_id" label="Course" required options=${meta.courses.filter((c) => Number(c.is_active) || String(c.id) === form.values.course_id).map((c) => ({ value: String(c.id), label: c.name }))} />
          <${Field2} label="Department" value=${course ? (meta.departments.find((d) => d.id === course.department_id)?.name || '') : 'Selected automatically from the course'} />
          <${Select} form=${form} name="current_semester" label="Semester" required options=${semOpts(course ? course.total_semesters : 8)} placeholder=${false} />
          <${Input} form=${form} name="current_year" label="Year" type="number" min="1" max="8" hint="Leave blank to derive from semester" />
          <${Input} form=${form} name="admission_date" label="Admission date" type="date" required />
          <${Select} form=${form} name="academic_year_id" label="Academic year" required options=${meta.academic_years.map((a) => ({ value: String(a.id), label: a.name }))} />
          <${Select} form=${form} name="status" label="Status" required options=${STATUS_OPTS} placeholder=${false} />
        </div>
      <//>
      <div class="form-actions">
        <a class="btn" href=${id ? `#/students/${id}` : '#/students'}>Cancel</a>
        <${Button} type="submit" variant="primary" busy=${form.busy}>${id ? 'Save changes' : 'Add student'}<//>
      </div>
    </form>
  </div>`;
}

function Field2({ label, value }) {
  return html`<div class="field"><span class="field-label">${label}</span><div class="input input-static">${value}</div></div>`;
}

/* ================================================================== profile */
export function StudentProfile({ params }) {
  const auth = useAuth();
  const { data, loading, error, reload } = useApi(`/students/${params.id}`);
  const [tab, setTab] = useState('fees');
  const [modal, setModal] = useState(null);

  if (loading && !data) return html`<${Spinner} />`;
  if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
  const s = data.student;
  const fees = data.fees;

  const remove = async () => {
    if (!(await confirmDialog({ title: 'Delete student?', message: `Permanently delete ${s.full_name}? This cannot be undone.`, danger: true, confirmText: 'Delete' }))) return;
    post(`/students/${s.id}/delete`).then((d) => { toast(d.message); navigate('/students'); }).catch(toastError);
  };

  return html`<div>
    <${PageHeader} title="Student Profile" back="#/students">
      ${auth.can('fees.collect') && html`<${Button} variant="primary" icon="fees" onClick=${() => setModal({ type: 'pay' })}>Record Payment<//>`}
      ${auth.can('students.manage') && html`<a class="btn" href=${`#/students/${s.id}/edit`}>Edit</a>`}
      ${auth.can('students.delete') && html`<${Button} variant="danger-ghost" icon="trash" onClick=${remove}>Delete<//>`}
    <//>
    <div class="profile-layout">
      <${Card} className="profile-card">
        <div class="profile-hero">
          <${Avatar} name=${s.full_name} src=${photoUrl('students', s.id, Number(s.has_photo), s.updated_at)} size=${96} />
          <h2>${s.full_name}</h2>
          <p class="muted">${s.admission_no}</p>
          <${Badge} value=${s.status} />
        </div>
        <${DetailList} items=${[
          ['Course', s.course_name], ['Department', s.department_name], ['Year / Semester', `Year ${s.current_year} / Semester ${s.current_semester}`],
          ['Academic year', s.academic_year], ['Admission date', fmtDate(s.admission_date)],
        ]} />
      <//>
      <div class="profile-main">
        ${fees && html`<div class="stat-grid stat-grid-3">
          <${StatCard} label="Total Payable" value=${money(fees.summary.total_payable)} icon="fees" />
          <${StatCard} label="Total Paid" value=${money(fees.summary.total_paid)} icon="check" tone="green" />
          <${StatCard} label="Outstanding" value=${money(fees.summary.outstanding)} icon="alert" tone=${Number(fees.summary.outstanding) > 0 ? 'red' : 'green'} />
        </div>`}
        <${Card} pad=${false}>
          <${Tabs} active=${fees ? tab : 'details'} onChange=${setTab} tabs=${[
            ...(fees ? [{ key: 'fees', label: 'Fee Details' }, { key: 'payments', label: 'Payment History', count: fees.payments.length }] : []),
            { key: 'details', label: 'Personal Details' },
          ]} />
          <div class="card-body">
            ${(!fees || tab === 'details') && html`<${DetailList} items=${[
              ["Father's name", s.father_name], ["Mother's name", s.mother_name], ['Date of birth', fmtDate(s.date_of_birth)],
              ['Gender', s.gender], ['Contact number', s.contact_number], ['Parent contact', s.parent_contact_number],
              ['Email', s.email], ['Address', s.address],
            ]} />`}
            ${fees && tab === 'fees' && html`<${FeeAssignments} fees=${fees} auth=${auth} onAssign=${() => setModal({ type: 'assign' })}
              onPay=${(a) => setModal({ type: 'pay', assignment: a })} onEdit=${(a) => setModal({ type: 'edit', assignment: a })} reload=${reload} />`}
            ${fees && tab === 'payments' && html`<${DataTable} rows=${fees.payments} empty="No payments recorded" columns=${[
              { key: 'receipt_no', label: 'Receipt', render: (r) => r.receipt_no || html`<em class="muted">—</em>` },
              { key: 'payment_date', label: 'Date', type: 'date' },
              { key: 'assignment_title', label: 'Fee' },
              { key: 'payment_mode', label: 'Mode', render: (r) => html`<div class="cell-title">${r.payment_mode}<small>${r.transaction_ref || ''}</small></div>` },
              { key: 'amount', label: 'Amount', type: 'money' },
              { key: 'status', label: 'Status', render: (r) => html`<${Badge} value=${r.status} />` },
              { key: 'act', label: '', render: (r) => paymentActions(r, auth, reload) },
            ]} />`}
          </div>
        <//>
      </div>
    </div>
    ${modal?.type === 'assign' && html`<${AssignFeeModal} student=${s} onClose=${() => setModal(null)} onDone=${() => { setModal(null); reload(); }} />`}
    ${modal?.type === 'pay' && html`<${PaymentModal} student=${s} assignments=${fees ? fees.assignments : []} preselect=${modal.assignment}
      onClose=${() => setModal(null)} onDone=${() => { setModal(null); reload(); }} />`}
    ${modal?.type === 'edit' && html`<${EditAssignmentModal} assignment=${modal.assignment} onClose=${() => setModal(null)} onDone=${() => { setModal(null); reload(); }} />`}
  </div>`;
}

function FeeAssignments({ fees, auth, onAssign, onPay, onEdit, reload }) {
  const removeAssignment = async (a) => {
    if (!(await confirmDialog({ title: 'Remove fee?', message: `Remove "${a.title}" from this student?`, danger: true, confirmText: 'Remove' }))) return;
    post(`/fees/assignments/${a.id}/delete`).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  const s = fees.summary;
  return html`<div>
    <div class="section-head">
      <p class="muted small">Gross ${money(s.gross)} − Scholarship ${money(s.scholarship)} − Discount ${money(s.discount)} = Payable ${money(s.total_payable)}</p>
      ${(auth.can('fees.collect') || auth.can('fees.configure')) && html`<${Button} size="sm" icon="plus" onClick=${onAssign}>Assign Fee<//>`}
    </div>
    ${!fees.assignments.length ? html`<${Empty} title="No fees assigned" text="Assign a fee structure to start collecting payments." />`
    : html`<${DataTable} rows=${fees.assignments} columns=${[
      { key: 'title', label: 'Fee', render: (a) => html`<div class="cell-title">${a.title}<small>${a.academic_year} · Semester ${a.semester}${Number(a.pending_review_count) ? ' · ' + a.pending_review_count + ' awaiting review' : ''}</small></div>` },
      { key: 'gross_amount', label: 'Gross', type: 'money', className: 'hide-md' },
      { key: 'concession', label: 'Concession', align: 'right', className: 'hide-md', render: (a) => money(Number(a.scholarship_amount) + Number(a.discount_amount)) },
      { key: 'total_payable', label: 'Payable', type: 'money' },
      { key: 'amount_paid', label: 'Paid', type: 'money' },
      { key: 'balance', label: 'Balance', type: 'money', render: (a) => html`<strong class=${Number(a.balance) > 0 ? 'text-red' : 'text-green'}>${money(a.balance)}</strong>` },
      { key: 'due_date', label: 'Due', render: (a) => html`${fmtDate(a.due_date)}${Number(a.balance) > 0 && a.due_date && a.due_date < today() ? html` <${Badge} value="overdue" />` : Number(a.balance) <= 0 ? html` <${Badge} value="cleared" tone="green" />` : ''}` },
      { key: 'act', label: '', render: (a) => html`<div class="row-actions">
        ${auth.can('fees.collect') && Number(a.balance) > 0 && html`<${IconButton} icon="fees" title="Record payment" onClick=${() => onPay(a)} />`}
        ${auth.can('fees.configure') && html`<${IconButton} icon="edit" title="Edit fee / concession" onClick=${() => onEdit(a)} />`}
        ${auth.can('fees.configure') && Number(a.amount_paid) === 0 && html`<${IconButton} icon="trash" tone="danger" title="Remove" onClick=${() => removeAssignment(a)} />`}
      </div>` },
    ]} />`}
  </div>`;
}
