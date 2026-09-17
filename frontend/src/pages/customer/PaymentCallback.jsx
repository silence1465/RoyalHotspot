import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Clock3, Loader2, XCircle } from 'lucide-react';
import api from '../../services/api';

export default function PaymentCallback() {
  const [params] = useSearchParams();
  const reference = params.get('reference') || params.get('trxref');
  const [state, setState] = useState(reference ? 'verifying' : 'error');
  const [message, setMessage] = useState(reference
    ? 'Confirming your payment securely with Paystack…'
    : 'The Paystack payment reference is missing.');

  useEffect(() => {
    if (!reference) return undefined;

    let cancelled = false;
    let timer;
    let attempts = 0;

    const verify = async () => {
      attempts += 1;
      try {
        const { data } = await api.post(`/customer/purchases/${encodeURIComponent(reference)}/verify-paystack`);
        if (cancelled) return;

        if (data.success) {
          setState('success');
          setMessage(data.message || 'Payment verified. Your internet package is being prepared.');
          return;
        }

        if (data.status === 'failed') {
          setState('error');
          setMessage(data.message || 'The payment could not be verified. Please contact support.');
          return;
        }

        if (attempts < 5) {
          setState('waiting');
          setMessage('Payment received. Waiting for Paystack to finalize it…');
          timer = window.setTimeout(verify, 2500);
        } else {
          setState('waiting');
          setMessage('Paystack is still processing this payment. You can check your dashboard shortly.');
        }
      } catch (error) {
        if (cancelled) return;
        const status = error.response?.status;
        if (status >= 400 && status < 500 && status !== 429) {
          setState('error');
          setMessage(error.response?.data?.message || 'This payment could not be verified.');
        } else if (attempts < 5) {
          setState('waiting');
          setMessage('Verification is temporarily unavailable. Retrying…');
          timer = window.setTimeout(verify, 2500);
        } else {
          setState('waiting');
          setMessage('Verification is delayed. Your payment remains safe; check your dashboard shortly.');
        }
      }
    };

    verify();
    return () => {
      cancelled = true;
      if (timer) window.clearTimeout(timer);
    };
  }, [reference]);

  const Icon = state === 'success'
    ? CheckCircle2
    : state === 'error'
      ? XCircle
      : state === 'verifying'
        ? Loader2
        : Clock3;
  const iconColor = state === 'success'
    ? 'text-emerald-500'
    : state === 'error'
      ? 'text-red-500'
      : 'text-amber-500';

  return (
    <div className="mx-auto max-w-md py-12 text-center">
      <Icon className={`mx-auto mb-4 h-12 w-12 ${iconColor} ${state === 'verifying' ? 'animate-spin' : ''}`} />
      <h1 className="mb-2 text-xl font-semibold text-slate-900">
        {state === 'success' ? 'Payment verified' : state === 'error' ? 'Verification problem' : 'Confirming payment'}
      </h1>
      <p className="mb-1 text-sm text-slate-500">{message}</p>
      {reference && <p className="mb-6 font-mono text-xs text-slate-400">Ref: {reference}</p>}
      <Link
        to="/dashboard"
        className="inline-block rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700"
      >
        Go to Dashboard
      </Link>
    </div>
  );
}
