import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import OpenInBrowserLink from '../../components/OpenInBrowserLink';

export default function CustomerLogin() {
  const { login, loading } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ username: '', password: '' });
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    const result = await login('/customer/login', form, 'customer');
    if (result.success) {
      navigate(sessionStorage.getItem('guest_login_url') ? '/dashboard?auto_connect=1' : '/dashboard');
    } else {
      setError(result.message);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <div className="flex justify-center mb-4">
          <img src="/pwa-icon.svg" alt="Royal Hotspot" className="h-14 w-14 rounded-2xl shadow-sm" />
        </div>
        <h1 className="text-xl font-semibold text-slate-900 mb-1 text-center">Welcome back</h1>
        <p className="text-sm text-slate-500 mb-6 text-center">Log in to manage your internet subscription.</p>

        {error && (
          <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
            <p>{error}</p>
            <OpenInBrowserLink className="mt-1" />
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Username or Phone</label>
            <input
              type="text"
              required
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
            />
          </div>
          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="block text-sm font-medium text-slate-700">Password</label>
              <Link to="/forgot-password?type=customer" className="text-xs text-indigo-600 hover:underline">Forgot password?</Link>
            </div>
            <input
              type="password"
              required
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition"
          >
            {loading ? 'Logging in…' : 'Log in'}
          </button>
        </form>

        <p className="text-sm text-slate-500 mt-6 text-center">
          New here?{' '}
          <Link to="/register" className="text-indigo-600 font-medium hover:underline">
            Create an account
          </Link>
        </p>
      </div>
    </div>
  );
}
