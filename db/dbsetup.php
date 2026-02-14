<?php
declare(strict_types=1);

function db_init(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS iot_users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(190) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

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
