import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

export default function AdminLogin() {
  const { login, loading } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ email: '', password: '' });
  const [requiresTwoFactor, setRequiresTwoFactor] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    const result = await login('/admin/login', form, 'admin');
    if (result.success) {
      navigate('/admin/dashboard');
    } else {
      if (result.data?.requires_two_factor) setRequiresTwoFactor(true);
      setError(result.message);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-900 px-4">
      <div className="w-full max-w-sm bg-white rounded-xl shadow-lg p-8">
        <div className="flex justify-center mb-4">
          <img src="/pwa-icon.svg" alt="Royal Hotspot" className="h-14 w-14 rounded-2xl shadow-sm" />
        </div>
        <h1 className="text-xl font-semibold text-slate-900 mb-1 text-center">Admin Login</h1>
        <p className="text-sm text-slate-500 mb-6 text-center">Hotspot Billing System — staff access.</p>

        {error && (
          <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
            <input
              type="email"
              required
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-700"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
            />
          </div>
          {requiresTwoFactor && (
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Authenticator code</label>
              <input inputMode="numeric" pattern="[0-9]{6}" maxLength={6} required className="input" value={form.two_factor_code || ''} onChange={(e) => setForm({ ...form, two_factor_code: e.target.value.replace(/\D/g, '') })} />
            </div>
          )}
          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="block text-sm font-medium text-slate-700">Password</label>
              <Link to="/forgot-password?type=admin" className="text-xs text-slate-600 hover:underline">Forgot password?</Link>
            </div>
            <input
              type="password"
              required
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-700"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-slate-900 text-white rounded-md py-2 text-sm font-medium hover:bg-slate-800 disabled:opacity-50 transition"
          >
            {loading ? 'Logging in…' : 'Log in'}
          </button>
        </form>
      </div>
    </div>
  );
}
