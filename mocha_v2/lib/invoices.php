<?php
declare(strict_types=1);
// Photographed invoices (data/invoices/): period summaries, a ZIP download for the accountant and, from the admin
// page, deleting the photos of a period to free disk space. Receipts, values and stock are never touched here.

const INVOICE_DIR = APP_ROOT . '/data/invoices';
const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const PHOTO_NAME = '/^factura-[0-9a-f-]+\.(jpg|png|webp)$/';

/** "2026-10-01" or "" from a period field; anything else is refused. */
function period_day(mixed $v, string $label): string
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') return '';
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) fail($label . ': dată invalidă.');
    return $v;
}

/** Period from the request (?from=&to=); empty ends are open. */
function invoice_period(array $src): array
{
    $from = period_day($src['from'] ?? '', 'De la');
    $to = period_day($src['to'] ?? '', 'Până la');
    if ($from !== '' && $to !== '' && $from > $to) fail('Perioada este inversată: „de la” e după „până la”.');
    return [$from, $to];
}

/**
 * Receipts with photos whose invoice date (or, when missing, receipt date) falls in the period, oldest first,
 * each with its photo files that still exist on disk.
 */
function invoice_receipts(string $from, string $to): array
{
    $q = db()->prepare(<<<SQL
        SELECT r.*, COALESCE(r.doc_date, substr(r.created_at, 1, 10)) AS day, COUNT(m.id) AS lines, SUM(m.cost) AS total
        FROM receipts r LEFT JOIN stock_moves m ON m.receipt_id = r.id
        WHERE r.photos <> '[]' AND COALESCE(r.doc_date, substr(r.created_at, 1, 10)) BETWEEN ? AND ?
        GROUP BY r.id ORDER BY day, r.id
    SQL);
    $q->execute([$from ?: '0000-01-01', $to ?: '9999-12-31']);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $files = [];
        foreach (json_decode($r['photos'], true) ?: [] as $name) {
            if (is_string($name) && preg_match(PHOTO_NAME, $name) && is_file(INVOICE_DIR . '/' . $name)) {
                $files[] = ['name' => $name, 'size' => filesize(INVOICE_DIR . '/' . $name)];
            }
        }
        $r['files'] = $files;
        $out[] = $r;
    }
    return $out;
}

/** Counts for a period, plus what is on the server in total. */
function invoice_info(string $from, string $to): array
{
    $sum = function (array $list): array {
        $photos = $bytes = 0;
        foreach ($list as $r) { $photos += count($r['files']); foreach ($r['files'] as $f) $bytes += $f['size']; }
        return ['receipts' => count($list), 'photos' => $photos, 'bytes' => $bytes,
            'first' => $list ? $list[0]['day'] : null, 'last' => $list ? $list[count($list) - 1]['day'] : null];
    };
    return ['period' => $sum(invoice_receipts($from, $to)), 'all' => $sum(invoice_receipts('', ''))];
}

/** ASCII piece of a file name: "HORECA SUPPLY S.R.L." -> "HORECA-SUPPLY-S-R-L". */
function file_slug(string $s, int $max = 40): string
{
    $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T']);
    return substr(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'), 0, $max);
}

/**
 * Stream the period's invoice photos as a ZIP, with facturi.csv listing the invoices. Files are stored, not
 * compressed (JPEG does not shrink further), so the archive is written straight to the browser without ZipArchive
 * or temporary files, whatever the number of photos.
 */
function invoice_zip(string $from, string $to): never
{
    $receipts = invoice_receipts($from, $to);
    if (!$receipts) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Nu există facturi fotografiate în perioada aleasă.'); }

    $csv = [["Data facturii", "Furnizor", "Nr. factură", "Data recepției", "Rânduri", "Valoare fără TVA (lei)", "Fișiere"]];
    $entries = [];
    foreach ($receipts as $r) {
        $base = $r['day'] . '_' . (file_slug($r['supplier']) ?: 'furnizor') . ($r['doc_no'] !== '' ? '_' . file_slug($r['doc_no'], 30) : '') . '_r' . $r['id'];
        $names = [];
        foreach ($r['files'] as $n => $f) {
            $names[] = $name = $base . (count($r['files']) > 1 ? '_p' . ($n + 1) : '') . '.' . pathinfo($f['name'], PATHINFO_EXTENSION);
            $entries[] = ['name' => $name, 'file' => INVOICE_DIR . '/' . $f['name']];
        }
        $csv[] = [$r['doc_date'] ?? '', $r['supplier'], $r['doc_no'], substr($r['created_at'], 0, 16), (string)(int)$r['lines'],
            $r['total'] !== null ? number_format((int)$r['total'] / 100, 2, ',', '') : '', implode(' ', $names)];
    }
    $lines = array_map(fn($row) => implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)), $csv);
    array_unshift($entries, ['name' => 'facturi.csv', 'data' => "\u{FEFF}" . implode("\r\n", $lines) . "\r\n"]);

    $label = ($from ?: $receipts[0]['day']) . '_' . ($to ?: $receipts[count($receipts) - 1]['day']);
    zip_stream('facturi-mocha_' . $label . '.zip', $entries);
}

/** Minimal ZIP writer (stored entries, UTF-8 names). $entries: [['name' =>, 'file' => path] | ['name' =>, 'data' => string]]. */
function zip_stream(string $filename, array $entries): never
{
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_clean();
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($entries as &$e) {
        $e['size'] = isset($e['data']) ? strlen($e['data']) : (int)filesize($e['file']);
        $e['crc'] = isset($e['data']) ? crc32($e['data']) : (int)hexdec(hash_file('crc32b', $e['file']));
    }
    unset($e);
    $total = 22;
    foreach ($entries as $e) $total += 30 + 46 + 2 * strlen($e['name']) + $e['size'];

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $total);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $central = '';
    $offset = 0;
    foreach ($entries as $e) {
        $len = strlen($e['name']);
        echo pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $e['crc'], $e['size'], $e['size'], $len, 0), $e['name'];
        if (isset($e['data'])) echo $e['data']; else readfile($e['file']);
        flush();
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $e['crc'], $e['size'], $e['size'], $len, 0, 0, 0, 0, 0, $offset) . $e['name'];
        $offset += 30 + $len + $e['size'];
    }
    echo $central, pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0);
    exit;
}

/** Delete the period's photo files (admin, to free space). Receipts keep their data; returns [files, bytes] removed. */
function invoice_delete(string $from, string $to): array
{
    $files = $bytes = 0;
    $set = db()->prepare('UPDATE receipts SET photos = ? WHERE id = ?');
    foreach (invoice_receipts($from, $to) as $r) {
        $left = [];
        foreach (json_decode($r['photos'], true) ?: [] as $name) {
            $path = INVOICE_DIR . '/' . $name;
            if (!is_string($name) || !preg_match(PHOTO_NAME, $name) || !is_file($path)) continue;
            $size = filesize($path);
            if (@unlink($path)) { $files++; $bytes += $size; } else $left[] = $name;
        }
        $set->execute([json_encode($left), $r['id']]);
    }
    return [$files, $bytes];
}
