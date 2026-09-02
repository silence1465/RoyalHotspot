import { useEffect, useState, useRef, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { Copy, Check, Wifi } from 'lucide-react';
import guestApi from '../../services/guestApi';
import SmsForwarderStatus from '../../components/SmsForwarderStatus';
import CopyButton from '../../components/CopyButton';
import { copyText } from '../../utils/copyText';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

export default function GuestPayment() {
  const { reference } = useParams();
  const [purchase, setPurchase] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState(false);
  const [txId, setTxId] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [verifyMessage, setVerifyMessage] = useState('');
  const [showVerification, setShowVerification] = useState(false);
  const pollRef = useRef(null);

  const load = useCallback(() => {
    guestApi
      .get(`/guest/purchases/${reference}`)
      .then(({ data }) => setPurchase(data))
      .catch(() => setError('Purchase not found.'))
      .finally(() => setLoading(false));
  }, [reference]);

  useEffect(() => {
    load();
    guestApi.post(`/guest/purchases/${reference}/acknowledge-payment`).catch(() => {});
  }, [reference, load]);

  // Poll while unresolved — same cadence reasoning as the customer
  // payment page: frequent enough to feel live, not so frequent it
  // hammers the backend.
  useEffect(() => {
    if (!purchase || ['completed', 'active', 'failed', 'expired', 'cancelled'].includes(purchase.status)) {
      return;
    }

    pollRef.current = setInterval(() => {
      guestApi.get(`/guest/purchases/${reference}/status`).then(({ data }) => {
        if (data.status !== purchase.status) {
          load();
        }
      });
    }, 4000);

    return () => clearInterval(pollRef.current);
  }, [purchase, reference, load]);

  const handleVerify = async () => {
    setVerifying(true);
    setVerifyMessage('');
    try {
      const { data } = await guestApi.post(`/guest/purchases/${reference}/verify`, {
        transaction_id: txId.trim(),
      });
      setVerifyMessage(data.message);
      if (data.success) load();
    } catch (err) {
      setVerifyMessage(err.response?.data?.message || 'Could not verify payment right now.');
    } finally {
      setVerifying(false);
    }
  };

  const handleCopy = async () => {
    await copyText(purchase.code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  /**
   * The literal auto-login — submits the guest's code straight to the
   * router's own login endpoint, from their browser, while they're
   * still connected to that hotspot's WiFi. This can't be done from our
   * backend; RouterOS ties hotspot auth to which device on its own LAN
   * sent the request. loginUrl was captured on the GuestBuy landing
   * page from RouterOS's own $(link-login-only) template variable.
   */
  const handleActivate = () => {
    const loginUrl = sessionStorage.getItem('guest_login_url');

    if (!loginUrl) {
      // No captured redirect URL — most likely they navigated here
      // directly (e.g. from the recovery page on a different visit)
      // rather than through the MikroTik-provided link. Copy is the
      // safe fallback.
      handleCopy();
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = loginUrl;

    const userField = document.createElement('input');
    userField.type = 'hidden';
    userField.name = 'username';
    userField.value = purchase.code;

    const passField = document.createElement('input');
    passField.type = 'hidden';
    passField.name = 'password';
    passField.value = purchase.code;

    form.appendChild(userField);
    form.appendChild(passField);
    document.body.appendChild(form);
    form.submit();
  };

  if (loading) return <CenteredMessage>Loading…</CenteredMessage>;
  if (error) return <CenteredMessage tone="error">{error}</CenteredMessage>;

  const hasCode = purchase.has_code || purchase.code;
  const isTerminal = ['failed', 'expired', 'cancelled'].includes(purchase.status);

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="max-w-md mx-auto">
        {hasCode ? (
          <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm text-center">
            <Wifi className="h-8 w-8 text-emerald-500 mx-auto mb-3" />
            <h1 className="text-lg font-semibold text-slate-900 mb-1">You're connected!</h1>
            <p className="text-slate-500 text-sm mb-5">Your access code:</p>

            <div className="bg-slate-50 border border-slate-200 rounded-lg py-4 mb-4">
              <p className="text-3xl font-mono font-bold tracking-widest text-slate-900">{purchase.code}</p>
            </div>

            <button
              onClick={handleActivate}
              className="w-full bg-indigo-600 text-white rounded-md py-2.5 text-sm font-medium hover:bg-indigo-700 mb-2"
            >
              Connect to WiFi
            </button>
            <button
              onClick={handleCopy}
              className="w-full flex items-center justify-center gap-1.5 text-sm text-slate-600 py-2"
            >
              {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
              {copied ? 'Copied' : 'Copy code'}
            </button>

            <p className="text-xs text-slate-400 mt-4">
              Save this code — you'll need your Mobile Money number to find it again if you lose it.
            </p>
          </div>
        ) : isTerminal ? (
          <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm text-center">
            <p className="text-slate-700 font-medium mb-1">
              {purchase.status === 'expired' ? 'This payment window closed.' : 'This purchase could not be completed.'}
            </p>
            <p className="text-slate-400 text-sm">Please start a new purchase from the link on the WiFi page.</p>
          </div>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
            <div className="text-center mb-5">
              <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600 mb-1">Mobile Money Payment</p>
              <h1 className="text-xl font-bold text-slate-900">Complete Your Payment</h1>
              <p className="text-slate-500 text-sm mt-1">{purchase.package.name}</p>
            </div>

            <div className="rounded-xl border-2 border-indigo-300 bg-indigo-50 p-4 mb-4 shadow-sm">
              <div className="text-center border-b border-indigo-200 pb-4 mb-4">
                <p className="text-xs font-semibold uppercase tracking-wide text-indigo-700">Send exactly</p>
                <p className="text-3xl font-extrabold text-indigo-950 mt-1">{currency(purchase.amount)}</p>
                <p className="text-xs text-indigo-700 mt-1">No checkout charge</p>
              </div>

              <div className="space-y-4">
                <div>
                  <p className="text-xs font-semibold text-indigo-700 mb-1">1. Send to this MoMo number</p>
                  <div className="flex items-center justify-between gap-2 rounded-lg bg-white border border-indigo-200 px-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-xl font-bold tracking-wide text-slate-950">{purchase.momo_number}</p>
                      {purchase.momo_account_name && (
                        <p className="text-xs font-medium text-slate-500 mt-0.5">{purchase.momo_account_name}</p>
                      )}
                    </div>
                    <CopyButton text={purchase.momo_number} label="Copy" />
                  </div>
                </div>

                <div>
                  <p className="text-xs font-semibold text-indigo-700 mb-1">2. Use this payment reference</p>
                  <div className="flex items-center justify-between gap-2 rounded-lg bg-white border border-amber-300 px-3 py-2.5">
                    <p className="font-mono text-lg font-extrabold tracking-wide text-slate-950 break-all">{purchase.reference}</p>
                    <CopyButton text={purchase.reference} label="Copy" />
                  </div>
                  <p className="text-xs font-semibold text-amber-700 mt-2">
                    Enter this reference in the payment note or reason field.
                  </p>
                </div>
              </div>
            </div>

            <div className="flex items-center justify-center gap-2 text-xs font-medium text-slate-500 mb-5">
              <span className="h-2 w-2 rounded-full bg-amber-400 animate-pulse" />
              Waiting for payment confirmation
            </div>

            <div className="border-t border-slate-100 pt-4">
              <button
                type="button"
                onClick={() => setShowVerification((visible) => !visible)}
                aria-expanded={showVerification}
                className="block mx-auto text-sm font-semibold text-indigo-600 hover:text-indigo-800 hover:underline"
              >
                {showVerification ? 'Hide payment verification' : 'Have you already paid? Click here.'}
              </button>

              {showVerification && (
                <div className="mt-4 rounded-lg bg-slate-50 border border-slate-200 p-3">
                  <p className="text-xs text-slate-600 mb-2">
                    Enter the Transaction ID from your MoMo confirmation message.
                  </p>
                  <SmsForwarderStatus />
                  <div className="flex gap-2">
                    <input
                      className="input flex-1 min-w-0"
                      placeholder="e.g. 87425747421"
                      value={txId}
                      onChange={(e) => setTxId(e.target.value)}
                    />
                    <button
                      onClick={handleVerify}
                      disabled={verifying || !txId.trim()}
                      className="bg-slate-800 text-white rounded-md px-4 text-sm font-medium disabled:opacity-50"
                    >
                      {verifying ? '...' : 'Verify'}
                    </button>
                  </div>
                  {verifyMessage && <p className="text-xs text-slate-500 mt-2">{verifyMessage}</p>}
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function CenteredMessage({ children, tone }) {
  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
      <p className={`text-sm text-center ${tone === 'error' ? 'text-red-600' : 'text-slate-400'}`}>{children}</p>
    </div>
  );
}
