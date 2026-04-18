<?php
declare(strict_types=1);

/**
 * Importa el dataset bootstrap (tmp/peony_prices.json) a la DB.
 * Uso:
 *   php deploy/bin/import_bootstrap.php [path/to/peony_prices.json]
 *
 * Idempotente por día: si ya hay un registro en vsync_peony_files con la
 * misma file_date, se omite. Usar --force para re-importar.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/.app_config.php';
require_once $root . '/lib/db.php';
require_once $root . '/lib/schema.php';
require_once $root . '/lib/peony_parser.php';

$force = in_array('--force', $argv, true);
$args = array_values(array_filter($argv, fn($a, $i) => $i > 0 && $a !== '--force', ARRAY_FILTER_USE_BOTH));
$jsonPath = $args[0] ?? ($root . '/../tmp/peony_prices.json');
if (!is_file($jsonPath)) {
    fwrite(STDERR, "JSON not found: $jsonPath\n");
    exit(1);
}

$pdo = db();
initDB($pdo);

$payload = json_decode((string) file_get_contents($jsonPath), true);
if (!isset($payload['rows']) || !is_array($payload['rows'])) {
    fwrite(STDERR, "Invalid bootstrap JSON\n");
    exit(1);
}

$rowsByDate = [];
foreach ($payload['rows'] as $r) {
    $rowsByDate[$r['date']][] = $r;
}

$pdo->beginTransaction();
try {
    $stmtCheck = $pdo->prepare("SELECT id FROM vsync_peony_files WHERE file_date = :d LIMIT 1");
    $stmtFile  = $pdo->prepare("
        INSERT INTO vsync_peony_files
            (filename, file_date, email_ts, sha256, size, row_count, parser_version, sync_status)
        VALUES
            (:filename, :file_date, 0, :sha, 0, :rc, :ver, 'pending')
    ");
    $stmtRow = $pdo->prepare("
        INSERT INTO vsync_peony_prices
            (file_id, file_date, category, material, price_raw, price_num, price_unit,
             delivery_basis, company, buyer, phone, row_status)
        VALUES
            (:file_id, :file_date, :category, :material, :price_raw, :price_num, :price_unit,
             :delivery_basis, :company, :buyer, :phone, 'ok')
    ");

    $totalFiles = 0; $totalRows = 0;
    foreach ($rowsByDate as $date => $rows) {
        $stmtCheck->execute([':d' => $date]);
        $existing = $stmtCheck->fetchColumn();
        if ($existing !== false && !$force) {
            fprintf(STDOUT, "skip %s (exists)\n", $date);
            continue;
        }
        if ($existing !== false && $force) {
            $pdo->prepare("DELETE FROM vsync_peony_prices WHERE file_id = :id")->execute([':id' => $existing]);
            $pdo->prepare("DELETE FROM vsync_peony_files  WHERE id = :id")->execute([':id' => $existing]);
        }

        $filename = str_replace('-', '', $date) . 'CBE.pdf';
        $stmtFile->execute([
            ':filename'  => $filename,
            ':file_date' => $date,
            ':sha'       => 'bootstrap:' . sha1($filename),
            ':rc'        => count($rows),
            ':ver'       => 'bootstrap-1.0',
        ]);
        $fileId = (int) $pdo->lastInsertId();

        foreach ($rows as $r) {
            $stmtRow->execute([
                ':file_id'        => $fileId,
                ':file_date'      => $date,
                ':category'       => $r['category'],
                ':material'       => $r['material'],
                ':price_raw'      => $r['price_raw'],
                ':price_num'      => PeonyParser::parsePriceNum($r['price_raw']),
                ':price_unit'     => PeonyParser::parsePriceUnit($r['price_raw']),
                ':delivery_basis' => $r['delivery_basis'] ?? null,
                ':company'        => $r['company'] ?? null,
                ':buyer'          => $r['buyer'] ?? null,
                ':phone'          => $r['phone'] ?? null,
            ]);
            $totalRows++;
        }
        $totalFiles++;
        fprintf(STDOUT, "imp  %s  %d rows\n", $date, count($rows));
    }

    $pdo->prepare("
        INSERT INTO vsync_logs (event, status, details, file_count, row_count)
        VALUES ('import', 'ok', :det, :fc, :rc)
    ")->execute([
        ':det' => 'bootstrap import from ' . basename($jsonPath),
        ':fc'  => $totalFiles,
        ':rc'  => $totalRows,
    ]);

    $pdo->commit();
    fprintf(STDOUT, "\nOK: %d files, %d rows\n", $totalFiles, $totalRows);
} catch (Throwable $e) {
    $pdo->rollBack();
    fprintf(STDERR, "ERR: %s\n", $e->getMessage());
    exit(2);
}
