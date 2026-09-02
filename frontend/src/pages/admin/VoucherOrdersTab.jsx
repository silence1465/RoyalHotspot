import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import SearchFilterInput from '../../components/SearchFilterInput';
import OrderDetailModal from './OrderDetailModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'processing', label: 'Processing' },
  { value: 'verified', label: 'Verified' },
  { value: 'manual_review', label: 'Manual Review' },
  { value: 'completed', label: 'Completed' },
  { value: 'failed', label: 'Failed' },
];

export default function VoucherOrdersTab() {
  const [orders, setOrders] = useState([]);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/admin/orders', { params: { status: status || undefined, search: search || undefined, ...pagination.requestParams } })
      .then(({ data }) => setOrders(pagination.capture(data)))
      .catch(() => setError('Could not load orders.'))
      .finally(() => setLoading(false));
  }, [status, search, pagination.page]);

  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);

  const openOrder = (order) => {
    api.get(`/admin/orders/${order.id}`).then(({ data }) => setSelected(data));
  };

  return (
    <div>
      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <SearchFilterInput value={search} onChange={setSearch} placeholder="Search by reference or transaction ID…" />
        <div className="flex gap-2 flex-wrap">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.value}
              onClick={() => setStatus(f.value)}
              className={`px-3 py-1.5 rounded-md text-xs font-medium border whitespace-nowrap ${
                status === f.value
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : 'bg-white text-slate-600 border-slate-200'
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
              <th className="px-5 py-3 font-medium">Reference</th>
              <th className="px-5 py-3 font-medium">Customer</th>
              <th className="px-5 py-3 font-medium">Package</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Voucher</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Date</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>
            )}
            {!loading && orders.length === 0 && (
              <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">No orders found.</td></tr>
            )}
            {!loading &&
              orders.map((o) => (
                <tr
                  key={o.id}
                  onClick={() => openOrder(o)}
                  className="border-b border-slate-50 last:border-0 cursor-pointer hover:bg-slate-50"
                >
                  <td className="px-5 py-3 font-mono text-xs text-slate-500">{o.reference}</td>
                  <td className="px-5 py-3 text-slate-700">{o.customer?.full_name}</td>
                  <td className="px-5 py-3 text-slate-600">{o.package?.name}</td>
                  <td className="px-5 py-3 text-slate-700">{currency(o.amount)}</td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-500">{o.voucher?.code || '—'}</td>
                  <td className="px-5 py-3"><StatusBadge status={o.status} /></td>
                  <td className="px-5 py-3 text-slate-500">{new Date(o.created_at).toLocaleDateString()}</td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {selected && (
        <OrderDetailModal
          order={selected}
          onClose={() => setSelected(null)}
          onUpdated={(order) => {
            setSelected(order);
            load();
          }}
        />
      )}
    </div>
  );
}
