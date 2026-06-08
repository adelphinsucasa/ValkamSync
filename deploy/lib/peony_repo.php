<?php
declare(strict_types=1);

/**
 * Repositorio de datos PeonyInc — toda query vía PDO prepared statements.
 * Rendimiento: todas las consultas usan LIMIT/OFFSET y proyecciones acotadas
 * para respetar el presupuesto de 256M del shared hosting.
 */

/**
 * Dashboard: última sincronización, conteos, rango de fechas.
 * Target: el header de la vista "Analizar Archivos PeonyInc".
 */
function peony_dashboard(PDO $pdo): array
{
    $lastSync = $pdo->query("
        SELECT fecha, event, status, file_count, row_count
        FROM vsync_logs
        WHERE event = 'import' AND status = 'ok'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch() ?: null;

    $counts = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM vsync_peony_files) AS files_total,
            (SELECT COUNT(*) FROM vsync_peony_files WHERE sync_status = 'pending')   AS files_pending,
            (SELECT COUNT(*) FROM vsync_peony_files WHERE sync_status = 'validated') AS files_validated,
            (SELECT COUNT(*) FROM vsync_peony_files WHERE sync_status = 'synced')    AS files_synced,
            (SELECT COUNT(*) FROM vsync_peony_prices) AS prices_total,
            (SELECT MIN(file_date) FROM vsync_peony_files) AS date_min,
            (SELECT MAX(file_date) FROM vsync_peony_files) AS date_max
    ")->fetch() ?: [];

    return [
        'ok'        => true,
        'last_sync' => $lastSync ? [
            'fecha'      => $lastSync['fecha'],
            'fecha_es'   => format_fecha_es($lastSync['fecha']),
            'file_count' => (int) $lastSync['file_count'],
            'row_count'  => (int) $lastSync['row_count'],
        ] : null,
        'counts' => array_map(fn($v) => $v === null ? null : (is_numeric($v) ? (int) $v : $v), $counts),
    ];
}

/**
 * Lista archivos con metadatos — para la UI de filtros y selección.
 * No se traen las filas de precios aquí (eso es peony_prices).
 */
function peony_files(PDO $pdo, array $q): array
{
    $filter   = trim((string) ($q['filter']    ?? 'all'));
    $date     = trim((string) ($q['date']      ?? ''));
    $dateFrom = trim((string) ($q['date_from'] ?? ''));
    $dateTo   = trim((string) ($q['date_to']   ?? ''));
    $limit  = min(200, max(1, (int) ($q['limit']  ?? 100)));
    $offset = max(0, (int) ($q['offset'] ?? 0));

    [$whereSql, $params] = peony_build_date_where('f.file_date', $filter, $date, true, $dateFrom, $dateTo);

    $sql = "
        SELECT f.id, f.filename, f.file_date, f.row_count, f.parser_version,
               f.sync_status, f.imported_at, f.synced_at, f.size
        FROM vsync_peony_files f
        $whereSql
        ORDER BY f.file_date DESC, f.id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['ok' => true, 'files' => $stmt->fetchAll(), 'filter' => $filter, 'date' => $date];
}

/**
 * Precios de un archivo (o rango). Paginado agresivamente.
 */
function peony_prices(PDO $pdo, array $q): array
{
    $fileId   = isset($q['file_id']) ? (int) $q['file_id'] : null;
    $category = trim((string) ($q['category']  ?? ''));
    $filter   = trim((string) ($q['filter']    ?? 'all'));
    $date     = trim((string) ($q['date']      ?? ''));
    $dateFrom = trim((string) ($q['date_from'] ?? ''));
    $dateTo   = trim((string) ($q['date_to']   ?? ''));
    $search   = trim((string) ($q['q']         ?? ''));
    $limit    = min(500, max(1, (int) ($q['limit']  ?? 200)));
    $offset   = max(0, (int) ($q['offset'] ?? 0));

    $where = [];
    $params = [];

    if ($fileId !== null && $fileId > 0) {
        $where[] = 'p.file_id = :file_id';
        $params[':file_id'] = $fileId;
    } else {
        [$dateSql, $dateParams] = peony_build_date_where('p.file_date', $filter, $date, false, $dateFrom, $dateTo);
        if ($dateSql !== '') {
            $where[] = ltrim($dateSql, 'WHERE ');
            $params = array_merge($params, $dateParams);
        }
    }
    if ($category !== '') {
        $where[] = 'p.category = :category';
        $params[':category'] = $category;
    }
    if ($search !== '') {
        $where[] = '(p.material LIKE :search OR p.company LIKE :search OR p.buyer LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "SELECT COUNT(*) FROM vsync_peony_prices p $whereSql";
    $cstmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $cstmt->bindValue($k, $v);
    $cstmt->execute();
    $total = (int) $cstmt->fetchColumn();

    $sql = "
        SELECT p.id, p.file_id, p.file_date, p.category, p.material,
               p.price_raw, p.price_num, p.price_unit,
               p.delivery_basis, p.company, p.buyer, p.phone, p.row_status
        FROM vsync_peony_prices p
        $whereSql
        ORDER BY p.file_date DESC, p.category ASC, p.id ASC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'ok'      => true,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'prices'  => $stmt->fetchAll(),
    ];
}

/**
 * Comparación actuales vs históricos (tendencia por material).
 * Devuelve: último precio, precio promedio previos N días, delta %, trend.
 */
function peony_compare(PDO $pdo, array $q): array
{
    $windowDays = max(3, min(90, (int) ($q['window'] ?? 14)));
    $category   = trim((string) ($q['category'] ?? ''));
    $limit      = min(200, max(1, (int) ($q['limit'] ?? 100)));

    $params = [':win' => $windowDays];
    $catSql = '';
    if ($category !== '') {
        $catSql = 'AND p.category = :category';
        $params[':category'] = $category;
    }

    $driver = db_driver();
    $dateBackExpr = $driver === 'sqlite'
        ? "date('now', '-' || :win || ' days')"
        : "DATE_SUB(CURRENT_DATE, INTERVAL :win DAY)";

    $sql = "
        WITH latest AS (
            SELECT p.material, p.category, p.price_num, p.file_date, p.company, p.buyer, p.phone,
                   ROW_NUMBER() OVER (PARTITION BY p.material ORDER BY p.file_date DESC) AS rn
            FROM vsync_peony_prices p
            WHERE p.price_num IS NOT NULL $catSql
        ),
        hist AS (
            SELECT material, AVG(price_num) AS avg_prev, COUNT(*) AS n_prev
            FROM vsync_peony_prices
            WHERE price_num IS NOT NULL AND file_date >= $dateBackExpr
            GROUP BY material
        )
        SELECT l.material, l.category, l.price_num AS price_current, l.file_date AS date_current,
               l.company, l.buyer, l.phone,
               h.avg_prev, h.n_prev
        FROM latest l
        LEFT JOIN hist h ON h.material = l.material
        WHERE l.rn = 1
        ORDER BY
            CASE WHEN h.avg_prev IS NULL OR h.avg_prev = 0 THEN 0
                 ELSE ABS(l.price_num - h.avg_prev) / h.avg_prev END DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $cur = (float) ($r['price_current'] ?? 0);
        $prev = $r['avg_prev'] !== null ? (float) $r['avg_prev'] : null;
        $delta = ($prev && $prev > 0) ? ($cur - $prev) / $prev : null;
        $r['delta_pct'] = $delta === null ? null : round($delta * 100, 2);
        $r['trend'] = $delta === null ? 'flat' : ($delta > 0.02 ? 'up' : ($delta < -0.02 ? 'down' : 'flat'));
    }

    return ['ok' => true, 'window_days' => $windowDays, 'rows' => $rows];
}

/**
 * Cambia sync_status de un archivo. Server-enforced state machine.
 * pending → validated → synced. 'error' es terminal hasta reimport.
 */
function peony_validate(PDO $pdo, array $body): array
{
    $fileId = (int) ($body['file_id'] ?? 0);
    $target = (string) ($body['status'] ?? '');
    if ($fileId <= 0 || !in_array($target, ['validated', 'synced', 'pending'], true)) {
        return ['ok' => false, 'error' => 'params'];
    }

    $stmt = $pdo->prepare("SELECT sync_status FROM vsync_peony_files WHERE id = :id");
    $stmt->execute([':id' => $fileId]);
    $cur = $stmt->fetchColumn();
    if ($cur === false) return ['ok' => false, 'error' => 'not_found'];

    $allowed = [
        'pending'   => ['validated'],
        'validated' => ['synced', 'pending'],
        'synced'    => ['pending'],
        'error'     => ['pending'],
    ];
    if (!in_array($target, $allowed[$cur] ?? [], true)) {
        return ['ok' => false, 'error' => 'invalid_transition', 'from' => $cur, 'to' => $target];
    }

    $driver = db_driver();
    $tsNow = $driver === 'sqlite' ? "datetime('now')" : "CURRENT_TIMESTAMP";
    $sql = $target === 'synced'
        ? "UPDATE vsync_peony_files SET sync_status = :s, synced_at = $tsNow WHERE id = :id"
        : "UPDATE vsync_peony_files SET sync_status = :s WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':s' => $target, ':id' => $fileId]);

    return ['ok' => true, 'file_id' => $fileId, 'from' => $cur, 'to' => $target];
}

/**
 * Clientes: agrega buyers/compañías únicos del dataset PeonyInc + status + notes_count.
 * Clave natural = teléfono. Soporta quick-filters: overdue|today|week|pending|scheduled|contacted|discarded|all.
 */
function peony_clients(PDO $pdo, array $q): array
{
    $search   = trim((string) ($q['q']        ?? ''));
    $category = trim((string) ($q['category'] ?? ''));
    $quick    = trim((string) ($q['quick']    ?? 'all'));
    $limit    = min(500, max(1, (int) ($q['limit']  ?? 200)));
    $offset   = max(0, (int) ($q['offset'] ?? 0));

    $today   = date('Y-m-d');
    $weekEnd = date('Y-m-d', strtotime('+7 days'));

    // Filtros sobre precios (base del universo de clientes)
    $priceWhere  = ["phone IS NOT NULL AND phone <> ''"];
    $priceParams = [];
    if ($category !== '') {
        $priceWhere[] = 'category = :category';
        $priceParams[':category'] = $category;
    }
    if ($search !== '') {
        $priceWhere[] = '(company LIKE :search OR buyer LIKE :search OR phone LIKE :search)';
        $priceParams[':search'] = '%' . $search . '%';
    }
    $priceWhereSql = 'WHERE ' . implode(' AND ', $priceWhere);

    // Filtros sobre status (post-JOIN)
    $statusWhere = [];
    $statusParams = [];
    switch ($quick) {
        case 'overdue':
            $statusWhere[] = "st.status = 'scheduled' AND st.next_contact_date < :today";
            $statusParams[':today'] = $today;
            break;
        case 'today':
            $statusWhere[] = "st.status = 'scheduled' AND st.next_contact_date = :today";
            $statusParams[':today'] = $today;
            break;
        case 'week':
            $statusWhere[] = "st.status = 'scheduled' AND st.next_contact_date BETWEEN :today AND :week_end";
            $statusParams[':today'] = $today;
            $statusParams[':week_end'] = $weekEnd;
            break;
        case 'scheduled':
            $statusWhere[] = "st.status = 'scheduled'";
            break;
        case 'contacted':
            $statusWhere[] = "st.status = 'contacted'";
            break;
        case 'discarded':
            $statusWhere[] = "st.status = 'discarded'";
            break;
        case 'pending':
            $statusWhere[] = "(st.status IS NULL OR st.status = 'pending')";
            break;
        // 'all' o desconocido: sin filtro de status
    }
    $statusWhereSql = $statusWhere ? ('WHERE ' . implode(' AND ', $statusWhere)) : '';

    // Subquery con agregación de precios + LEFT JOIN a status
    $baseSql = "
        FROM (
            SELECT
                p.phone,
                MAX(p.company)                    AS company,
                MAX(p.buyer)                      AS buyer,
                GROUP_CONCAT(DISTINCT p.category) AS categories,
                COUNT(*)                          AS row_count,
                COUNT(DISTINCT p.material)        AS material_count,
                MAX(p.file_date)                  AS last_seen,
                MIN(p.file_date)                  AS first_seen
            FROM vsync_peony_prices p
            $priceWhereSql
            GROUP BY p.phone
        ) agg
        LEFT JOIN vsync_client_status st ON st.phone = agg.phone
        $statusWhereSql
    ";

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
    foreach ($priceParams as $k => $v)  $countStmt->bindValue($k, $v);
    foreach ($statusParams as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    // Query principal — orden derivado: overdue → today → scheduled → pending → contacted → discarded
    $sql = "
        SELECT
            agg.phone, agg.company, agg.buyer, agg.categories, agg.row_count, agg.material_count,
            agg.last_seen, agg.first_seen,
            COALESCE(st.status, 'pending') AS status,
            st.next_contact_date,
            st.last_contact_at,
            st.note_summary,
            st.updated_at AS status_updated_at,
            (SELECT COUNT(*) FROM vsync_client_notes n WHERE n.phone = agg.phone) AS notes_count
        $baseSql
        ORDER BY
            CASE
                WHEN st.status = 'scheduled' AND st.next_contact_date < :ord_today    THEN 0
                WHEN st.status = 'scheduled' AND st.next_contact_date = :ord_today2   THEN 1
                WHEN st.status = 'scheduled' AND st.next_contact_date <= :ord_weekend THEN 2
                WHEN st.status = 'scheduled'                                          THEN 3
                WHEN st.status IS NULL OR st.status = 'pending'                       THEN 4
                WHEN st.status = 'contacted'                                          THEN 5
                ELSE 6
            END,
            agg.last_seen DESC,
            agg.company ASC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($priceParams as $k => $v)  $stmt->bindValue($k, $v);
    foreach ($statusParams as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':ord_today',    $today);
    $stmt->bindValue(':ord_today2',   $today);
    $stmt->bindValue(':ord_weekend',  $weekEnd);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['categories']     = $r['categories'] ? array_values(array_unique(explode(',', $r['categories']))) : [];
        $r['row_count']      = (int) $r['row_count'];
        $r['material_count'] = (int) $r['material_count'];
        $r['notes_count']    = (int) ($r['notes_count'] ?? 0);
        // Flag derivado para UI
        $r['overdue'] = ($r['status'] === 'scheduled' && $r['next_contact_date'] && $r['next_contact_date'] < $today);
        $r['due_today'] = ($r['status'] === 'scheduled' && $r['next_contact_date'] === $today);
    }

    return [
        'ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
        'quick' => $quick, 'today' => $today, 'clients' => $rows,
    ];
}

/**
 * Stats agregadas para los quick-filters del header.
 */
function peony_clients_stats(PDO $pdo): array
{
    $today   = date('Y-m-d');
    $weekEnd = date('Y-m-d', strtotime('+7 days'));

    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(DISTINCT phone) FROM vsync_peony_prices WHERE phone IS NOT NULL AND phone <> '') AS total,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'scheduled' AND next_contact_date < :t1)                         AS overdue,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'scheduled' AND next_contact_date = :t2)                         AS today_count,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'scheduled' AND next_contact_date BETWEEN :t3 AND :we)           AS week_count,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'scheduled')                                                     AS scheduled,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'contacted')                                                     AS contacted,
            (SELECT COUNT(*) FROM vsync_client_status WHERE status = 'discarded')                                                     AS discarded
    ");
    $stmt->execute([':t1'=>$today, ':t2'=>$today, ':t3'=>$today, ':we'=>$weekEnd]);
    $r = $stmt->fetch() ?: [];
    foreach ($r as $k => $v) $r[$k] = (int) $v;
    $statusTotal = $r['scheduled'] + $r['contacted'] + $r['discarded'];
    $r['pending'] = max(0, $r['total'] - $statusTotal);
    return ['ok' => true, 'today' => $today, 'stats' => $r];
}

/**
 * Upsert del status de un cliente.
 */
function peony_client_status_update(PDO $pdo, array $body): array
{
    $input   = trim((string) ($body['phone']  ?? ''));
    $status  = trim((string) ($body['status'] ?? ''));
    $next    = trim((string) ($body['next_contact_date'] ?? ''));
    $markContacted = !empty($body['mark_contacted_now']);

    if ($input === '' || !in_array($status, ['pending','scheduled','contacted','discarded'], true)) {
        return ['ok' => false, 'error' => 'params'];
    }
    if ($status === 'scheduled' && $next === '') {
        return ['ok' => false, 'error' => 'next_contact_date_required'];
    }
    if ($next !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
        return ['ok' => false, 'error' => 'bad_date'];
    }

    // Normaliza la key. Migra filas legacy con mismo teléfono pero formato distinto.
    $phone  = phone_normalize($input);
    $digits = ltrim($phone, '+');
    $normExpr = phone_sql_norm('phone');
    $pdo->prepare("DELETE FROM vsync_client_status WHERE $normExpr = :d AND phone <> :p")
        ->execute([':d' => $digits, ':p' => $phone]);

    $driver = db_driver();
    $tsNow = $driver === 'sqlite' ? "datetime('now')" : "CURRENT_TIMESTAMP";

    if ($driver === 'sqlite') {
        $sql = "
            INSERT INTO vsync_client_status (phone, status, next_contact_date, last_contact_at, updated_at)
            VALUES (:phone, :status, :next, " . ($markContacted || $status === 'contacted' ? $tsNow : 'NULL') . ", $tsNow)
            ON CONFLICT(phone) DO UPDATE SET
                status = excluded.status,
                next_contact_date = excluded.next_contact_date,
                last_contact_at = CASE
                    WHEN :mark_contacted = 1 OR excluded.status = 'contacted' THEN $tsNow
                    ELSE vsync_client_status.last_contact_at
                END,
                updated_at = $tsNow
        ";
    } else {
        // MySQL / MariaDB
        $sql = "
            INSERT INTO vsync_client_status (phone, status, next_contact_date, last_contact_at, updated_at)
            VALUES (:phone, :status, :next, " . ($markContacted || $status === 'contacted' ? $tsNow : 'NULL') . ", $tsNow)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                next_contact_date = VALUES(next_contact_date),
                last_contact_at = IF(:mark_contacted = 1 OR VALUES(status) = 'contacted', $tsNow, last_contact_at),
                updated_at = $tsNow
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':phone',   $phone);
    $stmt->bindValue(':status',  $status);
    $stmt->bindValue(':next',    $next !== '' ? $next : null, $next !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':mark_contacted', $markContacted ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();

    $read = $pdo->prepare("SELECT phone, status, next_contact_date, last_contact_at, updated_at FROM vsync_client_status WHERE phone = :phone");
    $read->execute([':phone' => $phone]);
    return ['ok' => true, 'status' => $read->fetch()];
}

/**
 * Detalle por cliente: todas las filas de precios asociadas a su teléfono.
 */
function peony_client_detail(PDO $pdo, array $q): array
{
    $phone = trim((string) ($q['phone'] ?? ''));
    if ($phone === '') return ['ok' => false, 'error' => 'phone_required'];

    $limit = min(1000, max(1, (int) ($q['limit'] ?? 300)));

    $stmt = $pdo->prepare("
        SELECT file_date, category, material, price_raw, price_num, price_unit,
               delivery_basis, company, buyer
        FROM vsync_peony_prices
        WHERE phone = :phone
        ORDER BY file_date DESC, category ASC, material ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':phone', $phone);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    return ['ok' => true, 'phone' => $phone, 'count' => count($rows), 'rows' => $rows];
}

/**
 * SQL helper: expresión que normaliza un teléfono (elimina espacios, -, (, ), .)
 * Compatible con SQLite y MySQL.
 */
function phone_sql_norm(string $col): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
}

/**
 * Notas de conversación por cliente. Lookup tolerante a formato (fuzzy).
 * Nuevas notas se guardan en forma normalizada.
 */
function peony_client_notes(PDO $pdo, array $q): array
{
    $input = trim((string) ($q['phone'] ?? ''));
    if ($input === '') return ['ok' => false, 'error' => 'phone_required'];
    $norm = phone_normalize($input);
    $digits = ltrim($norm, '+');

    // Match contra:
    //   - filas nuevas guardadas ya normalizadas
    //   - filas legacy guardadas con formato original (comparación vía REPLACE)
    $normExpr = phone_sql_norm('phone');
    $stmt = $pdo->prepare("
        SELECT id, note, author, created_at
        FROM vsync_client_notes
        WHERE phone = :n OR $normExpr = :d
        ORDER BY id DESC
        LIMIT 200
    ");
    $stmt->execute([':n' => $norm, ':d' => $digits]);

    return ['ok' => true, 'phone' => $norm, 'notes' => $stmt->fetchAll()];
}

function peony_client_note_create(PDO $pdo, array $body): array
{
    $input  = trim((string) ($body['phone']  ?? ''));
    $note   = trim((string) ($body['note']   ?? ''));
    $author = trim((string) ($body['author'] ?? ''));

    if ($input === '' || $note === '') return ['ok' => false, 'error' => 'params'];
    if (mb_strlen($note)   > 4000) return ['ok' => false, 'error' => 'note_too_long'];
    if (mb_strlen($author) > 80)   return ['ok' => false, 'error' => 'author_too_long'];
    if (mb_strlen($input)  > 40)   return ['ok' => false, 'error' => 'phone_too_long'];

    $phone = phone_normalize($input);  // guarda ya normalizado

    $stmt = $pdo->prepare("
        INSERT INTO vsync_client_notes (phone, note, author)
        VALUES (:phone, :note, :author)
    ");
    $stmt->execute([
        ':phone'  => $phone,
        ':note'   => $note,
        ':author' => $author !== '' ? $author : null,
    ]);
    $id = (int) $pdo->lastInsertId();

    $read = $pdo->prepare("SELECT id, note, author, created_at FROM vsync_client_notes WHERE id = :id");
    $read->execute([':id' => $id]);

    return ['ok' => true, 'note' => $read->fetch()];
}

/**
 * MAILDIR reader — extrae metadatos + PDF adjunto desde un archivo Maildir RFC822.
 * Sólo procesa emails cuyo From contenga peonyinc.com.
 * Retorna null si no aplica o hay error de parseo.
 */
function peony_parse_maildir_email(string $emailPath): ?array
{
    $raw = @file_get_contents($emailPath);
    if (!$raw || strlen($raw) < 500) return null;
    if (stripos($raw, 'peonyinc.com') === false) return null;

    // Separa headers del cuerpo
    if (!preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) return null;
    $headersRaw = substr($raw, 0, $m[0][1]);
    $body       = substr($raw, $m[0][1] + strlen($m[0][0]));

    // Parse headers (unfolding continuation lines)
    $headers = [];
    foreach (preg_split("/\r?\n(?!\s)/", $headersRaw) as $h) {
        if (preg_match('/^([^:]+):\s*(.*)$/s', $h, $mm)) {
            $headers[strtolower(trim($mm[1]))] = preg_replace("/\s+/", ' ', trim($mm[2]));
        }
    }

    if (stripos($headers['from'] ?? '', 'peonyinc.com') === false) return null;

    $ct = $headers['content-type'] ?? '';
    if (!preg_match('/boundary="?([^";\s]+)"?/i', $ct, $bm)) return null;
    $boundary = $bm[1];

    // Split body por boundary
    $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?\r?\n?/', $body);

    foreach ($parts as $part) {
        if (!trim($part)) continue;
        $sub = preg_split("/\r?\n\r?\n/", $part, 2);
        if (count($sub) < 2) continue;
        [$partHeaders, $partBody] = $sub;

        if (stripos($partHeaders, 'application/pdf') === false) continue;

        $filename = 'unknown.pdf';
        if (preg_match('/filename\*?="?([^";\r\n]+?)"?(?:\r|\n|$|;)/i', $partHeaders, $fnm)) {
            $filename = $fnm[1];
        } elseif (preg_match('/name="?([^";\r\n]+?)"?(?:\r|\n|$|;)/i', $partHeaders, $fnm)) {
            $filename = $fnm[1];
        }

        $pdfData = $partBody;
        if (stripos($partHeaders, 'base64') !== false) {
            $pdfData = @base64_decode(preg_replace('/\s+/', '', $partBody), true) ?: '';
        }

        if (!$pdfData || substr($pdfData, 0, 4) !== '%PDF') continue;

        return [
            'filename'    => $filename,
            'data'        => $pdfData,
            'subject'     => $headers['subject']    ?? '',
            'date_header' => $headers['date']       ?? '',
            'email_path'  => $emailPath,
        ];
    }
    return null;
}

/**
 * Lista PDFs PeonyInc disponibles en el maildir, con flag de ya-importado.
 * Sólo funciona cuando la app corre en el servidor que tiene el maildir (Hostgator).
 */
function peony_available_files(PDO $pdo): array
{
    $mailRoot = defined('MAIL_ROOT') ? MAIL_ROOT : '';
    if ($mailRoot === '' || !is_dir($mailRoot)) {
        return [
            'ok'    => false,
            'error' => 'mail_root_not_found',
            'hint'  => 'No se encuentra el maildir (' . ($mailRoot ?: 'sin configurar') . '). Esta función sólo funciona cuando la app corre en Hostgator con acceso directo a ~/mail/valkamgm.com/info. En otros hosts, usa el importer CLI desde tu máquina local.',
        ];
    }

    $imported = [];
    $stmt = $pdo->query("SELECT filename, file_date, sha256, row_count, imported_at FROM vsync_peony_files");
    while ($r = $stmt->fetch()) {
        $imported[$r['filename']] = $r;
    }

    $files = [];
    foreach (['cur', 'new'] as $sub) {
        $dir = $mailRoot . '/' . $sub;
        if (!is_dir($dir)) continue;
        $paths = @glob($dir . '/*') ?: [];
        foreach ($paths as $emailPath) {
            if (is_dir($emailPath)) continue;
            // Filtro rápido: lee primeros 4KB para detectar peonyinc.com antes de parsear completo
            $fp = @fopen($emailPath, 'rb');
            if (!$fp) continue;
            $head = @fread($fp, 4096) ?: '';
            fclose($fp);
            if (stripos($head, 'peonyinc.com') === false) continue;

            $parsed = peony_parse_maildir_email($emailPath);
            if (!$parsed) continue;

            $sha = hash('sha256', $parsed['data']);
            $base = basename($emailPath);
            $ts = (int) strtok($base, '.');
            $existing = $imported[$parsed['filename']] ?? null;

            $files[] = [
                'filename'             => $parsed['filename'],
                'email_path'           => $emailPath,
                'email_ts'             => $ts,
                'email_date'           => $parsed['date_header'],
                'subject'              => $parsed['subject'],
                'file_date'            => PeonyParser::dateFromFilename($parsed['filename']),
                'size'                 => strlen($parsed['data']),
                'sha256'               => $sha,
                'already_imported'     => (bool) $existing,
                'imported_at'          => $existing ? $existing['imported_at'] : null,
                'imported_rows'        => $existing ? (int) $existing['row_count'] : 0,
                'imported_sha_matches' => $existing ? ($existing['sha256'] === $sha) : null,
            ];
        }
    }

    // Orden: timestamp desc
    usort($files, fn($a, $b) => $b['email_ts'] <=> $a['email_ts']);

    // Dedup por filename (conservar el más reciente)
    $seen = [];
    $deduped = [];
    foreach ($files as $f) {
        if (isset($seen[$f['filename']])) continue;
        $seen[$f['filename']] = true;
        $deduped[] = $f;
    }

    return ['ok' => true, 'files' => $deduped, 'total' => count($deduped)];
}

/**
 * Importa archivos seleccionados. Política anti-duplicados:
 * - Si ya existe registro para file_date (o filename), BORRA todas sus filas y re-importa.
 * - Las filas dentro del archivo nuevo no se duplican entre sí (un PDF = N filas únicas por layout).
 * - Teléfonos se guardan ya normalizados.
 */
function peony_import_files(PDO $pdo, array $body): array
{
    $filenames = $body['filenames'] ?? [];
    if (!is_array($filenames) || !count($filenames)) {
        return ['ok' => false, 'error' => 'no_files'];
    }
    $mailRoot = defined('MAIL_ROOT') ? MAIL_ROOT : '';
    if (!$mailRoot || !is_dir($mailRoot)) {
        return ['ok' => false, 'error' => 'mail_root_not_found'];
    }

    $all = peony_available_files($pdo);
    if (!($all['ok'] ?? false)) return $all;
    $byFilename = [];
    foreach ($all['files'] as $f) $byFilename[$f['filename']] = $f;

    $parser = new PeonyParser();
    $results = [];
    $totalRows = 0;

    foreach ($filenames as $fn) {
        $meta = $byFilename[$fn] ?? null;
        if (!$meta) {
            $results[] = ['filename' => $fn, 'ok' => false, 'error' => 'not_in_maildir'];
            continue;
        }

        $pdo->beginTransaction();
        try {
            $parsed = peony_parse_maildir_email($meta['email_path']);
            if (!$parsed) throw new RuntimeException('cant_parse_email');

            $tmpPath = tempnam(sys_get_temp_dir(), 'peony_') . '.pdf';
            file_put_contents($tmpPath, $parsed['data']);
            $parseResult = $parser->parse($tmpPath);
            @unlink($tmpPath);
            $rows = $parseResult['rows'] ?? [];

            $fileDate = PeonyParser::dateFromFilename($fn) ?: date('Y-m-d');

            // Sobrescritura: borra fila existente por filename O file_date
            $findOld = $pdo->prepare("SELECT id FROM vsync_peony_files WHERE filename = :f OR file_date = :d LIMIT 1");
            $findOld->execute([':f' => $fn, ':d' => $fileDate]);
            $oldId = $findOld->fetchColumn();
            $replaced = false;
            if ($oldId) {
                $pdo->prepare("DELETE FROM vsync_peony_prices WHERE file_id = :id")->execute([':id' => $oldId]);
                $pdo->prepare("DELETE FROM vsync_peony_files  WHERE id = :id")->execute([':id' => $oldId]);
                $replaced = true;
            }

            // Inserta nuevo archivo
            $pdo->prepare("
                INSERT INTO vsync_peony_files
                    (filename, file_date, email_ts, sha256, size, row_count, parser_version, sync_status)
                VALUES
                    (:fn, :d, :ts, :sha, :sz, :rc, :ver, 'pending')
            ")->execute([
                ':fn'  => $fn, ':d' => $fileDate, ':ts' => $meta['email_ts'],
                ':sha' => $meta['sha256'], ':sz' => $meta['size'],
                ':rc'  => count($rows), ':ver' => PeonyParser::VERSION,
            ]);
            $fileId = (int) $pdo->lastInsertId();

            // Inserta filas — teléfonos normalizados
            $ins = $pdo->prepare("
                INSERT INTO vsync_peony_prices
                    (file_id, file_date, category, material, price_raw, price_num, price_unit,
                     delivery_basis, company, buyer, phone, row_status)
                VALUES
                    (:fid, :d, :cat, :m, :pr, :pn, :pu, :db, :co, :by, :ph, :rs)
            ");
            foreach ($rows as $r) {
                $ins->execute([
                    ':fid' => $fileId, ':d' => $fileDate,
                    ':cat' => $r['section'], ':m' => $r['material'],
                    ':pr'  => $r['price_raw'],
                    ':pn'  => PeonyParser::parsePriceNum($r['price_raw']),
                    ':pu'  => PeonyParser::parsePriceUnit($r['price_raw']),
                    ':db'  => $r['delivery_basis'] ?? null,
                    ':co'  => $r['company'] ?? null,
                    ':by'  => $r['buyer']   ?? null,
                    ':ph'  => phone_normalize($r['phone'] ?? ''),
                    ':rs'  => $r['row_status'] ?? 'ok',
                ]);
            }

            $pdo->commit();
            $totalRows += count($rows);

            // Resolución LME best-effort (fuera de transacción; no afecta import si falla)
            $lmeResult = lme_resolve_formula_prices($pdo, $fileId, $fileDate);

            $results[] = [
                'filename'     => $fn, 'ok' => true,
                'file_date'    => $fileDate, 'rows' => count($rows),
                'replaced'     => $replaced,
                'lme_resolved' => $lmeResult['resolved'],
                'lme_skipped'  => $lmeResult['skipped'],
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $results[] = ['filename' => $fn, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $allOk = array_reduce($results, fn($acc, $r) => $acc && ($r['ok'] ?? false), true);
    $okCount = count(array_filter($results, fn($r) => $r['ok'] ?? false));
    $pdo->prepare("
        INSERT INTO vsync_logs (event, status, details, file_count, row_count)
        VALUES ('import', :st, :det, :fc, :rc)
    ")->execute([
        ':st'  => $allOk ? 'ok' : 'partial',
        ':det' => 'Import UI (' . count($filenames) . ' seleccionados)',
        ':fc'  => $okCount,
        ':rc'  => $totalRows,
    ]);

    return ['ok' => true, 'results' => $results, 'total_rows' => $totalRows, 'ok_count' => $okCount];
}

/**
 * MATERIALS: lista agrupada por material con agregados + último precio.
 */
function peony_materials(PDO $pdo, array $q): array
{
    $search   = trim((string) ($q['q']         ?? ''));
    $category = trim((string) ($q['category']  ?? ''));
    $filter   = trim((string) ($q['filter']    ?? 'all'));
    $date     = trim((string) ($q['date']      ?? ''));
    $dateFrom = trim((string) ($q['date_from'] ?? ''));
    $dateTo   = trim((string) ($q['date_to']   ?? ''));
    $sort     = trim((string) ($q['sort']      ?? 'latest'));
    $limit    = min(500, max(1, (int) ($q['limit'] ?? 200)));
    $offset   = max(0, (int) ($q['offset'] ?? 0));

    [$dateWhere, $dateParams] = peony_build_date_where('p.file_date', $filter, $date, false, $dateFrom, $dateTo);

    $where = [];
    $params = [];
    if ($dateWhere !== '') { $where[] = $dateWhere; $params = array_merge($params, $dateParams); }
    if ($category !== '')  { $where[] = 'p.category = :category'; $params[':category'] = $category; }
    if ($search !== '')    { $where[] = 'p.material LIKE :search'; $params[':search'] = '%' . $search . '%'; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $driver = db_driver();
    $nullsLast = $driver === 'mysql' ? 'IS NULL ASC,' : '';
    $orderBy = match ($sort) {
        'price_desc' => "latest_price_num $nullsLast DESC, latest_date DESC",
        'price_asc'  => "latest_price_num $nullsLast ASC, latest_date DESC",
        'contacts'   => 'contact_count DESC, latest_date DESC',
        default      => 'latest_date DESC, material ASC',
    };

    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT material) FROM vsync_peony_prices p $whereSql");
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            p.material,
            MAX(p.category)              AS category,
            COUNT(*)                     AS price_count,
            COUNT(DISTINCT p.phone)      AS contact_count,
            MIN(p.price_num)             AS min_price,
            MAX(p.price_num)             AS max_price,
            AVG(p.price_num)             AS avg_price,
            MAX(p.file_date)             AS latest_date,
            (SELECT price_raw FROM vsync_peony_prices x
             WHERE x.material = p.material
             ORDER BY x.file_date DESC LIMIT 1) AS latest_price_raw,
            (SELECT price_num FROM vsync_peony_prices x
             WHERE x.material = p.material
             ORDER BY x.file_date DESC LIMIT 1) AS latest_price_num
        FROM vsync_peony_prices p
        $whereSql
        GROUP BY p.material
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_map(function ($r) {
        foreach (['min_price','max_price','avg_price','latest_price_num'] as $k) {
            $r[$k] = $r[$k] !== null ? (float) $r[$k] : null;
        }
        $r['price_count']   = (int) $r['price_count'];
        $r['contact_count'] = (int) $r['contact_count'];
        return $r;
    }, $stmt->fetchAll());

    return [
        'ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
        'sort' => $sort, 'filter' => $filter, 'date' => $date, 'materials' => $rows,
    ];
}

/**
 * MATERIAL_DETAIL: stats + timeline + últimas ofertas de un material.
 */
function peony_material_detail(PDO $pdo, array $q): array
{
    $material = trim((string) ($q['material'] ?? ''));
    if ($material === '') return ['ok' => false, 'error' => 'material_required'];

    $validIntervals = ['daily', 'weekly', 'biweekly', 'monthly', 'annual'];
    $interval = in_array($q['interval'] ?? '', $validIntervals, true) ? (string) $q['interval'] : 'daily';
    $dateFrom = trim((string) ($q['date_from'] ?? ''));
    $dateTo   = trim((string) ($q['date_to']   ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
    if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

    // ── Stats: siempre sobre todos los datos (sin filtro de fecha) ──────────
    $stmt = $pdo->prepare("
        SELECT material,
               MAX(category)         AS category,
               COUNT(*)              AS price_count,
               COUNT(DISTINCT phone) AS contact_count,
               MIN(price_num)        AS min_price,
               MAX(price_num)        AS max_price,
               AVG(price_num)        AS avg_price,
               MIN(file_date)        AS first_date,
               MAX(file_date)        AS latest_date
        FROM vsync_peony_prices
        WHERE material = :m
        GROUP BY material
    ");
    $stmt->execute([':m' => $material]);
    $stats = $stmt->fetch();
    if (!$stats) return ['ok' => false, 'error' => 'not_found'];

    // ── Timeline: agrupado por intervalo + rango opcional ───────────────────
    $driver = db_driver();

    // Expresión de agrupación temporal por driver
    switch ($interval) {
        case 'weekly':
            $periodExpr = $driver === 'sqlite'
                ? "strftime('%Y', file_date) || '-W' || printf('%02d', strftime('%W', file_date))"
                : "DATE_FORMAT(file_date, '%x-W%v')";
            break;
        case 'biweekly':
            $periodExpr = $driver === 'sqlite'
                ? "strftime('%Y-%m', file_date) || CASE WHEN CAST(strftime('%d', file_date) AS INTEGER) <= 15 THEN '-H1' ELSE '-H2' END"
                : "CONCAT(DATE_FORMAT(file_date, '%Y-%m'), IF(DAY(file_date) <= 15, '-H1', '-H2'))";
            break;
        case 'monthly':
            $periodExpr = $driver === 'sqlite'
                ? "strftime('%Y-%m', file_date)"
                : "DATE_FORMAT(file_date, '%Y-%m')";
            break;
        case 'annual':
            $periodExpr = $driver === 'sqlite'
                ? "strftime('%Y', file_date)"
                : "CAST(YEAR(file_date) AS CHAR)";
            break;
        default: // daily
            $periodExpr = 'file_date';
    }

    // Filtro de rango de fechas
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== '' && $dateTo !== '') {
        $dateWhere = 'AND file_date BETWEEN :df AND :dt';
        $dateParams[':df'] = $dateFrom; $dateParams[':dt'] = $dateTo;
    } elseif ($dateFrom !== '') {
        $dateWhere = 'AND file_date >= :df';
        $dateParams[':df'] = $dateFrom;
    } elseif ($dateTo !== '') {
        $dateWhere = 'AND file_date <= :dt';
        $dateParams[':dt'] = $dateTo;
    }

    $limit = match ($interval) {
        'weekly'   => 156,
        'biweekly' => 120,
        'monthly'  => 120,
        'annual'   => 50,
        default    => 365,
    };

    $tlStmt = $pdo->prepare("
        SELECT
            MIN(file_date)   AS file_date,
            $periodExpr      AS period_key,
            AVG(price_num)   AS avg_price,
            MIN(price_num)   AS min_price,
            MAX(price_num)   AS max_price,
            COUNT(*)         AS cnt
        FROM vsync_peony_prices
        WHERE material = :m AND price_num IS NOT NULL
        $dateWhere
        GROUP BY $periodExpr
        ORDER BY $periodExpr DESC
        LIMIT :lim
    ");
    $tlStmt->bindValue(':m', $material);
    foreach ($dateParams as $k => $v) $tlStmt->bindValue($k, $v);
    $tlStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $tlStmt->execute();
    $tlRows = $tlStmt->fetchAll(PDO::FETCH_ASSOC);

    // Etiquetas legibles por intervalo
    $meses = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',
              7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
    $timeline = [];
    foreach ($tlRows as $r) {
        $pk = (string) $r['period_key'];
        switch ($interval) {
            case 'weekly':
                $label = preg_match('/^(\d{4})-W(\d{1,2})$/', $pk, $m)
                    ? 'S' . (int)$m[2] . ' ' . $m[1] : $pk;
                break;
            case 'biweekly':
                $label = preg_match('/^(\d{4})-(\d{2})-(H[12])$/', $pk, $m)
                    ? ($m[3]==='H1' ? '1-15 ' : '16+ ') . ($meses[(int)$m[2]] ?? $m[2]) . ' ' . $m[1] : $pk;
                break;
            case 'monthly':
                $label = preg_match('/^(\d{4})-(\d{2})$/', $pk, $m)
                    ? ($meses[(int)$m[2]] ?? $m[2]) . ' ' . $m[1] : $pk;
                break;
            case 'annual':
                $label = $pk;
                break;
            default:
                $label = $r['file_date'];
        }
        $timeline[] = [
            'file_date'    => $r['file_date'],
            'period_key'   => $pk,
            'period_label' => $label,
            'avg_price'    => $r['avg_price'] !== null ? (float) $r['avg_price'] : null,
            'min_price'    => $r['min_price'] !== null ? (float) $r['min_price'] : null,
            'max_price'    => $r['max_price'] !== null ? (float) $r['max_price'] : null,
            'cnt'          => (int) $r['cnt'],
        ];
    }

    foreach (['min_price','max_price','avg_price'] as $k) {
        $stats[$k] = $stats[$k] !== null ? (float) $stats[$k] : null;
    }
    $stats['price_count']   = (int) $stats['price_count'];
    $stats['contact_count'] = (int) $stats['contact_count'];

    return ['ok' => true, 'interval' => $interval, 'stats' => $stats, 'timeline' => $timeline];
}

/**
 * MATERIAL_CONTACTS: contactos que ofrecen un material, sort configurable.
 *   best   → recientes + precio ALTO primero (mejor oferta para el vendedor)
 *   recent → más recientes primero, ignora precio
 *   cheap  → recientes + precio BAJO primero (para comparar/negociar)
 */
function peony_material_contacts(PDO $pdo, array $q): array
{
    $material   = trim((string) ($q['material'] ?? ''));
    $sort       = trim((string) ($q['sort']     ?? 'best'));
    $recentDays = max(3, min(365, (int) ($q['recent_days'] ?? 14)));
    $limit      = min(500, max(1, (int) ($q['limit'] ?? 300)));
    if ($material === '') return ['ok' => false, 'error' => 'material_required'];

    $cutoff = date('Y-m-d', strtotime("-{$recentDays} days"));

    $sql = "
        SELECT
            p.phone, p.company, p.buyer, p.price_raw, p.price_num,
            p.file_date AS latest_date, p.delivery_basis,
            (SELECT COUNT(*) FROM vsync_peony_prices WHERE phone = p.phone AND material = :m2) AS quote_count,
            (SELECT COUNT(*) FROM vsync_client_notes  WHERE phone = p.phone) AS notes_count,
            COALESCE((SELECT status FROM vsync_client_status WHERE phone = p.phone), 'pending') AS status,
            (SELECT next_contact_date FROM vsync_client_status WHERE phone = p.phone) AS next_contact_date
        FROM vsync_peony_prices p
        INNER JOIN (
            SELECT phone, MAX(file_date) AS max_date
            FROM vsync_peony_prices
            WHERE material = :m1
            GROUP BY phone
        ) latest ON latest.phone = p.phone AND latest.max_date = p.file_date
        WHERE p.material = :m3
        LIMIT :limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':m1', $material);
    $stmt->bindValue(':m2', $material);
    $stmt->bindValue(':m3', $material);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['is_recent']   = ($r['latest_date'] >= $cutoff);
        $r['price_num']   = $r['price_num']   !== null ? (float) $r['price_num'] : null;
        $r['quote_count'] = (int) $r['quote_count'];
        $r['notes_count'] = (int) $r['notes_count'];
    }
    unset($r);

    usort($rows, function ($a, $b) use ($sort) {
        $na = $a['price_num'] ?? -INF;
        $nb = $b['price_num'] ?? -INF;
        $da = $a['latest_date'] ?? '';
        $db = $b['latest_date'] ?? '';
        switch ($sort) {
            case 'recent':
                return strcmp($db, $da) ?: ($nb <=> $na);
            case 'cheap':
                if ($a['is_recent'] !== $b['is_recent']) return $a['is_recent'] ? -1 : 1;
                return ($na <=> $nb) ?: strcmp($db, $da);
            case 'best':
            default:
                if ($a['is_recent'] !== $b['is_recent']) return $a['is_recent'] ? -1 : 1;
                return ($nb <=> $na) ?: strcmp($db, $da);
        }
    });

    return [
        'ok' => true, 'material' => $material, 'sort' => $sort,
        'recent_days' => $recentDays, 'cutoff' => $cutoff,
        'contacts' => array_slice($rows, 0, $limit),
    ];
}

/**
 * SCAN: busca cliente existente por teléfono (fuzzy: ignora formato).
 * Prioriza vsync_clients_manual; si no está, busca en vsync_peony_prices.
 */
function scan_find_existing(PDO $pdo, string $phone): ?array
{
    $norm = phone_normalize($phone);
    if ($norm === '') return null;
    // Quitamos '+' para comparar solo dígitos
    $digits = ltrim($norm, '+');
    $normExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', '')";

    // 1) manual
    $stmt = $pdo->prepare("
        SELECT 'manual' AS source, phone, company, buyer, scan_count AS hits, last_scan_at AS last_seen
        FROM vsync_clients_manual
        WHERE $normExpr = :d
        LIMIT 1
    ");
    $stmt->execute([':d' => $digits]);
    $r = $stmt->fetch();
    if ($r) return $r;

    // 2) peony
    $stmt = $pdo->prepare("
        SELECT 'peony' AS source, MAX(phone) AS phone, MAX(company) AS company, MAX(buyer) AS buyer,
               COUNT(*) AS hits, MAX(file_date) AS last_seen
        FROM vsync_peony_prices
        WHERE $normExpr = :d
        GROUP BY $normExpr
        LIMIT 1
    ");
    $stmt->execute([':d' => $digits]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Logger dedicado para debugging del pipeline de escaneo.
 * Escribe a ./.error_log del deploy (tail -f desde SSH para monitorear en vivo).
 */
function vs_log(string $msg): void
{
    $logPath = defined('APP_LOG_PATH')
        ? APP_LOG_PATH
        : dirname(__DIR__) . '/.error_log';
    @error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, $logPath);
}

/**
 * SCAN: procesa imagen → Gemini → match → retorna payload para que el frontend arme el form.
 * Si Gemini no está disponible, retorna extracted:null (modo manual).
 *
 * Instrumentado para diagnosticar 504 timeouts en HostGator:
 *   - log por paso (read / reencode / gemini / find_existing)
 *   - memoria 512M + tiempo 300s al inicio (defensivo)
 *   - re-encode a 1920px max (manual V1.3) para reducir payload y CPU
 *   - libera buffer de imagen antes de retornar
 */
function scan_extract_image(PDO $pdo, array $files): array
{
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    $t0 = microtime(true);
    vs_log('[scan_extract] START mem_peak=' . round(memory_get_peak_usage(true) / 1048576, 1) . 'MB');

    // ---- Paso 1: lectura / validación upload ----
    if (!isset($files['image']) || !is_uploaded_file($files['image']['tmp_name'] ?? '')) {
        vs_log('[scan_extract] ERR no_image');
        return ['ok' => false, 'error' => 'no_image'];
    }
    $file = $files['image'];
    vs_log(sprintf('[scan_extract] upload ok  name=%s  size=%d  type=%s',
        preg_replace('/[^\w.\-]/', '_', (string) ($file['name'] ?? '?')),
        (int) ($file['size'] ?? 0),
        (string) ($file['type'] ?? '?')
    ));
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        vs_log('[scan_extract] ERR image_too_large');
        return ['ok' => false, 'error' => 'image_too_large'];
    }

    // ---- Paso 2: re-encode + resize (max 1920px, JPEG 82 por master prompt) ----
    $origSize = (int) $file['size'];
    try {
        $t1 = microtime(true);
        $reenc = safe_reencode_image($file['tmp_name'], 1920, 82);
        vs_log(sprintf('[scan_extract] reencode OK  %.2fs  %d→%d bytes (-%d%%)',
            microtime(true) - $t1,
            $origSize,
            $reenc['size'],
            $origSize > 0 ? (int) round((1 - $reenc['size'] / $origSize) * 100) : 0
        ));
    } catch (Throwable $e) {
        vs_log('[scan_extract] ERR reencode: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'image_invalid', 'detail' => $e->getMessage()];
    }

    $sha256   = hash('sha256', $reenc['binary']);
    $reSize   = $reenc['size'];
    $b64Ready = base64_encode($reenc['binary']);
    unset($reenc);  // libera RAM antes de Gemini

    // ---- Paso 3: Gemini ----
    $ai          = new GeminiClient();
    $aiAvailable = $ai->isAvailable();
    $extracted   = null;
    $aiError     = null;
    if ($aiAvailable) {
        try {
            $t2 = microtime(true);
            vs_log(sprintf('[scan_extract] gemini → request  b64_len=%d', strlen($b64Ready)));
            $extracted = $ai->extractClientFields($b64Ready, 'image/jpeg');
            vs_log(sprintf('[scan_extract] gemini OK  %.2fs  phone=%s conf=%s',
                microtime(true) - $t2,
                $extracted['phone']      ?? '-',
                $extracted['confidence'] ?? '-'
            ));
        } catch (Throwable $e) {
            $aiError = $e->getMessage();
            vs_log('[scan_extract] ERR gemini: ' . $aiError);
        }
    } else {
        vs_log('[scan_extract] gemini NOT_CONFIGURED');
    }
    unset($b64Ready);  // libera ~1MB inmediatamente

    // ---- Paso 4: lookup cliente existente ----
    $existing = null;
    if ($extracted && !empty($extracted['phone'])) {
        try {
            $t3 = microtime(true);
            $existing = scan_find_existing($pdo, $extracted['phone']);
            vs_log(sprintf('[scan_extract] find_existing  %.2fs  match=%s',
                microtime(true) - $t3,
                $existing ? ($existing['source'] ?? 'yes') : 'no'
            ));
        } catch (Throwable $e) {
            vs_log('[scan_extract] ERR find_existing: ' . $e->getMessage());
        }
    }

    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    vs_log(sprintf('[scan_extract] DONE  total=%.2fs  mem_peak=%.1fMB',
        microtime(true) - $t0,
        memory_get_peak_usage(true) / 1048576
    ));

    return [
        'ok'           => true,
        'ai_available' => $aiAvailable,
        'ai_error'     => $aiError,
        'extracted'    => $extracted,
        'existing'     => $existing,
        'image_sha256' => $sha256,
        'image_size'   => $reSize,
    ];
}

/**
 * SCAN: consulta por teléfono (lookup manual desde UI, tras editar el campo).
 */
function scan_lookup(PDO $pdo, array $q): array
{
    $phone = trim((string) ($q['phone'] ?? ''));
    if ($phone === '') return ['ok' => false, 'error' => 'phone_required'];
    return ['ok' => true, 'existing' => scan_find_existing($pdo, $phone)];
}

/**
 * SCAN: guarda el escaneo (upsert manual + append scan + actualiza status como 'contacted' si aplica).
 */
function scan_save(PDO $pdo, array $body): array
{
    $phone    = trim((string) ($body['phone']    ?? ''));
    $company  = trim((string) ($body['company']  ?? ''));
    $buyer    = trim((string) ($body['buyer']    ?? ''));
    $material = trim((string) ($body['material'] ?? ''));
    $priceRaw = trim((string) ($body['price_raw'] ?? ''));
    $notes    = trim((string) ($body['notes']    ?? ''));
    $scanDate = trim((string) ($body['scan_date'] ?? ''));
    if ($scanDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDate)) {
        $scanDate = date('Y-m-d');
    }
    $sha        = trim((string) ($body['image_sha256'] ?? ''));
    $confidence = trim((string) ($body['confidence']   ?? ''));

    if ($phone === '')              return ['ok' => false, 'error' => 'phone_required'];
    if (mb_strlen($phone)    > 40)  return ['ok' => false, 'error' => 'phone_too_long'];
    if (mb_strlen($company)  > 150) return ['ok' => false, 'error' => 'company_too_long'];
    if (mb_strlen($buyer)    > 150) return ['ok' => false, 'error' => 'buyer_too_long'];
    if (mb_strlen($material) > 200) return ['ok' => false, 'error' => 'material_too_long'];
    if (mb_strlen($notes)    > 4000) return ['ok' => false, 'error' => 'notes_too_long'];

    $priceNum = null;
    if ($priceRaw !== '' && preg_match('/^([\d.]+)/', $priceRaw, $m)) $priceNum = (float) $m[1];

    $driver = db_driver();
    $tsNow  = $driver === 'sqlite' ? "datetime('now')" : "CURRENT_TIMESTAMP";

    $pdo->beginTransaction();
    try {
        // Upsert cliente
        if ($driver === 'sqlite') {
            $sql = "
                INSERT INTO vsync_clients_manual (phone, company, buyer, source, first_scan_at, last_scan_at, scan_count)
                VALUES (:phone, :company, :buyer, 'scan', $tsNow, $tsNow, 1)
                ON CONFLICT(phone) DO UPDATE SET
                    company = COALESCE(excluded.company, vsync_clients_manual.company),
                    buyer   = COALESCE(excluded.buyer,   vsync_clients_manual.buyer),
                    last_scan_at = $tsNow,
                    scan_count   = vsync_clients_manual.scan_count + 1
            ";
        } else {
            $sql = "
                INSERT INTO vsync_clients_manual (phone, company, buyer, source, first_scan_at, last_scan_at, scan_count)
                VALUES (:phone, :company, :buyer, 'scan', $tsNow, $tsNow, 1)
                ON DUPLICATE KEY UPDATE
                    company = COALESCE(VALUES(company), company),
                    buyer   = COALESCE(VALUES(buyer),   buyer),
                    last_scan_at = $tsNow,
                    scan_count   = scan_count + 1
            ";
        }
        $pdo->prepare($sql)->execute([
            ':phone'   => $phone,
            ':company' => $company !== '' ? $company : null,
            ':buyer'   => $buyer   !== '' ? $buyer   : null,
        ]);

        // Inserta scan
        $ins = $pdo->prepare("
            INSERT INTO vsync_client_scans
                (phone, scan_date, material, price_raw, price_num, notes,
                 company_snapshot, buyer_snapshot, image_sha256, confidence)
            VALUES
                (:phone, :scan_date, :material, :price_raw, :price_num, :notes,
                 :co, :by, :sha, :conf)
        ");
        $ins->execute([
            ':phone'     => $phone,
            ':scan_date' => $scanDate,
            ':material'  => $material !== '' ? $material : null,
            ':price_raw' => $priceRaw !== '' ? $priceRaw : null,
            ':price_num' => $priceNum,
            ':notes'     => $notes !== '' ? $notes : null,
            ':co'        => $company !== '' ? $company : null,
            ':by'        => $buyer   !== '' ? $buyer   : null,
            ':sha'       => $sha !== '' ? $sha : null,
            ':conf'      => $confidence !== '' ? $confidence : null,
        ]);
        $scanId = (int) $pdo->lastInsertId();

        // Si hay notas, persístelas también en vsync_client_notes para el modal de cliente
        if ($notes !== '') {
            $pdo->prepare("INSERT INTO vsync_client_notes (phone, note, author) VALUES (:p, :n, 'scan')")
                ->execute([':p' => $phone, ':n' => $notes]);
        }

        $pdo->commit();
        return ['ok' => true, 'phone' => $phone, 'scan_id' => $scanId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ======================================================================
// EDA — Exploratory Data Analysis por archivo
// ======================================================================

function peony_eda(PDO $pdo, array $params): array
{
    $fileId = (int) ($params['file_id'] ?? 0);
    if ($fileId <= 0) return ['ok' => false, 'error' => 'missing_file_id'];

    $stmt = $pdo->prepare("SELECT * FROM vsync_peony_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) return ['ok' => false, 'error' => 'file_not_found'];

    $stmt = $pdo->prepare("SELECT * FROM vsync_peony_prices WHERE file_id = ? ORDER BY category, material");
    $stmt->execute([$fileId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total  = count($rows);
    $nums   = [];
    $categories = [];
    foreach ($rows as $r) {
        $cat = $r['category'] ?? 'OTHER';
        $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        $pn = ($r['price_num'] !== null && $r['price_num'] !== '') ? (float) $r['price_num'] : null;
        if ($pn !== null && $pn > 0) $nums[] = $pn;
    }
    sort($nums);
    $n = count($nums);

    // Estadísticas descriptivas
    $stats = null;
    $lower = null; $upper = null;
    if ($n > 0) {
        $mean   = array_sum($nums) / $n;
        $median = $n % 2 === 0 ? ($nums[$n/2-1] + $nums[$n/2]) / 2 : $nums[(int)($n/2)];
        $q1     = $nums[(int)($n/4)];
        $q3     = $nums[(int)(3*$n/4)];
        $iqr    = $q3 - $q1;
        $lower  = $q1 - 1.5 * $iqr;
        $upper  = $q3 + 1.5 * $iqr;
        $variance = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $nums)) / $n;
        $stats = [
            'min'         => round($nums[0], 4),
            'max'         => round($nums[$n-1], 4),
            'mean'        => round($mean, 4),
            'median'      => round($median, 4),
            'std_dev'     => round(sqrt($variance), 4),
            'q1'          => round($q1, 4),
            'q3'          => round($q3, 4),
            'iqr'         => round($iqr, 4),
            'lower_fence' => round($lower, 4),
            'upper_fence' => round($upper, 4),
        ];
    }

    // Promedios históricos por material (archivos anteriores al actual)
    $histStmt = $pdo->prepare(
        "SELECT material, AVG(price_num) as h_avg, MIN(price_num) as h_min, MAX(price_num) as h_max
         FROM vsync_peony_prices
         WHERE file_id != ? AND price_num IS NOT NULL AND price_num > 0
         GROUP BY material"
    );
    $histStmt->execute([$fileId]);
    $hist = [];
    while ($hr = $histStmt->fetch(PDO::FETCH_ASSOC)) {
        $hist[$hr['material']] = $hr;
    }

    // Keywords que indican precio fórmula/relativo (no spot directo)
    $formulaKw = ['%', 'lme', 'spot', 'basis', 'cwt', 'comex', 'shfe', 'formula'];

    // Detección de outliers y problemas
    $outliers = [];
    $formulaCount = 0; $iqrCount = 0; $histCount = 0; $zeroCount = 0; $lmeResolvedCount = 0;

    foreach ($rows as $r) {
        $issues = [];
        $rawLower = strtolower((string) $r['price_raw']);
        $pn = $r['price_num'] !== null ? (float) $r['price_num'] : null;
        $isLmeResolved = !empty($r['lme_resolved']);

        // 1. Precio fórmula: price_raw tiene keywords no-spot
        // Si ya fue resuelto por LME, contar aparte y no marcar como anomalía
        if ($isLmeResolved) {
            $lmeResolvedCount++;
        } else {
            foreach ($formulaKw as $kw) {
                if (str_contains($rawLower, $kw)) {
                    $issues[] = [
                        'type'   => 'formula_price',
                        'detail' => "price_raw contiene «{$kw}» — precio porcentual/fórmula, no spot directo",
                        'level'  => 'warning',
                    ];
                    $formulaCount++;
                    break;
                }
            }
        }

        // 2. Outlier IQR
        if ($pn !== null && $n >= 4 && $lower !== null && ($pn < $lower || $pn > $upper)) {
            $issues[] = [
                'type'   => 'iqr_outlier',
                'detail' => "Fuera del rango IQR [" . round($lower, 4) . "–" . round($upper, 4) . "] — valor: {$pn}",
                'level'  => 'error',
            ];
            $iqrCount++;
        }

        // 3. Cero o negativo
        if ($pn !== null && $pn <= 0) {
            $issues[] = ['type' => 'zero_or_negative', 'detail' => "Precio ≤ 0: {$pn}", 'level' => 'error'];
            $zeroCount++;
        }

        // 4. Outlier histórico: desviación > 60% del promedio histórico conocido
        $mat = $r['material'];
        if ($pn !== null && isset($hist[$mat])) {
            $hAvg = (float) $hist[$mat]['h_avg'];
            if ($hAvg > 0) {
                $pct = abs($pn - $hAvg) / $hAvg * 100;
                if ($pct > 60) {
                    $issues[] = [
                        'type'   => 'historical_outlier',
                        'detail' => sprintf("Desviación %.0f%% del promedio histórico (hist_avg=%.4f, actual=%.4f)", $pct, $hAvg, $pn),
                        'level'  => 'error',
                    ];
                    $histCount++;
                }
            }
        }

        if (!empty($issues)) {
            $outliers[] = [
                'id'        => (int) $r['id'],
                'material'  => $r['material'],
                'category'  => $r['category'],
                'price_raw' => $r['price_raw'],
                'price_num' => $pn,
                'issues'    => $issues,
            ];
        }
    }

    $totalIssues = count($outliers);
    return [
        'ok'          => true,
        'file_id'     => $fileId,
        'filename'    => $file['filename'],
        'file_date'   => $file['file_date'],
        'summary'     => [
            'total_rows'      => $total,
            'rows_with_price' => $n,
            'null_price_count'=> $total - $n,
            'null_price_rate' => $total > 0 ? round(($total - $n) / $total * 100, 1) : 0.0,
            'categories'      => $categories,
        ],
        'price_stats' => $stats,
        'outliers'    => $outliers,
        'audit'       => [
            'formula_prices'     => $formulaCount,
            'lme_resolved'       => $lmeResolvedCount,
            'iqr_outliers'       => $iqrCount,
            'historical_outliers'=> $histCount,
            'zero_or_negative'   => $zeroCount,
            'total_issues'       => $totalIssues,
            'quality_score'      => $total > 0 ? round((1 - $totalIssues / $total) * 100, 1) : 100.0,
        ],
    ];
}

// ======================================================================
// Export CSV por archivo
// ======================================================================

function peony_export_csv(PDO $pdo, array $params): void
{
    $fileId = (int) ($params['file_id'] ?? 0);
    if ($fileId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'missing_file_id']);
        return;
    }

    $stmt = $pdo->prepare("SELECT filename, file_date FROM vsync_peony_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'file_not_found']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT file_date, category, material, price_raw, price_num, price_unit,
                delivery_basis, company, buyer, phone, row_status
         FROM vsync_peony_prices WHERE file_id = ? ORDER BY category, material"
    );
    $stmt->execute([$fileId]);

    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $file['filename']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['file_date','category','material','price_raw','price_num','price_unit',
                   'delivery_basis','company','buyer','phone','row_status']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['file_date'], $r['category'], $r['material'], $r['price_raw'],
            $r['price_num'], $r['price_unit'], $r['delivery_basis'],
            $r['company'], $r['buyer'], $r['phone'], $r['row_status'],
        ]);
    }
    fclose($out);
}

// ======================================================================
// Helpers internos
// ======================================================================

function peony_build_date_where(string $col, string $filter, string $date, bool $prefix = true, string $dateFrom = '', string $dateTo = ''): array
{
    $params = [];
    $driver = db_driver();

    if ($filter === 'day') {
        $d = $date !== '' ? $date : gmdate('Y-m-d');
        $where = "$col = :d";
        $params[':d'] = $d;
    } elseif ($filter === 'week') {
        $base = $date !== '' ? $date : gmdate('Y-m-d');
        if ($driver === 'mysql') {
            $startExpr = "DATE_SUB(:base, INTERVAL WEEKDAY(:base) DAY)";
            $where = "$col BETWEEN $startExpr AND DATE_ADD($startExpr, INTERVAL 6 DAY)";
        } else {
            $where = "$col BETWEEN date(:base, 'weekday 0', '-6 days') AND date(:base, 'weekday 0')";
        }
        $params[':base'] = $base;
    } elseif ($filter === 'month') {
        $base = $date !== '' ? $date : gmdate('Y-m-d');
        if ($driver === 'mysql') {
            $where = "DATE_FORMAT($col, '%Y-%m') = DATE_FORMAT(:base, '%Y-%m')";
        } else {
            $where = "strftime('%Y-%m', $col) = strftime('%Y-%m', :base)";
        }
        $params[':base'] = $base;
    } elseif ($filter === 'range' && ($dateFrom !== '' || $dateTo !== '')) {
        if ($dateFrom !== '' && $dateTo !== '') {
            $where = "$col BETWEEN :df AND :dt";
            $params[':df'] = $dateFrom;
            $params[':dt'] = $dateTo;
        } elseif ($dateFrom !== '') {
            $where = "$col >= :df";
            $params[':df'] = $dateFrom;
        } else {
            $where = "$col <= :dt";
            $params[':dt'] = $dateTo;
        }
    } else {
        return ['', []];
    }

    return [($prefix ? 'WHERE ' : '') . $where, $params];
}

function format_fecha_es(?string $ts): ?string
{
    if (!$ts) return null;
    $meses = [1=>'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
              'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $t = strtotime($ts);
    if (!$t) return $ts;
    $h = (int) date('G', $t);
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12 === 0 ? 12 : $h % 12;
    return sprintf('%d de %s, %d - %d:%02d %s',
        (int) date('j', $t), $meses[(int) date('n', $t)], (int) date('Y', $t),
        $h12, (int) date('i', $t), $ampm);
}
