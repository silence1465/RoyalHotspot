import { useEffect, useState } from 'react';
import { LineChart, Line, AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import api from '../../services/api';
import StatCard from '../../components/StatCard';
import StatusBadge from '../../components/StatusBadge';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function AdminDashboard() {
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/dashboard/stats')
      .then(({ data }) => setStats(data))
      .catch(() => setError('Could not load dashboard. Is the backend running?'));
  }, []);

  if (error) {
    return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  }
  if (!stats) return <p className="text-sm text-slate-400">Loading…</p>;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <p className="text-slate-500 text-sm mt-1">System overview.</p>
      </div>

      {/* Row 1 — Core stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total Customers" value={stats.total_customers} />
        <StatCard label="Active Customers" value={stats.active_customers} tone="positive" />
        <StatCard label="Today Revenue" value={currency(stats.today_revenue)} tone="positive" />
        <StatCard label="Month Revenue" value={currency(stats.month_revenue)} tone="positive" />
      </div>

      {/* Row 2 — Purchase status breakdown (moved to the top) */}
      {stats.purchases_by_status && Object.keys(stats.purchases_by_status).length > 0 && (
        <div>
          <h2 className="text-sm font-semibold text-slate-700 mb-3">Purchases by Status</h2>
          <div className="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-3">
            {Object.entries(stats.purchases_by_status).map(([s, count]) => (
              <div key={s} className="bg-white rounded-lg border border-slate-200 px-3 py-2 text-center">
                <p className="text-lg font-semibold text-slate-900">{count}</p>
                <p className="text-xs text-slate-400 capitalize">{s.replace(/_/g, ' ')}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Row 3 — Purchases + gateway + bandwidth */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Active Purchases" value={stats.active_purchases} tone="positive" />
        <StatCard
          label="Active Gateways"
          value={[
            stats.payment_methods?.paystack && 'Paystack',
            stats.payment_methods?.momo && 'Mobile Money',
          ].filter(Boolean).join(' + ') || 'None'}
        />
        <StatCard label="Today Bandwidth" value={formatBytes(stats.today_bandwidth)} tone="positive" />
        <StatCard label="Month Bandwidth" value={formatBytes(stats.month_bandwidth)} tone="positive" />
      </div>

      {/* Row 4 — System health */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Routers (Live)" value={stats.live_routers} tone="positive" />
        <StatCard label="Routers (Manual)" value={stats.manual_routers} />
        <StatCard label="Routers Online" value={stats.online_routers} tone="positive" />
        <StatCard label="Low Stock Packages" value={stats.low_stock_package_count} tone={stats.low_stock_package_count > 0 ? 'warning' : undefined} />
      </div>

      {/* Alerts */}
      {stats.pending_activation > 0 && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-md px-4 py-3">
          {stats.pending_activation} purchase{stats.pending_activation > 1 ? 's' : ''} stuck in{' '}
          <strong>pending activation</strong> — a MikroTik activation failed after retries.
        </div>
      )}

      {stats.manual_review > 0 && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-md px-4 py-3">
          {stats.manual_review} purchase{stats.manual_review > 1 ? 's need' : ' needs'}{' '}
          <strong>manual review</strong> — check the Purchases page.
        </div>
      )}

      {stats.low_stock_package_count > 0 && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-md px-4 py-3">
          {stats.low_stock_package_count} voucher package{stats.low_stock_package_count > 1 ? 's are' : ' is'}{' '}
          running low on available codes — check the Vouchers page.
        </div>
      )}

      {/* Row 5 — Real charts */}
      <div className="grid lg:grid-cols-2 gap-6">
        <RevenueTrendChart />
        <GatewaySplitChart />
      </div>

      {/* Row 6 — Active users right now */}
      <ActiveUsersPanel />

      {/* Row 7 — Recent activity tables */}
      <div className="grid lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="px-5 py-3 border-b border-slate-200">
            <h2 className="text-sm font-semibold text-slate-700">Recent Purchases</h2>
          </div>
          <table className="w-full text-sm">
            <tbody>
              {stats.recent_purchases.length === 0 && (
                <tr><td colSpan={4} className="px-5 py-4 text-slate-400 text-center">No purchases yet.</td></tr>
              )}
              {stats.recent_purchases.map((p) => (
                <tr key={p.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-2.5 text-slate-700">{p.customer?.full_name || 'Guest'}</td>
                  <td className="px-5 py-2.5 text-slate-500">{currency(p.amount)}</td>
                  <td className="px-5 py-2.5">
                    <span className={`text-xs ${p.fulfillment_type === 'live' ? 'text-emerald-600' : 'text-indigo-600'}`}>
                      {p.fulfillment_type === 'live' ? 'Live' : 'Voucher'}
                    </span>
                  </td>
                  <td className="px-5 py-2.5"><StatusBadge status={p.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="px-5 py-3 border-b border-slate-200">
            <h2 className="text-sm font-semibold text-slate-700">Recent Paystack Payments</h2>
          </div>
          <table className="w-full text-sm">
            <tbody>
              {stats.recent_payments.length === 0 && (
                <tr><td colSpan={3} className="px-5 py-4 text-slate-400 text-center">No payments yet.</td></tr>
              )}
              {stats.recent_payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-2.5 text-slate-700">{p.customer?.full_name || '—'}</td>
                  <td className="px-5 py-2.5">{currency(p.amount)}</td>
                  <td className="px-5 py-2.5"><StatusBadge status={p.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="px-5 py-3 border-b border-slate-200">
            <h2 className="text-sm font-semibold text-slate-700">Recent Customers</h2>
          </div>
          <table className="w-full text-sm">
            <tbody>
              {stats.recent_customers.length === 0 && (
                <tr><td colSpan={3} className="px-5 py-4 text-slate-400 text-center">No customers yet.</td></tr>
              )}
              {stats.recent_customers.map((c) => (
                <tr key={c.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-2.5 text-slate-700">{c.full_name}</td>
                  <td className="px-5 py-2.5 text-slate-500">{c.phone}</td>
                  <td className="px-5 py-2.5"><StatusBadge status={c.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="px-5 py-3 border-b border-slate-200">
            <h2 className="text-sm font-semibold text-slate-700">Recent PDF Imports</h2>
          </div>
          <table className="w-full text-sm">
            <tbody>
              {stats.recent_imports.length === 0 && (
                <tr><td colSpan={3} className="px-5 py-4 text-slate-400 text-center">No imports yet.</td></tr>
              )}
              {stats.recent_imports.map((b) => (
                <tr key={b.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-2.5 text-slate-700">{b.original_filename}</td>
                  <td className="px-5 py-2.5 text-slate-500">{b.total_imported} imported</td>
                  <td className="px-5 py-2.5">
                    <StatusBadge status={b.status === 'pending_review' ? 'pending' : b.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

const currencyShort = (n) => `GHS ${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
const chartDateLabel = (d) => new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

function RevenueTrendChart() {
  const [series, setSeries] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/dashboard/chart-data', { params: { days: 30 } })
      .then(({ data }) => setSeries(data.series))
      .catch(() => setError('Could not load chart data.'));
  }, []);

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
      <h2 className="text-sm font-semibold text-slate-700 mb-1">Revenue Trend</h2>
      <p className="text-xs text-slate-400 mb-4">Last 30 days, combined across both gateways.</p>

      {error && <p className="text-xs text-red-500">{error}</p>}
      {!series && !error && <p className="text-xs text-slate-400 py-16 text-center">Loading…</p>}

      {series && (
        <ResponsiveContainer width="100%" height={220}>
          <AreaChart data={series}>
            <defs>
              <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#4f46e5" stopOpacity={0.25} />
                <stop offset="95%" stopColor="#4f46e5" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
            <XAxis dataKey="date" tickFormatter={chartDateLabel} tick={{ fontSize: 11, fill: '#94a3b8' }} interval="preserveStartEnd" />
            <YAxis tickFormatter={currencyShort} tick={{ fontSize: 11, fill: '#94a3b8' }} width={70} />
            <Tooltip
              formatter={(value) => [currencyShort(value), 'Revenue']}
              labelFormatter={chartDateLabel}
              contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: '#e2e8f0' }}
            />
            <Area type="monotone" dataKey="total" stroke="#4f46e5" strokeWidth={2} fill="url(#revenueFill)" />
          </AreaChart>
        </ResponsiveContainer>
      )}
    </div>
  );
}

function GatewaySplitChart() {
  const [series, setSeries] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .get('/admin/dashboard/chart-data', { params: { days: 30 } })
      .then(({ data }) => setSeries(data.series))
      .catch(() => setError('Could not load chart data.'));
  }, []);

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
      <h2 className="text-sm font-semibold text-slate-700 mb-1">Payment Gateway Split</h2>
      <p className="text-xs text-slate-400 mb-4">Paystack vs Mobile Money over time.</p>

      {error && <p className="text-xs text-red-500">{error}</p>}
      {!series && !error && <p className="text-xs text-slate-400 py-16 text-center">Loading…</p>}

      {series && (
        <ResponsiveContainer width="100%" height={220}>
          <LineChart data={series}>
            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
            <XAxis dataKey="date" tickFormatter={chartDateLabel} tick={{ fontSize: 11, fill: '#94a3b8' }} interval="preserveStartEnd" />
            <YAxis tickFormatter={currencyShort} tick={{ fontSize: 11, fill: '#94a3b8' }} width={70} />
            <Tooltip
              formatter={(value, name) => [currencyShort(value), name === 'paystack' ? 'Paystack' : 'Mobile Money']}
              labelFormatter={chartDateLabel}
              contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: '#e2e8f0' }}
            />
            <Legend
              formatter={(value) => (value === 'paystack' ? 'Paystack' : 'Mobile Money')}
              wrapperStyle={{ fontSize: 11 }}
            />
            <Line type="monotone" dataKey="paystack" stroke="#4f46e5" strokeWidth={2} dot={false} />
            <Line type="monotone" dataKey="momo" stroke="#10b981" strokeWidth={2} dot={false} />
          </LineChart>
        </ResponsiveContainer>
      )}
    </div>
  );
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

const formatDateTime = (value) => value
  ? new Intl.DateTimeFormat('en-GH', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
  : '—';

function ActiveUsersPanel() {
  const [sessions, setSessions] = useState(null);
  const [error, setError] = useState('');
  const pagination = useClientPagination(sessions || []);

  useEffect(() => {
    api
      .get('/admin/active-users')
      .then(({ data }) => setSessions(data.sessions))
      .catch(() => setError('Could not load active users.'));
  }, []);

  return (
    <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
      <div className="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-slate-700">Active Users Right Now</h2>
        {sessions && <span className="text-xs text-slate-400">{sessions.length} connected</span>}
      </div>
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-slate-400 text-xs border-b border-slate-100">
            <th className="px-5 py-2 font-medium">User</th>
            <th className="px-5 py-2 font-medium">Router</th>
            <th className="px-5 py-2 font-medium">Package</th>
            <th className="px-5 py-2 font-medium">Started</th>
            <th className="px-5 py-2 font-medium">Expires</th>
            <th className="px-5 py-2 font-medium">Data Used (↓ / ↑)</th>
          </tr>
        </thead>
        <tbody>
          {!sessions && !error && (
            <tr><td colSpan={6} className="px-5 py-4 text-slate-400 text-center">Loading…</td></tr>
          )}
          {error && <tr><td colSpan={6} className="px-5 py-4 text-red-500 text-center text-xs">{error}</td></tr>}
          {sessions && sessions.length === 0 && (
            <tr><td colSpan={6} className="px-5 py-4 text-slate-400 text-center">No one currently connected on a live router.</td></tr>
          )}
          {pagination.rows.map((s, i) => (
            <tr key={i} className="border-b border-slate-50 last:border-0">
              <td className="px-5 py-2.5 text-slate-700 font-mono text-xs">{s.username}</td>
              <td className="px-5 py-2.5 text-slate-500">{s.router}</td>
              <td className="px-5 py-2.5 text-slate-500 whitespace-nowrap">{s.package_name || '—'}</td>
              <td className="px-5 py-2.5 text-slate-500 whitespace-nowrap">{formatDateTime(s.started_at)}</td>
              <td className="px-5 py-2.5 text-slate-500 whitespace-nowrap">{formatDateTime(s.expires_at)}</td>
              <td className="px-5 py-2.5 text-slate-500">
                {formatBytes(s.bytes_in)} / {formatBytes(s.bytes_out)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <TablePagination {...pagination} onPageChange={pagination.setPage} />
    </div>
  );
}
