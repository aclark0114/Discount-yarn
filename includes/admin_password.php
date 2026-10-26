<?php
// The admin password. It starts as admin.password_hash in the config; once
// changed in admin > Change password, the new hash is kept in
// discount-yarn-admin.json next to the config (outside public_html) and wins.
// Deleting that file goes back to the config password.

declare(strict_types=1);

const ADMIN_PASSWORD_MIN = 12;

function admin_password_file(): string
{
    return dirname(CONFIG_FILE) . '/discount-yarn-admin.json';
}

function admin_password_saved(bool $reload = false): ?array
{
    static $saved = false;
    if ($saved === false || $reload) {
        $data = is_file(admin_password_file()) ? json_decode((string)file_get_contents(admin_password_file()), true) : null;
        $saved = is_array($data) && !empty($data['password_hash']) ? $data : null;
    }
    return $saved;
}

function admin_password_verify(string $password): bool
{
    $hash = (string)(admin_password_saved()['password_hash'] ?? config('admin.password_hash'));
    return $hash !== '' && $hash !== 'CHANGE_ME' && password_verify($password, $hash);
}

// Changes whenever the password does; sessions from before a change end.
function admin_password_version(): string
{
    return (string)(admin_password_saved()['changed_at'] ?? '');
}

function admin_password_save(string $password): bool
{
    $path = admin_password_file();
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'changed_at' => date('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(4))]);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    admin_password_saved(true);
    return true;
}
