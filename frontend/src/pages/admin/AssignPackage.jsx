import { useEffect, useState } from 'react';
import api from '../../services/api';

export default function AdminAssignPackage() {
  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState([]);
  const [selectedCustomer, setSelectedCustomer] = useState(null);

  const [packages, setPackages] = useState([]);
  const [packageId, setPackageId] = useState('');
  const [routers, setRouters] = useState([]);
  const [routerId, setRouterId] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);

  useEffect(() => {
    api.get('/admin/packages', { params: { per_page: 100 } }).then(({ data }) => setPackages(data.data || data));
    api.get('/admin/routers', { params: { per_page: 100 } }).then(({ data }) => setRouters(data.data || data));
  }, []);

  useEffect(() => {
    if (customerQuery.trim().length < 2) {
      setCustomerResults([]);
      return;
    }
    const t = setTimeout(() => {
      api.get('/admin/search', { params: { q: customerQuery.trim() } }).then(({ data }) => setCustomerResults(data.customers));
    }, 300);
    return () => clearTimeout(t);
  }, [customerQuery]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    setSuccess(null);
    try {
      const { data } = await api.post('/admin/purchases/assign', {
        customer_id: selectedCustomer.id,
        package_id: Number(packageId),
        router_id: Number(routerId),
      });
      setSuccess(data);
      setSelectedCustomer(null);
      setCustomerQuery('');
      setPackageId('');
      setRouterId('');
    } catch (err) {
      setError(err.response?.data?.message || 'Could not assign this package. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="max-w-lg">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Assign Package</h1>
        <p className="text-slate-500 text-sm mt-1">Give a customer access without going through payment.</p>
      </div>

      {success && (
        <div className="mb-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-4 py-3">
          Assigned successfully. Reference: <span className="font-mono">{success.reference}</span>
        </div>
      )}
      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>
      )}

      <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-slate-200 p-5 space-y-5">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Customer</label>
          {selectedCustomer ? (
            <div className="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-md px-3 py-2">
              <span className="text-sm text-slate-700">
                {selectedCustomer.full_name} · {selectedCustomer.phone}
              </span>
              <button type="button" onClick={() => setSelectedCustomer(null)} className="text-xs text-indigo-600 hover:underline">
                Change
              </button>
            </div>
          ) : (
            <>
              <input
                className="input"
                placeholder="Search by name, phone, or username…"
                value={customerQuery}
                onChange={(e) => setCustomerQuery(e.target.value)}
              />
              {customerResults.length > 0 && (
                <div className="mt-1 border border-slate-200 rounded-md overflow-hidden">
                  {customerResults.map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => {
                        setSelectedCustomer(c);
                        setCustomerResults([]);
                      }}
                      className="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 border-b border-slate-100 last:border-0"
                    >
                      {c.full_name} <span className="text-slate-400">· {c.phone}</span>
                    </button>
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Package</label>
          <select required className="input" value={packageId} onChange={(e) => setPackageId(e.target.value)}>
            <option value="">Select a package…</option>
            {packages.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Router</label>
          <select required className="input" value={routerId} onChange={(e) => setRouterId(e.target.value)}>
            <option value="">Select a router…</option>
            {routers.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name} {r.connection_mode === 'manual' ? '(Manual — voucher)' : '(Live)'}
              </option>
            ))}
          </select>
        </div>

        <button
          type="submit"
          disabled={submitting || !selectedCustomer || !packageId || !routerId}
          className="w-full bg-indigo-600 text-white rounded-md py-2.5 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
        >
          {submitting ? 'Assigning…' : 'Assign Package'}
        </button>
      </form>
    </div>
  );
}
