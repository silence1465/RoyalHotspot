import { useEffect, useState, useRef } from 'react';
import { Bell } from 'lucide-react';
import { Link } from 'react-router-dom';
import api from '../services/api';

export default function NotificationBell() {
  const [data, setData] = useState(null);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const lastSeenIdRef = useRef(null);

  const load = () => {
    api
      .get('/admin/notifications/summary')
      .then(({ data }) => {
        // Fire a real browser notification for any router.went_offline
        // entry newer than the last one we've already seen — this is
        // what makes item 12's "notify me when a router is off" real,
        // not just a badge count. Only fires while this tab/PWA is open
        // in the background; Telegram (sent server-side, see
        // MonitorRouterHealth) is the channel that still works closed.
        if (data.recent && typeof Notification !== 'undefined' && Notification.permission === 'granted') {
          const offlineEvents = data.recent.filter((item) => item.action === 'router.went_offline');
          const newest = offlineEvents[0]?.id ?? null;

          if (lastSeenIdRef.current !== null) {
            offlineEvents
              .filter((item) => item.id > lastSeenIdRef.current)
              .forEach((item) => {
                new Notification('Router Offline', { body: item.description });
              });
          }

          if (newest !== null) {
            lastSeenIdRef.current = newest;
          }
        }

        setData(data);
      })
      .catch(() => {});
  };

  useEffect(() => {
    load();
    const interval = setInterval(load, 30000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const totalBadges = data ? Object.values(data.badges).reduce((sum, n) => sum + n, 0) : 0;

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen((o) => !o)} className="relative text-slate-500 hover:text-slate-900">
        <Bell className="h-5 w-5" />
        {totalBadges > 0 && (
          <span className="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-semibold rounded-full h-4 min-w-4 px-1 flex items-center justify-center">
            {totalBadges > 99 ? '99+' : totalBadges}
          </span>
        )}
      </button>

      {open && data && (
        <div className="absolute right-0 mt-2 w-80 bg-white rounded-xl border border-slate-200 shadow-lg z-50 overflow-hidden">
          <div className="px-4 py-3 border-b border-slate-100">
            <p className="text-sm font-semibold text-slate-700">Needs Attention</p>
          </div>
          <div className="px-4 py-3 border-b border-slate-100 space-y-2">
            <BadgeRow label="Purchases" count={data.badges.purchases} to="/admin/purchases" onNavigate={() => setOpen(false)} />
            <BadgeRow label="Complaints" count={data.badges.complaints} to="/admin/complaints" onNavigate={() => setOpen(false)} />
            <BadgeRow label="Routers Offline" count={data.badges.routers_offline} to="/admin/routers" onNavigate={() => setOpen(false)} />
          </div>

          <div className="px-4 py-2 border-b border-slate-100">
            <p className="text-xs font-semibold text-slate-500">Recent Activity</p>
          </div>
          <div className="max-h-64 overflow-y-auto">
            {data.recent.length === 0 && <p className="px-4 py-4 text-xs text-slate-400 text-center">Nothing yet.</p>}
            {data.recent.map((item) => (
              <div key={item.id} className="px-4 py-2.5 border-b border-slate-50 last:border-0">
                <p className="text-xs text-slate-700">{item.description}</p>
                <p className="text-[10px] text-slate-400 mt-0.5">
                  {item.user?.name ? `${item.user.name} · ` : ''}
                  {new Date(item.created_at).toLocaleString()}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function BadgeRow({ label, count, to, onNavigate }) {
  if (!count) {
    return (
      <div className="flex items-center justify-between text-xs text-slate-400">
        <span>{label}</span>
        <span>0</span>
      </div>
    );
  }

  return (
    <Link to={to} onClick={onNavigate} className="flex items-center justify-between text-xs text-slate-700 hover:text-indigo-600">
      <span>{label}</span>
      <span className="bg-red-100 text-red-700 font-semibold rounded-full px-1.5 py-0.5">{count}</span>
    </Link>
  );
}
