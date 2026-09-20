import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { CheckCircle2, Clock, AlertTriangle, XCircle, ChevronDown, Loader2 } from 'lucide-react';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';
import CopyButton from '../../components/CopyButton';
import CountdownTimer from '../../components/CountdownTimer';
import SmsForwarderStatus from '../../components/SmsForwarderStatus';
import { isLiveConnectionReady } from './paymentAutoConnect';

const currency = (n) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(n || 0);

const TERMINAL_STATUSES = ['completed', 'voucher_assigned', 'failed', 'expired', 'cancelled'];

export default function PaymentPage() {
  const { reference } = useParams();
  const navigate = useNavigate();
  const [order, setOrder] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [acknowledging, setAcknowledging] = useState(false);

  const [txnId, setTxnId] = useState('');
  const [refUsed, setRefUsed] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [verifyMessage, setVerifyMessage] = useState('');
  const [verifySuccess, setVerifySuccess] = useState(null);
  const [showVerifyForm, setShowVerifyForm] = useState(false);

  const pollRef = useRef(null);

  const fetchOrder = useCallback(() => {
    return api
      .get(`/customer/purchases/${reference}`)
      .then(({ data }) => {
        setOrder(data);
        return data;
      })
      .catch(() => setError('Could not load this payment. Please check the link and try again.'));
  }, [reference]);

  useEffect(() => {
    fetchOrder().finally(() => setLoading(false));
  }, [fetchOrder]);

  // Poll status every 5s while the order is still in flight — stops
  // itself once a terminal status is reached, and re-fetches the full
  // order (to pick up the voucher code) the moment it becomes available.
  useEffect(() => {
    if (!order || TERMINAL_STATUSES.includes(order.status)) {
      return;
    }

    pollRef.current = setInterval(async () => {
      const { data } = await api.get(`/customer/purchases/${reference}/status`);
      if (
        data.status !== order.status
        || data.has_voucher
        || data.connection_ready !== order.connection_ready
      ) {
        fetchOrder();
      }
    }, 5000);

    return () => clearInterval(pollRef.current);
  }, [fetchOrder, order, reference]);

  useEffect(() => {
    const liveReady = isLiveConnectionReady(order);
    const voucherReady = ['completed', 'voucher_assigned'].includes(order?.status) && order?.has_voucher;

    if (liveReady || voucherReady) {
      navigate('/dashboard?auto_connect=1', { replace: true });
    }
  }, [navigate, order]);

  const handleAcknowledge = async () => {
    setAcknowledging(true);
    try {
      await api.post(`/customer/purchases/${reference}/acknowledge-payment`);
      await fetchOrder();
    } finally {
      setAcknowledging(false);
    }
  };

  const handleVerify = async (e) => {
    e.preventDefault();
    setVerifying(true);
    setVerifyMessage('');
    try {
      const { data } = await api.post(`/customer/purchases/${reference}/verify`, {
        transaction_id: txnId,
        reference_used: refUsed,
      });
      setVerifySuccess(data.success);
      setVerifyMessage(data.message);
      await fetchOrder();
    } catch (err) {
      setVerifySuccess(false);
      setVerifyMessage(err.response?.data?.message || 'Could not verify right now. Please try again.');
    } finally {
      setVerifying(false);
    }
  };

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;
  if (error || !order) {
    return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  }

  if (order.fulfillment_type === 'live' && order.status === 'active') {
    return (
      <div className="mx-auto max-w-md py-12 text-center" role="status" aria-live="polite">
        <Loader2 className="mx-auto mb-4 h-12 w-12 animate-spin text-indigo-600" />
        <h1 className="mb-2 text-xl font-semibold text-slate-900">Payment complete — connecting to WiFi</h1>
        <p className="text-sm text-slate-500">Please wait while we prepare your hotspot account and connect this device…</p>
        <div className="mx-auto my-5 h-1.5 max-w-xs overflow-hidden rounded-full bg-indigo-100">
          <div className="h-full w-1/2 animate-pulse rounded-full bg-indigo-600" />
        </div>
        <p className="font-mono text-xs text-slate-400">Ref: {reference}</p>
      </div>
    );
  }

  // ── Voucher already assigned — the success view. Never rendered
  // before this point, i.e. never before has_voucher is actually true. ──
  if (order.has_voucher && order.voucher) {
    return (
      <div className="max-w-sm mx-auto text-center py-8">
        <CheckCircle2 className="h-12 w-12 text-emerald-500 mx-auto mb-3" />
        <h1 className="text-xl font-semibold text-slate-900 mb-1">Payment Successful</h1>
        <p className="text-slate-500 text-sm mb-6">Your Royal WiFi Voucher</p>

        <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
          <p className="text-xs text-slate-400 mb-1">Voucher Code</p>
          <p className="text-2xl font-mono font-bold text-slate-900 tracking-wide mb-3">{order.voucher.code}</p>
          <div className="flex justify-center mb-4">
            <CopyButton text={order.voucher.code} />
          </div>

          <dl className="text-sm text-left space-y-1.5 border-t border-slate-100 pt-4">
            <div className="flex justify-between">
              <dt className="text-slate-400">Package</dt>
              <dd className="text-slate-700 font-medium">{order.package.name}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-slate-400">Amount</dt>
              <dd className="text-slate-700 font-medium">{currency(order.amount)}</dd>
            </div>
            {order.voucher.duration_days && (
              <div className="flex justify-between">
                <dt className="text-slate-400">Duration</dt>
                <dd className="text-slate-700 font-medium">{order.voucher.duration_days} days</dd>
              </div>
            )}
          </dl>
        </div>

        <Link to="/my-vouchers" className="inline-block mt-5 text-sm text-indigo-600 font-medium hover:underline">
          View My Vouchers →
        </Link>
      </div>
    );
  }

  // ── Terminal, no voucher: failed / expired / cancelled ──
  if (['failed', 'expired', 'cancelled'].includes(order.status)) {
    const copy = {
      failed: { icon: XCircle, title: 'Payment could not be verified', tone: 'text-red-500' },
      expired: { icon: Clock, title: 'This order expired', tone: 'text-slate-400' },
      cancelled: { icon: XCircle, title: 'This order was cancelled', tone: 'text-slate-400' },
    }[order.status];
    const Icon = copy.icon;

    return (
      <div className="max-w-sm mx-auto text-center py-8">
        <Icon className={`h-12 w-12 mx-auto mb-3 ${copy.tone}`} />
        <h1 className="text-lg font-semibold text-slate-900 mb-2">{copy.title}</h1>
        <p className="text-slate-500 text-sm mb-6">
          {order.status === 'expired'
            ? "This payment window closed before we received your payment. Start a new order if you'd still like to buy this package."
            : 'Please start a new order, or contact support if you believe this is a mistake.'}
        </p>
        <Link
          to="/buy"
          className="inline-block bg-indigo-600 text-white rounded-md px-5 py-2 text-sm font-medium hover:bg-indigo-700"
        >
          Buy Internet
        </Link>
      </div>
    );
  }

  // ── manual_review or verified-but-waiting-for-inventory ──
  const isWaitingReview = order.status === 'manual_review';
  const isWaitingInventory = order.status === 'verified' && !order.has_voucher;

  return (
    <div className="max-w-md mx-auto">
      <h1 className="text-xl font-semibold text-slate-900 mb-1 text-center">Pay for Royal WiFi</h1>
      <p className="text-slate-500 text-sm mb-6 text-center">
        Package: <strong>{order.package.name}</strong>
      </p>

      {(isWaitingReview || isWaitingInventory) && (
        <div className="mb-5 bg-amber-50 border border-amber-200 rounded-md px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
          <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
          {isWaitingReview
            ? "We received a possible payment match but it needs a quick manual check — we'll update this page automatically once confirmed."
            : "Payment confirmed! We're preparing your voucher — this can take a few minutes if stock is being restocked. This page will update automatically."}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm mb-5">
        <dl className="text-sm space-y-2">
          <div className="flex justify-between">
            <dt className="text-slate-400">Package</dt>
            <dd className="text-slate-700">{currency(order.subtotal ?? order.amount)}</dd>
          </div>
          {Number(order.payment_fee) > 0 && (
            <div className="flex justify-between">
              <dt className="text-slate-400">Payment charge</dt>
              <dd className="text-slate-700">{currency(order.payment_fee)}</dd>
            </div>
          )}
          <div className="flex justify-between border-t border-slate-100 pt-2">
            <dt className="font-medium text-slate-700">Total to pay</dt>
            <dd className="font-bold text-slate-900">{currency(order.amount)}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-slate-400">MoMo Number</dt>
            <dd className="font-mono font-semibold text-slate-900">{order.momo_number || '—'}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-slate-400">Account Name</dt>
            <dd className="font-semibold text-slate-900">{order.momo_account_name || '—'}</dd>
          </div>
          <div className="flex justify-between items-center">
            <dt className="text-slate-400">Reference</dt>
            <dd className="font-mono font-bold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-md tracking-wide">
              {order.reference}
            </dd>
          </div>
        </dl>

        <ol className="mt-4 text-sm text-slate-600 space-y-1 list-decimal list-inside border-t border-slate-100 pt-4">
          <li>Open your Mobile Money service.</li>
          <li>
            Send exactly <strong>{currency(order.amount)}</strong> to <strong>{order.momo_number}</strong>.
          </li>
          <li>
            Include the reference{' '}
            <strong className="font-mono font-bold text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded">
              {order.reference}
            </strong>{' '}
            if your MoMo service allows a note.
          </li>
          <li>Wait for payment verification — usually within a minute or two.</li>
        </ol>

        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
          <span className="text-sm text-slate-500">Payment Status</span>
          <StatusBadge status={order.status} />
        </div>

        {order.expires_at && !isWaitingInventory && (
          <p className="text-xs text-slate-400 mt-2 text-center">
            Payment window closes in{' '}
            <CountdownTimer
              initialSeconds={Math.max(0, Math.floor((new Date(order.expires_at) - new Date()) / 1000))}
            />
          </p>
        )}

        {order.status === 'pending' && (
          <button
            onClick={handleAcknowledge}
            disabled={acknowledging}
            className="mt-4 w-full bg-emerald-600 text-white rounded-md py-2.5 text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50"
          >
            {acknowledging ? 'Please wait…' : 'I have made payment'}
          </button>
        )}
      </div>

      {!isWaitingInventory && !isWaitingReview && (
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <button
            onClick={() => setShowVerifyForm((s) => !s)}
            className="w-full flex items-center justify-between px-5 py-4 text-left"
          >
            <span className="text-sm font-medium text-slate-700">Already made your payment?</span>
            <ChevronDown className={`h-4 w-4 text-slate-400 transition-transform ${showVerifyForm ? 'rotate-180' : ''}`} />
          </button>

          {showVerifyForm && (
            <div className="px-5 pb-5 border-t border-slate-100 pt-4">
              <SmsForwarderStatus />
              <form onSubmit={handleVerify} className="space-y-3">
                <div>
                  <label className="block text-xs text-slate-500 mb-1">Transaction ID</label>
                  <input
                    required
                    className="input"
                    value={txnId}
                    onChange={(e) => setTxnId(e.target.value)}
                    placeholder="From your MoMo confirmation SMS"
                  />
                </div>
                <div>
                  <label className="block text-xs text-slate-500 mb-1">Reference Code Used</label>
                  <input
                    required
                    className="input font-mono"
                    value={refUsed}
                    onChange={(e) => setRefUsed(e.target.value)}
                    placeholder={order.reference}
                  />
                </div>

                {verifyMessage && (
                  <p className={`text-xs ${verifySuccess ? 'text-emerald-600' : 'text-amber-600'}`}>{verifyMessage}</p>
                )}

                <button
                  type="submit"
                  disabled={verifying}
                  className="w-full border border-indigo-600 text-indigo-600 rounded-md py-2 text-sm font-medium hover:bg-indigo-50 disabled:opacity-50"
                >
                  {verifying ? 'Checking…' : 'Verify Payment'}
                </button>
              </form>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
