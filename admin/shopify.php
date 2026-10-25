<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/shopify_sync.php';

$rows = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['sync'])) {
        set_time_limit(600);
        try {
            $s = shopify_sync_catalog();
            flash(sprintf('Synced %d products (%d variants): %d added, %d updated, %d hidden.', $s['products'], $s['variants'], $s['added'], $s['updated'], $s['hidden']));
        } catch (Throwable $ex) {
            flash('Sync failed: ' . $ex->getMessage());
        }
        redirect('/admin/shopify.php');
    }
    $rows = shopify_connection_report();
}
$last = shopify_last_sync();

admin_header('Shopify connection');
?>
<div class="head"><h1>Shopify connection</h1></div>
<p>Last catalog sync: <?= $last ? e(date('M j, g:ia', (int)$last['finished_at'])) . ', ' . (int)$last['variants'] . ' variants from ' . (int)$last['products'] . ' products' : 'never' ?>. The cron job syncs every 15 minutes.</p>
<?php if (!shopify_has_admin_token()): ?>
  <p class="error">Not connected to Shopify's Admin API yet. <a href="/shopify/install.php">Connect to Shopify</a> and approve the app in the Stars Hollow Shopify admin. Until then the catalog syncs through the Storefront token only.</p>
<?php else: ?>
  <p>Connected to Shopify's Admin API<?= shopify_can_push_stock() ? '' : ' (read only: sold stock is lowered in Shopify by hand)' ?>. <a href="/shopify/install.php">Reconnect</a> needs the admin password.</p>
<?php endif; ?>
<p>The test shows the shop name and product count from the Storefront API, then stock for three products from the Admin API. Tokens are never shown.</p>

<form method="post" action="/admin/shopify.php" class="actions">
  <?= csrf_field() ?>
  <button type="submit">Run connection test</button>
  <button type="submit" name="sync" value="1">Sync catalog now</button>
</form>

<?php if ($rows !== null): ?>
<div class="table-wrap" style="margin-top:16px">
<table class="report">
  <tbody>
  <?php foreach ($rows as [$label, $ok, $detail]): ?>
    <tr>
      <th style="width:190px"><?= e($label) ?></th>
      <td><?= $ok ? '<span style="color:#2f5d50;font-weight:700">OK</span>' : '<span class="flag">Problem</span>' ?></td>
      <td><?= e($detail) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer();
