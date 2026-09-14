import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './routes/ProtectedRoute';
import AdminLayout from './layouts/AdminLayout';
import CustomerLayout from './layouts/CustomerLayout';
import PwaInstallPrompt from './components/PwaInstallPrompt';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';

import CustomerLogin from './pages/customer/Login';
import CustomerRegister from './pages/customer/Register';
import CustomerDashboard from './pages/customer/Dashboard';
import PaymentPage from './pages/customer/PaymentPage';
import MyVouchers from './pages/customer/MyVouchers';
import BuyInternet from './pages/customer/BuyInternet';
import RedeemVoucher from './pages/customer/RedeemVoucher';
import CustomerPayments from './pages/customer/Payments';
import CustomerProfile from './pages/customer/Profile';
import PaymentCallback from './pages/customer/PaymentCallback';
import CustomerComplaints from './pages/customer/Complaints';

import Portal from './pages/Portal';

import AdminLogin from './pages/admin/Login';
// Lazy — recharts pulls in a genuinely large dependency tree (d3
// internals), and this is the only page that uses it. Every guest and
// customer page (loaded on hotspot mobile data, often metered) would
// otherwise pay for that weight in the shared bundle even though they
// never render a chart.
const AdminDashboard = lazy(() => import('./pages/admin/Dashboard'));
import AdminRoutersPage from './pages/admin/Routers';
import AdminCustomers from './pages/admin/Customers';
import AdminPackagesPage from './pages/admin/Packages';
import AdminPurchases from './pages/admin/Purchases';
import AdminPayments from './pages/admin/Payments';
import AdminVouchers from './pages/admin/Vouchers';
import AdminLogs from './pages/admin/Logs';
import AdminSettings from './pages/admin/Settings';
import AdminFreeTrials from './pages/admin/FreeTrials';
import CustomerFreeTrial from './pages/customer/FreeTrial';
import AdminComplaints from './pages/admin/Complaints';
import AdminBandwidthPage from './pages/admin/Bandwidth';
import AdminAssignPackage from './pages/admin/AssignPackage';
import AdminActiveSessionsPage from './pages/admin/ActiveSessions';
import AccountingHistory from './pages/admin/AccountingHistory';
import MikrotikSecurityGate from './components/MikrotikSecurityGate';
import RouterManagementPage from './pages/admin/RouterManagement';

const AdminRouters = () => <MikrotikSecurityGate><AdminRoutersPage /></MikrotikSecurityGate>;
const AdminPackages = () => <MikrotikSecurityGate><AdminPackagesPage /></MikrotikSecurityGate>;
const AdminBandwidth = () => <MikrotikSecurityGate><AdminBandwidthPage /></MikrotikSecurityGate>;
const AdminActiveSessions = () => <MikrotikSecurityGate><AdminActiveSessionsPage /></MikrotikSecurityGate>;
const RouterManagement = () => <MikrotikSecurityGate><RouterManagementPage /></MikrotikSecurityGate>;

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <PwaInstallPrompt />
        <Routes>
          <Route path="/" element={<Navigate to="/login" replace />} />

          {/* Guest checkout — no account, no layout/sidebar. Entry via
              an admin-pasted link per router on the MikroTik login
              page: /portal?router=5&login-url=$(link-login-only)&mac=$(mac) */}
          <Route path="/portal" element={<Portal />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />

          {/* Customer */}
          <Route path="/login" element={<CustomerLogin />} />
          <Route path="/register" element={<CustomerRegister />} />
          <Route element={<ProtectedRoute requiredRole="customer" />}>
            <Route element={<CustomerLayout />}>
              <Route path="/dashboard" element={<CustomerDashboard />} />
              <Route path="/vouchers/buy" element={<Navigate to="/buy" replace />} />
              <Route path="/payment/:reference" element={<PaymentPage />} />
              <Route path="/my-vouchers" element={<MyVouchers />} />
              <Route path="/buy" element={<BuyInternet />} />
              <Route path="/redeem" element={<RedeemVoucher />} />
              {/* /subscription route retired — purchases page covers this now */}
              <Route path="/subscription" element={<Navigate to="/my-vouchers" replace />} />
              <Route path="/payments" element={<CustomerPayments />} />
              <Route path="/profile" element={<CustomerProfile />} />
              <Route path="/complaints" element={<CustomerComplaints />} />
              <Route path="/free-trial" element={<CustomerFreeTrial />} />
              <Route path="/payment/callback" element={<PaymentCallback />} />
            </Route>
          </Route>

          {/* Admin */}
          <Route path='/admin/router-management' element={<ProtectedRoute requiredRole='admin' />}>
            <Route element={<AdminLayout />}><Route index element={<RouterManagement />} /></Route>
          </Route>
          <Route path="/admin/login" element={<AdminLogin />} />
          <Route element={<ProtectedRoute requiredRole="admin" />}>
            <Route element={<AdminLayout />}>
              <Route
                path="/admin/dashboard"
                element={
                  <Suspense fallback={<p className="text-sm text-slate-400">Loading…</p>}>
                    <AdminDashboard />
                  </Suspense>
                }
              />
              <Route path="/admin/routers" element={<AdminRouters />} />
              <Route path="/admin/customers" element={<AdminCustomers />} />
              <Route path="/admin/packages" element={<AdminPackages />} />
              <Route path="/admin/purchases" element={<AdminPurchases />} />
              {/* Old /admin/subscriptions redirects to unified page */}
              <Route path="/admin/subscriptions" element={<Navigate to="/admin/purchases" replace />} />
              <Route path="/admin/payments" element={<AdminPayments />} />
              <Route path="/admin/accounting" element={<AccountingHistory />} />
              <Route path="/admin/vouchers" element={<AdminVouchers />} />
              <Route path="/admin/logs" element={<AdminLogs />} />
              <Route path="/admin/settings" element={<AdminSettings />} />
              <Route path="/admin/free-trials" element={<AdminFreeTrials />} />
              <Route path="/admin/complaints" element={<AdminComplaints />} />
              <Route path="/admin/bandwidth" element={<AdminBandwidth />} />
              <Route path="/admin/assign-package" element={<AdminAssignPackage />} />
              <Route path="/admin/active-sessions" element={<AdminActiveSessions />} />
            </Route>
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;
