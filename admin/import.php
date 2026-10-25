<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/products.php';

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, PRODUCT_FIELDS);
    fputcsv($out, ['MAL-RIOS-001', 'Rios', 'Malabrigo', 'Worsted', '100% superwash merino', 'Azul Profundo', '210', '100',
        'Soft, round, machine washable.', '15.50', '10.99', '12', 'yes', 'no', '']);
    exit;
}

$report = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $file = $_FILES['csv'] ?? null;
    $mode = ($_POST['mode'] ?? '') === 'add_stock' ? 'add_stock' : 'set';
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $error = 'Choose a CSV file to import.';
    } else {
        $fh = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($fh);
        if ($header) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);  // Excel BOM
            $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        }
        if (!$header || !in_array('sku', $header, true)) {
            $error = 'The first row must be column names and include "sku". Download the template to see the format.';
        } else {
            $report = ['added' => 0, 'updated' => 0, 'errors' => []];
            $pdo = db();
            $pdo->beginTransaction();
            $line = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $line++;
                if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                    continue;
                }
                $in = [];
                foreach ($header as $i => $col) {
                    if (in_array($col, PRODUCT_FIELDS, true)) {
                        $in[$col] = $row[$i] ?? '';
                    }
                }
                // Blank cells keep the current value on updates.
                $in = array_filter($in, fn($c) => trim((string)$c) !== '');
                try {
                    $existing = isset($in['sku']) ? product_by_sku(trim($in['sku'])) : null;
                    if ($existing && $mode === 'add_stock' && isset($in['stock'])) {
                        $in['stock'] = (string)((int)$existing['stock'] + (int)$in['stock']);
                    }
                    $cols = product_from_input($in, $existing);
                    if (isset($in['image'])) {
                        // Image names refer to files already uploaded to uploads/.
                        $img = basename($in['image']);
                        $cols['image'] = is_file(__DIR__ . '/../uploads/' . $img) ? $img : ($existing['image'] ?? null);
                    }
                    save_product($cols, $existing ? (int)$existing['id'] : null);
                    $report[$existing ? 'updated' : 'added']++;
                } catch (InvalidArgumentException $ex) {
                    $report['errors'][] = 'Row ' . $line . ': ' . $ex->getMessage();
                }
            }
            $pdo->commit();
        }
        fclose($fh);
    }
}

admin_header('CSV import');
?>
<div class="head"><h1>Import inventory</h1></div>
<p>Upload a CSV to add new yarn or update existing yarn by SKU. Blank cells leave the current value alone, so a file with just <code>sku,stock</code> works for a stock count.</p>
<p><a href="/admin/import.php?template=1">Download the template</a> (columns: <?= e(implode(', ', PRODUCT_FIELDS)) ?>). Prices are in dollars. For photos, put the file name of an image already in <code>uploads/</code>, or add photos one at a time on the product page.</p>

<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if ($report): ?>
  <p class="notice">Added <?= $report['added'] ?>, updated <?= $report['updated'] ?><?= $report['errors'] ? ', skipped ' . count($report['errors']) : '' ?>.</p>
  <?php if ($report['errors']): ?><ul class="error-list"><?php foreach ($report['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>

<form class="edit" method="post" enctype="multipart/form-data" action="/admin/import.php">
  <?= csrf_field() ?>
  <label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label>
  <fieldset>
    <legend>Stock column</legend>
    <label class="check"><input type="radio" name="mode" value="set" checked> Replace stock with the file's number</label>
    <label class="check"><input type="radio" name="mode" value="add_stock"> Add the file's number to current stock (for new shipments)</label>
  </fieldset>
  <button type="submit">Import</button>
</form>
<?php admin_footer();
