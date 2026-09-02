import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import ConfirmDialog from '../../components/ConfirmDialog';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'pending', label: 'Pending' },
  { value: 'pending_activation', label: 'Pending Activation' },
  { value: 'expired', label: 'Expired' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'cancelled', label: 'Cancelled' },
];

// Which action buttons make sense for a given status.
const ACTIONS_FOR = {
  active: ['suspend', 'cancel'],
  suspended: ['activate', 'cancel'],
  pending_activation: ['retry-activation', 'cancel'],
  pending: ['cancel'],
};

export default function AdminSubscriptions() {
  const [subscriptions, setSubscriptions] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [confirmTarget, setConfirmTarget] = useState(null); // { subscription, action }
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/subscriptions', { params: { status: status || undefined, ...pagination.requestParams } })
      .then(({ data }) => setSubscriptions(pagination.capture(data)))
      .catch(() => setError('Could not load subscriptions. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [status, pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  const runAction = async (subscription, action) => {
    setBusyId(subscription.id);
    setActionError('');
    try {
      await api.post(`/admin/subscriptions/${subscription.id}/${action}`);
      setConfirmTarget(null);
      load();
    } catch (err) {
      setActionError(err.response?.data?.message || 'Action failed.');
      setConfirmTarget(null);
    } finally {
      setBusyId(null);
    }
  };

  const handleActionClick = (subscription, action) => {
    // suspend/cancel are consequential enough to confirm; retry/activate
    // (un-suspend) are lower-stakes and just fire immediately.
    if (action === 'suspend' || action === 'cancel') {
      setConfirmTarget({ subscription, action });
    } else {
      runAction(subscription, action);
    }
  };

  const ACTION_LABELS = {
    suspend: 'Suspend',
    activate: 'Reactivate',
    cancel: 'Cancel',
    'retry-activation': 'Retry Activation',
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Subscriptions</h1>
        <p className="text-slate-500 text-sm mt-1">
          Every customer subscription, its status, and manual overrides.
        </p>
      </div>

      <div className="mb-4 flex gap-2 overflow-x-auto">
        {STATUS_FILTERS.map((f) => (
          <button
            key={f.value}
            onClick={() => setStatus(f.value)}
            className={`px-3 py-1.5 rounded-md text-sm font-medium whitespace-nowrap border ${
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
      {actionError && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">
          {actionError}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Customer</th>
              <th className="px-5 py-3 font-medium">Package</th>
              <th className="px-5 py-3 font-medium">Router</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Expires</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={7} className="px-5 py-6 text-center text-slate-400">Loading…</td>
              </tr>
            )}
            {!loading && subscriptions.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-6 text-center text-slate-400">No subscriptions found.</td>
              </tr>
            )}
            {!loading &&
              subscriptions.map((sub) => (
                <tr key={sub.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3">
                    <div className="text-slate-900 font-medium">{sub.customer?.full_name}</div>
                    <div className="text-xs text-slate-400">{sub.customer?.phone}</div>
                  </td>
                  <td className="px-5 py-3 text-slate-600">{sub.package?.name}</td>
                  <td className="px-5 py-3 text-slate-600">{sub.router?.name}</td>
                  <td className="px-5 py-3 text-slate-600">{currency(sub.amount)}</td>
                  <td className="px-5 py-3 text-slate-600">
                    {sub.expires_at ? new Date(sub.expires_at).toLocaleString() : '—'}
                  </td>
                  <td className="px-5 py-3">
                    <StatusBadge status={sub.status} />
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex items-center justify-end gap-2">
                      {(ACTIONS_FOR[sub.status] || []).map((action) => (
                        <button
                          key={action}
                          onClick={() => handleActionClick(sub, action)}
                          disabled={busyId === sub.id}
                          className={`text-xs font-medium px-2.5 py-1 rounded-md border disabled:opacity-50 ${
                            action === 'cancel'
                              ? 'text-red-600 border-red-200 hover:bg-red-50'
                              : 'text-indigo-600 border-indigo-200 hover:bg-indigo-50'
                          }`}
                        >
                          {ACTION_LABELS[action]}
                        </button>
                      ))}
                      {(ACTIONS_FOR[sub.status] || []).length === 0 && (
                        <span className="text-xs text-slate-300">—</span>
                      )}
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
              ? `Cancel ${confirmTarget.subscription.customer?.full_name}'s subscription? This is terminal and disables their hotspot access immediately.`
              : `Suspend ${confirmTarget.subscription.customer?.full_name}'s subscription? Their remaining time is preserved and access can be restored later.`
          }
          confirmLabel={ACTION_LABELS[confirmTarget.action]}
          danger={confirmTarget.action === 'cancel'}
          loading={busyId === confirmTarget.subscription.id}
          onConfirm={() => runAction(confirmTarget.subscription, confirmTarget.action)}
          onCancel={() => setConfirmTarget(null)}
        />
      )}
    </div>
  );
}
