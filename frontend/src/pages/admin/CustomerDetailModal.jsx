import { useState } from 'react';
import Modal from '../../components/Modal';
import StatusBadge from '../../components/StatusBadge';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function CustomerDetailModal({ customer, onClose, onDeleted }) {
  const { user } = useAuth();
  const [resettingId, setResettingId] = useState(null);
  const [resetResult, setResetResult] = useState(null);
  const [resetError, setResetError] = useState('');
  const [deleting, setDeleting] = useState(false);
  const canResetPassword = user?.role === 'super_admin' || user?.permissions == null || user?.permissions?.includes('customers.manage');
  const subscriptionsPagination = useClientPagination(customer.subscriptions || []);
  const paymentsPagination = useClientPagination(customer.payments || []);

  const resetWifiPassword = async (hotspotUser) => {
    if (!window.confirm(`Reset the Wi-Fi password for ${hotspotUser.username} on ${hotspotUser.router?.name || 'this router'}? Existing sessions will be disconnected.`)) return;
    setResettingId(hotspotUser.id);
    setResetResult(null);
    setResetError('');
    try {
      const { data } = await api.post(`/admin/customers/${customer.id}/hotspot-users/${hotspotUser.id}/reset-password`);
      setResetResult(data);
    } catch (error) {
      setResetError(error.response?.data?.message || 'Could not reset the Wi-Fi password.');
    } finally {
      setResettingId(null);
    }
  };

  const deleteTestData = async () => {
    const confirmation = window.prompt(
      `Permanently delete ${customer.full_name}, all purchases, payments, usage logs, sessions, and MikroTik accounts?\n\nType DELETE TEST DATA to continue.`
    );
    if (confirmation !== 'DELETE TEST DATA') return;
    setDeleting(true);
    setResetError('');
    try {
      await api.delete(`/admin/customers/${customer.id}/test-data`, {
        data: { confirmation },
      });
      onDeleted?.();
    } catch (error) {
      setResetError(error.response?.data?.message || 'Could not delete the test customer data.');
    } finally {
      setDeleting(false);
    }
  };
  return (
    <Modal title={customer.full_name} onClose={onClose} wide>
      <div className="grid sm:grid-cols-3 gap-4 text-sm mb-5">
        <div>
          <p className="text-slate-400">Phone</p>
          <p className="text-slate-800">{customer.phone}</p>
        </div>
        <div>
          <p className="text-slate-400">Username</p>
          <p className="text-slate-800">{customer.username}</p>
        </div>
        <div>
          <p className="text-slate-400">Status</p>
          <StatusBadge status={customer.status} />
        </div>
      </div>

      <h3 className="text-sm font-semibold text-slate-700 mb-2">Wi-Fi Accounts</h3>
      <div className="border border-slate-200 rounded-md overflow-hidden mb-5">
        {(customer.hotspot_users || []).length === 0 ? (
          <p className="px-3 py-4 text-center text-sm text-slate-400">No Wi-Fi account has been provisioned yet.</p>
        ) : (
          <div className="divide-y divide-slate-100">
            {(customer.hotspot_users || []).map((hotspotUser) => (
              <div key={hotspotUser.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-3 text-sm">
                <div>
                  <p className="font-medium text-slate-800">{hotspotUser.username}</p>
                  <p className="text-xs text-slate-400">{hotspotUser.router?.name || 'Unknown router'} · {hotspotUser.disabled ? 'Disabled' : 'Enabled'}</p>
                </div>
                {canResetPassword && (
                  <button
                    type="button"
                    disabled={resettingId === hotspotUser.id}
                    onClick={() => resetWifiPassword(hotspotUser)}
                    className="rounded-md border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                  >
                    {resettingId === hotspotUser.id ? 'Resetting…' : 'Reset Wi-Fi Password'}
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {resetError && <div className="mb-5 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{resetError}</div>}
      {resetResult && (
        <div className="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
          <p className="font-semibold">New Wi-Fi credentials</p>
          <p className="mt-1">Username: <span className="font-mono font-semibold">{resetResult.username}</span></p>
          <p>Password: <span className="font-mono font-semibold">{resetResult.temporary_password}</span></p>
          <p className="mt-2 text-xs text-emerald-700">Copy this password now and give it securely to the customer.</p>
        </div>
      )}

      <h3 className="text-sm font-semibold text-slate-700 mb-2">Subscription History</h3>
      <div className="border border-slate-200 rounded-md overflow-hidden mb-5">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
              <th className="px-3 py-2 font-medium">Package</th>
              <th className="px-3 py-2 font-medium">Router</th>
              <th className="px-3 py-2 font-medium">Expires</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {(!customer.subscriptions || customer.subscriptions.length === 0) && (
              <tr>
                <td colSpan={4} className="px-3 py-4 text-center text-slate-400">No subscriptions yet.</td>
              </tr>
            )}
            {subscriptionsPagination.rows.map((s) => (
              <tr key={s.id} className="border-b border-slate-50 last:border-0">
                <td className="px-3 py-2">{s.package?.name}</td>
                <td className="px-3 py-2">{s.router?.name}</td>
                <td className="px-3 py-2">{s.expires_at ? new Date(s.expires_at).toLocaleString() : '—'}</td>
                <td className="px-3 py-2"><StatusBadge status={s.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...subscriptionsPagination} onPageChange={subscriptionsPagination.setPage} />
      </div>

      <h3 className="text-sm font-semibold text-slate-700 mb-2">Recent Payments</h3>
      <div className="border border-slate-200 rounded-md overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
              <th className="px-3 py-2 font-medium">Reference</th>
              <th className="px-3 py-2 font-medium">Amount</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {(!customer.payments || customer.payments.length === 0) && (
              <tr>
                <td colSpan={3} className="px-3 py-4 text-center text-slate-400">No payments yet.</td>
              </tr>
            )}
            {paymentsPagination.rows.map((p) => (
              <tr key={p.id} className="border-b border-slate-50 last:border-0">
                <td className="px-3 py-2 font-mono text-xs">{p.reference}</td>
                <td className="px-3 py-2">{currency(p.amount)}</td>
                <td className="px-3 py-2"><StatusBadge status={p.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...paymentsPagination} onPageChange={paymentsPagination.setPage} />
      </div>

      {user?.role === 'super_admin' && (
        <div className="mt-6 border-t border-red-100 pt-4">
          <button
            type="button"
            disabled={deleting}
            onClick={deleteTestData}
            className="rounded-md border border-red-300 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            {deleting ? 'Deleting test data…' : 'Delete Test Customer and Purchases'}
          </button>
          <p className="mt-1 text-xs text-slate-400">Cancel active purchases first. This permanently removes the selected test customer and associated records.</p>
        </div>
      )}
    </Modal>
  );
}
