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
  'csrf' => csrf_token(),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?php echo ROOT_URL; ?>/assets/css/output.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Lato&display=swap" rel="stylesheet">
  <link rel="icon" type="image/x-icon" href="<?php echo ROOT_URL; ?>/assets/images/favicon.webp" />
  <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <title>IoT Dashboard</title>
</head>
<body class="bg-slate-950 text-slate-100 max-w-full overflow-x-hidden">
  <div class="min-h-screen">
    <div class="sticky top-0 z-20 border-b border-slate-800 bg-slate-950/80 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center justify-between gap-4">
        <div>
          <div class="text-lg font-semibold leading-tight">IoT Vision Dashboard</div>
          <div class="text-xs text-slate-400">Live telemetry, event history, camera preview, and remote commands</div>
        </div>
        <div class="flex items-center gap-3">
          <div id="mqttBadge" class="text-xs px-2 py-1 rounded-lg bg-slate-800 text-slate-200">connecting</div>
          <a href="<?php echo ROOT_URL; ?>/admin/controllers/auth/logout.php" class="text-xs px-2 py-1 rounded-lg bg-slate-950/30 border border-slate-800 hover:bg-slate-800/30">Logout</a>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-3 space-y-6">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-semibold">Devices</div>
            <button id="refreshBtn" class="text-xs px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700">Refresh</button>
          </div>
          <div class="mt-3"><input id="deviceSearch" class="w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-sm" placeholder="Search device_id"></div>
          <div class="mt-3 space-y-2" id="deviceList"></div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="text-sm font-semibold">Latest event snapshot</div>
          <div class="mt-3" id="latestEventBox"><div class="text-sm text-slate-400">Select a device</div></div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
          <div class="text-sm font-semibold">Remote commands</div>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <button data-cmd="capture_now" class="cmdBtn text-xs px-2 py-2 rounded-lg bg-slate-800 hover:bg-slate-700">Capture now</button>
            <button data-cmd="refresh_status" class="cmdBtn text-xs px-2 py-2 rounded-lg bg-slate-800 hover:bg-slate-700">Refresh status</button>
            <button data-cmd="vision_on" class="cmdBtn text-xs px-2 py-2 rounded-lg bg-slate-800 hover:bg-slate-700">Vision on</button>
            <button data-cmd="vision_off" class="cmdBtn text-xs px-2 py-2 rounded-lg bg-slate-800 hover:bg-slate-700">Vision off</button>
          </div>
          <div class="mt-3 text-xs text-slate-400">Command payload (optional JSON)</div>
          <textarea id="cmdPayload" class="mt-2 h-24 w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-xs" placeholder='{"note":"hello"}'></textarea>
          <div id="cmdStatus" class="mt-2 text-xs text-slate-400">No command sent yet.</div>
          <div id="commandsList" class="mt-3 space-y-2"></div>
        </div>
      </div>

      <div class="lg:col-span-9 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-xs text-slate-400">Status</div><div class="mt-1 flex items-center gap-2"><div id="statusDot" class="h-2.5 w-2.5 rounded-full bg-slate-600"></div><div id="statusText" class="text-base font-semibold">Unknown</div></div><div class="mt-2 text-xs text-slate-400" id="lastSeen">Last seen: —</div></div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-xs text-slate-400">Device</div><div id="deviceId" class="mt-1 text-base font-semibold">—</div><div class="mt-2 text-xs text-slate-400" id="deviceIp">IP: —</div><div class="mt-1 text-xs text-slate-400" id="uptime">Uptime: —</div></div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-xs text-slate-400">CPU</div><div class="mt-1 text-base font-semibold" id="cpuTemp">—</div><div class="mt-2 text-xs text-slate-400">°C</div></div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-xs text-slate-400">Disk</div><div class="mt-1 text-base font-semibold" id="diskUsed">—</div><div class="mt-2 text-xs text-slate-400" id="diskHint">used</div></div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-sm font-semibold">Live camera preview</div><div class="mt-3 rounded-2xl border border-slate-800 bg-slate-950/40 p-3"><img id="liveFrame" class="w-full rounded-xl object-cover max-h-[460px] border border-slate-800" src="" alt="Live frame"><div id="liveFrameMeta" class="mt-2 text-xs text-slate-400">No live frame yet.</div></div></div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-sm font-semibold">Recent events</div><div class="mt-3 space-y-3 max-h-[520px] overflow-auto" id="eventsList"></div></div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-sm font-semibold">CPU Temperature</div><div class="mt-3"><canvas id="cpuChart" height="130"></canvas></div></div>
          <div class="rounded-2xl border border-slate-800 bg-slate-900/40 p-4"><div class="text-sm font-semibold">Disk Used %</div><div class="mt-3"><canvas id="diskChart" height="130"></canvas></div></div>
        </div>
      </div>
    </div>
  </div>

  <script>window.APP = <?php echo json_encode($appConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
  <script src="<?php echo ROOT_URL; ?>/assets/js/dashboard.js"></script>
</body>
</html>
