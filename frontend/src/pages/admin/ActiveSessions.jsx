import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import ConfirmDialog from '../../components/ConfirmDialog';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

const formatDateTime = (value) => value
  ? new Intl.DateTimeFormat('en-GH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : '—';

export default function AdminActiveSessions() {
  const [sessions, setSessions] = useState(null);
  const [error, setError] = useState('');
  const [confirmTarget, setConfirmTarget] = useState(null);
  const [busy, setBusy] = useState(false);
  const [actionMessage, setActionMessage] = useState('');
  const pagination = useClientPagination(sessions || []);

  const load = useCallback(() => {
    api
      .get('/admin/active-users')
      .then(({ data }) => setSessions(data.sessions))
      .catch(() => setError('Could not load active sessions.'));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const handleReset = async () => {
    setBusy(true);
    setActionMessage('');
    try {
      await api.post('/admin/active-users/reset-session', {
        router_id: confirmTarget.router_id,
        session_id: confirmTarget.session_id,
      });
      setConfirmTarget(null);
      setActionMessage(`${confirmTarget.username} has been disconnected.`);
      load();
    } catch (err) {
      setActionMessage(err.response?.data?.message || 'Could not reset that session.');
      setConfirmTarget(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Active Sessions</h1>
        <p className="text-slate-500 text-sm mt-1">
          Every device currently connected on a live router. Resetting a session disconnects the device
          immediately — it doesn't disable the account, they can just log back in right away.
        </p>
      </div>

      {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}
      {actionMessage && (
        <div className="mb-4 text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-md px-4 py-2">
          {actionMessage}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">User</th>
              <th className="px-5 py-3 font-medium">Router</th>
              <th className="px-5 py-3 font-medium">IP Address</th>
              <th className="px-5 py-3 font-medium">Package</th>
              <th className="px-5 py-3 font-medium">Started</th>
              <th className="px-5 py-3 font-medium">Expires</th>
              <th className="px-5 py-3 font-medium">Connected</th>
              <th className="px-5 py-3 font-medium">Data Used</th>
              <th className="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody>
            {!sessions && (
              <tr><td colSpan={9} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>
            )}
            {sessions?.length === 0 && (
              <tr><td colSpan={9} className="px-5 py-6 text-center text-slate-400">No one currently connected on a live router.</td></tr>
            )}
            {pagination.rows.map((s) => (
              <tr key={s.session_id} className="border-b border-slate-50 last:border-0">
                <td className="px-5 py-3 font-mono text-xs text-slate-700">{s.username}</td>
                <td className="px-5 py-3 text-slate-600">{s.router}</td>
                <td className="px-5 py-3 text-slate-500">{s.address || '—'}</td>
                <td className="px-5 py-3 text-slate-600 whitespace-nowrap">{s.package_name || '—'}</td>
                <td className="px-5 py-3 text-slate-500 whitespace-nowrap">{formatDateTime(s.started_at)}</td>
                <td className="px-5 py-3 text-slate-500 whitespace-nowrap">{formatDateTime(s.expires_at)}</td>
                <td className="px-5 py-3 text-slate-500">{s.uptime || '—'}</td>
                <td className="px-5 py-3 text-slate-500">{formatBytes(s.bytes_in + s.bytes_out)}</td>
                <td className="px-5 py-3 text-right">
                  <button
                    onClick={() => setConfirmTarget(s)}
                    disabled={!s.session_id}
                    className="text-xs text-red-600 font-medium hover:underline disabled:opacity-40"
                  >
                    Reset Session
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {confirmTarget && (
        <ConfirmDialog
          title="Reset Session"
          message={`Disconnect ${confirmTarget.username} on ${confirmTarget.router}? They'll need to log in again to reconnect.`}
          confirmLabel="Reset Session"
          danger
          loading={busy}
          onConfirm={handleReset}
          onCancel={() => setConfirmTarget(null)}
        />
      )}
    </div>
  );
}
