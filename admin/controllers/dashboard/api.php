<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

require_login();

function safe_json_decode(?string $s) {
  if (!$s) return null;
  $d = json_decode($s, true);
  return is_array($d) ? $d : null;
}

$api = isset($_GET['api']) ? (string) $_GET['api'] : '';
if ($api === '') json_out(['ok' => false, 'error' => 'api required'], 400);

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

  $tel_limit = isset($_GET['tel_limit']) ? (int) $_GET['tel_limit'] : DASH_TEL_LIMIT;
  $evt_limit = isset($_GET['evt_limit']) ? (int) $_GET['evt_limit'] : DASH_EVT_LIMIT;
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
