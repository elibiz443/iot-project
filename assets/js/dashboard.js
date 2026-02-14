const APP = <?php echo json_encode($appConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const state = {
  devices: {},
  selected: localStorage.getItem('iot_selected') || null,
  mqtt: null,
  chartCpu: null,
  chartDisk: null,
  onlyOnline: false,
  autoScroll: true,
  eventsLive: []
};

const el = (id) => document.getElementById(id);

const fmtAgo = (isoOrMysql) => {
  if (!isoOrMysql) return '—';
  const s = isoOrMysql.includes('T') ? isoOrMysql : isoOrMysql.replace(' ', 'T') + 'Z';
  const t = Date.parse(s);
  if (Number.isNaN(t)) return isoOrMysql;
  const d = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (d < 60) return `${d}s ago`;
  if (d < 3600) return `${Math.floor(d / 60)}m ago`;
  if (d < 86400) return `${Math.floor(d / 3600)}h ago`;
  return `${Math.floor(d / 86400)}d ago`;
};

const fmtUptime = (s) => {
  if (typeof s !== 'number' || !Number.isFinite(s)) return '—';
  const sec = Math.max(0, Math.floor(s));
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  if (d > 0) return `${d}d ${h}h ${m}m`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
};

const chip = (txt) => {
  const d = document.createElement('span');
  d.className = 'inline-flex items-center rounded-full bg-slate-800 px-2 py-1 text-xs text-slate-200';
  d.textContent = txt;
  return d;
};

const setMqttBadge = (mode) => {
  const b = el('mqttBadge');
  if (mode === 'connected') {
    b.textContent = 'connected';
    b.className = 'text-xs px-2 py-1 rounded-lg bg-emerald-500/15 text-emerald-300';
    return;
  }
  if (mode === 'reconnecting') {
    b.textContent = 'reconnecting';
    b.className = 'text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200';
    return;
  }
  if (mode === 'disconnected') {
    b.textContent = 'disconnected';
    b.className = 'text-xs px-2 py-1 rounded-lg bg-rose-500/15 text-rose-300';
    return;
  }
  if (mode === 'error') {
    b.textContent = 'error';
    b.className = 'text-xs px-2 py-1 rounded-lg bg-rose-500/15 text-rose-300';
    return;
  }
  b.textContent = 'connecting';
  b.className = 'text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200';
};

const setStatus = (on) => {
  el('statusDot').className = `h-2.5 w-2.5 rounded-full ${on ? 'bg-emerald-400' : 'bg-rose-400'}`;
  el('statusText').textContent = on ? 'Online' : 'Offline';
};

const topicParts = (topic) => topic.replace(/^\/+|\/+$/g, '').split('/');
const getDeviceFromTopic = (topic) => {
  const p = topicParts(topic);
  if (p.length < 4) return null;
  if (p[0] !== 'home' || p[1] !== 'iot') return null;
  return p[2];
};
const getTypeFromTopic = (topic) => {
  const p = topicParts(topic);
  if (p.length < 4) return null;
  if (p[3] === 'telemetry') return 'telemetry';
  if (p[3] === 'events') return 'events';
  if (p[3] === 'status' && p[4] === 'online') return 'online';
  return null;
};

const ensureCharts = () => {
  if (!state.chartCpu) {
    state.chartCpu = new Chart(el('cpuChart').getContext('2d'), {
      type: 'line',
      data: { labels: [], datasets: [{ label: 'CPU °C', data: [], tension: 0.25, pointRadius: 0, borderWidth: 2 }] },
      options: { responsive: true, plugins: { legend: { display: true } }, scales: { x: { ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: false } } }
    });
  }
  if (!state.chartDisk) {
    state.chartDisk = new Chart(el('diskChart').getContext('2d'), {
      type: 'line',
      data: { labels: [], datasets: [{ label: 'Disk %', data: [], tension: 0.25, pointRadius: 0, borderWidth: 2 }] },
      options: { responsive: true, plugins: { legend: { display: true } }, scales: { x: { ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, suggestedMax: 100 } } }
    });
  }
};

const apiGet = async (url) => {
  const r = await fetch(url, { cache: 'no-store' });
  const j = await r.json().catch(() => null);
  if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'api error');
  return j;
};

const normalizeDevice = (d) => {
  if (!d || !d.device_id) return null;
  state.devices[d.device_id] = state.devices[d.device_id] || { device_id: d.device_id, online: 0 };
  const cur = state.devices[d.device_id];
  cur.online = typeof d.online === 'number' ? d.online : (cur.online || 0);
  cur.last_seen = d.last_seen || cur.last_seen || null;
  cur.ip = d.ip || cur.ip || null;
  cur.last_telemetry = d.last_telemetry || cur.last_telemetry || null;
  cur.last_event = d.last_event || cur.last_event || null;
  return cur;
};

const renderDeviceList = () => {
  const q = (el('deviceSearch').value || '').trim().toLowerCase();
  const ids = Object.keys(state.devices).sort((a, b) => a.localeCompare(b));
  const box = el('deviceList');
  box.innerHTML = '';

  const filtered = ids.filter(id => {
    const d = state.devices[id];
    if (state.onlyOnline && !d.online) return false;
    if (q && !id.toLowerCase().includes(q)) return false;
    return true;
  });

  if (filtered.length === 0) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'No devices';
    box.appendChild(d);
    return;
  }

  for (const id of filtered) {
    const dev = state.devices[id];
    const btn = document.createElement('button');
    const active = state.selected === id;
    btn.className = `w-full text-left rounded-xl border px-3 py-2 transition ${active ? 'border-emerald-500/60 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/30 hover:bg-slate-800/30'}`;
    btn.onclick = () => selectDevice(id);

    const top = document.createElement('div');
    top.className = 'flex items-center justify-between gap-2';

    const left = document.createElement('div');
    left.className = 'min-w-0';
    const name = document.createElement('div');
    name.className = 'text-sm font-semibold truncate';
    name.textContent = id;
    const sub = document.createElement('div');
    sub.className = 'text-xs text-slate-400';
    sub.textContent = dev.last_seen ? `Last: ${fmtAgo(dev.last_seen)}` : 'No data';
    left.appendChild(name);
    left.appendChild(sub);

    const right = document.createElement('div');
    right.className = `text-xs px-2 py-1 rounded-lg ${dev.online ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300'}`;
    right.textContent = dev.online ? 'online' : 'offline';

    top.appendChild(left);
    top.appendChild(right);
    btn.appendChild(top);
    box.appendChild(btn);
  }
};

const getSelected = () => {
  const id = state.selected;
  if (!id) return null;
  return state.devices[id] || null;
};

const renderSelectedHeader = () => {
  const dev = getSelected();
  if (!dev) {
    el('deviceId').textContent = '—';
    el('deviceIp').textContent = 'IP: —';
    el('lastSeen').textContent = 'Last seen: —';
    el('uptime').textContent = 'Uptime: —';
    el('cpuTemp').textContent = '—';
    el('diskUsed').textContent = '—';
    el('diskHint').textContent = 'used';
    setStatus(false);
    return;
  }

  el('deviceId').textContent = dev.device_id;
  el('deviceIp').textContent = `IP: ${dev.ip || '—'}`;
  el('lastSeen').textContent = `Last seen: ${dev.last_seen ? fmtAgo(dev.last_seen) : '—'}`;
  setStatus(!!dev.online);

  const tel = dev.last_telemetry || null;
  if (tel) {
    el('uptime').textContent = `Uptime: ${fmtUptime(tel.uptime_s)}`;
    el('cpuTemp').textContent = `${tel.cpu_temp_c ?? '—'}°C`;
    if (tel.disk && typeof tel.disk.used_pct !== 'undefined') {
      el('diskUsed').textContent = `${tel.disk.used_pct}%`;
      el('diskHint').textContent = `${tel.disk.used_mb ?? '—'}MB / ${tel.disk.total_mb ?? '—'}MB`;
    } else {
      el('diskUsed').textContent = '—';
      el('diskHint').textContent = 'used';
    }
  } else {
    el('uptime').textContent = 'Uptime: —';
    el('cpuTemp').textContent = '—';
    el('diskUsed').textContent = '—';
    el('diskHint').textContent = 'used';
  }
};

const renderLatestEventBox = () => {
  const box = el('latestEventBox');
  box.innerHTML = '';
  const dev = getSelected();
  if (!dev) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'Select a device';
    box.appendChild(d);
    return;
  }

  const evt = dev.last_event || null;
  if (!evt) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'No events yet';
    box.appendChild(d);
    return;
  }

  const top = document.createElement('div');
  top.className = 'flex items-center justify-between gap-2';
  const a = document.createElement('div');
  a.className = 'text-sm font-semibold';
  a.textContent = `Faces: ${evt.faces ?? 0}`;
  const b = document.createElement('div');
  b.className = 'text-xs text-slate-400';
  b.textContent = fmtAgo(evt.ts);
  top.appendChild(a);
  top.appendChild(b);

  const chips = document.createElement('div');
  chips.className = 'mt-2 flex flex-wrap gap-2';
  const labels = Array.isArray(evt.labels) ? evt.labels : [];
  if (labels.length === 0) chips.appendChild(chip('no labels'));
  for (const l of labels.slice(0, 10)) chips.appendChild(chip(l));

  const imgSrc = evt.snapshot_url ? evt.snapshot_url : (evt.snapshot_b64 ? `data:image/jpeg;base64,${evt.snapshot_b64}` : '');
  box.appendChild(top);
  box.appendChild(chips);

  if (imgSrc) {
    const img = document.createElement('img');
    img.src = imgSrc;
    img.className = 'mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-56';
    img.onclick = () => openModal(evt);
    box.appendChild(img);
  } else {
    const hint = document.createElement('div');
    hint.className = 'mt-3 text-xs text-slate-400';
    hint.textContent = 'No snapshot available';
    box.appendChild(hint);
  }
};

const renderCharts = (telemetry) => {
  ensureCharts();
  const labels = [];
  const cpu = [];
  const disk = [];
  for (const t of telemetry) {
    const ts = (t.ts || '');
    labels.push(ts ? ts.slice(11, 19) : '');
    cpu.push(typeof t.cpu_temp_c === 'number' ? t.cpu_temp_c : null);
    disk.push(typeof t.disk_used_pct === 'number' ? t.disk_used_pct : null);
  }
  state.chartCpu.data.labels = labels;
  state.chartCpu.data.datasets[0].data = cpu;
  state.chartCpu.update();

  state.chartDisk.data.labels = labels;
  state.chartDisk.data.datasets[0].data = disk;
  state.chartDisk.update();
};

const eventCard = (e, fromLive = false) => {
  const card = document.createElement('button');
  card.className = 'w-full text-left rounded-2xl border border-slate-800 bg-slate-950/30 p-4 hover:bg-slate-800/20 transition';
  card.onclick = () => openModal(e.payload || e);

  const top = document.createElement('div');
  top.className = 'flex items-center justify-between gap-2';

  const left = document.createElement('div');
  left.className = 'text-sm font-semibold';
  left.textContent = `Faces: ${e.faces ?? (e.payload && e.payload.faces) ?? 0}`;

  const right = document.createElement('div');
  right.className = 'text-xs text-slate-400';
  right.textContent = fmtAgo(e.ts || (e.payload && e.payload.ts) || '');

  top.appendChild(left);
  top.appendChild(right);

  const chips = document.createElement('div');
  chips.className = 'mt-2 flex flex-wrap gap-2';
  const labels = Array.isArray(e.labels) ? e.labels : (e.payload && Array.isArray(e.payload.labels) ? e.payload.labels : []);
  if (labels.length === 0) chips.appendChild(chip('no labels'));
  for (const l of labels.slice(0, 12)) chips.appendChild(chip(l));

  const meta = document.createElement('div');
  meta.className = 'mt-3 text-xs text-slate-400 flex items-center justify-between gap-2';
  const a2 = document.createElement('div');
  a2.className = 'truncate';
  a2.textContent = fromLive ? 'live' : 'db';
  const b2 = document.createElement('div');
  b2.textContent = (e.payload && e.payload.snapshot_url) ? 'snapshot_url' : (e.payload && e.payload.snapshot_b64) ? 'snapshot_b64' : (e.snapshot_url ? 'snapshot_url' : 'no snapshot');
  meta.appendChild(a2);
  meta.appendChild(b2);

  card.appendChild(top);
  card.appendChild(chips);
  card.appendChild(meta);
  return card;
};

const renderEventsList = (dbEvents) => {
  const box = el('eventsList');
  box.innerHTML = '';

  const dev = getSelected();
  if (!dev) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'Select a device';
    box.appendChild(d);
    return;
  }

  const combined = [];
  const seen = new Set();

  for (const e of state.eventsLive) {
    const key = (e.ts || '') + '|' + (e.device_id || '');
    if (!seen.has(key)) {
      combined.push({ ...e, _live: true });
      seen.add(key);
    }
  }

  for (const e of (dbEvents || [])) {
    const key = (e.ts || '') + '|' + dev.device_id;
    if (!seen.has(key)) {
      combined.push({ ...e, _live: false });
      seen.add(key);
    }
  }

  if (combined.length === 0) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'No events';
    box.appendChild(d);
    return;
  }

  for (const e of combined.slice(0, APP.historyEvtLimit)) box.appendChild(eventCard(e, !!e._live));
  if (state.autoScroll) box.scrollIntoView({ block: 'end' });
};

const openModal = (evt) => {
  if (!evt) return;
  const imgSrc = evt.snapshot_url ? evt.snapshot_url : (evt.snapshot_b64 ? `data:image/jpeg;base64,${evt.snapshot_b64}` : '');
  el('modalImg').src = imgSrc || '';
  el('modalTitle').textContent = `${evt.device_id || state.selected || 'device'} · ${evt.ts ? fmtAgo(evt.ts) : ''}`.trim();

  const labels = Array.isArray(evt.labels) ? evt.labels : [];
  const faces = typeof evt.faces === 'number' ? evt.faces : 0;
  const meta = [];
  meta.push(`faces: ${faces}`);
  if (labels.length) meta.push(`labels: ${labels.slice(0, 20).join(', ')}`);
  if (evt.snapshot_url) meta.push('snapshot_url');
  if (evt.snapshot_path) meta.push('snapshot_path');
  if (evt.snapshot_b64) meta.push('snapshot_b64');
  el('modalMeta').textContent = meta.join(' · ');

  const detBox = el('modalDetections');
  detBox.innerHTML = '';
  const det = Array.isArray(evt.detections) ? evt.detections : [];
  if (det.length === 0) {
    const d = document.createElement('div');
    d.className = 'text-sm text-slate-400';
    d.textContent = 'No detections payload';
    detBox.appendChild(d);
  } else {
    for (const d0 of det.slice(0, 50)) {
      const row = document.createElement('div');
      row.className = 'rounded-xl border border-slate-800 bg-slate-950/30 p-3 flex items-center justify-between gap-2';
      const a = document.createElement('div');
      a.className = 'text-sm font-semibold truncate';
      a.textContent = `${d0.label ?? 'obj'}`;
      const b = document.createElement('div');
      b.className = 'text-xs text-slate-400';
      b.textContent = typeof d0.conf === 'number' ? `${Math.round(d0.conf * 100)}%` : '';
      row.appendChild(a);
      row.appendChild(b);
      detBox.appendChild(row);
    }
  }

  el('modal').classList.remove('hidden');
};

const closeModal = () => el('modal').classList.add('hidden');

const loadDevices = async () => {
  const j = await apiGet(`${APP.apiBase}?api=devices`);
  for (const d of j.devices) normalizeDevice(d);

  const ids = Object.keys(state.devices).sort((a, b) => a.localeCompare(b));
  if (!state.selected && ids.length) {
    state.selected = ids[0];
    localStorage.setItem('iot_selected', state.selected);
  } else if (state.selected && !state.devices[state.selected] && ids.length) {
    state.selected = ids[0];
    localStorage.setItem('iot_selected', state.selected);
  }

  renderDeviceList();
  renderSelectedHeader();
  renderLatestEventBox();
  if (state.selected) await loadHistory(state.selected);
};

const loadHistory = async (deviceId) => {
  const url = `${APP.apiBase}?api=history&device_id=${encodeURIComponent(deviceId)}&tel_limit=${encodeURIComponent(APP.historyTelLimit)}&evt_limit=${encodeURIComponent(APP.historyEvtLimit)}`;
  const j = await apiGet(url);
  renderCharts(j.telemetry || []);
  renderEventsList(j.events || []);
  el('cpuChartHint').textContent = `latest ${j.telemetry ? j.telemetry.length : 0}`;
  el('diskChartHint').textContent = `latest ${j.telemetry ? j.telemetry.length : 0}`;
};

const selectDevice = async (id) => {
  state.selected = id;
  localStorage.setItem('iot_selected', id);
  state.eventsLive = [];
  renderDeviceList();
  renderSelectedHeader();
  renderLatestEventBox();
  await loadHistory(id);
};

const pushLiveEvent = (deviceId, evt) => {
  if (!deviceId || !evt) return;
  if (state.selected !== deviceId) return;

  state.eventsLive.unshift({ ...evt, device_id: deviceId });
  state.eventsLive = state.eventsLive.slice(0, 40);

  const dev = state.devices[deviceId];
  if (dev) {
    dev.last_event = evt;
    dev.last_seen = evt.ts || new Date().toISOString();
    dev.online = 1;
  }

  renderSelectedHeader();
  renderLatestEventBox();
  loadHistory(deviceId).catch(() => {});
};

const applyLiveTelemetry = (deviceId, tel) => {
  if (!deviceId || !tel) return;
  const dev = state.devices[deviceId] || (state.devices[deviceId] = { device_id: deviceId, online: 0 });
  dev.last_telemetry = tel;
  dev.ip = tel.ip || dev.ip || null;
  dev.last_seen = tel.ts || new Date().toISOString();
  dev.online = 1;

  renderDeviceList();
  if (state.selected === deviceId) renderSelectedHeader();
};

const applyOnline = (deviceId, on) => {
  if (!deviceId) return;
  const dev = state.devices[deviceId] || (state.devices[deviceId] = { device_id: deviceId, online: 0 });
  dev.online = on ? 1 : 0;
  dev.last_seen = new Date().toISOString();
  renderDeviceList();
  if (state.selected === deviceId) renderSelectedHeader();
};

const mqttConnect = () => {
  const wsProto = location.protocol === 'https:' ? 'wss' : 'ws';
  const wsDefault = `${wsProto}://${location.host}/mqtt`;
  const wsUrl = (APP.mqttWsUrl && !APP.mqttWsUrl.includes('127.0.0.1')) ? APP.mqttWsUrl : wsDefault;

  const opts = {
    keepalive: 30,
    connectTimeout: 8000,
    reconnectPeriod: 2000
  };
  if (APP.mqttUser) opts.username = APP.mqttUser;
  if (APP.mqttPass) opts.password = APP.mqttPass;

  try {
    state.mqtt = mqtt.connect(wsUrl, opts);
  } catch (e) {
    setMqttBadge('error');
    return;
  }

  state.mqtt.on('connect', () => {
    setMqttBadge('connected');
    state.mqtt.subscribe(`${APP.topicRoot}/+/telemetry`);
    state.mqtt.subscribe(`${APP.topicRoot}/+/events`);
    state.mqtt.subscribe(`${APP.topicRoot}/+/status/online`);
  });

  state.mqtt.on('reconnect', () => setMqttBadge('reconnecting'));
  state.mqtt.on('close', () => setMqttBadge('disconnected'));
  state.mqtt.on('error', () => setMqttBadge('error'));

  state.mqtt.on('message', (topic, message) => {
    const deviceId = getDeviceFromTopic(topic);
    const type = getTypeFromTopic(topic);
    if (!deviceId || !type) return;

    if (!state.selected) {
      state.selected = deviceId;
      localStorage.setItem('iot_selected', deviceId);
    }

    if (type === 'online') {
      const on = message.toString().trim() === '1';
      applyOnline(deviceId, on);
      return;
    }

    if (type === 'telemetry') {
      try {
        const data = JSON.parse(message.toString());
        applyLiveTelemetry(deviceId, data);
      } catch (e) {}
      return;
    }

    if (type === 'events') {
      try {
        const data = JSON.parse(message.toString());
        pushLiveEvent(deviceId, data);
      } catch (e) {}
      return;
    }
  });
};

el('refreshBtn').onclick = async () => loadDevices().catch(() => {});
el('deviceSearch').oninput = () => renderDeviceList();

el('onlyOnlineBtn').onclick = () => {
  state.onlyOnline = !state.onlyOnline;
  el('onlyOnlineBtn').className = `text-xs px-2 py-1 rounded-lg border transition ${state.onlyOnline ? 'bg-emerald-500/10 border-emerald-500/60 text-emerald-300' : 'bg-slate-950/30 border-slate-800 hover:bg-slate-800/30'}`;
  renderDeviceList();
};

el('autoScrollBtn').onclick = () => {
  state.autoScroll = !state.autoScroll;
  el('autoScrollBtn').textContent = state.autoScroll ? 'Auto' : 'Manual';
  el('autoScrollBtn').className = `text-xs px-2 py-1 rounded-lg transition ${state.autoScroll ? 'bg-slate-800 hover:bg-slate-700' : 'bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30'}`;
};

el('clearLocalBtn').onclick = () => {
  state.eventsLive = [];
  if (state.selected) loadHistory(state.selected).catch(() => {});
};

el('openModalBtn').onclick = () => {
  const dev = getSelected();
  if (!dev || !dev.last_event) return;
  openModal(dev.last_event);
};

el('closeModalBtn').onclick = () => closeModal();
el('modal').onclick = (e) => { if (e.target === el('modal')) closeModal(); };
window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

const setConnInfo = () => {
  const wsProto = location.protocol === 'https:' ? 'wss' : 'ws';
  const wsDefault = `${wsProto}://${location.host}/mqtt`;
  const wsUrl = (APP.mqttWsUrl && !APP.mqttWsUrl.includes('127.0.0.1')) ? APP.mqttWsUrl : wsDefault;
  el('connInfo').textContent = `ws: ${wsUrl} · root: ${APP.topicRoot}`;
};

(async () => {
  setConnInfo();
  setMqttBadge('connecting');
  await loadDevices().catch(() => {});
  mqttConnect();
  setInterval(() => loadDevices().catch(() => {}), Math.max(10, APP.autoRefreshSec) * 1000);
})();

