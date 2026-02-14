<?php
declare(strict_types=1);

$server = $_SERVER['SERVER_NAME'] ?? 'localhost';
$isLocal = ($server === 'localhost' || $server === '127.0.0.1');

define('ROOT_URL', $isLocal ? 'http://localhost/iot_project' : 'https://iot.ellyambet.com');
define('ROOT_PATH', $isLocal ? ($_SERVER['DOCUMENT_ROOT'] . '/iot_project') : dirname(__FILE__));

if ($isLocal) {
  define('DB_DSN', getenv('DB_DSN') !== false ? (string) getenv('DB_DSN') : 'mysql:host=localhost;dbname=iot;charset=utf8mb4');
  define('DB_USER', getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'root');
  define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
} else {
  define('DB_DSN', getenv('DB_DSN') !== false ? (string) getenv('DB_DSN') : 'mysql:host=localhost;dbname=ellyamb1_iot;charset=utf8mb4');
  define('DB_USER', getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'ellyamb1_iot');
  define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'VtU9dL4hgV7dF98MrcBQ');
}

define('MQTT_TOPIC_ROOT', getenv('MQTT_TOPIC_ROOT') !== false ? (string) getenv('MQTT_TOPIC_ROOT') : 'home/iot');
define('MQTT_WS_URL', getenv('MQTT_WS_URL') !== false ? (string) getenv('MQTT_WS_URL') : '');
define('MQTT_WS_USER', getenv('MQTT_WS_USER') !== false ? (string) getenv('MQTT_WS_USER') : '');
define('MQTT_WS_PASS', getenv('MQTT_WS_PASS') !== false ? (string) getenv('MQTT_WS_PASS') : '');

define('DASH_TEL_LIMIT', getenv('DASH_TEL_LIMIT') !== false ? (int) getenv('DASH_TEL_LIMIT') : 180);
define('DASH_EVT_LIMIT', getenv('DASH_EVT_LIMIT') !== false ? (int) getenv('DASH_EVT_LIMIT') : 80);
define('DASH_AUTO_REFRESH_SEC', getenv('DASH_AUTO_REFRESH_SEC') !== false ? (int) getenv('DASH_AUTO_REFRESH_SEC') : 20);
