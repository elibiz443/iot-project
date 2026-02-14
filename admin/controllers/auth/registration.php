<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (is_logged_in()) redirect(ROOT_URL . '/admin/controllers/dashboard/index.php');

$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string) ($_POST['email'] ?? ''));
  $pass = (string) ($_POST['password'] ?? '');
  $pass2 = (string) ($_POST['password2'] ?? '');
  $csrf = (string) ($_POST['csrf'] ?? '');

  if (!csrf_check($csrf)) {
    flash_set('error', 'Invalid session. Refresh and try again.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  if ($email === '' || $pass === '' || $pass2 === '') {
    flash_set('error', 'All fields are required.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'Enter a valid email.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  if ($pass !== $pass2) {
    flash_set('error', 'Passwords do not match.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  if (strlen($pass) < 8) {
    flash_set('error', 'Password must be at least 8 characters.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  $st = $pdo->prepare("SELECT id FROM iot_users WHERE email = :e LIMIT 1");
  $st->execute([':e' => $email]);
  if ($st->fetch()) {
    flash_set('error', 'Email already registered.');
    redirect(ROOT_URL . '/admin/controllers/auth/registration.php');
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  $ins = $pdo->prepare("INSERT INTO iot_users (email, password_hash) VALUES (:e, :h)");
  $ins->execute([':e' => $email, ':h' => $hash]);

  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id' => (int) $pdo->lastInsertId(),
    'email' => $email,
  ];

  redirect(ROOT_URL . '/admin/controllers/dashboard/index.php');
}

$title = 'Register';
require ROOT_PATH . '/includes/header.php';
require ROOT_PATH . '/admin/views/auth/register.php';
require ROOT_PATH . '/includes/footer.php';
