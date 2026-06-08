<?php
declare(strict_types=1);

/**
 * LME 4-Point Matrix Client — ValkamSync F7+
 *
 * Resuelve precios fórmula del tipo "75%LMESpot", "80% LME", "75% de LME"
 * usando la matriz completa de 4 puntos del London Metal Exchange:
 *
 *   Cash Buyer         → precio contado de compra (físico inmediato)
 *   Cash Seller        → precio contado de venta  (= Cash Settlement)
 *   3 Months Buyer     → precio a plazo de compra
 *   3 Months Seller    → precio a plazo de venta
 *
 * Lógica de cálculo (negocio):
 *   1. Obtener los 4 valores de la matriz para el metal y la fecha del PDF.
 *   2. Determinar cuál de los 4 es el MENOR absoluto (criterio conservador Valkam).
 *   3. precio_final = valor_mínimo × (pct / 100).
 *   4. Persistir precio_final + la matriz completa en columnas de auditoría.
 *
 * Jerarquía de fuentes (cascada automática ante fallos):
 *   0. Cache DB (vsync_lme_cache) — consulta primero; si existe, evita llamadas a la red.
 *   1. Alpha Vantage (alphavantage.co) — PRIORIDAD 1. Promedio mensual IMF. Probada en prod.
 *   2. Metal Radar  (metalradar.io)   — PRIORIDAD 2. Datos diarios LME. Activar al renovar key.
 *   3. NASDAQ Data Link               — PRIORIDAD 3. Último recurso. Datos diarios 4 puntos.
 *   4. Error — las 3 fuentes fallaron; se registra en lme_error, lme_resolved = -1.
 *
 * Comportamiento ante fallo:
 *   Cada fuente se intenta solo si la anterior falló (HTTP error, key vacía/inválida,
 *   límite de requests, respuesta vacía). Los fallos se registran en error_log.
 *   Si la key de una fuente está vacía, se omite silenciosamente sin log de error.
 *
 * Manejo de días no hábiles (fines de semana, feriados LME):
 *   Se busca hacia atrás hasta 7 días para encontrar el último día hábil.
 *   El campo trade_date en vsync_lme_cache registra la fecha real de cotización
 *   usada (puede diferir de price_date del PDF).
 *
 * Credenciales (.app_config.php del servidor — ver .app_config.php.example):
 *   define('ALPHAVANTAGE_API_KEY',  '...');  ← P1 (25 req/día gratis)
 *   define('METALRADAR_EMAIL',      '...');  ← P2 — usuario Metal Radar
 *   define('METALRADAR_PASSWORD',   '...');  ← P2 — contraseña Metal Radar
 *   define('NASDAQ_API_KEY',        '...');  ← P3 (50 req/día gratis)
 */

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTES Y MAPEOS
// ─────────────────────────────────────────────────────────────────────────────

/** Categoría Peony → clave interna de metal (retrocompatible con datos existentes) */
const LME_METAL_MAP = [
    'ALUMINUM'        => 'LME-AL',
    'COPPER'          => 'LME-CU',
    'COPPER/BRASS'    => 'LME-CU',
    'BRASS'           => 'LME-CU',
    'SS/HITEMP/OTHER' => 'LME-CU',   // E-Scrap, Solder Dross — Copper como referencia
];

/** Clave interna → dataset NASDAQ Data Link (datos diarios LME completos) */
const LME_NASDAQ_DATASETS = [
    'LME-AL' => 'LME/PR_AL',
    'LME-CU' => 'LME/PR_CU',
];

/** Clave interna → función Alpha Vantage (P1 — promedio mensual IMF) */
const LME_AV_FUNCTIONS = [
    'LME-AL' => 'ALUMINUM',
    'LME-CU' => 'COPPER',
];

/** Clave interna → símbolo Metal Radar (P2 — datos diarios, auth email/password) */
const LME_METALRADAR_SYMBOLS = [
    'LME-AL' => 'ALUMINUM',
    'LME-CU' => 'COPPER',
];

/** URL base de la API Metal Radar — verificar con la documentación al renovar acceso */
const METALRADAR_API_BASE = 'https://metalradar.io/api/v1';

/**
 * Etiquetas de los 4 puntos de la matriz LME.
 * El valor de cada clave se almacena en lme_type_used para trazabilidad de auditoría.
 */
const LME_MATRIX_LABELS = [
    'CASH_BUYER'          => 'Cash Buyer',
    'CASH_SELLER'         => 'Cash Seller',
    'THREE_MONTHS_BUYER'  => '3 Months Buyer',
    'THREE_MONTHS_SELLER' => '3 Months Seller',
];

// ─────────────────────────────────────────────────────────────────────────────
// PARSEO DE FÓRMULA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parsea un string de fórmula LME a un array normalizado.
 *
 * Acepta:
 *   "75%LMESpot"     "75% LMESpot"    "80%LME"
 *   "80% LME"        "75% de LME"     "90 % LME Spot"
 *
 * Rechaza (devuelve null):
 *   "70%LMENi+Co"   (tiene sufijo distinto a "Spot")
 *   "LME 3M+46/-pr" (no inicia con porcentaje)
 *   "LLMEx60%"      (formato inválido)
 *
 * @return array{pct: float}|null
 */
function lme_parse_formula(string $priceRaw): ?array
{
    // Patrón: {número}[espacio]%[espacio][de ][espacio]LME[Spot|fin]
    if (preg_match(
        '/^(\d+(?:\.\d+)?)\s*%\s*(?:de\s+)?LME(?:\s*Spot)?\s*$/i',
        trim($priceRaw),
        $m
    )) {
        return ['pct' => (float) $m[1]];
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// FUENTE PRIMARIA: NASDAQ DATA LINK (4 puntos diarios)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Descarga datos LME diarios de NASDAQ Data Link para los 7 días anteriores a $dateFrom.
 * Cacheado estáticamente — una llamada HTTP por ($lmeKey × $dateFrom) por ejecución.
 *
 * @param  string $lmeKey   'LME-CU' o 'LME-AL'
 * @param  string $dateFrom 'YYYY-MM-DD' — fecha objetivo (inclusive)
 * @return array            ['YYYY-MM-DD' => ['cash_buyer'=>..., 'cash_seller'=>...,
 *                           'three_months_buyer'=>..., 'three_months_seller'=>...,
 *                           'source'=>'nasdaq'], ...]
 */
function lme_nasdaq_fetch_range(string $lmeKey, string $dateFrom): array
{
    static $cache = [];
    $cKey = $lmeKey . '|' . $dateFrom;
    if (array_key_exists($cKey, $cache)) return $cache[$cKey];
    $cache[$cKey] = [];

    if (!function_exists('curl_init')) return [];
    if (!defined('NASDAQ_API_KEY') || (string) NASDAQ_API_KEY === '') {
        error_log('LME NASDAQ: NASDAQ_API_KEY no configurado en .app_config.php');
        return [];
    }

    $dataset = LME_NASDAQ_DATASETS[$lmeKey] ?? null;
    if (!$dataset) return [];

    // Pedimos desde 7 días antes para cubrir fines de semana y feriados LME
    $startDate = date('Y-m-d', strtotime($dateFrom . ' -7 days'));

    $url = 'https://data.nasdaq.com/api/v3/datasets/'
         . rawurlencode($dataset)
         . '.json'
         . '?start_date=' . rawurlencode($startDate)
         . '&end_date='   . rawurlencode($dateFrom)
         . '&order=desc'
         . '&api_key='    . rawurlencode((string) NASDAQ_API_KEY);

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
        error_log("LME NASDAQ HTTP $code para $dataset ($startDate → $dateFrom)");
        return [];
    }

    $body = json_decode((string) $resp, true);
    $ds   = $body['dataset'] ?? null;
    if (!$ds) {
        error_log("LME NASDAQ: respuesta inesperada para $dataset");
        return [];
    }

    // Mapear nombres de columna a índices para ser robusto ante cambios de orden.
    // NASDAQ LME columnas esperadas: Date, Cash Buyer, Cash Seller, Cash Settlement,
    //                                3 Months Buyer, 3 Months Seller, 3 Months Settlement, Stock
    $cols = array_flip(array_map('strtolower', (array) ($ds['column_names'] ?? [])));
    $iDate = $cols['date']            ?? 0;
    $iCB   = $cols['cash buyer']      ?? 1;
    $iCS   = $cols['cash seller']     ?? 2;
    $i3B   = $cols['3 months buyer']  ?? 4;
    $i3S   = $cols['3 months seller'] ?? 5;

    $result = [];
    foreach ((array) ($ds['data'] ?? []) as $row) {
        $date = (string) ($row[$iDate] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        $cb = isset($row[$iCB]) && $row[$iCB] !== null ? (float) $row[$iCB] : null;
        $cs = isset($row[$iCS]) && $row[$iCS] !== null ? (float) $row[$iCS] : null;
        $tb = isset($row[$i3B]) && $row[$i3B] !== null ? (float) $row[$i3B] : null;
        $ts = isset($row[$i3S]) && $row[$i3S] !== null ? (float) $row[$i3S] : null;

        // Exigir los 4 puntos con valor positivo para garantizar la integridad de la matriz
        if ($cb === null || $cs === null || $tb === null || $ts === null) continue;
        if ($cb <= 0  || $cs <= 0  || $tb <= 0  || $ts <= 0)  continue;

        $result[$date] = [
            'cash_buyer'          => $cb,
            'cash_seller'         => $cs,
            'three_months_buyer'  => $tb,
            'three_months_seller' => $ts,
            'source'              => 'nasdaq',
        ];
    }

    $cache[$cKey] = $result;
    return $result;
}

/**
 * Retorna la matriz de 4 puntos LME de NASDAQ para una fecha dada.
 * Si la fecha no tiene cotización (fin de semana / feriado), busca hacia atrás
 * hasta 7 días calendario para encontrar el último día hábil LME.
 *
 * @param  string     $lmeKey 'LME-CU' o 'LME-AL'
 * @param  string     $date   'YYYY-MM-DD' — fecha del PDF
 * @return array|null         Matriz con campos cash_buyer, cash_seller, three_months_buyer,
 *                            three_months_seller, trade_date, source  |  null si falla
 */
function lme_fetch_nasdaq_matrix(string $lmeKey, string $date): ?array
{
    $data = lme_nasdaq_fetch_range($lmeKey, $date);
    if (empty($data)) return null;

    $ts = strtotime($date);
    for ($i = 0; $i <= 7; $i++) {
        $candidate = date('Y-m-d', $ts - $i * 86400);
        if (isset($data[$candidate])) {
            $matrix               = $data[$candidate];
            $matrix['trade_date'] = $candidate;   // fecha real de cotización LME (≤ date del PDF)
            if ($i > 0) {
                error_log(
                    "LME NASDAQ: $lmeKey sin cotización en $date → "
                    . "usando último día hábil anterior: $candidate ($i días atrás)"
                );
            }
            return $matrix;
        }
    }

    error_log("LME NASDAQ: $lmeKey sin datos en $date ni en los 7 días anteriores");
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// FUENTE FALLBACK: ALPHA VANTAGE (promedio mensual, 1 punto)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Descarga el dataset mensual de Alpha Vantage para un metal (IMF Primary Commodity Prices).
 * Cacheado estáticamente — una llamada HTTP por metal por ejecución.
 * Se usa como fallback cuando NASDAQ no está configurado o falla.
 */
function lme_av_dataset(string $lmeKey): array
{
    static $cache = [];
    if (array_key_exists($lmeKey, $cache)) return $cache[$lmeKey];
    $cache[$lmeKey] = [];

    if (!function_exists('curl_init')) return [];
    if (!defined('ALPHAVANTAGE_API_KEY') || (string) ALPHAVANTAGE_API_KEY === '') {
        $cache[$lmeKey] = [];
        return [];
    }

    $function = LME_AV_FUNCTIONS[$lmeKey] ?? null;
    if (!$function) return [];

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
 * Usa el mes exacto si disponible; si no, el mes más reciente anterior al objetivo.
 */
function lme_fetch_alphavantage(string $lmeKey, string $date): ?float
{
    $data = lme_av_dataset($lmeKey);
    if (empty($data)) return null;

    $targetMonth  = substr($date, 0, 7);
    $bestFallback = null;

    foreach ($data as $pt) {
        $ptMonth = substr((string) ($pt['date'] ?? ''), 0, 7);
        $ptValue = (float) ($pt['value'] ?? 0);
        if ($ptValue <= 0) continue;

        if ($ptMonth === $targetMonth) return $ptValue;
        if ($ptMonth < $targetMonth && $bestFallback === null) {
            $bestFallback = $ptValue;
        }
    }

    if ($bestFallback !== null) {
        error_log("LME AV: $lmeKey en $date → usando promedio del mes anterior (mes objetivo aún sin publicar)");
    }
    return $bestFallback;
}

// ─────────────────────────────────────────────────────────────────────────────
// FUENTE P2: METAL RADAR (datos diarios, activa al renovar key)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Obtiene la matriz LME de Metal Radar para un metal y fecha.
 * Si la fecha no tiene cotización, busca hacia atrás hasta 7 días calendario.
 *
 * Usa HTTP Basic Auth con METALRADAR_EMAIL y METALRADAR_PASSWORD.
 * Si las credenciales no están configuradas se retorna null en silencio.
 * Si el servidor responde 401/403 se loguea advertencia y retorna null.
 *
 * NOTA: verificar el endpoint exacto en la documentación de metalradar.io
 * al renovar el acceso — el path /api/v1/lme/prices puede variar entre planes.
 *
 * @param  string     $lmeKey 'LME-CU' o 'LME-AL'
 * @param  string     $date   'YYYY-MM-DD' — fecha del PDF
 * @return array|null         Matriz con campos cash_buyer … three_months_seller, trade_date, source
 */
function lme_fetch_metalradar_matrix(string $lmeKey, string $date): ?array
{
    static $cache = [];
    $cKey = $lmeKey . '|' . $date;
    if (array_key_exists($cKey, $cache)) return $cache[$cKey];
    $cache[$cKey] = null;

    if (!function_exists('curl_init')) return null;
    if (!defined('METALRADAR_EMAIL')    || (string) METALRADAR_EMAIL    === ''
     || !defined('METALRADAR_PASSWORD') || (string) METALRADAR_PASSWORD === '') {
        return null;   // credenciales no configuradas — silencioso
    }

    $symbol = LME_METALRADAR_SYMBOLS[$lmeKey] ?? null;
    if (!$symbol) return null;

    $ts = strtotime($date);
    for ($i = 0; $i <= 7; $i++) {
        $candidate = date('Y-m-d', $ts - $i * 86400);

        // NOTA: ajustar URL si la documentación de metalradar.io indica otro path
        $url = METALRADAR_API_BASE . '/lme/prices'
             . '?metal=' . rawurlencode($symbol)
             . '&date='  . rawurlencode($candidate);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERPWD,
            rawurlencode((string) METALRADAR_EMAIL) . ':' . rawurlencode((string) METALRADAR_PASSWORD)
        );
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: ValkamSync/1.0 (+https://petit.valkamgm.com)',
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 401 || $code === 403) {
            error_log("LME MetalRadar: key inválida o vencida (HTTP $code) — activar fallback");
            return null;   // key mala: no tiene sentido reintentar con otra fecha
        }
        if ($code === 429) {
            error_log("LME MetalRadar: límite de requests alcanzado (HTTP 429)");
            return null;
        }
        if ($code !== 200 || !$resp) {
            // Puede ser día sin cotización — seguir buscando hacia atrás
            continue;
        }

        $body = json_decode((string) $resp, true);
        if (!is_array($body)) continue;

        // Metal Radar puede responder con distintos niveles de detalle según el plan
        $data = $body['data'] ?? $body;

        // Intentar leer los 4 puntos; si solo hay spot, usarlo como proxy para los demás
        $cb = isset($data['cash_buyer'])   ? (float) $data['cash_buyer']   :
             (isset($data['cash'])         ? (float) $data['cash']         :
             (isset($data['price'])        ? (float) $data['price']        : null));

        if ($cb === null || $cb <= 0) continue;   // fecha sin datos → buscar día anterior

        $cs = isset($data['cash_seller'])  && $data['cash_seller']  > 0 ? (float) $data['cash_seller']  : $cb;
        $tb = isset($data['3m_buyer'])     && $data['3m_buyer']     > 0 ? (float) $data['3m_buyer']     :
             (isset($data['forward'])      && $data['forward']      > 0 ? (float) $data['forward']      : $cb);
        $ts2 = isset($data['3m_seller'])   && $data['3m_seller']    > 0 ? (float) $data['3m_seller']    : $tb;

        $tradeDate = (string) ($data['date'] ?? $candidate);

        $matrix = [
            'cash_buyer'          => $cb,
            'cash_seller'         => $cs,
            'three_months_buyer'  => $tb,
            'three_months_seller' => $ts2,
            'trade_date'          => $tradeDate,
            'source'              => 'metalradar',
        ];

        if ($i > 0) {
            error_log("LME MetalRadar: $lmeKey sin cotización en $date → usando $candidate ($i días atrás)");
        }
        $cache[$cKey] = $matrix;
        return $matrix;
    }

    error_log("LME MetalRadar: $lmeKey sin datos en $date ni en los 7 días anteriores");
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// CAPA DE OBTENCIÓN CON CACHE DB
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Retorna la matriz LME de 4 puntos para un metal y fecha, consultando el cache DB primero.
 *
 * @param  string     $metal 'ALUMINUM', 'COPPER/BRASS', etc.
 * @param  string     $date  'YYYY-MM-DD' — fecha del PDF
 * @return array|null        ['cash_buyer', 'cash_seller', 'three_months_buyer',
 *                            'three_months_seller', 'trade_date', 'source']  |  null
 */
function lme_get_matrix(PDO $pdo, string $metal, string $date): ?array
{
    $lmeKey = LME_METAL_MAP[strtoupper($metal)] ?? null;
    if (!$lmeKey) return null;

    // 1. Cache DB — intentar leer la matriz de 4 puntos guardada previamente
    try {
        $stmt = $pdo->prepare(
            "SELECT cash_buyer, cash_seller, three_months_buyer, three_months_seller,
                    cash_usd, trade_date, source
             FROM vsync_lme_cache
             WHERE metal = ? AND price_date = ?
             LIMIT 1"
        );
        $stmt->execute([$lmeKey, $date]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cached) {
            $cb = isset($cached['cash_buyer']) ? (float) $cached['cash_buyer'] : 0.0;

            if ($cb > 0) {
                // Cache con la matriz completa de 4 puntos (formato nuevo)
                return [
                    'cash_buyer'          => $cb,
                    'cash_seller'         => (float) ($cached['cash_seller']         ?? $cb),
                    'three_months_buyer'  => (float) ($cached['three_months_buyer']  ?? $cb),
                    'three_months_seller' => (float) ($cached['three_months_seller'] ?? $cb),
                    'trade_date'          => (string) ($cached['trade_date']         ?? $date),
                    'source'              => (string) ($cached['source']             ?? 'cache'),
                ];
            }

            // Cache legacy (solo cash_usd) — construir matriz simétrica de 1 punto
            $legacy = isset($cached['cash_usd']) ? (float) $cached['cash_usd'] : 0.0;
            if ($legacy > 0) {
                return [
                    'cash_buyer'          => $legacy,
                    'cash_seller'         => $legacy,
                    'three_months_buyer'  => $legacy,
                    'three_months_seller' => $legacy,
                    'trade_date'          => $date,
                    'source'              => 'av_legacy',
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('LME cache read: ' . $e->getMessage());
    }

    // ── Cascada de fuentes externas (P1 → P2 → P3) ──────────────────────────

    // 2. Alpha Vantage — P1 (promedio mensual IMF; probada en prod 26/26 fórmulas)
    $avPrice = lme_fetch_alphavantage($lmeKey, $date);
    if ($avPrice !== null && $avPrice > 0) {
        $matrix = [
            'cash_buyer'          => $avPrice,
            'cash_seller'         => $avPrice,
            'three_months_buyer'  => $avPrice,
            'three_months_seller' => $avPrice,
            'trade_date'          => $date,
            'source'              => 'alphavantage',
        ];
        _lme_cache_save($pdo, $lmeKey, $date, $matrix);
        return $matrix;
    }

    // 3. Metal Radar — P2 (datos diarios; activar cuando se renueve METALRADAR_API_KEY)
    $matrix = lme_fetch_metalradar_matrix($lmeKey, $date);
    if ($matrix !== null) {
        _lme_cache_save($pdo, $lmeKey, $date, $matrix);
        return $matrix;
    }

    // 4. NASDAQ Data Link — P3 (último recurso; datos diarios 4 puntos)
    $matrix = lme_fetch_nasdaq_matrix($lmeKey, $date);
    if ($matrix !== null) {
        _lme_cache_save($pdo, $lmeKey, $date, $matrix);
        return $matrix;
    }

    return null;
}

/**
 * Alias backward-compatible: retorna solo el Cash Buyer para código heredado.
 */
function lme_get_spot(PDO $pdo, string $metal, string $date): ?float
{
    $matrix = lme_get_matrix($pdo, $metal, $date);
    return $matrix ? $matrix['cash_buyer'] : null;
}

/**
 * Persiste la matriz LME en vsync_lme_cache (INSERT OR REPLACE).
 */
function _lme_cache_save(PDO $pdo, string $lmeKey, string $priceDate, array $matrix): void
{
    try {
        $driver    = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tradeDate = $matrix['trade_date'] ?? $priceDate;
        $source    = $matrix['source']     ?? 'unknown';

        $vals = [
            $lmeKey,
            $priceDate,
            $matrix['cash_buyer'],             // cash_usd backward compat
            $matrix['cash_buyer'],
            $matrix['cash_seller'],
            $matrix['three_months_buyer'],
            $matrix['three_months_seller'],
            $tradeDate,
            $source,
        ];

        if ($driver === 'sqlite') {
            $pdo->prepare(
                "INSERT OR REPLACE INTO vsync_lme_cache
                    (metal, price_date, cash_usd, cash_buyer, cash_seller,
                     three_months_buyer, three_months_seller, trade_date, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute($vals);
        } else {
            $pdo->prepare(
                "INSERT INTO vsync_lme_cache
                    (metal, price_date, cash_usd, cash_buyer, cash_seller,
                     three_months_buyer, three_months_seller, trade_date, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    cash_usd             = VALUES(cash_usd),
                    cash_buyer           = VALUES(cash_buyer),
                    cash_seller          = VALUES(cash_seller),
                    three_months_buyer   = VALUES(three_months_buyer),
                    three_months_seller  = VALUES(three_months_seller),
                    trade_date           = VALUES(trade_date),
                    source               = VALUES(source),
                    fetched_at           = NOW()"
            )->execute($vals);
        }
    } catch (Throwable $e) {
        error_log('LME cache save: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// LÓGICA DE NEGOCIO: MÍNIMO DE LA MATRIZ
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Determina cuál de los 4 puntos de la matriz LME es el MENOR absoluto.
 *
 * Criterio: se usa el menor para calcular el precio de compra más conservador
 * posible para Valkam Capital al negociar chatarra con fórmulas LME.
 *
 * En caso de empate numérico exacto, la prioridad es:
 *   CASH_BUYER → CASH_SELLER → THREE_MONTHS_BUYER → THREE_MONTHS_SELLER
 * (preferencia por contado sobre a plazo, y por posición compradora sobre vendedora).
 *
 * @return array{value: float, type: string, label: string}
 *   value → valor numérico mínimo (USD/MT)
 *   type  → clave de auditoría ('CASH_BUYER', 'CASH_SELLER', etc.)
 *   label → texto legible para reportes ('Cash Buyer', '3 Months Seller', etc.)
 */
function lme_find_minimum_point(array $matrix): array
{
    // Orden define prioridad en empates: primero contado, luego plazo; compra antes que venta
    $candidates = [
        'CASH_BUYER'          => (float) $matrix['cash_buyer'],
        'CASH_SELLER'         => (float) $matrix['cash_seller'],
        'THREE_MONTHS_BUYER'  => (float) $matrix['three_months_buyer'],
        'THREE_MONTHS_SELLER' => (float) $matrix['three_months_seller'],
    ];

    $minType  = 'CASH_BUYER';
    $minValue = PHP_FLOAT_MAX;

    foreach ($candidates as $type => $value) {
        if ($value < $minValue) {
            $minValue = $value;
            $minType  = $type;
        }
    }

    return [
        'value' => $minValue,
        'type'  => $minType,
        'label' => LME_MATRIX_LABELS[$minType],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// RESOLUCIÓN DE FÓRMULAS EN DB
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resuelve precios fórmula LME para un archivo recién importado.
 * Se ejecuta post-import, fuera de transacción — best-effort, no bloquea el import.
 *
 * Por cada fila con lme_resolved = 0 y price_raw que sea fórmula LME:
 *   OK  → price_num = mínimo × pct/100, lme_resolved = 1, auditoría completa guardada.
 *   ERR → lme_resolved = -1, lme_error = descripción del fallo (API no disponible,
 *          día no hábil sin lookback disponible, etc.). No bloquea el import.
 *
 * @return array{resolved: int, skipped: int, errors: int}
 */
function lme_resolve_formula_prices(PDO $pdo, int $fileId, string $fileDate): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id, category, price_raw
             FROM vsync_peony_prices
             WHERE file_id = ? AND lme_resolved = 0"
        );
        $stmt->execute([$fileId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LME resolve fetch: ' . $e->getMessage());
        return ['resolved' => 0, 'skipped' => 0, 'errors' => 0];
    }

    $resolved    = 0;
    $skipped     = 0;
    $errors      = 0;
    $matrixCache = [];   // [cat|date => array|null] — evita llamadas duplicadas a la API

    // UPDATE para resolución exitosa (precio calculado + auditoría completa)
    $updOk = $pdo->prepare(
        "UPDATE vsync_peony_prices SET
            price_num              = ?,
            lme_resolved           = 1,
            lme_price              = ?,
            lme_cash_buyer         = ?,
            lme_cash_seller        = ?,
            lme_3_months_buyer     = ?,
            lme_3_months_seller    = ?,
            lme_percentage_applied = ?,
            lme_base_price_used    = ?,
            lme_type_used          = ?,
            lme_error              = NULL
         WHERE id = ?"
    );

    // UPDATE para error (no detiene el import; lme_resolved = -1 marca como "falló, no reintentar")
    $updErr = $pdo->prepare(
        "UPDATE vsync_peony_prices
         SET lme_resolved = -1, lme_error = ?
         WHERE id = ?"
    );

    foreach ($rows as $r) {
        $formula = lme_parse_formula((string) $r['price_raw']);
        if (!$formula) continue;   // no es fórmula LME → dejar intacto (row_status lo clasifica)

        $cat = strtoupper((string) ($r['category'] ?? ''));
        if (!isset(LME_METAL_MAP[$cat])) { $skipped++; continue; }

        $cacheKey = $cat . '|' . $fileDate;
        if (!array_key_exists($cacheKey, $matrixCache)) {
            $matrixCache[$cacheKey] = lme_get_matrix($pdo, $cat, $fileDate);
        }
        $matrix = $matrixCache[$cacheKey];

        if ($matrix === null) {
            $errMsg = "Sin datos LME para $cat en $fileDate (todas las fuentes agotadas: AV, MetalRadar, NASDAQ)";
            error_log("LME resolve: row={$r['id']} $errMsg");
            try { $updErr->execute([$errMsg, (int) $r['id']]); } catch (Throwable $e) {}
            $errors++;
            continue;
        }

        $min        = lme_find_minimum_point($matrix);
        $calculated = round(($formula['pct'] / 100.0) * $min['value'], 4);

        try {
            $updOk->execute([
                $calculated,                         // price_num — precio final
                $min['value'],                       // lme_price — backward compat
                $matrix['cash_buyer'],               // lme_cash_buyer
                $matrix['cash_seller'],              // lme_cash_seller
                $matrix['three_months_buyer'],       // lme_3_months_buyer
                $matrix['three_months_seller'],      // lme_3_months_seller
                $formula['pct'],                     // lme_percentage_applied
                $min['value'],                       // lme_base_price_used
                $min['type'],                        // lme_type_used
                (int) $r['id'],
            ]);
            $resolved++;
        } catch (Throwable $e) {
            error_log('LME resolve update: ' . $e->getMessage());
            $errors++;
        }
    }

    if ($resolved + $skipped + $errors > 0) {
        error_log("LME resolve: file=$fileId date=$fileDate resolved=$resolved skipped=$skipped errors=$errors");
    }

    return ['resolved' => $resolved, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Re-resuelve TODOS los precios fórmula pendientes (lme_resolved = 0) en toda la DB.
 * No toca filas ya resueltas (= 1) ni las marcadas con error permanente (= -1).
 * Agrupa por metal+fecha para minimizar llamadas a la API.
 *
 * Útil para re-procesar el histórico tras configurar NASDAQ_API_KEY por primera vez,
 * o para actualizar rows que antes fallaron con Alpha Vantage y ahora tienen
 * NASDAQ disponible (requiere resetear lme_resolved a 0 manualmente).
 *
 * @return array{files: int, resolved: int, skipped: int, errors: int}
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
        return ['files' => 0, 'resolved' => 0, 'skipped' => 0, 'errors' => 0];
    }

    $resolved    = 0;
    $skipped     = 0;
    $errors      = 0;
    $matrixCache = [];
    $fileDates   = [];

    $updOk = $pdo->prepare(
        "UPDATE vsync_peony_prices SET
            price_num              = ?,
            lme_resolved           = 1,
            lme_price              = ?,
            lme_cash_buyer         = ?,
            lme_cash_seller        = ?,
            lme_3_months_buyer     = ?,
            lme_3_months_seller    = ?,
            lme_percentage_applied = ?,
            lme_base_price_used    = ?,
            lme_type_used          = ?,
            lme_error              = NULL
         WHERE id = ?"
    );

    $updErr = $pdo->prepare(
        "UPDATE vsync_peony_prices SET lme_resolved = -1, lme_error = ? WHERE id = ?"
    );

    foreach ($rows as $r) {
        $formula = lme_parse_formula((string) $r['price_raw']);
        if (!$formula) continue;

        $cat  = strtoupper((string) ($r['category'] ?? ''));
        $date = (string) $r['file_date'];
        if (!isset(LME_METAL_MAP[$cat])) { $skipped++; continue; }

        $cacheKey = $cat . '|' . $date;
        if (!array_key_exists($cacheKey, $matrixCache)) {
            $matrixCache[$cacheKey] = lme_get_matrix($pdo, $cat, $date);
        }
        $matrix = $matrixCache[$cacheKey];

        if ($matrix === null) {
            $errMsg = "Sin datos LME para $cat en $date";
            error_log("LME resolve_all: row={$r['id']} $errMsg");
            try { $updErr->execute([$errMsg, (int) $r['id']]); } catch (Throwable $e) {}
            $errors++;
            continue;
        }

        $min        = lme_find_minimum_point($matrix);
        $calculated = round(($formula['pct'] / 100.0) * $min['value'], 4);

        try {
            $updOk->execute([
                $calculated,
                $min['value'],
                $matrix['cash_buyer'],
                $matrix['cash_seller'],
                $matrix['three_months_buyer'],
                $matrix['three_months_seller'],
                $formula['pct'],
                $min['value'],
                $min['type'],
                (int) $r['id'],
            ]);
            $resolved++;
            $fileDates[$date] = true;
        } catch (Throwable $e) {
            error_log('LME resolve_all update: ' . $e->getMessage());
            $errors++;
        }
    }

    error_log(
        "LME resolve_all: files=" . count($fileDates)
        . " resolved=$resolved skipped=$skipped errors=$errors"
    );
    return [
        'files'    => count($fileDates),
        'resolved' => $resolved,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ];
}
