<?php
// Coarse visitor location from a local IP geolocation file (DB-IP "IP to City
// Lite", MaxMind DB format, CC BY 4.0, https://db-ip.com). A small pure-PHP
// reader: no extensions, no Composer, and the file is read with seeks, never
// loaded whole. The cron/geo_update.php job downloads the file every month.
// Only country, region and city are ever kept; the IP address is not stored.

declare(strict_types=1);

// Reads one MaxMind DB file. See https://maxmind.github.io/MaxMind-DB/
final class GeoMmdb
{
    private $fh;
    private int $size;
    private string $buf = '';
    private int $bufAt = -1;
    private int $base = 0;           // absolute offset that pointers and decode offsets are relative to
    private int $nodeCount;
    private int $recordSize;
    private int $nodeBytes;
    private int $treeSize;
    private int $ipVersion;
    private ?int $v4Start = null;
    public array $meta = [];

    public function __construct(string $path)
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('cannot open database');
        }
        $this->fh = $fh;
        $this->size = (int)(fstat($fh)['size'] ?? 0);
        $tailLen = min($this->size, 131072 + 14);
        fseek($fh, $this->size - $tailLen);
        $tail = (string)fread($fh, $tailLen);
        $at = strrpos($tail, "\xAB\xCD\xEFMaxMind.com");
        if ($at === false) {
            throw new RuntimeException('not a MaxMind DB file');
        }
        $this->base = $this->size - $tailLen + $at + 14;
        $off = 0;
        $meta = $this->decode($off);
        if (!is_array($meta)) {
            throw new RuntimeException('bad metadata');
        }
        $this->meta = $meta;
        $this->nodeCount  = (int)($meta['node_count'] ?? 0);
        $this->recordSize = (int)($meta['record_size'] ?? 0);
        $this->ipVersion  = (int)($meta['ip_version'] ?? 0);
        if ($this->nodeCount < 1 || !in_array($this->recordSize, [24, 28, 32], true) || !in_array($this->ipVersion, [4, 6], true)) {
            throw new RuntimeException('unsupported database');
        }
        $this->nodeBytes = intdiv($this->recordSize, 4);
        $this->treeSize  = $this->nodeBytes * $this->nodeCount;
        $this->base = $this->treeSize + 16;   // data section
        $this->buf = '';
        $this->bufAt = -1;
        if ($this->base > $this->size) {
            throw new RuntimeException('truncated database');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    private function bytes(int $abs, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        if ($this->bufAt < 0 || $abs < $this->bufAt || $abs + $n > $this->bufAt + strlen($this->buf)) {
            fseek($this->fh, $abs);
            $this->buf = (string)fread($this->fh, max($n, 4096));
            $this->bufAt = $abs;
        }
        $s = substr($this->buf, $abs - $this->bufAt, $n);
        if (strlen($s) !== $n) {
            throw new RuntimeException('read past end of database');
        }
        return $s;
    }

    private function uint(string $s): int
    {
        $v = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $v = ($v << 8) | ord($s[$i]);
        }
        return $v;
    }

    // Decodes the value at $off (relative to $this->base) and advances $off.
    private function decode(int &$off, int $depth = 0)
    {
        if ($depth > 16) {
            throw new RuntimeException('data nested too deeply');
        }
        $ctrl = ord($this->bytes($this->base + $off++, 1));
        $type = $ctrl >> 5;
        if ($type === 1) {                    // pointer
            $ss = ($ctrl >> 3) & 3;
            $vvv = $ctrl & 7;
            $raw = $this->bytes($this->base + $off, $ss + 1);
            $off += $ss + 1;
            $p = $this->uint($raw);
            if ($ss === 0) {
                $p |= $vvv << 8;
            } elseif ($ss === 1) {
                $p = ($p | ($vvv << 16)) + 2048;
            } elseif ($ss === 2) {
                $p = ($p | ($vvv << 24)) + 526336;
            }                                  // ss 3: four bytes, vvv ignored
            $at = $p;
            return $this->decode($at, $depth + 1);
        }
        if ($type === 0) {                    // extended type
            $type = 7 + ord($this->bytes($this->base + $off++, 1));
        }
        $size = $ctrl & 31;
        if ($size === 29) {
            $size = 29 + ord($this->bytes($this->base + $off++, 1));
        } elseif ($size === 30) {
            $size = 285 + $this->uint($this->bytes($this->base + $off, 2));
            $off += 2;
        } elseif ($size === 31) {
            $size = 65821 + $this->uint($this->bytes($this->base + $off, 3));
            $off += 3;
        }
        switch ($type) {
            case 7:                           // map
                $m = [];
                for ($i = 0; $i < $size; $i++) {
                    $k = $this->decode($off, $depth + 1);
                    $m[(string)$k] = $this->decode($off, $depth + 1);
                }
                return $m;
            case 11:                          // array
                $a = [];
                for ($i = 0; $i < $size; $i++) {
                    $a[] = $this->decode($off, $depth + 1);
                }
                return $a;
            case 14:                          // boolean: the size is the value
                return $size !== 0;
            case 12:                          // data cache container
            case 13:                          // end marker
                return null;
        }
        $raw = $this->bytes($this->base + $off, $size);
        $off += $size;
        switch ($type) {
            case 2: return $raw;              // utf8 string
            case 4: return $raw;              // bytes
            case 3:                           // double
                return $size === 8 ? unpack('E', $raw)[1] : 0.0;
            case 15:                          // float
                return $size === 4 ? unpack('G', $raw)[1] : 0.0;
            case 5:                           // uint16
            case 6:                           // uint32
                return $this->uint($raw);
            case 8:                           // int32 (4 bytes means signed)
                $v = $this->uint($raw);
                return $size === 4 && $v >= 0x80000000 ? $v - 0x100000000 : $v;
            case 9:                           // uint64: a string when it does not fit
                if ($size === 8 && (ord($raw[0]) & 0x80)) {
                    return sprintf('%u', unpack('J', $raw)[1]);
                }
                return $this->uint($raw);
            case 10:                          // uint128 as hex
                return '0x' . bin2hex($raw);
        }
        throw new RuntimeException('unknown data type ' . $type);
    }

    private function record(int $node, int $bit): int
    {
        $b = $this->bytes($node * $this->nodeBytes, $this->nodeBytes);
        $o = fn(int $i) => ord($b[$i]);
        switch ($this->recordSize) {
            case 24:
                $i = $bit ? 3 : 0;
                return ($o($i) << 16) | ($o($i + 1) << 8) | $o($i + 2);
            case 28:
                return $bit
                    ? (($o(3) & 0x0F) << 24) | ($o(4) << 16) | ($o(5) << 8) | $o(6)
                    : (($o(3) & 0xF0) << 20) | ($o(0) << 16) | ($o(1) << 8) | $o(2);
            default:
                $i = $bit ? 4 : 0;
                return ($o($i) << 24) | ($o($i + 1) << 16) | ($o($i + 2) << 8) | $o($i + 3);
        }
    }

    // Raw record for an IP (a valid IPv4 or IPv6 string), [] when the IP is in
    // no network, null when the address cannot be looked up in this file.
    public function find(string $ip): ?array
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
            $bin = substr($bin, 12);          // IPv4-mapped IPv6
        }
        $bits = strlen($bin) * 8;
        if ($bits === 128 && $this->ipVersion === 4) {
            return null;
        }
        $node = 0;
        if ($bits === 32 && $this->ipVersion === 6) {
            if ($this->v4Start === null) {    // IPv4 lives under 96 zero bits
                $n = 0;
                for ($i = 0; $i < 96 && $n < $this->nodeCount; $i++) {
                    $n = $this->record($n, 0);
                }
                $this->v4Start = $n;
            }
            $node = $this->v4Start;
        }
        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $node = $this->record($node, (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1);
        }
        if ($node === $this->nodeCount) {
            return [];
        }
        if ($node < $this->nodeCount) {
            return null;
        }
        $off = $node - $this->nodeCount - 16;
        if ($off < 0) {
            throw new RuntimeException('corrupt search tree');
        }
        $rec = $this->decode($off);
        return is_array($rec) ? $rec : [];
    }
}

// Where the geolocation file lives: config 'geoip_db' if set, else
// dbip-city-lite.mmdb in cache_dir (outside public_html).
function geo_db_path(): string
{
    $p = trim((string)config('geoip_db', ''));
    if ($p !== '') {
        return $p;
    }
    $dir = rtrim((string)config('cache_dir', ''), '/');
    return $dir === '' ? '' : $dir . '/dbip-city-lite.mmdb';
}

function geo_db_exists(): bool
{
    $p = geo_db_path();
    return $p !== '' && is_file($p) && is_readable($p);
}

// The reader for this request, or null with no database. Never throws.
function geo_reader(): ?GeoMmdb
{
    static $tried = false, $reader = null;
    if (!$tried) {
        $tried = true;
        if (geo_db_exists()) {
            try {
                $reader = new GeoMmdb(geo_db_path());
            } catch (Throwable $ex) {
                error_log('Geo lookup disabled: ' . $ex->getMessage());
            }
        }
    }
    return $reader;
}

function geo_name($node): string
{
    $names = is_array($node) && is_array($node['names'] ?? null) ? $node['names'] : [];
    $n = $names['en'] ?? (reset($names) ?: '');
    return is_string($n) ? $n : '';
}

// ['country' => 'US', 'country_name' => 'United States', 'region' => 'Connecticut',
// 'city' => 'Woodbury'] (empty strings where unknown), or null when there is
// no database, the address is private or reserved, or it is not an IP.
function geo_lookup(string $ip): ?array
{
    static $failed = false;
    if ($failed || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }
    try {
        $reader = geo_reader();
        $rec = $reader ? $reader->find($ip) : null;
    } catch (Throwable $ex) {
        $failed = true;                       // log once, then stop trying this request
        error_log('Geo lookup failed: ' . $ex->getMessage());
        return null;
    }
    if ($rec === null) {
        return null;
    }
    $country = $rec['country']['iso_code'] ?? '';
    $sub = is_array($rec['subdivisions'] ?? null) ? ($rec['subdivisions'][0] ?? null) : null;
    return [
        'country'      => is_string($country) ? strtoupper(substr($country, 0, 2)) : '',
        'country_name' => geo_name($rec['country'] ?? null),
        'region'       => geo_name($sub),
        'city'         => geo_name($rec['city'] ?? null),
    ];
}

// English name for an ISO 3166-1 country code; the code itself when unknown.
function geo_country_name(string $code): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (explode('|', 'AD=Andorra|AE=United Arab Emirates|AF=Afghanistan|AG=Antigua and Barbuda|AL=Albania|AM=Armenia|AO=Angola|AR=Argentina|AT=Austria|AU=Australia|AZ=Azerbaijan|BA=Bosnia and Herzegovina|BB=Barbados|BD=Bangladesh|BE=Belgium|BF=Burkina Faso|BG=Bulgaria|BH=Bahrain|BI=Burundi|BJ=Benin|BN=Brunei|BO=Bolivia|BR=Brazil|BS=Bahamas|BT=Bhutan|BW=Botswana|BY=Belarus|BZ=Belize|CA=Canada|CD=DR Congo|CG=Congo|CH=Switzerland|CI=Cote d\'Ivoire|CL=Chile|CM=Cameroon|CN=China|CO=Colombia|CR=Costa Rica|CU=Cuba|CV=Cabo Verde|CY=Cyprus|CZ=Czechia|DE=Germany|DJ=Djibouti|DK=Denmark|DO=Dominican Republic|DZ=Algeria|EC=Ecuador|EE=Estonia|EG=Egypt|ER=Eritrea|ES=Spain|ET=Ethiopia|FI=Finland|FJ=Fiji|FR=France|GA=Gabon|GB=United Kingdom|GE=Georgia|GH=Ghana|GL=Greenland|GM=Gambia|GN=Guinea|GR=Greece|GT=Guatemala|GU=Guam|GY=Guyana|HK=Hong Kong|HN=Honduras|HR=Croatia|HT=Haiti|HU=Hungary|ID=Indonesia|IE=Ireland|IL=Israel|IN=India|IQ=Iraq|IR=Iran|IS=Iceland|IT=Italy|JM=Jamaica|JO=Jordan|JP=Japan|KE=Kenya|KG=Kyrgyzstan|KH=Cambodia|KR=South Korea|KW=Kuwait|KZ=Kazakhstan|LA=Laos|LB=Lebanon|LI=Liechtenstein|LK=Sri Lanka|LR=Liberia|LS=Lesotho|LT=Lithuania|LU=Luxembourg|LV=Latvia|LY=Libya|MA=Morocco|MC=Monaco|MD=Moldova|ME=Montenegro|MG=Madagascar|MK=North Macedonia|ML=Mali|MM=Myanmar|MN=Mongolia|MO=Macao|MT=Malta|MU=Mauritius|MV=Maldives|MW=Malawi|MX=Mexico|MY=Malaysia|MZ=Mozambique|NA=Namibia|NE=Niger|NG=Nigeria|NI=Nicaragua|NL=Netherlands|NO=Norway|NP=Nepal|NZ=New Zealand|OM=Oman|PA=Panama|PE=Peru|PG=Papua New Guinea|PH=Philippines|PK=Pakistan|PL=Poland|PR=Puerto Rico|PS=Palestine|PT=Portugal|PY=Paraguay|QA=Qatar|RO=Romania|RS=Serbia|RU=Russia|RW=Rwanda|SA=Saudi Arabia|SD=Sudan|SE=Sweden|SG=Singapore|SI=Slovenia|SK=Slovakia|SL=Sierra Leone|SN=Senegal|SO=Somalia|SR=Suriname|SV=El Salvador|SY=Syria|TG=Togo|TH=Thailand|TJ=Tajikistan|TN=Tunisia|TR=Turkey|TT=Trinidad and Tobago|TW=Taiwan|TZ=Tanzania|UA=Ukraine|UG=Uganda|US=United States|UY=Uruguay|UZ=Uzbekistan|VE=Venezuela|VN=Vietnam|YE=Yemen|ZA=South Africa|ZM=Zambia|ZW=Zimbabwe') as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $map[$k] = $v;
        }
    }
    return $map[strtoupper($code)] ?? strtoupper($code);
}
