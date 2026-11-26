import {
  html, useState, useEffect, useList, useApi, useAuth, useForm, useMeta, api, post, toast, toastError, navigate,
  confirmDialog, download, openPrint, money, fmtDate, today,
} from '../lib.js';
import {
  PageHeader, Card, DataTable, Pagination, Toolbar, SearchBox, FilterSelect, Button, IconButton, Badge, Avatar, Modal,
  Input, Select, Textarea, MoneyInput, Spinner, ErrorBox, Alert, Empty, StatCard, Tabs, Field, photoUrl,
} from '../components.js';

export const COMPONENTS = [
  ['admission_fee', 'Admission Fee'], ['tuition_fee', 'Tuition Fee'], ['examination_fee', 'Examination Fee'],
  ['library_fee', 'Library Fee'], ['laboratory_fee', 'Laboratory Fee'], ['transport_fee', 'Transport Fee'],
  ['hostel_fee', 'Hostel Fee'], ['misc_fee', 'Miscellaneous Charges'],
];
const MODES = ['Cash', 'UPI', 'Bank Transfer', 'Card'].map((m) => ({ value: m, label: m }));
const toPaise = (v) => Math.round(Number(v || 0) * 100);
const sumPaise = (vals) => vals.reduce((t, v) => t + toPaise(v), 0);
const fromPaise = (p) => (p / 100).toFixed(2);

/* ================================================================== payment actions (shared) */
export function paymentActions(r, auth, reload) {
  const review = async (decision) => {
    const note = await confirmDialog({
      title: decision === 'approve' ? 'Approve payment?' : 'Reject payment?',
      message: decision === 'approve'
        ? `Approve ${money(r.amount)} despite the duplicate reference "${r.transaction_ref}"? A receipt will be generated and the balance reduced.`
        : `Reject this ${money(r.amount)} entry? It will not count towards the student's balance.`,
      confirmText: decision === 'approve' ? 'Approve' : 'Reject', danger: decision === 'reject',
      input: { label: 'Review note (optional)' },
    });
    if (note === false) return;
    post(`/fees/payments/${r.id}/review`, { decision, note: note || '' }).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  const cancel = async () => {
    const reason = await confirmDialog({
      title: 'Cancel payment?', danger: true, confirmText: 'Cancel payment',
      message: `Cancel receipt ${r.receipt_no} for ${money(r.amount)}? The amount will be added back to the outstanding balance. This is recorded in the audit log.`,
      input: { label: 'Reason (required)', required: true, minLength: 5 },
    });
    if (reason === false) return;
    post(`/fees/payments/${r.id}/cancel`, { reason }).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  return html`<div class="row-actions" onClick=${(e) => e.stopPropagation()}>
    ${(r.status === 'confirmed' || r.status === 'cancelled') && html`<${IconButton} icon="print" title="Print receipt" onClick=${() => openPrint(`/print/receipt/${r.id}`)} />`}
    ${r.status === 'pending_review' && auth.can('fees.review') && html`
      <${IconButton} icon="check" tone="success" title="Approve" onClick=${() => review('approve')} />
      <${IconButton} icon="x" tone="danger" title="Reject" onClick=${() => review('reject')} />`}
    ${r.status === 'confirmed' && auth.can('fees.review') && html`<${IconButton} icon="trash" tone="danger" title="Cancel payment" onClick=${cancel} />`}
  </div>`;
}

/* ================================================================== record payment modal */
export function PaymentModal({ student, assignments, preselect, onClose, onDone }) {
  const open = assignments.filter((a) => Number(a.balance) > 0);
  const first = preselect || open[0];
  const form = useForm({
    assignment_id: first ? String(first.id) : '', amount: first ? String(Number(first.balance)) : '',
    payment_date: today(), payment_mode: 'Cash', transaction_ref: '', remarks: '',
  });
  const [result, setResult] = useState(null);
  const selected = assignments.find((a) => String(a.id) === form.values.assignment_id);

  const send = (confirmDuplicate) => form.submit((v) => post('/fees/payments', { ...v, confirm_duplicate: confirmDuplicate ? '1' : '0' }));
  const save = async (e) => {
    e.preventDefault();
    try {
      setResult(await send(false));
    } catch (err) {
      if (err.status === 409 && err.data?.duplicate) {
        const ok = await confirmDialog({
          title: 'Possible duplicate payment', message: err.message + (err.data.can_override
            ? ' Do you want to record it anyway? This override is logged.'
            : ' You can submit it for administrator review; it will not reduce the balance until approved.'),
          confirmText: err.data.can_override ? 'Record anyway' : 'Submit for review', danger: true,
        });
        if (!ok) return;
        try { setResult(await send(true)); } catch (e2) { if (e2.status !== 422) toastError(e2); }
      } else if (err.status !== 422) {
        toastError(err);
      }
    }
  };

  if (result) {
    const confirmed = result.status === 'confirmed';
    return html`<${Modal} title=${confirmed ? 'Payment recorded' : 'Sent for review'} onClose=${onDone} size="sm"
      footer=${html`<${Button} onClick=${onDone}>Close<//>${confirmed && html`<${Button} variant="primary" icon="print" onClick=${() => openPrint(`/print/receipt/${result.id}`)}>Print receipt<//>`}`}>
      <${Alert} type=${confirmed ? 'success' : 'warning'}>${result.message}<//>
    <//>`;
  }

  return html`<${Modal} title=${`Record Payment — ${student.full_name}`} onClose=${onClose}
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Record payment<//>`}>
    ${!open.length ? html`<${Alert} type="info">This student has no outstanding fees. Assign a fee first.<//>` : html`<form onSubmit=${save} novalidate class="form-grid">
      <${Select} form=${form} name="assignment_id" label="Fee" required className="span-2" placeholder=${false}
        options=${open.map((a) => ({ value: String(a.id), label: `${a.title} — balance ${money(a.balance)}` }))}
        onChange=${(e) => { const a = assignments.find((x) => String(x.id) === e.target.value); form.setValues((v) => ({ ...v, assignment_id: e.target.value, amount: a ? String(Number(a.balance)) : '' })); }} />
      ${selected && html`<div class="span-2 summary-strip">
        <span>Payable <strong>${money(selected.total_payable)}</strong></span>
        <span>Paid <strong>${money(selected.amount_paid)}</strong></span>
        <span>Balance <strong class="text-red">${money(selected.balance)}</strong></span>
      </div>`}
      <${MoneyInput} form=${form} name="amount" label="Amount" required max=${selected ? selected.balance : undefined}
        hint=${selected && form.values.amount && toPaise(form.values.amount) < toPaise(selected.balance) ? `Partial payment — ${money(fromPaise(toPaise(selected.balance) - toPaise(form.values.amount)))} will remain due` : ''} />
      <${Input} form=${form} name="payment_date" label="Payment date" type="date" required max=${today()} />
      <${Select} form=${form} name="payment_mode" label="Payment mode" required options=${MODES} placeholder=${false} />
      <${Input} form=${form} name="transaction_ref" label="Transaction reference" maxlength="100" required=${form.values.payment_mode !== 'Cash'}
        hint=${form.values.payment_mode === 'Cash' ? 'Optional for cash' : 'UTR / transaction ID / card approval code'} />
      <${Textarea} form=${form} name="remarks" label="Remarks" className="span-2" rows="2" maxlength="255" />
    </form>`}
  <//>`;
}

/* ================================================================== assign fee modal */
export function AssignFeeModal({ student, onClose, onDone }) {
  const auth = useAuth();
  const canConfigure = auth.can('fees.configure');
  const { data } = useApi('/fees/structures', { course_id: student.course_id, active: '1' });
  const form = useForm({ fee_structure_id: '', title: '', academic_year_id: String(student.academic_year_id), semester: String(student.current_semester),
    scholarship_amount: '', discount_amount: '', due_date: '', remarks: '', ...Object.fromEntries(COMPONENTS.map(([k]) => [k, ''])) });
  const meta = useMeta();
  const structures = data?.data || [];
  const fs = structures.find((s) => String(s.id) === form.values.fee_structure_id);

  useEffect(() => {
    if (fs) {
      form.setValues((v) => ({ ...v, ...Object.fromEntries(COMPONENTS.map(([k]) => [k, String(Number(fs[k]))])), due_date: fs.due_date || '' }));
    }
  }, [form.values.fee_structure_id]);
  useEffect(() => {
    if (!form.values.fee_structure_id && structures.length) {
      const best = structures.find((s) => Number(s.semester) === Number(student.current_semester) && Number(s.academic_year_id) === Number(student.academic_year_id)) || structures[0];
      form.setValues((v) => ({ ...v, fee_structure_id: String(best.id) }));
    }
  }, [structures.length]);

  const gross = sumPaise(COMPONENTS.map(([k]) => form.values[k]));
  const payable = gross - toPaise(form.values.scholarship_amount) - toPaise(form.values.discount_amount);
  const editable = (k) => canConfigure || k === 'transport_fee' || k === 'hostel_fee';

  const save = (e) => {
    e.preventDefault();
    const body = { ...form.values, student_id: student.id };
    if (!canConfigure) { delete body.scholarship_amount; delete body.discount_amount; }
    form.submit(() => post('/fees/assignments', body)).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };

  return html`<${Modal} title=${`Assign Fee — ${student.full_name}`} onClose=${onClose} size="lg"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Assign fee<//>`}>
    <form onSubmit=${save} novalidate>
      <div class="form-grid">
        <${Select} form=${form} name="fee_structure_id" label="Fee structure" className="span-2"
          placeholder=${canConfigure ? 'Custom fee (no structure)' : 'Select a structure'}
          options=${structures.map((s) => ({ value: String(s.id), label: `${s.academic_year} · Sem ${s.semester} · ${s.name} (${money(s.total_fee)})` }))} />
        ${!fs && canConfigure && html`
          <${Input} form=${form} name="title" label="Title" required className="span-2" maxlength="150" />
          <${Select} form=${form} name="academic_year_id" label="Academic year" required options=${meta.academic_years.map((a) => ({ value: String(a.id), label: a.name }))} />
          <${Input} form=${form} name="semester" label="Semester" type="number" min="1" max="16" required />`}
      </div>
      ${data && !structures.length && html`<${Alert} type="info">No active fee structures exist for ${student.course_name}. ${canConfigure ? 'Enter a custom fee below or create a structure.' : 'Ask an administrator to create one.'}<//>`}
      <h4 class="form-section">Fee components</h4>
      <div class="form-grid form-grid-4">
        ${COMPONENTS.map(([k, label]) => html`<${MoneyInput} key=${k} form=${form} name=${k} label=${label} disabled=${!editable(k)} />`)}
      </div>
      <h4 class="form-section">Concessions ${!canConfigure && html`<small class="muted">(administrator only)</small>`}</h4>
      <div class="form-grid form-grid-4">
        <${MoneyInput} form=${form} name="scholarship_amount" label="Scholarship" disabled=${!canConfigure} />
        <${MoneyInput} form=${form} name="discount_amount" label="Discount" disabled=${!canConfigure} />
        <${Input} form=${form} name="due_date" label="Due date" type="date" />
        <${Input} form=${form} name="remarks" label="Remarks" maxlength="255" />
      </div>
      <div class="summary-strip">
        <span>Gross <strong>${money(fromPaise(gross))}</strong></span>
        <span>Concession <strong>${money(fromPaise(gross - payable))}</strong></span>
        <span>Total payable <strong class=${payable < 0 ? 'text-red' : ''}>${money(fromPaise(payable))}</strong></span>
      </div>
    </form>
  <//>`;
}

/* ================================================================== edit assignment (admin) */
export function EditAssignmentModal({ assignment: a, onClose, onDone }) {
  const form = useForm({
    title: a.title, due_date: a.due_date || '', remarks: a.remarks || '',
    scholarship_amount: String(Number(a.scholarship_amount)), discount_amount: String(Number(a.discount_amount)),
    ...Object.fromEntries(COMPONENTS.map(([k]) => [k, String(Number(a[k]))])),
  });
  const gross = sumPaise(COMPONENTS.map(([k]) => form.values[k]));
  const payable = gross - toPaise(form.values.scholarship_amount) - toPaise(form.values.discount_amount);
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => post(`/fees/assignments/${a.id}`, v)).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title="Edit Fee & Concessions" onClose=${onClose} size="lg"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Save changes<//>`}>
    <${Alert} type="info">Already paid: <strong>${money(a.amount_paid)}</strong>. The new payable amount cannot be lower than this. All changes are audit-logged.<//>
    <form onSubmit=${save} novalidate>
      <div class="form-grid"><${Input} form=${form} name="title" label="Title" required className="span-2" /></div>
      <div class="form-grid form-grid-4">
        ${COMPONENTS.map(([k, label]) => html`<${MoneyInput} key=${k} form=${form} name=${k} label=${label} />`)}
        <${MoneyInput} form=${form} name="scholarship_amount" label="Scholarship" />
        <${MoneyInput} form=${form} name="discount_amount" label="Discount" />
        <${Input} form=${form} name="due_date" label="Due date" type="date" />
        <${Input} form=${form} name="remarks" label="Remarks" />
      </div>
      <div class="summary-strip">
        <span>Gross <strong>${money(fromPaise(gross))}</strong></span>
        <span>Payable <strong>${money(fromPaise(payable))}</strong></span>
        <span>New balance <strong>${money(fromPaise(payable - toPaise(a.amount_paid)))}</strong></span>
      </div>
    </form>
  <//>`;
}

/* ================================================================== collect fee page */
export function CollectFee({ query }) {
  const [search, setSearch] = useState('');
  const [studentId, setStudentId] = useState(query.student || null);
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [modal, setModal] = useState(null);
  const profile = useApi(studentId ? `/students/${studentId}` : null);

  useEffect(() => {
    if (search.trim().length < 2) { setResults([]); return undefined; }
    setSearching(true);
    const t = setTimeout(() => {
      api('/students', { params: { q: search, per_page: 8 } }).then((d) => setResults(d.data)).catch(toastError).finally(() => setSearching(false));
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  const s = profile.data?.student;
  const fees = profile.data?.fees;
  return html`<div>
    <${PageHeader} title="Collect Fee" subtitle="Find a student, choose the fee and record the payment." />
    <div class="collect-layout">
      <${Card} title="1. Find student">
        <${SearchBox} value=${search} onChange=${setSearch} placeholder="Type name or admission number…" />
        <div class="result-list">
          ${searching && html`<${Spinner} />`}
          ${!searching && search.length >= 2 && !results.length && html`<p class="muted small">No students found.</p>`}
          ${results.map((r) => html`<button key=${r.id} class=${'result-item' + (String(r.id) === String(studentId) ? ' active' : '')} onClick=${() => setStudentId(r.id)}>
            <${Avatar} name=${r.full_name} src=${photoUrl('students', r.id, Number(r.has_photo), r.updated_at)} size=${34} />
            <div class="cell-title">${r.full_name}<small>${r.admission_no} · ${r.course_code} · Sem ${r.current_semester}</small></div>
            <${Badge} value=${r.status} />
          </button>`)}
        </div>
      <//>
      <${Card} title="2. Outstanding fees">
        ${!studentId ? html`<${Empty} icon="search" title="No student selected" text="Search on the left to begin." />`
        : profile.loading && !s ? html`<${Spinner} />`
        : profile.error ? html`<${ErrorBox} error=${profile.error} />`
        : s && html`<div>
          <div class="cell-user mb">
            <${Avatar} name=${s.full_name} src=${photoUrl('students', s.id, Number(s.has_photo), s.updated_at)} size=${48} />
            <div class="cell-title"><a class="link" href=${`#/students/${s.id}`}>${s.full_name}</a><small>${s.admission_no} · ${s.course_name}</small></div>
          </div>
          <div class="summary-strip">
            <span>Payable <strong>${money(fees.summary.total_payable)}</strong></span>
            <span>Paid <strong>${money(fees.summary.total_paid)}</strong></span>
            <span>Outstanding <strong class="text-red">${money(fees.summary.outstanding)}</strong></span>
          </div>
          <${DataTable} rows=${fees.assignments} empty="No fees assigned to this student" columns=${[
            { key: 'title', label: 'Fee', render: (a) => html`<div class="cell-title">${a.title}<small>Due ${fmtDate(a.due_date)}</small></div>` },
            { key: 'total_payable', label: 'Payable', type: 'money' },
            { key: 'balance', label: 'Balance', type: 'money' },
            { key: 'act', label: '', render: (a) => Number(a.balance) > 0
              ? html`<${Button} size="sm" variant="primary" onClick=${() => setModal(a)}>Pay<//>` : html`<${Badge} value="cleared" tone="green" />` },
          ]} />
          ${fees.payments.length > 0 && html`<h4 class="form-section">Recent payments</h4>
            <ul class="mini-list">${fees.payments.slice(0, 5).map((p) => html`<li key=${p.id}>
              <span>${fmtDate(p.payment_date)} · ${p.receipt_no || 'pending'} <${Badge} value=${p.status} /></span>
              <span class="num">${money(p.amount)} ${p.status === 'confirmed' && html`<${IconButton} icon="print" title="Print receipt" onClick=${() => openPrint(`/print/receipt/${p.id}`)} />`}</span>
            </li>`)}</ul>`}
        </div>`}
      <//>
    </div>
    ${modal && s && html`<${PaymentModal} student=${s} assignments=${fees.assignments} preselect=${modal}
      onClose=${() => setModal(null)} onDone=${() => { setModal(null); profile.reload(); }} />`}
  </div>`;
}

/* ================================================================== payments list */
export function Payments({ query }) {
  const auth = useAuth();
  const meta = useMeta();
  const list = useList('/fees/payments', { from: '', to: '', mode: '', status: query.status || '', course_id: '' }, { sort: 'id', dir: 'desc' });
  const f = list.filters;
  const totals = list.data?.totals;
  return html`<div>
    <${PageHeader} title="Fee Payments" subtitle="Complete payment history with receipts.">
      <${Button} icon="download" onClick=${() => download('/fees/payments/export', list.params)}>Export CSV<//>
      ${auth.can('fees.collect') && html`<a class="btn btn-primary" href="#/fees/collect">+ Collect Fee</a>`}
    <//>
    ${list.data?.pending_review > 0 && html`<${Alert} type="warning">${list.data.pending_review} payment(s) are awaiting administrator review because of duplicate transaction references.
      ${f.status !== 'pending_review' && html` <button class="link" onClick=${() => list.setFilter('status', 'pending_review')}>Show them</button>`}<//>`}
    <${Card} pad=${false}>
      <${Toolbar}>
        <${SearchBox} value=${list.search} onChange=${list.setSearch} placeholder="Student name or ID, full receipt no. or reference…" />
        <label class="inline-field">From <input class="input input-sm" type="date" value=${f.from} onChange=${(e) => list.setFilter('from', e.target.value)} /></label>
        <label class="inline-field">To <input class="input input-sm" type="date" value=${f.to} onChange=${(e) => list.setFilter('to', e.target.value)} /></label>
        <${FilterSelect} placeholder="All modes" value=${f.mode} onChange=${(v) => list.setFilter('mode', v)} options=${MODES} />
        <${FilterSelect} placeholder="All courses" value=${f.course_id} onChange=${(v) => list.setFilter('course_id', v)} options=${meta.courses.map((c) => ({ value: c.id, label: c.code }))} />
        <${FilterSelect} placeholder="All statuses" value=${f.status} onChange=${(v) => list.setFilter('status', v)}
          options=${[['confirmed', 'Confirmed'], ['pending_review', 'Pending review'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled']].map(([value, label]) => ({ value, label }))} />
        <${Button} variant="ghost" size="sm" onClick=${list.resetFilters}>Reset<//>
      <//>
      ${totals && html`<div class="totals-bar"><span>${totals.count} payment(s)</span><span>Confirmed total: <strong>${money(totals.confirmed_total)}</strong></span></div>`}
      ${list.error && html`<${ErrorBox} error=${list.error} onRetry=${list.reload} />`}
      <${DataTable} rows=${list.rows} loading=${list.loading} sort=${list.sort} onSort=${list.toggleSort} columns=${[
        { key: 'receipt_no', label: 'Receipt', sort: 'receipt_no', render: (r) => r.receipt_no || html`<em class="muted">—</em>` },
        { key: 'payment_date', label: 'Date', sort: 'payment_date', type: 'date' },
        { key: 'student_name', label: 'Student', sort: 'student_name', render: (r) => html`<a class="cell-title link" href=${`#/students/${r.student_id}`}>${r.student_name}<small>${r.admission_no} · ${r.assignment_title}</small></a>` },
        { key: 'payment_mode', label: 'Mode', sort: 'payment_mode', render: (r) => html`<div class="cell-title">${r.payment_mode}<small>${r.transaction_ref || ''}</small></div>` },
        { key: 'amount', label: 'Amount', sort: 'amount', type: 'money' },
        { key: 'status', label: 'Status', sort: 'status', render: (r) => html`<span title=${r.review_reason || r.review_note || ''}><${Badge} value=${r.status} /></span>` },
        { key: 'recorded_by_name', label: 'By', className: 'hide-md' },
        { key: 'act', label: '', render: (r) => paymentActions(r, auth, list.reload) },
      ]} />
      <${Pagination} meta=${list.meta} onPage=${list.setPage} />
    <//>
  </div>`;
}

/* ================================================================== dues */
export function Dues() {
  const meta = useMeta();
  const auth = useAuth();
  const list = useList('/fees/dues', { course_id: '', academic_year_id: '', semester: '', overdue: '' }, { sort: 'balance', dir: 'desc' });
  const f = list.filters;
  const t = list.data?.totals;
  return html`<div>
    <${PageHeader} title="Pending Dues" subtitle="Students with outstanding balances (Total Payable − Total Paid).">
      <${Button} icon="download" onClick=${() => download('/fees/dues/export', list.params)}>Export CSV<//>
    <//>
    ${t && html`<div class="stat-grid stat-grid-3">
      <${StatCard} label="Total Outstanding" value=${money(t.outstanding)} icon="alert" tone="red" />
      <${StatCard} label="Students with Dues" value=${t.students} icon="students" />
      <${StatCard} label="Open Fee Items" value=${t.count} icon="fees" tone="amber" />
    </div>`}
    <${Card} pad=${false}>
      <${Toolbar}>
        <${SearchBox} value=${list.search} onChange=${list.setSearch} placeholder="Student name or ID…" />
        <${FilterSelect} placeholder="All courses" value=${f.course_id} onChange=${(v) => list.setFilter('course_id', v)} options=${meta.courses.map((c) => ({ value: c.id, label: c.code }))} />
        <${FilterSelect} placeholder="All years" value=${f.academic_year_id} onChange=${(v) => list.setFilter('academic_year_id', v)} options=${meta.academic_years.map((a) => ({ value: a.id, label: a.name }))} />
        <${FilterSelect} placeholder="All semesters" value=${f.semester} onChange=${(v) => list.setFilter('semester', v)} options=${Array.from({ length: 10 }, (_, i) => ({ value: i + 1, label: `Semester ${i + 1}` }))} />
        <label class="check-field"><input type="checkbox" checked=${f.overdue === '1'} onChange=${(e) => list.setFilter('overdue', e.target.checked ? '1' : '')} /> Overdue only</label>
      <//>
      ${list.error && html`<${ErrorBox} error=${list.error} onRetry=${list.reload} />`}
      <${DataTable} rows=${list.rows} rowKey="assignment_id" loading=${list.loading} sort=${list.sort} onSort=${list.toggleSort} empty="No pending dues 🎉" columns=${[
        { key: 'student_name', label: 'Student', sort: 'student_name', render: (r) => html`<a class="cell-title link" href=${`#/students/${r.student_id}`}>${r.student_name}<small>${r.admission_no} · ${r.parent_contact_number || r.contact_number || ''}</small></a>` },
        { key: 'course_name', label: 'Course', sort: 'course', render: (r) => html`<div class="cell-title">${r.course_name}<small>${r.academic_year} · Sem ${r.semester}</small></div>` },
        { key: 'title', label: 'Fee', className: 'hide-md' },
        { key: 'total_payable', label: 'Payable', sort: 'total_payable', type: 'money', className: 'hide-md' },
        { key: 'amount_paid', label: 'Paid', type: 'money' },
        { key: 'balance', label: 'Outstanding', sort: 'balance', type: 'money', render: (r) => html`<strong class="text-red">${money(r.balance)}</strong>` },
        { key: 'due_date', label: 'Due', sort: 'due_date', render: (r) => html`${fmtDate(r.due_date)}${Number(r.is_overdue) ? html`<br /><${Badge} value=${`${r.days_overdue}d overdue`} tone="red" />` : ''}` },
        { key: 'act', label: '', render: (r) => auth.can('fees.collect') && html`<a class="btn btn-sm btn-primary" href=${`#/fees/collect?student=${r.student_id}`}>Collect</a>` },
      ]} />
      <${Pagination} meta=${list.meta} onPage=${list.setPage} />
    <//>
  </div>`;
}

/* ================================================================== fee structures */
export function FeeStructures() {
  const auth = useAuth();
  const meta = useMeta();
  const [filters, setFilters] = useState({ course_id: '', academic_year_id: '' });
  const { data, loading, error, reload } = useApi('/fees/structures', filters);
  const [edit, setEdit] = useState(null);
  const canConfigure = auth.can('fees.configure');
  const rows = data?.data || [];

  const bulk = async (s) => {
    if (!(await confirmDialog({ title: 'Assign to all students?', message: `Assign "${s.name}" (${money(s.total_fee)}) to every active ${s.course_code} student in semester ${s.semester} of ${s.academic_year} who doesn't already have it?`, confirmText: 'Assign' }))) return;
    post('/fees/assignments/bulk', { fee_structure_id: s.id }).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  const remove = async (s) => {
    if (!(await confirmDialog({ title: 'Delete fee structure?', message: `Delete "${s.name}" for ${s.course_code}?`, danger: true, confirmText: 'Delete' }))) return;
    post(`/fees/structures/${s.id}/delete`).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };

  return html`<div>
    <${PageHeader} title="Fee Structures" subtitle="Semester-wise fee templates per course and academic year.">
      ${canConfigure && html`<${Button} variant="primary" icon="plus" onClick=${() => setEdit({})}>New Structure<//>`}
    <//>
    <${Card} pad=${false}>
      <${Toolbar}>
        <${FilterSelect} placeholder="All courses" value=${filters.course_id} onChange=${(v) => setFilters((f) => ({ ...f, course_id: v }))} options=${meta.courses.map((c) => ({ value: c.id, label: c.name }))} />
        <${FilterSelect} placeholder="All years" value=${filters.academic_year_id} onChange=${(v) => setFilters((f) => ({ ...f, academic_year_id: v }))} options=${meta.academic_years.map((a) => ({ value: a.id, label: a.name }))} />
      <//>
      ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
      <${DataTable} rows=${rows} loading=${loading} columns=${[
        { key: 'name', label: 'Structure', render: (s) => html`<div class="cell-title">${s.name}<small>${s.course_name}</small></div>` },
        { key: 'academic_year', label: 'Year' },
        { key: 'semester', label: 'Sem' },
        { key: 'tuition_fee', label: 'Tuition', type: 'money', className: 'hide-md' },
        { key: 'total_fee', label: 'Total Fee', type: 'money', render: (s) => html`<strong>${money(s.total_fee)}</strong>` },
        { key: 'due_date', label: 'Due', type: 'date', className: 'hide-md' },
        { key: 'assigned_count', label: 'Students', type: 'int' },
        { key: 'is_active', label: 'Status', render: (s) => html`<${Badge} value=${Number(s.is_active) ? 'Active' : 'Inactive'} />` },
        { key: 'act', label: '', render: (s) => canConfigure && html`<div class="row-actions">
          <${IconButton} icon="students" title="Assign to all matching students" onClick=${() => bulk(s)} />
          <${IconButton} icon="edit" title="Edit" onClick=${() => setEdit(s)} />
          ${Number(s.assigned_count) === 0 && html`<${IconButton} icon="trash" tone="danger" title="Delete" onClick=${() => remove(s)} />`}
        </div>` },
      ]} />
    <//>
    ${edit && html`<${StructureModal} structure=${edit} meta=${meta} onClose=${() => setEdit(null)} onDone=${() => { setEdit(null); reload(); }} />`}
  </div>`;
}

function StructureModal({ structure: s, meta, onClose, onDone }) {
  const cur = meta.academic_years.find((a) => Number(a.is_current));
  const form = useForm({
    name: s.name || 'Semester Fee', course_id: s.course_id ? String(s.course_id) : '', academic_year_id: s.academic_year_id ? String(s.academic_year_id) : (cur ? String(cur.id) : ''),
    semester: s.semester ? String(s.semester) : '1', due_date: s.due_date || '', is_active: s.id ? String(s.is_active) : '1',
    ...Object.fromEntries(COMPONENTS.map(([k]) => [k, s[k] !== undefined ? String(Number(s[k])) : ''])),
  });
  const total = sumPaise(COMPONENTS.map(([k]) => form.values[k]));
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => post(s.id ? `/fees/structures/${s.id}` : '/fees/structures', v)).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title=${s.id ? 'Edit Fee Structure' : 'New Fee Structure'} onClose=${onClose} size="lg"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Save<//>`}>
    ${s.id && Number(s.assigned_count) > 0 && html`<${Alert} type="info">Already assigned to ${s.assigned_count} student(s). Changes apply to new assignments only.<//>`}
    <form onSubmit=${save} novalidate>
      <div class="form-grid">
        <${Input} form=${form} name="name" label="Name" required maxlength="150" />
        <${Select} form=${form} name="course_id" label="Course" required options=${meta.courses.map((c) => ({ value: String(c.id), label: c.name }))} />
        <${Select} form=${form} name="academic_year_id" label="Academic year" required options=${meta.academic_years.map((a) => ({ value: String(a.id), label: a.name }))} />
        <${Input} form=${form} name="semester" label="Semester" type="number" min="1" max="16" required />
        <${Input} form=${form} name="due_date" label="Due date" type="date" />
        <${Select} form=${form} name="is_active" label="Status" placeholder=${false} options=${[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]} />
      </div>
      <h4 class="form-section">Components</h4>
      <div class="form-grid form-grid-4">${COMPONENTS.map(([k, label]) => html`<${MoneyInput} key=${k} form=${form} name=${k} label=${label} />`)}</div>
      <div class="summary-strip"><span>Total course fee for this semester <strong>${money(fromPaise(total))}</strong></span></div>
    </form>
  <//>`;
}
