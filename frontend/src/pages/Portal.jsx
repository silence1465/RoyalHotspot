import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Wifi, User, UserPlus } from 'lucide-react';
import { useAuth } from '../context/AuthContext';

/**
 * The actual landing page a device sees when it connects to a hotspot
 * with the guest portal set up (see Step 6 — MikrotikService::
 * fetchLoginPage()). RouterOS's login.html on the router itself just
 * redirects here immediately, carrying $(link-login-only)/$(mac)/$(ip)
 * as query params.
 *
 * This didn't exist when Step 6 was first built — that redirect went
 * straight to /guest/buy, which meant a returning customer arriving at
 * the hotspot had no way to use their existing account, only ever
 * seeing the guest checkout flow. This page is the actual fork: guest
 * checkout, or log in and use "Connect to WiFi" from the dashboard.
 */
export default function Portal() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { role, initializing } = useAuth();
  const routerId = searchParams.get('router');
  const isIos = /iPad|iPhone|iPod/i.test(navigator.userAgent);
  const [showIosHelp, setShowIosHelp] = useState(false);

  useEffect(() => {
    // Same capture technique as GuestBuy.jsx — held in sessionStorage so
    // it survives navigating to /login and back, since the actual
    // auto-login submission can only happen once we know which purchase
    // (or voucher) to use, several steps later.
    const loginUrl = searchParams.get('login-url');
    const mac = searchParams.get('mac');
    const ip = searchParams.get('ip');
    if (loginUrl) sessionStorage.setItem('guest_login_url', loginUrl);
    if (mac) sessionStorage.setItem('guest_mac', mac);
    if (ip) sessionStorage.setItem('guest_ip', ip);
    if (routerId) sessionStorage.setItem('portal_router_id', routerId);
  }, [searchParams, routerId]);

  useEffect(() => {
    if (!initializing && role === 'customer' && routerId && searchParams.get('login-url')) {
      navigate('/dashboard?auto_connect=1', { replace: true });
    }
  }, [initializing, navigate, role, routerId, searchParams]);

  if (initializing || role === 'customer') {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
        <div className="text-center">
          <Wifi className="h-10 w-10 text-indigo-600 mx-auto mb-3 animate-pulse" />
          <p className="text-sm font-medium text-slate-700">Recognizing your account and connecting...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
      <div className="max-w-sm w-full">
        <div className="text-center mb-8">
          <Wifi className="h-10 w-10 text-indigo-600 mx-auto mb-3" />
          <h1 className="text-xl font-semibold text-slate-900">Welcome</h1>
          <p className="text-slate-500 text-sm mt-1">Sign in or create an account to buy and connect.</p>
        </div>

        {isIos && (
          <div className="mb-5 text-center">
            <button
              type="button"
              onClick={() => setShowIosHelp((shown) => !shown)}
              className="text-sm font-medium text-indigo-600 underline underline-offset-2"
            >
              {showIosHelp ? 'Hide iPhone payment instructions' : 'Using an iPhone? See payment instructions'}
            </button>
            {showIosHelp && (
              <div className="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                <ol className="text-xs text-indigo-800 text-left space-y-1 list-decimal list-inside">
                  <li>Log in or create your account here.</li>
                  <li>Choose a package, then open MoMo and pay.</li>
                  <li>If this page closes, reconnect to the WiFi.</li>
                  <li>Log in again and your paid package will be waiting.</li>
                </ol>
              </div>
            )}
          </div>
        )}

        <div className="space-y-3">
          <button
            onClick={() => navigate('/login')}
            className="w-full bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-left hover:border-indigo-300 flex items-center gap-3"
          >
            <User className="h-5 w-5 text-indigo-600 shrink-0" />
            <div>
              <p className="font-medium text-slate-900 text-sm">I have an account</p>
              <p className="text-xs text-slate-400">Log in and connect instantly</p>
            </div>
          </button>

          <button
            onClick={() => navigate('/register')}
            className="w-full bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-left hover:border-indigo-300 flex items-center gap-3"
          >
            <UserPlus className="h-5 w-5 text-indigo-600 shrink-0" />
            <div>
              <p className="font-medium text-slate-900 text-sm">Create an account</p>
              <p className="text-xs text-slate-400">Register once so every purchase stays linked to you</p>
            </div>
          </button>

        </div>
      </div>
    </div>
  );
}
