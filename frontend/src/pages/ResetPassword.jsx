import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../services/api';

export default function ResetPassword() {
  const [params] = useSearchParams();
  const email = params.get('email') || '';
  const token = params.get('token') || '';
  const type = params.get('type') === 'admin' ? 'admin' : 'customer';
  const [form, setForm] = useState({ password: '', password_confirmation: '' });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    try {
      const { data } = await api.post('/password/reset', { ...form, email, token, account_type: type });
      setMessage(data.message);
    } catch (err) {
      setError(err.response?.data?.message || 'This reset link is invalid or has expired.');
    } finally {
      setLoading(false);
    }
  };

  const loginUrl = type === 'admin' ? '/admin/login' : '/login';
  return (
    <div className={`min-h-screen flex items-center justify-center px-4 ${type === 'admin' ? 'bg-slate-900' : 'bg-slate-50'}`}>
      <div className="w-full max-w-sm bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 className="text-xl font-semibold text-slate-900 mb-1">Create a new password</h1>
        <p className="text-sm text-slate-500 mb-6">Use at least 8 characters.</p>
        {!token || !email ? <div className="mb-4 text-sm text-red-700">This reset link is incomplete.</div> : null}
        {message && <div className="mb-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2">{message}</div>}
        {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>}
        {!message && (
          <form onSubmit={submit} className="space-y-4">
            <input type="password" required minLength={8} autoComplete="new-password" className="input" placeholder="New password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
            <input type="password" required minLength={8} autoComplete="new-password" className="input" placeholder="Confirm new password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
            <button disabled={loading || !token || !email} className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium disabled:opacity-50">{loading ? 'Resetting…' : 'Reset password'}</button>
          </form>
        )}
        <Link to={loginUrl} className="block text-center text-sm text-indigo-600 mt-5 hover:underline">Back to login</Link>
      </div>
    </div>
  );
}
