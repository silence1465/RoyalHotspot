import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import StatCard from '../../components/StatCard';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

export default function AdminBandwidth() {
  const [summary, setSummary] = useState(null);
  const [error, setError] = useState('');
  const [activeUsageView, setActiveUsageView] = useState('users');

  const [historyPeriod, setHistoryPeriod] = useState('day');
  const [historyMonth, setHistoryMonth] = useState(new Date().toISOString().slice(0, 7));
  const [historyYear, setHistoryYear] = useState(new Date().getFullYear());
  const [history, setHistory] = useState(null);
  const usersPagination = useClientPagination(summary?.users || []);
  const historyPagination = useClientPagination(history || []);

  const loadSummary = useCallback(() => {
    api
      .get('/admin/bandwidth/summary')
      .then(({ data }) => setSummary(data))
      .catch(() => setError('Could not load bandwidth data.'));
  }, []);

  useEffect(() => { loadSummary(); }, [loadSummary]);

  const loadHistory = useCallback(() => {
    const params = historyPeriod === 'month' ? { period: 'month', year: historyYear } : { period: 'day', month: historyMonth };
    api.get('/admin/bandwidth/history', { params }).then(({ data }) => setHistory(data.entries));
  }, [historyPeriod, historyMonth, historyYear]);

  useEffect(() => {
    loadHistory();
  }, [loadHistory]);

  if (error) return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  if (!summary) return <p className="text-sm text-slate-400">Loading…</p>;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Bandwidth</h1>
        <p className="text-slate-500 text-sm mt-1">Usage across live routers. Manual routers have no visibility into this.</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Today (all users)"
          value={formatBytes(summary.today_total.bytes_in + summary.today_total.bytes_out)}
          tone="positive"
        />
        <StatCard
          label="This Month (all users)"
          value={formatBytes(summary.month_total.bytes_in + summary.month_total.bytes_out)}
          tone="positive"
        />
        <StatCard
          label="Total Capacity"
          value={summary.isp_capacity_totals ? formatBytes(summary.isp_capacity_totals.capacity_bytes) : 'Not set'}
          tone="positive"
        />
        <StatCard
          label="Remaining Capacity"
          value={summary.isp_capacity_totals ? formatBytes(summary.isp_capacity_totals.remaining_bytes) : 'Not set'}
          tone={summary.isp_capacity_totals?.remaining_bytes === 0 ? 'negative' : 'positive'}
        />
      </div>

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-800">ISP monthly capacity</h2>
          <p className="text-xs text-slate-400 mt-1">Capacity configured on the selected router. Change it from Routers → Edit Router.</p>
        </div>

        {summary.isps?.length ? (
          <div className="grid gap-4 lg:grid-cols-2">
            {summary.isps.map((isp) => (
              <div key={isp.id} className="rounded-lg border border-slate-200 p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <div>
                    <p className="font-semibold text-slate-900">{isp.name}</p>
                    <p className="text-xs text-slate-400">{isp.router_name} · {isp.wan_interface}</p>
                  </div>
                  <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Enabled</span>
                </div>
                <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                  <div><p className="text-xs text-slate-400">Monthly capacity</p><p className="font-semibold">{isp.capacity_bytes ? formatBytes(isp.capacity_bytes) : 'Unlimited'}</p></div>
                  <div><p className="text-xs text-slate-400">Reserved</p><p className="font-semibold">{formatBytes(isp.reserved_bytes)}</p></div>
                  <div><p className="text-xs text-slate-400">Remaining</p><p className="font-semibold text-emerald-700">{isp.capacity_bytes ? formatBytes(isp.remaining_bytes) : 'Unlimited'}</p></div>
                  <div><p className="text-xs text-slate-400">Subscriber limit</p><p className="font-semibold">{isp.subscriber_limit ?? 'Unlimited'}</p></div>
                  <div><p className="text-xs text-slate-400">Allocated users</p><p className="font-semibold">{isp.subscribers_allocated}</p></div>
                  <div><p className="text-xs text-slate-400">Slots remaining</p><p className="font-semibold">{isp.subscriber_slots_remaining ?? 'Unlimited'}</p></div>
                </div>
              </div>
            ))}
          </div>
        ) : <p className="text-sm text-amber-700">No enabled ISP is configured for the selected router.</p>}
      </div>

      <div>
      <div className="border-b border-slate-200">
        <nav className="flex gap-1" aria-label="Bandwidth details">
          <button
            type="button"
            onClick={() => setActiveUsageView('users')}
            className={`rounded-t-lg border border-b-0 px-5 py-2.5 text-sm font-medium transition-colors ${activeUsageView === 'users' ? 'border-indigo-200 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
          >
            Per-User Usage
          </button>
          <button
            type="button"
            onClick={() => setActiveUsageView('history')}
            className={`rounded-t-lg border border-b-0 px-5 py-2.5 text-sm font-medium transition-colors ${activeUsageView === 'history' ? 'border-indigo-200 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
          >
            History
          </button>
        </nav>
      </div>

      {activeUsageView === 'users' && (
      <div className="bg-white rounded-b-xl rounded-tr-xl border border-t-0 border-slate-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-400 text-xs border-b border-slate-100">
              <th className="px-5 py-2 font-medium">Customer</th>
              <th className="px-5 py-2 font-medium">Today</th>
              <th className="px-5 py-2 font-medium">All-Time Total</th>
            </tr>
          </thead>
          <tbody>
            {summary.users.length === 0 && (
              <tr><td colSpan={3} className="px-5 py-4 text-slate-400 text-center">No usage recorded yet.</td></tr>
            )}
            {usersPagination.rows.map((u) => (
              <tr key={u.customer_id} className="border-b border-slate-50 last:border-0">
                <td className="px-5 py-2.5 text-slate-700">{u.name}</td>
                <td className="px-5 py-2.5 text-slate-500">{formatBytes(u.today_bytes_in + u.today_bytes_out)}</td>
                <td className="px-5 py-2.5 text-slate-500">{formatBytes(u.total_bytes_in + u.total_bytes_out)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...usersPagination} onPageChange={usersPagination.setPage} />
      </div>
      )}

      {activeUsageView === 'history' && (
      <div className="bg-white rounded-b-xl rounded-tr-xl border border-t-0 border-slate-200 shadow-sm overflow-hidden">
        <div className="px-5 py-3 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <h2 className="text-sm font-semibold text-slate-700">History</h2>
          <div className="flex items-center gap-2">
            <select className="input !py-1 !text-xs w-auto" value={historyPeriod} onChange={(e) => setHistoryPeriod(e.target.value)}>
              <option value="day">By Day</option>
              <option value="month">By Month</option>
            </select>
            {historyPeriod === 'day' ? (
              <input
                type="month"
                className="input !py-1 !text-xs w-auto"
                value={historyMonth}
                onChange={(e) => setHistoryMonth(e.target.value)}
              />
            ) : (
              <input
                type="number"
                className="input !py-1 !text-xs w-24"
                value={historyYear}
                onChange={(e) => setHistoryYear(e.target.value)}
              />
            )}
          </div>
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-400 text-xs border-b border-slate-100">
              <th className="px-5 py-2 font-medium">{historyPeriod === 'month' ? 'Month' : 'Date'}</th>
              <th className="px-5 py-2 font-medium">Total</th>
            </tr>
          </thead>
          <tbody>
            {!history && (
              <tr><td colSpan={2} className="px-5 py-4 text-slate-400 text-center">Loading…</td></tr>
            )}
            {history?.length === 0 && (
              <tr><td colSpan={2} className="px-5 py-4 text-slate-400 text-center">No data for this period.</td></tr>
            )}
            {historyPagination.rows.map((row) => (
              <tr key={row.bucket} className="border-b border-slate-50 last:border-0">
                <td className="px-5 py-2.5 text-slate-700">{row.bucket}</td>
                <td className="px-5 py-2.5 text-slate-500">{formatBytes(Number(row.bytes_in) + Number(row.bytes_out))}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...historyPagination} onPageChange={historyPagination.setPage} />
      </div>
      )}
      </div>
    </div>
  );
}
