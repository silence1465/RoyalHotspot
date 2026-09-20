export async function prepareAndSubmitHotspotLogin(apiClient, {
  storage = window.sessionStorage,
  documentRef = document,
  origin = window.location.origin,
} = {}) {
  const loginUrl = storage.getItem('guest_login_url');
  const routerId = storage.getItem('portal_router_id');
  if (!loginUrl || !routerId) return { available: false, submitted: false };

  const { data: prepared } = await apiClient.post('/customer/hotspot/sessions/prepare', {
    router_id: Number(routerId),
    login_url: loginUrl,
    mac_address: storage.getItem('guest_mac') || null,
    ip_address: storage.getItem('guest_ip') || null,
  });

  if (prepared.connected === true || prepared.status === 'active') {
    storage.removeItem('hotspot_pending_session');
    return { available: true, submitted: false, connected: true };
  }

  storage.setItem('hotspot_pending_session', prepared.session_id);
  const form = documentRef.createElement('form');
  form.method = 'POST';
  form.action = prepared.login_url;
  const fields = {
    username: prepared.username,
    password: prepared.password,
    dst: `${origin}/dashboard?confirm_session=${encodeURIComponent(prepared.session_id)}`,
  };
  Object.entries(fields).forEach(([name, value]) => {
    const input = documentRef.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  });
  documentRef.body.appendChild(form);
  form.submit();

  return { available: true, submitted: true, connected: false };
}
