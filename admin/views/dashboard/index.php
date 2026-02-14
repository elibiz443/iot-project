<?php
declare(strict_types=1);

$appConfig = [
  'mqttWsUrl' => MQTT_WS_URL,
  'mqttUser' => MQTT_WS_USER,
  'mqttPass' => MQTT_WS_PASS,
  'topicRoot' => MQTT_TOPIC_ROOT,
  'historyTelLimit' => DASH_TEL_LIMIT,
  'historyEvtLimit' => DASH_EVT_LIMIT,
  'autoRefreshSec' => DASH_AUTO_REFRESH_SEC,
  'apiBase' => ROOT_URL . '/admin/controllers/dashboard/api.php',
  'logoutUrl' => ROOT_URL . '/admin/controllers/auth/logout.php',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?php echo ROOT_URL; ?>/assets/css/output.css" rel="stylesheet">

  <!-- Font -->
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Lato&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400..700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/x-icon" href="<?php echo ROOT_URL; ?>/assets/images/favicon.webp" />
  <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <title>IoT Dashboard</title>
</head>
<body class="bg-slate-950 text-slate-100 max-w-full overflow-x-hidden">
  <div class="min-h-screen">
    <div class="sticky top-0 z-20 border-b border-slate-800 bg-slate-950/80 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
          <div class="h-10 w-10 rounded-2xl bg-slate-800 flex items-center justify-center shrink-0">
            <div class="h-3 w-3 rounded-full bg-emerald-400"></div>
          </div>
          <div class="min-w-0">
            <div class="text-lg font-semibold leading-tight truncate">IoT Vision Dashboard</div>
            <div class="text-xs text-slate-400 truncate">Telemetry + Vision Events</div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden sm:block text-xs text-slate-400">MQTT</div>
          <div id="mqttBadge" class="text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200">connecting</div>
          <a href="<?php echo htmlspecialchars($appConfig['logoutUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="text-xs px-2 py-1 rounded-lg bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30">Logout</a>
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
            <div class="mt-2 text-xs text-slate-400">°C</div>
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

  <script src="<?php echo ROOT_URL; ?>/assets/js/dashboard.js"></script>
</body>
</html>
