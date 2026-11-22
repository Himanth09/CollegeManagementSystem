import { html, useState, useApi, useForm, post, toast, toastError, confirmDialog, download, openPrint, money, fmtDate, fmtMonth, today, thisMonth } from '../lib.js';
import { PageHeader, Card, DataTable, Toolbar, SearchBox, Button, IconButton, Badge, Modal, Input, Select, MoneyInput, Spinner, ErrorBox, Alert, StatCard, Empty, Tabs } from '../components.js';

const EARN = [['basic_salary', 'Basic Salary'], ['hra', 'House Rent Allowance'], ['da', 'Dearness Allowance'], ['ta', 'Travel Allowance'], ['other_allowances', 'Other Allowances']];
const DED = [['pf_deduction', 'Provident Fund'], ['professional_tax', 'Professional Tax'], ['other_deductions', 'Other Deductions']];
const PAY_MODES = ['Bank Transfer', 'Cheque', 'Cash', 'UPI'].map((m) => ({ value: m, label: m }));
const p = (v) => Math.round(Number(v || 0) * 100);
const fromP = (x) => (x / 100).toFixed(2);

function Confidential() {
  return html`<div class="confidential"><strong>Confidential</strong> — visible to Super Admin only. All views and changes are audit-logged.</div>`;
}

/* ================================================================== structures */
export function SalaryStructures() {
  const [q, setQ] = useState('');
  const { data, loading, error, reload } = useApi('/salary/structures', { q });
  const [edit, setEdit] = useState(null);
  const [history, setHistory] = useState(null);
  const rows = data?.data || [];
  return html`<div>
    <${PageHeader} title="Salary Structures" subtitle="Current pay structure for each employee." />
    <${Confidential} />
    ${data && html`<div class="stat-grid stat-grid-3">
      <${StatCard} label="Employees on payroll" value=${data.totals.count} icon="staff" />
      <${StatCard} label="Monthly gross" value=${money(data.totals.gross)} icon="salary" tone="amber" />
      <${StatCard} label="Monthly net" value=${money(data.totals.net)} icon="check" tone="green" />
    </div>`}
    <${Card} pad=${false}>
      <${Toolbar}><${SearchBox} value=${q} onChange=${setQ} placeholder="Search employee…" /><//>
      ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
      <${DataTable} rows=${rows} rowKey="staff_id" loading=${loading} columns=${[
        { key: 'full_name', label: 'Employee', render: (r) => html`<div class="cell-title">${r.full_name}<small>${r.employee_id} · ${r.designation}</small></div>` },
        { key: 'department_name', label: 'Department', className: 'hide-md' },
        { key: 'basic_salary', label: 'Basic', type: 'money', className: 'hide-md' },
        { key: 'gross_salary', label: 'Gross', type: 'money' },
        { key: 'total_deductions', label: 'Deductions', type: 'money', className: 'hide-md' },
        { key: 'net_salary', label: 'Net', type: 'money', render: (r) => (r.structure_id ? html`<strong>${money(r.net_salary)}</strong>` : html`<${Badge} value="Not set" tone="amber" />`) },
        { key: 'effective_date', label: 'Effective', type: 'date', className: 'hide-md' },
        { key: 'act', label: '', render: (r) => html`<div class="row-actions">
          <${Button} size="sm" onClick=${() => setEdit(r)}>${r.structure_id ? 'Revise' : 'Set salary'}<//>
          ${Number(r.revision_count) > 0 && html`<${IconButton} icon="clock" title="Revision history" onClick=${() => setHistory(r)} />`}
        </div>` },
      ]} />
    <//>
    ${edit && html`<${StructureModal} row=${edit} onClose=${() => setEdit(null)} onDone=${() => { setEdit(null); reload(); }} />`}
    ${history && html`<${HistoryModal} row=${history} onClose=${() => setHistory(null)} />`}
  </div>`;
}

function StructureModal({ row, onClose, onDone }) {
  const form = useForm({
    staff_id: row.staff_id, effective_date: today(), change_reason: '',
    ...Object.fromEntries([...EARN, ...DED].map(([k]) => [k, row.structure_id ? String(Number(row[k])) : ''])),
  });
  const gross = EARN.reduce((t, [k]) => t + p(form.values[k]), 0);
  const ded = DED.reduce((t, [k]) => t + p(form.values[k]), 0);
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => post('/salary/structures', v)).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title=${`${row.structure_id ? 'Revise' : 'Set'} Salary — ${row.full_name}`} onClose=${onClose} size="lg"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Save structure<//>`}>
    ${row.structure_id && html`<${Alert} type="info">Current net salary ${money(row.net_salary)} effective ${fmtDate(row.effective_date)}. Saving creates a new version; history is preserved.<//>`}
    <form onSubmit=${save} novalidate>
      <h4 class="form-section">Earnings</h4>
      <div class="form-grid form-grid-3">${EARN.map(([k, l]) => html`<${MoneyInput} key=${k} form=${form} name=${k} label=${l} required=${k === 'basic_salary'} />`)}</div>
      <h4 class="form-section">Deductions</h4>
      <div class="form-grid form-grid-3">${DED.map(([k, l]) => html`<${MoneyInput} key=${k} form=${form} name=${k} label=${l} />`)}</div>
      <div class="form-grid">
        <${Input} form=${form} name="effective_date" label="Effective date" type="date" required />
        <${Input} form=${form} name="change_reason" label="Reason for change" maxlength="255" placeholder="e.g. Annual increment" />
      </div>
      <div class="summary-strip">
        <span>Gross <strong>${money(fromP(gross))}</strong></span>
        <span>Deductions <strong>${money(fromP(ded))}</strong></span>
        <span>Net salary <strong class=${gross - ded < 0 ? 'text-red' : 'text-green'}>${money(fromP(gross - ded))}</strong></span>
      </div>
    </form>
  <//>`;
}

function HistoryModal({ row, onClose }) {
  const { data, loading, error } = useApi(`/salary/history/${row.staff_id}`);
  const [tab, setTab] = useState('revisions');
  return html`<${Modal} title=${`Salary History — ${row.full_name}`} onClose=${onClose} size="lg">
    ${loading ? html`<${Spinner} />` : error ? html`<${ErrorBox} error=${error} />` : html`<div>
      <${Tabs} active=${tab} onChange=${setTab} tabs=${[{ key: 'revisions', label: 'Revisions' }, { key: 'structures', label: 'All structures' }]} />
      ${tab === 'revisions' ? html`<${DataTable} rows=${data.revisions} columns=${[
        { key: 'effective_date', label: 'Effective', type: 'date' },
        { key: 'previous_net', label: 'Previous net', type: 'money' },
        { key: 'new_net', label: 'New net', type: 'money' },
        { key: 'change', label: 'Change', align: 'right', render: (r) => (r.previous_net === null ? '-' : html`<span class=${Number(r.new_net) >= Number(r.previous_net) ? 'text-green' : 'text-red'}>${money(Number(r.new_net) - Number(r.previous_net))}</span>`) },
        { key: 'change_reason', label: 'Reason' },
        { key: 'revised_by_name', label: 'By' },
      ]} />` : html`<${DataTable} rows=${data.structures} columns=${[
        { key: 'effective_date', label: 'Effective', type: 'date' },
        { key: 'basic_salary', label: 'Basic', type: 'money' },
        { key: 'gross_salary', label: 'Gross', type: 'money' },
        { key: 'total_deductions', label: 'Deductions', type: 'money' },
        { key: 'net_salary', label: 'Net', type: 'money' },
        { key: 'is_current', label: '', render: (r) => (Number(r.is_current) ? html`<${Badge} value="Current" tone="green" />` : '') },
      ]} />`}
    </div>`}
  <//>`;
}

/* ================================================================== payroll */
export function Payroll() {
  const [month, setMonth] = useState(thisMonth());
  const { data, loading, error, reload } = useApi('/salary/payroll', { month });
  const [pay, setPay] = useState(null);
  const rows = data?.data || [];
  const s = data?.summary;

  const generate = async () => {
    if (!(await confirmDialog({ title: 'Generate payroll?', message: `Create pending salary entries for ${fmtMonth(month)} using each employee's current salary structure?`, confirmText: 'Generate' }))) return;
    post('/salary/payroll/generate', { month }).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };
  const removePending = async (r) => {
    if (!(await confirmDialog({ title: 'Remove entry?', message: `Remove the pending salary entry for ${r.full_name}? You can regenerate it afterwards.`, danger: true, confirmText: 'Remove' }))) return;
    post(`/salary/payments/${r.payment_id}/delete`).then((d) => { toast(d.message); reload(); }).catch(toastError);
  };

  return html`<div>
    <${PageHeader} title="Monthly Payroll" subtitle=${fmtMonth(month)}>
      <input class="input input-sm month-input" type="month" value=${month} onChange=${(e) => e.target.value && setMonth(e.target.value)} aria-label="Payroll month" />
      <${Button} icon="download" onClick=${() => download('/salary/payroll/export', { month })}>Export<//>
      ${s && s.not_generated > 0 && html`<${Button} icon="refresh" onClick=${generate}>Generate payroll<//>`}
      ${s && s.pending_count > 0 && html`<${Button} variant="primary" icon="check" onClick=${() => setPay({ all: true })}>Pay all pending<//>`}
    <//>
    <${Confidential} />
    ${s && html`<div class="stat-grid">
      <${StatCard} label="Paid" value=${money(s.paid)} icon="check" tone="green" hint=${`${s.paid_count} employee(s)`} />
      <${StatCard} label="Pending" value=${money(s.pending)} icon="clock" tone="amber" hint=${`${s.pending_count} employee(s)`} />
      <${StatCard} label="Not generated" value=${s.not_generated} icon="alert" tone=${s.not_generated ? 'red' : 'sky'} hint="Eligible staff without an entry" />
      <${StatCard} label="Total payroll" value=${money(Number(s.paid) + Number(s.pending))} icon="salary" />
    </div>`}
    <${Card} pad=${false}>
      ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
      <${DataTable} rows=${rows} rowKey="staff_id" loading=${loading} empty="No employees with a salary structure" columns=${[
        { key: 'full_name', label: 'Employee', render: (r) => html`<div class="cell-title">${r.full_name}<small>${r.employee_id} · ${r.designation}</small></div>` },
        { key: 'department_name', label: 'Department', className: 'hide-md' },
        { key: 'gross_salary', label: 'Gross', type: 'money' },
        { key: 'total_deductions', label: 'Deductions', type: 'money', className: 'hide-md' },
        { key: 'net_salary', label: 'Net', type: 'money', render: (r) => html`<strong>${money(r.net_salary)}</strong>` },
        { key: 'status', label: 'Status', render: (r) => html`<${Badge} value=${r.status} />${r.paid_date ? html`<small class="muted block">${fmtDate(r.paid_date)} · ${r.payment_mode}</small>` : ''}` },
        { key: 'act', label: '', render: (r) => html`<div class="row-actions">
          ${r.status === 'pending' && html`<${Button} size="sm" variant="primary" onClick=${() => setPay(r)}>Mark paid<//>
            <${IconButton} icon="trash" tone="danger" title="Remove pending entry" onClick=${() => removePending(r)} />`}
          ${r.payment_id && html`<${IconButton} icon="print" title="Salary slip" onClick=${() => openPrint(`/print/salary-slip/${r.payment_id}`)} />`}
        </div>` },
      ]} />
    <//>
    ${pay && html`<${PayModal} row=${pay} month=${month} count=${s?.pending_count} total=${s?.pending} onClose=${() => setPay(null)} onDone=${() => { setPay(null); reload(); }} />`}
  </div>`;
}

function PayModal({ row, month, count, total, onClose, onDone }) {
  const form = useForm({ paid_date: today(), payment_mode: 'Bank Transfer', transaction_ref: '', remarks: '' });
  const save = (e) => {
    e.preventDefault();
    form.submit((v) => (row.all ? post('/salary/payroll/pay-all', { ...v, month }) : post(`/salary/payments/${row.payment_id}/pay`, v))).then((d) => { toast(d.message); onDone(); }).catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Modal} title=${row.all ? `Pay all pending — ${fmtMonth(month)}` : `Mark salary paid — ${row.full_name}`} onClose=${onClose} size="sm"
    footer=${html`<${Button} onClick=${onClose}>Cancel<//><${Button} variant="primary" busy=${form.busy} onClick=${save}>Confirm payment<//>`}>
    <${Alert} type="info">${row.all ? html`${count} employee(s), total net <strong>${money(total)}</strong>.` : html`Net salary <strong>${money(row.net_salary)}</strong> for ${fmtMonth(month)}.`}<//>
    <form onSubmit=${save} novalidate class="stack">
      <${Input} form=${form} name="paid_date" label="Paid date" type="date" required max=${today()} />
      <${Select} form=${form} name="payment_mode" label="Payment mode" required options=${PAY_MODES} placeholder=${false} />
      <${Input} form=${form} name="transaction_ref" label=${row.all ? 'Batch reference' : 'Transaction reference'} required=${form.values.payment_mode !== 'Cash'} maxlength="100" />
      ${!row.all && html`<${Input} form=${form} name="remarks" label="Remarks" maxlength="255" />`}
    </form>
  <//>`;
}
