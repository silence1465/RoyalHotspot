import { useEffect, useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../services/api';

export default function GenerateVoucherModal({ onClose, onGenerated }) {
  const [packages, setPackages] = useState([]);
  const [routers, setRouters] = useState([]);
  const [form, setForm] = useState({ package_id: '', router_id: '', quantity: 10, expires_at: '' });
  const [errors, setErrors] = useState({});
  const [serverError, setServerError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get('/admin/packages', { params: { per_page: 100, status: 'active' } })
      .then(({ data }) => setPackages(data.data || data))
      .catch(() => {});
    api.get('/admin/routers', { params: { per_page: 100 } })
      .then(({ data }) => setRouters((data.data || data).filter((r) => r.connection_mode !== 'manual')))
      .catch(() => {});
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setServerError('');

    try {
      const payload = {
        package_id: form.package_id,
        router_id: form.router_id,
        quantity: form.quantity,
        expires_at: form.expires_at || null,
      };
      const { data } = await api.post('/admin/vouchers/generate', payload);
      onGenerated(data);
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors || {});
      } else {
        setServerError(err.response?.data?.message || 'Something went wrong. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  };

  const fieldError = (key) => errors[key]?.[0];

  return (
    <Modal title="Generate Vouchers" onClose={onClose}>
      {serverError && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
          {serverError}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Package</label>
          <select
            required
            className="input"
            value={form.package_id}
            onChange={(e) => setForm({ ...form, package_id: e.target.value })}
          >
            <option value="">Select package…</option>
            {packages.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          {fieldError('package_id') && <p className="text-xs text-red-600 mt-1">{fieldError('package_id')}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Router</label>
          <select
            required
            className="input"
            value={form.router_id}
            onChange={(e) => setForm({ ...form, router_id: e.target.value })}
          >
            <option value="">Select a live router…</option>
            {routers.map((r) => (
              <option key={r.id} value={r.id}>{r.name}</option>
            ))}
          </select>
          <p className="text-xs text-slate-400 mt-1">
            Each code is created live on this router right now — not just saved to the database. For codes
            you've already generated directly on a manual router, use Import PDF instead.
          </p>
          {fieldError('router_id') && <p className="text-xs text-red-600 mt-1">{fieldError('router_id')}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Quantity</label>
          <input
            type="number"
            min="1"
            max="500"
            required
            className="input"
            value={form.quantity}
            onChange={(e) => setForm({ ...form, quantity: Number(e.target.value) })}
          />
          {fieldError('quantity') && <p className="text-xs text-red-600 mt-1">{fieldError('quantity')}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Expiry Date (optional)</label>
          <input
            type="date"
            className="input"
            value={form.expires_at}
            onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
          />
          {fieldError('expires_at') && <p className="text-xs text-red-600 mt-1">{fieldError('expires_at')}</p>}
        </div>

        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {saving ? 'Creating on router…' : 'Generate'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
