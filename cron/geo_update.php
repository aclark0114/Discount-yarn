<?php
// Cron (monthly): downloads the DB-IP "IP to City Lite" database (CC BY 4.0,
// https://db-ip.com) used for the visitor locations in admin > Visitors.
// DB-IP publishes a new file at the start of each month; if this month's is
// not out yet, last month's is used. The old file is only replaced after the
// new one opens and answers a test lookup.
//   php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/geo_update.php

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/geoip.php';

set_time_limit(0);

function geo_update_fail(string $why, array $cleanup = []): void
{
    foreach ($cleanup as $f) {
        if (is_string($f) && is_file($f)) {
            @unlink($f);
        }
    }
    echo 'Geo database NOT updated: ' . $why . "\n";
    exit(1);
}

$dest = geo_db_path();
if ($dest === '') {
    geo_update_fail('set cache_dir (or geoip_db) in the config');
}
$dir = dirname($dest);
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    geo_update_fail('folder does not exist and could not be created');
}
if (!is_writable($dir)) {
    geo_update_fail('folder is not writable');
}

$base = rtrim(getenv('GEOIP_TEST_URL') ?: 'https://download.db-ip.com/free', '/');
$gz  = $dest . '.' . getmypid() . '.gz.tmp';
$tmp = $dest . '.' . getmypid() . '.tmp';

$months = [date('Y-m'), date('Y-m', strtotime('first day of last month'))];
$got = '';
foreach ($months as $month) {
    $out = fopen($gz, 'wb');
    if (!$out) {
        geo_update_fail('cannot write a temporary file', [$gz]);
    }
    $ch = curl_init($base . '/dbip-city-lite-' . $month . '.mmdb.gz');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 900,   // the file is large and shared hosts can be slow
        CURLOPT_FAILONERROR    => false,
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($out);
    if ($ok && $code === 200) {
        $got = $month;
        break;
    }
    if ($ok && $code === 404) {
        continue;                              // not published yet: try last month
    }
    geo_update_fail($ok ? 'download answered HTTP ' . $code : 'download failed (' . $err . ')', [$gz]);
}
if ($got === '') {
    geo_update_fail('no file found for ' . implode(' or ', $months), [$gz]);
}

// Stream-decompress next to the old file.
$in = @gzopen($gz, 'rb');
$w = @fopen($tmp, 'wb');
if (!$in || !$w) {
    geo_update_fail('cannot decompress the download', [$gz, $tmp]);
}
while (!gzeof($in)) {
    $chunk = gzread($in, 1048576);
    if ($chunk === false || ($chunk !== '' && fwrite($w, $chunk) !== strlen($chunk))) {
        fclose($w);
        gzclose($in);
        geo_update_fail('decompressing failed (disk full?)', [$gz, $tmp]);
    }
}
gzclose($in);
fclose($w);
@unlink($gz);

// The new file must open and know Google DNS (8.8.8.8) is in the US.
try {
    $rec = (new GeoMmdb($tmp))->find('8.8.8.8');
    $cc = is_array($rec) ? ($rec['country']['iso_code'] ?? '') : '';
} catch (Throwable $ex) {
    geo_update_fail('the download is not a usable database (' . $ex->getMessage() . ')', [$tmp]);
}
if ($cc !== 'US') {
    geo_update_fail('the download failed its test lookup', [$tmp]);
}

@chmod($tmp, 0600);
if (!@rename($tmp, $dest)) {
    geo_update_fail('could not replace the old database', [$tmp]);
}
echo 'Geo database updated to ' . $got . ' (' . number_format((int)filesize($dest)) . " bytes)\n";
