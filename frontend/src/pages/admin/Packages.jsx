import { useEffect, useState, useCallback } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import SearchFilterInput from '../../components/SearchFilterInput';
import ConfirmDialog from '../../components/ConfirmDialog';
import PackageFormModal from './PackageFormModal';
import TablePagination from '../../components/TablePagination';
import useServerPagination from '../../hooks/useServerPagination';

const currency = (n) =>
  new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const durationLabel = (pkg) =>
  `${pkg.duration_value} ${pkg.duration_value === 1 ? pkg.duration_unit.replace(/s$/, '') : pkg.duration_unit}`;

export default function AdminPackages() {
  const [packages, setPackages] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const pagination = useServerPagination();

  const [formTarget, setFormTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/admin/packages', { params: { ...pagination.requestParams, search: search || undefined } })
      .then(({ data }) => setPackages(pagination.capture(data)))
      .catch(() => setError('Could not load packages. Is the backend running?'))
      .finally(() => setLoading(false));
  }, [search, pagination.page]);

  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/admin/packages/${deleteTarget.id}`);
      setDeleteTarget(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete this package.');
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Internet Packages</h1>
          <p className="text-slate-500 text-sm mt-1">
            Pricing tiers customers can buy. Map each to a RouterOS profile per router.
          </p>
        </div>
        <button
          onClick={() => setFormTarget({})}
          className="flex items-center gap-2 bg-indigo-600 text-white rounded-md px-4 py-2 text-sm font-medium hover:bg-indigo-700 whitespace-nowrap"
        >
          <Plus className="h-4 w-4" />
          Add Package
        </button>
      </div>

      <div className="mb-4">
        <SearchFilterInput value={search} onChange={setSearch} placeholder="Search by name…" />
      </div>

      {error && (
        <div className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">
          {error}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500 border-b border-slate-100">
              <th className="px-5 py-3 font-medium">Name</th>
              <th className="px-5 py-3 font-medium">Price</th>
              <th className="px-5 py-3 font-medium">Duration</th>
              <th className="px-5 py-3 font-medium">Guests</th>
              <th className="px-5 py-3 font-medium">Speed / Data</th>
              <th className="px-5 py-3 font-medium">Routers Mapped</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={8} className="px-5 py-6 text-center text-slate-400">
                  Loading…
                </td>
              </tr>
            )}
            {!loading && packages.length === 0 && (
              <tr>
                <td colSpan={8} className="px-5 py-6 text-center text-slate-400">
                  No packages yet — add one to get started.
                </td>
              </tr>
            )}
            {!loading &&
              packages.map((pkg) => (
                <tr key={pkg.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3 font-medium text-slate-900">{pkg.name}</td>
                  <td className="px-5 py-3 text-slate-600">{currency(pkg.price)}</td>
                  <td className="px-5 py-3 text-slate-600">{durationLabel(pkg)}</td>
                  <td className="px-5 py-3">
                    <span className={`text-xs font-medium ${pkg.available_to_guests ? 'text-emerald-600' : 'text-slate-400'}`}>
                      {pkg.available_to_guests ? 'Yes' : 'No'}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-slate-600">
                    {pkg.speed_limit || '—'} {pkg.data_limit ? `/ ${pkg.data_limit}` : '/ Unlimited'}
                  </td>
                  <td className="px-5 py-3 text-slate-600">
                    {pkg.router_profiles?.length ? (
                      pkg.router_profiles.length
                    ) : (
                      <span className="text-amber-600">None — won't activate</span>
                    )}
                  </td>
                  <td className="px-5 py-3">
                    <StatusBadge status={pkg.status} />
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex items-center justify-end gap-3">
                      <button
                        onClick={() => setFormTarget(pkg)}
                        className="text-slate-500 hover:text-indigo-600"
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setDeleteTarget(pkg)}
                        className="text-slate-500 hover:text-red-600"
                        title="Delete"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
        <TablePagination {...pagination} onPageChange={pagination.setPage} />
      </div>

      {formTarget !== null && (
        <PackageFormModal
          pkg={formTarget.id ? formTarget : null}
          onClose={() => setFormTarget(null)}
          onSaved={() => {
            setFormTarget(null);
            load();
          }}
        />
      )}

      {deleteTarget && (
        <ConfirmDialog
          title="Delete Package"
          message={`Delete "${deleteTarget.name}"? This can't be undone. Packages with purchases, vouchers, subscriptions, orders, or free campaigns must be set to Inactive instead.`}
          confirmLabel="Delete"
          danger
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setDeleteTarget(null)}
        />
      )}
    </div>
  );
}
