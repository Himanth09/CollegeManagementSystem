import { html, useApi, useAuth, money, moneyShort, num, fmtDate } from '../lib.js';
import { PageHeader, StatCard, Card, ChartBox, Spinner, ErrorBox, DataTable, Badge, Empty, Alert, Avatar, DetailList, photoUrl } from '../components.js';

export function Dashboard() {
  const auth = useAuth();
  const { data, loading, error, reload } = useApi('/dashboard');
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  if (loading) return html`<${Spinner} />`;
  if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
  const { cards, charts, lists } = data;
  const hasAny = Object.keys(cards).length > 0;

  return html`<div>
    <${PageHeader} title=${`${greet}, ${auth.user.full_name.replace(/^(Dr|Mr|Ms|Mrs|Prof)\.?\s+/i, '').split(' ')[0]}`}
      subtitle=${new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}>
      ${auth.can('fees.collect') && html`<a class="btn btn-primary" href="#/fees/collect">+ Collect Fee</a>`}
      ${auth.can('students.manage') && html`<a class="btn" href="#/students/new">+ Add Student</a>`}
    <//>

    ${cards.pending_review > 0 && auth.can('fees.review') && html`<${Alert} type="warning">
      <strong>${cards.pending_review} payment(s)</strong> with duplicate transaction references are awaiting your review.
      <a class="link" href="#/fees/payments?status=pending_review"> Review now →</a><//>`}

    ${!hasAny && html`<${StaffWelcome} />`}

    ${hasAny && html`<div class="stat-grid">
      ${cards.total_students !== undefined && html`<${StatCard} label="Total Students" value=${num(cards.total_students)} icon="students" href=${auth.can('students.view') ? '#/students' : undefined} />`}
      ${cards.active_students !== undefined && html`<${StatCard} label="Active Students" value=${num(cards.active_students)} icon="check" tone="green"
        hint=${cards.total_students ? `${Math.round((cards.active_students / cards.total_students) * 100)}% of all students` : ''} />`}
      ${cards.total_staff !== undefined && html`<${StatCard} label="Total Staff" value=${num(cards.total_staff)} icon="staff" tone="sky" href=${auth.can('staff.view') ? '#/staff' : undefined} />`}
      ${cards.total_fee_collection !== undefined && html`<${StatCard} label="Total Fee Collection" value=${moneyShort(cards.total_fee_collection)} icon="fees" tone="teal" hint=${money(cards.total_fee_collection)} href="#/fees/payments" />`}
      ${cards.total_outstanding !== undefined && html`<${StatCard} label="Outstanding Fees" value=${moneyShort(cards.total_outstanding)} icon="alert" tone="red" hint=${money(cards.total_outstanding)} href="#/fees/dues" />`}
      ${cards.monthly_fee_collection !== undefined && html`<${StatCard} label="This Month's Collection" value=${moneyShort(cards.monthly_fee_collection)} icon="trend" tone="indigo" hint=${money(cards.monthly_fee_collection)} />`}
      ${cards.monthly_salary_expense !== undefined && html`<${StatCard} label="This Month's Payroll" value=${moneyShort(cards.monthly_salary_expense)} icon="salary" tone="amber" hint=${money(cards.monthly_salary_expense)} href="#/salary/payroll" />`}
    </div>`}

    <div class="grid-2">
      ${charts.fee_collection_by_month && html`<${Card} title="Fee Collection by Month" className="span-2-lg">
        <${ChartBox} type="bar" isMoney labels=${charts.fee_collection_by_month.map((r) => r.label)}
          datasets=${[{ label: 'Collected', data: charts.fee_collection_by_month.map((r) => Number(r.value)) }]} /><//>`}
      ${charts.students_by_department && html`<${Card} title="Active Students by Department">
        ${charts.students_by_department.length ? html`<${ChartBox} type="doughnut" labels=${charts.students_by_department.map((r) => r.label)}
          datasets=${[{ data: charts.students_by_department.map((r) => Number(r.value)) }]} />` : html`<${Empty} title="No students yet" />`}<//>`}
      ${charts.outstanding_by_course && html`<${Card} title="Outstanding Fees by Course">
        ${charts.outstanding_by_course.length ? html`<${ChartBox} type="bar" horizontal isMoney labels=${charts.outstanding_by_course.map((r) => r.label)}
          datasets=${[{ label: 'Outstanding', data: charts.outstanding_by_course.map((r) => Number(r.value)), color: '#ef4444' }]} />` : html`<${Empty} title="No outstanding fees" icon="check" />`}<//>`}
      ${charts.salary_by_month && html`<${Card} title="Monthly Salary Expenses (Paid)">
        <${ChartBox} type="line" isMoney labels=${charts.salary_by_month.map((r) => r.label)}
          datasets=${[{ label: 'Net salary paid', data: charts.salary_by_month.map((r) => Number(r.value)), color: '#f59e0b', backgroundColor: 'rgba(245,158,11,.12)' }]} /><//>`}
    </div>

    ${lists.recent_payments && html`<div class="grid-2">
      <${Card} title="Recent Fee Payments" actions=${html`<a class="link" href="#/fees/payments">View all</a>`} pad=${false}>
        <${DataTable} rows=${lists.recent_payments} empty="No payments yet" onRowClick=${(r) => (location.hash = `#/students/${r.student_id}`)} columns=${[
          { key: 'student_name', label: 'Student', render: (r) => html`<div class="cell-title">${r.student_name}<small>${r.receipt_no}</small></div>` },
          { key: 'payment_date', label: 'Date', type: 'date' },
          { key: 'payment_mode', label: 'Mode' },
          { key: 'amount', label: 'Amount', type: 'money' },
        ]} />
      <//>
      <${Card} title="Pending Dues" actions=${html`<a class="link" href="#/fees/dues">View all</a>`} pad=${false}>
        <${DataTable} rows=${lists.pending_dues} rowKey="title" empty="No pending dues" onRowClick=${(r) => (location.hash = `#/students/${r.student_id}`)} columns=${[
          { key: 'student_name', label: 'Student', render: (r) => html`<div class="cell-title">${r.student_name}<small>${r.admission_no} · ${r.course_code}</small></div>` },
          { key: 'due_date', label: 'Due', render: (r) => html`${fmtDate(r.due_date)} ${Number(r.is_overdue) ? html`<${Badge} value="overdue" />` : ''}` },
          { key: 'balance', label: 'Balance', type: 'money' },
        ]} />
      <//>
    </div>`}
  </div>`;
}

function StaffWelcome() {
  const { data } = useApi('/profile');
  const s = data?.staff;
  return html`<${Card} title="My Profile">
    ${!data ? html`<${Spinner} />` : s ? html`<div class="profile-head">
      <${Avatar} name=${s.full_name} src=${photoUrl('staff', s.id, Number(s.has_photo))} size=${72} />
      <div><h2>${s.full_name}</h2><p class="muted">${s.designation} · ${s.department_name || '—'}</p></div>
    </div>
    <${DetailList} items=${[['Employee ID', s.employee_id], ['Email', s.email], ['Contact', s.contact_number], ['Date of joining', fmtDate(s.date_of_joining)], ['Status', html`<${Badge} value=${s.employment_status} />`]]} />`
    : html`<p class="muted">Your account is not linked to a staff record. Contact the administrator.</p>`}
    <p class="muted small">Additional sections appear here when your administrator grants access.</p>
  <//>`;
}
