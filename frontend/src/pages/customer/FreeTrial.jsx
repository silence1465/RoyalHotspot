import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Gift } from 'lucide-react';
import api from '../../services/api';

function remaining(end) {
  const ms = Math.max(0, new Date(end) - new Date());
  const days = Math.floor(ms / 86400000);
  const hours = Math.floor((ms % 86400000) / 3600000);
  return `${days} day${days === 1 ? '' : 's'} and ${hours} hour${hours === 1 ? '' : 's'}`;
}

export default function FreeTrial() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => { api.get('/customer/free-trial').then(({ data }) => setData(data)).catch(() => setError('Could not check the free offer.')); }, []);

  const claim = async () => {
    setBusy(true); setError('');
    try {
      await api.post('/customer/free-trial/claim', { campaign_id: data.campaign.id });
      navigate('/dashboard?auto_connect=1');
    } catch (err) {
      setError(err.response?.data?.message || 'Could not activate free internet.');
      setBusy(false);
    }
  };

  if (!data && !error) return <p className="text-sm text-slate-400">Checking offer…</p>;
  const campaign = data?.campaign;
  return <div className="max-w-xl mx-auto"><h1 className="text-2xl font-semibold text-slate-900">Free Internet</h1>
    {!campaign ? <div className="mt-5 bg-white rounded-xl border p-8 text-center"><Gift className="h-9 w-9 text-slate-300 mx-auto mb-3"/><p className="font-medium text-slate-700">There is no free campaign available now.</p><p className="text-sm text-slate-400 mt-1">Check again during the next promotional period.</p></div> :
      <div className="mt-5 bg-white rounded-xl border border-emerald-200 shadow-sm p-6"><div className="flex gap-3"><Gift className="h-7 w-7 text-emerald-600"/><div><h2 className="font-semibold text-lg text-slate-900">{campaign.name}</h2><p className="text-sm text-slate-500">{campaign.package.name} at {campaign.router.name}</p></div></div>
        <div className="my-5 bg-emerald-50 border border-emerald-200 rounded-lg p-4"><p className="text-sm text-emerald-900">Claim now to receive approximately <strong>{remaining(campaign.ends_at)}</strong> of free internet.</p><p className="text-xs text-emerald-700 mt-2">All free access ends on {new Date(campaign.ends_at).toLocaleString()}, regardless of when it was claimed.</p></div>
        {error && <p className="text-sm text-red-600 mb-3">{error}</p>}
        {data.claimed ? <p className="text-sm font-medium text-slate-500">You have already claimed this campaign.</p>
          : data.can_claim ? <button onClick={claim} disabled={busy} className="w-full bg-emerald-600 text-white rounded-md py-2.5 font-semibold disabled:opacity-50">{busy ? 'Activating…' : 'Claim Free Internet'}</button>
            : <p className="rounded-md bg-slate-50 px-4 py-3 text-center text-sm font-medium text-slate-600">{data.claim_unavailable_reason || 'This campaign is currently unavailable to your account.'}</p>}
      </div>}
  </div>;
}
