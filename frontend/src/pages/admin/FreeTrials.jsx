import { useEffect, useState } from 'react';
import api from '../../services/api';

const localInput = (value) => value ? new Date(value).toISOString().slice(0, 16) : '';

export default function AdminFreeTrials() {
  const [campaigns, setCampaigns] = useState([]);
  const [packages, setPackages] = useState([]);
  const [routers, setRouters] = useState([]);
  const [form, setForm] = useState({ name: '', package_id: '', router_id: '', starts_at: '', ends_at: '', is_active: true });
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = () => api.get('/admin/free-trials').then(({ data }) => setCampaigns(data));
  useEffect(() => {
    load();
    api.get('/admin/packages', { params: { per_page: 100, status: 'active' } }).then(({ data }) => setPackages(data.data || data));
    api.get('/admin/routers', { params: { per_page: 100 } }).then(({ data }) => setRouters(data.data || data));
  }, []);

  const reset = () => {
    setEditing(null);
    setForm({ name: '', package_id: '', router_id: '', starts_at: '', ends_at: '', is_active: true });
    setError('');
  };

  const edit = (campaign) => {
    setEditing(campaign.id);
    setForm({
      name: campaign.name,
      package_id: campaign.package_id,
      router_id: campaign.router_id,
      starts_at: localInput(campaign.starts_at),
      ends_at: localInput(campaign.ends_at),
      is_active: campaign.is_active,
    });
  };

  const submit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const payload = { ...form, package_id: Number(form.package_id), router_id: Number(form.router_id) };
      if (editing) await api.put(`/admin/free-trials/${editing}`, payload);
      else await api.post('/admin/free-trials', payload);
      reset();
      load();
    } catch (err) {
      setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || 'Could not save campaign.');
    } finally {
      setSaving(false);
    }
  };

  const disable = async (id) => {
    await api.delete(`/admin/free-trials/${id}`);
    load();
  };

  return (
    <div className="space-y-6">
      <div><h1 className="text-2xl font-semibold text-slate-900">Free Access Campaigns</h1><p className="text-sm text-slate-500 mt-1">Everyone’s access ends at the campaign end time, so later claims receive less time.</p></div>
      <form onSubmit={submit} className="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4">
        <h2 className="font-semibold text-slate-800">{editing ? 'Edit campaign' : 'Create campaign'}</h2>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Campaign name"><input required className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
          <Field label="Trial package"><select required className="input" value={form.package_id} onChange={(e) => setForm({ ...form, package_id: e.target.value })}><option value="">Select package…</option>{packages.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
          <Field label="Hotspot location"><select required className="input" value={form.router_id} onChange={(e) => setForm({ ...form, router_id: e.target.value })}><option value="">Select router…</option>{routers.filter((r) => r.connection_mode === 'live').map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
          <Field label="Starts"><input required type="datetime-local" className="input" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
          <Field label="Ends (all free access stops here)"><input required type="datetime-local" className="input" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Campaign enabled</label>
        </div>
        <div className="flex gap-2"><button disabled={saving} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium disabled:opacity-50">{saving ? 'Saving…' : 'Save campaign'}</button>{editing && <button type="button" onClick={reset} className="px-4 py-2 text-sm text-slate-600">Cancel</button>}</div>
      </form>
      <div className="bg-white border border-slate-200 rounded-xl overflow-x-auto shadow-sm">
        <table className="w-full text-sm"><thead><tr className="text-left text-slate-500 border-b"><th className="p-3">Campaign</th><th className="p-3">Package / Location</th><th className="p-3">Period</th><th className="p-3">Claims</th><th className="p-3">Status</th><th className="p-3"></th></tr></thead>
          <tbody>{campaigns.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-slate-400">No campaigns yet.</td></tr>}{campaigns.map((c) => { const open = c.is_active && new Date(c.starts_at) <= new Date() && new Date(c.ends_at) > new Date(); return <tr key={c.id} className="border-b last:border-0"><td className="p-3 font-medium">{c.name}</td><td className="p-3 text-slate-600">{c.package?.name}<br/><span className="text-xs">{c.router?.name}</span></td><td className="p-3 text-xs text-slate-500">{new Date(c.starts_at).toLocaleString()}<br/>to {new Date(c.ends_at).toLocaleString()}</td><td className="p-3">{c.purchases_count ?? '—'}</td><td className="p-3"><span className={open ? 'text-emerald-600' : 'text-slate-400'}>{open ? 'Open' : c.is_active ? 'Scheduled/ended' : 'Disabled'}</span></td><td className="p-3 text-right"><button onClick={() => edit(c)} className="text-indigo-600 mr-3">Edit</button>{c.is_active && <button onClick={() => disable(c.id)} className="text-red-600">Disable</button>}</td></tr>; })}</tbody>
        </table>
      </div>
    </div>
  );
}

function Field({ label, children }) { return <label className="block text-sm font-medium text-slate-700">{label}<div className="mt-1">{children}</div></label>; }
