import { useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../services/api';

const emptyIsp = () => ({
  name: '',
  wan_interface: '',
  gateway: '',
  routing_table: 'main',
  connection_mark: '',
  monthly_capacity_gb: '',
  subscriber_limit: '',
  priority: 100,
  enabled: true,
  session_monitoring_enabled: false,
  session_protection_enabled: false,
  stale_cleanup_enabled: false,
  emergency_cleanup_enabled: false,
  session_soft_limit: 800,
  session_hard_limit: 1000,
  session_emergency_limit: 1100,
  max_tcp_sessions_per_client: '',
  max_udp_sessions_per_client: '',
  max_total_sessions_per_client: '',
});

const emptyForm = {
  name: '',
  location: '',
  connection_mode: 'live',
  router_ip: '',
  wireguard_ip: '',
  api_username: '',
  api_password: '',
  api_port: 8729,
  api_ssl: true,
  provisioning_api_username: '',
  provisioning_api_password: '',
  address_pool: '',
  hotspot_login_host: '',
  momo_enabled: true,
  paystack_enabled: true,
  routeros_version: '',
  isp_failover_enabled: false,
  isp_failback_enabled: true,
  isps: [],
};

export default function RouterFormModal({ router, onClose, onSaved }) {
  const isEdit = Boolean(router);
  const [form, setForm] = useState(
    isEdit
      ? {
          name: router.name || '',
          location: router.location || '',
          connection_mode: router.connection_mode || 'live',
          router_ip: router.router_ip || '',
          wireguard_ip: router.wireguard_ip || '',
          api_username: router.api_username || '',
          api_password: '', // never pre-filled — blank means "keep existing"
          api_port: router.api_port || 8729,
          api_ssl: router.api_ssl ?? true,
          provisioning_api_username: router.provisioning_api_username || '',
          provisioning_api_password: '',
          address_pool: router.address_pool || '',
          hotspot_login_host: router.hotspot_login_host || '',
          momo_enabled: router.momo_enabled ?? true,
          paystack_enabled: router.paystack_enabled ?? true,
          routeros_version: router.routeros_version || '',
          isp_failover_enabled: router.isp_failover_enabled ?? false,
          isp_failback_enabled: router.isp_failback_enabled ?? true,
          isps: (router.isps || []).map((isp) => ({
            ...isp,
            monthly_capacity_gb: isp.monthly_capacity_bytes
              ? Number(isp.monthly_capacity_bytes) / 1073741824
              : '',
            subscriber_limit: isp.subscriber_limit || '',
          })),
        }
      : emptyForm
  );
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [serverError, setServerError] = useState('');

  const isManual = form.connection_mode === 'manual';

  const handleChange = (key, value) => setForm((f) => ({ ...f, [key]: value }));
  const handleIspChange = (index, key, value) => {
    setForm((current) => ({
      ...current,
      isps: current.isps.map((isp, ispIndex) => (ispIndex === index ? { ...isp, [key]: value } : isp)),
    }));
  };
  const addIsp = () => setForm((current) => ({ ...current, isps: [...current.isps, emptyIsp()] }));
  const removeIsp = (index) => {
    setForm((current) => ({ ...current, isps: current.isps.filter((_, ispIndex) => ispIndex !== index) }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setServerError('');

    // For a manual router, omit connection-detail fields from the
    // payload entirely rather than sending empty strings — an omitted
    // key leaves the column untouched/NULL at the database level; an
    // empty string would get written (and, for api_password, actually
    // encrypted) as a real "blank" value instead of being cleanly absent.
    const payload = isManual
      ? {
          name: form.name,
          location: form.location,
          connection_mode: form.connection_mode,
          routeros_version: form.routeros_version,
          isp_failover_enabled: form.isp_failover_enabled,
          isp_failback_enabled: form.isp_failback_enabled,
          isps: form.isps,
          momo_enabled: form.momo_enabled,
          paystack_enabled: form.paystack_enabled,
        }
      : form;

    try {
      if (isEdit) {
        await api.put(`/admin/routers/${router.id}`, payload);
      } else {
        await api.post('/admin/routers', payload);
      }
      onSaved();
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors || {});
      } else {
        setServerError(err.response?.data?.message || 'Something went wrong. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  };

  const fieldError = (key) => errors[key]?.[0];

  return (
    <Modal title={isEdit ? 'Edit Router' : 'Add Router'} onClose={onClose} wide>
      {serverError && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
          {serverError}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-5">
        <div>
          <p className="text-sm font-semibold text-slate-700 mb-2">Customer payment methods</p>
          <div className="grid grid-cols-2 gap-2">
            <label className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
              <input type="checkbox" checked={form.momo_enabled} onChange={(e) => handleChange('momo_enabled', e.target.checked)} />
              Mobile Money
            </label>
            <label className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
              <input type="checkbox" checked={form.paystack_enabled} onChange={(e) => handleChange('paystack_enabled', e.target.checked)} />
              Paystack
            </label>
          </div>
          {!form.momo_enabled && !form.paystack_enabled && (
            <p className="mt-2 text-xs text-amber-700">Customers will not be able to buy packages for this router.</p>
          )}
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">Connection Mode</label>
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              onClick={() => handleChange('connection_mode', 'live')}
              className={`rounded-md border px-3 py-2 text-sm font-medium text-left ${
                !isManual ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'
              }`}
            >
              Live (VPS)
              <span className="block text-xs font-normal mt-0.5 opacity-80">
                Reachable over WireGuard — full automation
              </span>
            </button>
            <button
              type="button"
              onClick={() => handleChange('connection_mode', 'manual')}
              className={`rounded-md border px-3 py-2 text-sm font-medium text-left ${
                isManual ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'
              }`}
            >
              Manual
              <span className="block text-xs font-normal mt-0.5 opacity-80">
                No live connection — use PDF-imported vouchers
              </span>
            </button>
          </div>
          {isManual && (
            <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mt-2">
              Card/Paystack payments won't be offered for packages tied to this router, since there's no way to
              auto-create a hotspot login without a live connection. Use PDF-imported MoMo vouchers instead.
            </p>
          )}
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Name" error={fieldError('name')}>
            <input
              required
              className="input"
              value={form.name}
              onChange={(e) => handleChange('name', e.target.value)}
            />
          </Field>

          <Field label="Location" error={fieldError('location')}>
            <input
              className="input"
              value={form.location}
              onChange={(e) => handleChange('location', e.target.value)}
            />
          </Field>

          <Field label="RouterOS Version" error={fieldError('routeros_version')}>
            <input
              className="input"
              placeholder="e.g. 7.18.2"
              value={form.routeros_version}
              onChange={(e) => handleChange('routeros_version', e.target.value.trim())}
            />
          </Field>
        </div>

        <div className="border-t border-slate-100 pt-5">
          <div className="flex items-start justify-between gap-4 mb-3">
            <div>
              <p className="text-sm font-semibold text-slate-700">ISP uplinks</p>
              <p className="text-xs text-slate-400 mt-1">
                Add every WAN connected to this MikroTik. Capacity and subscriber limits are monthly configuration values.
              </p>
            </div>
            <button
              type="button"
              onClick={addIsp}
              className="shrink-0 rounded-md border border-indigo-200 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50"
            >
              + Add ISP
            </button>
          </div>

          {form.isps.length === 0 && (
            <p className="rounded-md bg-slate-50 px-3 py-3 text-xs text-slate-500">
              No ISP configured. Normal mode can still operate, but ISP capacity modes cannot be enabled.
            </p>
          )}

          <div className="space-y-4">
            {form.isps.map((isp, index) => (
              <div key={isp.id || `new-${index}`} className="rounded-lg border border-slate-200 bg-slate-50/50 p-4">
                <div className="mb-3 flex items-center justify-between">
                  <p className="text-sm font-medium text-slate-700">ISP {index + 1}</p>
                  <div className="flex items-center gap-3">
                    <label className="flex items-center gap-2 text-xs text-slate-600">
                      <input
                        type="checkbox"
                        checked={isp.enabled}
                        onChange={(e) => handleIspChange(index, 'enabled', e.target.checked)}
                      />
                      Enabled
                    </label>
                    <button type="button" onClick={() => removeIsp(index)} className="text-xs text-red-600 hover:text-red-700">
                      Remove
                    </button>
                  </div>
                </div>
                <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  <Field label="ISP name" error={fieldError(`isps.${index}.name`)}>
                    <input required className="input" placeholder="e.g. Telecel" value={isp.name} onChange={(e) => handleIspChange(index, 'name', e.target.value)} />
                  </Field>
                  <Field label="WAN interface" error={fieldError(`isps.${index}.wan_interface`)}>
                    <input required className="input" placeholder="e.g. ether1" value={isp.wan_interface} onChange={(e) => handleIspChange(index, 'wan_interface', e.target.value.trim())} />
                  </Field>
                  <Field label="Gateway IP" error={fieldError(`isps.${index}.gateway`)}>
                    <input required className="input" placeholder="e.g. 192.168.1.1" value={isp.gateway} onChange={(e) => handleIspChange(index, 'gateway', e.target.value.trim())} />
                  </Field>
                  <Field label="Routing table" error={fieldError(`isps.${index}.routing_table`)}>
                    <input required className="input" placeholder="e.g. to-telecel" value={isp.routing_table} onChange={(e) => handleIspChange(index, 'routing_table', e.target.value.trim())} />
                  </Field>
                  <Field label="Connection mark" error={fieldError(`isps.${index}.connection_mark`)}>
                    <input className="input" placeholder="e.g. royal-mtn" value={isp.connection_mark || ''} onChange={(e) => handleIspChange(index, 'connection_mark', e.target.value.trim())} />
                  </Field>
                  <Field label="Monthly capacity (GB)" error={fieldError(`isps.${index}.monthly_capacity_gb`)}>
                    <input type="number" min="0.001" step="0.001" className="input" placeholder="Unlimited if blank" value={isp.monthly_capacity_gb} onChange={(e) => handleIspChange(index, 'monthly_capacity_gb', e.target.value)} />
                  </Field>
                  <Field label="Subscriber limit" error={fieldError(`isps.${index}.subscriber_limit`)}>
                    <input type="number" min="1" step="1" className="input" placeholder="Unlimited if blank" value={isp.subscriber_limit} onChange={(e) => handleIspChange(index, 'subscriber_limit', e.target.value)} />
                  </Field>
                  <Field label="Priority" error={fieldError(`isps.${index}.priority`)}>
                    <input type="number" min="1" step="1" required className="input" value={isp.priority} onChange={(e) => handleIspChange(index, 'priority', Number(e.target.value))} />
                  </Field>
                </div>
                <div className="mt-4 rounded-md border border-slate-200 bg-white p-3">
                  <label className="flex items-start gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      className="mt-0.5"
                      checked={Boolean(isp.session_monitoring_enabled)}
                      onChange={(e) => handleIspChange(index, 'session_monitoring_enabled', e.target.checked)}
                    />
                    <span>
                      Monitor estimated WAN concurrent sessions
                      <span className="block text-xs text-slate-400">Counts TCP and UDP entries carrying this ISP's RouterOS connection mark.</span>
                    </span>
                  </label>
                  {isp.session_monitoring_enabled && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                      <Field label="Soft warning" error={fieldError(`isps.${index}.session_soft_limit`)}>
                        <input type="number" min="1" className="input" value={isp.session_soft_limit ?? ''} onChange={(e) => handleIspChange(index, 'session_soft_limit', e.target.value)} />
                      </Field>
                      <Field label="Hard limit" error={fieldError(`isps.${index}.session_hard_limit`)}>
                        <input type="number" min="2" className="input" value={isp.session_hard_limit ?? ''} onChange={(e) => handleIspChange(index, 'session_hard_limit', e.target.value)} />
                      </Field>
                      <Field label="Emergency limit" error={fieldError(`isps.${index}.session_emergency_limit`)}>
                        <input type="number" min="3" className="input" value={isp.session_emergency_limit ?? ''} onChange={(e) => handleIspChange(index, 'session_emergency_limit', e.target.value)} />
                      </Field>
                    </div>
                  )}
                  <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    {[
                      ['session_protection_enabled', 'Protect new sessions'],
                      ['stale_cleanup_enabled', 'Safe stale cleanup'],
                      ['emergency_cleanup_enabled', 'Emergency cleanup'],
                    ].map(([key, label]) => (
                      <label key={key} className="flex items-center gap-2 text-xs text-slate-400" title="Locked until this connection mark is validated on the physical router">
                        <input type="checkbox" disabled checked={Boolean(isp[key])} readOnly />
                        {label} (validation required)
                      </label>
                    ))}
                  </div>
                </div>
              </div>
            ))}
          </div>

          {form.isps.length > 1 && (
            <div className="mt-4 rounded-lg border border-slate-200 p-4">
              <p className="text-sm font-semibold text-slate-700">ISP failure policy</p>
              <p className="mt-1 text-xs text-slate-400">
                Lower ISP priority numbers are preferred. Failover uses the next enabled, healthy ISP with available capacity.
              </p>
              <div className="mt-3 grid gap-2 sm:grid-cols-2">
                <label className="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={form.isp_failover_enabled}
                    onChange={(e) => handleChange('isp_failover_enabled', e.target.checked)}
                  />
                  <span>
                    Automatic failover
                    <span className="block text-xs text-slate-400">Move affected users when their assigned ISP is unavailable.</span>
                  </span>
                </label>
                <label className={`flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm ${form.isp_failover_enabled ? 'text-slate-700' : 'text-slate-400'}`}>
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    disabled={!form.isp_failover_enabled}
                    checked={form.isp_failback_enabled}
                    onChange={(e) => handleChange('isp_failback_enabled', e.target.checked)}
                  />
                  <span>
                    Return on recovery
                    <span className="block text-xs text-slate-400">Move users back to their preferred ISP after it becomes healthy.</span>
                  </span>
                </label>
              </div>
            </div>
          )}
        </div>

        {!isManual && (
          <div className="grid sm:grid-cols-2 gap-4 border-t border-slate-100 pt-5">
            <Field label="Router IP (LAN, optional)" error={fieldError('router_ip')}>
              <input
                className="input"
                placeholder="192.168.88.1"
                value={form.router_ip}
                onChange={(e) => handleChange('router_ip', e.target.value)}
              />
            </Field>

            <Field label="WireGuard IP" error={fieldError('wireguard_ip')}>
              <input
                required
                className="input"
                placeholder="10.10.0.2"
                value={form.wireguard_ip}
                onChange={(e) => handleChange('wireguard_ip', e.target.value)}
              />
            </Field>

            <Field label="API Username" error={fieldError('api_username')}>
              <input
                required
                className="input"
                value={form.api_username}
                onChange={(e) => handleChange('api_username', e.target.value)}
              />
            </Field>

            <Field
              label={isEdit ? 'API Password (leave blank to keep current)' : 'API Password'}
              error={fieldError('api_password')}
            >
              <input
                type="password"
                required={!isEdit}
                className="input"
                value={form.api_password}
                onChange={(e) => handleChange('api_password', e.target.value)}
              />
            </Field>

            <Field label="API Port" error={fieldError('api_port')}>
              <input
                type="number"
                className="input"
                value={form.api_port}
                onChange={(e) => handleChange('api_port', Number(e.target.value))}
              />
            </Field>

            <label className="flex items-center gap-2 mt-6 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={form.api_ssl}
                onChange={(e) => handleChange('api_ssl', e.target.checked)}
              />
              Use API-SSL (port 8729) — recommended
            </label>

            <Field label="Address Pool (optional)" error={fieldError('address_pool')}>
              <input
                className="input"
                placeholder="e.g. hs-pool-1"
                value={form.address_pool}
                onChange={(e) => handleChange('address_pool', e.target.value)}
              />
              <p className="text-xs text-slate-400 mt-1">
                Must already exist on the router (/ip pool). Applied to every hotspot user profile created for
                packages mapped to this router.
              </p>
            </Field>

            <Field label="Hotspot Login Host" error={fieldError('hotspot_login_host')}>
              <input
                required
                className="input"
                placeholder="10.5.50.1 or hotspot.example.com"
                value={form.hotspot_login_host}
                onChange={(e) => handleChange('hotspot_login_host', e.target.value.trim())}
              />
              <p className="text-xs text-slate-400 mt-1">
                Hostname or IP from RouterOS's login URL. This prevents sending credentials to another server.
              </p>
            </Field>
          </div>
        )}

        {!isManual && (
          <div className="border-t border-slate-100 pt-5">
            <p className="text-sm font-semibold text-slate-700 mb-1">Guest Portal Provisioning (optional)</p>
            <p className="text-xs text-slate-400 mb-4">
              A second, separate RouterOS API user with /tool fetch and file-write permissions — deliberately
              different from the credential above, which stays restricted to hotspot user management only.
              Only needed if you'll use "Set Up Guest Portal". See docs/PRODUCTION_SECURITY.md for how to
              create this user.
            </p>
            <div className="grid sm:grid-cols-2 gap-4">
              <Field label="Provisioning API Username" error={fieldError('provisioning_api_username')}>
                <input
                  className="input"
                  value={form.provisioning_api_username}
                  onChange={(e) => handleChange('provisioning_api_username', e.target.value)}
                />
              </Field>

              <Field
                label={isEdit ? 'Provisioning API Password (leave blank to keep current)' : 'Provisioning API Password'}
                error={fieldError('provisioning_api_password')}
              >
                <input
                  type="password"
                  className="input"
                  value={form.provisioning_api_password}
                  onChange={(e) => handleChange('provisioning_api_password', e.target.value)}
                />
              </Field>
            </div>
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Router'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function Field({ label, error, children }) {
  return (
    <div>
      <label className="block text-sm font-medium text-slate-700 mb-1">{label}</label>
      {children}
      {error && <p className="text-xs text-red-600 mt-1">{error}</p>}
    </div>
  );
}
