<?php
require __DIR__ . '/../includes/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Slow down guessing: a 1 second delay per attempt and a lock after 10 misses.
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    if ($fails >= 10) {
        $error = 'Too many attempts. Close the browser and try again later.';
    } else {
        sleep(1);
        $user = (string)($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $hash = (string)config('admin.password_hash');
        if (hash_equals((string)config('admin.username'), $user) && $hash !== 'CHANGE_ME' && password_verify($pass, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['admin_seen'] = time();
            unset($_SESSION['login_fails']);
            redirect('/admin/');
        }
        $_SESSION['login_fails'] = $fails + 1;
        $error = 'That username and password did not match.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin login | <?= e(config('store_name')) ?></title>
<link rel="stylesheet" href="/assets/admin.css?v=6">
</head>
<body class="login-page">
<form class="login" method="post" action="/admin/login.php">
  <h1><?= e(config('store_name')) ?> admin</h1>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <?= csrf_field() ?>
  <label for="username">Username</label>
  <input id="username" name="username" autocomplete="username" required>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button type="submit">Log in</button>
</form>
</body>
</html>
