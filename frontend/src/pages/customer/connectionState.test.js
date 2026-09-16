import test from 'node:test';
import assert from 'node:assert/strict';
import { canPrepareConnection, connectionControl } from './connectionState.js';

test('active renders the connected indicator', () => {
  assert.equal(connectionControl('active', true, true).label, '✓ Connected to WiFi');
});

test('connecting renders a disabled button', () => {
  const control = connectionControl('connecting', true, true);
  assert.equal(control.label, 'Connecting...');
  assert.equal(control.disabled, true);
});

test('disconnected with portal context renders connect', () => {
  assert.equal(connectionControl('', true, true).label, 'Connect to WiFi');
});

test('a restored active session cannot prepare a replacement', () => {
  assert.equal(canPrepareConnection('active', true), false);
  assert.equal(connectionControl('active', true, true).kind, 'connected');
});

test('prepare waits for current-session restoration', () => {
  assert.equal(canPrepareConnection('', false), false);
  assert.equal(canPrepareConnection('', true), true);
});
