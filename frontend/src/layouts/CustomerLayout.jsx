import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { LayoutDashboard, Wifi, Receipt, User, Ticket, ListChecks, MessageSquare, Gift } from 'lucide-react';
import Sidebar from '../components/Sidebar';
import Topbar from '../components/Topbar';

const CUSTOMER_NAV_SECTIONS = [
  {
    label: 'Overview',
    items: [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }],
  },
  {
    label: 'Buy',
    items: [
      { to: '/buy', label: 'Buy Internet', icon: Wifi },
      { to: '/free-trial', label: 'Free Internet', icon: Gift },
      { to: '/my-vouchers', label: 'My Vouchers', icon: ListChecks },
      { to: '/redeem', label: 'Redeem Voucher', icon: Ticket },
    ],
  },
  {
    label: 'Account',
    items: [
      { to: '/payments', label: 'Payments', icon: Receipt },
      { to: '/complaints', label: 'Complaints', icon: MessageSquare },
      { to: '/profile', label: 'Profile', icon: User },
    ],
  },
];

/**
 * Mirrors AdminLayout.jsx exactly — same shared Sidebar/Topbar
 * components, grouped nav sections (see Sidebar.jsx).
 */
export default function CustomerLayout() {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="min-h-screen flex bg-slate-50">
      <Sidebar
        mobileOpen={mobileOpen}
        onClose={() => setMobileOpen(false)}
        navSections={CUSTOMER_NAV_SECTIONS}
        title="Hotspot Billing"
        subtitle="Customer Portal"
      />

      <div className="flex-1 min-w-0 flex flex-col">
        <Topbar onMenuClick={() => setMobileOpen(true)} logoutRedirect="/login" fallbackLabel="Customer" showAdminExtras={false} />
        <main className="flex-1 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
