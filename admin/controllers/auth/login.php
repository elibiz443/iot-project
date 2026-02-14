<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (is_logged_in()) redirect(ROOT_URL . '/admin/controllers/dashboard/index.php');

$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string) ($_POST['email'] ?? ''));
  $pass = (string) ($_POST['password'] ?? '');
  $csrf = (string) ($_POST['csrf'] ?? '');

  if (!csrf_check($csrf)) {
    flash_set('error', 'Invalid session. Refresh and try again.');
    redirect(ROOT_URL . '/admin/controllers/auth/login.php');
  }

  if ($email === '' || $pass === '') {
    flash_set('error', 'Email and password are required.');
    redirect(ROOT_URL . '/admin/controllers/auth/login.php');
  }

  $st = $pdo->prepare("SELECT id, email, password_hash FROM iot_users WHERE email = :e LIMIT 1");
  $st->execute([':e' => $email]);
  $u = $st->fetch();

  if (!$u || !password_verify($pass, (string) $u['password_hash'])) {
    flash_set('error', 'Invalid credentials.');
    redirect(ROOT_URL . '/admin/controllers/auth/login.php');
  }

  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id' => (int) $u['id'],
    'email' => (string) $u['email'],
  ];

  redirect(ROOT_URL . '/admin/controllers/dashboard/index.php');
}

$title = 'Login';
require ROOT_PATH . '/includes/header.php';
require ROOT_PATH . '/admin/views/auth/login.php';
require ROOT_PATH . '/includes/footer.php';
