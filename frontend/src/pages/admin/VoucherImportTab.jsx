import { useEffect, useState, useCallback } from 'react';
import { Upload } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import PdfUploadModal from './PdfUploadModal';
import ImportPreviewModal from './ImportPreviewModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

export default function VoucherImportTab() {
  const [batches, setBatches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showUpload, setShowUpload] = useState(false);
  const [previewBatch, setPreviewBatch] = useState(null);
  const pagination = useServerPagination();

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/admin/vouchers/import', { params: pagination.requestParams })
      .then(({ data }) => setBatches(pagination.capture(data)))
      .catch(() => setError('Could not load import history.'))
      .finally(() => setLoading(false));
  }, [pagination.page]);

  useEffect(() => {
    load();
  }, [load]);

  const openBatch = (batch) => {
    // Batches from the list endpoint don't include extraction_meta (kept
    // light for the table) — fetch the full detail before opening preview.
    api.get(`/admin/vouchers/import/${batch.id}`).then(({ data }) => setPreviewBatch(data));
  };

  return (
    <div>
      <div className="flex justify-end mb-4">
        <button
          onClick={() => setShowUpload(true)}
          className="flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700"
        >
          <Upload className="h-4 w-4" />
          Upload PDF
        </button>
      </div>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">File</th>
              <th className="px-5 py-3 font-medium">Uploaded By</th>
              <th className="px-5 py-3 font-medium">Extracted</th>
              <th className="px-5 py-3 font-medium">Imported</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Date</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={6} className="px-5 py-6 text-center text-slate-400">Loading…</td></tr>
            )}
            {!loading && batches.length === 0 && (
              <tr><td colSpan={6} className="px-5 py-6 text-center text-slate-400">No PDF imports yet.</td></tr>
            )}
            {!loading &&
              batches.map((b) => (
                <tr
                  key={b.id}
                  onClick={() => openBatch(b)}
                  className="border-b border-slate-50 last:border-0 cursor-pointer hover:bg-slate-50"
                >
                  <td className="px-5 py-3 text-slate-800">{b.original_filename}</td>
                  <td className="px-5 py-3 text-slate-600">{b.uploaded_by?.name || '—'}</td>
                  <td className="px-5 py-3 text-slate-600">{b.total_extracted}</td>
                  <td className="px-5 py-3 text-slate-600">{b.total_imported}</td>
                  <td className="px-5 py-3">
                    <StatusBadge status={b.status === 'pending_review' ? 'pending' : b.status} />
                    {b.status === 'pending_review' && (
                      <span className="ml-2 text-xs text-indigo-600">Continue review →</span>
                    )}
                  </td>
                  <td className="px-5 py-3 text-slate-500">{new Date(b.created_at).toLocaleDateString()}</td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {showUpload && (
        <PdfUploadModal
          onClose={() => setShowUpload(false)}
          onUploaded={(batch) => {
            setShowUpload(false);
            setPreviewBatch(batch);
            load();
          }}
        />
      )}

      {previewBatch && (
        <ImportPreviewModal
          batch={previewBatch}
          onClose={() => setPreviewBatch(null)}
          onImported={() => {
            setPreviewBatch(null);
            load();
          }}
          onCancelled={() => {
            setPreviewBatch(null);
            load();
          }}
        />
      )}
    </div>
  );
}
