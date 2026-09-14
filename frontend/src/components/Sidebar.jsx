import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard,
  Router as RouterIcon,
  Users,
  Package,
  CreditCard,
  Receipt,
  Ticket,
  ScrollText,
  Settings,
  MessageSquare,
  Activity,
  Gift,
  Zap,
  X,
  BookOpen,
} from 'lucide-react';

const DEFAULT_NAV_SECTIONS = [
  {
    label: 'Overview',
    items: [{ to: '/admin/dashboard', label: 'Dashboard', icon: LayoutDashboard }],
  },
  {
    label: 'Operations',
    items: [
      { to: '/admin/router-management', label: 'Router Management', icon: RouterIcon },
      { to: '/admin/routers', label: 'Routers', icon: RouterIcon },
      { to: '/admin/customers', label: 'Customers', icon: Users },
      { to: '/admin/packages', label: 'Packages', icon: Package },
      { to: '/admin/bandwidth', label: 'Bandwidth', icon: Activity },
      { to: '/admin/active-sessions', label: 'Active Sessions', icon: Zap },
    ],
  },
  {
    label: 'Payments',
    items: [
      { to: '/admin/purchases', label: 'Purchases', icon: CreditCard },
      { to: '/admin/payments', label: 'Payments', icon: Receipt },
      { to: '/admin/accounting', label: 'Accounting History', icon: BookOpen },
      { to: '/admin/vouchers', label: 'Vouchers', icon: Ticket },
      { to: '/admin/assign-package', label: 'Assign Package', icon: Gift },
      { to: '/admin/free-trials', label: 'Free Campaigns', icon: Gift },
    ],
  },
  {
    label: 'Support',
    items: [{ to: '/admin/complaints', label: 'Complaints', icon: MessageSquare }],
  },
  {
    label: 'System',
    items: [
      { to: '/admin/logs', label: 'Logs', icon: ScrollText },
      { to: '/admin/settings', label: 'Settings', icon: Settings },
    ],
  },
];

function NavSections({ sections, onNavigate, badges = {} }) {
  return (
    <nav className="flex-1 px-3 py-4 space-y-5 overflow-y-auto">
      {sections.map((section) => (
        <div key={section.label}>
          <p className="px-3 mb-1.5 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
            {section.label}
          </p>
          <div className="space-y-1">
            {section.items.map(({ to, label, icon: Icon }) => (
              <NavLink
                key={to}
                to={to}
                onClick={onNavigate}
                className={({ isActive }) =>
                  `flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm font-medium transition ${
                    isActive
                      ? 'bg-slate-800 text-white'
                      : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'
                  }`
                }
              >
                <span className="flex items-center gap-3">
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
                </span>
                {Number.isFinite(Number(badges[to])) && (
                  <span className="bg-slate-700 text-slate-100 text-[10px] font-semibold rounded-full h-4 min-w-4 px-1 flex items-center justify-center">
                    {badges[to] > 99 ? '99+' : badges[to]}
                  </span>
                )}
              </NavLink>
            ))}
          </div>
        </div>
      ))}
    </nav>
  );
}

/**
 * Shared between AdminLayout and CustomerLayout — desktop-fixed sidebar,
 * mobile slide-over drawer. Takes `navSections` (grouped, with labeled
 * headers) rather than a flat `navItems` list.
 */
export default function Sidebar({
  mobileOpen,
  onClose,
  navSections = DEFAULT_NAV_SECTIONS,
  title = 'Hotspot Billing',
  subtitle = 'Admin Panel',
  badges = {},
}) {
  return (
    <>
      {/* Desktop sidebar */}
      <aside className="hidden lg:flex lg:flex-col lg:w-64 lg:shrink-0 bg-slate-900 min-h-screen">
        <div className="px-5 py-5 border-b border-slate-800">
          <p className="text-white font-semibold text-sm tracking-wide">{title}</p>
          <p className="text-slate-400 text-xs mt-0.5">{subtitle}</p>
        </div>
        <NavSections sections={navSections} badges={badges} />
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="fixed inset-0 bg-black/40" onClick={onClose} />
          <aside className="fixed inset-y-0 left-0 w-64 bg-slate-900 flex flex-col">
            <div className="flex items-center justify-between px-5 py-5 border-b border-slate-800">
              <div>
                <p className="text-white font-semibold text-sm tracking-wide">{title}</p>
                <p className="text-slate-400 text-xs mt-0.5">{subtitle}</p>
              </div>
              <button onClick={onClose} className="text-slate-400 hover:text-white">
                <X className="h-5 w-5" />
              </button>
            </div>
            <NavSections sections={navSections} onNavigate={onClose} badges={badges} />
          </aside>
        </div>
      )}
    </>
  );
}
