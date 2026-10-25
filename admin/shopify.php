<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/shopify_sync.php';

$rows = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['reset_storefront'])) {
        try {
            shopify_cache_delete('storefront_token.json');
            flash('Cached Storefront token removed. The next check creates a new one.');
        } catch (ShopifyError $ex) {
            flash($ex->getMessage());
        }
        redirect('/admin/shopify.php');
    }
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
<p>Checks the Stars Hollow Shopify app: gets a fresh Admin API token, reads the shop name and product count, then makes a Storefront API call. Tokens are never shown.</p>

<form method="post" action="/admin/shopify.php" class="actions">
  <?= csrf_field() ?>
  <button type="submit">Run connection test</button>
  <button type="submit" name="sync" value="1">Sync catalog now</button>
  <button type="submit" name="reset_storefront" value="1" class="danger">Forget Storefront token</button>
</form>

<?php if ($rows !== null): ?>
<div class="table-wrap" style="margin-top:16px">
<table>
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
