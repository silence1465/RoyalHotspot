import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { Wifi } from 'lucide-react';
import guestApi from '../../services/guestApi';
import OpenInBrowserLink from '../../components/OpenInBrowserLink';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const durationLabel = (pkg) =>
  `${pkg.duration_value} ${pkg.duration_value === 1 ? pkg.duration_unit.replace(/s$/, '') : pkg.duration_unit}`;

const STORAGE_KEY = 'guest_last_purchase';

/**
 * Landing page for a guest arriving via the admin-pasted MikroTik link:
 * https://ourapp.com/guest/buy?router=5&login-url=$(link-login-only)&mac=$(mac)
 *
 * RouterOS substitutes login-url and mac when it serves the page the
 * admin configured this link on. We hold onto those in sessionStorage —
 * not because THIS page needs them, but because the payment/success page
 * a few steps later does, to submit the literal auto-login form POST
 * straight to the router (see guest checkout design: "activate" = real
 * auto-login, not just a copy button).
 */
export default function GuestBuy() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const routerId = searchParams.get('router');

  const [routerName, setRouterName] = useState('');
  const [packages, setPackages] = useState([]);
  const [selected, setSelected] = useState(null);
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [processing, setProcessing] = useState(false);
  const [actionError, setActionError] = useState('');

  useEffect(() => {
    // Stash the RouterOS-supplied redirect params for later — see the
    // module comment. Only overwrite if actually present, so navigating
    // back to this page later doesn't wipe a value we already captured.
    const loginUrl = searchParams.get('login-url');
    const mac = searchParams.get('mac');
    if (loginUrl) sessionStorage.setItem('guest_login_url', loginUrl);
    if (mac) sessionStorage.setItem('guest_mac', mac);
  }, [searchParams]);

  useEffect(() => {
    if (!routerId) {
      setError('This link is missing location information. Please ask staff for the correct link.');
      setLoading(false);
      return;
    }

    guestApi
      .get('/guest/packages', { params: { router_id: routerId } })
      .then(({ data }) => {
        setRouterName(data.router?.name || '');
        setPackages(data.packages || []);
      })
      .catch(() => setError('Could not load packages. Please try again shortly.'))
      .finally(() => setLoading(false));
  }, [routerId]);

  const handleBuy = async () => {
    if (!phone.trim()) {
      setActionError('Please enter the Mobile Money number you\'ll pay from.');
      return;
    }

    setProcessing(true);
    setActionError('');

    try {
      const { data } = await guestApi.post('/guest/purchases', {
        package_id: selected.id,
        router_id: Number(routerId),
        guest_phone: phone.trim(),
      });

      localStorage.setItem(STORAGE_KEY, JSON.stringify({ reference: data.reference, phone: phone.trim() }));

      navigate(`/guest/payment/${data.reference}`);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Could not start your purchase. Please try again.');
      setProcessing(false);
    }
  };

  if (loading) return <CenteredMessage>Loading…</CenteredMessage>;
  if (error) {
    return (
      <CenteredMessage tone="error">
        {error}
        <br />
        <OpenInBrowserLink className="mt-2" />
      </CenteredMessage>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="max-w-md mx-auto">
        <div className="text-center mb-6">
          <Wifi className="h-8 w-8 text-indigo-600 mx-auto mb-2" />
          <h1 className="text-xl font-semibold text-slate-900">Buy Internet Access</h1>
          {routerName && <p className="text-slate-500 text-sm mt-1">{routerName}</p>}
        </div>

        {selected ? (
          <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
            <p className="text-sm text-slate-700 mb-4">
              <strong>{selected.name}</strong> — {currency(selected.price)}
            </p>

            <label className="block text-xs font-medium text-slate-700 mb-1">
              Mobile Money Number
            </label>
            <input
              type="tel"
              className="input mb-1"
              placeholder="024 XXX XXXX"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
            <p className="text-xs text-slate-400 mb-4">
              The number you'll pay from — used to recover your code if you lose it.
            </p>

            {actionError && <p className="text-sm text-red-600 mb-3">{actionError}</p>}

            <div className="flex gap-2">
              <button
                onClick={handleBuy}
                disabled={processing}
                className="flex-1 bg-indigo-600 text-white rounded-md py-2.5 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
              >
                {processing ? 'Starting…' : 'Continue to Payment'}
              </button>
              <button
                onClick={() => setSelected(null)}
                className="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-900"
              >
                Back
              </button>
            </div>
          </div>
        ) : (
          <div className="space-y-3">
            {packages.length === 0 && (
              <p className="text-center text-slate-400 text-sm py-8">No packages available right now.</p>
            )}
            {packages.map((pkg) => (
              <button
                key={pkg.id}
                onClick={() => setSelected(pkg)}
                className="w-full bg-white rounded-xl border border-slate-200 p-4 shadow-sm text-left hover:border-indigo-300 flex items-center justify-between"
              >
                <div>
                  <p className="font-semibold text-slate-900">{pkg.name}</p>
                  <p className="text-xs text-slate-400">{durationLabel(pkg)}</p>
                </div>
                <p className="text-lg font-semibold text-indigo-600">{currency(pkg.price)}</p>
              </button>
            ))}
          </div>
        )}

        <p className="text-center text-xs text-slate-400 mt-6">
          Already have a code?{' '}
          <Link to="/guest/recover" className="text-indigo-600 hover:underline">
            Find it here
          </Link>
        </p>
      </div>
    </div>
  );
}

function CenteredMessage({ children, tone }) {
  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
      <p className={`text-sm text-center ${tone === 'error' ? 'text-red-600' : 'text-slate-400'}`}>{children}</p>
    </div>
  );
}
