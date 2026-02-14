<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], '', (bool) $p['secure'], (bool) $p['httponly']);
}
session_destroy();

redirect(ROOT_URL . '/index.php');
