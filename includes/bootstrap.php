<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../config.php';
require_once ROOT_PATH . '/db/dbsetup.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

function is_logged_in(): bool {
  return isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id']);
}

function require_login(): void {
  if (!is_logged_in()) redirect(ROOT_URL . '/admin/controllers/auth/login.php');
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return (string) $_SESSION['csrf'];
}

function csrf_check(?string $t): bool {
  return isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function flash_set(string $k, string $v): void {
  $_SESSION['flash'][$k] = $v;
}

function flash_get(string $k): string {
  if (!isset($_SESSION['flash'][$k])) return '';
  $v = (string) $_SESSION['flash'][$k];
  unset($_SESSION['flash'][$k]);
  return $v;
}

try {
  $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  db_init($pdo);
} catch (Throwable $e) {
  if (isset($_GET['api'])) json_out(['ok' => false, 'error' => $e->getMessage()], 500);
  $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  require ROOT_PATH . '/includes/header.php';
  echo '<div class="min-h-screen flex items-center justify-center p-6">';
  echo '<div class="max-w-xl w-full rounded-2xl border border-slate-800 bg-slate-900/40 p-6">';
  echo '<div class="text-lg font-semibold">App failed to start</div>';
  echo '<div class="mt-2 text-sm text-slate-300">Database connection failed.</div>';
  echo '<div class="mt-4 rounded-xl bg-slate-950/40 border border-slate-800 p-4 text-xs text-slate-300 break-words">' . $msg . '</div>';
  echo '</div></div>';
  require ROOT_PATH . '/includes/footer.php';
  exit;
}
