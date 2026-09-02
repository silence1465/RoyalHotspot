const STYLES = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  online: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  successful: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  used: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  verified: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  voucher_assigned: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  assigned: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  matched: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',

  inactive: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  offline: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  unused: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  available: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  unmatched: 'bg-slate-100 text-slate-600 ring-slate-500/20',

  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  pending_activation: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  maintenance: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  processing: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  manual_review: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  reserved: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  queued: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

  suspended: 'bg-red-50 text-red-700 ring-red-600/20',
  expired: 'bg-red-50 text-red-700 ring-red-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  cancelled: 'bg-red-50 text-red-700 ring-red-600/20',
  invalid: 'bg-red-50 text-red-700 ring-red-600/20',
  duplicate: 'bg-red-50 text-red-700 ring-red-600/20',
};

const LABELS = {
  pending_activation: 'Pending Activation',
  voucher_assigned: 'Voucher Assigned',
  manual_review: 'Manual Review',
};

export default function StatusBadge({ status }) {
  if (!status) return null;
  const key = String(status).toLowerCase();
  const style = STYLES[key] || 'bg-slate-100 text-slate-600 ring-slate-500/20';
  const label = LABELS[key] || key.charAt(0).toUpperCase() + key.slice(1);

  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${style}`}
    >
      {label}
    </span>
  );
}
