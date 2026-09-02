import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Ticket, CheckCircle2 } from 'lucide-react';
import api from '../../services/api';

export default function RedeemVoucher() {
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const [availableRouters, setAvailableRouters] = useState(null); // null = not needed yet
  const [routerId, setRouterId] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');

    try {
      const { data } = await api.post('/customer/vouchers/redeem', {
        code,
        router_id: routerId || undefined,
      });
      setSuccess(true);
      setTimeout(() => navigate('/dashboard'), 1500);
      void data;
    } catch (err) {
      const body = err.response?.data;
      if (body?.requires_router_selection) {
        setAvailableRouters(body.available_routers);
        setError(body.message);
      } else {
        setError(body?.message || 'Could not redeem this voucher. Please check the code and try again.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (success) {
    return (
      <div className="max-w-sm mx-auto text-center py-12">
        <CheckCircle2 className="h-12 w-12 text-emerald-500 mx-auto mb-4" />
        <h1 className="text-xl font-semibold text-slate-900">Voucher redeemed!</h1>
        <p className="text-slate-500 text-sm mt-1">Setting up your connection now…</p>
      </div>
    );
  }

  return (
    <div className="max-w-sm mx-auto">
      <div className="text-center mb-6">
        <Ticket className="h-8 w-8 text-indigo-500 mx-auto mb-2" />
        <h1 className="text-xl font-semibold text-slate-900">Redeem a Voucher</h1>
        <p className="text-slate-500 text-sm mt-1">Enter the code from your voucher to get connected.</p>
      </div>

      <form onSubmit={submit} className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Voucher Code</label>
          <input
            required
            className="input font-mono uppercase"
            placeholder="XXXX-XXXX-XXXX"
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
        </div>

        {availableRouters && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Location</label>
            <select required className="input" value={routerId} onChange={(e) => setRouterId(e.target.value)}>
              <option value="">Select a location…</option>
              {availableRouters.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                  {r.location ? ` — ${r.location}` : ''}
                </option>
              ))}
            </select>
          </div>
        )}

        {error && (
          <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>
        )}

        <button
          type="submit"
          disabled={submitting}
          className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
        >
          {submitting ? 'Redeeming…' : 'Redeem'}
        </button>
      </form>
    </div>
  );
}
