const BASE = process.env.HASSAL_API ?? 'http://127.0.0.1:8000/api/v1';

export async function api(method, path, { token, org, body, headers = {} } = {}) {
  const h = { Accept: 'application/json', ...headers };
  if (token) h.Authorization = `Bearer ${token}`;
  if (org) h['X-Organization-Id'] = String(org);
  let payload;
  if (body !== undefined) { h['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
  const res = await fetch(`${BASE}${path}`, { method, headers: h, body: payload });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { raw: text.slice(0, 300) }; }
  return { status: res.status, ok: res.ok, json };
}

export async function login(email, password) {
  const r = await api('POST', '/auth/login', { body: { login: email, password, device_name: 'live-cycle-check' } });
  if (!r.ok) throw new Error(`login ${email} -> ${r.status} ${JSON.stringify(r.json).slice(0, 300)}`);
  const d = r.json.data;
  return { email, token: d.token, user: d.user, organizations: d.organizations ?? [], active: d.active_organization };
}

export const CODE = process.env.HASSAL_SANDBOX_PASSWORD ?? 'HassalSandbox##2026';
