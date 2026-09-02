import { ChevronLeft, ChevronRight } from 'lucide-react';

export default function TablePagination({ page, lastPage, total, onPageChange }) {
  if (!total || lastPage <= 1) return null;

  const first = (page - 1) * 10 + 1;
  const last = Math.min(page * 10, total);

  return (
    <div className="flex items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
      <span>Showing {first}-{last} of {total}</span>
      <div className="flex items-center gap-2">
        <button
          type="button"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
          className="rounded-md border border-slate-200 p-1.5 hover:bg-slate-50 disabled:opacity-40"
          aria-label="Previous page"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>
        <span>Page {page} of {lastPage}</span>
        <button
          type="button"
          disabled={page >= lastPage}
          onClick={() => onPageChange(page + 1)}
          className="rounded-md border border-slate-200 p-1.5 hover:bg-slate-50 disabled:opacity-40"
          aria-label="Next page"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
