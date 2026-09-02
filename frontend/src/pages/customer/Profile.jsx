import { useEffect, useState } from 'react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';

export default function CustomerProfile() {
  const { setUser } = useAuth();
  const [form, setForm] = useState({ full_name: '', phone: '', email: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [success, setSuccess] = useState(false);
  const [serverError, setServerError] = useState('');

  useEffect(() => {
    api
      .get('/customer/me')
      .then(({ data }) => {
        setForm({ full_name: data.full_name, phone: data.phone, email: data.email || '' });
        setUser(data);
      })
      .finally(() => setLoading(false));
  }, [setUser]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setServerError('');
    setSuccess(false);

    try {
      const { data } = await api.put('/customer/profile', form);
      setUser(data);
      setSuccess(true);
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors || {});
      } else {
        setServerError('Could not update your profile. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;

  const fieldError = (key) => errors[key]?.[0];

  return (
    <div className="max-w-md">
      <h1 className="text-xl font-semibold text-slate-900 mb-4">Profile</h1>

      {success && (
        <div className="mb-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2">
          Profile updated.
        </div>
      )}
      {serverError && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
          {serverError}
        </div>
      )}

      <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
          <input
            className="input"
            value={form.full_name}
            onChange={(e) => setForm({ ...form, full_name: e.target.value })}
          />
          {fieldError('full_name') && <p className="text-xs text-red-600 mt-1">{fieldError('full_name')}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Phone</label>
          <input
            className="input"
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
          />
          {fieldError('phone') && <p className="text-xs text-red-600 mt-1">{fieldError('phone')}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Email (optional)</label>
          <input
            type="email"
            className="input"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
          {fieldError('email') && <p className="text-xs text-red-600 mt-1">{fieldError('email')}</p>}
        </div>

        <button
          type="submit"
          disabled={saving}
          className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
        >
          {saving ? 'Saving…' : 'Save Changes'}
        </button>
      </form>
    </div>
  );
}
