import { canPrepareConnection } from './connectionState.js';

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
      && canPrepareConnection(connectionStatus, currentCheckComplete),
  );
}

export function shouldRetryCurrentConnectionCheck({ requested, status, attempts }) {
  return Boolean(requested && status === 'unknown' && attempts < 15);
}
