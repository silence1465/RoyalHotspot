import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Gift, Loader2 } from 'lucide-react';
import api from '../../services/api';
import { isLiveConnectionReady } from './paymentAutoConnect';
import { prepareAndSubmitHotspotLogin } from './hotspotAutoLogin';

const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

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
      const { data: claimed } = await api.post('/customer/free-trial/claim', { campaign_id: data.campaign.id });
      const reference = claimed.purchase?.reference;
      if (!reference) throw new Error('The free package was created without a reference.');

      // Match the MoMo flow: wait until the asynchronous RouterOS job has
      // produced usable credentials before entering dashboard auto-connect.
      for (let attempt = 0; attempt < 30; attempt += 1) {
        const { data: status } = await api.get(`/customer/purchases/${encodeURIComponent(reference)}/status`);
        if (isLiveConnectionReady(status)) {
          const connection = await prepareAndSubmitHotspotLogin(api);
          if (!connection.submitted) navigate('/dashboard', { replace: true });
          return;
        }
        if (status.status === 'pending_activation') {
          throw new Error('Your free package could not be prepared on the router. Please contact support.');
        }
        if (status.status === 'queued') {
          throw new Error('Free internet is waiting for available capacity. Please try again shortly.');
        }
        await wait(2000);
      }

      throw new Error('Your free package is taking longer than expected to activate. Please try again shortly.');
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Could not activate free internet.');
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
        {busy ? (
          <div className="py-5 text-center" role="status" aria-live="polite">
            <Loader2 className="mx-auto mb-3 h-10 w-10 animate-spin text-emerald-600" />
            <p className="font-semibold text-slate-900">Free internet activated — connecting to WiFi</p>
            <p className="mt-1 text-sm text-slate-500">Please wait while we prepare your hotspot account and connect this device…</p>
            <div className="mx-auto mt-5 h-1.5 max-w-xs overflow-hidden rounded-full bg-emerald-100">
              <div className="h-full w-1/2 animate-pulse rounded-full bg-emerald-600" />
            </div>
          </div>
        ) : data.claimed ? <p className="text-sm font-medium text-slate-500">You have already claimed this campaign.</p>
          : data.can_claim ? <button onClick={claim} className="w-full bg-emerald-600 text-white rounded-md py-2.5 font-semibold">Claim Free Internet</button>
            : <p className="rounded-md bg-slate-50 px-4 py-3 text-center text-sm font-medium text-slate-600">{data.claim_unavailable_reason || 'This campaign is currently unavailable to your account.'}</p>}
      </div>}
  </div>;
}
