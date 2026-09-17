import { useEffect, useState, useCallback } from 'react';
import { Plus, Wifi, Pencil, Trash2, Link as LinkIcon, Check, ShieldCheck, RefreshCw } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import SearchFilterInput from '../../components/SearchFilterInput';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterFormModal from './RouterFormModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

export default function AdminRouters() {
  const [routers, setRouters] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const pagination = useServerPagination();

  const [formTarget, setFormTarget] = useState(null); // null = closed, {} = new, router obj = edit
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [testingId, setTestingId] = useState(null);
  const [testResult, setTestResult] = useState(null); // { id, success, message }
  const [copiedId, setCopiedId] = useState(null);
  const [portalSettingId, setPortalSettingId] = useState(null);
  const [portalResult, setPortalResult] = useState(null); // { id, success, message }
  const [sessionRefreshingId, setSessionRefreshingId] = useState(null);
  const [sessionDetails, setSessionDetails] = useState({});

  const handleCopyGuestLink = (router) => {
    // login-url and mac are RouterOS template variables — MikroTik
    // substitutes them at request time when this link is served from
    // (or linked from) the router's own login.html. See GuestBuy.jsx
    // for how the frontend captures them on arrival.
    const base = window.location.origin;
    const link = `${base}/portal?router=${router.id}&login-url=$(link-login-only)&mac=$(mac)`;
    navigator.clipboard.writeText(link);
    setCopiedId(router.id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/routers', { params: { ...pagination.requestParams, search: search || undefined } })
      .then(({ data }) => setRouters(pagination.capture(data)))
      .catch(() => setError('Could not load routers. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [search, pagination.page]);

  useEffect(() => {
    const t = setTimeout(load, 250); // debounce search
    return () => clearTimeout(t);
  }, [load]);

  const handleTestConnection = async (router) => {
    setTestingId(router.id);
    setTestResult(null);
    try {
      const { data } = await api.post(`/admin/routers/${router.id}/test-connection`);
      setTestResult({ id: router.id, success: data.success, message: data.message });
      load();
    } catch (err) {
      setTestResult({
        id: router.id,
        success: false,
        message: err.response?.data?.message || 'Connection test failed.',
      });
      load();
    } finally {
      setTestingId(null);
    }
  };

  const handlePortalSetup = async (router) => {
    setPortalSettingId(router.id);
    setPortalResult(null);
    try {
      const { data } = await api.post(`/admin/routers/${router.id}/setup-guest-portal`);
      setPortalResult({ id: router.id, success: data.success, message: data.message });
    } catch (err) {
      setPortalResult({
        id: router.id,
        success: false,
        message: err.response?.data?.message || 'Guest portal setup failed.',
      });
    } finally {
      setPortalSettingId(null);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/admin/routers/${deleteTarget.id}`);
      setDeleteTarget(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete this router.');
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  };

  const refreshSessions = async (router) => {
    setSessionRefreshingId(router.id);
    try {
      const { data } = await api.post(`/admin/routers/${router.id}/isp-sessions/refresh`);
      setSessionDetails((current) => ({ ...current, [router.id]: data.customers || [] }));
      load();
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Could not read RouterOS connection tracking.');
    } finally {
      setSessionRefreshingId(null);
    }
  };

  const monitoredIsps = routers.flatMap((router) =>
    (router.isps || [])
      .filter((isp) => isp.session_monitoring_enabled)
      .map((isp) => ({ router, isp }))
  );

  return (
    <div>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Routers</h1>
          <p className="text-slate-500 text-sm mt-1">
            MikroTik routers reachable over WireGuard. Test connectivity before assigning packages.
          </p>
        </div>
        <button
          onClick={() => setFormTarget({})}
          className="flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700 whitespace-nowrap"
        >
          <Plus className="h-4 w-4" />
          Add Router
        </button>
      </div>

      <div className="mb-4">
        <SearchFilterInput value={search} onChange={setSearch} placeholder="Search by name, location, or IP…" />
      </div>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">
          {error}
        </div>
      )}

      {monitoredIsps.length > 0 && (
        <section className="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3">
            <h2 className="font-semibold text-slate-900">Estimated WAN Concurrent Sessions</h2>
            <p className="text-xs text-slate-500">RouterOS TCP/UDP connection-tracking estimates, not exact upstream CGNAT sessions.</p>
          </div>
          <div className="grid gap-3 lg:grid-cols-2">
            {monitoredIsps.map(({ router, isp }) => {
              const snapshot = isp.latest_session_snapshot;
              const customers = (sessionDetails[router.id] || [])
                .filter((row) => row.connection_mark === isp.connection_mark)
                .sort((a, b) => b.total - a.total)
                .slice(0, 5);
              const stateClass = snapshot?.state === 'emergency' || snapshot?.state === 'critical'
                ? 'bg-red-50 text-red-700'
                : snapshot?.state === 'warning'
                  ? 'bg-amber-50 text-amber-700'
                  : 'bg-emerald-50 text-emerald-700';
              return (
                <div key={isp.id} className="rounded-lg border border-slate-200 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-medium text-slate-900">{isp.name}</p>
                      <p className="text-xs text-slate-400">{router.name} · {isp.connection_mark}</p>
                    </div>
                    <button type="button" onClick={() => refreshSessions(router)} disabled={sessionRefreshingId === router.id} className="rounded border border-slate-200 p-1.5 text-slate-500 hover:text-indigo-600 disabled:opacity-50" title="Refresh session counts">
                      <RefreshCw className={`h-4 w-4 ${sessionRefreshingId === router.id ? 'animate-spin' : ''}`} />
                    </button>
                  </div>
                  {snapshot ? (
                    <>
                      <div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <Metric label="TCP" value={snapshot.tcp_sessions} />
                        <Metric label="UDP" value={snapshot.udp_sessions} />
                        <Metric label="Total" value={snapshot.total_sessions} />
                      </div>
                      <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span className={`rounded-full px-2 py-1 font-medium capitalize ${stateClass}`}>{snapshot.state}</span>
                        <span className="text-slate-500">{snapshot.utilization_percent ?? '—'}% of hard limit</span>
                        <span className="text-slate-400">Soft {isp.session_soft_limit} · Hard {isp.session_hard_limit} · Emergency {isp.session_emergency_limit}</span>
                      </div>
                    </>
                  ) : <p className="mt-3 text-xs text-slate-400">Awaiting the first scheduled or manual sample.</p>}
                  {customers.length > 0 && (
                    <div className="mt-3 border-t border-slate-100 pt-2">
                      <p className="mb-1 text-xs font-medium text-slate-600">Largest current users</p>
                      {customers.map((row) => (
                        <p key={row.username} className="flex justify-between text-xs text-slate-500">
                          <span>{row.username}</span><span>{row.total} ({row.tcp} TCP / {row.udp} UDP)</span>
                        </p>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </section>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Name</th>
              <th className="px-5 py-3 font-medium">Location</th>
              <th className="px-5 py-3 font-medium">WireGuard IP</th>
              <th className="px-5 py-3 font-medium">Port</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Topology</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={7} className="px-5 py-6 text-center text-slate-400">
                  Loading…
                </td>
              </tr>
            )}
            {!loading && routers.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-6 text-center text-slate-400">
                  No routers yet — add one to get started.
                </td>
              </tr>
            )}
            {!loading &&
              routers.map((router) => (
                <tr key={router.id} className="border-b border-slate-50 last:border-0 align-top">
                  <td className="px-5 py-3 font-medium text-slate-900">{router.name}</td>
                  <td className="px-5 py-3 text-slate-600">{router.location || '—'}</td>
                  <td className="px-5 py-3 text-slate-600 font-mono text-xs">{router.wireguard_ip}</td>
                  <td className="px-5 py-3 text-slate-600">{router.api_port}</td>
                  <td className="px-5 py-3">
                    <StatusBadge status={router.status} />
                    {testResult?.id === router.id && (
                      <p className={`text-xs mt-1 ${testResult.success ? 'text-emerald-600' : 'text-red-600'}`}>
                        {testResult.message}
                      </p>
                    )}
                    {portalResult?.id === router.id && (
                      <p className={`text-xs mt-1 ${portalResult.success ? 'text-emerald-600' : 'text-red-600'}`}>
                        {portalResult.message}
                      </p>
                    )}
                  </td>
                  <td className="px-5 py-3 text-xs text-slate-600">
                    <p>RouterOS {router.routeros_version || 'not set'}</p>
                    <p className="mt-1">{router.isps?.filter((isp) => isp.enabled).length || 0} active ISP(s)</p>
                    {router.isps?.filter((isp) => isp.enabled).length > 1 && (
                      <p className="mt-1">Failover: {router.isp_failover_enabled ? (router.isp_failback_enabled ? 'auto + return' : 'automatic') : 'off'}</p>
                    )}
                    {router.isps?.filter((isp) => isp.session_monitoring_enabled).map((isp) => {
                      const snapshot = isp.latest_session_snapshot;
                      const color = snapshot?.state === 'emergency' || snapshot?.state === 'critical'
                        ? 'text-red-600'
                        : snapshot?.state === 'warning'
                          ? 'text-amber-600'
                          : 'text-emerald-600';
                      return (
                        <p key={isp.id} className={`mt-1 ${snapshot ? color : 'text-slate-400'}`}>
                          {isp.name}: {snapshot ? `${snapshot.total_sessions} sessions (${snapshot.tcp_sessions} TCP / ${snapshot.udp_sessions} UDP)` : 'awaiting first sample'}
                        </p>
                      );
                    })}
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex items-center justify-end gap-3">
                      {router.connection_mode !== 'manual' && (
                        <button
                          onClick={() => handleCopyGuestLink(router)}
                          className="flex items-center gap-1 text-slate-500 hover:text-indigo-600"
                          title="Copy guest checkout link — paste into this router's MikroTik login page"
                        >
                          {copiedId === router.id ? <Check className="h-4 w-4" /> : <LinkIcon className="h-4 w-4" />}
                          <span className="hidden lg:inline text-xs">
                            {copiedId === router.id ? 'Copied' : 'Guest Link'}
                          </span>
                        </button>
                      )}
                      <button
                        onClick={() => handleTestConnection(router)}
                        disabled={testingId === router.id}
                        className="flex items-center gap-1 text-slate-500 hover:text-indigo-600 disabled:opacity-50"
                        title="Test connection"
                      >
                        <Wifi className="h-4 w-4" />
                        <span className="hidden lg:inline text-xs">
                          {testingId === router.id ? 'Testing…' : 'Test'}
                        </span>
                      </button>
                      {router.connection_mode !== 'manual' && (
                        <button
                          onClick={() => handlePortalSetup(router)}
                          disabled={portalSettingId === router.id}
                          className="flex items-center gap-1 text-slate-500 hover:text-indigo-600 disabled:opacity-50"
                          title="Set up guest portal — rewrites this router's hotspot login page"
                        >
                          <ShieldCheck className="h-4 w-4" />
                          <span className="hidden lg:inline text-xs">
                            {portalSettingId === router.id ? 'Setting up…' : 'Set Up Portal'}
                          </span>
                        </button>
                      )}
                      <button
                        onClick={() => setFormTarget(router)}
                        className="text-slate-500 hover:text-indigo-600"
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setDeleteTarget(router)}
                        className="text-slate-500 hover:text-red-600"
                        title="Delete"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {formTarget !== null && (
        <RouterFormModal
          router={formTarget.id ? formTarget : null}
          onClose={() => setFormTarget(null)}
          onSaved={() => {
            setFormTarget(null);
            load();
          }}
        />
      )}

      {deleteTarget && (
        <ConfirmDialog
          title="Delete Router"
          message={`Delete "${deleteTarget.name}"? This can't be undone, and will fail if customers still have active subscriptions on it.`}
          confirmLabel="Delete"
          danger
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setDeleteTarget(null)}
        />
      )}
    </div>
  );
}

function Metric({ label, value }) {
  return <div className="rounded bg-slate-50 px-2 py-2"><p className="text-slate-400">{label}</p><p className="mt-0.5 text-base font-semibold text-slate-800">{value}</p></div>;
}
