import { useEffect, useState, useCallback } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import ConfirmDialog from '../../components/ConfirmDialog';
import GenerateVoucherModal from './GenerateVoucherModal';
import BatchResultModal from './BatchResultModal';
import VoucherInventoryCards from './VoucherInventoryCards';
import VoucherImportTab from './VoucherImportTab';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'available', label: 'Available' },
  { value: 'assigned', label: 'Assigned' },
  { value: 'used', label: 'Used' },
  { value: 'expired', label: 'Expired' },
  { value: 'invalid', label: 'Invalid' },
];

export default function AdminVouchers() {
  const [tab, setTab] = useState('codes'); // 'codes' | 'import'
  const [vouchers, setVouchers] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [showGenerate, setShowGenerate] = useState(false);
  const [batchResult, setBatchResult] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/vouchers', { params: { ...pagination.requestParams, status: status || undefined } })
      .then(({ data }) => setVouchers(pagination.capture(data)))
      .catch(() => setError('Could not load vouchers. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [status, pagination.page]);

  useEffect(() => {
    if (tab === 'codes') load();
  }, [load, tab]);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/admin/vouchers/${deleteTarget.id}`);
      setDeleteTarget(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete this voucher.');
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Vouchers</h1>
          <p className="text-slate-500 text-sm mt-1">
            Royal WiFi voucher inventory — generated in-app or imported from a MikroTik PDF export.
          </p>
        </div>
        {tab === 'codes' && (
          <button
            onClick={() => setShowGenerate(true)}
            className="flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700 whitespace-nowrap"
          >
            <Plus className="h-4 w-4" />
            Generate Vouchers
          </button>
        )}
      </div>

      <VoucherInventoryCards />

      <div className="mb-4 flex gap-2">
        <button
          onClick={() => setTab('codes')}
          className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
            tab === 'codes' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
          }`}
        >
          Codes
        </button>
        <button
          onClick={() => setTab('import')}
          className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
            tab === 'import' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
          }`}
        >
          Import PDF
        </button>
      </div>

      {tab === 'import' ? (
        <VoucherImportTab />
      ) : (
        <>
          <div className="mb-4 flex gap-2 flex-wrap">
            {STATUS_FILTERS.map((f) => (
              <button
                key={f.value}
                onClick={() => setStatus(f.value)}
                className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
                  status === f.value
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>

          {error && (
            <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>
          )}

          <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100">
                  <th className="px-5 py-3 font-medium">Code</th>
                  <th className="px-5 py-3 font-medium">Package</th>
                  <th className="px-5 py-3 font-medium">Router</th>
                  <th className="px-5 py-3 font-medium">Assigned/Used By</th>
                  <th className="px-5 py-3 font-medium">Expires</th>
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {loading && (
                  <tr>
                    <td colSpan={7} className="px-5 py-6 text-center text-slate-400">Loading…</td>
                  </tr>
                )}
                {!loading && vouchers.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-5 py-6 text-center text-slate-400">No vouchers yet.</td>
                  </tr>
                )}
                {!loading &&
                  vouchers.map((v) => (
                    <tr key={v.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-5 py-3 font-mono text-xs text-slate-700">{v.code}</td>
                      <td className="px-5 py-3 text-slate-600">{v.package?.name}</td>
                      <td className="px-5 py-3 text-slate-600">{v.router?.name || 'Any'}</td>
                      <td className="px-5 py-3 text-slate-600">{v.used_by?.full_name || '—'}</td>
                      <td className="px-5 py-3 text-slate-600">
                        {v.expires_at ? new Date(v.expires_at).toLocaleDateString() : 'Never'}
                      </td>
                      <td className="px-5 py-3">
                        <StatusBadge status={v.status} />
                      </td>
                      <td className="px-5 py-3 text-right">
                        {v.status !== 'used' && v.status !== 'assigned' && (
                          <button
                            onClick={() => setDeleteTarget(v)}
                            className="text-slate-500 hover:text-red-600"
                            title="Delete"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
            <TablePagination {...pagination} onPageChange={pagination.setPage} />
          </div>
        </>
      )}

      {showGenerate && (
        <GenerateVoucherModal
          onClose={() => setShowGenerate(false)}
          onGenerated={(batch) => {
            setShowGenerate(false);
            setBatchResult(batch);
            load();
          }}
        />
      )}

      {batchResult && <BatchResultModal batch={batchResult} onClose={() => setBatchResult(null)} />}

      {deleteTarget && (
        <ConfirmDialog
          title="Delete Voucher"
          message={`Delete voucher "${deleteTarget.code}"? This can't be undone.`}
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
