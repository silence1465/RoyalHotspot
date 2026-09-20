import { canPrepareConnection } from './connectionState.js';

const PORTAL_CONTEXT_KEYS = ['guest_login_url', 'portal_router_id', 'guest_mac', 'guest_ip'];
const PAYSTACK_CONTEXT_PREFIX = 'paystack_portal_';
const PAYSTACK_CONTEXT_SAVED_AT = `${PAYSTACK_CONTEXT_PREFIX}saved_at`;
const PAYSTACK_CONTEXT_TTL_MS = 30 * 60 * 1000;

export function preservePaystackPortalContext(sessionStore, durableStore, now = Date.now()) {
  try {
    if (!sessionStore?.getItem('guest_login_url')) return false;

    PORTAL_CONTEXT_KEYS.forEach((key) => {
      const value = sessionStore.getItem(key);
      if (value) durableStore.setItem(`${PAYSTACK_CONTEXT_PREFIX}${key}`, value);
    });
    durableStore.setItem(PAYSTACK_CONTEXT_SAVED_AT, String(now));
    return true;
  } catch {
    return false;
  }
}

export function restorePaystackPortalContext(durableStore, sessionStore, now = Date.now()) {
  try {
    if (sessionStore?.getItem('guest_login_url')) {
      clearPaystackPortalContext(durableStore);
      return true;
    }

    const savedAt = Number(durableStore?.getItem(PAYSTACK_CONTEXT_SAVED_AT));
    const loginUrl = durableStore?.getItem(`${PAYSTACK_CONTEXT_PREFIX}guest_login_url`);
    const isFresh = savedAt > 0 && now - savedAt <= PAYSTACK_CONTEXT_TTL_MS;

    if (!loginUrl || !isFresh) {
      clearPaystackPortalContext(durableStore);
      return false;
    }

    PORTAL_CONTEXT_KEYS.forEach((key) => {
      const value = durableStore.getItem(`${PAYSTACK_CONTEXT_PREFIX}${key}`);
      if (value) sessionStore.setItem(key, value);
    });
    clearPaystackPortalContext(durableStore);
    return true;
  } catch {
    return false;
  }
}

function clearPaystackPortalContext(durableStore) {
  PORTAL_CONTEXT_KEYS.forEach((key) => durableStore?.removeItem(`${PAYSTACK_CONTEXT_PREFIX}${key}`));
  durableStore?.removeItem(PAYSTACK_CONTEXT_SAVED_AT);
}

export function paymentSuccessDestination(hasPortalContext) {
  return hasPortalContext ? '/dashboard?auto_connect=1' : '/dashboard';
}

export function shouldPollProvisioning({ requested, hasPortalContext, hasPurchase, hasCredentials, attempts }) {
  return Boolean(requested && hasPortalContext && hasPurchase && !hasCredentials && attempts < 30);
}

export function canBeginAutoConnect({
  requested,
  hasPortalContext,
  hasPurchase,
  hasCredentials,
  connectionStatus,
  currentCheckComplete,
}) {
  return Boolean(
    requested
      && hasPortalContext
      && hasPurchase
      && hasCredentials
      && (connectionStatus === 'provisioning' || canPrepareConnection(connectionStatus, currentCheckComplete)),
  );
}

export function shouldRetryCurrentConnectionCheck({ requested, status, attempts }) {
  return Boolean(requested && status === 'unknown' && attempts < 15);
}

export function shouldRetryDashboardLoad({ requested, attempts }) {
  return Boolean(requested && attempts < 6);
}
