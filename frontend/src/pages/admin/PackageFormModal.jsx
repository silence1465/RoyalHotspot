import { useEffect, useState } from 'react';
import { Trash2, Plus } from 'lucide-react';
import Modal from '../../components/Modal';
import api from '../../services/api';

const emptyForm = {
  name: '',
  description: '',
  price: '',
  duration_value: 1,
  duration_unit: 'days',
  momo_bonus_value: 0,
  momo_bonus_unit: 'days',
  speed_limit: '',
  data_limit: '',
  usage_policy: 'none',
  fup_period: 'cycle',
  data_allowance_gb: '',
  tier1_threshold_percent: 60,
  tier2_threshold_percent: 85,
  tier1_speed_percent: 100,
  tier2_speed_percent: 70,
  tier3_speed_percent: 30,
  status: 'active',
  available_to_guests: false,
};

export default function PackageFormModal({ pkg, onClose, onSaved }) {
  const isEdit = Boolean(pkg);
  const [form, setForm] = useState(
    isEdit
      ? {
          name: pkg.name,
          description: pkg.description || '',
          price: pkg.price,
          duration_value: pkg.duration_value,
          duration_unit: pkg.duration_unit,
          momo_bonus_value: pkg.momo_bonus_value || 0,
          momo_bonus_unit: pkg.momo_bonus_unit || 'days',
          speed_limit: pkg.speed_limit || '',
          data_limit: pkg.data_limit || '',
          usage_policy: pkg.usage_policy || 'none',
          fup_period: pkg.fup_period || 'cycle',
          data_allowance_gb: pkg.data_allowance_bytes ? Number(pkg.data_allowance_bytes) / 1073741824 : '',
          tier1_threshold_percent: pkg.tier1_threshold_percent ?? 60,
          tier2_threshold_percent: pkg.tier2_threshold_percent ?? 85,
          tier1_speed_percent: pkg.tier1_speed_percent ?? 100,
          tier2_speed_percent: pkg.tier2_speed_percent ?? 70,
          tier3_speed_percent: pkg.tier3_speed_percent ?? 30,
          status: pkg.status,
          available_to_guests: Boolean(pkg.available_to_guests),
        }
      : emptyForm
  );

  // Per-router profile mappings — see router_package_profiles (Phase 2/6).
  const [profiles, setProfiles] = useState(
    isEdit
      ? (pkg.router_profiles || []).map((p) => ({ router_id: p.router_id, profile_name: p.profile_name, shared_users: p.shared_users || 1 }))
      : []
  );
  const [routers, setRouters] = useState([]);
  const [errors, setErrors] = useState({});
  const [serverError, setServerError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .get('/admin/routers', { params: { per_page: 100 } })
      .then(({ data }) => setRouters(data.data || data))
      .catch(() => {});
  }, []);

  const handleChange = (key, value) => setForm((f) => ({ ...f, [key]: value }));

  const addProfileRow = () => setProfiles((p) => [...p, { router_id: '', profile_name: '', shared_users: 1 }]);
  const updateProfileRow = (idx, key, value) =>
    setProfiles((p) => p.map((row, i) => (i === idx ? { ...row, [key]: value } : row)));
  const removeProfileRow = (idx) => setProfiles((p) => p.filter((_, i) => i !== idx));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setServerError('');

    const { data_allowance_gb, ...formValues } = form;
    const payload = {
      ...formValues,
      data_allowance_bytes: form.usage_policy === 'none' || data_allowance_gb === ''
        ? null
        : Math.round(Number(data_allowance_gb) * 1073741824),
      profiles: profiles.filter((p) => p.router_id && p.profile_name),
    };

    try {
      if (isEdit) {
        await api.put(`/admin/packages/${pkg.id}`, payload);
      } else {
        await api.post('/admin/packages', payload);
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
  const usedRouterIds = (excludeIdx) =>
    profiles.filter((_, i) => i !== excludeIdx).map((p) => String(p.router_id));

  return (
    <Modal title={isEdit ? 'Edit Package' : 'Add Package'} onClose={onClose} wide>
      {serverError && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
          {serverError}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-5">
        <div className="border border-slate-200 rounded-md p-3">
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              type="checkbox"
              checked={form.available_to_guests}
              onChange={(e) => handleChange('available_to_guests', e.target.checked)}
            />
            Available to Guests
          </label>
          <p className="text-xs text-slate-400 mt-1">
            Lets someone without an account buy this package directly from the MikroTik login page. Guest
            purchases are Mobile Money only and require a live (not Manual) router.
          </p>
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Name" error={fieldError('name')}>
            <input required className="input" value={form.name} onChange={(e) => handleChange('name', e.target.value)} />
          </Field>

          <Field label="Price (GHS)" error={fieldError('price')}>
            <input
              type="number"
              step="0.01"
              min="0"
              required
              className="input"
              value={form.price}
              onChange={(e) => handleChange('price', e.target.value)}
            />
          </Field>

          <Field label="Duration Value" error={fieldError('duration_value')}>
            <input
              type="number"
              min="1"
              required
              className="input"
              value={form.duration_value}
              onChange={(e) => handleChange('duration_value', Number(e.target.value))}
            />
          </Field>

          <Field label="Duration Unit" error={fieldError('duration_unit')}>
            <select
              className="input"
              value={form.duration_unit}
              onChange={(e) => handleChange('duration_unit', e.target.value)}
            >
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
              <option value="days">Days</option>
              <option value="weeks">Weeks</option>
              <option value="months">Months</option>
            </select>
          </Field>

          <Field label="MoMo Bonus Duration" error={fieldError('momo_bonus_value') || fieldError('momo_bonus_unit')}>
            <div className="flex gap-2">
              <input
                type="number"
                min="0"
                max="87600"
                className="input"
                value={form.momo_bonus_value}
                onChange={(e) => handleChange('momo_bonus_value', Number(e.target.value))}
              />
              <select className="input max-w-32" value={form.momo_bonus_unit} onChange={(e) => handleChange('momo_bonus_unit', e.target.value)}>
                <option value="hours">Hours</option>
                <option value="days">Days</option>
              </select>
            </div>
            <p className="text-xs text-slate-400 mt-1">Extra time added only for direct Mobile Money payments.</p>
          </Field>

          <Field label="Speed Limit (RouterOS format, e.g. 5M/5M)" error={fieldError('speed_limit')}>
            <input
              className="input"
              placeholder="5M/5M"
              value={form.speed_limit}
              onChange={(e) => handleChange('speed_limit', e.target.value)}
            />
          </Field>

          <Field label="Data Limit (optional, e.g. 2G)" error={fieldError('data_limit')}>
            <input
              className="input"
              placeholder="Leave blank for unlimited"
              value={form.data_limit}
              onChange={(e) => handleChange('data_limit', e.target.value)}
            />
          </Field>

          <Field label="Status" error={fieldError('status')}>
            <select className="input" value={form.status} onChange={(e) => handleChange('status', e.target.value)}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </Field>
        </div>

        <div className="border border-slate-200 rounded-md p-4 space-y-4">
          <div>
            <h3 className="text-sm font-semibold text-slate-800">Usage policy</h3>
            <p className="text-xs text-slate-400 mt-1">Upload and download traffic both count toward the allowance.</p>
          </div>
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label="Policy type" error={fieldError('usage_policy')}>
              <select className="input" value={form.usage_policy} onChange={(e) => handleChange('usage_policy', e.target.value)}>
                <option value="none">No usage policy</option>
                <option value="fup">Three-tier FUP (service continues)</option>
                <option value="data_cap">Hard data cap (service stops)</option>
              </select>
            </Field>
            {form.usage_policy !== 'none' && (
              <Field label="Data allowance (GB)" error={fieldError('data_allowance_bytes')}>
                <input type="number" min="0.001" step="0.001" required className="input" value={form.data_allowance_gb} onChange={(e) => handleChange('data_allowance_gb', e.target.value)} />
              </Field>
            )}
            {form.usage_policy === 'fup' && (
              <Field label="FUP reset period" error={fieldError('fup_period')}>
                <select className="input" value={form.fup_period} onChange={(e) => handleChange('fup_period', e.target.value)}>
                  <option value="daily">Daily</option>
                  <option value="cycle">Subscription cycle</option>
                </select>
              </Field>
            )}
          </div>
          {form.usage_policy === 'fup' && (
            <div className="grid sm:grid-cols-3 gap-3">
              <Field label="Tier 1 ends at %" error={fieldError('tier1_threshold_percent')}>
                <input type="number" min="1" max="98" className="input" value={form.tier1_threshold_percent} onChange={(e) => handleChange('tier1_threshold_percent', Number(e.target.value))} />
              </Field>
              <Field label="Tier 2 ends at %" error={fieldError('tier2_threshold_percent')}>
                <input type="number" min="2" max="99" className="input" value={form.tier2_threshold_percent} onChange={(e) => handleChange('tier2_threshold_percent', Number(e.target.value))} />
              </Field>
              <div />
              <Field label="Tier 1 speed %" error={fieldError('tier1_speed_percent')}>
                <input type="number" min="1" max="100" className="input" value={form.tier1_speed_percent} onChange={(e) => handleChange('tier1_speed_percent', Number(e.target.value))} />
              </Field>
              <Field label="Tier 2 speed %" error={fieldError('tier2_speed_percent')}>
                <input type="number" min="1" max="100" className="input" value={form.tier2_speed_percent} onChange={(e) => handleChange('tier2_speed_percent', Number(e.target.value))} />
              </Field>
              <Field label="Tier 3 speed %" error={fieldError('tier3_speed_percent')}>
                <input type="number" min="1" max="100" className="input" value={form.tier3_speed_percent} onChange={(e) => handleChange('tier3_speed_percent', Number(e.target.value))} />
              </Field>
            </div>
          )}
          {form.usage_policy === 'data_cap' && (
            <p className="text-xs text-amber-700 bg-amber-50 rounded-md px-3 py-2">Access stops when the allowance is consumed or the package expires, whichever happens first. No FUP tiers apply.</p>
          )}
        </div>

        <Field label="Description (optional, shown to customers)" error={fieldError('description')}>
          <textarea
            className="input"
            rows={2}
            value={form.description}
            onChange={(e) => handleChange('description', e.target.value)}
          />
        </Field>

        <div>
          <div className="flex items-center justify-between mb-2">
            <label className="block text-sm font-medium text-slate-700">
              RouterOS Profile Mapping
              <span className="block text-xs font-normal text-slate-400 mt-0.5">
                Each router names its hotspot profiles independently — map this package to the
                actual profile name on each router you want to sell it on.
              </span>
            </label>
            <button
              type="button"
              onClick={addProfileRow}
              className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 whitespace-nowrap"
            >
              <Plus className="h-3.5 w-3.5" />
              Add router
            </button>
          </div>

          {profiles.length === 0 && (
            <p className="text-xs text-slate-400 border border-dashed border-slate-300 rounded-md px-3 py-3 text-center">
              No router mapped yet — activation will fail until at least one is added.
            </p>
          )}

          <div className="space-y-2">
            {profiles.map((row, idx) => (
              <div key={idx} className="flex gap-2 items-start">
                <select
                  className="input"
                  value={row.router_id}
                  onChange={(e) => updateProfileRow(idx, 'router_id', e.target.value)}
                >
                  <option value="">Select router…</option>
                  {routers
                    .filter((r) => !usedRouterIds(idx).includes(String(r.id)))
                    .map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name}
                      </option>
                    ))}
                </select>
                <input
                  className="input"
                  placeholder="RouterOS profile name"
                  value={row.profile_name}
                  onChange={(e) => updateProfileRow(idx, 'profile_name', e.target.value)}
                />
                <input
                  type="number"
                  min="1"
                  max="20"
                  className="input max-w-24"
                  title="Maximum simultaneous devices"
                  value={row.shared_users || 1}
                  onChange={(e) => updateProfileRow(idx, 'shared_users', Number(e.target.value))}
                />
                <button
                  type="button"
                  onClick={() => removeProfileRow(idx)}
                  className="text-slate-400 hover:text-red-600 mt-2 shrink-0"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        </div>

        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Package'}
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
