import { useEffect, useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import Modal from '../../components/Modal';
import api from '../../services/api';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

const STATUS_NOTE = {
  valid: null,
  duplicate_in_batch: 'Repeated within this PDF',
  duplicate_existing: 'Already in the system',
};

export default function ImportPreviewModal({ batch, onClose, onImported, onCancelled }) {
  const [packages, setPackages] = useState([]);
  const [packageOverrides, setPackageOverrides] = useState({});
  const [excludedCodes, setExcludedCodes] = useState(new Set());
  const [expandedSection, setExpandedSection] = useState(null);
  const [error, setError] = useState('');
  const [importing, setImporting] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const meta = batch.extraction_meta;

  useEffect(() => {
    api
      .get('/admin/packages', { params: { per_page: 100, status: 'active' } })
      .then(({ data }) => setPackages(data.data || data))
      .catch(() => {});
  }, []);

  const toggleExclude = (code) => {
    setExcludedCodes((prev) => {
      const next = new Set(prev);
      if (next.has(code)) {
        next.delete(code);
      } else {
        next.add(code);
      }
      return next;
    });
  };

  const handleImport = async () => {
    setImporting(true);
    setError('');
    try {
      const { data } = await api.post(`/admin/vouchers/import/${batch.id}/confirm`, {
        package_overrides: packageOverrides,
        excluded_codes: Array.from(excludedCodes),
      });
      onImported(data.batch);
    } catch (err) {
      setError(err.response?.data?.message || 'Import failed. Please try again.');
    } finally {
      setImporting(false);
    }
  };

  const handleCancel = async () => {
    setCancelling(true);
    try {
      await api.post(`/admin/vouchers/import/${batch.id}/cancel`);
      onCancelled();
    } catch {
      setError('Could not cancel this batch.');
      setCancelling(false);
    }
  };

  const isReadOnly = batch.status !== 'pending_review';

  return (
    <Modal title={`Preview — ${batch.original_filename}`} onClose={onClose} wide>
      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{error}</div>
      )}

      <div className="grid grid-cols-4 gap-3 mb-5 text-center">
        <Stat label="Extracted" value={meta.totals.extracted} />
        <Stat label="Valid" value={meta.totals.valid} tone="text-emerald-600" />
        <Stat label="Duplicate" value={meta.totals.duplicate} tone="text-amber-600" />
        <Stat label="Invalid" value={meta.totals.invalid} tone="text-red-600" />
      </div>

      {isReadOnly && (
        <p className="text-xs text-slate-400 mb-4">
          This batch is already <strong>{batch.status}</strong> — read-only.
        </p>
      )}

      <div className="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
        {meta.sections.map((section, index) => {
          const isExpanded = expandedSection === index;
          const packageId = packageOverrides[index] ?? section.matched_package_id ?? '';
          const validCount = section.codes.filter((c) => c.status === 'valid').length;

          return (
            <div key={index} className="border border-slate-200 rounded-lg overflow-hidden">
              <div className="flex items-center justify-between px-4 py-3 bg-slate-50">
                <div>
                  <p className="text-sm font-medium text-slate-800">{section.raw_header}</p>
                  <p className="text-xs text-slate-400">
                    {section.codes.length} code(s) — {validCount} importable
                  </p>
                </div>
                <button onClick={() => setExpandedSection(isExpanded ? null : index)} className="text-slate-400">
                  {isExpanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                </button>
              </div>

              <div className="px-4 py-3 border-t border-slate-100">
                <label className="block text-xs font-medium text-slate-500 mb-1">Package</label>
                {!section.matched_package_id && !packageOverrides[index] && (
                  <p className="text-xs text-amber-600 mb-1">
                    No package found at GHS {section.amount} — select one manually or these codes won't import.
                  </p>
                )}
                <select
                  disabled={isReadOnly}
                  className="input"
                  value={packageId}
                  onChange={(e) =>
                    setPackageOverrides((prev) => {
                      const next = { ...prev };
                      if (e.target.value) {
                        next[index] = Number(e.target.value);
                      } else {
                        delete next[index]; // omit entirely — sending null would fail backend validation
                      }
                      return next;
                    })
                  }
                >
                  <option value="">No package selected — will be skipped</option>
                  {packages.map((p) => (
                    <option key={p.id} value={p.id}>{p.name} (GHS {p.price})</option>
                  ))}
                </select>
              </div>

              {isExpanded && (
                <SectionCodesTable
                  codes={section.codes}
                  isReadOnly={isReadOnly}
                  excludedCodes={excludedCodes}
                  toggleExclude={toggleExclude}
                />
              )}
            </div>
          );
        })}

        {(meta.unassigned_codes.length > 0 || meta.unparsed_lines.length > 0) && (
          <div className="border border-amber-200 bg-amber-50 rounded-lg p-4">
            <p className="text-sm font-medium text-amber-800 mb-1">Needs review</p>
            <p className="text-xs text-amber-700">
              {meta.unassigned_codes.length} code(s) found before any package heading, and{' '}
              {meta.unparsed_lines.length} line(s) matched neither a code nor a package heading. These won't be
              imported — re-check the PDF formatting if this looks wrong.
            </p>
          </div>
        )}
      </div>

      <div className="flex justify-end gap-3 pt-5 mt-2 border-t border-slate-100">
        <button onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
          Close
        </button>
        {!isReadOnly && (
          <>
            <button
              onClick={handleCancel}
              disabled={cancelling}
              className="px-4 py-2 text-sm text-red-600 border border-red-200 rounded-md hover:bg-red-50 disabled:opacity-50"
            >
              {cancelling ? 'Cancelling…' : 'Cancel Batch'}
            </button>
            <button
              onClick={handleImport}
              disabled={importing}
              className="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
            >
              {importing ? 'Importing…' : 'Import Vouchers'}
            </button>
          </>
        )}
      </div>
    </Modal>
  );
}

function SectionCodesTable({ codes, isReadOnly, excludedCodes, toggleExclude }) {
  const pagination = useClientPagination(codes);

  return (
    <div className="max-h-64 overflow-y-auto border-t border-slate-100">
      <table className="w-full text-xs">
        <tbody>
          {pagination.rows.map((entry) => (
            <tr key={entry.code} className="border-b border-slate-50 last:border-0">
              <td className="px-4 py-1.5">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    disabled={isReadOnly || entry.status !== 'valid'}
                    checked={entry.status === 'valid' && !excludedCodes.has(entry.code)}
                    onChange={() => toggleExclude(entry.code)}
                  />
                  <span className={`font-mono ${entry.status !== 'valid' ? 'text-slate-300 line-through' : 'text-slate-700'}`}>
                    {entry.code}
                  </span>
                </label>
              </td>
              <td className="px-4 py-1.5 text-right text-slate-400">{STATUS_NOTE[entry.status]}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <TablePagination {...pagination} onPageChange={pagination.setPage} />
    </div>
  );
}

function Stat({ label, value, tone = 'text-slate-900' }) {
  return (
    <div className="bg-slate-50 rounded-lg py-2">
      <p className={`text-lg font-semibold ${tone}`}>{value}</p>
      <p className="text-xs text-slate-400">{label}</p>
    </div>
  );
}
