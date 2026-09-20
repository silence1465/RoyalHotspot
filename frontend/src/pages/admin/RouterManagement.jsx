import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity, Boxes, ChevronDown, Clipboard, Database, HardDrive, Layers, ListTree,
  Radio, RefreshCw, Router as RouterIcon, ScrollText, Server, Shield, TerminalSquare, Users, Wifi,
} from 'lucide-react';
import api from '../../services/api';

/**
 * RouterOS console. Every section reads live from the router through
 * MikrotikManagementController — nothing here is cached server-side, so a
 * slow router shows as a slow panel, not stale numbers.
 *
 * Presentation rules used throughout, so the twelve sections read as one
 * screen rather than twelve tables:
 *   - RouterOS booleans ("true"/"false"/"yes") become a coloured pill, never
 *     the raw string.
 *   - Anything technical — MAC, IP, rate, byte count, uptime — is set in the
 *     system mono stack and right-aligned when numeric, so columns line up.
 *   - Byte counts and bit rates are humanised; the router's raw values are
 *     unreadable at a glance (184238472934).
 * The mono stack is deliberately `font-mono` (ui-monospace/Consolas) rather
 * than a webfont: index.html is shared with the captive-portal pages, and
 * fonts.googleapis.com is not in the hotspot walled garden.
 */

// ── formatters ──────────────────────────────────────────────────────

const isTrue = (v) => v === true || v === 'true' || v === 'yes';

const num = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};

function fmtBytes(v) {
  const n = num(v);
  if (n === null) return '—';
  if (n < 1024) return `${n} B`;
  const units = ['KiB', 'MiB', 'GiB', 'TiB'];
  let value = n / 1024;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
  return `${value.toFixed(value >= 100 ? 0 : 1)} ${units[i]}`;
}

function fmtBits(v) {
  const n = num(v);
  if (n === null) return '—';
  if (n < 1000) return `${n} bps`;
  const units = ['Kbps', 'Mbps', 'Gbps'];
  let value = n / 1000;
  let i = 0;
  while (value >= 1000 && i < units.length - 1) { value /= 1000; i++; }
  return `${value.toFixed(value >= 100 ? 0 : 2)} ${units[i]}`;
}

/** RouterOS returns uptime as "3w2d07h41m10s"; our own sessions API returns seconds. */
function fmtUptime(v) {
  if (v === null || v === undefined || v === '') return '—';
  const n = num(v);
  if (n === null) return String(v);
  const d = Math.floor(n / 86400);
  const h = Math.floor((n % 86400) / 3600);
  const m = Math.floor((n % 3600) / 60);
  return d > 0 ? `${d}d ${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}` : `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function fmtDateTime(v) {
  if (!v) return '—';
  const date = new Date(v);
  return Number.isNaN(date.getTime())
    ? String(v)
    : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const plain = (v) => (v === null || v === undefined || v === '' ? '—' : String(v));

// ── section definitions ─────────────────────────────────────────────

const GROUPS = [
  {
    label: 'Monitor',
    items: [
      { id: 'overview', label: 'Overview', icon: Activity },
      { id: 'sessions', label: 'Sessions', icon: Radio, count: 'sessions' },
      { id: 'router-logs', label: 'Router logs', icon: ScrollText, count: 'router-logs' },
      { id: 'system-information', label: 'System info', icon: RouterIcon },
      { id: 'diagnostics', label: 'Diagnostics', icon: Activity },
      { id: 'terminal', label: 'Terminal', icon: TerminalSquare },
    ],
  },
  {
    label: 'Hotspot',
    items: [
      { id: 'users', label: 'Users', icon: Users, count: 'users' },
      { id: 'profiles', label: 'Profiles', icon: Layers, count: 'profiles' },
      { id: 'hosts', label: 'Hosts', icon: Wifi, count: 'hosts' },
      { id: 'bindings', label: 'IP bindings', icon: Shield, count: 'bindings' },
      { id: 'hotspot-servers', label: 'Hotspot servers', icon: Server, count: 'hotspot-servers' },
      { id: 'walled-garden', label: 'Walled garden', icon: Shield, count: 'walled-garden' },
      { id: 'hotspot-cookies', label: 'Cookies', icon: Clipboard, count: 'hotspot-cookies' },
    ],
  },
  {
    label: 'Network',
    items: [
      { id: 'dhcp-servers', label: 'DHCP servers', icon: Server, count: 'dhcp-servers' },
      { id: 'dhcp-leases', label: 'DHCP leases', icon: ListTree, count: 'dhcp-leases' },
      { id: 'queues', label: 'Queues', icon: Boxes, count: 'queues' },
      { id: 'address-lists', label: 'Address lists', icon: Database, count: 'address-lists' },
      { id: 'ip-pools', label: 'IP pools', icon: Layers, count: 'ip-pools' },
      { id: 'dhcp-networks', label: 'DHCP networks', icon: ListTree, count: 'dhcp-networks' },
      { id: 'arp', label: 'ARP table', icon: Radio, count: 'arp' },
      { id: 'ip-addresses', label: 'IP addresses', icon: Wifi, count: 'ip-addresses' },
      { id: 'dns-cache', label: 'DNS cache', icon: Database, count: 'dns-cache' },
      { id: 'dns-settings', label: 'DNS settings', icon: Server },
      { id: 'wireless-clients', label: 'Wireless clients', icon: Wifi, count: 'wireless-clients' },
      { id: 'bridges', label: 'Bridges', icon: Layers, count: 'bridges' },
      { id: 'bridge-ports', label: 'Bridge ports', icon: ListTree, count: 'bridge-ports' },
      { id: 'bridge-hosts', label: 'Bridge hosts', icon: Radio, count: 'bridge-hosts' },
    ],
  },
  {
    label: 'System',
    items: [{ id: 'backups', label: 'Backups', icon: HardDrive }],
  },
];

const ALL_TABS = GROUPS.flatMap((g) => g.items.map((i) => i.id));
const EXPANDED = ['hotspot-servers', 'walled-garden', 'ip-pools', 'dhcp-networks', 'hotspot-cookies', 'arp', 'ip-addresses', 'dns-cache', 'dns-settings', 'wireless-clients'];
const EDITABLE = ['users', 'bindings', 'profiles', 'queues', 'address-lists', 'dhcp-leases', 'hotspot-servers', 'walled-garden', 'ip-pools', 'dhcp-networks', 'bridges', 'bridge-ports'];
const CREATABLE = EDITABLE.filter((item) => item !== 'dhcp-leases');
const DELETABLE = [...EDITABLE, 'backups', 'hotspot-cookies'];

const TONE = {
  ok: 'bg-emerald-50 text-emerald-700',
  down: 'bg-rose-50 text-rose-700',
  warn: 'bg-amber-50 text-amber-700',
  mute: 'bg-slate-100 text-slate-600',
  accent: 'bg-indigo-50 text-indigo-700',
};

function Pill({ tone = 'mute', children }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ${TONE[tone]}`}>
      {children}
    </span>
  );
}

const statePill = (v, on = 'enabled', off = 'disabled') =>
  isTrue(v) ? <Pill tone="down">{off}</Pill> : <Pill tone="ok">{on}</Pill>;

const bindingTone = { bypassed: 'ok', blocked: 'down', regular: 'mute' };

const mono = (v) => <span className="font-mono text-[12px]">{plain(v)}</span>;
const dim = (v) => <span className="text-slate-400">{plain(v)}</span>;

/**
 * Per-section columns. `align: 'right'` puts the value in the tabular-nums
 * mono column; `render` gets (value, row).
 */
const COLUMNS = {
  sessions: [
    { key: 'username', label: 'User', render: (v) => <span className="font-mono text-[12px] font-semibold">{plain(v)}</span> },
    { key: 'address', label: 'Address', render: mono },
    { key: 'package_name', label: 'Package', render: (v) => (v ? <Pill tone="accent">{v}</Pill> : dim('—')) },
    { key: 'started_at', label: 'Started', render: (v) => mono(fmtDateTime(v)) },
    { key: 'expires_at', label: 'Expires', render: (v) => mono(fmtDateTime(v)) },
    { key: 'uptime', label: 'Uptime', render: (v) => mono(fmtUptime(v)) },
    { key: 'bytes_in', label: 'In', align: 'right', render: fmtBytes },
    { key: 'bytes_out', label: 'Out', align: 'right', render: fmtBytes },
  ],
  users: [
    { key: 'name', label: 'User', render: (v) => <span className="font-mono text-[12px] font-semibold">{plain(v)}</span> },
    { key: 'profile', label: 'Profile', render: (v) => (v ? <Pill tone="accent">{v}</Pill> : dim('default')) },
    { key: 'disabled', label: 'State', render: (v) => statePill(v) },
    { key: 'uptime', label: 'Uptime', render: (v) => mono(v || '—') },
    { key: 'bytes-in', label: 'In', align: 'right', render: fmtBytes },
    { key: 'bytes-out', label: 'Out', align: 'right', render: fmtBytes },
  ],
  hosts: [
    { key: 'mac-address', label: 'MAC', render: mono },
    { key: 'address', label: 'Address', render: mono },
    { key: 'to-address', label: 'Translated to', render: mono },
    { key: 'server', label: 'Server', render: plain },
    { key: 'uptime', label: 'Uptime', render: (v) => mono(v || '—') },
  ],
  bindings: [
    { key: 'mac-address', label: 'MAC', render: mono },
    { key: 'address', label: 'Address', render: mono },
    { key: 'type', label: 'Type', render: (v) => <Pill tone={bindingTone[v] || 'mute'}>{plain(v)}</Pill> },
    { key: 'disabled', label: 'State', render: (v) => statePill(v, 'active', 'disabled') },
    { key: 'comment', label: 'Comment', render: dim },
  ],
  profiles: [
    { key: 'name', label: 'Profile', render: (v) => <span className="font-semibold">{plain(v)}</span> },
    { key: 'rate-limit', label: 'Rate limit', render: (v) => (v ? mono(v) : dim('unlimited')) },
    { key: 'address-pool', label: 'Address pool', render: (v) => (v && v !== 'none' ? mono(v) : dim('none')) },
    { key: 'shared-users', label: 'Shared', align: 'right', render: plain },
  ],
  'dhcp-servers': [
    { key: 'name', label: 'Server', render: (v) => <span className="font-semibold">{plain(v)}</span> },
    { key: 'interface', label: 'Interface', render: mono },
    { key: 'address-pool', label: 'Pool', render: mono },
    { key: 'lease-time', label: 'Lease time', render: (v) => mono(v) },
    { key: 'disabled', label: 'State', render: (v) => statePill(v, 'running', 'disabled') },
  ],
  'dhcp-leases': [
    { key: 'address', label: 'Address', render: (v) => <span className="font-mono text-[12px] font-semibold">{plain(v)}</span> },
    { key: 'mac-address', label: 'MAC', render: mono },
    { key: 'host-name', label: 'Hostname', render: (v) => (v ? plain(v) : dim('—')) },
    { key: 'server', label: 'Server', render: plain },
    { key: 'status', label: 'Status', render: (v) => <Pill tone={v === 'bound' ? 'ok' : 'mute'}>{plain(v)}</Pill> },
    { key: 'dynamic', label: 'Kind', render: (v) => <Pill tone={isTrue(v) ? 'mute' : 'accent'}>{isTrue(v) ? 'dynamic' : 'static'}</Pill> },
  ],
  queues: [
    { key: 'name', label: 'Queue', render: (v) => <span className="font-semibold">{plain(v)}</span> },
    { key: 'target', label: 'Target', render: mono },
    { key: 'max-limit', label: 'Max limit', render: (v) => (v ? mono(v) : dim('unlimited')) },
    { key: 'rate', label: 'Current rate', render: (v) => (v ? mono(v) : dim('idle')) },
    { key: 'disabled', label: 'State', render: (v) => statePill(v, 'active', 'disabled') },
  ],
  'router-logs': [
    { key: 'time', label: 'Time', render: (v) => mono(v) },
    { key: 'topics', label: 'Topics', render: (v) => (
      <span className="flex flex-wrap gap-1">
        {String(v || '').split(',').filter(Boolean).map((t) => (
          <Pill key={t} tone={/error|critical|warning/.test(t) ? 'warn' : 'mute'}>{t.trim()}</Pill>
        ))}
      </span>
    ) },
    { key: 'message', label: 'Message', wrap: true, render: (v) => <span className="font-mono text-[12px]">{plain(v)}</span> },
  ],
  'address-lists': [
    { key: 'list', label: 'List', render: (v) => <Pill tone={/block|deny/.test(String(v)) ? 'down' : 'ok'}>{plain(v)}</Pill> },
    { key: 'address', label: 'Address', render: (v) => <span className="font-mono text-[12px] font-semibold">{plain(v)}</span> },
    { key: 'timeout', label: 'Timeout', render: (v) => (v ? mono(v) : dim('permanent')) },
    { key: 'dynamic', label: 'Kind', render: (v) => <Pill tone={isTrue(v) ? 'mute' : 'accent'}>{isTrue(v) ? 'dynamic' : 'static'}</Pill> },
    { key: 'comment', label: 'Comment', render: dim },
  ],
  backups: [
    { key: 'name', label: 'File', render: (v) => <span className="font-mono text-[12px] font-semibold">{plain(v)}</span> },
    { key: 'size', label: 'Size', align: 'right', render: fmtBytes },
    { key: 'creation-time', label: 'Created', render: (v) => mono(v) },
  ],
  'hotspot-servers': [
    { key: 'name', label: 'Server', render: mono }, { key: 'interface', label: 'Interface', render: mono },
    { key: 'address-pool', label: 'Pool', render: mono }, { key: 'profile', label: 'Profile', render: plain },
    { key: 'disabled', label: 'State', render: (v) => statePill(v) },
  ],
  'walled-garden': [
    { key: 'dst-host', label: 'Destination host', render: mono }, { key: 'comment', label: 'Comment', render: dim },
    { key: 'disabled', label: 'State', render: (v) => statePill(v) },
  ],
  'ip-pools': [
    { key: 'name', label: 'Pool', render: plain }, { key: 'ranges', label: 'Ranges', render: mono },
    { key: 'next-pool', label: 'Next pool', render: plain },
  ],
  'dhcp-networks': [
    { key: 'address', label: 'Network', render: mono }, { key: 'gateway', label: 'Gateway', render: mono },
    { key: 'dns-server', label: 'DNS servers', render: mono }, { key: 'domain', label: 'Domain', render: plain },
  ],
  'hotspot-cookies': [
    { key: 'user', label: 'User', render: plain }, { key: 'mac-address', label: 'MAC', render: mono },
    { key: 'expires-in', label: 'Expires', render: mono },
  ],
  arp: [
    { key: 'address', label: 'Address', render: mono }, { key: 'mac-address', label: 'MAC', render: mono },
    { key: 'interface', label: 'Interface', render: plain }, { key: 'status', label: 'Status', render: plain },
  ],
  'ip-addresses': [
    { key: 'address', label: 'Address', render: mono }, { key: 'network', label: 'Network', render: mono },
    { key: 'interface', label: 'Interface', render: plain }, { key: 'dynamic', label: 'Kind', render: plain },
  ],
  'dns-cache': [
    { key: 'name', label: 'Name', render: plain }, { key: 'type', label: 'Type', render: plain },
    { key: 'data', label: 'Data', render: mono }, { key: 'ttl', label: 'TTL', render: mono },
  ],
  'dns-settings': [
    { key: 'servers', label: 'Servers', render: mono }, { key: 'dynamic-servers', label: 'Dynamic servers', render: mono },
    { key: 'allow-remote-requests', label: 'Remote requests', render: plain }, { key: 'cache-size', label: 'Cache size', render: mono },
  ],
  'wireless-clients': [
    { key: 'mac-address', label: 'MAC', render: mono }, { key: 'interface', label: 'Interface', render: plain },
    { key: 'signal-strength', label: 'Signal', render: mono }, { key: 'tx-rate', label: 'TX rate', render: mono },
    { key: 'rx-rate', label: 'RX rate', render: mono }, { key: 'uptime', label: 'Uptime', render: mono },
  ],
  bridges: [
    { key: 'name', label: 'Bridge', render: (v) => <span className='font-semibold'>{plain(v)}</span> },
    { key: 'protocol-mode', label: 'Protocol', render: plain }, { key: 'vlan-filtering', label: 'VLAN filtering', render: plain },
    { key: 'running', label: 'Running', render: plain }, { key: 'disabled', label: 'State', render: (v) => statePill(v) },
  ],
  'bridge-ports': [
    { key: 'interface', label: 'Interface', render: mono }, { key: 'bridge', label: 'Bridge', render: plain },
    { key: 'pvid', label: 'PVID', render: mono }, { key: 'role', label: 'Role', render: plain },
    { key: 'hw-offload', label: 'HW offload', render: plain }, { key: 'dynamic', label: 'Dynamic', render: plain },
  ],
  'bridge-hosts': [
    { key: 'mac-address', label: 'MAC', render: mono }, { key: 'bridge', label: 'Bridge', render: plain },
    { key: 'on-interface', label: 'Interface', render: mono }, { key: 'vid', label: 'VLAN', render: mono },
    { key: 'dynamic', label: 'Dynamic', render: plain },
  ],
  interfaces: [
    { key: 'name', label: 'Interface', render: (v, row) => (
      <span className="flex items-center gap-2">
        <i className={`h-3 w-[3px] rounded-sm ${isTrue(row.running) ? 'bg-emerald-500' : 'bg-slate-300'}`} />
        <span className="font-mono text-[12px] font-semibold">{plain(v)}</span>
      </span>
    ) },
    { key: 'type', label: 'Type', render: dim },
    { key: 'running', label: 'State', render: (v, row) => (
      isTrue(row.disabled) ? <Pill tone="mute">disabled</Pill>
        : isTrue(v) ? <Pill tone="ok">running</Pill> : <Pill tone="down">down</Pill>
    ) },
    { key: 'rx-byte', label: 'RX', align: 'right', render: fmtBytes },
    { key: 'tx-byte', label: 'TX', align: 'right', render: fmtBytes },
    { key: 'comment', label: 'Comment', render: dim },
  ],
};

// ── canvas instruments ──────────────────────────────────────────────

/** Arc gauge, 0..1, coloured by threshold. */
function Gauge({ value, size = 56, stroke = 6, accent = false }) {
  const ref = useRef(null);

  useEffect(() => {
    const cv = ref.current;
    if (!cv) return;
    const dpr = window.devicePixelRatio || 1;
    cv.width = size * dpr;
    cv.height = size * dpr;
    cv.style.width = `${size}px`;
    cv.style.height = `${size}px`;

    const c = cv.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, size, size);

    const v = Math.max(0, Math.min(1, Number(value) || 0));
    const col = accent ? '#4f46e5' : v < 0.6 ? '#059669' : v < 0.85 ? '#d97706' : '#e11d48';
    const r = size / 2 - stroke / 2 - 1;
    const mid = size / 2;
    const a0 = Math.PI * 0.75;
    const a1 = Math.PI * 2.25;

    c.lineCap = 'round';
    c.lineWidth = stroke;
    c.strokeStyle = '#e2e8f0';
    c.beginPath();
    c.arc(mid, mid, r, a0, a1);
    c.stroke();

    c.strokeStyle = col;
    c.beginPath();
    c.arc(mid, mid, r, a0, a0 + (a1 - a0) * Math.max(v, 0.015));
    c.stroke();

    c.fillStyle = col;
    c.font = '700 13px ui-sans-serif, system-ui, sans-serif';
    c.textAlign = 'center';
    c.textBaseline = 'middle';
    c.fillText(`${Math.round(v * 100)}%`, mid, mid + 1);
  }, [value, size, stroke, accent]);

  return <canvas ref={ref} className="shrink-0" />;
}

/** Filled area sparkline over the traffic samples collected so far. */
function Sparkline({ points, color = '#059669', height = 32 }) {
  const ref = useRef(null);

  const draw = useCallback(() => {
    const cv = ref.current;
    if (!cv) return;
    const w = cv.clientWidth || 240;
    const dpr = window.devicePixelRatio || 1;
    cv.width = w * dpr;
    cv.height = height * dpr;
    const c = cv.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, w, height);

    if (!points || points.length < 2) return;
    const max = Math.max(...points) * 1.25 || 1;
    const step = w / (points.length - 1);
    const y = (v) => height - 3 - (v / max) * (height - 7);

    c.beginPath();
    c.moveTo(0, y(points[0]));
    points.forEach((p, i) => { if (i) c.lineTo(i * step, y(p)); });
    c.lineTo(w, height);
    c.lineTo(0, height);
    c.closePath();
    const g = c.createLinearGradient(0, 0, 0, height);
    g.addColorStop(0, `${color}38`);
    g.addColorStop(1, `${color}00`);
    c.fillStyle = g;
    c.fill();

    c.beginPath();
    c.moveTo(0, y(points[0]));
    points.forEach((p, i) => { if (i) c.lineTo(i * step, y(p)); });
    c.strokeStyle = color;
    c.lineWidth = 1.6;
    c.lineJoin = 'round';
    c.stroke();

    c.beginPath();
    c.arc(w - 1.5, y(points[points.length - 1]), 2.4, 0, Math.PI * 2);
    c.fillStyle = color;
    c.fill();
  }, [points, color, height]);

  useEffect(() => {
    draw();
    window.addEventListener('resize', draw);
    return () => window.removeEventListener('resize', draw);
  }, [draw]);

  return <canvas ref={ref} className="block w-full" style={{ height }} />;
}

// ── page ────────────────────────────────────────────────────────────

export default function RouterManagement() {
  const [routers, setRouters] = useState([]);
  const [routerId, setRouterId] = useState('');
  const [tab, setTab] = useState('overview');
  const [data, setData] = useState(null);
  const [counts, setCounts] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({});
  const [notice, setNotice] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [metadata, setMetadata] = useState({});

  // Traffic samples accumulate across the 10s overview polls — RouterOS only
  // reports an instantaneous rate, so the history is ours to keep.
  const history = useRef({});

  useEffect(() => {
    api.get('/admin/routers', { params: { per_page: 100 } }).then(({ data: response }) => {
      const rows = response.data || [];
      setRouters(rows);
      if (rows[0]) setRouterId(String(rows[0].id));
    });
  }, []);

  useEffect(() => { history.current = {}; }, [routerId]);

  const load = useCallback((silent = false) => {
    if (!routerId) return;
    if (!silent) { setData(null); setLoading(true); }
    setError('');

    if (['diagnostics', 'terminal'].includes(tab)) {
      setData([]);
      setLoading(false);
      return;
    }
    const request = tab === 'sessions'
      ? api.get('/admin/active-users', { params: { router_id: routerId } })
      : api.get(`/admin/router-management/${routerId}/${EXPANDED.includes(tab) ? 'modules/' : ''}${tab}`);

    request
      .then(({ data: response }) => {
        const payload = response.sessions ?? response.data ?? response;
        setData(payload);
        setMetadata({ bridges: response.bridges || [], interfaces: response.interfaces || [] });
        if (response.counts) setCounts((current) => ({
          ...current,
          ...response.counts,
          sessions: response.counts.active_sessions,
          users: response.counts.hotspot_users,
        }));
        if (Array.isArray(payload)) {
          setCounts((current) => ({ ...current, [tab]: payload.length }));
        }

        (response.traffic || []).forEach((row) => {
          const key = row.name;
          const series = history.current[key] || { rx: [], tx: [] };
          series.rx = [...series.rx, Number(row['rx-bits-per-second']) || 0].slice(-40);
          series.tx = [...series.tx, Number(row['tx-bits-per-second']) || 0].slice(-40);
          history.current[key] = series;
        });
      })
      .catch((requestError) =>
        setError(requestError.response?.data?.message || 'Could not load router data.'))
      .finally(() => setLoading(false));
  }, [routerId, tab]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (tab !== 'overview' || !routerId) return undefined;
    const timer = setInterval(() => load(true), 10000);
    return () => clearInterval(timer);
  }, [load, routerId, tab]);

  // Deliberately does NOT clear `notice`: a backup password is shown exactly
  // once and never stored, so navigating to another section must not throw it
  // away — it stays until explicitly dismissed.
  useEffect(() => { setForm({}); setEditingId(null); setFormOpen(false); }, [tab, routerId]);

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const path = `/admin/router-management/${routerId}/${EXPANDED.includes(tab) ? 'modules/' : ''}${tab}`;
      const response = editingId ? await api.patch(`${path}/${encodeURIComponent(editingId)}`, form) : await api.post(path, form);

      if (response.data.password) {
        setNotice({
          name: response.data.name,
          password: response.data.password,
          warning: response.data.warning,
        });
      }
      setForm({});
      setEditingId(null);
      setFormOpen(false);
      load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The router rejected this change.');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (item) => {
    const id = typeof item === 'object' ? item['.id'] : item;
    if (!window.confirm('Delete this item from the router?')) return;
    let confirmation;
    if (tab === 'bridge-ports') {
      confirmation = window.prompt(`Type ${item.interface} to confirm removing this port from ${item.bridge}.`);
      if (confirmation !== item.interface) {
        setError('Bridge-port removal cancelled: the interface name did not match.');
        return;
      }
    }
    setBusy(true);
    try {
      await api.delete(`/admin/router-management/${routerId}/${EXPANDED.includes(tab) ? 'modules/' : ''}${tab}/${encodeURIComponent(id)}`, { data: { confirmation } });
      load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Could not delete this item.');
    } finally {
      setBusy(false);
    }
  };

  const makeStatic = async (id) => {
    setBusy(true);
    try {
      await api.post(`/admin/router-management/${routerId}/dhcp-leases/${encodeURIComponent(id)}/make-static`);
      load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Could not make this lease static.');
    } finally {
      setBusy(false);
    }
  };

  const setBindingStatus = async (item, active) => {
    if (!active && !window.confirm('Deactivate this IP binding? It will stop applying to new hotspot connections.')) return;
    setBusy(true);
    setError('');
    try {
      await api.patch(
        `/admin/router-management/${routerId}/bindings/${encodeURIComponent(item['.id'])}/status`,
        { active },
      );
      load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || `Could not ${active ? 'activate' : 'deactivate'} this IP binding.`);
    } finally {
      setBusy(false);
    }
  };

  const edit = (row) => {
    let confirmation;
    if (tab === 'bridge-ports') {
      confirmation = window.prompt(`Type ${row.interface} to confirm changing this bridge-port assignment.`);
      if (confirmation !== row.interface) {
        setError('Bridge-port change cancelled: the interface name did not match.');
        return;
      }
    }
    setEditingId(row['.id']);
    setForm(tab === 'bridge-ports' ? {
      bridge: row.bridge || '',
      interface: row.interface || '',
      pvid: row.pvid || '',
      'path-cost': row['path-cost'] || '',
      edge: row.edge || 'auto',
      confirmation,
    } : { ...row, disabled: isTrue(row.disabled), confirmation });
    setError('');
    setFormOpen(true);
  };

  const diagnose = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const response = await api.post(`/admin/router-management/${routerId}/diagnostics`, form);
      setData(response.data.data || []);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Diagnostic failed.');
    } finally {
      setBusy(false);
    }
  };

  const runTerminal = async (command) => {
    const readOnly = /\s(?:print|monitor|get)(?:\s|$)/i.test(command)
      || /^\/(?:ping|tool(?:\/|\s+)traceroute)(?:\s|$)/i.test(command);
    let confirmation;
    if (!readOnly) {
      const approved = window.confirm(
        `Execute this router-changing command on ${router?.name || 'the router'}?\n\n${command}\n\nThis can interrupt service or make the router unreachable.`
      );
      if (!approved) return [];
      confirmation = 'EXECUTE';
    }
    setBusy(true);
    setError('');
    try {
      const response = await api.post(`/admin/router-management/${routerId}/terminal`, { command, confirmation });
      return response.data.data || [];
    } catch (requestError) {
      const message = requestError.response?.data?.message || 'The command could not be completed.';
      setError(message);
      throw new Error(message);
    } finally {
      setBusy(false);
    }
  };

  const rows = Array.isArray(data) ? data : [];
  const router = routers.find((r) => String(r.id) === String(routerId));
  const overview = tab === 'overview' && data && !Array.isArray(data) ? data : null;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Router Management</h1>
          <p className="text-sm text-slate-500">Live RouterOS status and controlled hotspot administration.</p>
        </div>
        <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
          <RouterIcon className="h-4 w-4 text-slate-400" />
          <select
            aria-label="Select router"
            value={routerId}
            onChange={(e) => setRouterId(e.target.value)}
            className="cursor-pointer border-0 bg-transparent text-sm font-semibold text-slate-800 focus:outline-none"
          >
            {routers.map((r) => (
              <option key={r.id} value={r.id}>{r.name} — {r.location || 'No location'}</option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => load()}
            className="ml-1 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
            title="Refresh"
          >
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      <div className="flex flex-col gap-5 lg:flex-row">
        <NavRail tab={tab} setTab={setTab} counts={counts} />

        <main className="min-w-0 flex-1 space-y-4">
          {router && <Identity router={router} resource={overview?.resource} />}

          {error && (
            <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>
          )}

          {notice && <BackupNotice notice={notice} onDismiss={() => setNotice(null)} />}

          {loading && !data && (
            <p className="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400">
              Reading from the router…
            </p>
          )}

          {overview && (
            <Overview data={overview} history={history.current} />
          )}

          {tab === 'system-information' && data && <SystemInformation data={data} />}

          {tab === 'diagnostics' && <DiagnosticPanel form={form} setForm={setForm} submit={diagnose} rows={rows} busy={busy} />}

          {tab === 'terminal' && <TerminalPanel run={runTerminal} busy={busy} routerName={router?.name} />}

          {CREATABLE.includes(tab) && data && (
            <div className='flex justify-end'>
              <button type='button' onClick={() => { setEditingId(null); setForm({}); setError(''); setFormOpen(true); }} className='rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700'>
                + Add {tab.replaceAll('-', ' ').replace(/s$/, '')}
              </button>
            </div>
          )}

          {!overview && data && !['system-information', 'diagnostics', 'terminal'].includes(tab) && (
            <DataTable
              tab={tab}
              rows={rows}
              busy={busy}
              onEdit={EDITABLE.includes(tab) ? edit : null}
              onRemove={DELETABLE.includes(tab) ? remove : null}
              onMakeStatic={tab === 'dhcp-leases' ? makeStatic : null}
              onBindingStatus={tab === 'bindings' ? setBindingStatus : null}
            />
          )}

          {tab === 'backups' && (
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="text-sm font-semibold text-slate-900">Create an encrypted backup</h2>
              <p className="mt-1 text-xs text-slate-500">
                The password is generated on the server, shown once, and never stored. Save it before dismissing.
              </p>
              <button
                onClick={submit}
                disabled={busy}
                className="mt-3 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
              >
                {busy ? 'Creating…' : 'Create encrypted backup'}
              </button>
            </div>
          )}

          {EDITABLE.includes(tab) && formOpen && (
            <FormModal onClose={() => { if (!busy) { setFormOpen(false); setEditingId(null); setForm({}); } }}>
              <CreateForm
              tab={tab}
              rows={rows}
              metadata={metadata}
              form={form}
              setForm={setForm}
              submit={submit}
                busy={busy}
                error={error}
                editing={editingId}
                cancel={() => { setFormOpen(false); setEditingId(null); setForm({}); }}
              />
            </FormModal>
          )}
        </main>
      </div>
    </div>
  );
}

// ── nav ─────────────────────────────────────────────────────────────

function NavRail({ tab, setTab, counts }) {
  const activeGroup = GROUPS.find((group) => group.items.some((item) => item.id === tab))?.label;
  const [openGroup, setOpenGroup] = useState(activeGroup || GROUPS[0].label);

  useEffect(() => {
    if (activeGroup) setOpenGroup(activeGroup);
  }, [activeGroup]);

  return (
    <aside className="shrink-0 overflow-x-auto rounded-xl border border-slate-200 bg-white p-3 lg:sticky lg:top-4 lg:w-56 lg:self-start lg:overflow-visible">
      <div className="flex gap-4 lg:flex-col lg:gap-5">
        {GROUPS.map((group) => (
          <div key={group.label} className="flex gap-1 lg:flex-col">
            <button type='button' onClick={() => setOpenGroup(openGroup === group.label ? null : group.label)} aria-expanded={openGroup === group.label} className='flex w-full items-center justify-between gap-3 rounded-md px-2.5 py-2 text-left font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500 hover:bg-slate-50 hover:text-slate-800'>
              {group.label}
              <ChevronDown className={openGroup === group.label ? 'rotate-180' : ''} />
            </button>
            <div className={`${openGroup === group.label ? 'flex' : 'hidden'} flex-col gap-1`}>
            {group.items.map((item) => {
              const Icon = item.icon;
              const active = tab === item.id;
              const count = item.count && counts ? counts[item.count] : null;
              return (
                <button
                  key={item.id}
                  onClick={() => setTab(item.id)}
                  aria-current={active}
                  className={`flex w-full items-center gap-2.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-left text-[13px] font-medium ${
                    active ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                  }`}
                >
                  <Icon className="h-[15px] w-[15px] shrink-0" />
                  {item.label}
                  {count !== null && count !== undefined && (
                    <span className={`ml-auto hidden font-mono text-[10.5px] lg:inline ${active ? 'text-indigo-600' : 'text-slate-400'}`}>
                      {count}
                    </span>
                  )}
                </button>
              );
            })}
            </div>
          </div>
        ))}
      </div>
    </aside>
  );
}

// ── identity strip ──────────────────────────────────────────────────

function Identity({ router, resource }) {
  const facts = resource ? [
    ['RouterOS', resource.version],
    ['Board', resource['board-name']],
    ['Arch', resource.architecture || resource['architecture-name']],
    ['Uptime', resource.uptime],
  ] : [];

  return (
    <div className="relative overflow-hidden rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
      <i className="absolute inset-y-0 left-0 w-[3px] bg-gradient-to-b from-indigo-600 to-amber-500" />
      <div className="flex flex-wrap items-center gap-x-7 gap-y-3 pl-1">
        <div className="min-w-[180px]">
          <div className="flex items-center gap-2">
            <span className="text-[15px] font-semibold text-slate-900">{router.name}</span>
            {router.status === 'online'
              ? <Pill tone="ok"><i className="h-1.5 w-1.5 rounded-full bg-emerald-600" />Online</Pill>
              : <Pill tone={router.status === 'maintenance' ? 'warn' : 'down'}>{router.status || 'unknown'}</Pill>}
            <Pill tone={router.connection_mode === 'manual' ? 'mute' : 'accent'}>
              {router.connection_mode === 'manual' ? 'Manual mode' : 'Live mode'}
            </Pill>
          </div>
          <p className="mt-0.5 font-mono text-[11.5px] text-slate-500">
            {router.wireguard_ip || 'no tunnel IP'}{router.location ? ` · ${router.location}` : ''}
          </p>
        </div>

        {facts.length > 0 && (
          <div className="ml-auto flex flex-wrap gap-x-7 gap-y-2">
            {facts.map(([k, v]) => (
              <div key={k}>
                <p className="font-mono text-[9.5px] uppercase tracking-[0.1em] text-slate-400">{k}</p>
                <p className="font-mono text-[13px] font-medium tabular-nums text-slate-800">{plain(v)}</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ── overview ────────────────────────────────────────────────────────

function Overview({ data, history }) {
  const resource = data.resource || {};
  const traffic = data.traffic || [];
  const interfaces = data.interfaces || [];

  const totalMem = num(resource['total-memory']);
  const freeMem = num(resource['free-memory']);
  const totalHdd = num(resource['total-hdd-space']);
  const freeHdd = num(resource['free-hdd-space']);
  const cpu = num(resource['cpu-load']);

  const throughput = traffic.reduce(
    (sum, row) => sum + (Number(row['rx-bits-per-second']) || 0) + (Number(row['tx-bits-per-second']) || 0),
    0,
  );

  const running = interfaces.filter((i) => isTrue(i.running)).length;

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Vital label="CPU load" big={cpu === null ? '—' : `${cpu}%`} small="processor" value={cpu === null ? 0 : cpu / 100} />
        <Vital
          label="Memory"
          big={totalMem && freeMem !== null ? fmtBytes(totalMem - freeMem) : '—'}
          small={totalMem ? `of ${fmtBytes(totalMem)} used` : 'unavailable'}
          value={totalMem ? (totalMem - freeMem) / totalMem : 0}
        />
        <Vital
          label="Storage"
          big={totalHdd && freeHdd !== null ? fmtBytes(totalHdd - freeHdd) : '—'}
          small={totalHdd ? `of ${fmtBytes(totalHdd)} used` : 'unavailable'}
          value={totalHdd ? (totalHdd - freeHdd) / totalHdd : 0}
        />
        <div className="flex items-center gap-3.5 rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
          <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-indigo-50">
            <Activity className="h-5 w-5 text-indigo-600" />
          </div>
          <div className="min-w-0">
            <p className="font-mono text-[9.5px] uppercase tracking-[0.1em] text-slate-400">Throughput</p>
            <p className="text-lg font-semibold tabular-nums text-slate-900">{fmtBits(throughput)}</p>
            <p className="font-mono text-[11px] text-slate-500">across {traffic.length || 0} live link(s)</p>
          </div>
        </div>
      </div>

      <div className="flex flex-wrap gap-2.5">
        {Object.entries(data.counts || {}).map(([name, count]) => (
          <div key={name} className="flex items-baseline gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2">
            <b className="text-[17px] font-bold tabular-nums text-slate-900">{count}</b>
            <span className="text-xs text-slate-500">{name.replaceAll('_', ' ')}</span>
          </div>
        ))}
      </div>

      {data.partial && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
          Some router sections could not be read — the numbers above may be incomplete.
        </p>
      )}

      <section>
        <div className="mb-2.5 flex items-baseline justify-between gap-3">
          <h2 className="text-[13.5px] font-semibold text-slate-900">Live interface rates</h2>
          <span className="font-mono text-[10.5px] text-slate-400">/interface monitor-traffic · 10s</span>
        </div>
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          {traffic.length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-slate-400">No running interfaces reporting traffic.</p>
          )}
          {traffic.map((row) => {
            const series = history[row.name] || { rx: [], tx: [] };
            return (
              <div
                key={row.name}
                className="grid grid-cols-[130px_1fr_120px] items-center gap-4 border-b border-slate-100 px-4 py-2.5 last:border-0"
              >
                <div className="flex items-center gap-2">
                  <i className="h-5 w-[3px] shrink-0 rounded-sm bg-emerald-500" />
                  <span className="truncate font-mono text-[12.5px] font-medium text-slate-800">{row.name}</span>
                </div>
                <div>
                  {series.rx.length > 1
                    ? <Sparkline points={series.rx} />
                    : <p className="font-mono text-[10.5px] text-slate-300">collecting samples…</p>}
                </div>
                <div className="flex flex-col items-end gap-0.5">
                  <span className="font-mono text-[11.5px] tabular-nums text-emerald-600">
                    RX <b>{fmtBits(row['rx-bits-per-second'])}</b>
                  </span>
                  <span className="font-mono text-[11.5px] tabular-nums text-indigo-600">
                    TX <b>{fmtBits(row['tx-bits-per-second'])}</b>
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      </section>

      <section>
        <div className="mb-2.5 flex items-baseline justify-between gap-3">
          <h2 className="text-[13.5px] font-semibold text-slate-900">Interfaces</h2>
          <span className="font-mono text-[10.5px] text-slate-400">{interfaces.length} total · {running} running</span>
        </div>
        <DataTable tab="interfaces" rows={interfaces} paginate={false} />
      </section>
    </div>
  );
}

function Vital({ label, big, small, value }) {
  return (
    <div className="flex items-center gap-3.5 rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm">
      <Gauge value={value} />
      <div className="min-w-0">
        <p className="font-mono text-[9.5px] uppercase tracking-[0.1em] text-slate-400">{label}</p>
        <p className="text-lg font-semibold tabular-nums text-slate-900">{big}</p>
        <p className="truncate font-mono text-[11px] text-slate-500">{small}</p>
      </div>
    </div>
  );
}

function SystemInformation({ data }) {
  return <div className='grid gap-4 md:grid-cols-2'>
    {Object.entries(data).map(([section, values]) => <section key={section} className='rounded-xl border bg-white p-4'>
      <h2 className='mb-3 font-semibold capitalize'>{section.replaceAll('-', ' ')}</h2>
      {Object.entries(Array.isArray(values) ? (values[0] || {}) : (values || {})).map(([key, item]) => <div key={key} className='flex justify-between gap-4 border-b py-2 text-sm'><span className='text-slate-500'>{key}</span><span className='break-all text-right font-mono text-xs'>{plain(item)}</span></div>)}
    </section>)}
  </div>;
}

function TerminalPanel({ run, busy, routerName }) {
  const [command, setCommand] = useState('');
  const [entries, setEntries] = useState([]);
  const execute = async (event) => {
    event.preventDefault();
    const next = command.trim();
    if (!next || busy) return;
    setEntries((items) => [...items, { command: next, pending: true }]);
    setCommand('');
    try {
      const result = await run(next);
      setEntries((items) => items.map((item, i) => i === items.length - 1 ? { command: next, result } : item));
    } catch (error) {
      setEntries((items) => items.map((item, i) => i === items.length - 1 ? { command: next, error: error.message } : item));
    }
  };
  return <TerminalConsole command={command} setCommand={setCommand} entries={entries} setEntries={setEntries} execute={execute} busy={busy} routerName={routerName} />;
}

function TerminalConsole({ command, setCommand, entries, setEntries, execute, busy, routerName }) {
  return <section className='overflow-hidden rounded-xl border border-slate-800 bg-slate-950 text-slate-300'><TerminalHeader routerName={routerName} clear={() => setEntries([])} /><TerminalOutput entries={entries} routerName={routerName} /><form onSubmit={execute} className='flex gap-2 border-t border-slate-800 p-3'><input value={command} onChange={(e) => setCommand(e.target.value)} placeholder='/system/resource print' className='min-w-0 flex-1 bg-transparent font-mono text-white outline-none' /><button disabled={busy} className='rounded bg-emerald-600 px-4 py-2 text-white disabled:opacity-40'>{busy ? 'Running...' : 'Run'}</button></form></section>;
}

function TerminalHeader({ routerName, clear }) {
  return <div className='flex items-center justify-between border-b border-slate-800 p-4'><div><h2 className='flex gap-2 font-semibold text-white'><TerminalSquare className='h-5 w-5 text-emerald-400' />RouterOS terminal</h2><p className='text-xs text-slate-400'>{routerName || 'Router'} - full commands require super admin confirmation and are audited</p></div><button type='button' onClick={clear} className='rounded border border-slate-700 px-3 py-1 text-xs'>Clear</button></div>;
}

function TerminalOutput({ entries, routerName }) {
  return <div className='h-[420px] overflow-auto p-4 font-mono text-xs'>{!entries.length && <p className='text-slate-400'>Try /system/resource print or /interface print.</p>}{entries.map((entry, index) => <TerminalEntry key={index} entry={entry} routerName={routerName} />)}</div>;
}

function TerminalEntry({ entry, routerName }) {
  return <div><p>admin@{routerName || 'router'} &gt; {entry.command}</p>{entry.pending && <p>Running...</p>}{entry.error && <p>{entry.error}</p>}{entry.result?.map((row, index) => <pre key={index}>{typeof row === 'object' ? JSON.stringify(row, null, 2) : String(row)}</pre>)}</div>;
}

function DiagnosticPanel({ form, setForm, submit, rows, busy }) {
  return <div className='space-y-4'>
    <form onSubmit={submit} className='flex flex-wrap gap-2 rounded-xl border bg-white p-4'>
      <select value={form.tool || 'ping'} onChange={(e) => setForm({ ...form, tool: e.target.value })} className='rounded-md border px-3 py-2'><option value='ping'>Ping</option><option value='traceroute'>Traceroute</option></select>
      <input required placeholder='IP address or hostname' value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} className='min-w-64 flex-1 rounded-md border px-3 py-2' />
      <button disabled={busy} className='rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white'>{busy ? 'Running...' : 'Run diagnostic'}</button>
    </form>
    {rows.map((row, index) => <pre key={index} className='overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-emerald-300'>{JSON.stringify(row, null, 2)}</pre>)}
  </div>;
}

// ── table ───────────────────────────────────────────────────────────

function DataTable({ tab, rows, busy, onEdit, onRemove, onMakeStatic, onBindingStatus, paginate = true }) {
  const columns = COLUMNS[tab] || COLUMNS.interfaces;
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => { setSearch(''); setPage(1); }, [tab]);

  const filtered = useMemo(
    () => rows.filter((row) => JSON.stringify(row).toLowerCase().includes(search.toLowerCase())),
    [rows, search],
  );

  const perPage = 10;
  const pageCount = Math.max(1, Math.ceil(filtered.length / perPage));
  const current = Math.min(page, pageCount);
  const visible = paginate ? filtered.slice((current - 1) * perPage, current * perPage) : filtered;
  const hasActions = Boolean(onEdit || onRemove || onMakeStatic || onBindingStatus);

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      {paginate && (
        <div className="border-b border-slate-200 p-3">
          <input
            aria-label="Search table"
            placeholder="Search…"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full max-w-xs rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:w-64"
          />
        </div>
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr>
              {columns.map((col) => (
                <th
                  key={col.key}
                  className={`border-b border-slate-200 bg-slate-50 px-4 py-2.5 font-mono text-[9.5px] font-medium uppercase tracking-[0.1em] text-slate-400 ${
                    col.align === 'right' ? 'text-right' : 'text-left'
                  }`}
                >
                  {col.label}
                </th>
              ))}
              {hasActions && <th className="border-b border-slate-200 bg-slate-50 px-4 py-2.5" />}
            </tr>
          </thead>
          <tbody>
            {visible.map((row, index) => (
              <tr key={row['.id'] || index} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                {columns.map((col) => (
                  <td
                    key={col.key}
                    className={`px-4 py-2.5 ${col.wrap ? 'min-w-[280px]' : 'whitespace-nowrap'} ${
                      col.align === 'right' ? 'text-right font-mono text-[12px] tabular-nums' : ''
                    } ${isTrue(row.disabled) ? 'opacity-60' : ''}`}
                  >
                    {col.render ? col.render(row[col.key], row) : plain(row[col.key])}
                  </td>
                ))}
                {hasActions && (
                  <td className="whitespace-nowrap px-4 py-2.5 text-right">
                    {onBindingStatus && (
                      <button
                        disabled={busy || !row['.id']}
                        onClick={() => onBindingStatus(row, isTrue(row.disabled))}
                        className={`rounded-md border px-2.5 py-1 text-xs font-semibold disabled:opacity-40 ${
                          isTrue(row.disabled)
                            ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'
                            : 'border-amber-200 text-amber-700 hover:bg-amber-50'
                        }`}
                      >
                        {isTrue(row.disabled) ? 'Activate' : 'Deactivate'}
                      </button>
                    )}
                    {onMakeStatic && isTrue(row.dynamic) && (
                      <button
                        disabled={busy}
                        onClick={() => onMakeStatic(row['.id'])}
                        className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:border-emerald-300 hover:text-emerald-700 disabled:opacity-40"
                      >
                        Make static
                      </button>
                    )}
                    {onEdit && !(['dhcp-leases', 'bridge-ports'].includes(tab) && isTrue(row.dynamic)) && (
                      <button
                        disabled={busy}
                        onClick={() => onEdit(row)}
                        className="ml-1.5 rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:border-indigo-300 hover:text-indigo-700 disabled:opacity-40"
                      >
                        Edit
                      </button>
                    )}
                    {onRemove && !(tab === 'bridge-ports' && isTrue(row.dynamic)) && (
                      <button
                        disabled={busy || !row['.id']}
                        onClick={() => onRemove(row)}
                        className="ml-1.5 rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:border-rose-300 hover:text-rose-700 disabled:opacity-40"
                      >
                        Delete
                      </button>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {filtered.length === 0 && (
        <p className="px-4 py-10 text-center text-sm text-slate-400">
          {search ? `Nothing matches “${search}”.` : `No ${tab.replaceAll('-', ' ')} on this router.`}
        </p>
      )}

      {paginate && filtered.length > 0 && (
        <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-2.5 text-xs text-slate-500">
          <span>{filtered.length} item{filtered.length === 1 ? '' : 's'}</span>
          <div className="flex items-center gap-2">
            <button
              disabled={current <= 1}
              onClick={() => setPage(current - 1)}
              className="rounded-md border border-slate-200 bg-white px-2.5 py-1 font-semibold disabled:opacity-40"
            >
              Previous
            </button>
            <span className="tabular-nums">Page {current} of {pageCount}</span>
            <button
              disabled={current >= pageCount}
              onClick={() => setPage(current + 1)}
              className="rounded-md border border-slate-200 bg-white px-2.5 py-1 font-semibold disabled:opacity-40"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// ── create / edit ───────────────────────────────────────────────────

function FormModal({ children, onClose }) {
  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', closeOnEscape); document.body.style.overflow = previousOverflow; };
  }, [onClose]);

  return <div className='fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm' role='presentation' onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <div role='dialog' aria-modal='true' className='max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl'>
      {children}
    </div>
  </div>;
}

function CreateForm({ tab, rows, metadata, form, setForm, submit, busy, error, editing, cancel }) {
  const set = (name, value) => setForm({ ...form, [name]: value });

  const field = (name, placeholder, { required = false, type = 'text', list } = {}) => (
    <input
      required={required}
      type={type}
      list={list}
      placeholder={placeholder}
      value={form[name] ?? ''}
      onChange={(e) => set(name, e.target.value)}
      className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
    />
  );

  // Suggestion lists come from what the router already has, so nothing is
  // hardcoded against server config (allowed_address_lists is env-driven).
  const suggestions = (key) => [...new Set(rows.map((r) => r[key]).filter(Boolean))];

  let fields;
  if (tab === 'users') {
    fields = (
      <>
        {field('name', 'Username', { required: true })}
        {field('password', editing ? 'New password (optional)' : 'Password', { required: !editing })}
        {field('profile', 'Profile', { list: 'rm-profiles' })}
        <datalist id="rm-profiles">
          {suggestions('profile').map((p) => <option key={p} value={p} />)}
        </datalist>
      </>
    );
  } else if (tab === 'bindings') {
    fields = (
      <>
        {field('address', 'IP address')}
        {field('mac-address', 'MAC address (AA:BB:CC:DD:EE:FF)')}
        <select
          value={form.type || 'regular'}
          onChange={(e) => set('type', e.target.value)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="regular">Regular</option>
          <option value="bypassed">Bypassed — skips the login page</option>
          <option value="blocked">Blocked</option>
        </select>
        {field('comment', 'Comment')}
      </>
    );
  } else if (tab === 'profiles') {
    fields = (
      <>
        {field('name', 'Profile name', { required: true })}
        {field('rate-limit', 'Rate limit e.g. 5M/5M')}
        {field('address-pool', 'Address pool')}
        {field('shared-users', 'Shared users', { type: 'number' })}
      </>
    );
  } else if (tab === 'queues') {
    fields = (
      <>
        {field('name', 'Queue name', { required: true })}
        {field('target', 'Target e.g. 10.0.0.2/32', { required: true })}
        {field('max-limit', 'Max limit e.g. 5M/5M')}
        {field('comment', 'Comment')}
      </>
    );
  } else if (tab === 'dhcp-leases') {
    fields = <>{field('address', 'Lease address', { required: true })}{field('mac-address', 'MAC address', { required: true })}{field('server', 'DHCP server')}{field('comment', 'Comment')}</>;
  } else if (tab === 'hotspot-servers') {
    fields = <>{field('name', 'Server name', { required: true })}{field('interface', 'Interface', { required: true })}{field('address-pool', 'Address pool')}{field('profile', 'Hotspot profile')}</>;
  } else if (tab === 'walled-garden') {
    fields = <>{field('dst-host', 'Destination host', { required: true })}{field('comment', 'Comment')}</>;
  } else if (tab === 'ip-pools') {
    fields = <>{field('name', 'Pool name', { required: true })}{field('ranges', 'Ranges e.g. 10.5.5.2-10.5.5.254', { required: true })}{field('next-pool', 'Next pool')}</>;
  } else if (tab === 'dhcp-networks') {
    fields = <>{field('address', 'Network e.g. 10.5.5.0/24', { required: true })}{field('gateway', 'Gateway')}{field('dns-server', 'DNS servers')}{field('domain', 'Domain')}</>;
  } else if (tab === 'bridges') {
    fields = <>{field('name', 'Bridge name', { required: true })}<select value={form['protocol-mode'] || 'rstp'} onChange={(e) => set('protocol-mode', e.target.value)} className='rounded-md border px-3 py-2'><option value='rstp'>RSTP</option><option value='stp'>STP</option><option value='mstp'>MSTP</option><option value='none'>None</option></select>{field('comment', 'Comment')}</>;
  } else if (tab === 'bridge-ports') {
    const assigned = new Set(rows.filter((row) => row['.id'] !== editing).map((row) => row.interface));
    fields = <><select required value={form.bridge || ''} onChange={(e) => set('bridge', e.target.value)} className='rounded-md border px-3 py-2'><option value=''>Select bridge</option>{(metadata.bridges || []).map((item) => <option key={item['.id']} value={item.name}>{item.name}</option>)}</select><select required value={form.interface || ''} onChange={(e) => set('interface', e.target.value)} className='rounded-md border px-3 py-2'><option value=''>Select available interface</option>{(metadata.interfaces || []).filter((item) => !assigned.has(item.name)).map((item) => <option key={item['.id']} value={item.name}>{item.name}</option>)}</select>{field('pvid', 'PVID 1-4094', { type: 'number' })}{field('path-cost', 'Path cost', { type: 'number' })}<select value={form.edge || 'auto'} onChange={(e) => set('edge', e.target.value)} className='rounded-md border px-3 py-2'><option value='auto'>Edge auto</option><option value='yes'>Edge yes</option><option value='no'>Edge no</option></select></>;
  } else {
    fields = (
      <>
        {field('list', 'Allowed list name', { required: true, list: 'rm-lists' })}
        <datalist id="rm-lists">
          {suggestions('list').map((l) => <option key={l} value={l} />)}
        </datalist>
        {field('address', 'IP or subnet', { required: true })}
        {field('timeout', 'Timeout e.g. 1d')}
        {field('comment', 'Comment')}
      </>
    );
  }

  return (
    <form onSubmit={submit} className="bg-white p-5">
      <h2 className="mb-3 text-sm font-semibold text-slate-900">
        {editing ? 'Edit' : 'Add'} {tab.replaceAll('-', ' ').replace(/s$/, '')}
        {editing && <span className="ml-2 font-mono text-[11px] font-normal text-slate-400">{editing}</span>}
      </h2>
      {error && <p className='mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700'>{error}</p>}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{fields}</div>
      <div className="mt-3 flex items-center gap-2">
        <button
          disabled={busy}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
        >
          {busy ? 'Saving…' : editing ? 'Save changes' : 'Add to router'}
        </button>
        <button type="button" onClick={cancel} disabled={busy} className="px-3 py-2 text-sm text-slate-500 hover:text-slate-900 disabled:opacity-40">Cancel</button>
      </div>
    </form>
  );
}

// ── backup password ─────────────────────────────────────────────────

function BackupNotice({ notice, onDismiss }) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(notice.password);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-amber-900">Backup created — save this password now</p>
          <p className="mt-0.5 text-xs text-amber-800">
            {notice.warning || 'It will not be shown or stored again.'}
          </p>
          <p className="mt-2 font-mono text-[12px] text-amber-900">{notice.name}</p>
          <div className="mt-1 flex items-center gap-2">
            <code className="rounded-md border border-amber-300 bg-white px-2.5 py-1 font-mono text-[13px] font-semibold text-slate-900">
              {notice.password}
            </code>
            <button
              onClick={copy}
              className="flex items-center gap-1.5 rounded-md border border-amber-300 bg-white px-2.5 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100"
            >
              <Clipboard className="h-3.5 w-3.5" />
              {copied ? 'Copied' : 'Copy'}
            </button>
          </div>
        </div>
        <button onClick={onDismiss} className="shrink-0 text-xs font-semibold text-amber-800 hover:text-amber-950">
          Dismiss
        </button>
      </div>
    </div>
  );
}
