import { html, useState, useApi, useAuth, useMeta, download, today, thisMonth } from '../lib.js';
import { PageHeader, Card, DataTable, Button, Spinner, ErrorBox, Empty, renderCell, Icon } from '../components.js';
import { STAFF_STATUSES } from './staff.js';

const yearAgo = () => { const d = new Date(); d.setMonth(d.getMonth() - 11, 1); return d.toISOString().slice(0, 8) + '01'; };
const monthsAgo = (n) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - n); return d.toISOString().slice(0, 7); };

const REPORTS = [
  { key: 'admissions', label: 'Student Admissions', perm: 'reports.students', icon: 'students', filters: ['from', 'to', 'course_id', 'academic_year_id'], desc: 'Students admitted within a date range.' },
  { key: 'fee-collection', label: 'Fee Collection', perm: 'reports.fees', icon: 'fees', filters: ['from', 'to', 'course_id', 'mode'], desc: 'Confirmed fee receipts within a date range.' },
  { key: 'pending-dues', label: 'Pending Dues', perm: 'reports.fees', icon: 'alert', filters: ['course_id', 'academic_year_id', 'semester'], desc: 'All outstanding balances.' },
  { key: 'overdue', label: 'Overdue Fees', perm: 'reports.fees', icon: 'clock', filters: ['course_id', 'academic_year_id', 'semester'], desc: 'Balances past their due date.' },
  { key: 'course-financial', label: 'Course-wise Financial', perm: 'reports.fees', icon: 'reports', filters: ['academic_year_id'], desc: 'Payable, collected and outstanding per course.' },
  { key: 'semester-collection', label: 'Semester-wise Collection', perm: 'reports.fees', icon: 'layers', filters: ['academic_year_id'], desc: 'Collection per course and semester.' },
  { key: 'staff', label: 'Staff Information', perm: 'reports.staff', icon: 'staff', filters: ['department_id', 'status'], desc: 'Employee directory by department.' },
  { key: 'salary-expenditure', label: 'Salary Expenditure', perm: 'admin', icon: 'salary', filters: ['from_month', 'to_month'], desc: 'Monthly payroll totals (Super Admin).' },
  { key: 'monthly-summary', label: 'Monthly Financial Summary', perm: 'admin', icon: 'trend', filters: ['from_month', 'to_month'], desc: 'Fee income vs salary expense per month (Super Admin).' },
];

export function Reports({ query }) {
  const auth = useAuth();
  const allowed = REPORTS.filter((r) => (r.perm === 'admin' ? auth.isAdmin : auth.can(r.perm)));
  const [active, setActive] = useState(allowed.find((r) => r.key === query.type)?.key || allowed[0]?.key);
  if (!allowed.length) return html`<${Empty} icon="shield" title="No reports available" text="Ask your administrator for report access." />`;
  const report = allowed.find((r) => r.key === active);
  return html`<div>
    <${PageHeader} title="Reports" subtitle="Generate, print and export management reports." />
    <div class="report-picker">
      ${allowed.map((r) => html`<button key=${r.key} class=${'report-tile' + (r.key === active ? ' active' : '')} onClick=${() => setActive(r.key)}>
        <${Icon} name=${r.icon} size=${20} /><span><strong>${r.label}</strong><small>${r.desc}</small></span></button>`)}
    </div>
    <${ReportView} key=${report.key} report=${report} />
  </div>`;
}

function ReportView({ report }) {
  const meta = useMeta();
  const [filters, setFilters] = useState({ from: yearAgo(), to: today(), from_month: monthsAgo(11), to_month: thisMonth(), course_id: '', academic_year_id: '', semester: '', department_id: '', status: '', mode: '' });
  const params = Object.fromEntries(report.filters.map((k) => [k, filters[k]]));
  const { data, loading, error, reload } = useApi(`/reports/${report.key}`, params);
  const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value }));
  const F = {
    from: html`<label class="inline-field">From <input class="input input-sm" type="date" value=${filters.from} onChange=${set('from')} /></label>`,
    to: html`<label class="inline-field">To <input class="input input-sm" type="date" value=${filters.to} onChange=${set('to')} /></label>`,
    from_month: html`<label class="inline-field">From <input class="input input-sm" type="month" value=${filters.from_month} onChange=${set('from_month')} /></label>`,
    to_month: html`<label class="inline-field">To <input class="input input-sm" type="month" value=${filters.to_month} onChange=${set('to_month')} /></label>`,
    course_id: html`<select class="input input-sm" value=${filters.course_id} onChange=${set('course_id')} aria-label="Course"><option value="">All courses</option>${meta.courses.map((c) => html`<option key=${c.id} value=${c.id}>${c.code}</option>`)}</select>`,
    academic_year_id: html`<select class="input input-sm" value=${filters.academic_year_id} onChange=${set('academic_year_id')} aria-label="Academic year"><option value="">All years</option>${meta.academic_years.map((a) => html`<option key=${a.id} value=${a.id}>${a.name}</option>`)}</select>`,
    semester: html`<select class="input input-sm" value=${filters.semester} onChange=${set('semester')} aria-label="Semester"><option value="">All semesters</option>${Array.from({ length: 10 }, (_, i) => html`<option key=${i} value=${i + 1}>Semester ${i + 1}</option>`)}</select>`,
    department_id: html`<select class="input input-sm" value=${filters.department_id} onChange=${set('department_id')} aria-label="Department"><option value="">All departments</option>${meta.departments.map((d) => html`<option key=${d.id} value=${d.id}>${d.name}</option>`)}</select>`,
    status: html`<select class="input input-sm" value=${filters.status} onChange=${set('status')} aria-label="Status"><option value="">All statuses</option>${STAFF_STATUSES.map((s) => html`<option key=${s.value} value=${s.value}>${s.label}</option>`)}</select>`,
    mode: html`<select class="input input-sm" value=${filters.mode} onChange=${set('mode')} aria-label="Mode"><option value="">All modes</option>${['Cash', 'UPI', 'Bank Transfer', 'Card'].map((m) => html`<option key=${m} value=${m}>${m}</option>`)}</select>`,
  };
  const cols = data?.columns || [];
  const totals = data?.totals || {};
  const hasTotals = Object.keys(totals).length > 0;
  return html`<${Card} title=${data?.title || report.label} className="report-card" pad=${false}
    actions=${html`<${Button} size="sm" icon="print" onClick=${() => window.print()}>Print<//><${Button} size="sm" icon="download" variant="primary" onClick=${() => download(`/reports/${report.key}`, { ...params, format: 'csv' })}>CSV<//>`}>
    <div class="toolbar no-print">${report.filters.map((k) => html`<span key=${k}>${F[k]}</span>`)}</div>
    ${error && html`<${ErrorBox} error=${error} onRetry=${reload} />`}
    ${loading && !data ? html`<${Spinner} />` : data && html`<${DataTable} rows=${data.rows.map((r, i) => ({ ...r, __i: i }))} rowKey="__i" loading=${loading} empty="No data for the selected filters"
      columns=${cols.map((c) => ({ key: c.key, label: c.label, type: c.type, align: ['money', 'int', 'percent'].includes(c.type) ? 'right' : undefined }))}
      footer=${hasTotals && data.rows.length > 0 && html`<tr>${cols.map((c, i) => html`<td key=${c.key} class=${['money', 'int', 'percent'].includes(c.type) ? 'r num' : ''}>
        ${totals[c.key] !== undefined ? renderCell(c.type, totals[c.key]) : i === 0 ? 'TOTAL' : ''}</td>`)}</tr>`} />`}
  <//>`;
}
