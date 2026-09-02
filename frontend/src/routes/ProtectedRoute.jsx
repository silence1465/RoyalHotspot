import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * Guards a route group by required role. Usage:
 *   <Route element={<ProtectedRoute requiredRole="admin" />}>
 *     <Route path="/admin/*" ... />
 *   </Route>
 */
export default function ProtectedRoute({ requiredRole }) {
  const { role, initializing } = useAuth();
  const token = sessionStorage.getItem('auth_token') || localStorage.getItem('auth_token');

  // Avoid redirecting before the /me rehydration call (see AuthContext)
  // has had a chance to resolve on a fresh page load.
  if (initializing) {
    return (
      <div className="min-h-screen flex items-center justify-center text-slate-400 text-sm">
        Loading…
      </div>
    );
  }

  if (!token || role !== requiredRole) {
    const loginPath = requiredRole === 'admin' ? '/admin/login' : '/login';
    return <Navigate to={loginPath} replace />;
  }

  return <Outlet />;
}
