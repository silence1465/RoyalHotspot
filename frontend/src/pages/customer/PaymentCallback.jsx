import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Clock3, Loader2, XCircle } from 'lucide-react';
import api from '../../services/api';
import { isLiveConnectionReady, paymentSuccessDestination, restorePaystackPortalContext } from './paymentAutoConnect';
import { prepareAndSubmitHotspotLogin } from './hotspotAutoLogin';

const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export default function PaymentCallback() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const reference = params.get('reference') || params.get('trxref');
  const [hasPortalContext] = useState(() => restorePaystackPortalContext(
    window.localStorage,
    window.sessionStorage,
  ));
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
          setState('connecting');
          setMessage('Payment complete. Please wait while we connect you to WiFi…');
          if (!hasPortalContext) {
            navigate(paymentSuccessDestination(false), { replace: true });
            return;
          }

          // Wait for RouterOS provisioning, then submit the login from this
          // hotspot device directly instead of relying on a route effect.
          for (let attempt = 0; attempt < 30; attempt += 1) {
            const { data: status } = await api.get(`/customer/purchases/${encodeURIComponent(reference)}/status`);
            if (cancelled) return;
            if (isLiveConnectionReady(status)) {
              const connection = await prepareAndSubmitHotspotLogin(api);
              if (!connection.submitted) navigate('/dashboard', { replace: true });
              return;
            }
            if (status.status === 'pending_activation') {
              setState('error');
              setMessage('Payment succeeded, but the hotspot account could not be prepared. Please contact support.');
              return;
            }
            if (status.status === 'queued') {
              setState('waiting');
              setMessage('Payment succeeded. Your package is waiting for available capacity.');
              return;
            }
            await wait(2000);
          }

          setState('waiting');
          setMessage('Payment succeeded. Hotspot activation is taking longer than expected; check your dashboard shortly.');
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
  }, [hasPortalContext, navigate, reference]);

  const isBusy = state === 'verifying' || state === 'connecting';
  const Icon = state === 'success'
    ? CheckCircle2
    : state === 'error'
      ? XCircle
      : isBusy
        ? Loader2
        : Clock3;
  const iconColor = state === 'success'
    ? 'text-emerald-500'
    : state === 'error'
      ? 'text-red-500'
      : state === 'connecting'
        ? 'text-indigo-600'
        : 'text-amber-500';

  return (
    <div className="mx-auto max-w-md py-12 text-center">
      <Icon className={`mx-auto mb-4 h-12 w-12 ${iconColor} ${isBusy ? 'animate-spin' : ''}`} />
      <h1 className="mb-2 text-xl font-semibold text-slate-900">
        {state === 'connecting'
          ? 'Payment complete — connecting to WiFi'
          : state === 'success'
            ? 'Payment verified'
            : state === 'error'
              ? 'Verification problem'
              : 'Confirming payment'}
      </h1>
      <p className="mb-1 text-sm text-slate-500">{message}</p>
      {state === 'connecting' && (
        <div className="mx-auto my-5 h-1.5 max-w-xs overflow-hidden rounded-full bg-indigo-100" role="status" aria-label="Connecting to WiFi">
          <div className="h-full w-1/2 animate-pulse rounded-full bg-indigo-600" />
        </div>
      )}
      {reference && <p className="mb-6 font-mono text-xs text-slate-400">Ref: {reference}</p>}
      {!isBusy && (
        <Link
          to={paymentSuccessDestination(hasPortalContext)}
          className="inline-block rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700"
        >
          Go to Dashboard
        </Link>
      )}
    </div>
  );
}
