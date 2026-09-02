import { useEffect, useState } from 'react';

function formatDuration(totalSeconds) {
  if (totalSeconds <= 0) return 'Expired';

  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = Math.floor(totalSeconds % 60);

  if (days > 0) return `${days}d ${hours}h ${minutes}m`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

/**
 * Ticks down client-side from an initial server-provided remaining-seconds
 * value. This is a display convenience only — the server's expires_at is
 * always the source of truth for actually disabling access
 * (see PurchaseService::expirePurchase).
 */
export default function CountdownTimer({ initialSeconds }) {
  const [seconds, setSeconds] = useState(initialSeconds);

  useEffect(() => {
    setSeconds(initialSeconds);
  }, [initialSeconds]);

  useEffect(() => {
    if (initialSeconds <= 0) return undefined;

    const interval = setInterval(() => {
      setSeconds((current) => {
        if (current <= 1) {
          clearInterval(interval);
          return 0;
        }

        return current - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [initialSeconds]);

  return <span>{formatDuration(seconds)}</span>;
}
