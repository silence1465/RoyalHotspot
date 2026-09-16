import { useEffect, useState } from 'react';
import api from '../../services/api';

const blank = { name: '', email: '', phone: '', password: '', role: 'admin', status: 'active', router_ids: [], permissions: [] };

export default function AdminUsers() {
  const [data, setData] = useState({ admins: [], routers: [], available_permissions: [] });
  const [form, setForm] = useState(blank);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');

  const load = () => api.get('/admin/admin-users').then(({ data: response }) => setData(response));
  useEffect(() => { load().catch(() => setError('Could not load administrator accounts.')); }, []);

  const toggle = (field, value) => setForm((current) => ({
    ...current,
    [field]: current[field].includes(value) ? current[field].filter((item) => item !== value) : [...current[field], value],
  }));

  const edit = (admin) => {
    setEditing(admin.id);
    setForm({
      name: admin.name, email: admin.email, phone: admin.phone || '', password: '', role: admin.role,
      status: admin.status, router_ids: admin.routers?.map((router) => router.id) || [], permissions: admin.permissions || [],
    });
  };

  const submit = async (event) => {
    event.preventDefault(); setError('');
    try {
      if (editing) await api.put(`/admin/admin-users/${editing}`, form);
      else await api.post('/admin/admin-users', form);
      setEditing(null); setForm(blank); await load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || Object.values(requestError.response?.data?.errors || {})[0]?.[0] || 'Could not save administrator.');
    }
  };

  return <div className="space-y-6">
    <div><h1 className="text-2xl font-semibold text-slate-900">Administrators</h1><p className="text-sm text-slate-500">Assign multiple routers and explicit permissions.</p></div>
    {error && <div className="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
    <form onSubmit={submit} className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
      <div className="grid sm:grid-cols-2 gap-3">
        <input required className="input" placeholder="Full name" value={form.name} onChange={(e) => setForm({...form, name:e.target.value})}/>
        <input required type="email" className="input" placeholder="Email" value={form.email} onChange={(e) => setForm({...form, email:e.target.value})}/>
        <input className="input" placeholder="Phone" value={form.phone} onChange={(e) => setForm({...form, phone:e.target.value})}/>
        <input required={!editing} type="password" className="input" placeholder={editing ? 'New password (optional)' : 'Password'} value={form.password} onChange={(e) => setForm({...form, password:e.target.value})}/>
        <select className="input" value={form.role} onChange={(e) => setForm({...form, role:e.target.value})}><option value="admin">Admin</option><option value="support">Support</option><option value="super_admin">Super admin</option></select>
        <select className="input" value={form.status} onChange={(e) => setForm({...form, status:e.target.value})}><option value="active">Active</option><option value="inactive">Inactive</option></select>
      </div>
      {form.role !== 'super_admin' && <><div><p className="text-sm font-medium mb-2">Routers</p><div className="grid sm:grid-cols-3 gap-2">{data.routers.map((router)=><label key={router.id} className="text-sm"><input type="checkbox" className="mr-2" checked={form.router_ids.includes(router.id)} onChange={()=>toggle('router_ids',router.id)}/>{router.name}</label>)}</div></div>
      <div><p className="text-sm font-medium mb-2">Permissions</p><div className="grid sm:grid-cols-3 gap-2">{data.available_permissions.map((permission)=><label key={permission} className="text-sm"><input type="checkbox" className="mr-2" checked={form.permissions.includes(permission)} onChange={()=>toggle('permissions',permission)}/>{permission.replaceAll('.',' ')}</label>)}</div></div></>}
      <div className="flex gap-2"><button className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">{editing?'Save Changes':'Create Admin'}</button>{editing&&<button type="button" className="px-4 py-2 text-sm" onClick={()=>{setEditing(null);setForm(blank)}}>Cancel</button>}</div>
    </form>
    <div className="bg-white border border-slate-200 rounded-xl overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-3 text-left">Admin</th><th className="p-3 text-left">Role</th><th className="p-3 text-left">Routers</th><th className="p-3 text-left">Status</th><th/></tr></thead><tbody>{data.admins.map((admin)=><tr key={admin.id} className="border-b"><td className="p-3">{admin.name}<div className="text-xs text-slate-400">{admin.email}</div></td><td className="p-3">{admin.role}</td><td className="p-3">{admin.role==='super_admin'?'All':admin.routers?.map((r)=>r.name).join(', ')||'None'}</td><td className="p-3">{admin.status}</td><td className="p-3 text-right"><button className="text-indigo-600" onClick={()=>edit(admin)}>Edit</button></td></tr>)}</tbody></table></div>
  </div>;
}
