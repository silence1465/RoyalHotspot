import { useEffect, useState, useCallback } from 'react';
import { MessageSquare, Plus } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import Modal from '../../components/Modal';

export default function CustomerComplaints() {
  const [complaints, setComplaints] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showModal, setShowModal] = useState(false);

  const load = useCallback(() => {
    api
      .get('/customer/complaints')
      .then(({ data }) => setComplaints(data.data || data))
      .catch(() => setError('Could not load your messages.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Complaints & Suggestions</h1>
          <p className="text-slate-500 text-sm mt-1">Let us know if something's wrong, or if you have an idea.</p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-1.5 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700 whitespace-nowrap"
        >
          <Plus className="h-4 w-4" />
          Make Complaint
        </button>
      </div>

      {loading && <p className="text-sm text-slate-400">Loading…</p>}
      {error && <p className="text-sm text-red-600">{error}</p>}

      {!loading && !error && (
        <div className="space-y-3">
          {complaints.length === 0 && (
            <div className="bg-white rounded-xl border border-slate-200 p-8 text-center">
              <MessageSquare className="h-6 w-6 text-slate-300 mx-auto mb-2" />
              <p className="text-slate-400 text-sm">Nothing sent yet.</p>
            </div>
          )}
          {complaints.map((c) => (
            <div key={c.id} className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
              <div className="flex items-center justify-between mb-1">
                <p className="font-medium text-slate-900 text-sm">{c.subject}</p>
                <StatusBadge status={c.status} />
              </div>
              <p className="text-sm text-slate-600 mb-2">{c.message}</p>
              <p className="text-xs text-slate-400">{new Date(c.created_at).toLocaleString()}</p>

              {c.admin_response && (
                <div className="mt-3 pt-3 border-t border-slate-100 bg-indigo-50 -mx-4 -mb-4 px-4 py-3 rounded-b-xl">
                  <p className="text-xs font-medium text-indigo-900 mb-1">Response from support</p>
                  <p className="text-sm text-indigo-800">{c.admin_response}</p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {showModal && (
        <ComplaintFormModal
          onClose={() => setShowModal(false)}
          onSent={() => {
            setShowModal(false);
            load();
          }}
        />
      )}
    </div>
  );
}

function ComplaintFormModal({ onClose, onSent }) {
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setSubmitError('');
    try {
      await api.post('/customer/complaints', { subject, message });
      onSent();
    } catch (err) {
      setSubmitError(err.response?.data?.message || 'Could not send your message. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal title="Make a Complaint" onClose={onClose}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-xs font-medium text-slate-700 mb-1">Subject</label>
          <input
            required
            className="input"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            placeholder="e.g. Slow speeds at Main Office"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-700 mb-1">Message</label>
          <textarea
            required
            rows={4}
            className="input"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder="Tell us what's going on…"
          />
        </div>
        {submitError && <p className="text-sm text-red-600">{submitError}</p>}
        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {submitting ? 'Sending…' : 'Send'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
