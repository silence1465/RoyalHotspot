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
  const [capacityForm, setCapacityForm] = useState({ capacity_gb: '', reserve_percent: 15, reason: '' });
  const [savingCapacity, setSavingCapacity] = useState(false);

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

  const saveCapacity = async (event) => {
    event.preventDefault();
    setSavingCapacity(true);
    setError('');
    try {
      await api.post('/admin/bandwidth/capacity', capacityForm);
      setCapacityForm((value) => ({ ...value, capacity_gb: '', reason: '' }));
      await loadSummary();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not update monthly capacity.');
    } finally {
      setSavingCapacity(false);
    }
  };

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
          value={summary.capacity ? formatBytes(summary.capacity.capacity_bytes) : 'Not set'}
          tone="positive"
        />
        <StatCard
          label="Remaining Capacity"
          value={summary.capacity ? formatBytes(summary.capacity.remaining_bytes) : 'Not set'}
          tone={summary.capacity?.control?.level === 'critical' ? 'negative' : 'positive'}
        />
      </div>

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
          <div>
            <h2 className="text-sm font-semibold text-slate-800">Monthly capacity control</h2>
            <p className="text-xs text-slate-400 mt-1">Capacity can be adjusted during the month. Existing usage is never reset.</p>
          </div>
          {summary.capacity && (
            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold uppercase ${summary.capacity.control.level === 'green' ? 'bg-emerald-50 text-emerald-700' : summary.capacity.control.level === 'amber' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700'}`}>
              {summary.capacity.control.level}
            </span>
          )}
        </div>

        {summary.capacity ? (
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
            <div><p className="text-slate-400 text-xs">Total capacity</p><p className="font-semibold">{formatBytes(summary.capacity.capacity_bytes)}</p></div>
            <div><p className="text-slate-400 text-xs">Usable after reserve</p><p className="font-semibold">{formatBytes(summary.capacity.usable_bytes)}</p></div>
            <div><p className="text-slate-400 text-xs">Remaining</p><p className="font-semibold">{formatBytes(summary.capacity.remaining_bytes)}</p></div>
            <div><p className="text-slate-400 text-xs">New daily target</p><p className="font-semibold">{formatBytes(summary.capacity.daily_target_bytes)}</p></div>
            <div><p className="text-slate-400 text-xs">Projected month end</p><p className="font-semibold">{formatBytes(summary.capacity.projected_month_end_bytes)}</p></div>
            <div><p className="text-slate-400 text-xs">Reserve</p><p className="font-semibold">{summary.capacity.reserve_percent}%</p></div>
          </div>
        ) : <p className="text-sm text-amber-700">No capacity has been configured for this month.</p>}

        <form onSubmit={saveCapacity} className="grid sm:grid-cols-4 gap-3 items-end">
          <label className="text-xs text-slate-600">Capacity (GB)
            <input type="number" min="0.001" step="0.001" required className="input mt-1" value={capacityForm.capacity_gb} onChange={(e) => setCapacityForm((v) => ({ ...v, capacity_gb: e.target.value }))} />
          </label>
          <label className="text-xs text-slate-600">Reserve %
            <input type="number" min="0" max="90" required className="input mt-1" value={capacityForm.reserve_percent} onChange={(e) => setCapacityForm((v) => ({ ...v, reserve_percent: Number(e.target.value) }))} />
          </label>
          <label className="text-xs text-slate-600">Adjustment reason
            <input required className="input mt-1" placeholder="Initial allocation or additional data" value={capacityForm.reason} onChange={(e) => setCapacityForm((v) => ({ ...v, reason: e.target.value }))} />
          </label>
          <button disabled={savingCapacity} className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium disabled:opacity-50">{savingCapacity ? 'Saving…' : 'Update capacity'}</button>
        </form>

        {summary.capacity_history?.length > 0 && (
          <div className="border-t border-slate-100 pt-3">
            <p className="text-xs font-semibold text-slate-600 mb-2">This month’s adjustments</p>
            <div className="space-y-1 text-xs text-slate-500">
              {summary.capacity_history.map((entry) => (
                <div key={entry.id} className="flex flex-wrap gap-x-3">
                  <span>{new Date(entry.created_at).toLocaleString()}</span>
                  <span>{formatBytes(entry.capacity_bytes)}</span>
                  <span>{entry.reserve_percent}% reserve</span>
                  <span>{entry.reason}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="px-5 py-3 border-b border-slate-200">
          <h2 className="text-sm font-semibold text-slate-700">Per-User Usage</h2>
        </div>
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

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
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
    </div>
  );
}
