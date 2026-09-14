import { useState, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from '../components/Sidebar';
import Topbar from '../components/Topbar';
import api from '../services/api';

export default function AdminLayout() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [badges, setBadges] = useState({});

  useEffect(() => {
    const load = () => {
      api
        .get('/admin/notifications/summary')
        .then(({ data }) =>
          setBadges({
            ...(data.navigation_counts || {}),
            '/admin/purchases': Math.max(data.navigation_counts?.['/admin/purchases'] || 0, data.badges.purchases || 0),
            '/admin/complaints': data.badges.complaints,
          })
        )
        .catch(() => {});
    };

    load();
    const interval = setInterval(load, 30000);
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="min-h-screen flex bg-slate-50">
      <Sidebar mobileOpen={mobileOpen} onClose={() => setMobileOpen(false)} badges={badges} />

      <div className="flex-1 min-w-0 flex flex-col">
        <Topbar onMenuClick={() => setMobileOpen(true)} />
        <main className="flex-1 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
