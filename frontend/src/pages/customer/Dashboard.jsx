import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Eye, EyeOff, Gift, Loader2, Wifi } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import CountdownTimer from '../../components/CountdownTimer';
import { canPrepareConnection, connectionControl } from './connectionState';
import { canBeginAutoConnect, shouldPollProvisioning, shouldRetryCurrentConnectionCheck, shouldRetryDashboardLoad } from './paymentAutoConnect';

const currency = (n, c = 'GHS') => new Intl.NumberFormat('en-GH', { style: 'currency', currency: c }).format(n || 0);

export default function CustomerDashboard() {
  const [searchParams, setSearchParams] = useSearchParams();
  const autoConnectRequested = searchParams.get('auto_connect') === '1';
  const confirmationSessionParam = searchParams.get('confirm_session');
  const autoConnectStarted = useRef(false);
  const provisioningAttempts = useRef(0);
  const pendingSessionOnLoad = useRef(
    confirmationSessionParam || sessionStorage.getItem('hotspot_pending_session'),
  );
  const [pendingSessionId, setPendingSessionId] = useState(pendingSessionOnLoad.current);
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [freeInternetOffer, setFreeInternetOffer] = useState(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showWifiPasswordForm, setShowWifiPasswordForm] = useState(false);
  const [wifiPasswordForm, setWifiPasswordForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [wifiPasswordSaving, setWifiPasswordSaving] = useState(false);
  const [wifiPasswordMessage, setWifiPasswordMessage] = useState('');
  const [wifiPasswordError, setWifiPasswordError] = useState('');
  const [connection, setConnection] = useState({ status: '', message: '' });
  const [currentCheckAttempt, setCurrentCheckAttempt] = useState(0);
  const [currentConnectionChecked, setCurrentConnectionChecked] = useState(
    !sessionStorage.getItem('portal_router_id')
      || Boolean(pendingSessionOnLoad.current),
  );

  useEffect(() => {
    const routerId = sessionStorage.getItem('portal_router_id');
    const pendingConfirmation = pendingSessionOnLoad.current;
    if (!routerId || pendingConfirmation) return undefined;

    let cancelled = false;
    let retryTimer;
    setConnection({ status: 'checking', message: 'Checking your WiFi connection...' });

    const retryIfNeeded = (status) => {
      if (shouldRetryCurrentConnectionCheck({
        requested: autoConnectRequested,
        status,
        attempts: currentCheckAttempt + 1,
      })) {
        retryTimer = window.setTimeout(() => {
          if (!cancelled) setCurrentCheckAttempt((attempt) => attempt + 1);
        }, 2000);
      }
    };

    api.get('/customer/hotspot/sessions/current', {
      params: { router_id: Number(routerId) },
      timeout: 5000,
    })
      .then(({ data: current }) => {
        if (cancelled) return;
        if (current.connected === true) {
          setConnection({ status: 'active', message: 'Connected. Your internet is active.' });
        } else if (current.status === 'unknown') {
          setConnection({ status: 'unknown', message: 'Your WiFi connection could not be checked right now.' });
          retryIfNeeded('unknown');
        } else {
          setConnection({ status: '', message: '' });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setConnection({ status: 'unknown', message: 'Your WiFi connection could not be checked right now.' });
          retryIfNeeded('unknown');
        }
      })
      .finally(() => {
        if (!cancelled) setCurrentConnectionChecked(true);
      });

    return () => {
      cancelled = true;
      if (retryTimer) window.clearTimeout(retryTimer);
    };
  }, [autoConnectRequested, currentCheckAttempt]);

  useEffect(() => {
    let cancelled = false;
    let retryTimer;
    let attempts = 0;

    const loadDashboard = async () => {
      attempts += 1;
      try {
        const response = await api.get('/customer/dashboard', { timeout: 10000 });
        if (cancelled) return;
        setData(response.data);
        setError('');
        setLoading(false);
      } catch {
        if (cancelled) return;
        if (shouldRetryDashboardLoad({ requested: autoConnectRequested, attempts })) {
          retryTimer = window.setTimeout(loadDashboard, 2000);
          return;
        }
        setError('Could not load your dashboard. Please try again shortly.');
        setLoading(false);
      }
    };

    loadDashboard();

    // Free-trial availability is optional dashboard decoration. It must
    // never hold up a paid customer's automatic MikroTik login.
    api.get('/customer/free-trial', { timeout: 5000 })
      .then(({ data: freeTrial }) => {
        if (!cancelled) setFreeInternetOffer(freeTrial?.campaign ? freeTrial : null);
      })
      .catch(() => {});

    return () => {
      cancelled = true;
      if (retryTimer) window.clearTimeout(retryTimer);
    };
  }, [autoConnectRequested]);

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
        setPendingSessionId(null);
        setConnection({ status: 'active', message: 'Connected. Your internet is active.' });
        return;
      }
      sessionStorage.setItem('hotspot_pending_session', prepared.session_id);
      setPendingSessionId(prepared.session_id);

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

  const resetWifiPassword = async (event) => {
    event.preventDefault();
    setWifiPasswordSaving(true);
    setWifiPasswordError('');
    setWifiPasswordMessage('');
    try {
      const { data: result } = await api.post('/customer/profile/wifi-password', {
        router_id: purchase.router.id,
        ...wifiPasswordForm,
      });
      setData((current) => ({
        ...current,
        hotspot_credentials: { ...current.hotspot_credentials, password: result.password },
      }));
      setShowPassword(true);
      setShowWifiPasswordForm(false);
      setWifiPasswordForm({ current_password: '', password: '', password_confirmation: '' });
      setWifiPasswordMessage(result.message);
      setConnection({ status: '', message: '' });
    } catch (error) {
      const errors = error.response?.data?.errors || {};
      setWifiPasswordError(errors.current_password?.[0] || errors.password?.[0] || error.response?.data?.message || 'Could not change your Wi-Fi password.');
    } finally {
      setWifiPasswordSaving(false);
    }
  };

  useEffect(() => {
    const sessionId = confirmationSessionParam || pendingSessionId;
    if (!sessionId) return undefined;
    let cancelled = false;
    let attempts = 0;
    let retryTimer;
    setConnection({ status: 'connecting', message: 'Confirming your internet connection...' });

    const finishConfirmation = (nextConnection) => {
      sessionStorage.removeItem('hotspot_pending_session');
      setPendingSessionId(null);
      setConnection(nextConnection);
      if (confirmationSessionParam) setSearchParams({}, { replace: true });
    };

    const confirm = async () => {
      attempts += 1;
      try {
        const { data: result } = await api.get(`/customer/hotspot/sessions/${encodeURIComponent(sessionId)}`);
        if (cancelled) return;
        if (result.status === 'active') {
          finishConfirmation({ status: 'active', message: 'Connected. Your internet is now active.' });
          return;
        }
        if (result.status === 'failed') {
          finishConfirmation({ status: 'failed', message: result.failure_message || 'WiFi connection failed. Please try again.' });
          return;
        }
      } catch (err) {
        if (cancelled) return;
        if (err.response?.status === 404) {
          finishConfirmation({ status: 'failed', message: 'This WiFi connection attempt is no longer available. Please try again.' });
          return;
        }
        if (err.response?.status === 429) {
          const retryAfter = Math.max(2, Number(err.response.headers?.['retry-after']) || 5);
          if (attempts < 15) {
            retryTimer = window.setTimeout(confirm, retryAfter * 1000);
          } else {
            finishConfirmation({ status: 'failed', message: 'WiFi confirmation is temporarily busy. Tap Connect to WiFi to check again.' });
          }
          return;
        }
      }
      if (!cancelled && attempts < 15) {
        retryTimer = window.setTimeout(confirm, 2000);
      } else if (!cancelled) {
        finishConfirmation({ status: 'failed', message: 'WiFi connection could not be confirmed. Tap Connect to WiFi to check again.' });
      }
    };
    confirm();
    return () => {
      cancelled = true;
      if (retryTimer) window.clearTimeout(retryTimer);
    };
  }, [confirmationSessionParam, pendingSessionId, setSearchParams]);

  useEffect(() => {
    if (searchParams.get('auto_connect') !== '1' || !data || autoConnectStarted.current || !currentConnectionChecked || connection.status === 'active' || connection.status === 'unknown') {
      return undefined;
    }

    const hasCredentials = Boolean(
      (data.hotspot_credentials && !data.hotspot_credentials.disabled) || data.voucher,
    );
    const canConnectHere = Boolean(sessionStorage.getItem('guest_login_url'));
    if (!hasCredentials && canConnectHere && data.purchase && provisioningAttempts.current >= 30) {
      setConnection({
        status: 'failed',
        message: 'Your hotspot account is taking longer than expected to activate. Tap Connect to WiFi shortly.',
      });
      return undefined;
    }
    if (!shouldPollProvisioning({
      requested: true,
      hasPortalContext: canConnectHere,
      hasPurchase: Boolean(data.purchase),
      hasCredentials,
      attempts: provisioningAttempts.current,
    })) return undefined;

    setConnection({ status: 'provisioning', message: 'Activating your hotspot account...' });
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
    if (!data || autoConnectStarted.current) {
      return;
    }

    const purchase = data.purchase;
    const credentials = data.hotspot_credentials && !data.hotspot_credentials.disabled
      ? data.hotspot_credentials
      : (data.voucher ? { username: data.voucher.code, password: data.voucher.code } : null);

    if (!canBeginAutoConnect({
      requested: searchParams.get('auto_connect') === '1',
      hasPortalContext: Boolean(sessionStorage.getItem('guest_login_url')),
      hasPurchase: Boolean(purchase),
      hasCredentials: Boolean(credentials),
      connectionStatus: connection.status,
      currentCheckComplete: currentConnectionChecked,
    })) {
      return;
    }

    autoConnectStarted.current = true;
    provisioningAttempts.current = 0;
    window.history.replaceState({}, '', '/dashboard');
    handleConnectToWifi(credentials, purchase.id);
  }, [connection.status, currentConnectionChecked, data, handleConnectToWifi, searchParams]);

  if (loading) {
    return autoConnectRequested ? (
      <div className="mx-auto max-w-md py-12 text-center" role="status" aria-live="polite">
        <Loader2 className="mx-auto mb-4 h-12 w-12 animate-spin text-indigo-600" />
        <h1 className="text-xl font-semibold text-slate-900">Preparing your connection</h1>
        <p className="mt-2 text-sm text-slate-500">Loading your paid package and WiFi credentials&hellip;</p>
        <div className="mx-auto mt-6 h-1.5 max-w-xs overflow-hidden rounded-full bg-indigo-100">
          <div className="h-full w-1/2 animate-pulse rounded-full bg-indigo-600" />
        </div>
      </div>
    ) : <p className="text-sm text-slate-400">Loading…</p>;
  }
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
  const cappedUsage = purchase?.usage_policy === 'data_cap' && Number(purchase.data_allowance_bytes) > 0
    ? {
        used: Number(purchase.cycle_bytes_used || 0),
        allowance: Number(purchase.data_allowance_bytes),
        remaining: Math.max(0, Number(purchase.data_allowance_bytes) - Number(purchase.cycle_bytes_used || 0)),
        percent: Math.min(100, Math.round((Number(purchase.cycle_bytes_used || 0) / Number(purchase.data_allowance_bytes)) * 100)),
      }
    : null;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Hi, {data.customer.full_name.split(' ')[0]} 👋</h1>
        <p className="text-slate-500 text-sm mt-1">Here's your connection status.</p>
      </div>

      {connection.message && (
        <div
          role="status"
          aria-live="polite"
          className={`rounded-md border px-4 py-3 text-sm ${connection.status === 'active' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : connection.status === 'failed' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-indigo-50 border-indigo-200 text-indigo-700'}`}
        >
          <div className="flex items-center gap-3">
            {(connection.status === 'connecting' || connection.status === 'checking' || connection.status === 'provisioning') && (
              <Loader2 className="h-5 w-5 shrink-0 animate-spin" />
            )}
            {connection.status === 'active' && <CheckCircle2 className="h-5 w-5 shrink-0" />}
            <div>
              <p className="font-medium">{connection.message}</p>
              {(connection.status === 'connecting' || connection.status === 'checking' || connection.status === 'provisioning') && (
                <p className="mt-0.5 text-xs opacity-80">Please keep this page open. This normally takes a few seconds.</p>
              )}
            </div>
          </div>
          {(connection.status === 'connecting' || connection.status === 'checking' || connection.status === 'provisioning') && (
            <div className="mt-3 h-1 overflow-hidden rounded-full bg-indigo-100">
              <div className="h-full w-2/3 animate-pulse rounded-full bg-indigo-600" />
            </div>
          )}
        </div>
      )}

      {purchase?.policy_access_status === 'data_exhausted' && (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          <p className="font-semibold">Data allowance exhausted</p>
          <p className="mt-1 text-xs">This package no longer provides internet access. You can purchase another package.</p>
          <Link to="/buy" className="mt-3 inline-block rounded-md bg-red-600 px-3 py-2 text-xs font-medium text-white hover:bg-red-700">
            Buy another package
          </Link>
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

      {freeInternetOffer?.campaign && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
              <Gift className="mt-0.5 h-6 w-6 shrink-0 text-emerald-600" />
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Free campaign available</p>
                <h2 className="mt-1 font-semibold text-slate-900">{freeInternetOffer.campaign.name}</h2>
                <p className="mt-1 text-sm text-slate-600">
                  {freeInternetOffer.campaign.package?.name} at {freeInternetOffer.campaign.router?.name}
                </p>
                <p className="mt-1 text-xs text-emerald-700">Ends {new Date(freeInternetOffer.campaign.ends_at).toLocaleString()}</p>
              </div>
            </div>
            {freeInternetOffer.claimed ? (
              <span className="inline-flex rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700">Already claimed</span>
            ) : freeInternetOffer.can_claim ? (
              <Link to="/free-trial" className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <Gift className="h-4 w-4" />
                View Free Internet
              </Link>
            ) : (
              <span className="inline-flex rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-600">
                {freeInternetOffer.claim_unavailable_reason || 'Currently unavailable'}
              </span>
            )}
          </div>
        </div>
      )}

      {!purchase && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 text-center">
          <Wifi className="h-8 w-8 text-slate-300 mx-auto mb-3" />
          <p className="text-slate-600 font-medium">No active subscription</p>
          <p className="text-slate-400 text-sm mt-1 mb-4">Pick a package to get connected.</p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            <Link
              to="/buy"
              className="inline-flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700"
            >
              <Wifi className="h-4 w-4" />
              Buy Internet
            </Link>
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
            {cappedUsage && (
              <>
                <div>
                  <dt className="text-slate-400">Data remaining</dt>
                  <dd className="text-slate-700">{formatBytes(cappedUsage.remaining)}</dd>
                </div>
                <div>
                  <dt className="text-slate-400">Data used</dt>
                  <dd className="text-slate-700">{cappedUsage.percent}%</dd>
                </div>
              </>
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
                  className="flex w-full items-center justify-center gap-2 bg-indigo-600 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white rounded-md py-2.5 text-sm font-medium hover:bg-indigo-700 mb-4"
                >
                  {connectionUi.kind === 'connecting' && <Loader2 className="h-4 w-4 animate-spin" />}
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
                  <button
                    type="button"
                    onClick={() => {
                      setShowWifiPasswordForm((visible) => !visible);
                      setWifiPasswordError('');
                      setWifiPasswordMessage('');
                    }}
                    className="mt-3 text-xs font-medium text-indigo-600 hover:underline"
                  >
                    {showWifiPasswordForm ? 'Cancel password change' : 'Change Wi-Fi password'}
                  </button>
                  {showWifiPasswordForm && (
                    <form onSubmit={resetWifiPassword} className="mt-3 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                      <input
                        type="password"
                        required
                        className="input"
                        autoComplete="current-password"
                        placeholder="Dashboard password"
                        value={wifiPasswordForm.current_password}
                        onChange={(event) => setWifiPasswordForm((form) => ({ ...form, current_password: event.target.value }))}
                      />
                      <input
                        type="password"
                        required
                        minLength={8}
                        pattern="[A-Za-z0-9]+"
                        className="input"
                        autoComplete="new-password"
                        placeholder="New Wi-Fi password"
                        value={wifiPasswordForm.password}
                        onChange={(event) => setWifiPasswordForm((form) => ({ ...form, password: event.target.value }))}
                      />
                      <input
                        type="password"
                        required
                        minLength={8}
                        pattern="[A-Za-z0-9]+"
                        className="input"
                        autoComplete="new-password"
                        placeholder="Confirm Wi-Fi password"
                        value={wifiPasswordForm.password_confirmation}
                        onChange={(event) => setWifiPasswordForm((form) => ({ ...form, password_confirmation: event.target.value }))}
                      />
                      <button type="submit" disabled={wifiPasswordSaving} className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 sm:col-span-3">
                        {wifiPasswordSaving ? 'Changing…' : 'Change password and disconnect sessions'}
                      </button>
                    </form>
                  )}
                  {wifiPasswordError && <p className="mt-2 text-xs text-red-600">{wifiPasswordError}</p>}
                  {wifiPasswordMessage && <p className="mt-2 text-xs text-emerald-700">{wifiPasswordMessage}</p>}
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
