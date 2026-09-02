import { useEffect, useState } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

/**
 * Built against /customer/purchases (the unified Purchase model), not a
 * dedicated /customer/payments endpoint — that route never existed after
 * the unified purchase refactor, and even if it had, a Payment-only
 * source would only ever show Paystack transactions. A MoMo customer's
 * actual payments never create a Payment record at all (see
 * Customer\PurchaseController::storeMomo()), so Purchase is the only
 * source that genuinely covers both gateways.
 */
export default function CustomerPayments() {
  const [purchases, setPurchases] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const pagination = useServerPagination();

  useEffect(() => {
    api
      .get('/customer/purchases', { params: pagination.requestParams })
      .then(({ data }) => setPurchases(pagination.capture(data)))
      .catch(() => setError('Could not load your payment history.'))
      .finally(() => setLoading(false));
  }, [pagination.page]);

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900 mb-4">Payment History</h1>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Date</th>
              <th className="px-5 py-3 font-medium">Reference</th>
              <th className="px-5 py-3 font-medium">Package</th>
              <th className="px-5 py-3 font-medium">Via</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">Loading…</td>
              </tr>
            )}
            {!loading && purchases.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">No payments yet.</td>
              </tr>
            )}
            {!loading &&
              purchases.map((p) => (
                <tr key={p.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3 text-slate-600">
                    {new Date(p.verified_at || p.created_at).toLocaleDateString()}
                  </td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-500">{p.reference}</td>
                  <td className="px-5 py-3 text-slate-600">{p.package?.name || '—'}</td>
                  <td className="px-5 py-3 text-slate-500 capitalize">{p.payment_method}</td>
                  <td className="px-5 py-3 text-slate-700">{currency(p.amount)}</td>
                  <td className="px-5 py-3">
                    <StatusBadge status={p.status} />
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
