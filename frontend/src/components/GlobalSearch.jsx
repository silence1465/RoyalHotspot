import { useState, useRef, useEffect } from 'react';
import { Search } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';

export default function GlobalSearch() {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState(null);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (query.trim().length < 2) {
      setResults(null);
      return;
    }

    const t = setTimeout(() => {
      api.get('/admin/search', { params: { q: query.trim() } }).then(({ data }) => {
        setResults(data);
        setOpen(true);
      });
    }, 300);

    return () => clearTimeout(t);
  }, [query]);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const hasResults = results && (results.customers.length || results.purchases.length || results.vouchers.length);

  const goTo = (path) => {
    navigate(path);
    setOpen(false);
    setQuery('');
  };

  return (
    <div className="relative hidden lg:block w-72" ref={ref}>
      <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
      <input
        className="w-full bg-slate-50 border border-slate-200 rounded-md pl-9 pr-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200"
        placeholder="Search customers, purchases, vouchers…"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onFocus={() => results && setOpen(true)}
      />

      {open && results && (
        <div className="absolute left-0 mt-1 w-96 bg-white rounded-xl border border-slate-200 shadow-lg z-50 overflow-hidden">
          {!hasResults && <p className="px-4 py-4 text-xs text-slate-400 text-center">No results.</p>}

          {results.customers.length > 0 && (
            <ResultSection title="Customers">
              {results.customers.map((c) => (
                <ResultRow key={c.id} onClick={() => goTo('/admin/customers')}>
                  {c.full_name} <span className="text-slate-400">· {c.phone}</span>
                </ResultRow>
              ))}
            </ResultSection>
          )}

          {results.purchases.length > 0 && (
            <ResultSection title="Purchases">
              {results.purchases.map((p) => (
                <ResultRow key={p.id} onClick={() => goTo('/admin/purchases')}>
                  {p.reference} <span className="text-slate-400">· {p.customer?.full_name || 'Guest'}</span>
                </ResultRow>
              ))}
            </ResultSection>
          )}

          {results.vouchers.length > 0 && (
            <ResultSection title="Vouchers">
              {results.vouchers.map((v) => (
                <ResultRow key={v.id} onClick={() => goTo('/admin/vouchers')}>
                  {v.code} <span className="text-slate-400">· {v.status}</span>
                </ResultRow>
              ))}
            </ResultSection>
          )}
        </div>
      )}
    </div>
  );
}

function ResultSection({ title, children }) {
  return (
    <div className="border-b border-slate-100 last:border-0">
      <p className="px-4 pt-2.5 pb-1 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">{title}</p>
      {children}
    </div>
  );
}

function ResultRow({ children, onClick }) {
  return (
    <button onClick={onClick} className="w-full text-left px-4 py-2 text-xs text-slate-700 hover:bg-slate-50">
      {children}
    </button>
  );
}
