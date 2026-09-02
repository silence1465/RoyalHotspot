import { useEffect, useState } from 'react';
import api from '../../services/api';

const TABS = [
  { id: 'business', label: 'Business' },
  { id: 'gateway', label: 'Payment Gateway' },
  { id: 'momo', label: 'MoMo & Vouchers' },
  { id: 'system', label: 'System' },
];

export default function AdminSettings() {
  const [tab, setTab] = useState('business');
  const [routers, setRouters] = useState([]);
  const [form, setForm] = useState({
    paystack_enabled: true,
    momo_enabled: true,
    business_name: '',
    business_phone: '',
    default_router_id: '',
    paystack_public_key: '',
    sms_enabled: false,
    grace_period_minutes: 0,
    auto_suspend_enabled: true,
    momo_number: '',
    momo_account_name: '',
    order_expiry_minutes: 60,
    low_stock_threshold: 10,
    sms_heartbeat_threshold_minutes: 5,
    telegram_bot_token: '',
    telegram_chat_id: '',
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([api.get('/admin/settings'), api.get('/admin/routers', { params: { per_page: 100 } })])
      .then(([settingsRes, routersRes]) => {
        const s = settingsRes.data;
        setForm({
          paystack_enabled: s.paystack_enabled ?? true,
          momo_enabled: s.momo_enabled ?? true,
          business_name: s.business_name || '',
          business_phone: s.business_phone || '',
          default_router_id: s.default_router_id || '',
          paystack_public_key: s.paystack_public_key || '',
          sms_enabled: Boolean(s.sms_enabled),
          grace_period_minutes: s.grace_period_minutes ?? 0,
          auto_suspend_enabled: s.auto_suspend_enabled ?? true,
          momo_number: s.momo_number || '',
          momo_account_name: s.momo_account_name || '',
          order_expiry_minutes: s.order_expiry_minutes ?? 60,
          low_stock_threshold: s.low_stock_threshold ?? 10,
          sms_heartbeat_threshold_minutes: s.sms_heartbeat_threshold_minutes ?? 5,
          telegram_bot_token: s.telegram_bot_token || '',
          telegram_chat_id: s.telegram_chat_id || '',
        });
        setRouters(routersRes.data.data || routersRes.data);
      })
      .catch(() => setError('Could not load settings. Is the backend running?'))
      .finally(() => setLoading(false));
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setSuccess(false);
    try {
      await api.put('/admin/settings', form);
      setSuccess(true);
    } catch (err) {
      setError(err.response?.data?.message || 'Could not save settings. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;

  return (
    <div className="max-w-xl">
      <h1 className="text-2xl font-semibold text-slate-900 mb-1">Settings</h1>
      <p className="text-slate-500 text-sm mb-6">Business info and system behavior.</p>

      {success && (
        <div className="mb-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2">
          Settings saved.
        </div>
      )}
      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>
      )}

      <div className="flex gap-1 mb-4 border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${
              tab === t.id ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-slate-200 p-5 space-y-5">
        {tab === 'business' && (
          <>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Business Name</label>
              <input className="input" value={form.business_name} onChange={(e) => set('business_name', e.target.value)} />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Business Phone</label>
              <input className="input" value={form.business_phone} onChange={(e) => set('business_phone', e.target.value)} />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Default Router</label>
              <select className="input" value={form.default_router_id} onChange={(e) => set('default_router_id', e.target.value)}>
                <option value="">None</option>
                {routers.map((r) => (
                  <option key={r.id} value={r.id}>{r.name}</option>
                ))}
              </select>
            </div>
          </>
        )}

        {tab === 'gateway' && (
          <>
            <div className="border border-indigo-200 bg-indigo-50 rounded-lg p-4">
              <label className="block text-sm font-semibold text-indigo-900 mb-1">Customer Payment Options</label>
              <p className="text-xs text-indigo-700 mb-3">
                Choose which payment methods customers can use. At least one must remain enabled.
              </p>
              <div className="space-y-2">
                <label className="flex items-center justify-between rounded-md border border-indigo-200 bg-white p-3 cursor-pointer">
                  <span>
                    <span className="block text-sm font-medium text-slate-900">Paystack</span>
                    <span className="block text-xs text-slate-500">Card or mobile money via Paystack · 2% charge</span>
                  </span>
                  <input type="checkbox" className="h-5 w-5 accent-indigo-600" checked={form.paystack_enabled} onChange={(e) => set('paystack_enabled', e.target.checked)} />
                </label>
                <label className="flex items-center justify-between rounded-md border border-indigo-200 bg-white p-3 cursor-pointer">
                  <span>
                    <span className="block text-sm font-medium text-slate-900">Direct Mobile Money</span>
                    <span className="block text-xs text-slate-500">SMS-confirmed transfer · no checkout charge</span>
                  </span>
                  <input type="checkbox" className="h-5 w-5 accent-indigo-600" checked={form.momo_enabled} onChange={(e) => set('momo_enabled', e.target.checked)} />
                </label>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Paystack Public Key</label>
              <input className="input" value={form.paystack_public_key} onChange={(e) => set('paystack_public_key', e.target.value)} />
              <p className="text-xs text-slate-400 mt-1">
                Not currently used by the checkout flow (which redirects server-side via the secret key) — kept
                here for future client-side integrations.
              </p>
            </div>
          </>
        )}

        {tab === 'momo' && (
          <>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">MoMo Number</label>
              <input
                className="input"
                placeholder="0540433375"
                value={form.momo_number}
                onChange={(e) => set('momo_number', e.target.value)}
              />
              <p className="text-xs text-slate-400 mt-1">Shown to customers on the payment page — never hardcoded.</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">MoMo Account Name</label>
              <input
                className="input"
                placeholder="Ebenezer Gyamfi"
                value={form.momo_account_name}
                onChange={(e) => set('momo_account_name', e.target.value)}
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Order Expiry (minutes)</label>
              <input
                type="number"
                min="5"
                max="1440"
                className="input"
                value={form.order_expiry_minutes}
                onChange={(e) => set('order_expiry_minutes', Number(e.target.value))}
              />
              <p className="text-xs text-slate-400 mt-1">How long a customer has to pay before an order expires.</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Low Stock Threshold</label>
              <input
                type="number"
                min="0"
                max="1000"
                className="input"
                value={form.low_stock_threshold}
                onChange={(e) => set('low_stock_threshold', Number(e.target.value))}
              />
              <p className="text-xs text-slate-400 mt-1">
                A package is flagged "low stock" on the Vouchers page once available codes fall to or below this.
              </p>
            </div>
          </>
        )}

        {tab === 'system' && (
          <>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Grace Period (minutes)</label>
              <input
                type="number"
                min="0"
                max="10080"
                className="input"
                value={form.grace_period_minutes}
                onChange={(e) => set('grace_period_minutes', Number(e.target.value))}
              />
              <p className="text-xs text-slate-400 mt-1">
                How long after expiry a customer's hotspot access stays on before being disabled.
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                SMS Forwarder Heartbeat Threshold (minutes)
              </label>
              <input
                type="number"
                min="1"
                max="60"
                className="input"
                value={form.sms_heartbeat_threshold_minutes}
                onChange={(e) => set('sms_heartbeat_threshold_minutes', Number(e.target.value))}
              />
              <p className="text-xs text-slate-400 mt-1">
                The SMS Forwarder phone is shown as "Offline" if it hasn't pinged within this many minutes —
                shown on the Dashboard and to customers submitting a Transaction ID.
              </p>
            </div>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={form.auto_suspend_enabled}
                onChange={(e) => set('auto_suspend_enabled', e.target.checked)}
              />
              Automatically suspend customers with no active subscription
            </label>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={form.sms_enabled} onChange={(e) => set('sms_enabled', e.target.checked)} />
              SMS notifications enabled
              <span className="text-xs text-slate-400">(integration not yet built — flagged as future work)</span>
            </label>

            <AdminMfa />

            <div className="border-t border-slate-100 pt-5">
              <p className="text-sm font-semibold text-slate-700 mb-1">Router-Offline Alerts</p>
              <p className="text-xs text-slate-400 mb-4">
                Notified when a live router goes offline. Browser notifications only fire while this admin panel
                is open in a background tab — Telegram works even when it's closed.
              </p>

              <label className="block text-xs font-medium text-slate-700 mb-1">Telegram Bot Token</label>
              <input
                className="input mb-3"
                placeholder="123456:ABC-DEF..."
                value={form.telegram_bot_token}
                onChange={(e) => set('telegram_bot_token', e.target.value)}
              />

              <label className="block text-xs font-medium text-slate-700 mb-1">Telegram Chat ID</label>
              <input
                className="input mb-3"
                placeholder="e.g. 123456789"
                value={form.telegram_chat_id}
                onChange={(e) => set('telegram_chat_id', e.target.value)}
              />

              <BrowserNotificationToggle />
            </div>
          </>
        )}

        <button
          type="submit"
          disabled={saving}
          className="w-full bg-indigo-600 text-white rounded-md py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
        >
          {saving ? 'Saving…' : 'Save Settings'}
        </button>
      </form>
    </div>
  );
}

function AdminMfa() {
  const [enabled, setEnabled] = useState(false);
  const [setup, setSetup] = useState(null);
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/admin/me').then(({ data }) => setEnabled(Boolean(data.two_factor_confirmed_at)));
  }, []);

  const begin = async () => {
    setError('');
    const { data } = await api.post('/admin/mfa/setup');
    setSetup(data);
  };

  const confirm = async () => {
    try {
      const { data } = await api.post('/admin/mfa/confirm', { secret: setup.secret, code });
      setEnabled(true); setSetup(null); setCode(''); setMessage(data.message); setError('');
    } catch (err) { setError(err.response?.data?.message || 'Could not enable two-factor authentication.'); }
  };

  const disable = async () => {
    try {
      const { data } = await api.post('/admin/mfa/disable', { password, code });
      setEnabled(false); setPassword(''); setCode(''); setMessage(data.message); setError('');
    } catch (err) { setError(err.response?.data?.message || 'Could not disable two-factor authentication.'); }
  };

  return (
    <div className="border-t border-slate-100 pt-5">
      <p className="text-sm font-semibold text-slate-700">Administrator two-factor authentication</p>
      <p className="text-xs text-slate-400 mt-1 mb-3">Protect this account with Google Authenticator, Microsoft Authenticator, Authy, or another TOTP app.</p>
      {message && <p className="text-xs text-emerald-600 mb-2">{message}</p>}
      {error && <p className="text-xs text-red-600 mb-2">{error}</p>}
      {!enabled && !setup && <button type="button" onClick={begin} className="text-sm font-medium text-indigo-600 border border-indigo-200 rounded-md px-3 py-2">Set up authenticator</button>}
      {setup && <div className="space-y-2 bg-slate-50 border rounded-md p-3"><p className="text-xs text-slate-600">Add an account manually using this secret:</p><p className="font-mono text-sm break-all font-semibold">{setup.secret}</p><input className="input" inputMode="numeric" maxLength={6} placeholder="6-digit code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}/><button type="button" onClick={confirm} disabled={code.length !== 6} className="bg-indigo-600 text-white rounded-md px-3 py-2 text-sm disabled:opacity-50">Confirm and enable</button></div>}
      {enabled && <div className="space-y-2"><p className="text-xs font-medium text-emerald-600">Enabled</p><input type="password" className="input" placeholder="Current password" value={password} onChange={(e) => setPassword(e.target.value)}/><input className="input" inputMode="numeric" maxLength={6} placeholder="Authenticator code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}/><button type="button" onClick={disable} disabled={!password || code.length !== 6} className="text-sm text-red-600 border border-red-200 rounded-md px-3 py-2 disabled:opacity-50">Disable two-factor authentication</button></div>}
    </div>
  );
}

function BrowserNotificationToggle() {
  const [permission, setPermission] = useState(
    typeof Notification !== 'undefined' ? Notification.permission : 'unsupported'
  );

  const handleEnable = async () => {
    if (typeof Notification === 'undefined') return;
    const result = await Notification.requestPermission();
    setPermission(result);
  };

  if (permission === 'unsupported') {
    return <p className="text-xs text-slate-400">Browser notifications aren't supported in this browser.</p>;
  }

  if (permission === 'granted') {
    return <p className="text-xs text-emerald-600">✓ Browser notifications enabled on this device.</p>;
  }

  return (
    <button
      type="button"
      onClick={handleEnable}
      className="text-xs text-indigo-600 border border-indigo-200 rounded-md px-3 py-1.5 hover:bg-indigo-50"
    >
      {permission === 'denied' ? 'Blocked — enable in browser settings' : 'Enable Browser Notifications'}
    </button>
  );
}
