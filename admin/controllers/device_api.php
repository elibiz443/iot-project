<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_device_token();

$api = isset($_GET['api']) ? (string) $_GET['api'] : '';
if ($api === '') json_out(['ok' => false, 'error' => 'api required'], 400);

if ($api === 'ingest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = input_json();
  $kind = (string) ($in['kind'] ?? '');
  $device = trim((string) ($in['device_id'] ?? ''));
  if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);

  if ($kind === 'telemetry') {
    $payload = isset($in['payload']) && is_array($in['payload']) ? $in['payload'] : [];
    $ts = iso_to_mysql($payload['ts'] ?? null);
    $disk = (isset($payload['disk']) && is_array($payload['disk'])) ? $payload['disk'] : [];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    upsert_device($pdo, $device, [
      'online' => 1,
      'last_seen' => $ts,
      'ip' => $payload['ip'] ?? null,
      'last_telemetry' => $payloadJson,
    ]);
    $st = $pdo->prepare("INSERT INTO iot_telemetry (device_id, ts, ip, uptime_s, cpu_temp_c, disk_used_pct, payload) VALUES (:d, :ts, :ip, :up, :cpu, :disk, :p)");
    $st->execute([
      ':d' => $device, ':ts' => $ts, ':ip' => $payload['ip'] ?? null,
      ':up' => isset($payload['uptime_s']) ? (int) $payload['uptime_s'] : null,
      ':cpu' => isset($payload['cpu_temp_c']) ? (float) $payload['cpu_temp_c'] : null,
      ':disk' => isset($disk['used_pct']) ? (float) $disk['used_pct'] : null,
      ':p' => $payloadJson,
    ]);
    json_out(['ok' => true]);
  }

  if ($kind === 'event') {
    $payload = isset($in['payload']) && is_array($in['payload']) ? $in['payload'] : [];
    $ts = iso_to_mysql($payload['ts'] ?? null);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    upsert_device($pdo, $device, [
      'online' => 1,
      'last_seen' => $ts,
      'last_event' => $payloadJson,
      'ip' => $payload['ip'] ?? null,
    ]);
    $st = $pdo->prepare("INSERT INTO iot_events (device_id, ts, faces, labels, snapshot_url, snapshot_path, payload) VALUES (:d, :ts, :faces, :labels, :url, :path, :p)");
    $st->execute([
      ':d' => $device, ':ts' => $ts,
      ':faces' => isset($payload['faces']) ? (int) $payload['faces'] : null,
      ':labels' => isset($payload['labels']) && is_array($payload['labels']) ? json_encode($payload['labels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      ':url' => $payload['snapshot_url'] ?? null,
      ':path' => $payload['snapshot_path'] ?? null,
      ':p' => $payloadJson,
    ]);
    json_out(['ok' => true]);
  }

  if ($kind === 'online') {
    upsert_device($pdo, $device, ['online' => !empty($in['online']) ? 1 : 0, 'last_seen' => gmdate('Y-m-d H:i:s'), 'ip' => $in['ip'] ?? null]);
    json_out(['ok' => true]);
  }

  json_out(['ok' => false, 'error' => 'unknown ingest kind'], 400);
}

if ($api === 'upload_snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $device = trim((string) ($_POST['device_id'] ?? ''));
  if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);
  if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) json_out(['ok' => false, 'error' => 'image required'], 400);
  $dir = uploads_path('snapshots/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $device));
  $name = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
  $dest = $dir . '/' . $name;
  if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) json_out(['ok' => false, 'error' => 'upload failed'], 500);
  $url = uploads_url('snapshots/' . rawurlencode(preg_replace('/[^A-Za-z0-9_-]/', '_', $device)) . '/' . rawurlencode($name));
  json_out(['ok' => true, 'url' => $url, 'path' => $dest]);
}

if ($api === 'upload_live_frame' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $device = trim((string) ($_POST['device_id'] ?? ''));
  if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);
  if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) json_out(['ok' => false, 'error' => 'image required'], 400);
  $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $device);
  $dir = uploads_path('live/' . $safe);
  $name = 'current.jpg';
  $dest = $dir . '/' . $name;
  if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) json_out(['ok' => false, 'error' => 'upload failed'], 500);
  $url = uploads_url('live/' . rawurlencode($safe) . '/' . rawurlencode($name)) . '?v=' . time();
  upsert_device($pdo, $device, [
    'online' => 1,
    'last_seen' => gmdate('Y-m-d H:i:s'),
    'live_frame_url' => $url,
    'live_frame_path' => $dest,
    'live_frame_updated_at' => gmdate('Y-m-d H:i:s'),
  ]);
  json_out(['ok' => true, 'url' => $url]);
}

if ($api === 'commands_poll') {
  $device = trim((string) ($_GET['device_id'] ?? ''));
  if ($device === '') json_out(['ok' => false, 'error' => 'device_id required'], 400);
  $limit = max(1, min(100, (int) ($_GET['limit'] ?? DEVICE_COMMAND_POLL_LIMIT)));
  $st = $pdo->prepare("SELECT id, command_name, command_payload, queued_at FROM iot_commands WHERE device_id = :d AND status = 'queued' ORDER BY id ASC LIMIT :n");
  $st->bindValue(':d', $device, PDO::PARAM_STR);
  $st->bindValue(':n', $limit, PDO::PARAM_INT);
  $st->execute();
  $cmds = $st->fetchAll();
  $ids = [];
  foreach ($cmds as &$c) {
    $c['command_payload'] = safe_json_decode($c['command_payload']) ?? [];
    $ids[] = (int) $c['id'];
  }
  if ($ids) {
    $pdo->exec("UPDATE iot_commands SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id IN (" . implode(',', $ids) . ")");
  }
  json_out(['ok' => true, 'commands' => $cmds]);
}

if ($api === 'command_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = input_json();
  $device = trim((string) ($in['device_id'] ?? ''));
  $id = (int) ($in['command_id'] ?? 0);
  if ($device === '' || $id <= 0) json_out(['ok' => false, 'error' => 'device_id and command_id required'], 400);
  $status = (($in['status'] ?? 'ack') === 'failed') ? 'failed' : 'ack';
  $result = isset($in['result_payload']) && is_array($in['result_payload']) ? $in['result_payload'] : [];
  $st = $pdo->prepare("UPDATE iot_commands SET status = :s, result_payload = :r, acked_at = CURRENT_TIMESTAMP WHERE id = :id AND device_id = :d");
  $st->execute([':s' => $status, ':r' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => $id, ':d' => $device]);
  json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'unknown api'], 404);
