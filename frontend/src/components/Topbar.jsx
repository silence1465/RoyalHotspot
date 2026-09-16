import { Menu, LogOut } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useNavigate } from 'react-router-dom';
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

  const handleLogout = async () => {
    await logout();
    navigate(logoutRedirect);
  };

  const selectedRouter = localStorage.getItem('admin_router_scope') || 'all';
  const changeRouter = (value) => {
    localStorage.setItem('admin_router_scope', value);
    window.location.reload();
  };

  return (
    <header className="sticky top-0 z-30 flex items-center justify-between bg-white border-b border-slate-200 px-4 sm:px-6 py-3">
      <button
        onClick={onMenuClick}
        className="lg:hidden text-slate-600 hover:text-slate-900"
        aria-label="Open menu"
      >
        <Menu className="h-6 w-6" />
      </button>

      {showAdminExtras ? <GlobalSearch /> : <div className="hidden lg:block" />}

      <div className="flex items-center gap-4">
        {showAdminExtras && user?.routers && (
          <select
            aria-label="Selected router"
            className="input max-w-56 text-sm"
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
