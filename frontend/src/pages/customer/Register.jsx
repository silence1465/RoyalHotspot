import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import OpenInBrowserLink from '../../components/OpenInBrowserLink';

export default function CustomerRegister() {
  const { login, loading } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ full_name: '', phone: '', email: '', username: '', password: '' });
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    const result = await login('/customer/register', form, 'customer');
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
        <h1 className="text-xl font-semibold text-slate-900 mb-1 text-center">Create your account</h1>
        <p className="text-sm text-slate-500 mb-6 text-center">Register to get connected in seconds.</p>

        {error && (
          <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
            <p>{error}</p>
            <OpenInBrowserLink className="mt-1" />
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          {[
            { key: 'full_name', label: 'Full Name', type: 'text' },
            { key: 'phone', label: 'Phone Number', type: 'tel' },
            { key: 'email', label: 'Email Address', type: 'email' },
            { key: 'username', label: 'Username', type: 'text' },
            { key: 'password', label: 'Password', type: 'password' },
          ].map((field) => (
            <div key={field.key}>
              <label className="block text-sm font-medium text-slate-700 mb-1">{field.label}</label>
              <input
                type={field.type}
                required
                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                value={form[field.key]}
                onChange={(e) => setForm({ ...form, [field.key]: e.target.value })}
              />
            </div>
          ))}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition"
          >
            {loading ? 'Creating account…' : 'Create account'}
          </button>
        </form>

        <p className="text-sm text-slate-500 mt-6 text-center">
          Already have an account?{' '}
          <Link to="/login" className="text-indigo-600 font-medium hover:underline">
            Log in
          </Link>
        </p>
      </div>
    </div>
  );
}
