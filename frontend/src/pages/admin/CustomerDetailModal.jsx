import Modal from '../../components/Modal';
import StatusBadge from '../../components/StatusBadge';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function CustomerDetailModal({ customer, onClose }) {
  const subscriptionsPagination = useClientPagination(customer.subscriptions || []);
  const paymentsPagination = useClientPagination(customer.payments || []);
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
    </Modal>
  );
}
