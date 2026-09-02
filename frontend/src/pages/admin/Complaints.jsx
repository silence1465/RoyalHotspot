import { useEffect, useState, useCallback } from 'react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import Modal from '../../components/Modal';

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'resolved', label: 'Resolved' },
];

export default function AdminComplaints() {
  const [complaints, setComplaints] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [respondTarget, setRespondTarget] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/admin/complaints', { params: { status: status || undefined } })
      .then(({ data }) => setComplaints(data.data || data))
      .catch(() => setError('Could not load complaints.'))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Complaints & Suggestions</h1>
        <p className="text-slate-500 text-sm mt-1">Customer messages, filterable by status.</p>
      </div>

      <div className="mb-4 flex gap-2">
        {STATUS_FILTERS.map((f) => (
          <button
            key={f.value}
            onClick={() => setStatus(f.value)}
            className={`px-3 py-1.5 rounded-md text-sm font-medium border ${
              status === f.value ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200'
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{error}</div>}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        {loading && <p className="p-6 text-center text-slate-400 text-sm">Loading…</p>}
        {!loading && complaints.length === 0 && <p className="p-6 text-center text-slate-400 text-sm">No complaints found.</p>}
        {!loading &&
          complaints.map((c) => (
            <button
              key={c.id}
              onClick={() => setRespondTarget(c)}
              className="w-full text-left px-5 py-4 border-b border-slate-50 last:border-0 hover:bg-slate-50 flex items-start justify-between gap-4"
            >
              <div>
                <p className="font-medium text-slate-900 text-sm">{c.subject}</p>
                <p className="text-xs text-slate-400 mt-0.5">
                  {c.customer?.full_name} · {new Date(c.created_at).toLocaleDateString()}
                </p>
              </div>
              <StatusBadge status={c.status} />
            </button>
          ))}
      </div>

      {respondTarget && (
        <RespondModal
          complaint={respondTarget}
          onClose={() => setRespondTarget(null)}
          onUpdated={() => {
            setRespondTarget(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function RespondModal({ complaint, onClose, onUpdated }) {
  const [response, setResponse] = useState(complaint.admin_response || '');
  const [newStatus, setNewStatus] = useState(complaint.status);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.post(`/admin/complaints/${complaint.id}/respond`, {
        status: newStatus,
        admin_response: response,
      });
      onUpdated();
    } catch {
      setError('Could not save. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={complaint.subject} onClose={onClose}>
      <div className="mb-4 bg-slate-50 border border-slate-200 rounded-md p-3">
        <p className="text-xs text-slate-400 mb-1">
          {complaint.customer?.full_name} · {complaint.customer?.phone}
        </p>
        <p className="text-sm text-slate-700">{complaint.message}</p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select className="input" value={newStatus} onChange={(e) => setNewStatus(e.target.value)}>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="resolved">Resolved</option>
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Response (optional)</label>
          <textarea
            rows={4}
            className="input"
            value={response}
            onChange={(e) => setResponse(e.target.value)}
            placeholder="Visible to the customer on their complaint..."
          />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
