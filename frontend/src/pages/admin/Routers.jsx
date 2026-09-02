import { useEffect, useState, useCallback } from 'react';
import { Plus, Wifi, Pencil, Trash2, Link as LinkIcon, Check, ShieldCheck } from 'lucide-react';
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

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Name</th>
              <th className="px-5 py-3 font-medium">Location</th>
              <th className="px-5 py-3 font-medium">WireGuard IP</th>
              <th className="px-5 py-3 font-medium">Port</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">
                  Loading…
                </td>
              </tr>
            )}
            {!loading && routers.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">
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
