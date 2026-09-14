import { useEffect, useState } from 'react';
import { LockKeyhole, ShieldCheck } from 'lucide-react';
import api from '../services/api';

export default function MikrotikSecurityGate({ children }) {
  const [status, setStatus] = useState(null);
  const [key, setKey] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/admin/mikrotik-security/status')
      .then(({ data }) => setStatus(data))
      .catch(() => setStatus({ configured: true, unlocked: false }));
  }, []);

  const unlock = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post('/admin/mikrotik-security/unlock', { security_key: key });
      setKey('');
      setStatus(data);
    } catch (requestError) {
      setError(requestError.response?.status === 429
        ? 'Too many attempts. Wait one minute and try again.'
        : requestError.response?.data?.message || 'Could not unlock Router Management.');
    } finally {
      setBusy(false);
    }
  };

  const lock = async () => {
    await api.post('/admin/mikrotik-security/lock').catch(() => {});
    setStatus((current) => ({ ...current, unlocked: false }));
  };

  if (!status) return <p>Checking Router Management security…</p>;
  if (!status.unlocked) return <form onSubmit={unlock} className='mx-auto mt-16 max-w-md rounded-xl border bg-white p-6 shadow-sm'>
    <LockKeyhole className='mb-4 h-10 w-10 text-amber-600' />
    <h1 className='text-xl font-semibold'>Unlock Router Management</h1>
    <p className='mt-2 text-sm text-slate-500'>Enter the separate security key. Your admin login alone is not enough.</p>
    {!status.configured && <p className='mt-4 text-sm text-red-700'>Run <code>php artisan mikrotik:security-key</code> first.</p>}
    {error && <p className='mt-4 text-sm text-red-700'>{error}</p>}
    <input aria-label='Security key' type='password' value={key} onChange={(e) => setKey(e.target.value)} disabled={!status.configured || busy} className='mt-5 w-full rounded-md border px-3 py-2' autoFocus />
    <button disabled={!status.configured || !key || busy} className='mt-4 w-full rounded-md bg-amber-500 px-4 py-2.5 font-semibold disabled:opacity-50'>{busy ? 'Checking' : 'Unlock'}</button>
  </form>;

  return <div>
    <div className='mb-4 flex justify-between rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800'>
      <span className='flex gap-2'><ShieldCheck className='h-4 w-4' />Router Management unlocked</span>
      <button type='button' onClick={lock} className='rounded bg-slate-900 px-3 py-1 text-white'>Lock now</button>
    </div>
    {children}
  </div>;
}
