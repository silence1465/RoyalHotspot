import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../services/api';

export default function ForgotPassword() {
  const [params] = useSearchParams();
  const type = params.get('type') === 'admin' ? 'admin' : 'customer';
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    try {
      const { data } = await api.post('/password/forgot', { email, account_type: type });
      setMessage(data.message);
    } catch (err) {
      setError(err.response?.data?.message || 'Could not send the reset link. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={`min-h-screen flex items-center justify-center px-4 ${type === 'admin' ? 'bg-slate-900' : 'bg-slate-50'}`}>
      <div className="w-full max-w-sm bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 className="text-xl font-semibold text-slate-900 mb-1">Forgot your password?</h1>
        <p className="text-sm text-slate-500 mb-6">Enter the email connected to your {type} account.</p>
        {message && <div className="mb-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2">{message}</div>}
        {error && <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>}
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Email address</label>
            <input type="email" required autoComplete="email" className="input" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <button disabled={loading} className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium disabled:opacity-50">
            {loading ? 'Sending…' : 'Send reset link'}
          </button>
        </form>
        <Link to={type === 'admin' ? '/admin/login' : '/login'} className="block text-center text-sm text-indigo-600 mt-5 hover:underline">Back to login</Link>
      </div>
    </div>
  );
}
