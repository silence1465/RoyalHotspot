import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2 } from 'lucide-react';

/**
 * Paystack redirects here after checkout (see PAYSTACK_CALLBACK_URL in
 * backend/.env.example). Actual activation happens via the webhook in
 * Phase 9, not here — this page is just a friendly waypoint so the
 * customer isn't left staring at a blank Paystack confirmation screen.
 * It deliberately does NOT call any verify/activate endpoint yet, to
 * keep this phase's scope to "initialize + redirect" as specified.
 */
export default function PaymentCallback() {
  const [params] = useSearchParams();
  const reference = params.get('reference') || params.get('trxref');

  return (
    <div className="max-w-md mx-auto text-center py-12">
      <CheckCircle2 className="h-12 w-12 text-emerald-500 mx-auto mb-4" />
      <h1 className="text-xl font-semibold text-slate-900 mb-2">Payment received</h1>
      <p className="text-slate-500 text-sm mb-1">
        We're setting up your internet access now — this usually takes just a few seconds.
      </p>
      {reference && <p className="text-xs text-slate-400 font-mono mb-6">Ref: {reference}</p>}
      <Link
        to="/dashboard"
        className="inline-block bg-indigo-600 text-white rounded-md px-5 py-2 text-sm font-medium hover:bg-indigo-700"
      >
        Go to Dashboard
      </Link>
    </div>
  );
}
