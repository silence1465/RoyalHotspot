import { useEffect, useState } from 'react';
import guestApi from '../services/guestApi';

/**
 * Shown specifically around the "Already Paid — enter your Transaction
 * ID" fallback, since that's the exact moment a down SMS relay phone
 * would otherwise look like a silent, unexplained failure — the person
 * has no way to know whether the system is even capable of matching
 * their payment right now. Uses guestApi (no auth) since this same
 * component is shared by both the authenticated customer PaymentPage
 * and the unauthenticated GuestPayment page.
 */
export default function SmsForwarderStatus() {
  const [status, setStatus] = useState(null);

  useEffect(() => {
    guestApi
      .get('/sms-forwarder/status')
      .then(({ data }) => setStatus(data))
      .catch(() => setStatus(null));
  }, []);

  if (!status) return null;

  return (
    <div className={`flex items-center gap-1.5 text-xs mb-3 ${status.online ? 'text-emerald-600' : 'text-amber-600'}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${status.online ? 'bg-emerald-500' : 'bg-amber-500'}`} />
      {status.online
        ? 'Automatic payment detection is online'
        : 'Automatic detection may be slow right now — your Transaction ID will still be reviewed'}
    </div>
  );
}
