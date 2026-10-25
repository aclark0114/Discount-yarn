<?php
// Cron (daily): marks checkout sessions older than a day that never paid as
// expired, in case Stripe's checkout.session.expired webhook was missed.
//   php -q /home/<user>/public_html/<domain>/cron/expire_pending.php

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';

$cutoff = date('Y-m-d H:i:s', time() - 86400);
$st = db()->prepare("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND created_at < ?");
$st->execute([$cutoff]);
echo $st->rowCount() . " pending orders expired\n";
