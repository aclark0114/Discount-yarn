<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/stripe.php';

// Describes a key without revealing it: its type, mode and last 4 characters.
function key_shape(string $key, array $allowed): array
{
    if ($key === '' || str_contains($key, 'CHANGE_ME')) {
        return [false, 'Not set (still the placeholder).'];
    }
    if (preg_match('/\s/', $key)) {
        return [false, 'Contains a space or line break in the middle. Copy it again.'];
    }
    if (str_contains($key, '...') || str_contains($key, '…') || str_contains($key, '*')) {
        return [false, 'Looks like the masked version shown in the dashboard, not the full key. Create a new key and copy it right away.'];
    }
    foreach ($allowed as $prefix => $label) {
        if (str_starts_with($key, $prefix)) {
            return [true, $label . ', ending ' . substr($key, -4) . ', ' . strlen($key) . ' characters'];
        }
    }
    $seen = preg_match('/^([a-z]+_(live|test)_)/', $key, $m) ? $m[1] : substr($key, 0, 3) . '...';
    return [false, 'Starts with "' . $seen . '", which is the wrong kind of key here.'];
}

$secret = stripe_key('secret_key');
$pk = stripe_key('publishable_key');
$whsec = stripe_key('webhook_secret');

$rows = [['Config file', true, CONFIG_FILE . ' (the store reads this file; edit this one)']];
[$ok, $msg] = key_shape($secret, [
    'rk_live_' => 'Restricted live key', 'sk_live_' => 'Secret live key',
    'rk_test_' => 'Restricted test key', 'sk_test_' => 'Secret test key',
]);
if (str_starts_with($secret, 'pk_')) {
    [$ok, $msg] = [false, 'This is the publishable key. secret_key needs the secret or restricted key (sk_... or rk_...).'];
}
$rows[] = ['Secret key', $ok, $msg];
[$pkOk, $pkMsg] = key_shape($pk, ['pk_live_' => 'Publishable live key', 'pk_test_' => 'Publishable test key']);
$rows[] = ['Publishable key', $pkOk, $pkOk ? $pkMsg : $pkMsg . ' Without it, checkout uses Stripe\'s hosted page.'];
if ($ok && $pkOk) {
    $secretLive = str_contains(substr($secret, 0, 8), '_live_');
    $pkLive = str_starts_with($pk, 'pk_live_');
    $rows[] = ['Same mode', $secretLive === $pkLive, $secretLive === $pkLive
        ? 'Both ' . ($secretLive ? 'live' : 'test') . '.'
        : 'One key is live and the other is test. Use both live or both test.'];
}
[$whOk, $whMsg] = key_shape($whsec, ['whsec_' => 'Webhook signing secret']);
$rows[] = ['Webhook secret', $whOk, $whMsg];

if ($ok) {
    try {
        stripe_request('GET', 'checkout/sessions', ['limit' => 1]);
        $rows[] = ['Stripe accepts the key', true, 'Checkout Sessions can be read with this key.'];
    } catch (Throwable $ex) {
        $m = $ex->getMessage();
        $hint = stripos($m, 'Invalid API Key') !== false
            ? ' Stripe does not recognize this key at all: it was mistyped, copied from the masked dashboard view, or rolled/deleted. Create a new restricted key (Write on Checkout Sessions), copy it the moment it appears, and paste it into the config file above.'
            : (stripos($m, 'permission') !== false ? ' The key works but is missing a permission: give it Write on Checkout Sessions.' : '');
        $rows[] = ['Stripe accepts the key', false, $m . $hint];
    }
}

admin_header('Stripe check');
?>
<div class="head"><h1>Stripe check</h1></div>
<p>Checks the Stripe settings in the config without showing the keys. Only the type, mode and last 4 characters are shown.</p>
<div class="table-wrap">
<table class="report">
  <tbody>
  <?php foreach ($rows as [$label, $good, $detail]): ?>
    <tr>
      <th style="width:190px"><?= e($label) ?></th>
      <td><?= $good ? '<span style="color:#2f5d50;font-weight:700">OK</span>' : '<span class="flag">Problem</span>' ?></td>
      <td><?= e($detail) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php admin_footer();
