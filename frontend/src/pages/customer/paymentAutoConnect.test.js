import test from 'node:test';
import assert from 'node:assert/strict';
import {
  canBeginAutoConnect,
  paymentSuccessDestination,
  shouldPollProvisioning,
} from './paymentAutoConnect.js';

test('verified Paystack payment from hotspot enters dashboard auto-connect flow', () => {
  assert.equal(paymentSuccessDestination(true), '/dashboard?auto_connect=1');
});

test('payment outside hotspot opens dashboard without pretending it can auto-login', () => {
  assert.equal(paymentSuccessDestination(false), '/dashboard');
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
