import { useEffect, useState, useCallback } from 'react';
import { Download } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import StatCard from '../../components/StatCard';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'successful', label: 'Successful' },
  { value: 'pending', label: 'Pending' },
  { value: 'failed', label: 'Failed' },
];

export default function AdminPayments() {
  const [payments, setPayments] = useState([]);
  const [revenue, setRevenue] = useState(null);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState(false);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    Promise.all([
      api.get('/admin/reports/payments', { params: { ...pagination.requestParams, status: status || undefined } }),
      api.get('/admin/reports/revenue'),
    ])
      .then(([paymentsRes, revenueRes]) => {
        setPayments(pagination.capture(paymentsRes.data));
        setRevenue(revenueRes.data);
      })
      .catch(() => setError('Could not load payments. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [status, pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  const handleExport = async () => {
    setExporting(true);
    try {
      const response = await api.get('/admin/reports/payments', {
        params: { export: 'csv', status: status || undefined },
        responseType: 'blob',
      });
      const url = URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'payments.csv';
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      setError('Could not export payments right now.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <div>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Payments</h1>
          <p className="text-slate-500 text-sm mt-1">Revenue and payment history, last 30 days by default.</p>
        </div>
        <button
          onClick={handleExport}
          disabled={exporting}
          className="flex items-center gap-2 text-sm text-slate-600 border border-slate-300 rounded-md px-4 py-2 hover:bg-slate-50 whitespace-nowrap disabled:opacity-50"
        >
          <Download className="h-4 w-4" />
          {exporting ? 'Exporting…' : 'Export CSV'}
        </button>
      </div>

      {revenue && (
        <div className="grid grid-cols-2 gap-4 mb-6">
          <StatCard label={`Revenue (${revenue.from} to ${revenue.to})`} value={currency(revenue.total_revenue)} tone="positive" />
          <StatCard label="Payments in range" value={revenue.total_payments} />
        </div>
      )}

      <div className="mb-4 flex gap-2">
        {STATUS_FILTERS.map((f) => (
          <button
            key={f.value}
            onClick={() => setStatus(f.value)}
            className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
              status === f.value
                ? 'bg-indigo-600 text-white border-indigo-600'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Reference</th>
              <th className="px-5 py-3 font-medium">Customer</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Provider</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Date</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">Loading…</td>
              </tr>
            )}
            {!loading && payments.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">No payments found.</td>
              </tr>
            )}
            {!loading &&
              payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3 font-mono text-xs text-slate-500">{p.reference}</td>
                  <td className="px-5 py-3 text-slate-700">{p.customer?.full_name || '—'}</td>
                  <td className="px-5 py-3 text-slate-700">{currency(p.amount)}</td>
                  <td className="px-5 py-3 text-slate-500 capitalize">{p.provider}</td>
                  <td className="px-5 py-3"><StatusBadge status={p.status} /></td>
                  <td className="px-5 py-3 text-slate-500">
                    {new Date(p.paid_at || p.created_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>
    </div>
  );
}
