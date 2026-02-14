<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

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

function iso_to_mysql(?string $iso): string {
  if (!$iso) return gmdate('Y-m-d H:i:s');
  try {
    $dt = new DateTimeImmutable($iso);
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return gmdate('Y-m-d H:i:s');
  }
}

function safe_json_decode(string $s): array {
  $d = json_decode($s, true);
  return is_array($d) ? $d : [];
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

$dsn = env_str('DB_DSN', 'mysql:host=127.0.0.1;dbname=iot;charset=utf8mb4');
$db_user = env_str('DB_USER', 'root');
$db_pass = env_str('DB_PASS', '');

$pdo = new PDO($dsn, $db_user, $db_pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

init_db($pdo);

$server = env_str('MQTT_HOST', '127.0.0.1');
$port = env_int('MQTT_PORT', 1883);
$clientId = env_str('MQTT_CLIENT_ID', 'php_iot_worker_' . bin2hex(random_bytes(4)));
$mqtt_user = env_str('MQTT_USER', '');
$mqtt_pass = env_str('MQTT_PASS', '');
$keepAlive = env_int('MQTT_KEEPALIVE', 60);

$topicRoot = env_str('MQTT_TOPIC_ROOT', 'home/iot');

$st_upsert_device = $pdo->prepare("
  INSERT INTO iot_devices (device_id, online, last_seen, ip, last_telemetry, last_event)
  VALUES (:device_id, :online, :last_seen, :ip, :last_telemetry, :last_event)
  ON DUPLICATE KEY UPDATE
    online = VALUES(online),
    last_seen = VALUES(last_seen),
    ip = COALESCE(VALUES(ip), ip),
    last_telemetry = COALESCE(VALUES(last_telemetry), last_telemetry),
    last_event = COALESCE(VALUES(last_event), last_event)
");

$st_update_online = $pdo->prepare("
  INSERT INTO iot_devices (device_id, online, last_seen)
  VALUES (:device_id, :online, :last_seen)
  ON DUPLICATE KEY UPDATE
    online = VALUES(online),
    last_seen = VALUES(last_seen)
");

$st_insert_tel = $pdo->prepare("
  INSERT INTO iot_telemetry (device_id, ts, ip, uptime_s, cpu_temp_c, disk_used_pct, payload)
  VALUES (:device_id, :ts, :ip, :uptime_s, :cpu_temp_c, :disk_used_pct, :payload)
");

$st_insert_evt = $pdo->prepare("
  INSERT INTO iot_events (device_id, ts, faces, labels, snapshot_url, snapshot_path, payload)
  VALUES (:device_id, :ts, :faces, :labels, :snapshot_url, :snapshot_path, :payload)
");

function parse_topic(string $topic): array {
  $p = explode('/', trim($topic, '/'));
  if (count($p) < 4) return ['', ''];
  if ($p[0] !== 'home' || $p[1] !== 'iot') return ['', ''];
  $device = $p[2];
  $type = $p[3] ?? '';
  if ($type === 'status') {
    $type = ($p[4] ?? '') === 'online' ? 'online' : 'status';
  }
  return [$device, $type];
}

while (true) {
  try {
    $mqtt = new MqttClient($server, $port, $clientId);

    $settings = (new ConnectionSettings())
      ->setKeepAliveInterval($keepAlive)
      ->setUseCleanSession(false);

    if ($mqtt_user !== '') {
      $settings = $settings->setUsername($mqtt_user)->setPassword($mqtt_pass);
    }

    $mqtt->connect($settings, true);

    $mqtt->subscribe($topicRoot . '/+/telemetry', function (string $topic, string $message) use ($pdo, $st_upsert_device, $st_insert_tel) {
      [$device, $type] = parse_topic($topic);
      if ($device === '' || $type !== 'telemetry') return;

      $data = safe_json_decode($message);
      $ts = iso_to_mysql($data['ts'] ?? null);

      $ip = $data['ip'] ?? null;
      $uptime = isset($data['uptime_s']) ? (int) $data['uptime_s'] : null;
      $cpu = isset($data['cpu_temp_c']) ? (float) $data['cpu_temp_c'] : null;

      $disk_used_pct = null;
      if (isset($data['disk']) && is_array($data['disk']) && isset($data['disk']['used_pct'])) {
        $disk_used_pct = (float) $data['disk']['used_pct'];
      }

      $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $pdo->beginTransaction();

      $st_upsert_device->execute([
        ':device_id' => $device,
        ':online' => 1,
        ':last_seen' => $ts,
        ':ip' => $ip,
        ':last_telemetry' => $payload,
        ':last_event' => null,
      ]);

      $st_insert_tel->execute([
        ':device_id' => $device,
        ':ts' => $ts,
        ':ip' => $ip,
        ':uptime_s' => $uptime,
        ':cpu_temp_c' => $cpu,
        ':disk_used_pct' => $disk_used_pct,
        ':payload' => $payload,
      ]);

      $pdo->commit();
    }, 1);

    $mqtt->subscribe($topicRoot . '/+/events', function (string $topic, string $message) use ($pdo, $st_upsert_device, $st_insert_evt) {
      [$device, $type] = parse_topic($topic);
      if ($device === '' || $type !== 'events') return;

      $data = safe_json_decode($message);
      $ts = iso_to_mysql($data['ts'] ?? null);

      $faces = isset($data['faces']) ? (int) $data['faces'] : null;
      $labels = isset($data['labels']) && is_array($data['labels']) ? json_encode($data['labels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
      $snapshot_url = isset($data['snapshot_url']) ? (string) $data['snapshot_url'] : null;
      $snapshot_path = isset($data['snapshot_path']) ? (string) $data['snapshot_path'] : null;

      $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $pdo->beginTransaction();

      $st_upsert_device->execute([
        ':device_id' => $device,
        ':online' => 1,
        ':last_seen' => $ts,
        ':ip' => $data['ip'] ?? null,
        ':last_telemetry' => null,
        ':last_event' => $payload,
      ]);

      $st_insert_evt->execute([
        ':device_id' => $device,
        ':ts' => $ts,
        ':faces' => $faces,
        ':labels' => $labels,
        ':snapshot_url' => $snapshot_url,
        ':snapshot_path' => $snapshot_path,
        ':payload' => $payload,
      ]);

      $pdo->commit();
    }, 1);

    $mqtt->subscribe($topicRoot . '/+/status/online', function (string $topic, string $message) use ($st_update_online) {
      [$device, $type] = parse_topic($topic);
      if ($device === '' || $type !== 'online') return;

      $online = trim($message) === '1' ? 1 : 0;
      $st_update_online->execute([
        ':device_id' => $device,
        ':online' => $online,
        ':last_seen' => gmdate('Y-m-d H:i:s'),
      ]);
    }, 1);

    $mqtt->loop(true);
  } catch (Throwable $e) {
    fwrite(STDERR, gmdate('c') . " worker error: " . $e->getMessage() . PHP_EOL);
    sleep(3);
  }
}
