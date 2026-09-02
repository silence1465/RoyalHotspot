import { useEffect, useState } from 'react';
import { Wifi, Copy, Check } from 'lucide-react';
import guestApi from '../../services/guestApi';
import { copyText } from '../../utils/copyText';

const STORAGE_KEY = 'guest_last_purchase';

/**
 * A guest never has a real session — no account means nothing for the
 * app to remember them by across visits. This page is the substitute:
 * check this specific browser's local storage first (same-device
 * convenience, e.g. re-opening the WiFi page an hour later), and fall
 * back to "prove who you are by re-entering the number you paid with"
 * otherwise. Local storage is fragile by design (different browser,
 * cleared data, new phone all lose it) so the phone lookup has to stay
 * fully capable on its own, not just a fallback in name.
 */
export default function GuestRecover() {
  const [phone, setPhone] = useState('');
  const [purchases, setPurchases] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [checkedLocal, setCheckedLocal] = useState(false);
  const [copiedRef, setCopiedRef] = useState(null);

  useEffect(() => {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
      try {
        const { phone: savedPhone } = JSON.parse(saved);
        if (savedPhone) {
          setPhone(savedPhone);
          runLookup(savedPhone);
        }
      } catch {
        // corrupt local storage value — ignore, fall through to manual entry
      }
    }
    setCheckedLocal(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const runLookup = (phoneValue) => {
    setLoading(true);
    setError('');
    guestApi
      .get('/guest/lookup', { params: { phone: phoneValue } })
      .then(({ data }) => setPurchases(data.purchases || []))
      .catch(() => setError('Could not look up your codes right now. Please try again.'))
      .finally(() => setLoading(false));
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!phone.trim()) return;
    runLookup(phone.trim());
  };

  const handleCopy = async (code, reference) => {
    await copyText(code);
    setCopiedRef(reference);
    setTimeout(() => setCopiedRef(null), 2000);
  };

  const handleActivate = (code) => {
    const loginUrl = sessionStorage.getItem('guest_login_url');

    if (!loginUrl) {
      // No captured router redirect on this visit — most likely they
      // came here directly rather than through the MikroTik link this
      // time. Copy is the safe, always-correct fallback.
      handleCopy(code);
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = loginUrl;
    ['username', 'password'].forEach((name) => {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      field.value = code;
      form.appendChild(field);
    });
    document.body.appendChild(form);
    form.submit();
  };

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="max-w-md mx-auto">
        <div className="text-center mb-6">
          <Wifi className="h-8 w-8 text-indigo-600 mx-auto mb-2" />
          <h1 className="text-xl font-semibold text-slate-900">Find Your Code</h1>
          <p className="text-slate-500 text-sm mt-1">
            Enter the Mobile Money number you paid with.
          </p>
        </div>

        {checkedLocal && (
          <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm mb-5">
            <div className="flex gap-2">
              <input
                type="tel"
                className="input flex-1"
                placeholder="024 XXX XXXX"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
              />
              <button
                type="submit"
                disabled={loading}
                className="bg-indigo-600 text-white rounded-md px-4 text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
              >
                {loading ? '...' : 'Find'}
              </button>
            </div>
          </form>
        )}

        {error && <p className="text-sm text-red-600 text-center mb-4">{error}</p>}

        {purchases && (
          <div className="space-y-3">
            {purchases.length === 0 && (
              <p className="text-center text-slate-400 text-sm py-8">No codes found for that number.</p>
            )}
            {purchases.map((p) => (
              <div key={p.reference} className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <div className="flex items-center justify-between mb-2">
                  <p className="text-sm font-medium text-slate-700">{p.package}</p>
                  <span
                    className={`text-xs font-medium px-2 py-0.5 rounded-full ${
                      p.is_valid ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                    }`}
                  >
                    {p.is_valid ? 'Valid' : 'Expired'}
                  </span>
                </div>
                <p className="text-xl font-mono font-bold tracking-widest text-slate-900 mb-3">{p.code}</p>
                {p.is_valid ? (
                  <div className="flex gap-2">
                    <button
                      onClick={() => handleActivate(p.code)}
                      className="flex-1 bg-indigo-600 text-white rounded-md py-2 text-xs font-medium hover:bg-indigo-700"
                    >
                      Connect to WiFi
                    </button>
                    <button
                      onClick={() => handleCopy(p.code, p.reference)}
                      className="px-3 text-slate-500"
                    >
                      {copiedRef === p.reference ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    </button>
                  </div>
                ) : (
                  <p className="text-xs text-slate-400">This code has expired.</p>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
