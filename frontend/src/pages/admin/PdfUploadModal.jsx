import { useEffect, useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../services/api';

export default function PdfUploadModal({ onClose, onUploaded }) {
  const [routers, setRouters] = useState([]);
  const [file, setFile] = useState(null);
  const [routerId, setRouterId] = useState('');
  const [error, setError] = useState('');
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    api
      .get('/admin/routers', { params: { per_page: 100 } })
      .then(({ data }) => setRouters(data.data || data))
      .catch(() => {});
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!file) return;

    setUploading(true);
    setError('');

    const formData = new FormData();
    formData.append('pdf', file);
    if (routerId) formData.append('router_id', routerId);

    try {
      const { data } = await api.post('/admin/vouchers/import/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      onUploaded(data);
    } catch (err) {
      setError(err.response?.data?.message || err.response?.data?.errors?.pdf?.[0] || 'Upload failed. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <Modal title="Upload Voucher PDF" onClose={onClose}>
      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">PDF File</label>
          <input
            type="file"
            accept="application/pdf"
            required
            onChange={(e) => setFile(e.target.files[0] || null)}
            className="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
          />
          <p className="text-xs text-slate-400 mt-1">Max 10MB. Nothing is imported yet — you'll review a preview first.</p>
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Router these codes were generated on (optional)
          </label>
          <select className="input" value={routerId} onChange={(e) => setRouterId(e.target.value)}>
            <option value="">Not specified</option>
            {routers.map((r) => (
              <option key={r.id} value={r.id}>{r.name}</option>
            ))}
          </select>
          <p className="text-xs text-slate-400 mt-1">
            Needed later for MikroTik status checks on these vouchers — safe to leave blank now and add later.
          </p>
        </div>

        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={uploading || !file}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {uploading ? 'Extracting…' : 'Upload & Extract'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
