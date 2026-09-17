import { useEffect, useState } from 'react';
import { Menu, LogOut } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import NotificationBell from './NotificationBell';
import GlobalSearch from './GlobalSearch';
import SmsForwarderIcon from './SmsForwarderIcon';

/**
 * Shared between AdminLayout and CustomerLayout — see Sidebar.jsx for the
 * same reasoning. Defaults match the original admin-only behavior.
 * showAdminExtras gates the notification bell and global search, which
 * only make sense in the admin context — CustomerLayout never passes it.
 */
export default function Topbar({ onMenuClick, logoutRedirect = '/admin/login', fallbackLabel = 'Admin', showAdminExtras = true }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [operatingMode, setOperatingMode] = useState('');
  const [modeLoading, setModeLoading] = useState(true);
  const [modeError, setModeError] = useState('');
  const canChangeMode = showAdminExtras && user?.role === 'super_admin';

  useEffect(() => {
    if (!canChangeMode) return;

    let active = true;
    setModeLoading(true);
    api.get('/admin/operating-mode')
      .then(({ data }) => {
        if (active) setOperatingMode(data.mode || 'normal');
      })
      .catch(() => {
        if (active) setModeError('Could not load operating mode. Refresh to retry.');
      })
      .finally(() => { if (active) setModeLoading(false); });

    return () => { active = false; };
  }, [canChangeMode]);

  const handleLogout = async () => {
    await logout();
    navigate(logoutRedirect);
  };

  const selectedRouter = localStorage.getItem('admin_router_scope') || 'all';
  const changeRouter = (value) => {
    localStorage.setItem('admin_router_scope', value);
    window.location.reload();
  };

  const changeOperatingMode = async (value) => {
    const previous = operatingMode;
    setModeError('');
    setModeLoading(true);
    try {
      const { data } = await api.put('/admin/operating-mode', { mode: value });
      setOperatingMode(data.mode);
    } catch (error) {
      setOperatingMode(previous);
      setModeError(error.response?.data?.message || 'Could not save operating mode. Please retry.');
    } finally {
      setModeLoading(false);
    }
  };

  return (
    <header className="sticky top-0 z-30 flex flex-wrap gap-2 items-center justify-between bg-white border-b border-slate-200 px-3 sm:px-6 py-3">
      <button
        onClick={onMenuClick}
        className="lg:hidden text-slate-600 hover:text-slate-900"
        aria-label="Open menu"
      >
        <Menu className="h-6 w-6" />
      </button>

      {showAdminExtras ? <GlobalSearch /> : <div className="hidden lg:block" />}

      <div className="flex flex-wrap items-center justify-end gap-2 sm:gap-3 min-w-0">
        {canChangeMode && (
          <div className="relative">
          <select
            aria-label="Operating mode"
            title="System operating mode"
            className="input w-28 sm:w-36 text-sm"
            value={operatingMode}
            disabled={modeLoading || !operatingMode}
            onChange={(event) => changeOperatingMode(event.target.value)}
          >
            <option value="" disabled>{modeLoading ? 'Loading…' : 'Unavailable'}</option>
            <option value="normal">Normal</option>
            <option value="data_cap">Data Cap</option>
            <option value="user_cap">User + Data Cap</option>
          </select>
          {modeError && <p role="alert" className="absolute right-0 top-full mt-1 w-60 rounded border border-red-200 bg-white p-2 text-xs text-red-700 shadow z-50">{modeError}</p>}
          </div>
        )}
        {showAdminExtras && user?.routers && (
          <select
            aria-label="Selected router"
            title="Selected router"
            className="input w-32 sm:w-40 text-sm"
            value={selectedRouter}
            onChange={(event) => changeRouter(event.target.value)}
          >
            <option value="all">{user.role === 'super_admin' ? 'All Routers' : 'All Assigned Routers'}</option>
            {user.routers.map((router) => (
              <option key={router.id} value={router.id}>{router.name}</option>
            ))}
          </select>
        )}
        {showAdminExtras && <SmsForwarderIcon />}
        {showAdminExtras && <NotificationBell />}
        <span className="text-sm text-slate-600 hidden sm:inline">
          {user?.name || user?.full_name || fallbackLabel}
        </span>
        <button
          onClick={handleLogout}
          className="flex items-center gap-1.5 text-sm text-slate-500 hover:text-red-600 transition"
        >
          <LogOut className="h-4 w-4" />
          <span className="hidden sm:inline">Log out</span>
        </button>
      </div>
    </header>
  );
}
