import { useEffect, useState, useCallback } from 'react';
import { Download } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import SearchFilterInput from '../../components/SearchFilterInput';
import CustomerDetailModal from './CustomerDetailModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
];

export default function AdminCustomers() {
  const [customers, setCustomers] = useState([]);
  const [counts, setCounts] = useState(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/reports/customers', { params: { ...pagination.requestParams, search: search || undefined, status: status || undefined } })
      .then(({ data }) => {
        setCounts(data.counts);
        setCustomers(pagination.capture(data.customers));
      })
      .catch(() => setError('Could not load customers. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [search, status, pagination.page]);

  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);

  const openDetail = (customer) => {
    setError('');
    api.get(`/admin/customers/${customer.id}`)
      .then(({ data }) => setDetail(data))
      .catch((requestError) => {
        setError(requestError.response?.data?.message || 'Could not open this customer. Please refresh and try again.');
      });
  };

  const [exporting, setExporting] = useState(false);

  const handleExport = async () => {
    // window.open()/a plain navigation can't attach the Authorization
    // header Sanctum needs here (this is header-based token auth, not
    // cookie-based) — the request has to go through the authenticated
    // axios instance as a blob download instead.
    setExporting(true);
    try {
      const response = await api.get('/admin/reports/customers', {
        params: { export: 'pdf', status: status || undefined },
        responseType: 'blob',
      });
      const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'customers.pdf';
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      setError('Could not export customers right now.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <div>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Customers</h1>
          <p className="text-slate-500 text-sm mt-1">Every registered customer and their subscription status.</p>
        </div>
        <button
          onClick={handleExport}
          disabled={exporting}
          className="flex items-center gap-2 text-sm text-slate-600 border border-slate-300 rounded-md px-4 py-2 hover:bg-slate-50 whitespace-nowrap disabled:opacity-50"
        >
          <Download className="h-4 w-4" />
          {exporting ? 'Exporting…' : 'Export PDF'}
        </button>
      </div>

      {counts && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
          <MiniStat label="Total" value={counts.total} />
          <MiniStat label="Active" value={counts.active} tone="text-emerald-600" />
          <MiniStat label="Inactive" value={counts.inactive} />
          <MiniStat label="Suspended" value={counts.suspended} tone="text-red-600" />
        </div>
      )}

      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <SearchFilterInput value={search} onChange={setSearch} placeholder="Search by name, phone, username…" />
        <div className="flex gap-2">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.value}
              onClick={() => setStatus(f.value)}
              className={`px-3 py-1.5 rounded-md text-sm font-medium border whitespace-nowrap ${
                status === f.value
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Name</th>
              <th className="px-5 py-3 font-medium">Phone</th>
              <th className="px-5 py-3 font-medium">Username</th>
              <th className="px-5 py-3 font-medium">Router / Location</th>
              <th className="px-5 py-3 font-medium">Registered</th>
              <th className="px-5 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">Loading…</td>
              </tr>
            )}
            {!loading && customers.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">No customers found.</td>
              </tr>
            )}
            {!loading &&
              customers.map((c) => (
                <tr
                  key={c.id}
                  onClick={() => openDetail(c)}
                  className="border-b border-slate-50 last:border-0 cursor-pointer hover:bg-slate-50"
                >
                  <td className="px-5 py-3 font-medium text-slate-900">{c.full_name}</td>
                  <td className="px-5 py-3 text-slate-600">{c.phone}</td>
                  <td className="px-5 py-3 text-slate-600">{c.username}</td>
                  <td className="px-5 py-3 text-slate-600">
                    {customerRouters(c).map((router) => (
                      <div key={router.id}>
                        <span className="font-medium text-slate-700">{router.name}</span>
                        {router.location && <span className="block text-xs text-slate-400">{router.location}</span>}
                      </div>
                    ))}
                    {customerRouters(c).length === 0 && '—'}
                  </td>
                  <td className="px-5 py-3 text-slate-600">{new Date(c.created_at).toLocaleDateString()}</td>
                  <td className="px-5 py-3">
                    <StatusBadge status={c.status} />
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {detail && (
        <CustomerDetailModal
          customer={detail}
          onClose={() => setDetail(null)}
          onUpdated={(customer) => {
            setDetail(customer);
            load();
          }}
          onDeleted={() => {
            setDetail(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function MiniStat({ label, value, tone = 'text-slate-900' }) {
  return (
    <div className="bg-white rounded-lg border border-slate-200 px-4 py-3">
      <p className="text-xs text-slate-400">{label}</p>
      <p className={`text-lg font-semibold ${tone}`}>{value}</p>
    </div>
  );
}

function customerRouters(customer) {
  const routers = [
    customer.home_router,
    customer.current_purchase?.router,
    ...(customer.hotspot_users || []).map((user) => user.router),
  ].filter(Boolean);

  return [...new Map(routers.map((router) => [router.id, router])).values()];
}
