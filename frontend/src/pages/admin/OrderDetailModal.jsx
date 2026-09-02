import { useState } from 'react';
import Modal from '../../components/Modal';
import StatusBadge from '../../components/StatusBadge';
import api from '../../services/api';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function OrderDetailModal({ order, onClose, onUpdated }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [showRejectForm, setShowRejectForm] = useState(false);
  const smsPagination = useClientPagination(order.sms_logs || []);

  const runAction = async (action, body) => {
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post(`/admin/purchases/${order.id}/${action}`, body);
      onUpdated(data.order || data);
    } catch (err) {
      setError(err.response?.data?.message || 'Action failed.');
    } finally {
      setBusy(false);
    }
  };

  const canApprove = ['pending', 'processing', 'manual_review'].includes(order.status);
  const canReject = !['completed', 'voucher_assigned', 'failed', 'cancelled'].includes(order.status);
  const canCancel = ['pending', 'processing'].includes(order.status);
  const canRetryVoucher = order.status === 'verified' && !order.voucher_id;

  return (
    <Modal title={`Order ${order.reference}`} onClose={onClose} wide>
      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>
      )}

      <div className="grid sm:grid-cols-2 gap-4 text-sm mb-5">
        <div>
          <p className="text-slate-400">Customer</p>
          <p className="text-slate-800 font-medium">{order.customer?.full_name}</p>
          <p className="text-slate-500 text-xs">{order.customer?.phone}</p>
        </div>
        <div>
          <p className="text-slate-400">Package / Amount</p>
          <p className="text-slate-800 font-medium">{order.package?.name} — {currency(order.amount)}</p>
        </div>
        <div>
          <p className="text-slate-400">Status</p>
          <StatusBadge status={order.status} />
        </div>
        <div>
          <p className="text-slate-400">Verification</p>
          <p className="text-slate-800">{order.verification_method || '—'} {order.verified_by ? `by ${order.verified_by.name}` : ''}</p>
        </div>
        <div>
          <p className="text-slate-400">Voucher</p>
          <p className="text-slate-800 font-mono">{order.voucher?.code || '—'}</p>
        </div>
        <div>
          <p className="text-slate-400">MoMo Transaction ID</p>
          <p className="text-slate-800">{order.momo_transaction_id || '—'}</p>
        </div>
      </div>

      {order.admin_notes && (
        <div className="mb-5 text-sm bg-amber-50 border border-amber-200 rounded-md px-3 py-2 text-amber-800">
          {order.admin_notes}
        </div>
      )}

      <h3 className="text-sm font-semibold text-slate-700 mb-2">Matched SMS Evidence</h3>
      <div className="border border-slate-200 rounded-md overflow-hidden mb-5">
        <table className="w-full text-xs">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
              <th className="px-3 py-2 font-medium">Received</th>
              <th className="px-3 py-2 font-medium">Sender</th>
              <th className="px-3 py-2 font-medium">Amount</th>
              <th className="px-3 py-2 font-medium">Transaction ID</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {(!order.sms_logs || order.sms_logs.length === 0) && (
              <tr><td colSpan={5} className="px-3 py-4 text-center text-slate-400">No SMS matched to this order.</td></tr>
            )}
            {smsPagination.rows.map((log) => (
              <tr key={log.id} className="border-b border-slate-50 last:border-0">
                <td className="px-3 py-2">{new Date(log.received_at).toLocaleString()}</td>
                <td className="px-3 py-2">{log.sender || '—'}</td>
                <td className="px-3 py-2">{log.amount != null ? currency(log.amount) : '—'}</td>
                <td className="px-3 py-2 font-mono">{log.transaction_id || '—'}</td>
                <td className="px-3 py-2"><StatusBadge status={log.verification_status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...smsPagination} onPageChange={smsPagination.setPage} />
      </div>

      {showRejectForm ? (
        <div className="border border-red-200 bg-red-50 rounded-md p-4 mb-2">
          <label className="block text-xs font-medium text-red-800 mb-1">Reason for rejection</label>
          <textarea
            className="input"
            rows={2}
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
          />
          <div className="flex justify-end gap-2 mt-2">
            <button onClick={() => setShowRejectForm(false)} className="text-xs text-slate-500">Cancel</button>
            <button
              onClick={() => runAction('reject', { reason: rejectReason })}
              disabled={busy || !rejectReason}
              className="text-xs bg-red-600 text-white px-3 py-1.5 rounded-md disabled:opacity-50"
            >
              Confirm Reject
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap gap-2 justify-end pt-2 border-t border-slate-100">
          {canRetryVoucher && (
            <button
              onClick={() => runAction('assign-voucher')}
              disabled={busy}
              className="text-sm border border-indigo-200 text-indigo-600 px-3 py-1.5 rounded-md hover:bg-indigo-50 disabled:opacity-50"
            >
              Retry Voucher Assignment
            </button>
          )}
          {canCancel && (
            <button
              onClick={() => runAction('cancel')}
              disabled={busy}
              className="text-sm border border-slate-300 text-slate-600 px-3 py-1.5 rounded-md hover:bg-slate-50 disabled:opacity-50"
            >
              Cancel Order
            </button>
          )}
          {canReject && (
            <button
              onClick={() => setShowRejectForm(true)}
              disabled={busy}
              className="text-sm border border-red-200 text-red-600 px-3 py-1.5 rounded-md hover:bg-red-50 disabled:opacity-50"
            >
              Reject Payment
            </button>
          )}
          {canApprove && (
            <button
              onClick={() => runAction('approve')}
              disabled={busy}
              className="text-sm bg-indigo-600 text-white px-3 py-1.5 rounded-md hover:bg-indigo-700 disabled:opacity-50"
            >
              Approve Payment
            </button>
          )}
        </div>
      )}
    </Modal>
  );
}
