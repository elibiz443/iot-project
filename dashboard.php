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

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function init_db(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS iot_devices (
      device_id VARCHAR(128) PRIMARY KEY,
      online TINYINT(1) NOT NULL DEFAULT 0,
      last_seen DATETIME NULL,
      ip VARCHAR(64) NULL,
      last_telemetry JSON NULL,
      last_event JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS iot_telemetry (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      device_id VARCHAR(128) NOT NULL,
      ts DATETIME NOT NULL,
      ip VARCHAR(64) NULL,
      uptime_s INT NULL,
      cpu_temp_c FLOAT NULL,
      disk_used_pct FLOAT NULL,
      payload JSON NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_device_ts (device_id, ts),
      CONSTRAINT fk_tel_device FOREIGN KEY (device_id) REFERENCES iot_devices(device_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS iot_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      device_id VARCHAR(128) NOT NULL,
      ts DATETIME NOT NULL,
      faces INT NULL,
      labels JSON NULL,
      snapshot_url TEXT NULL,
      snapshot_path TEXT NULL,
      payload JSON NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_device_ts (device_id, ts),
      CONSTRAINT fk_evt_device FOREIGN KEY (device_id) REFERENCES iot_devices(device_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

$dsn = env_str('DB_DSN', 'mysql:host=localhost;dbname=iot;charset=utf8mb4');
$db_user = env_str('DB_USER', 'root');
$db_pass = env_str('DB_PASS', '');

try {
  $pdo = new PDO($dsn, $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  init_db($pdo);
} catch (Throwable $e) {
  if (isset($_GET['api'])) json_out(['ok' => false, 'error' => $e->getMessage()], 500);
  $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<script src="https://cdn.tailwindcss.com"></script><title>Dashboard Error</title></head><body class="bg-slate-950 text-slate-100">';
  echo '<div class="min-h-screen flex items-center justify-center p-6"><div class="max-w-xl w-full rounded-2xl border border-slate-800 bg-slate-900/40 p-6">';
  echo '<div class="text-lg font-semibold">Dashboard failed to start</div>';
  echo '<div class="mt-2 text-sm text-slate-300">Check DB credentials and PHP MySQL driver.</div>';
  echo '<div class="mt-4 rounded-xl bg-slate-950/40 border border-slate-800 p-4 text-xs text-slate-300 break-words">' . $msg . '</div>';
  echo '</div></div></body></html>';
  exit;
}

function safe_json_decode(?string $s) {
  if (!$s) return null;
  $d = json_decode($s, true);
  return is_array($d) ? $d : null;
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
        'last_telemetry' => safe_json_decode($r['last_telemetry']),
        'last_event' => safe_json_decode($r['last_event']),
      ];
    }
    json_out(['ok' => true, 'devices' => $devices]);
  }

  if ($api === 'history') {
    $device = isset($_GET['device_id']) ? (string) $_GET['device_id'] : '';
    if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);

    $tel_limit = isset($_GET['tel_limit']) ? (int) $_GET['tel_limit'] : 180;
    $evt_limit = isset($_GET['evt_limit']) ? (int) $_GET['evt_limit'] : 80;
    $tel_limit = max(30, min(2000, $tel_limit));
    $evt_limit = max(10, min(500, $evt_limit));

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
      $e['payload'] = safe_json_decode($e['payload']);
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
  'historyTelLimit' => env_int('DASH_TEL_LIMIT', 180),
  'historyEvtLimit' => env_int('DASH_EVT_LIMIT', 80),
  'autoRefreshSec' => env_int('DASH_AUTO_REFRESH_SEC', 20),
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
    <div class="sticky top-0 z-20 border-b border-slate-800 bg-slate-950/80 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
          <div class="h-10 w-10 rounded-2xl bg-slate-800 flex items-center justify-center shrink-0">
            <div class="h-3 w-3 rounded-full bg-emerald-400"></div>
          </div>
          <div class="min-w-0">
            <div class="text-lg font-semibold leading-tight truncate">IoT Vision Dashboard</div>
            <div class="text-xs text-slate-400 truncate" id="subhead">Telemetry + Vision Events</div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden sm:block text-xs text-slate-400">MQTT</div>
          <div id="mqttBadge" class="text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200">connecting</div>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-3 space-y-6">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-semibold">Devices</div>
            <div class="flex items-center gap-2">
              <button id="refreshBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Refresh</button>
              <button id="onlyOnlineBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30">Online</button>
            </div>
          </div>
          <div class="mt-3">
            <input id="deviceSearch" class="w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-sm outline-none focus:border-emerald-500/60" placeholder="Search device_id">
          </div>
          <div class="mt-3 space-y-2" id="deviceList"></div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-semibold">Latest Snapshot</div>
            <button id="openModalBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Open</button>
          </div>
          <div class="mt-3" id="latestEventBox">
            <div class="text-sm text-slate-400">Select a device</div>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="text-sm font-semibold">Connection</div>
          <div class="mt-2 text-xs text-slate-400 break-words" id="connInfo"></div>
        </div>
      </div>

      <div class="lg:col-span-9 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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
            <div class="text-xs text-slate-400">CPU</div>
            <div class="mt-1 text-base font-semibold" id="cpuTemp">—</div>
            <div class="mt-2 text-xs text-slate-400" id="cpuHint">°C</div>
          </div>

          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="text-xs text-slate-400">Disk</div>
            <div class="mt-1 text-base font-semibold" id="diskUsed">—</div>
            <div class="mt-2 text-xs text-slate-400" id="diskHint">used</div>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="flex items-center justify-between gap-3">
              <div class="text-sm font-semibold">CPU Temperature</div>
              <div class="text-xs text-slate-400" id="cpuChartHint">history</div>
            </div>
            <div class="mt-3">
              <canvas id="cpuChart" height="130"></canvas>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
            <div class="flex items-center justify-between gap-3">
              <div class="text-sm font-semibold">Disk Used %</div>
              <div class="text-xs text-slate-400" id="diskChartHint">history</div>
            </div>
            <div class="mt-3">
              <canvas id="diskChart" height="130"></canvas>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-semibold">Recent Events</div>
            <div class="flex items-center gap-2">
              <button id="clearLocalBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30">Clear</button>
              <button id="autoScrollBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Auto</button>
            </div>
          </div>
          <div class="mt-3 space-y-3" id="eventsList"></div>
        </div>
      </div>
    </div>
  </div>

  <div id="modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/70"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-4xl rounded-2xl border border-slate-800 bg-slate-950">
        <div class="flex items-center justify-between p-4 border-b border-slate-800">
          <div class="text-sm font-semibold" id="modalTitle">Snapshot</div>
          <button id="closeModalBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Close</button>
        </div>
        <div class="p-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-3">
            <img id="modalImg" class="w-full rounded-xl border border-slate-800 object-cover max-h-[420px]" src="" alt="">
            <div class="mt-2 text-xs text-slate-400 break-words" id="modalMeta"></div>
          </div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-3">
            <div class="text-sm font-semibold">Detections</div>
            <div class="mt-2 space-y-2" id="modalDetections"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
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
      if (imgSrc) {
        const img = document.createElement('img');
        img.src = imgSrc;
        img.className = 'mt-3 w-full rounded-xl border border-slate-800 object-cover max-h-56';
        img.onclick = () => openModal(evt);
        box.appendChild(top);
        box.appendChild(chips);
        box.appendChild(img);
      } else {
        box.appendChild(top);
        box.appendChild(chips);
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
        const key = (e.ts || '') + '|' + (dev.device_id || '');
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

      for (const e of combined.slice(0, APP.historyEvtLimit)) {
        box.appendChild(eventCard(e, !!e._live));
      }

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

    const closeModal = () => {
      el('modal').classList.add('hidden');
    };

    const loadDevices = async () => {
      const j = await apiGet('?api=devices');
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
      const url = `?api=history&device_id=${encodeURIComponent(deviceId)}&tel_limit=${encodeURIComponent(APP.historyTelLimit)}&evt_limit=${encodeURIComponent(APP.historyEvtLimit)}`;
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
      const opts = {};
      if (APP.mqttUser) opts.username = APP.mqttUser;
      if (APP.mqttPass) opts.password = APP.mqttPass;

      try {
        state.mqtt = mqtt.connect(APP.mqttWsUrl, opts);
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

    el('refreshBtn').onclick = async () => {
      await loadDevices().catch(() => {});
    };

    el('onlyOnlineBtn').onclick = () => {
      state.onlyOnline = !state.onlyOnline;
      el('onlyOnlineBtn').className = `text-xs px-2 py-1 rounded-lg border transition ${state.onlyOnline ? 'bg-emerald-500/10 border-emerald-500/60 text-emerald-300' : 'bg-slate-950/30 border-slate-800 hover:bg-slate-800/30'}`;
      renderDeviceList();
    };

    el('deviceSearch').oninput = () => renderDeviceList();

    el('autoScrollBtn').onclick = () => {
      state.autoScroll = !state.autoScroll;
      el('autoScrollBtn').textContent = state.autoScroll ? 'Auto' : 'Manual';
      el('autoScrollBtn').className = `text-xs px-2 py-1 rounded-lg transition ${state.autoScroll ? 'bg-slate-800 hover:bg-slate-700' : 'bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30'}`;
    };

    el('clearLocalBtn').onclick = () => {
      state.eventsLive = [];
      loadHistory(state.selected).catch(() => {});
    };

    el('openModalBtn').onclick = () => {
      const dev = getSelected();
      if (!dev || !dev.last_event) return;
      openModal(dev.last_event);
    };

    el('closeModalBtn').onclick = () => closeModal();
    el('modal').onclick = (e) => {
      if (e.target === el('modal')) closeModal();
    };
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeModal();
    });

    const setConnInfo = () => {
      const parts = [];
      parts.push(`ws: ${APP.mqttWsUrl}`);
      parts.push(`root: ${APP.topicRoot}`);
      el('connInfo').textContent = parts.join(' · ');
    };

    (async () => {
      setConnInfo();
      setMqttBadge('connecting');
      await loadDevices().catch(() => {});
      mqttConnect();
      setInterval(() => loadDevices().catch(() => {}), Math.max(10, APP.autoRefreshSec) * 1000);
    })();
  </script>
</body>
</html>
