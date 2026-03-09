const APP = window.APP || {};
const state = { devices: {}, selected: localStorage.getItem('iot_selected') || null, chartCpu: null, chartDisk: null, mqtt: null, lastHistory: { events: [], commands: [] } };
const el = (id) => document.getElementById(id);
const fmtAgo = (v) => { if (!v) return '—'; const s = v.includes('T') ? v : v.replace(' ', 'T') + 'Z'; const t = Date.parse(s); if (Number.isNaN(t)) return v; const d = Math.max(0, Math.floor((Date.now()-t)/1000)); if (d < 60) return `${d}s ago`; if (d < 3600) return `${Math.floor(d/60)}m ago`; if (d < 86400) return `${Math.floor(d/3600)}h ago`; return `${Math.floor(d/86400)}d ago`; };
const fmtUptime = (s) => { if (!Number.isFinite(s)) return '—'; const d = Math.floor(s / 86400), h = Math.floor((s % 86400)/3600), m = Math.floor((s % 3600)/60); return d ? `${d}d ${h}h ${m}m` : h ? `${h}h ${m}m` : `${m}m`; };
const apiGet = async (u) => { const r = await fetch(u, { cache: 'no-store' }); const j = await r.json(); if (!j.ok) throw new Error(j.error || 'API error'); return j; };
const apiPost = async (u, body) => { const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); const j = await r.json(); if (!j.ok) throw new Error(j.error || 'API error'); return j; };
const ensureCharts = () => {
  if (!state.chartCpu) state.chartCpu = new Chart(el('cpuChart').getContext('2d'), { type: 'line', data: { labels: [], datasets: [{ label: 'CPU °C', data: [], tension: 0.25, pointRadius: 0, borderWidth: 2 }] }, options: { responsive: true, plugins: { legend: { display: true } } } });
  if (!state.chartDisk) state.chartDisk = new Chart(el('diskChart').getContext('2d'), { type: 'line', data: { labels: [], datasets: [{ label: 'Disk %', data: [], tension: 0.25, pointRadius: 0, borderWidth: 2 }] }, options: { responsive: true, plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true, suggestedMax: 100 } } } });
};
const renderDeviceList = () => {
  const q = (el('deviceSearch').value || '').toLowerCase(); const box = el('deviceList'); box.innerHTML = '';
  const ids = Object.keys(state.devices).sort((a,b) => a.localeCompare(b)).filter(id => id.toLowerCase().includes(q));
  if (!ids.length) { box.innerHTML = '<div class="text-sm text-slate-400">No devices</div>'; return; }
  ids.forEach(id => { const d = state.devices[id]; const active = id === state.selected; const b = document.createElement('button'); b.className = `w-full text-left rounded-xl border px-3 py-2 ${active ? 'border-emerald-500/60 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/30 hover:bg-slate-800/30'}`; b.innerHTML = `<div class="flex items-center justify-between gap-2"><div><div class="text-sm font-semibold truncate">${id}</div><div class="text-xs text-slate-400">${d.last_seen ? `Last: ${fmtAgo(d.last_seen)}` : 'No data'}</div></div><div class="text-xs px-2 py-1 rounded-lg ${d.online ? 'bg-emerald-500/15 text-emerald-300':'bg-rose-500/15 text-rose-300'}">${d.online ? 'online':'offline'}</div></div>`; b.onclick = () => selectDevice(id); box.appendChild(b); });
};
const getSelected = () => state.selected ? state.devices[state.selected] || null : null;
const renderHeader = () => {
  const d = getSelected(); if (!d) return;
  el('deviceId').textContent = d.device_id; el('deviceIp').textContent = `IP: ${d.ip || '—'}`; el('lastSeen').textContent = `Last seen: ${d.last_seen ? fmtAgo(d.last_seen) : '—'}`; el('statusText').textContent = d.online ? 'Online' : 'Offline'; el('statusDot').className = `h-2.5 w-2.5 rounded-full ${d.online ? 'bg-emerald-400':'bg-rose-400'}`;
  const tel = d.last_telemetry || {}; el('uptime').textContent = `Uptime: ${fmtUptime(Number(tel.uptime_s))}`; el('cpuTemp').textContent = tel.cpu_temp_c != null ? `${tel.cpu_temp_c}°C` : '—';
  if (tel.disk && tel.disk.used_pct != null) { el('diskUsed').textContent = `${tel.disk.used_pct}%`; el('diskHint').textContent = `${tel.disk.used_mb ?? '—'}MB / ${tel.disk.total_mb ?? '—'}MB`; } else { el('diskUsed').textContent = '—'; el('diskHint').textContent = 'used'; }
  if (d.live_frame_url) { el('liveFrame').src = d.live_frame_url; el('liveFrameMeta').textContent = `Updated ${fmtAgo(d.live_frame_updated_at || d.last_seen || '')}`; } else { el('liveFrame').removeAttribute('src'); el('liveFrameMeta').textContent = 'No live frame yet.'; }
};
const renderLatestEvent = () => {
  const box = el('latestEventBox'); const d = getSelected(); box.innerHTML = ''; if (!d || !d.last_event) { box.innerHTML = '<div class="text-sm text-slate-400">No events yet</div>'; return; }
  const evt = d.last_event; const labels = Array.isArray(evt.labels) ? evt.labels : []; const img = evt.snapshot_url ? `<img src="${evt.snapshot_url}" class="mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-56">` : '';
  box.innerHTML = `<div class="flex items-center justify-between gap-2"><div class="text-sm font-semibold">Faces: ${evt.faces ?? 0}</div><div class="text-xs text-slate-400">${fmtAgo(evt.ts || '')}</div></div><div class="mt-2 text-xs text-slate-400">${labels.join(', ') || 'No labels'}</div>${img}`;
};
const renderEvents = (events) => { const box = el('eventsList'); box.innerHTML = ''; if (!events.length) { box.innerHTML = '<div class="text-sm text-slate-400">No events</div>'; return; } events.forEach(e => { const labels = Array.isArray(e.labels) ? e.labels : ((e.payload && e.payload.labels) || []); const snap = e.snapshot_url || (e.payload && e.payload.snapshot_url); const card = document.createElement('div'); card.className = 'rounded-2xl border border-slate-800 bg-slate-950/30 p-4'; card.innerHTML = `<div class="flex items-center justify-between gap-2"><div class="text-sm font-semibold">Faces: ${e.faces ?? (e.payload && e.payload.faces) ?? 0}</div><div class="text-xs text-slate-400">${fmtAgo(e.ts || (e.payload && e.payload.ts) || '')}</div></div><div class="mt-2 text-xs text-slate-400">${labels.join(', ') || 'No labels'}</div>${snap ? `<img src="${snap}" class="mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-72">` : ''}`; box.appendChild(card); }); };
const renderCommands = (commands) => { const box = el('commandsList'); box.innerHTML = ''; if (!commands.length) { box.innerHTML = '<div class="text-xs text-slate-400">No commands yet.</div>'; return; } commands.forEach(c => { const div = document.createElement('div'); div.className = 'rounded-xl border border-slate-800 bg-slate-950/30 p-3'; div.innerHTML = `<div class="flex items-center justify-between gap-2"><div class="text-xs font-semibold">${c.command_name}</div><div class="text-[11px] ${c.status === 'ack' ? 'text-emerald-300' : c.status === 'failed' ? 'text-rose-300':'text-slate-400'}">${c.status}</div></div><div class="mt-1 text-[11px] text-slate-400">Queued ${fmtAgo(c.queued_at || '')}</div><div class="mt-1 text-[11px] text-slate-400 break-words">${JSON.stringify(c.command_payload || {})}</div>${c.result_payload ? `<div class="mt-1 text-[11px] text-slate-400 break-words">Result: ${JSON.stringify(c.result_payload)}</div>` : ''}`; box.appendChild(div); }); };
const renderCharts = (tel) => { ensureCharts(); const labels = tel.map(t => (t.ts || '').slice(11,19)); state.chartCpu.data.labels = labels; state.chartCpu.data.datasets[0].data = tel.map(t => t.cpu_temp_c ?? null); state.chartCpu.update(); state.chartDisk.data.labels = labels; state.chartDisk.data.datasets[0].data = tel.map(t => t.disk_used_pct ?? null); state.chartDisk.update(); };
const loadDevices = async () => { const j = await apiGet(`${APP.apiBase}?api=devices`); j.devices.forEach(d => state.devices[d.device_id] = d); const ids = Object.keys(state.devices).sort(); if (!state.selected && ids.length) state.selected = ids[0]; if (state.selected) localStorage.setItem('iot_selected', state.selected); renderDeviceList(); renderHeader(); renderLatestEvent(); if (state.selected) await loadHistory(state.selected); };
const loadHistory = async (deviceId) => { const j = await apiGet(`${APP.apiBase}?api=history&device_id=${encodeURIComponent(deviceId)}&tel_limit=${APP.historyTelLimit}&evt_limit=${APP.historyEvtLimit}`); state.lastHistory = j; renderCharts(j.telemetry || []); renderEvents(j.events || []); renderCommands(j.commands || []); };
const selectDevice = async (id) => { state.selected = id; localStorage.setItem('iot_selected', id); renderDeviceList(); renderHeader(); renderLatestEvent(); await loadHistory(id); };
const setMqttBadge = (s) => { const b = el('mqttBadge'); b.textContent = s; b.className = `text-xs px-2 py-1 rounded-lg ${s === 'connected' ? 'bg-emerald-500/15 text-emerald-300' : s === 'disabled' ? 'bg-slate-800 text-slate-200' : 'bg-rose-500/15 text-rose-300'}`; };
const mqttConnect = () => {
  if (!window.mqtt) { setMqttBadge('disabled'); return; }
  const proto = location.protocol === 'https:' ? 'wss' : 'ws'; const wsUrl = APP.mqttWsUrl || `${proto}://${location.host}/mqtt`;
  try { state.mqtt = mqtt.connect(wsUrl, { username: APP.mqttUser || undefined, password: APP.mqttPass || undefined, connectTimeout: 5000, reconnectPeriod: 2000 }); } catch { setMqttBadge('disabled'); return; }
  state.mqtt.on('connect', () => { setMqttBadge('connected'); state.mqtt.subscribe(`${APP.topicRoot}/+/telemetry`); state.mqtt.subscribe(`${APP.topicRoot}/+/events`); state.mqtt.subscribe(`${APP.topicRoot}/+/status/online`); });
  state.mqtt.on('close', () => setMqttBadge('disconnected')); state.mqtt.on('error', () => setMqttBadge('disabled'));
  state.mqtt.on('message', () => { loadDevices().catch(() => {}); });
};
const sendCommand = async (commandName) => {
  const d = getSelected(); if (!d) { el('cmdStatus').textContent = 'Select a device first.'; return; }
  let payload = {}; const raw = el('cmdPayload').value.trim(); if (raw) { try { payload = JSON.parse(raw); } catch { el('cmdStatus').textContent = 'Payload JSON is invalid.'; return; } }
  el('cmdStatus').textContent = `Sending ${commandName}...`;
  try { await apiPost(`${APP.apiBase}?api=command_send`, { csrf: APP.csrf, device_id: d.device_id, command_name: commandName, command_payload: payload }); el('cmdStatus').textContent = `Queued ${commandName}.`; await loadHistory(d.device_id); } catch (e) { el('cmdStatus').textContent = e.message; }
};

document.querySelectorAll('.cmdBtn').forEach(btn => btn.onclick = () => sendCommand(btn.dataset.cmd));
el('refreshBtn').onclick = () => loadDevices().catch(() => {}); el('deviceSearch').oninput = renderDeviceList;
(async () => { await loadDevices().catch(() => {}); mqttConnect(); setInterval(() => loadDevices().catch(() => {}), Math.max(5, APP.autoRefreshSec || 10) * 1000); })();
