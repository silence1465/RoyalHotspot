export function connectionControl(status, hasPortalContext, accountReady) {
  if (status === 'active') {
    return { kind: 'connected', label: '✓ Connected to WiFi', disabled: true };
  }

  if (status === 'connecting' || status === 'checking' || status === 'provisioning') {
    return { kind: 'connecting', label: 'Connecting...', disabled: true };
  }

  if (hasPortalContext && accountReady && status !== 'unknown') {
    return { kind: 'connect', label: 'Connect to WiFi', disabled: false };
  }

  return { kind: 'hidden', label: '', disabled: true };
}

export function canPrepareConnection(status, currentCheckComplete) {
  return currentCheckComplete && !['active', 'connecting', 'checking', 'provisioning', 'unknown'].includes(status);
}
