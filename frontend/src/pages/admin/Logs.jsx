import { useEffect, useState, useCallback, Fragment } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

export default function AdminLogs() {
  const [tab, setTab] = useState('mikrotik'); // 'mikrotik' | 'activity' | 'sms'

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Logs</h1>
        <p className="text-slate-500 text-sm mt-1">
          MikroTik action history (sensitive fields already redacted server-side), admin/customer activity, and
          received MoMo payment SMS.
        </p>
      </div>

      <div className="mb-4 flex gap-2">
        <TabButton active={tab === 'mikrotik'} onClick={() => setTab('mikrotik')}>
          MikroTik Logs
        </TabButton>
        <TabButton active={tab === 'activity'} onClick={() => setTab('activity')}>
          Activity Logs
        </TabButton>
        <TabButton active={tab === 'sms'} onClick={() => setTab('sms')}>
          SMS Logs
        </TabButton>
      </div>

      {tab === 'mikrotik' && <MikrotikLogsTable />}
      {tab === 'activity' && <ActivityLogsTable />}
      {tab === 'sms' && <SmsLogsTable />}
    </div>
  );
}

function TabButton({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
        active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
      }`}
    >
      {children}
    </button>
  );
}

function MikrotikLogsTable() {
  const [logs, setLogs] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expanded, setExpanded] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/mikrotik-logs', { params: { ...pagination.requestParams, status: status || undefined } })
      .then(({ data }) => setLogs(pagination.capture(data)))
      .catch(() => setError('Could not load MikroTik logs.'))
      .finally(() => setLoading(false));
  }, [status, pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div>
      <div className="mb-3 flex gap-2">
        {['', 'success', 'failed'].map((s) => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`px-3 py-1 rounded-md text-xs font-medium border ${
              status === s ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
            }`}
          >
            {s === '' ? 'All' : s === 'success' ? 'Success' : 'Failed'}
          </button>
        ))}
      </div>

      {error && <div className="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Router</th>
              <th className="px-5 py-3 font-medium">Action</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Time</th>
            </tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={4} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>}
            {!loading && logs.length === 0 && (
              <tr><td colSpan={4} className="px-5 py-6 text-center text-slate-400">No logs yet.</td></tr>
            )}
            {!loading &&
              logs.map((log) => (
                <Fragment key={log.id}>
                  <tr
                    onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                    className="border-b border-slate-50 cursor-pointer hover:bg-slate-50"
                  >
                    <td className="px-5 py-2.5">{log.router?.name || '—'}</td>
                    <td className="px-5 py-2.5 font-mono text-xs">{log.action}</td>
                    <td className="px-5 py-2.5"><StatusBadge status={log.status} /></td>
                    <td className="px-5 py-2.5 text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                  </tr>
                  {expanded === log.id && (
                    <tr className="bg-slate-50 border-b border-slate-100">
                      <td colSpan={4} className="px-5 py-3 text-xs font-mono text-slate-600 whitespace-pre-wrap">
                        {log.error_message && <p className="text-red-600 mb-2">Error: {log.error_message}</p>}
                        <p className="text-slate-400 mb-1">Request:</p>
                        <p className="mb-2">{log.request_payload || '—'}</p>
                        <p className="text-slate-400 mb-1">Response:</p>
                        <p>{log.response_payload || '—'}</p>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>
    </div>
  );
}

function ActivityLogsTable() {
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const pagination = useServerPagination();

  useEffect(() => {
    api
      .get('/admin/activity-logs', { params: pagination.requestParams })
      .then(({ data }) => setLogs(pagination.capture(data)))
      .catch(() => setError('Could not load activity logs.'))
      .finally(() => setLoading(false));
  }, [pagination.page]);

  return (
    <div>
      {error && <div className="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Actor</th>
              <th className="px-5 py-3 font-medium">Action</th>
              <th className="px-5 py-3 font-medium">Description</th>
              <th className="px-5 py-3 font-medium">Time</th>
            </tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={4} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>}
            {!loading && logs.length === 0 && (
              <tr><td colSpan={4} className="px-5 py-6 text-center text-slate-400">No activity yet.</td></tr>
            )}
            {!loading &&
              logs.map((log) => (
                <tr key={log.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-2.5 text-slate-700">
                    {log.user?.name || log.customer?.full_name || 'System'}
                  </td>
                  <td className="px-5 py-2.5 font-mono text-xs">{log.action}</td>
                  <td className="px-5 py-2.5 text-slate-600">{log.description}</td>
                  <td className="px-5 py-2.5 text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>
    </div>
  );
}

function SmsLogsTable() {
  const [logs, setLogs] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expanded, setExpanded] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/sms-logs', { params: { ...pagination.requestParams, status: status || undefined } })
      .then(({ data }) => setLogs(pagination.capture(data)))
      .catch(() => setError('Could not load SMS logs.'))
      .finally(() => setLoading(false));
  }, [status, pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div>
      <div className="mb-3 flex gap-2 flex-wrap">
        {['', 'matched', 'unmatched', 'manual_review', 'duplicate'].map((s) => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`px-3 py-1 rounded-md text-xs font-medium border ${
              status === s ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
            }`}
          >
            {s === '' ? 'All' : s.replace('_', ' ')}
          </button>
        ))}
      </div>

      {error && <div className="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Received</th>
              <th className="px-5 py-3 font-medium">Sender</th>
              <th className="px-5 py-3 font-medium">Amount</th>
              <th className="px-5 py-3 font-medium">Transaction ID</th>
              <th className="px-5 py-3 font-medium">Reference</th>
              <th className="px-5 py-3 font-medium">Matched Order</th>
              <th className="px-5 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>}
            {!loading && logs.length === 0 && (
              <tr><td colSpan={7} className="px-5 py-6 text-center text-slate-400">No SMS received yet.</td></tr>
            )}
            {!loading &&
              logs.map((log) => (
                <Fragment key={log.id}>
                  <tr
                    onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                    className="border-b border-slate-50 cursor-pointer hover:bg-slate-50"
                  >
                    <td className="px-5 py-2.5 text-slate-500">{new Date(log.received_at).toLocaleString()}</td>
                    <td className="px-5 py-2.5">{log.sender || '—'}</td>
                    <td className="px-5 py-2.5">{log.amount != null ? `GHS ${log.amount}` : '—'}</td>
                    <td className="px-5 py-2.5 font-mono text-xs">{log.transaction_id || '—'}</td>
                    <td className="px-5 py-2.5 font-mono text-xs">{log.parsed_reference || '—'}</td>
                    <td className="px-5 py-2.5 font-mono text-xs">{log.matched_order?.reference || '—'}</td>
                    <td className="px-5 py-2.5"><StatusBadge status={log.verification_status} /></td>
                  </tr>
                  {expanded === log.id && (
                    <tr className="bg-slate-50 border-b border-slate-100">
                      <td colSpan={7} className="px-5 py-3 text-xs text-slate-600 whitespace-pre-wrap">
                        <p className="text-slate-400 mb-1">Original SMS content:</p>
                        {log.raw_body}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>
    </div>
  );
}
