import { useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { Ticket } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import CopyButton from '../../components/CopyButton';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function MyVouchers() {
  const [tab, setTab] = useState('vouchers'); // 'vouchers' | 'orders'

  return (
    <div>
      <div className="mb-5">
        <h1 className="text-xl font-semibold text-slate-900">My Vouchers</h1>
        <p className="text-slate-500 text-sm mt-1">Everything you've bought or redeemed, and your payment history.</p>
      </div>

      <div className="mb-4 flex gap-2">
        <TabButton active={tab === 'vouchers'} onClick={() => setTab('vouchers')}>
          Vouchers
        </TabButton>
        <TabButton active={tab === 'orders'} onClick={() => setTab('orders')}>
          Payment History
        </TabButton>
      </div>

      {tab === 'vouchers' ? <VouchersTab /> : <OrdersTab />}
    </div>
  );
}

function TabButton({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
        active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
      }`}
    >
      {children}
    </button>
  );
}

function VouchersTab() {
  const [vouchers, setVouchers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const pagination = useServerPagination();

  useEffect(() => {
    api
      .get('/customer/my-vouchers', { params: pagination.requestParams })
      .then(({ data }) => setVouchers(pagination.capture(data)))
      .catch(() => setError('Could not load your vouchers.'))
      .finally(() => setLoading(false));
  }, [pagination.page]);

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;
  if (error) return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;

  if (vouchers.length === 0) {
    return (
      <div className="bg-white rounded-xl border border-slate-200 p-8 text-center">
        <Ticket className="h-8 w-8 text-slate-300 mx-auto mb-3" />
        <p className="text-slate-600 font-medium">No vouchers yet</p>
        <Link to="/buy" className="text-indigo-600 text-sm font-medium hover:underline mt-2 inline-block">
          Buy a voucher →
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {vouchers.map((v) => (
        <div key={v.id} className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="font-mono font-semibold text-slate-900">{v.code}</p>
              <p className="text-xs text-slate-400 mt-0.5">{v.package?.name}</p>
            </div>
            <StatusBadge status={v.status} />
          </div>
          <div className="flex items-center justify-between mt-3 pt-3 border-t border-slate-100">
            <div className="text-xs text-slate-400">
              {v.amount != null && <span className="mr-3">{currency(v.amount)}</span>}
              {v.used_at
                ? `Used ${new Date(v.used_at).toLocaleDateString()}`
                : v.assigned_at
                  ? `Received ${new Date(v.assigned_at).toLocaleDateString()}`
                  : `Redeemed ${new Date(v.created_at).toLocaleDateString()}`}
            </div>
            <CopyButton text={v.code} />
          </div>
        </div>
      ))}
      <TablePagination {...pagination} onPageChange={pagination.setPage} />
    </div>
  );
}

function OrdersTab() {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [rowMessages, setRowMessages] = useState({});
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/customer/purchases', { params: pagination.requestParams })
      .then(({ data }) => setOrders(pagination.capture(data)))
      .catch(() => setError('Could not load your payment history.'))
      .finally(() => setLoading(false));
  }, [pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  const handleActivate = async (order) => {
    setBusyId(order.id);
    setRowMessages((m) => ({ ...m, [order.id]: null }));
    try {
      const { data } = await api.post(`/customer/purchases/${order.id}/activate`);
      if (!data.success) {
        setRowMessages((m) => ({ ...m, [order.id]: data.message }));
      } else {
        load();
      }
    } catch (err) {
      setRowMessages((m) => ({ ...m, [order.id]: err.response?.data?.message || 'Could not activate. Please try again.' }));
    } finally {
      setBusyId(null);
    }
  };

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;
  if (error) return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;

  if (orders.length === 0) {
    return (
      <div className="bg-white rounded-xl border border-slate-200 p-8 text-center">
        <p className="text-slate-400 text-sm">No purchases yet.</p>
      </div>
    );
  }

  const CONTINUABLE = ['pending', 'processing'];

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-slate-500 border-b border-slate-100">
            <th className="px-4 py-3 font-medium">Reference</th>
            <th className="px-4 py-3 font-medium">Package</th>
            <th className="px-4 py-3 font-medium">Amount</th>
            <th className="px-4 py-3 font-medium">Date</th>
            <th className="px-4 py-3 font-medium">Status</th>
            <th className="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {orders.map((o) => (
            <tr key={o.id} className="border-b border-slate-50 last:border-0">
              <td className="px-4 py-3 font-mono text-xs text-slate-500">{o.reference}</td>
              <td className="px-4 py-3 text-slate-700">{o.package?.name}</td>
              <td className="px-4 py-3 text-slate-700">{currency(o.amount)}</td>
              <td className="px-4 py-3 text-slate-500">{new Date(o.created_at).toLocaleDateString()}</td>
              <td className="px-4 py-3">
                <StatusBadge status={o.status} />
              </td>
              <td className="px-4 py-3 text-right">
                {CONTINUABLE.includes(o.status) && (
                  <Link to={`/payment/${o.reference}`} className="text-xs text-indigo-600 font-medium hover:underline">
                    Continue →
                  </Link>
                )}
                {o.status === 'queued' && (
                  <div className="flex flex-col items-end gap-1">
                    <button
                      onClick={() => handleActivate(o)}
                      disabled={busyId === o.id}
                      className="text-xs text-indigo-600 font-medium hover:underline disabled:opacity-50"
                    >
                      Activate
                    </button>
                    {rowMessages[o.id] && (
                      <p className="text-[11px] text-red-600 max-w-[180px] text-right">{rowMessages[o.id]}</p>
                    )}
                  </div>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <TablePagination {...pagination} onPageChange={pagination.setPage} />
    </div>
  );
}
