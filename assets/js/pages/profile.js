import { html, useState, useApi, useAuth, useForm, post, toast, toastError, fmtDate, fmtDateTime } from '../lib.js';
import { PageHeader, Card, Input, Button, Spinner, DetailList, Avatar, Badge, Tabs, photoUrl } from '../components.js';

export function Profile({ query }) {
  const auth = useAuth();
  const { data, loading } = useApi('/profile');
  const [tab, setTab] = useState(query.tab === 'password' ? 'password' : 'profile');
  const s = data?.staff;
  return html`<div>
    <${PageHeader} title="My Profile" />
    <${Tabs} active=${tab} onChange=${setTab} tabs=${[{ key: 'profile', label: 'Profile' }, { key: 'password', label: 'Change Password' }]} />
    ${tab === 'profile' && (loading ? html`<${Spinner} />` : html`<div class="profile-layout">
      <${Card} className="profile-card">
        <div class="profile-hero">
          <${Avatar} name=${auth.user.full_name} src=${s ? photoUrl('staff', s.id, Number(s.has_photo), s.updated_at) : null} size=${96} />
          <h2>${auth.user.full_name}</h2><p class="muted">${auth.user.role_name}</p>
        </div>
        <${DetailList} items=${[['Username', auth.user.username], ['Email', auth.user.email]]} />
      <//>
      <${Card} title="Employment details" className="profile-main">
        ${s ? html`<${DetailList} items=${[
          ['Employee ID', s.employee_id], ['Designation', s.designation], ['Department', s.department_name],
          ['Qualification', s.qualification], ['Date of joining', fmtDate(s.date_of_joining)], ['Status', html`<${Badge} value=${s.employment_status} />`],
          ['Contact number', s.contact_number], ['Email', s.email], ['Address', s.address],
        ]} />` : html`<p class="muted">This account is not linked to a staff record.</p>`}
        <p class="muted small">To update these details, contact the administrator.</p>
      <//>
    </div>`)}
    ${tab === 'password' && html`<${ChangePassword} />`}
  </div>`;
}

function ChangePassword() {
  const form = useForm({ current_password: '', new_password: '', confirm: '' });
  const save = (e) => {
    e.preventDefault();
    if (form.values.new_password !== form.values.confirm) { form.setErrors({ confirm: 'Passwords do not match.' }); return; }
    form.submit((v) => post('/auth/change-password', v))
      .then((d) => { toast(d.message); form.setValues({ current_password: '', new_password: '', confirm: '' }); })
      .catch((err) => err.status !== 422 && toastError(err));
  };
  return html`<${Card} title="Change password" className="narrow">
    <form onSubmit=${save} class="stack" novalidate>
      <${Input} form=${form} name="current_password" label="Current password" type="password" required autocomplete="current-password" />
      <${Input} form=${form} name="new_password" label="New password" type="password" required autocomplete="new-password"
        hint="At least 8 characters with upper & lower case letters, a number and a symbol." />
      <${Input} form=${form} name="confirm" label="Confirm new password" type="password" required autocomplete="new-password" />
      <div><${Button} type="submit" variant="primary" busy=${form.busy}>Update password<//></div>
    </form>
  <//>`;
}
