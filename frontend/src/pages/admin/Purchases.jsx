import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import SearchFilterInput from '../../components/SearchFilterInput';
import ConfirmDialog from '../../components/ConfirmDialog';
import OrderDetailModal from './OrderDetailModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'processing', label: 'Processing' },
  { value: 'verified', label: 'Verified' },
  { value: 'active', label: 'Active' },
  { value: 'voucher_assigned', label: 'Voucher Assigned' },
  { value: 'completed', label: 'Completed' },
  { value: 'queued', label: 'Queued' },
  { value: 'pending_activation', label: 'Pending Activation' },
  { value: 'manual_review', label: 'Manual Review' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'failed', label: 'Failed' },
  { value: 'expired', label: 'Expired' },
  { value: 'cancelled', label: 'Cancelled' },
];

const FULFILLMENT_FILTERS = [
  { value: '', label: 'All Types' },
  { value: 'live', label: 'Live' },
  { value: 'voucher', label: 'Voucher' },
];

const ACTIONS_FOR = {
  active: ['suspend', 'cancel'],
  voucher_assigned: ['suspend', 'cancel'],
  completed: ['suspend', 'cancel'],
  queued: ['cancel'],
  suspended: ['activate', 'cancel'],
  pending_activation: ['retry-activation', 'cancel'],
  pending: ['cancel'],
  processing: ['cancel'],
  verified: ['assign-voucher'],
  manual_review: ['approve', 'reject'],
};

const ACTION_LABELS = {
  suspend: 'Suspend',
  activate: 'Reactivate',
  cancel: 'Cancel',
  'retry-activation': 'Retry Activation',
  'assign-voucher': 'Assign Voucher',
  approve: 'Approve',
  reject: 'Reject',
};

export default function AdminPurchases() {
  const [purchases, setPurchases] = useState([]);
  const [status, setStatus] = useState('');
  const [fulfillment, setFulfillment] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [confirmTarget, setConfirmTarget] = useState(null);
  const [detailPurchase, setDetailPurchase] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/purchases', {
        params: {
          ...pagination.requestParams,
          status: status || undefined,
          fulfillment_type: fulfillment || undefined,
          search: search || undefined,
        },
      })
      .then(({ data }) => setPurchases(pagination.capture(data)))
      .catch(() => setError('Could not load purchases.'))
      .finally(() => setLoading(false));
  }, [status, fulfillment, search, pagination.page]);

  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);

  const runAction = async (purchase, action, body) => {
    setBusyId(purchase.id);
    setActionError('');
    try {
      await api.post(`/admin/purchases/${purchase.id}/${action}`, body);
      setConfirmTarget(null);
      load();
    } catch (err) {
      setActionError(err.response?.data?.message || 'Action failed.');
      setConfirmTarget(null);
    } finally {
      setBusyId(null);
    }
  };

  const handleActionClick = (purchase, action) => {
    if (['suspend', 'cancel', 'reject'].includes(action)) {
      setConfirmTarget({ purchase, action });
    } else {
      runAction(purchase, action);
    }
  };

  const openDetail = (purchase) => {
    api.get(`/admin/purchases/${purchase.id}`).then(({ data }) => setDetailPurchase(data));
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Purchases</h1>
        <p className="text-slate-500 text-sm mt-1">
          Every customer purchase — live subscriptions and voucher assignments, unified.
        </p>
      </div>

      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <SearchFilterInput value={search} onChange={setSearch} placeholder="Search by name, phone, or reference…" />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="input sm:w-56 shrink-0"
        >
          {STATUS_FILTERS.map((f) => (
            <option key={f.value} value={f.value}>{f.label}</option>
          ))}
        </select>
      </div>

      <div className="mb-4 flex gap-2 flex-wrap">
        {FULFILLMENT_FILTERS.map((f) => (
          <button
            key={f.value}
            onClick={() => setFulfillment(f.value)}
            className={`px-3 py-1.5 rounded-md text-xs font-medium border whitespace-nowrap ${
              fulfillment === f.value
                ? 'bg-indigo-600 text-white border-indigo-600'
                : 'bg-white text-slate-600 border-slate-200'
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}
      {actionError && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{actionError}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Customer</th>
              <th className="px-5 py-3 font-medium">Package</th>
              <th className="px-5 py-3 font-medium">Router</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Fulfilled Via</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>
            )}
            {!loading && purchases.length === 0 && (
              <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">No purchases found.</td></tr>
            )}
            {!loading &&
              purchases.map((p) => (
                <tr
                  key={p.id}
                  className="border-b border-slate-50 last:border-0 cursor-pointer hover:bg-slate-50"
                  onClick={() => openDetail(p)}
                >
                  <td className="px-5 py-3">
                    <div className="text-slate-900 font-medium">{p.customer?.full_name || 'Guest'}</div>
                    <div className="text-xs text-slate-400">{p.reference}</div>
                  </td>
                  <td className="px-5 py-3 text-slate-600">{p.package?.name}</td>
                  <td className="px-5 py-3 text-slate-600">{p.router?.name || '—'}</td>
                  <td className="px-5 py-3 text-slate-600">{currency(p.amount)}</td>
                  <td className="px-5 py-3">
                    <span className={`text-xs font-medium ${p.fulfillment_type === 'live' ? 'text-emerald-600' : 'text-indigo-600'}`}>
                      {p.fulfillment_type === 'live' ? '🟢 Live' : '🎫 Voucher'}
                    </span>
                  </td>
                  <td className="px-5 py-3"><StatusBadge status={p.status} /></td>
                  <td className="px-5 py-3" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center justify-end gap-2">
                      {(ACTIONS_FOR[p.status] || []).map((action) => (
                        <button
                          key={action}
                          onClick={() => handleActionClick(p, action)}
                          disabled={busyId === p.id}
                          className={`text-xs font-medium px-2.5 py-1 rounded-md border disabled:opacity-50 ${
                            ['cancel', 'reject'].includes(action)
                              ? 'text-red-600 border-red-200 hover:bg-red-50'
                              : 'text-indigo-600 border-indigo-200 hover:bg-indigo-50'
                          }`}
                        >
                          {ACTION_LABELS[action]}
                        </button>
                      ))}
                    </div>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {confirmTarget && (
        <ConfirmDialog
          title={ACTION_LABELS[confirmTarget.action]}
          message={
            confirmTarget.action === 'cancel'
              ? `Cancel ${confirmTarget.purchase.customer?.full_name || 'this guest'}'s purchase? This is terminal.`
              : confirmTarget.action === 'reject'
                ? `Reject ${confirmTarget.purchase.customer?.full_name || 'this guest'}'s payment?`
                : `Suspend ${confirmTarget.purchase.customer?.full_name || 'this guest'}'s purchase?`
          }
          confirmLabel={ACTION_LABELS[confirmTarget.action]}
          danger={['cancel', 'reject'].includes(confirmTarget.action)}
          loading={busyId === confirmTarget.purchase.id}
          onConfirm={() => runAction(confirmTarget.purchase, confirmTarget.action)}
          onCancel={() => setConfirmTarget(null)}
        />
      )}

      {detailPurchase && (
        <OrderDetailModal
          order={detailPurchase}
          onClose={() => setDetailPurchase(null)}
          onUpdated={(updated) => {
            setDetailPurchase(updated);
            load();
          }}
        />
      )}
    </div>
  );
}
