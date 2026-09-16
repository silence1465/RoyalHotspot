import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Eye, EyeOff, Gift, Wifi } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import CountdownTimer from '../../components/CountdownTimer';
import { canPrepareConnection, connectionControl } from './connectionState';

const currency = (n, c = 'GHS') => new Intl.NumberFormat('en-GH', { style: 'currency', currency: c }).format(n || 0);

export default function CustomerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams();
  const autoConnectStarted = useRef(false);
  const provisioningAttempts = useRef(0);
  const pendingSessionOnLoad = useRef(
    searchParams.get('confirm_session') || sessionStorage.getItem('hotspot_pending_session'),
  );
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [hasFreeInternet, setHasFreeInternet] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [connection, setConnection] = useState({ status: '', message: '' });
  const [currentConnectionChecked, setCurrentConnectionChecked] = useState(
    !sessionStorage.getItem('portal_router_id')
      || Boolean(pendingSessionOnLoad.current),
  );

  useEffect(() => {
    const routerId = sessionStorage.getItem('portal_router_id');
    const pendingConfirmation = pendingSessionOnLoad.current;
    if (!routerId || pendingConfirmation) return undefined;

    let cancelled = false;
    setConnection({ status: 'checking', message: 'Checking your WiFi connection...' });

    api.get('/customer/hotspot/sessions/current', { params: { router_id: Number(routerId) } })
      .then(({ data: current }) => {
        if (cancelled) return;
        if (current.connected === true) {
          setConnection({ status: 'active', message: 'Connected. Your internet is active.' });
        } else if (current.status === 'unknown') {
          setConnection({ status: 'unknown', message: 'Your WiFi connection could not be checked right now.' });
        } else {
          setConnection({ status: '', message: '' });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setConnection({ status: 'unknown', message: 'Your WiFi connection could not be checked right now.' });
        }
      })
      .finally(() => {
        if (!cancelled) setCurrentConnectionChecked(true);
      });

    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    Promise.all([
      api.get('/customer/dashboard'),
      api.get('/customer/free-trial').catch(() => ({ data: { campaign: null } })),
    ])
      .then(([dashboardResponse, freeTrialResponse]) => {
        setData(dashboardResponse.data);
        setHasFreeInternet(Boolean(freeTrialResponse.data?.campaign));
      })
      .catch(() => setError('Could not load your dashboard. Please try again shortly.'))
      .finally(() => setLoading(false));
  }, []);

  /**
   * "Connect to WiFi" — submits the customer's REAL hotspot username/
   * password (never their app login password — see HotspotUser, a
   * separate machine-generated credential) straight to the router's own
   * login endpoint, from this browser, while the device is still
   * connected to that hotspot's WiFi. Same mechanism as guest checkout's
   * auto-login (see GuestPayment.jsx) — this can't be done server-side,
   * RouterOS ties hotspot auth to which device on its own LAN sent the
   * request.
   *
   * Only appears when guest_login_url is present in sessionStorage —
   * meaning this device actually arrived via the MikroTik portal
   * redirect (see Portal.jsx) this session. Opening the app from a
   * bookmark on mobile data, for instance, correctly shows nothing here
   * since there's no router to connect to in the first place.
   */
  const prepareSecureConnection = useCallback(async () => {
    const loginUrl = sessionStorage.getItem('guest_login_url');
    const routerId = sessionStorage.getItem('portal_router_id');
    if (!loginUrl || !routerId || !canPrepareConnection(connection.status, currentConnectionChecked)) return;

    setConnection({ status: 'connecting', message: 'Preparing your secure hotspot connection...' });
    try {
      const { data: prepared } = await api.post('/customer/hotspot/sessions/prepare', {
        router_id: Number(routerId),
        login_url: loginUrl,
        mac_address: sessionStorage.getItem('guest_mac') || null,
        ip_address: sessionStorage.getItem('guest_ip') || null,
      });
      if (prepared.connected === true || prepared.status === 'active') {
        sessionStorage.removeItem('hotspot_pending_session');
        setConnection({ status: 'active', message: 'Connected. Your internet is active.' });
        return;
      }
      sessionStorage.setItem('hotspot_pending_session', prepared.session_id);

      const form = document.createElement('form');
      form.method = 'POST';
      form.action = prepared.login_url;
      const fields = {
        username: prepared.username,
        password: prepared.password,
        dst: `${window.location.origin}/dashboard?confirm_session=${encodeURIComponent(prepared.session_id)}`,
      };
      Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    } catch (err) {
      setConnection({
        status: 'failed',
        message: err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || 'Could not prepare the hotspot connection.',
      });
    }
  }, [connection.status, currentConnectionChecked]);

  const handleConnectToWifi = useCallback(async () => {
    return prepareSecureConnection();
    /* Legacy direct-login implementation kept as an inline compatibility note.
    const loginUrl = sessionStorage.getItem('guest_login_url');
    if (!loginUrl) return;

    // Voucher purchases only: this is the earliest point the app can
    // observe "the customer is actually connecting now" rather than
    // "the code changed hands" — starts the calendar expiry window on
    // first use. No-op (and safe to skip awaiting hard) for live
    // purchases, which already started their window at payment
    // confirmation. Best-effort: a failed call here shouldn't block the
    // actual WiFi login — the router doesn't care either way.
    if (purchaseId) {
      try {
        await api.post(`/customer/purchases/${purchaseId}/connect`);
      } catch {
        // fall through — still let them connect
      }
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = loginUrl;

    const userField = document.createElement('input');
    userField.type = 'hidden';
    userField.name = 'username';
    userField.value = credentials.username;

    const passField = document.createElement('input');
    passField.type = 'hidden';
    passField.name = 'password';
    passField.value = credentials.password;

    form.appendChild(userField);
    form.appendChild(passField);
    document.body.appendChild(form);
    form.submit(); */
  }, [prepareSecureConnection]);

  useEffect(() => {
    const sessionId = searchParams.get('confirm_session') || sessionStorage.getItem('hotspot_pending_session');
    if (!sessionId) return undefined;
    let cancelled = false;
    let attempts = 0;
    setConnection({ status: 'connecting', message: 'Confirming your internet connection...' });

    const confirm = async () => {
      attempts += 1;
      try {
        const { data: result } = await api.get(`/customer/hotspot/sessions/${encodeURIComponent(sessionId)}`);
        if (cancelled) return;
        if (result.status === 'active') {
          sessionStorage.removeItem('hotspot_pending_session');
          setConnection({ status: 'active', message: 'Connected. Your internet is now active.' });
          setSearchParams({}, { replace: true });
          return;
        }
        if (result.status === 'failed') {
          sessionStorage.removeItem('hotspot_pending_session');
          setConnection({ status: 'failed', message: result.failure_message || 'WiFi connection failed. Please try again.' });
          return;
        }
      } catch (err) {
        if (err.response?.status === 404) sessionStorage.removeItem('hotspot_pending_session');
        if (err.response?.status === 429) {
          const retryAfter = Math.max(2, Number(err.response.headers?.['retry-after']) || 5);
          if (!cancelled && attempts < 15) window.setTimeout(confirm, retryAfter * 1000);
          return;
        }
      }
      if (!cancelled && attempts < 15) {
        window.setTimeout(confirm, 2000);
      } else if (!cancelled) {
        sessionStorage.removeItem('hotspot_pending_session');
        setConnection({ status: 'failed', message: 'WiFi connection failed. Please try again.' });
      }
    };
    confirm();
    return () => { cancelled = true; };
  }, [searchParams, setSearchParams]);

  useEffect(() => {
    if (searchParams.get('auto_connect') !== '1' || !data || autoConnectStarted.current || !currentConnectionChecked || connection.status === 'active' || connection.status === 'unknown') {
      return undefined;
    }

    const hasCredentials = Boolean(
      (data.hotspot_credentials && !data.hotspot_credentials.disabled) || data.voucher,
    );
    const canConnectHere = Boolean(sessionStorage.getItem('guest_login_url'));
    if (hasCredentials || !canConnectHere || !data.purchase) return undefined;

    if (provisioningAttempts.current >= 30) {
      setConnection({
        status: 'failed',
        message: 'Your hotspot account is taking longer than expected to activate. Tap Connect to WiFi shortly.',
      });
      return undefined;
    }

    setConnection({ status: 'connecting', message: 'Activating your hotspot account...' });
    const timer = window.setTimeout(async () => {
      provisioningAttempts.current += 1;
      try {
        const response = await api.get('/customer/dashboard');
        setData(response.data);
      } catch {
        // Trigger the next attempt even when this request failed temporarily.
        setData((current) => ({ ...current }));
      }
    }, 2000);

    return () => window.clearTimeout(timer);
  }, [connection.status, currentConnectionChecked, data, searchParams]);

  useEffect(() => {
    if (searchParams.get('auto_connect') !== '1' || !data || autoConnectStarted.current || !canPrepareConnection(connection.status, currentConnectionChecked)) {
      return;
    }

    const purchase = data.purchase;
    const credentials = data.hotspot_credentials && !data.hotspot_credentials.disabled
      ? data.hotspot_credentials
      : (data.voucher ? { username: data.voucher.code, password: data.voucher.code } : null);

    if (!purchase || !credentials || !sessionStorage.getItem('guest_login_url')) {
      return;
    }

    autoConnectStarted.current = true;
    provisioningAttempts.current = 0;
    window.history.replaceState({}, '', '/dashboard');
    handleConnectToWifi(credentials, purchase.id);
  }, [connection.status, currentConnectionChecked, data, handleConnectToWifi, searchParams]);

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;
  if (error) {
    return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  }

  const { purchase, hotspot_credentials: hotspot, voucher, remaining_seconds: remaining, recent_payments: payments, bandwidth_today: bandwidthToday, bandwidth_total: bandwidthTotal } = data;

  // Voucher purchases don't get a HotspotUser row (see DashboardController)
  // — their login is the voucher code itself, used as both username and
  // password, same convention as every other voucher/guest code in this
  // system.
  const credentials = hotspot || (voucher ? { username: voucher.code, password: voucher.code } : null);
  const hasPortalContext = Boolean(sessionStorage.getItem('guest_login_url'));
  const accountReady = Boolean(credentials) && purchase?.status !== 'pending_activation';
  const connectionUi = connectionControl(connection.status, hasPortalContext, accountReady);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Hi, {data.customer.full_name.split(' ')[0]} 👋</h1>
        <p className="text-slate-500 text-sm mt-1">Here's your connection status.</p>
      </div>

      {connection.message && (
        <div className={`rounded-md border px-4 py-3 text-sm ${connection.status === 'active' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : connection.status === 'failed' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-indigo-50 border-indigo-200 text-indigo-700'}`}>
          {connection.message}
        </div>
      )}

      <div className="grid grid-cols-2 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <p className="text-xs text-slate-400">Bandwidth Today</p>
          <p className="text-lg font-semibold text-slate-900 mt-1">{formatBytes(bandwidthToday)}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <p className="text-xs text-slate-400">Total Bandwidth Used</p>
          <p className="text-lg font-semibold text-slate-900 mt-1">{formatBytes(bandwidthTotal)}</p>
        </div>
      </div>

      {!purchase && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 text-center">
          <Wifi className="h-8 w-8 text-slate-300 mx-auto mb-3" />
          <p className="text-slate-600 font-medium">No active subscription</p>
          <p className="text-slate-400 text-sm mt-1 mb-4">Pick a package to get connected.</p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            {!hasFreeInternet && (
              <Link
                to="/buy"
                className="inline-flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700"
              >
                <Wifi className="h-4 w-4" />
                Buy Internet
              </Link>
            )}
            {hasFreeInternet && (
              <Link
                to="/free-trial"
                className="inline-flex items-center gap-2 bg-emerald-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-emerald-700"
              >
                <Gift className="h-4 w-4" />
                Free Internet
              </Link>
            )}
          </div>
        </div>
      )}

      {purchase && (
        <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold text-slate-900">{purchase.package.name}</h2>
            <StatusBadge status={purchase.status} />
          </div>

          <dl className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <dt className="text-slate-400">Speed</dt>
              <dd className="text-slate-700">{purchase.package.speed_limit || '—'}</dd>
            </div>
            <div>
              <dt className="text-slate-400">Usage policy</dt>
              <dd className="text-slate-700">
                {purchase.usage_policy === 'fup' && `FUP Tier ${purchase.current_fup_tier} (${purchase.fup_period})`}
                {purchase.usage_policy === 'data_cap' && (purchase.policy_access_status === 'data_exhausted' ? 'Data exhausted' : 'Hard data cap')}
                {(!purchase.usage_policy || purchase.usage_policy === 'none') && (purchase.package.data_limit || 'Unlimited')}
              </dd>
            </div>
            {purchase.usage_policy !== 'none' && purchase.data_allowance_bytes && (
              <div>
                <dt className="text-slate-400">Allowance used</dt>
                <dd className="text-slate-700">
                  {formatBytes(purchase.fup_period === 'daily' ? bandwidthToday : purchase.cycle_bytes_used)} / {formatBytes(purchase.data_allowance_bytes)}
                </dd>
              </div>
            )}
            <div>
              <dt className="text-slate-400">Time remaining</dt>
              <dd className="text-slate-700 font-medium">
                {remaining != null
                  ? <CountdownTimer initialSeconds={remaining} />
                  : (!purchase.starts_at ? 'Starts when connected' : '—')}
              </dd>
            </div>
            <div>
              <dt className="text-slate-400">Router</dt>
              <dd className="text-slate-700">{purchase.router?.name || '—'}</dd>
            </div>
          </dl>

          {purchase.status === 'pending_activation' && (
            <p className="mt-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
              Your hotspot account could not be activated. Please try again or contact support.
            </p>
          )}

          {purchase.status !== 'pending_activation' && purchase.fulfillment_type === 'live' && !credentials && (
            <p className="mt-4 text-xs text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-md px-3 py-2">
              Activating your hotspot account...
            </p>
          )}

          {credentials && (
            <div className="mt-4 pt-4 border-t border-slate-100">
              {connectionUi.kind === 'connected' && (
                <div className="w-full bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-md py-2.5 px-4 text-sm font-medium mb-4">
                  {connectionUi.label}
                </div>
              )}

              {(connectionUi.kind === 'connect' || connectionUi.kind === 'connecting') && (
                <button
                  onClick={() => handleConnectToWifi(credentials, purchase.id)}
                  disabled={connectionUi.disabled}
                  className="w-full bg-indigo-600 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white rounded-md py-2.5 text-sm font-medium hover:bg-indigo-700 mb-4"
                >
                  {connectionUi.label}
                </button>
              )}

              {hotspot ? (
                <>
                  <p className="text-xs text-slate-400 mb-2">Your Wi-Fi login</p>
                  <div className="flex items-center gap-4 text-sm">
                    <div>
                      <span className="text-slate-400">Username:</span>{' '}
                      <span className="font-mono text-slate-800">{hotspot.username}</span>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <span className="text-slate-400">Password:</span>{' '}
                      <span className="font-mono text-slate-800">
                        {showPassword ? hotspot.password : '••••••••'}
                      </span>
                      <button onClick={() => setShowPassword((s) => !s)} className="text-slate-400 hover:text-slate-700">
                        {showPassword ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                      </button>
                    </div>
                  </div>
                </>
              ) : (
                <>
                  <p className="text-xs text-slate-400 mb-2">Your voucher code</p>
                  <div className="flex items-center gap-1.5 text-sm">
                    <span className="font-mono text-slate-800 tracking-wide">
                      {showPassword ? voucher.code : '••••••••••••'}
                    </span>
                    <button onClick={() => setShowPassword((s) => !s)} className="text-slate-400 hover:text-slate-700">
                      {showPassword ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                    </button>
                  </div>
                </>
              )}
            </div>
          )}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-700">Recent Payments</h2>
          <Link to="/payments" className="text-xs text-indigo-600 hover:underline">
            View all
          </Link>
        </div>
        <table className="w-full text-sm">
          <tbody>
            {payments.length === 0 && (
              <tr>
                <td className="px-5 py-4 text-slate-400 text-center">No payments yet.</td>
              </tr>
            )}
            {payments.map((p) => (
              <tr key={p.id} className="border-b border-slate-50 last:border-0">
                <td className="px-5 py-2.5 text-slate-500">
                  {p.paid_at ? new Date(p.paid_at).toLocaleDateString() : new Date(p.created_at).toLocaleDateString()}
                </td>
                <td className="px-5 py-2.5">{currency(p.amount, p.currency)}</td>
                <td className="px-5 py-2.5 text-right">
                  <StatusBadge status={p.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}
