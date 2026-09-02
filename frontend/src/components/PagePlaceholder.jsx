export default function PagePlaceholder({ title, phaseNote }) {
  return (
    <div>
      <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
      <p className="text-slate-500 mt-2 text-sm">{phaseNote}</p>
      <div className="mt-6 bg-white rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-400 text-sm">
        Table, filters, and actions for this section will render here.
      </div>
    </div>
  );
}
