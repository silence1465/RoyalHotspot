import { useState } from 'react';
import Modal from '../../components/Modal';
import api from '../../services/api';

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
        }
      : emptyForm
  );
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [serverError, setServerError] = useState('');

  const isManual = form.connection_mode === 'manual';

  const handleChange = (key, value) => setForm((f) => ({ ...f, [key]: value }));

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
      ? { name: form.name, location: form.location, connection_mode: form.connection_mode, momo_enabled: form.momo_enabled, paystack_enabled: form.paystack_enabled }
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
