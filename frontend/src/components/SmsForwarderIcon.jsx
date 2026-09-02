import { useEffect, useState } from 'react';
import { Radio } from 'lucide-react';
import api from '../services/api';

/**
 * Was a dashboard-only stat card — moved to the navbar so the status is
 * visible from every admin page, not just the dashboard, since it's
 * operationally important enough (MoMo payments silently stop
 * confirming if this goes down) to want at a glance regardless of which
 * page you're on.
 */
export default function SmsForwarderIcon() {
  const [status, setStatus] = useState(null);

  useEffect(() => {
    const load = () => {
      api.get('/sms-forwarder/status').then(({ data }) => setStatus(data)).catch(() => {});
    };
    load();
    const interval = setInterval(load, 30000);
    return () => clearInterval(interval);
  }, []);

  if (!status) return null;

  return (
    <div
      className="relative"
      title={status.online ? 'SMS Forwarder: Online' : 'SMS Forwarder: Offline'}
    >
      <Radio className={`h-5 w-5 ${status.online ? 'text-emerald-500' : 'text-red-500'}`} />
      <span
        className={`absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full ${
          status.online ? 'bg-emerald-500' : 'bg-red-500'
        }`}
      />
    </div>
  );
}
