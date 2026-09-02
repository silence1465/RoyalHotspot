import { useCallback, useEffect, useState } from 'react';
import api from '../../services/api';
import StatCard from '../../components/StatCard';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (value) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(value || 0);
const months = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function AccountingHistory() {
  const currentYear = new Date().getFullYear();
  const [entries, setEntries] = useState([]);
  const [summary, setSummary] = useState(null);
  const [routers, setRouters] = useState([]);
  const [years, setYears] = useState([currentYear]);
  const [year, setYear] = useState(currentYear);
  const [month, setMonth] = useState('');
  const [routerId, setRouterId] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api.get('/admin/reports/accounting', {
      params: {
        ...pagination.requestParams,
        year,
        month: month || undefined,
        router_id: routerId || undefined,
        payment_method: paymentMethod || undefined,
      },
    }).then(({ data }) => {
      setEntries(pagination.capture(data.entries));
      setSummary(data.summary);
      setRouters(data.routers || []);
      setYears(data.years?.length ? data.years : [currentYear]);
    }).catch((err) => {
      setError(err.response?.data?.message || 'Could not load accounting history.');
    }).finally(() => setLoading(false));
  }, [year, month, routerId, paymentMethod, pagination.page]);

  useEffect(() => { load(); }, [load]);

  const changeFilter = (setter) => (event) => {
    setter(event.target.value);
    pagination.resetPage();
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Accounting History</h1>
        <p className="text-sm text-slate-500 mt-1">Verified income from Paystack and direct Mobile Money, organized by period and router.</p>
      </div>

      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <select className="input" value={year} onChange={changeFilter(setYear)} aria-label="Year">
          {years.map((value) => <option key={value} value={value}>{value}</option>)}
        </select>
        <select className="input" value={month} onChange={changeFilter(setMonth)} aria-label="Month">
          <option value="">All months</option>
          {months.map((label, index) => <option key={label} value={index + 1}>{label}</option>)}
        </select>
        <select className="input" value={routerId} onChange={changeFilter(setRouterId)} aria-label="Router">
          <option value="">All routers</option>
          {routers.map((router) => (
            <option key={router.id} value={router.id}>
              {router.name}{router.location ? ` — ${router.location}` : ''}{router.deleted_at ? ' (removed)' : ''}
            </option>
          ))}
        </select>
        <select className="input" value={paymentMethod} onChange={changeFilter(setPaymentMethod)} aria-label="Payment method">
          <option value="">All payment methods</option>
          <option value="momo">Direct Mobile Money</option>
          <option value="paystack">Paystack</option>
          <option value="admin_grant">Admin Grant</option>
          <option value="free_trial">Free Internet</option>
        </select>
      </div>

      {summary && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <StatCard label="Transactions" value={summary.transaction_count} />
          <StatCard label="Package Value" value={currency(summary.subtotal)} />
          <StatCard label="Payment Charges" value={currency(summary.fees)} />
          <StatCard label="Total Collected" value={currency(summary.total)} tone="positive" />
        </div>
      )}

      {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Reference</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Router</th>
              <th className="px-4 py-3 font-medium">Package</th>
              <th className="px-4 py-3 font-medium">Method</th>
              <th className="px-4 py-3 font-medium text-right">Subtotal</th>
              <th className="px-4 py-3 font-medium text-right">Charge</th>
              <th className="px-4 py-3 font-medium text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={9} className="px-4 py-8 text-center text-slate-400">Loading…</td></tr>}
            {!loading && entries.length === 0 && <tr><td colSpan={9} className="px-4 py-8 text-center text-slate-400">No verified transactions match these filters.</td></tr>}
            {!loading && entries.map((entry) => (
              <tr key={entry.id} className="border-b border-slate-50 last:border-0">
                <td className="px-4 py-3 text-slate-500 whitespace-nowrap">{new Date(entry.verified_at).toLocaleString()}</td>
                <td className="px-4 py-3 font-mono text-xs text-slate-600">{entry.reference}</td>
                <td className="px-4 py-3 text-slate-700 whitespace-nowrap">{entry.customer?.full_name || entry.guest_phone || 'Guest'}</td>
                <td className="px-4 py-3 text-slate-600 whitespace-nowrap">{entry.router?.name || '—'}</td>
                <td className="px-4 py-3 text-slate-600 whitespace-nowrap">{entry.package?.name || '—'}</td>
                <td className="px-4 py-3 text-slate-500 capitalize whitespace-nowrap">{entry.payment_method.replace(/_/g, ' ')}</td>
                <td className="px-4 py-3 text-slate-600 text-right">{currency(entry.subtotal)}</td>
                <td className="px-4 py-3 text-slate-600 text-right">{currency(entry.payment_fee)}</td>
                <td className="px-4 py-3 font-medium text-slate-900 text-right">{currency(entry.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>
    </div>
  );
}
