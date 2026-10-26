<?php
require __DIR__ . '/_auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    sleep(1);
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $fails = (int)($_SESSION['password_fails'] ?? 0);
    if ($fails >= 5) {
        $error = 'Too many wrong current passwords. Log out and back in to try again.';
    } elseif (!admin_password_verify($current)) {
        $_SESSION['password_fails'] = $fails + 1;
        $error = 'Your current password didn\'t match.';
    } elseif (mb_strlen($new) < ADMIN_PASSWORD_MIN) {
        $error = 'The new password needs at least ' . ADMIN_PASSWORD_MIN . ' characters.';
    } elseif (!hash_equals($new, (string)($_POST['confirm'] ?? ''))) {
        $error = 'The two new passwords don\'t match.';
    } elseif (hash_equals($current, $new)) {
        $error = 'The new password is the same as the current one.';
    } elseif (!admin_password_save($new)) {
        $error = 'Couldn\'t save the new password. Check that the folder holding the config file is writable.';
    } else {
        unset($_SESSION['password_fails']);
        session_regenerate_id(true);
        $_SESSION['admin_pw'] = admin_password_version();
        flash('Password changed. Any other browser logged into admin has been logged out.');
        redirect('/admin/password.php');
    }
}

admin_header('Change password');
?>
<h1>Change password</h1>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form class="edit narrow-form" method="post" action="/admin/password.php">
  <?= csrf_field() ?>
  <input type="hidden" name="username" value="<?= e(config('admin.username')) ?>" autocomplete="username">
  <label>Current password <input name="current" type="password" autocomplete="current-password" required></label>
  <label>New password (at least <?= ADMIN_PASSWORD_MIN ?> characters) <input name="new" type="password" autocomplete="new-password" minlength="<?= ADMIN_PASSWORD_MIN ?>" required></label>
  <label>New password again <input name="confirm" type="password" autocomplete="new-password" minlength="<?= ADMIN_PASSWORD_MIN ?>" required></label>
  <p class="muted-note">Your username stays <strong><?= e(config('admin.username')) ?></strong>. Changing the password logs out any other browser that's logged in. A password manager or a short phrase of four or five random words works well.</p>
  <div class="actions"><button type="submit">Change password</button></div>
</form>
<?php admin_footer();
