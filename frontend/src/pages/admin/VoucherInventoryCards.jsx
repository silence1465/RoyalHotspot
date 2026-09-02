import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import api from '../../services/api';

export default function VoucherInventoryCards() {
  const [inventory, setInventory] = useState(null);

  useEffect(() => {
    api
      .get('/admin/vouchers/inventory')
      .then(({ data }) => setInventory(data))
      .catch(() => {});
  }, []);

  if (!inventory || inventory.packages.length === 0) return null;

  return (
    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
      {inventory.packages.map((pkg) => (
        <div
          key={pkg.package_id}
          className={`rounded-lg border p-4 ${
            pkg.low_stock ? 'bg-amber-50 border-amber-200' : 'bg-white border-slate-200'
          }`}
        >
          <div className="flex items-center justify-between mb-2">
            <p className="font-medium text-slate-900 text-sm">{pkg.package_name}</p>
            {pkg.low_stock && <AlertTriangle className="h-4 w-4 text-amber-500" />}
          </div>
          <div className="grid grid-cols-3 gap-2 text-center">
            <div>
              <p className={`text-lg font-semibold ${pkg.low_stock ? 'text-amber-700' : 'text-slate-900'}`}>
                {pkg.available}
              </p>
              <p className="text-xs text-slate-400">Available</p>
            </div>
            <div>
              <p className="text-lg font-semibold text-slate-900">{pkg.assigned}</p>
              <p className="text-xs text-slate-400">Assigned</p>
            </div>
            <div>
              <p className="text-lg font-semibold text-slate-900">{pkg.used}</p>
              <p className="text-xs text-slate-400">Used</p>
            </div>
          </div>
          {pkg.low_stock && (
            <p className="text-xs text-amber-700 mt-2">
              At or below the low-stock threshold ({inventory.threshold}) — import more vouchers soon.
            </p>
          )}
        </div>
      ))}
    </div>
  );
}
