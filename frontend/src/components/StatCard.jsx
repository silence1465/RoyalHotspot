export default function StatCard({ label, value, hint, tone = 'default' }) {
  const toneStyles = {
    default: 'text-slate-900',
    positive: 'text-emerald-600',
    warning: 'text-amber-600',
    negative: 'text-red-600',
  };

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
      <p className="text-sm text-slate-500">{label}</p>
      <p className={`mt-2 text-2xl font-semibold ${toneStyles[tone]}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}
