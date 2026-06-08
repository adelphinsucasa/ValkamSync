<?php
declare(strict_types=1);

/**
 * ValkamSync API — single-file dispatcher (patrón del master-replication-prompt.md).
 *
 * Acciones disponibles (vía ?action=...):
 *   peony_dashboard  GET   — última sincronización + conteos para el header
 *   peony_files      GET   — lista archivos con filtro Mes/Semana/Día
 *   peony_prices     GET   — filas de precios (paginado)
 *   peony_compare    GET   — tendencias actuales vs históricos
 *   peony_validate   POST  — cambia sync_status (state machine enforced)
 *
 * Todas las queries usan PDO prepared statements (ver lib/peony_repo.php).
 */

// ---------- Config loader ----------
$configPaths = [
    __DIR__ . '/.app_config.php',
    dirname(__DIR__) . '/secrets/app_config.php',
];
$configLoaded = false;
foreach ($configPaths as $path) {
    if (is_file($path) && is_readable($path)) {
        require_once $path;
        $configLoaded = true;
        break;
    }
}
if (!$configLoaded) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'config_missing']);
    exit;
}

// ---------- Libs ----------
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/peony_parser.php';
require_once __DIR__ . '/lib/gemini_client.php';
require_once __DIR__ . '/lib/peony_repo.php';
require_once __DIR__ . '/lib/lme_client.php';

// ---------- Global exception handler (innegociable, master prompt §9) ----------
set_exception_handler(function (\Throwable $e): void {
    $ref = substr(sha1($e->getMessage() . $e->getFile() . $e->getLine()), 0, 8);
    @error_log("[valkamsync:api:$ref] " . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok'    => false,
        'error' => 'server_error',
        'ref'   => $ref,
    ]);
    exit;
});

// ---------- Headers base ----------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ---------- Rate limit muy básico por IP (file-based, flock) ----------
rate_limit_check($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// ---------- CSRF para POST: Origin/Referer match ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_origin_ok()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
}

// ---------- Init DB ----------
$pdo = db();
initDB($pdo);

// ---------- Auth guard ----------
// Acciones que NO requieren sesión activa
$_AUTH_OPEN = ['auth_login', 'auth_check', 'auth_logout', 'health'];
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if (!in_array($action, $_AUTH_OPEN, true) && !auth_check_session($pdo)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

// ---------- Routing ----------
switch ($action) {
    case 'peony_dashboard':
        require_get();
        echo json_encode(peony_dashboard($pdo), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_files':
        require_get();
        echo json_encode(peony_files($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_prices':
        require_get();
        echo json_encode(peony_prices($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_compare':
        require_get();
        echo json_encode(peony_compare($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_clients':
        require_get();
        echo json_encode(peony_clients($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_client':
        require_get();
        echo json_encode(peony_client_detail($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_available_files':
        require_get();
        echo json_encode(peony_available_files($pdo), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_import_files':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 16384) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        @set_time_limit(180);   // import puede tardar 30-90s según # archivos
        echo json_encode(peony_import_files($pdo, json_decode($raw, true) ?: []), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_materials':
        require_get();
        echo json_encode(peony_materials($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_material_detail':
        require_get();
        echo json_encode(peony_material_detail($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_material_contacts':
        require_get();
        echo json_encode(peony_material_contacts($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'scan_extract':
        require_post();
        // Optimizaciones HostGator: evitar 504 Gateway Timeout en OCR
        // Manual V1.3: memoria 512M, tiempo 300s al inicio de extracción
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        @ignore_user_abort(true);
        // multipart upload — NO leer php://input aquí
        echo json_encode(scan_extract_image($pdo, $_FILES), JSON_UNESCAPED_UNICODE);
        break;

    case 'scan_lookup':
        require_get();
        echo json_encode(scan_lookup($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'scan_save':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 12288) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        echo json_encode(scan_save($pdo, json_decode($raw, true) ?: []), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_clients_stats':
        require_get();
        echo json_encode(peony_clients_stats($pdo), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_client_status_update':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 4096) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        echo json_encode(peony_client_status_update($pdo, json_decode($raw, true) ?: []), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_client_notes':
        require_get();
        echo json_encode(peony_client_notes($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_client_note_create':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 10240) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        $body = json_decode($raw, true) ?: [];
        echo json_encode(peony_client_note_create($pdo, $body), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_validate':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 10240) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        $body = json_decode($raw, true) ?: [];
        echo json_encode(peony_validate($pdo, $body), JSON_UNESCAPED_UNICODE);
        break;

    // ---- Auth ----
    case 'auth_login':
        require_post();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 512) { http_response_code(413); echo json_encode(['ok'=>false,'error'=>'payload_too_large']); exit; }
        echo json_encode(auth_login($pdo, json_decode($raw, true) ?: []), JSON_UNESCAPED_UNICODE);
        break;

    case 'auth_check':
        require_get();
        echo json_encode(['ok' => auth_check_session($pdo)]);
        break;

    case 'auth_logout':
        // GET o POST — SameSite=Strict ya previene CSRF
        echo json_encode(auth_logout($pdo), JSON_UNESCAPED_UNICODE);
        break;

    case 'auth_pin_change':
        require_post();
        if (!auth_check_session($pdo)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthenticated']); exit; }
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 256) { http_response_code(413); echo json_encode(['ok'=>false,'error'=>'payload_too_large']); exit; }
        echo json_encode(auth_pin_change($pdo, json_decode($raw, true) ?: []), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_eda':
        require_get();
        echo json_encode(peony_eda($pdo, $_GET), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_export_csv':
        require_get();
        peony_export_csv($pdo, $_GET);
        break;

    case 'peony_lme_resolve_all':
        require_post();
        @set_time_limit(300);
        echo json_encode(array_merge(['ok' => true], lme_resolve_all_pending($pdo)), JSON_UNESCAPED_UNICODE);
        break;

    case 'peony_lme_fix_units':
        // Migración one-shot: corrige price_num almacenado en USD/MT en lugar de USD/lb.
        // Ejecutar una sola vez tras el deploy que introdujo LME_LB_PER_MT.
        require_post();
        echo json_encode(array_merge(['ok' => true], lme_fix_unit_prices($pdo)), JSON_UNESCAPED_UNICODE);
        break;

    case 'health':
        echo json_encode([
            'ok'             => true,
            'driver'         => db_driver(),
            'parser_version' => PeonyParser::VERSION,
            'php'            => PHP_VERSION,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_action', 'given' => $action]);
}

// ---------- Helpers ----------
function require_get(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }
}

function csrf_origin_ok(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    foreach ([$origin, $referer] as $src) {
        if ($src === '') continue;
        $p = parse_url($src);
        if (isset($p['host']) && strcasecmp($p['host'], $host) === 0) {
            return true;
        }
    }
    return false;
}

function rate_limit_check(string $ip): void
{
    $dir = sys_get_temp_dir() . '/vsync_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . sha1($ip);

    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }

    $now = time();
    $window = 60;
    $maxHits = 120; // 120 req/min por IP
    $hits = [];
    $raw = stream_get_contents($fp);
    if ($raw) {
        $hits = json_decode($raw, true) ?: [];
        $hits = array_filter($hits, fn($t) => $t > $now - $window);
    }
    if (count($hits) >= $maxHits) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'rate_limited']);
        exit;
    }
    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($hits)));
    flock($fp, LOCK_UN);
    fclose($fp);
}
