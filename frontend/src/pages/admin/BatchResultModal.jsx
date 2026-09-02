import { Download, Copy } from 'lucide-react';
import Modal from '../../components/Modal';
import TablePagination from '../../components/TablePagination';
import useClientPagination from '../../hooks/useClientPagination';

export default function BatchResultModal({ batch, onClose }) {
  const codes = batch.vouchers.map((v) => v.code);
  const pagination = useClientPagination(codes);

  const handleCopy = () => {
    navigator.clipboard.writeText(codes.join('\n'));
  };

  const handleDownload = () => {
    const csv = 'code\n' + codes.join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `vouchers-${batch.batch_id.slice(0, 8)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <Modal title={`${batch.count} Voucher(s) Generated`} onClose={onClose} wide>
      <div className="flex gap-2 mb-3">
        <button
          onClick={handleCopy}
          className="flex items-center gap-1.5 text-sm text-slate-600 border border-slate-300 rounded-md px-3 py-1.5 hover:bg-slate-50"
        >
          <Copy className="h-3.5 w-3.5" />
          Copy all
        </button>
        <button
          onClick={handleDownload}
          className="flex items-center gap-1.5 text-sm text-slate-600 border border-slate-300 rounded-md px-3 py-1.5 hover:bg-slate-50"
        >
          <Download className="h-3.5 w-3.5" />
          Download CSV
        </button>
      </div>

      <div className="max-h-80 overflow-y-auto border border-slate-200 rounded-md">
        <table className="w-full text-sm font-mono">
          <tbody>
            {pagination.rows.map((code) => (
              <tr key={code} className="border-b border-slate-50 last:border-0">
                <td className="px-4 py-2 text-slate-700">{code}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      <div className="flex justify-end mt-4">
        <button onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
          Done
        </button>
      </div>
    </Modal>
  );
}
