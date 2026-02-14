<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function env_str(string $k, string $d = ''): string {
  $v = getenv($k);
  return $v === false ? $d : $v;
}

function env_int(string $k, int $d): int {
  $v = getenv($k);
  if ($v === false) return $d;
  $n = filter_var($v, FILTER_VALIDATE_INT);
  return $n === false ? $d : (int) $n;
}

$dsn = env_str('DB_DSN', 'mysql:host=127.0.0.1;dbname=iot;charset=utf8mb4');
$db_user = env_str('DB_USER', 'root');
$db_pass = env_str('DB_PASS', '');

$pdo = new PDO($dsn, $db_user, $db_pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (isset($_GET['api'])) {
  $api = (string) $_GET['api'];

  if ($api === 'devices') {
    $rows = $pdo->query("
      SELECT device_id, online, last_seen, ip, last_telemetry, last_event, updated_at
      FROM iot_devices
      ORDER BY updated_at DESC
    ")->fetchAll();

    $devices = [];
    foreach ($rows as $r) {
      $devices[] = [
        'device_id' => $r['device_id'],
        'online' => (int) $r['online'],
        'last_seen' => $r['last_seen'],
        'ip' => $r['ip'],
        'last_telemetry' => $r['last_telemetry'] ? json_decode($r['last_telemetry'], true) : null,
        'last_event' => $r['last_event'] ? json_decode($r['last_event'], true) : null,
      ];
    }
    json_out(['ok' => true, 'devices' => $devices]);
  }

  if ($api === 'history') {
    $device = isset($_GET['device_id']) ? (string) $_GET['device_id'] : '';
    if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);

    $tel_limit = isset($_GET['tel_limit']) ? max(10, (int) $_GET['tel_limit']) : 120;
    $evt_limit = isset($_GET['evt_limit']) ? max(10, (int) $_GET['evt_limit']) : 50;

    $st_tel = $pdo->prepare("
      SELECT ts, cpu_temp_c, uptime_s, disk_used_pct, ip, payload
      FROM iot_telemetry
      WHERE device_id = :d
      ORDER BY ts DESC
      LIMIT :n
    ");
    $st_tel->bindValue(':d', $device, PDO::PARAM_STR);
    $st_tel->bindValue(':n', $tel_limit, PDO::PARAM_INT);
    $st_tel->execute();
    $tel = array_reverse($st_tel->fetchAll());

    $st_evt = $pdo->prepare("
      SELECT ts, faces, labels, snapshot_url, snapshot_path, payload
      FROM iot_events
      WHERE device_id = :d
      ORDER BY ts DESC
      LIMIT :n
    ");
    $st_evt->bindValue(':d', $device, PDO::PARAM_STR);
    $st_evt->bindValue(':n', $evt_limit, PDO::PARAM_INT);
    $st_evt->execute();
    $evt = $st_evt->fetchAll();

    foreach ($evt as &$e) {
      $e['labels'] = $e['labels'] ? json_decode($e['labels'], true) : [];
      $e['payload'] = $e['payload'] ? json_decode($e['payload'], true) : null;
    }

    json_out(['ok' => true, 'telemetry' => $tel, 'events' => $evt]);
  }

  json_out(['ok' => false, 'error' => 'unknown api'], 404);
}

$appConfig = [
  'mqttWsUrl' => env_str('MQTT_WS_URL', 'ws://127.0.0.1:9001'),
  'mqttUser' => env_str('MQTT_WS_USER', ''),
  'mqttPass' => env_str('MQTT_WS_PASS', ''),
  'topicRoot' => env_str('MQTT_TOPIC_ROOT', 'home/iot'),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <title>IoT Dashboard</title>
</head>
<body class="bg-slate-950 text-slate-100">
  <div class="min-h-screen">
    <div class="sticky top-0 z-10 border-b border-slate-800 bg-slate-950/80 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="h-9 w-9 rounded-xl bg-slate-800 flex items-center justify-center">
            <div class="h-3 w-3 rounded-full bg-emerald-400"></div>
          </div>
          <div>
            <div class="text-lg font-semibold leading-tight">IoT Vision Dashboard</div>
            <div class="text-xs text-slate-400" id="subhead">Telemetry + Vision Events</div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="text-xs text-slate-400">MQTT</div>
          <div id="mqttBadge" class="text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200">connecting</div>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-3">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-semibold">Devices</div>
            <button id="refreshBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Refresh</button>
          </div>
          <div class="mt-3 space-y-2" id="deviceList"></div>
        </div>

        <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="text-sm font-semibold">Latest Detection</div>
          <div class="mt-3 space-y-3" id="latestEventBox">
            <div class="text-sm text-slate-400">Select a device</div>
          </div>
        </div>
      </div>

      <div class="lg:col-span-9 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="text-xs text-slate-400">Status</div>
            <div class="mt-1 flex items-center gap-2">
              <div id="statusDot" class="h-2.5 w-2.5 rounded-full bg-slate-600"></div>
              <div id="statusText" class="text-base font-semibold">Unknown</div>
            </div>
            <div class="mt-2 text-xs text-slate-400" id="lastSeen">Last seen: —</div>
          </div>

          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="text-xs text-slate-400">Device</div>
            <div id="deviceId" class="mt-1 text-base font-semibold">—</div>
            <div class="mt-2 text-xs text-slate-400" id="deviceIp">IP: —</div>
            <div class="mt-1 text-xs text-slate-400" id="uptime">Uptime: —</div>
          </div>

          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="text-xs text-slate-400">Health</div>
            <div class="mt-1 text-base font-semibold" id="cpuTemp">CPU: —</div>
            <div class="mt-2 text-xs text-slate-400" id="disk">Disk: —</div>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-semibold">CPU Temperature</div>
            <div class="text-xs text-slate-400" id="chartHint">latest 120 points</div>
          </div>
          <div class="mt-3">
            <canvas id="cpuChart" height="110"></canvas>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-semibold">Recent Events</div>
            <div class="text-xs text-slate-400" id="eventsHint">latest 50</div>
          </div>
          <div class="mt-3 space-y-3" id="eventsList"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const APP = <?php echo json_encode($appConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    const state = {
      devices: {},
      selected: null,
      mqtt: null,
      chart: null
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

    const badge = (on) => {
      el('statusDot').className = `h-2.5 w-2.5 rounded-full ${on ? 'bg-emerald-400' : 'bg-rose-400'}`;
      el('statusText').textContent = on ? 'Online' : 'Offline';
    };

    const chip = (txt) => {
      const d = document.createElement('span');
      d.className = 'inline-flex items-center rounded-full bg-slate-800 px-2 py-1 text-xs text-slate-200';
      d.textContent = txt;
      return d;
    };

    const renderDeviceList = () => {
      const box = el('deviceList');
      box.innerHTML = '';
      const ids = Object.keys(state.devices).sort((a, b) => a.localeCompare(b));
      if (ids.length === 0) {
        const d = document.createElement('div');
        d.className = 'text-sm text-slate-400';
        d.textContent = 'No devices yet';
        box.appendChild(d);
        return;
      }
      for (const id of ids) {
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

    const renderSelected = () => {
      const id = state.selected;
      if (!id || !state.devices[id]) return;

      const dev = state.devices[id];
      el('deviceId').textContent = id;
      el('deviceIp').textContent = `IP: ${dev.ip || '—'}`;
      el('lastSeen').textContent = `Last seen: ${dev.last_seen ? fmtAgo(dev.last_seen) : '—'}`;

      badge(!!dev.online);

      const tel = dev.last_telemetry || null;
      if (tel) {
        el('uptime').textContent = `Uptime: ${typeof tel.uptime_s === 'number' ? tel.uptime_s + 's' : '—'}`;
        el('cpuTemp').textContent = `CPU: ${tel.cpu_temp_c ?? '—'}°C`;
        if (tel.disk && typeof tel.disk.used_pct !== 'undefined') {
          el('disk').textContent = `Disk: ${tel.disk.used_pct}% used (${tel.disk.used_mb ?? '—'}MB / ${tel.disk.total_mb ?? '—'}MB)`;
        } else {
          el('disk').textContent = 'Disk: —';
        }
      } else {
        el('uptime').textContent = 'Uptime: —';
        el('cpuTemp').textContent = 'CPU: —';
        el('disk').textContent = 'Disk: —';
      }

      const evtBox = el('latestEventBox');
      evtBox.innerHTML = '';
      const evt = dev.last_event || null;

      if (!evt) {
        const d = document.createElement('div');
        d.className = 'text-sm text-slate-400';
        d.textContent = 'No events yet';
        evtBox.appendChild(d);
      } else {
        const line1 = document.createElement('div');
        line1.className = 'flex items-center justify-between gap-2';
        const t = document.createElement('div');
        t.className = 'text-sm font-semibold';
        t.textContent = `Faces: ${evt.faces ?? 0}`;
        const when = document.createElement('div');
        when.className = 'text-xs text-slate-400';
        when.textContent = fmtAgo(evt.ts);
        line1.appendChild(t);
        line1.appendChild(when);

        const chips = document.createElement('div');
        chips.className = 'flex flex-wrap gap-2';
        const labels = Array.isArray(evt.labels) ? evt.labels : [];
        if (labels.length === 0) chips.appendChild(chip('no labels'));
        for (const l of labels.slice(0, 12)) chips.appendChild(chip(l));

        evtBox.appendChild(line1);
        evtBox.appendChild(chips);

        const imgSrc = evt.snapshot_url ? evt.snapshot_url : (evt.snapshot_b64 ? `data:image/jpeg;base64,${evt.snapshot_b64}` : '');
        if (imgSrc) {
          const img = document.createElement('img');
          img.src = imgSrc;
          img.className = 'mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-64';
          evtBox.appendChild(img);
        }

        if (Array.isArray(evt.detections) && evt.detections.length) {
          const det = document.createElement('div');
          det.className = 'mt-3 text-xs text-slate-300 space-y-1';
          for (const d of evt.detections.slice(0, 10)) {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-2';
            const a = document.createElement('div');
            a.className = 'truncate';
            a.textContent = `${d.label ?? 'obj'}`;
            const b = document.createElement('div');
            b.className = 'text-slate-400';
            b.textContent = typeof d.conf === 'number' ? `${Math.round(d.conf * 100)}%` : '';
            row.appendChild(a);
            row.appendChild(b);
            det.appendChild(row);
          }
          evtBox.appendChild(det);
        }
      }
    };

    const renderEventsList = (events) => {
      const box = el('eventsList');
      box.innerHTML = '';
      if (!events || events.length === 0) {
        const d = document.createElement('div');
        d.className = 'text-sm text-slate-400';
        d.textContent = 'No events';
        box.appendChild(d);
        return;
      }

      for (const e of events) {
        const card = document.createElement('div');
        card.className = 'rounded-2xl border border-slate-800 bg-slate-950/30 p-4';

        const top = document.createElement('div');
        top.className = 'flex items-center justify-between gap-2';
        const left = document.createElement('div');
        left.className = 'text-sm font-semibold';
        left.textContent = `Faces: ${e.faces ?? 0}`;
        const right = document.createElement('div');
        right.className = 'text-xs text-slate-400';
        right.textContent = fmtAgo(e.ts);
        top.appendChild(left);
        top.appendChild(right);

        const chips = document.createElement('div');
        chips.className = 'mt-2 flex flex-wrap gap-2';
        const labels = Array.isArray(e.labels) ? e.labels : [];
        if (labels.length === 0) chips.appendChild(chip('no labels'));
        for (const l of labels.slice(0, 10)) chips.appendChild(chip(l));

        card.appendChild(top);
        card.appendChild(chips);

        const p = e.payload || null;
        const imgSrc = p && p.snapshot_url ? p.snapshot_url : '';
        if (imgSrc) {
          const img = document.createElement('img');
          img.src = imgSrc;
          img.className = 'mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-64';
          card.appendChild(img);
        }

        box.appendChild(card);
      }
    };

    const ensureChart = () => {
      if (state.chart) return state.chart;
      const ctx = el('cpuChart').getContext('2d');
      state.chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: [],
          datasets: [{
            label: 'CPU °C',
            data: [],
            tension: 0.25,
            pointRadius: 0,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: true }
          },
          scales: {
            x: { ticks: { maxTicksLimit: 8 } },
            y: { beginAtZero: false }
          }
        }
      });
      return state.chart;
    };

    const renderChart = (telemetry) => {
      const ch = ensureChart();
      const labels = [];
      const data = [];
      for (const t of telemetry) {
        labels.push((t.ts || '').slice(11, 19));
        data.push(typeof t.cpu_temp_c === 'number' ? t.cpu_temp_c : null);
      }
      ch.data.labels = labels;
      ch.data.datasets[0].data = data;
      ch.update();
    };

    const loadDevices = async () => {
      const r = await fetch('?api=devices', { cache: 'no-store' });
      const j = await r.json();
      if (!j.ok) return;

      for (const d of j.devices) {
        state.devices[d.device_id] = state.devices[d.device_id] || {};
        Object.assign(state.devices[d.device_id], d);
      }

      if (!state.selected) {
        const ids = Object.keys(state.devices).sort((a, b) => a.localeCompare(b));
        if (ids.length) state.selected = ids[0];
      }

      renderDeviceList();
      renderSelected();
      if (state.selected) await loadHistory(state.selected);
    };

    const loadHistory = async (deviceId) => {
      const url = `?api=history&device_id=${encodeURIComponent(deviceId)}&tel_limit=120&evt_limit=50`;
      const r = await fetch(url, { cache: 'no-store' });
      const j = await r.json();
      if (!j.ok) return;
      renderChart(j.telemetry || []);
      renderEventsList(j.events || []);
    };

    const selectDevice = async (id) => {
      state.selected = id;
      renderDeviceList();
      renderSelected();
      await loadHistory(id);
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

    const mqttConnect = () => {
      const opts = {};
      if (APP.mqttUser) opts.username = APP.mqttUser;
      if (APP.mqttPass) opts.password = APP.mqttPass;

      state.mqtt = mqtt.connect(APP.mqttWsUrl, opts);

      state.mqtt.on('connect', () => {
        el('mqttBadge').textContent = 'connected';
        el('mqttBadge').className = 'text-xs px-2 py-1 rounded-lg bg-emerald-500/15 text-emerald-300';
        state.mqtt.subscribe(`${APP.topicRoot}/+/telemetry`);
        state.mqtt.subscribe(`${APP.topicRoot}/+/events`);
        state.mqtt.subscribe(`${APP.topicRoot}/+/status/online`);
      });

      state.mqtt.on('reconnect', () => {
        el('mqttBadge').textContent = 'reconnecting';
        el('mqttBadge').className = 'text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200';
      });

      state.mqtt.on('close', () => {
        el('mqttBadge').textContent = 'disconnected';
        el('mqttBadge').className = 'text-xs px-2 py-1 rounded-lg bg-rose-500/15 text-rose-300';
      });

      state.mqtt.on('error', () => {
        el('mqttBadge').textContent = 'error';
        el('mqttBadge').className = 'text-xs px-2 py-1 rounded-lg bg-rose-500/15 text-rose-300';
      });

      state.mqtt.on('message', (topic, message) => {
        const deviceId = getDeviceFromTopic(topic);
        const type = getTypeFromTopic(topic);
        if (!deviceId || !type) return;

        state.devices[deviceId] = state.devices[deviceId] || { device_id: deviceId, online: 0 };

        if (type === 'online') {
          const on = message.toString().trim() === '1';
          state.devices[deviceId].online = on ? 1 : 0;
          state.devices[deviceId].last_seen = new Date().toISOString();
        }

        if (type === 'telemetry') {
          try {
            const data = JSON.parse(message.toString());
            state.devices[deviceId].last_telemetry = data;
            state.devices[deviceId].ip = data.ip || state.devices[deviceId].ip || null;
            state.devices[deviceId].last_seen = data.ts || new Date().toISOString();
            state.devices[deviceId].online = 1;
            if (state.selected === deviceId) renderSelected();
          } catch (e) {}
        }

        if (type === 'events') {
          try {
            const data = JSON.parse(message.toString());
            state.devices[deviceId].last_event = data;
            state.devices[deviceId].last_seen = data.ts || new Date().toISOString();
            state.devices[deviceId].online = 1;
            if (state.selected === deviceId) renderSelected();
          } catch (e) {}
        }

        if (!state.selected) state.selected = deviceId;
        renderDeviceList();
      });
    };

    el('refreshBtn').onclick = async () => {
      await loadDevices();
    };

    (async () => {
      await loadDevices();
      mqttConnect();
    })();
  </script>
</body>
</html>
