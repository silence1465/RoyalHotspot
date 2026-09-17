import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Wifi } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);
const checkoutFee = (price, method) => method === 'paystack' ? Math.round(Number(price) * 2) / 100 : 0;

const durationLabel = (pkg) =>
  `${pkg.duration_value} ${pkg.duration_value === 1 ? pkg.duration_unit.replace(/s$/, '') : pkg.duration_unit}`;

const momoBonusLabel = (pkg) => {
  const value = Number(pkg.momo_bonus_value || 0);
  const unit = pkg.momo_bonus_unit || 'days';
  return `${value} ${value === 1 ? unit.replace(/s$/, '') : unit}`;
};

/**
 * Unified Buy Internet page. The customer never picks a payment method —
 * the system's global toggle (active_payment_method) decides. The
 * backend's POST /customer/purchases handles the routing: Paystack
 * returns an authorization_url for redirect, MoMo returns a reference
 * for the payment page. This component just sends the request and
 * follows whichever response shape comes back.
 */
export default function BuyInternet() {
  const navigate = useNavigate();
  const [packages, setPackages] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const [selected, setSelected] = useState(null);
  const [routerId, setRouterId] = useState('');
  const [processing, setProcessing] = useState(false);
  const [actionError, setActionError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [paymentMethod, setPaymentMethod] = useState('momo');
  const [paymentMethods, setPaymentMethods] = useState({ paystack: true, momo: true });

  const selectedRouter = selected?.available_routers?.find((router) => String(router.id) === routerId);
  const availablePaymentMethods = selectedRouter?.payment_methods || paymentMethods;
  const hasAvailablePaymentMethod = availablePaymentMethods.momo || availablePaymentMethods.paystack;
  const hasCapacity = selectedRouter?.capacity_available ?? true;

  useEffect(() => {
    api
      .get('/customer/packages')
      .then(({ data }) => {
        const methods = data.payment_methods || { paystack: true, momo: true };
        setPackages(data.packages || data);
        setPaymentMethods(methods);
        setPaymentMethod(methods.momo ? 'momo' : 'paystack');
      })
      .catch(() => setError('Could not load packages. Please try again shortly.'))
      .finally(() => setLoading(false));
  }, []);

  const handleSelect = (pkg) => {
    const onlyRouter = pkg.available_routers?.length === 1 ? pkg.available_routers[0] : null;
    const methods = onlyRouter?.payment_methods || paymentMethods;
    setSelected(pkg);
    setActionError('');
    setRouterId(onlyRouter ? String(onlyRouter.id) : '');
    setPaymentMethod(methods.momo ? 'momo' : 'paystack');
    // The summary panel renders above the package grid — without this,
    // tapping "Buy" on a card further down the list (very likely on
    // mobile, where several packages are stacked vertically) leaves the
    // panel off-screen with no visible sign anything happened.
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleBuy = async () => {
    setProcessing(true);
    setActionError('');

    const payload = { package_id: selected.id, payment_method: paymentMethod };
    if (routerId) payload.router_id = Number(routerId);

    try {
      const { data } = await api.post('/customer/purchases', payload);

      if (data.authorization_url) {
        window.location.href = data.authorization_url;
        return;
      }

      if (data.reference) {
        navigate(`/payment/${data.reference}`);
        return;
      }
    } catch (err) {
      setActionError(
        err.response?.data?.errors?.router_id?.[0] ||
          err.response?.data?.errors?.package_id?.[0] ||
          err.response?.data?.message ||
          'Could not start your purchase. Please try again.'
      );
      setProcessing(false);
    }
  };

  const reset = () => {
    setSelected(null);
    setActionError('');
    setRouterId('');
    setConfirming(false);
    setPaymentMethod(paymentMethods.momo ? 'momo' : 'paystack');
  };

  if (loading) return <p className="text-sm text-slate-400">Loading packages…</p>;
  if (error) {
    return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900 mb-1">Buy Internet</h1>
      <p className="text-slate-500 text-sm mb-5">Pick a package to get connected.</p>

      {selected && (
        <div className="mb-5 bg-indigo-50 border border-indigo-200 rounded-md p-4">
          <p className="text-sm text-indigo-900 mb-3">
            <strong>{selected.name}</strong> — {currency(selected.price)}
          </p>

          {/* Router picker — only shown if there are multiple locations */}
          {selected.available_routers?.length > 1 && (
            <div className="mb-3">
              <label className="block text-xs font-medium text-indigo-900 mb-1">Location</label>
              <select className="input" value={routerId} onChange={(e) => {
                const value = e.target.value;
                const router = selected.available_routers.find((item) => String(item.id) === value);
                setRouterId(value);
                setPaymentMethod(router?.payment_methods?.momo ? 'momo' : 'paystack');
              }}>
                <option value="">Select a location…</option>
                {selected.available_routers.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                    {r.location ? ` — ${r.location}` : ''}
                  </option>
                ))}
              </select>
            </div>
          )}

          {selected.available_routers?.length > 1 && !routerId && (
            <p className="text-xs text-indigo-600 mb-3">Choose a location above to continue.</p>
          )}

          {selected.available_routers?.length === 0 && selected.available_routers !== undefined && (
            <p className="text-sm text-amber-600 mb-3">
              No locations available for this package right now.
            </p>
          )}

          {routerId && !hasAvailablePaymentMethod && (
            <p className="text-sm text-amber-700 mb-3">Payments are currently unavailable at this location.</p>
          )}

          {routerId && (
            <p className={`mb-3 inline-flex rounded-full px-3 py-1 text-xs font-semibold ${hasCapacity ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}>
              {selectedRouter?.capacity_message || (hasCapacity ? 'Capacity available' : 'Capacity unavailable')}
            </p>
          )}

          {actionError && <p className="text-sm text-red-600 mb-3">{actionError}</p>}

          <div className="flex gap-2">
            <button
              onClick={() => setConfirming(true)}
              disabled={processing || (selected.available_routers?.length > 1 && !routerId) || selected.available_routers?.length === 0 || (routerId && (!hasAvailablePaymentMethod || !hasCapacity))}
              className="bg-red-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-red-700 disabled:opacity-50"
            >
              Buy Now
            </button>
            <button onClick={reset} className="px-4 py-2 text-sm text-indigo-700 hover:underline">
              Cancel
            </button>
          </div>
        </div>
      )}

      {confirming && selected && (
        <Modal title="Confirm Purchase" onClose={() => setConfirming(false)}>
          <div className="space-y-3 mb-5">
            <div className="flex justify-between text-sm">
              <span className="text-slate-500">Package</span>
              <span className="font-medium text-slate-900">{selected.name}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-slate-500">Duration</span>
              <span className="text-slate-700">{durationLabel(selected)}</span>
            </div>
            {routerId && (
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Location</span>
                <span className="text-slate-700">
                  {selected.available_routers?.find((r) => String(r.id) === routerId)?.name}
                </span>
              </div>
            )}
            <div className="pt-3 border-t border-slate-100">
              <p className="text-sm font-medium text-slate-700 mb-2">Choose payment method</p>
              <div className={`grid gap-2 ${availablePaymentMethods.momo && availablePaymentMethods.paystack ? 'grid-cols-2' : 'grid-cols-1'}`}>
                {availablePaymentMethods.momo && (
                <button type="button" onClick={() => setPaymentMethod('momo')} className={`rounded-md border p-3 text-left ${paymentMethod === 'momo' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200'}`}>
                  <span className="block text-sm font-semibold text-slate-900">Mobile Money</span>
                  <span className="block text-xs text-slate-500 mt-1">No checkout charge</span>
                  {Number(selected.momo_bonus_value) > 0 && (
                    <span className="block text-xs font-medium text-emerald-700 mt-1">
                      +{momoBonusLabel(selected)} free
                    </span>
                  )}
                </button>
                )}
                {availablePaymentMethods.paystack && (
                <button type="button" onClick={() => setPaymentMethod('paystack')} className={`rounded-md border p-3 text-left ${paymentMethod === 'paystack' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}>
                  <span className="block text-sm font-semibold text-slate-900">Paystack</span>
                  <span className="block text-xs text-slate-500 mt-1">Card or MoMo · 2% charge</span>
                </button>
                )}
              </div>
            </div>
            {paymentMethod === 'momo' && Number(selected.momo_bonus_value) > 0 && (
              <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-medium text-emerald-800">Free internet bonus</span>
                  <span className="font-semibold text-emerald-700">+{momoBonusLabel(selected)}</span>
                </div>
                <p className="text-xs text-emerald-700 mt-1">
                  This free bonus will be added after your Mobile Money payment is confirmed.
                </p>
              </div>
            )}
            <div className="flex justify-between text-sm pt-3 border-t border-slate-100">
              <span className="text-slate-500">Payment charge</span>
              <span className="text-slate-700">{currency(checkoutFee(selected.price, paymentMethod))}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="font-medium text-slate-700">Total</span>
              <span className="font-semibold text-indigo-600 text-lg">
                {currency(Number(selected.price) + checkoutFee(selected.price, paymentMethod))}
              </span>
            </div>
          </div>

          {actionError && <p className="text-sm text-red-600 mb-3">{actionError}</p>}

          <div className="flex gap-2">
            <button
              onClick={handleBuy}
              disabled={processing || !hasAvailablePaymentMethod}
              className="flex-1 bg-amber-500 text-slate-950 rounded-md py-2.5 text-sm font-semibold hover:bg-amber-600 disabled:opacity-50"
            >
              {processing ? 'Please wait…' : 'Confirm & Buy'}
            </button>
            <button
              onClick={() => setConfirming(false)}
              className="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-900"
            >
              Cancel
            </button>
          </div>
        </Modal>
      )}

      <div className="grid sm:grid-cols-2 gap-4">
        {packages.length === 0 && (
          <p className="text-slate-400 text-sm sm:col-span-2 text-center py-8">No packages available right now.</p>
        )}
        {packages.map((pkg) => (
          <div key={pkg.id} className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm flex flex-col">
            <div className="flex items-center gap-2 text-slate-400 mb-2">
              <Wifi className="h-4 w-4" />
              <span className="text-xs">{durationLabel(pkg)}</span>
            </div>
            <h2 className="font-semibold text-slate-900 text-lg">{pkg.name}</h2>
            <p className="text-2xl font-semibold text-indigo-600 mt-1">{currency(pkg.price)}</p>
            {pkg.description && <p className="text-sm text-slate-500 mt-2">{pkg.description}</p>}
            <dl className="mt-3 text-sm text-slate-500 space-y-1 flex-1">
              {pkg.speed_limit && (
                <div className="flex justify-between">
                  <dt>Speed</dt>
                  <dd>{pkg.speed_limit}</dd>
                </div>
              )}
              {(pkg.speed_limit || pkg.data_limit) && (
                <div className="flex justify-between">
                  <dt>Data</dt>
                  <dd>{pkg.data_limit || 'Unlimited'}</dd>
                </div>
              )}
            </dl>
            <button
              onClick={() => handleSelect(pkg)}
              className="mt-4 w-full bg-emerald-600 text-white rounded-md py-2 text-sm font-medium hover:bg-emerald-700"
            >
              Buy
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
