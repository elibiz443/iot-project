<?php
declare(strict_types=1);

$server = $_SERVER['SERVER_NAME'] ?? 'localhost';
$isLocal = ($server === 'localhost' || $server === '127.0.0.1');

$rootUrl = getenv('ROOT_URL');
$rootPath = getenv('ROOT_PATH');

if ($rootUrl === false || trim((string) $rootUrl) === '') {
    $rootUrl = $isLocal ? 'http://localhost/iot_project' : 'https://www.iot.ellyambet.com';
}

if ($rootPath === false || trim((string) $rootPath) === '') {
    $rootPath = $isLocal
        ? ($_SERVER['DOCUMENT_ROOT'] . '/iot_project')
        : $_SERVER['DOCUMENT_ROOT'];
}

define('ROOT_URL', rtrim((string) $rootUrl, '/'));
define('ROOT_PATH', rtrim((string) $rootPath, DIRECTORY_SEPARATOR));

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

if ($isLocal) {
    define('MQTT_WS_URL', getenv('MQTT_WS_URL') !== false ? (string) getenv('MQTT_WS_URL') : '');
} else {
    define('MQTT_WS_URL', getenv('MQTT_WS_URL') !== false ? (string) getenv('MQTT_WS_URL') : 'wss://www.iot.ellyambet.com/mqtt');
}

define('MQTT_WS_USER', getenv('MQTT_WS_USER') !== false ? (string) getenv('MQTT_WS_USER') : '');
define('MQTT_WS_PASS', getenv('MQTT_WS_PASS') !== false ? (string) getenv('MQTT_WS_PASS') : '');

define('DEVICE_SHARED_TOKEN', getenv('DEVICE_SHARED_TOKEN') !== false ? (string) getenv('DEVICE_SHARED_TOKEN') : 'fb78ad63a5d1587dfc1c55507eccb29300a840c9d040afa3e99e2ec32cf3b4b7');
define('DEVICE_HTTP_ENABLED', getenv('DEVICE_HTTP_ENABLED') !== false ? ((string) getenv('DEVICE_HTTP_ENABLED') !== '0') : true);
define('LIVE_FRAME_TTL_SEC', getenv('LIVE_FRAME_TTL_SEC') !== false ? (int) getenv('LIVE_FRAME_TTL_SEC') : 20);
define('DEVICE_COMMAND_POLL_LIMIT', getenv('DEVICE_COMMAND_POLL_LIMIT') !== false ? (int) getenv('DEVICE_COMMAND_POLL_LIMIT') : 20);

define('DASH_TEL_LIMIT', getenv('DASH_TEL_LIMIT') !== false ? (int) getenv('DASH_TEL_LIMIT') : 180);
define('DASH_EVT_LIMIT', getenv('DASH_EVT_LIMIT') !== false ? (int) getenv('DASH_EVT_LIMIT') : 80);
define('DASH_AUTO_REFRESH_SEC', getenv('DASH_AUTO_REFRESH_SEC') !== false ? (int) getenv('DASH_AUTO_REFRESH_SEC') : 10);
