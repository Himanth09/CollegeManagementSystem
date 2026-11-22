/* Application shell: authentication, layout, navigation and routing. */
import {
  html, useState, useEffect, api, post, setCsrf, on, AuthContext, useAuth, useRoute, navigate, matchRoute,
  toast, toastError, useForm, clearMetaCache,
} from './lib.js';
import { Icon, Avatar, Toasts, ConfirmHost, Spinner, Button, Input, Alert, Empty } from './components.js';
import { Dashboard } from './pages/dashboard.js';
import { StudentsList, StudentForm, StudentProfile } from './pages/students.js';
import { CollectFee, Payments, Dues, FeeStructures } from './pages/fees.js';
import { StaffList, StaffForm, StaffProfile } from './pages/staff.js';
import { SalaryStructures, Payroll } from './pages/salary.js';
import { Reports } from './pages/reports.js';
import { Users, Masters, AuditLogs, Backups } from './pages/admin.js';
import { Profile } from './pages/profile.js';

const React = window.React;

/* ------------------------------------------------------------------ navigation */
const NAV = [
  { label: 'Dashboard', icon: 'dashboard', href: '#/', show: () => true },
  { label: 'Students', icon: 'students', href: '#/students', show: (a) => a.can('students.view') },
  {
    label: 'Fees', icon: 'fees', show: (a) => a.can('fees.view') || a.can('fees.collect'),
    children: [
      { label: 'Collect Fee', href: '#/fees/collect', show: (a) => a.can('fees.collect') },
      { label: 'Payments', href: '#/fees/payments', show: (a) => a.can('fees.view') },
      { label: 'Pending Dues', href: '#/fees/dues', show: (a) => a.can('fees.view') },
      { label: 'Fee Structures', href: '#/fees/structures', show: (a) => a.can('fees.view') || a.can('fees.collect') },
    ],
  },
  { label: 'Staff', icon: 'staff', href: '#/staff', show: (a) => a.can('staff.view') },
  {
    label: 'Salary', icon: 'salary', show: (a) => a.isAdmin, badge: 'Admin',
    children: [
      { label: 'Salary Structures', href: '#/salary/structures', show: (a) => a.isAdmin },
      { label: 'Monthly Payroll', href: '#/salary/payroll', show: (a) => a.isAdmin },
    ],
  },
  { label: 'Reports', icon: 'reports', href: '#/reports', show: (a) => ['reports.students', 'reports.fees', 'reports.staff'].some((p) => a.can(p)) },
  {
    label: 'Administration', icon: 'settings', show: (a) => a.isAdmin,
    children: [
      { label: 'User Accounts', href: '#/admin/users', show: (a) => a.isAdmin },
      { label: 'Academic Setup', href: '#/admin/masters', show: (a) => a.isAdmin },
      { label: 'Audit Logs', href: '#/admin/audit', show: (a) => a.isAdmin },
      { label: 'Database Backup', href: '#/admin/backups', show: (a) => a.isAdmin },
    ],
  },
  { label: 'My Profile', icon: 'user', href: '#/profile', show: () => true },
];

/* ------------------------------------------------------------------ routes (client guards are cosmetic; the API enforces access) */
const ROUTES = [
  ['/', Dashboard],
  ['/students', StudentsList, 'students.view'],
  ['/students/new', StudentForm, 'students.manage'],
  ['/students/:id/edit', StudentForm, 'students.manage'],
  ['/students/:id', StudentProfile, 'students.view'],
  ['/fees/collect', CollectFee, 'fees.collect'],
  ['/fees/payments', Payments, 'fees.view'],
  ['/fees/dues', Dues, 'fees.view'],
  ['/fees/structures', FeeStructures],
  ['/staff', StaffList, 'staff.view'],
  ['/staff/new', StaffForm, 'staff.manage'],
  ['/staff/:id/edit', StaffForm, 'staff.manage'],
  ['/staff/:id', StaffProfile],
  ['/salary/structures', SalaryStructures, 'admin'],
  ['/salary/payroll', Payroll, 'admin'],
  ['/reports', Reports],
  ['/admin/users', Users, 'admin'],
  ['/admin/masters', Masters, 'admin'],
  ['/admin/audit', AuditLogs, 'admin'],
  ['/admin/backups', Backups, 'admin'],
  ['/profile', Profile],
];

function Sidebar({ open, onClose, path }) {
  const auth = useAuth();
  const [expanded, setExpanded] = useState({});
  const isActive = (href) => {
    const p = href.replace(/^#/, '');
    return p === '/' ? path === '/' : path === p || path.startsWith(p + '/');
  };
  return html`<aside class=${'sidebar' + (open ? ' open' : '')}>
    <div class="brand">
      <div class="brand-logo"><${Icon} name="students" size=${22} /></div>
      <div><strong>${window.CMS_APP?.short || 'CMS'}</strong><span>${window.CMS_APP?.college || 'College Management'}</span></div>
    </div>
    <nav class="nav" aria-label="Main navigation">
      ${NAV.filter((n) => n.show(auth)).map((n) => {
        if (!n.children) {
          return html`<a key=${n.label} href=${n.href} class=${'nav-link' + (isActive(n.href) ? ' active' : '')} onClick=${onClose}>
            <${Icon} name=${n.icon} /><span>${n.label}</span></a>`;
        }
        const kids = n.children.filter((c) => c.show(auth));
        const childActive = kids.some((c) => isActive(c.href));
        const isOpen = expanded[n.label] ?? childActive;
        return html`<div key=${n.label} class=${'nav-group' + (isOpen ? ' open' : '')}>
          <button class=${'nav-link' + (childActive ? ' parent-active' : '')} aria-expanded=${isOpen}
            onClick=${() => setExpanded((e) => ({ ...e, [n.label]: !isOpen }))}>
            <${Icon} name=${n.icon} /><span>${n.label}</span>${n.badge && html`<em class="nav-badge">${n.badge}</em>`}
            <${Icon} name="chevronDown" size=${14} className="caret" />
          </button>
          ${isOpen && html`<div class="nav-children">${kids.map((c) => html`<a key=${c.href} href=${c.href}
            class=${'nav-sublink' + (isActive(c.href) ? ' active' : '')} onClick=${onClose}>${c.label}</a>`)}</div>`}
        </div>`;
      })}
    </nav>
    <div class="sidebar-foot">Role: <strong>${auth.user.role_name}</strong></div>
  </aside>
  ${open && html`<div class="sidebar-overlay" onClick=${onClose}></div>`}`;
}

function Topbar({ onMenu }) {
  const auth = useAuth();
  const [open, setOpen] = useState(false);
  useEffect(() => {
    if (!open) return undefined;
    const h = (e) => { if (!e.target.closest('.profile-menu')) setOpen(false); };
    document.addEventListener('click', h);
    return () => document.removeEventListener('click', h);
  }, [open]);
  return html`<header class="topbar">
    <button class="icon-btn menu-btn" aria-label="Toggle menu" onClick=${onMenu}><${Icon} name="menu" size=${20} /></button>
    <div class="topbar-title">${window.CMS_APP?.college || ''}</div>
    <div class="profile-menu">
      <button class="profile-btn" onClick=${() => setOpen(!open)} aria-haspopup="menu" aria-expanded=${open}>
        <${Avatar} name=${auth.user.full_name} size=${34} />
        <span class="profile-name"><strong>${auth.user.full_name}</strong><small>${auth.user.role_name}</small></span>
        <${Icon} name="chevronDown" size=${14} />
      </button>
      ${open && html`<div class="dropdown" role="menu">
        <div class="dropdown-head"><strong>${auth.user.full_name}</strong><small>${auth.user.email}</small></div>
        <a href="#/profile" role="menuitem" onClick=${() => setOpen(false)}><${Icon} name="user" size=${16} /> My Profile</a>
        <a href="#/profile?tab=password" role="menuitem" onClick=${() => setOpen(false)}><${Icon} name="key" size=${16} /> Change Password</a>
        <button role="menuitem" class="danger" onClick=${auth.logout}><${Icon} name="logout" size=${16} /> Sign out</button>
      </div>`}
    </div>
  </header>`;
}

function Forbidden() {
  return html`<${Empty} icon="shield" title="Access restricted" text="You don't have permission to view this page. Contact your administrator if you need access.">
    <a class="btn btn-primary" href="#/">Go to dashboard</a><//>`;
}

function Layout() {
  const auth = useAuth();
  const route = useRoute();
  const [menu, setMenu] = useState(false);
  let page = null;
  for (const [pattern, Comp, perm] of ROUTES) {
    const params = matchRoute(pattern, route.path);
    if (params) {
      const allowed = !perm || (perm === 'admin' ? auth.isAdmin : auth.can(perm));
      page = allowed ? html`<${Comp} key=${route.path} params=${params} query=${route.query} />` : html`<${Forbidden} />`;
      break;
    }
  }
  return html`<div class="shell">
    <${Sidebar} open=${menu} onClose=${() => setMenu(false)} path=${route.path} />
    <div class="main">
      <${Topbar} onMenu=${() => setMenu(!menu)} />
      <main class="content" id="content">${page || html`<${Empty} title="Page not found"><a class="btn" href="#/">Back to dashboard</a><//>`}</main>
      <footer class="app-foot">© ${new Date().getFullYear()} ${window.CMS_APP?.college || ''}</footer>
    </div>
  </div>`;
}

/* ------------------------------------------------------------------ auth screens */
function AuthCard({ title, subtitle, children }) {
  return html`<div class="auth-page">
    <div class="auth-side">
      <div class="brand-logo lg"><${Icon} name="students" size=${34} /></div>
      <h2>${window.CMS_APP?.college || 'College Management System'}</h2>
      <p>Students, fees, staff and payroll in one secure portal.</p>
      <ul><li><${Icon} name="check" size=${16} /> Role-based access</li><li><${Icon} name="check" size=${16} /> Printable receipts & slips</li><li><${Icon} name="check" size=${16} /> Audit trail for every financial change</li></ul>
    </div>
    <div class="auth-main"><div class="auth-card"><h1>${title}</h1>${subtitle && html`<p class="muted">${subtitle}</p>`}${children}</div></div>
  </div>`;
}

function Login({ onLogin, notice }) {
  const form = useForm({ username: '', password: '' });
  const [error, setError] = useState(notice || '');
  const submit = (e) => {
    e.preventDefault();
    setError('');
    form.submit((v) => post('/auth/login', v)).then((d) => onLogin(d.user)).catch((err) => setError(err.message));
  };
  return html`<${AuthCard} title="Sign in" subtitle="Use your college account to continue.">
    ${error && html`<${Alert} type="error">${error}<//>`}
    <form onSubmit=${submit} class="stack" novalidate>
      <${Input} form=${form} name="username" label="Username or email" required autocomplete="username" autofocus />
      <${Input} form=${form} name="password" label="Password" type="password" required autocomplete="current-password" />
      <${Button} type="submit" variant="primary" busy=${form.busy} className="btn-block">Sign in<//>
      <a class="link center" href="#/forgot-password">Forgot your password?</a>
    </form>
  <//>`;
}

function ForgotPassword() {
  const form = useForm({ email: '' });
  const [done, setDone] = useState('');
  const submit = (e) => {
    e.preventDefault();
    form.submit((v) => post('/auth/forgot', v)).then((d) => setDone(d.message)).catch(() => {});
  };
  return html`<${AuthCard} title="Reset password" subtitle="Enter your account email and we'll send you a secure reset link.">
    ${done ? html`<${Alert} type="success">${done}<//>` : html`<form onSubmit=${submit} class="stack" novalidate>
      <${Input} form=${form} name="email" label="Email address" type="email" required autocomplete="email" />
      <${Button} type="submit" variant="primary" busy=${form.busy}>Send reset link<//>
    </form>`}
    <a class="link center" href="#/">Back to sign in</a>
  <//>`;
}

function ResetPassword({ token }) {
  const form = useForm({ password: '', confirm: '' });
  const [done, setDone] = useState('');
  const [error, setError] = useState('');
  const submit = (e) => {
    e.preventDefault();
    setError('');
    if (form.values.password !== form.values.confirm) { form.setErrors({ confirm: 'Passwords do not match.' }); return; }
    form.submit((v) => post('/auth/reset', { token, password: v.password })).then((d) => setDone(d.message)).catch((err) => setError(err.message));
  };
  return html`<${AuthCard} title="Choose a new password" subtitle="At least 8 characters with upper & lower case letters, a number and a symbol.">
    ${error && html`<${Alert} type="error">${error}<//>`}
    ${done ? html`<${Alert} type="success">${done}<//><a class="btn btn-primary" href="#/">Sign in</a>` : html`<form onSubmit=${submit} class="stack" novalidate>
      <${Input} form=${form} name="password" label="New password" type="password" required autocomplete="new-password" />
      <${Input} form=${form} name="confirm" label="Confirm password" type="password" required autocomplete="new-password" />
      <${Button} type="submit" variant="primary" busy=${form.busy}>Reset password<//>
    </form>`}
  <//>`;
}

/* ------------------------------------------------------------------ root */
function App() {
  const [state, setState] = useState({ loading: true, user: null, notice: '', install: false });
  const route = useRoute();

  const loadMe = () => api('/auth/me')
    .then((d) => { setCsrf(d.csrf); window.CMS_APP = { ...window.CMS_APP, college: d.app.college_name, name: d.app.name }; setState({ loading: false, user: d.user, notice: '' }); })
    .catch((e) => setState({ loading: false, user: null, notice: e.message, install: !!e.data?.install_required }));

  useEffect(() => { loadMe(); }, []);
  useEffect(() => on('unauthorized', (msg) => setState((s) => (s.user ? { loading: false, user: null, notice: msg || 'Your session has ended. Please sign in again.' } : s))), []);

  if (state.loading) return html`<div class="boot"><${Spinner} label="Loading portal…" /></div>`;
  if (state.install) {
    return html`<${AuthCard} title="Setup required"><${Alert} type="warning">${state.notice}<//><a class="btn btn-primary" href="install/">Open installer</a><//>`;
  }

  if (!state.user) {
    const resetMatch = route.path === '/reset-password';
    return html`<${React.Fragment}>
      ${resetMatch ? html`<${ResetPassword} token=${route.query.token || ''} />`
        : route.path === '/forgot-password' ? html`<${ForgotPassword} />`
        : html`<${Login} notice=${state.notice} onLogin=${(u) => { clearMetaCache(); setState({ loading: false, user: u, notice: '' }); toast(`Welcome, ${u.full_name}!`); if (route.path !== '/') navigate('/'); }} />`}
      <${Toasts} />
    <//>`;
  }

  const user = state.user;
  const authValue = {
    user,
    isAdmin: user.is_super_admin,
    can: (p) => user.is_super_admin || user.permissions.includes(p),
    refresh: loadMe,
    logout: async () => {
      try { await post('/auth/logout'); } catch (e) { /* session may already be gone */ }
      clearMetaCache();
      setState({ loading: false, user: null, notice: '' });
      navigate('/');
      toast('You have been signed out.');
    },
  };
  return html`<${AuthContext.Provider} value=${authValue}>
    <${Layout} />
    <${Toasts} />
    <${ConfirmHost} />
  <//>`;
}

window.addEventListener('unhandledrejection', (e) => {
  if (e.reason && typeof e.reason.status === 'number') toastError(e.reason);
});

ReactDOM.createRoot(document.getElementById('app')).render(html`<${App} />`);
