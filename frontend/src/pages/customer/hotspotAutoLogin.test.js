import test from 'node:test';
import assert from 'node:assert/strict';
import { prepareAndSubmitHotspotLogin } from './hotspotAutoLogin.js';

function memoryStorage(values) {
  const entries = new Map(Object.entries(values));
  return {
    getItem: (key) => entries.get(key) ?? null,
    setItem: (key, value) => entries.set(key, String(value)),
    removeItem: (key) => entries.delete(key),
  };
}

function fakeDocument() {
  const appended = [];
  return {
    appended,
    body: { appendChild: (element) => appended.push(element) },
    createElement: (tag) => ({
      tag,
      children: [],
      appendChild(element) { this.children.push(element); },
      submit() { this.submitted = true; },
    }),
  };
}

test('verified purchase submits secure MikroTik login without dashboard refresh', async () => {
  const storage = memoryStorage({
    guest_login_url: 'http://10.5.50.1/login',
    portal_router_id: '7',
    guest_mac: 'AA:BB:CC:DD:EE:FF',
    guest_ip: '10.5.50.22',
  });
  const documentRef = fakeDocument();
  const api = { post: async () => ({ data: {
    session_id: 'session-123', status: 'connecting', login_url: 'http://10.5.50.1/login',
    username: 'customer1', password: 'secret',
  } }) };

  const result = await prepareAndSubmitHotspotLogin(api, {
    storage, documentRef, origin: 'https://wifi.example.com',
  });

  assert.equal(result.submitted, true);
  assert.equal(storage.getItem('hotspot_pending_session'), 'session-123');
  assert.equal(documentRef.appended[0].submitted, true);
  assert.deepEqual(
    Object.fromEntries(documentRef.appended[0].children.map((input) => [input.name, input.value])),
    { username: 'customer1', password: 'secret', dst: 'https://wifi.example.com/dashboard?confirm_session=session-123' },
  );
});

test('missing portal context does not submit a hotspot form', async () => {
  const documentRef = fakeDocument();
  const result = await prepareAndSubmitHotspotLogin({ post: async () => assert.fail() }, {
    storage: memoryStorage({}), documentRef, origin: 'https://wifi.example.com',
  });
  assert.equal(result.available, false);
  assert.equal(documentRef.appended.length, 0);
});
