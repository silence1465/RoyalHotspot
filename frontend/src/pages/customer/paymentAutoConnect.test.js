import test from 'node:test';
import assert from 'node:assert/strict';
import {
  canBeginAutoConnect,
  paymentSuccessDestination,
  preservePaystackPortalContext,
  restorePaystackPortalContext,
  shouldRetryCurrentConnectionCheck,
  shouldRetryDashboardLoad,
  shouldPollProvisioning,
} from './paymentAutoConnect.js';

function memoryStorage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

test('verified Paystack payment from hotspot enters dashboard auto-connect flow', () => {
  assert.equal(paymentSuccessDestination(true), '/dashboard?auto_connect=1');
});

test('payment outside hotspot opens dashboard without pretending it can auto-login', () => {
  assert.equal(paymentSuccessDestination(false), '/dashboard');
});

test('Paystack redirect restores hotspot context and enters auto-connect flow', () => {
  const beforeRedirect = memoryStorage({
    guest_login_url: 'http://10.5.50.1/login',
    portal_router_id: '7',
    guest_mac: 'AA:BB:CC:DD:EE:FF',
    guest_ip: '10.5.50.22',
  });
  const durable = memoryStorage();
  const afterRedirect = memoryStorage();

  assert.equal(preservePaystackPortalContext(beforeRedirect, durable, 1000), true);
  assert.equal(restorePaystackPortalContext(durable, afterRedirect, 2000), true);
  assert.equal(afterRedirect.getItem('guest_login_url'), 'http://10.5.50.1/login');
  assert.equal(afterRedirect.getItem('portal_router_id'), '7');
  assert.equal(paymentSuccessDestination(Boolean(afterRedirect.getItem('guest_login_url'))), '/dashboard?auto_connect=1');
});

test('stale Paystack portal context cannot trigger an automatic login', () => {
  const beforeRedirect = memoryStorage({ guest_login_url: 'http://10.5.50.1/login' });
  const durable = memoryStorage();
  const afterRedirect = memoryStorage();

  preservePaystackPortalContext(beforeRedirect, durable, 1000);
  assert.equal(restorePaystackPortalContext(durable, afterRedirect, 31 * 60 * 1000), false);
  assert.equal(afterRedirect.getItem('guest_login_url'), null);
});

test('dummy delayed MikroTik provisioning is polled while credentials are absent', () => {
  assert.equal(shouldPollProvisioning({
    requested: true,
    hasPortalContext: true,
    hasPurchase: true,
    hasCredentials: false,
    attempts: 4,
  }), true);
});

test('dummy provisioned credentials trigger browser-side hotspot login once ready', () => {
  assert.equal(canBeginAutoConnect({
    requested: true,
    hasPortalContext: true,
    hasPurchase: true,
    hasCredentials: true,
    connectionStatus: '',
    currentCheckComplete: true,
  }), true);

  assert.equal(canBeginAutoConnect({
    requested: true,
    hasPortalContext: true,
    hasPurchase: true,
    hasCredentials: true,
    connectionStatus: 'provisioning',
    currentCheckComplete: true,
  }), true);
});

test('active or unknown connection cannot start a duplicate hotspot login', () => {
  const input = {
    requested: true,
    hasPortalContext: true,
    hasPurchase: true,
    hasCredentials: true,
    currentCheckComplete: true,
  };
  assert.equal(canBeginAutoConnect({ ...input, connectionStatus: 'active' }), false);
  assert.equal(canBeginAutoConnect({ ...input, connectionStatus: 'unknown' }), false);
});

test('missing portal context and provisioning timeout stop automatic connection', () => {
  assert.equal(shouldPollProvisioning({
    requested: true,
    hasPortalContext: false,
    hasPurchase: true,
    hasCredentials: false,
    attempts: 1,
  }), false);
  assert.equal(shouldPollProvisioning({
    requested: true,
    hasPortalContext: true,
    hasPurchase: true,
    hasCredentials: false,
    attempts: 30,
  }), false);
});

test('temporary router uncertainty is retried during post-payment auto-connect', () => {
  assert.equal(shouldRetryCurrentConnectionCheck({
    requested: true,
    status: 'unknown',
    attempts: 1,
  }), true);
  assert.equal(shouldRetryCurrentConnectionCheck({
    requested: true,
    status: 'disconnected',
    attempts: 1,
  }), false);
  assert.equal(shouldRetryCurrentConnectionCheck({
    requested: true,
    status: 'unknown',
    attempts: 15,
  }), false);
});

test('temporary dashboard loading failure retries only during auto-connect', () => {
  assert.equal(shouldRetryDashboardLoad({ requested: true, attempts: 1 }), true);
  assert.equal(shouldRetryDashboardLoad({ requested: true, attempts: 6 }), false);
  assert.equal(shouldRetryDashboardLoad({ requested: false, attempts: 1 }), false);
});
