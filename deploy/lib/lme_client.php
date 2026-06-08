<?php
declare(strict_types=1);

/**
 * LME Spot Price Client — Alpha Vantage (IMF Primary Commodity Prices)
 *
 * Resuelve precios fórmula del tipo "75%LMESpot" usando el precio mensual oficial
 * del London Metal Exchange (LME) en USD/MT, publicado por el FMI vía Alpha Vantage.
 *
 * Credenciales: define ALPHAVANTAGE_API_KEY en .app_config.php.
 * Clave gratis (25 req/día) en: https://www.alphavantage.co/support/#api-key
 *
 * Lógica de precio por fecha:
 *   1. Busca en cache vsync_lme_cache el precio exacto para ese metal+fecha.
 *   2. Si no hay cache: llama Alpha Vantage (datos mensuales LME).
 *   3. Usa el precio del mes exacto si está disponible; si no, el mes más reciente anterior.
 *   4. Guarda en cache para no repetir el fetch.
 *
 * Flujo para "75%LMESpot":
 *   lme_parse_formula()        → {pct: 75}
 *   lme_get_spot()             → cache → Alpha Vantage → precio USD/MT
 *   precio calculado           = spot × 0.75
 *   price_num = calculado, lme_price = spot de referencia, lme_resolved = 1
 */

// Mapeo categoría Peony → clave de cache LME (retrocompatible con datos existentes)
const LME_METAL_MAP = [
    'ALUMINUM'        => 'LME-AL',
    'COPPER'          => 'LME-CU',
    'COPPER/BRASS'    => 'LME-CU',
    'BRASS'           => 'LME-CU',
    'SS/HITEMP/OTHER' => 'LME-CU',  // E-Scrap, Solder Dross — Copper como referencia base
];

// Mapeo clave de cache → función Alpha Vantage
const LME_AV_FUNCTIONS = [
    'LME-AL' => 'ALUMINUM',
    'LME-CU' => 'COPPER',
];

/**
 * Descarga el dataset mensual completo de Alpha Vantage para un metal.
 * Cacheado en variable estática — una sola llamada HTTP por metal por ejecución.
 *
 * @param string $lmeKey  'LME-CU' o 'LME-AL'
 * @return array          [{date: "YYYY-MM-01", value: "12345.67"}, ...] o []
 */
function lme_av_dataset(string $lmeKey): array
{
    static $cache = [];
    if (array_key_exists($lmeKey, $cache)) return $cache[$lmeKey];

    if (!function_exists('curl_init')) { $cache[$lmeKey] = []; return []; }
    if (!defined('ALPHAVANTAGE_API_KEY') || (string) ALPHAVANTAGE_API_KEY === '') {
        error_log('LME: ALPHAVANTAGE_API_KEY no configurado en .app_config.php');
        $cache[$lmeKey] = [];
        return [];
    }

    $function = LME_AV_FUNCTIONS[$lmeKey] ?? null;
    if (!$function) { $cache[$lmeKey] = []; return []; }

    $url = 'https://www.alphavantage.co/query?function=' . $function
         . '&interval=monthly&apikey=' . rawurlencode((string) ALPHAVANTAGE_API_KEY);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: ValkamSync/1.0 (+https://petit.valkamgm.com)',
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("LME AV HTTP $code para $function");
        $cache[$lmeKey] = [];
        return [];
    }

    $body = json_decode((string) $resp, true);
    $data = $body['data'] ?? [];

    if (empty($data)) {
        error_log("LME AV: sin datos para $function — verifica ALPHAVANTAGE_API_KEY");
    }

    $cache[$lmeKey] = $data;
    return $data;
}

/**
 * Obtiene el precio mensual LME de Alpha Vantage para un metal y fecha (USD/MT).
 *
 * Usa el mes exacto si está disponible; si no (mes en curso sin publicar),
 * usa el mes más reciente anterior. El dataset completo se descarga una vez
 * y se reutiliza para todas las fechas del mismo metal en la misma ejecución.
 *
 * @param string $lmeKey   'LME-CU' o 'LME-AL'
 * @param string $date     'YYYY-MM-DD'
 * @return float|null      USD/MT o null si falla
 */
function lme_fetch_alphavantage(string $lmeKey, string $date): ?float
{
    $data = lme_av_dataset($lmeKey);
    if (empty($data)) return null;

    // data[] = [{date: "YYYY-MM-01", value: "12345.67"}, ...] orden descendente
    $targetMonth  = substr($date, 0, 7);   // "YYYY-MM"
    $bestFallback = null;

    foreach ($data as $pt) {
        $ptMonth = substr((string) ($pt['date'] ?? ''), 0, 7);
        $ptValue = (float) ($pt['value'] ?? 0);
        if ($ptValue <= 0) continue;

        if ($ptMonth === $targetMonth) {
            return $ptValue;                          // coincidencia exacta de mes
        }
        if ($ptMonth < $targetMonth && $bestFallback === null) {
            $bestFallback = $ptValue;                 // mes más reciente previo al objetivo
        }
    }

    if ($bestFallback !== null) {
        error_log("LME AV: $lmeKey en $date → usando promedio del mes anterior (dato del mes objetivo aún sin publicar)");
    }

    return $bestFallback;
}

/**
 * Parsea el formato "75%LMESpot" → ['pct' => 75.0].
 * Solo matchea {N}%LMESpot — excluye compuestos como "70%LMENi+Co".
 * Devuelve null si el price_raw no es una fórmula LME spot resolvible.
 */
function lme_parse_formula(string $priceRaw): ?array
{
    if (preg_match('/^(\d+(?:\.\d+)?)\s*%\s*LMESpot\s*$/i', trim($priceRaw), $m)) {
        return ['pct' => (float) $m[1]];
    }
    return null;
}

/**
 * Devuelve el precio spot LME para un metal en una fecha, usando cache DB primero.
 *
 * @param string $metal   Categoría Peony: 'ALUMINUM', 'COPPER/BRASS', etc.
 * @param string $date    'YYYY-MM-DD'
 * @return float|null     USD/MT o null si falla
 */
function lme_get_spot(PDO $pdo, string $metal, string $date): ?float
{
    $lmeKey = LME_METAL_MAP[strtoupper($metal)] ?? null;
    if (!$lmeKey) return null;

    // 1. Buscar en cache (por fecha exacta)
    try {
        $stmt = $pdo->prepare(
            "SELECT cash_usd FROM vsync_lme_cache WHERE metal = ? AND price_date = ? LIMIT 1"
        );
        $stmt->execute([$lmeKey, $date]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false && (float) $cached > 0) return (float) $cached;
    } catch (Throwable $e) {
        error_log('LME cache read: ' . $e->getMessage());
    }

    // 2. Fetch Alpha Vantage (precio mensual LME)
    $price = lme_fetch_alphavantage($lmeKey, $date);
    if ($price === null || $price <= 0) return null;

    // 3. Guardar en cache con la fecha exacta solicitada
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->prepare(
                "INSERT OR REPLACE INTO vsync_lme_cache (metal, price_date, cash_usd, source) VALUES (?, ?, ?, 'alphavantage')"
            )->execute([$lmeKey, $date, $price]);
        } else {
            $pdo->prepare(
                "INSERT INTO vsync_lme_cache (metal, price_date, cash_usd, source) VALUES (?, ?, ?, 'alphavantage')
                 ON DUPLICATE KEY UPDATE cash_usd = VALUES(cash_usd), source = 'alphavantage', fetched_at = NOW()"
            )->execute([$lmeKey, $date, $price]);
        }
    } catch (Throwable $e) {
        error_log('LME cache write: ' . $e->getMessage());
    }

    return $price;
}

/**
 * Resuelve precios fórmula para un archivo recién importado.
 * Corre fuera de transacción — best-effort, no afecta el import si falla.
 *
 * Para cada fila con price_raw = "75%LMESpot":
 *   1. Detecta la fórmula (pct=75)
 *   2. Busca el LME Cash USD/MT del mes del archivo (cache → Alpha Vantage)
 *   3. Calcula precio neto = spot × pct/100
 *   4. Actualiza price_num (precio calculado), lme_price (spot referencia), lme_resolved=1
 *
 * @return array{resolved: int, skipped: int}
 */
function lme_resolve_formula_prices(PDO $pdo, int $fileId, string $fileDate): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id, category, price_raw FROM vsync_peony_prices
             WHERE file_id = ? AND lme_resolved = 0"
        );
        $stmt->execute([$fileId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LME resolve fetch: ' . $e->getMessage());
        return ['resolved' => 0, 'skipped' => 0];
    }

    $resolved  = 0;
    $skipped   = 0;
    $spotCache = [];   // [cat|date => float|null]

    $upd = $pdo->prepare(
        "UPDATE vsync_peony_prices
         SET price_num = ?, lme_resolved = 1, lme_price = ?
         WHERE id = ?"
    );

    foreach ($rows as $r) {
        $formula = lme_parse_formula((string) $r['price_raw']);
        if (!$formula) continue;

        $cat = strtoupper((string) ($r['category'] ?? ''));
        if (!isset(LME_METAL_MAP[$cat])) { $skipped++; continue; }

        $cacheKey = $cat . '|' . $fileDate;
        if (!array_key_exists($cacheKey, $spotCache)) {
            $spotCache[$cacheKey] = lme_get_spot($pdo, $cat, $fileDate);
        }
        $spot = $spotCache[$cacheKey];

        if ($spot === null) { $skipped++; continue; }

        $calculated = round(($formula['pct'] / 100.0) * $spot, 4);
        try {
            $upd->execute([$calculated, $spot, (int) $r['id']]);
            $resolved++;
        } catch (Throwable $e) {
            error_log('LME resolve update: ' . $e->getMessage());
            $skipped++;
        }
    }

    if ($resolved > 0 || $skipped > 0) {
        error_log("LME resolve: file=$fileId date=$fileDate resolved=$resolved skipped=$skipped");
    }

    return ['resolved' => $resolved, 'skipped' => $skipped];
}

/**
 * Re-resuelve TODOS los precios fórmula pendientes en la DB (lme_resolved=0).
 * Agrupa por metal+fecha para minimizar llamadas a Alpha Vantage.
 *
 * @return array{files: int, resolved: int, skipped: int}
 */
function lme_resolve_all_pending(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.category, p.price_raw, f.file_date
             FROM vsync_peony_prices p
             JOIN vsync_peony_files f ON f.id = p.file_id
             WHERE p.lme_resolved = 0"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LME resolve_all fetch: ' . $e->getMessage());
        return ['files' => 0, 'resolved' => 0, 'skipped' => 0];
    }

    $resolved  = 0;
    $skipped   = 0;
    $spotCache = [];
    $fileDates = [];

    $upd = $pdo->prepare(
        "UPDATE vsync_peony_prices SET price_num = ?, lme_resolved = 1, lme_price = ? WHERE id = ?"
    );

    foreach ($rows as $r) {
        $formula = lme_parse_formula((string) $r['price_raw']);
        if (!$formula) continue;

        $cat = strtoupper((string) ($r['category'] ?? ''));
        if (!isset(LME_METAL_MAP[$cat])) { $skipped++; continue; }

        $date     = (string) $r['file_date'];
        $cacheKey = $cat . '|' . $date;
        if (!array_key_exists($cacheKey, $spotCache)) {
            $spotCache[$cacheKey] = lme_get_spot($pdo, $cat, $date);
        }
        $spot = $spotCache[$cacheKey];

        if ($spot === null) { $skipped++; continue; }

        $calculated = round(($formula['pct'] / 100.0) * $spot, 4);
        try {
            $upd->execute([$calculated, $spot, (int) $r['id']]);
            $resolved++;
            $fileDates[$date] = true;
        } catch (Throwable $e) {
            error_log('LME resolve_all update: ' . $e->getMessage());
            $skipped++;
        }
    }

    error_log("LME resolve_all: files=" . count($fileDates) . " resolved=$resolved skipped=$skipped");
    return ['files' => count($fileDates), 'resolved' => $resolved, 'skipped' => $skipped];
}
