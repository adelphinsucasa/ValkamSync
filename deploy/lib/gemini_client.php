<?php
declare(strict_types=1);

/**
 * Cliente mínimo para Google Gemini API (vision).
 * API key SOLO server-side (nunca se filtra al cliente).
 *
 * Si GEMINI_API_KEY no está definida, isAvailable() === false y el endpoint
 * degrada a modo manual (UI permite completar los campos a mano).
 */
final class GeminiClient
{
    private string $apiKey;
    private string $model;
    private int    $timeout;
    /** @var string[]  Cadena de API keys: primaria + fallback (GEMINI_API_KEY_2) */
    private array  $apiKeys = [];

    // Cadena de fallback de modelos: si el modelo principal no existe/no soporta generateContent,
    // intenta automáticamente el siguiente. gemini-1.5-* fue deprecado por Google en 2025.
    private const MODEL_FALLBACKS = [
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-2.5-flash-lite',
        'gemini-flash-latest',
    ];

    public function __construct(?string $apiKey = null, ?string $model = null, int $timeout = 90)
    {
        $k1 = $apiKey ?? (defined('GEMINI_API_KEY')   ? (string) GEMINI_API_KEY   : '');
        $k2 =            defined('GEMINI_API_KEY_2')  ? (string) GEMINI_API_KEY_2 : '';
        // Deduplica y filtra vacíos — mantiene el orden: primaria → fallback
        $this->apiKeys = array_values(array_filter(array_unique([$k1, $k2])));
        $this->apiKey  = $this->apiKeys[0] ?? '';
        $this->model   = $model ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash');
        // Timeout 90s total — permite que HostGator devuelva respuesta antes de 504 del gateway (~120s)
        $this->timeout = $timeout;
    }

    public function isAvailable(): bool { return $this->apiKey !== ''; }

    /** Error de sobrecarga/cuota que justifica intentar la siguiente key */
    private function isOverloaded(string $msg): bool
    {
        return stripos($msg, 'gemini_http_503') !== false
            || stripos($msg, 'gemini_http_429') !== false
            || stripos($msg, 'gemini_http_500') !== false;
    }

    /** Error de modelo deprecado/no disponible que justifica intentar el siguiente modelo */
    private function isDeprecatedModel(string $msg): bool
    {
        return stripos($msg, 'not found')       !== false
            || stripos($msg, 'not supported')   !== false
            || stripos($msg, 'gemini_http_404') !== false
            || (stripos($msg, 'gemini_http_400') !== false && stripos($msg, 'model') !== false);
    }

    /**
     * Extrae campos de cliente desde imagen (base64) + mime.
     *
     * Estrategia de reintentos en dos niveles:
     *   1. Modelo deprecado (404/400) → siguiente modelo, misma key.
     *   2. Sobrecarga/cuota (503/429/500) → misma posición de modelo, siguiente key.
     *
     * @return array{phone:?string, company:?string, buyer:?string, material:?string, price_raw:?string, scan_date:?string, notes:?string, confidence:?string}
     */
    public function extractClientFields(string $imageBase64, string $mime): array
    {
        if (!$this->isAvailable()) throw new RuntimeException('gemini_unavailable');

        $models = [$this->model];
        foreach (self::MODEL_FALLBACKS as $m) {
            if ($m !== $this->model) $models[] = $m;
        }

        $lastError = null;
        foreach ($this->apiKeys as $kIdx => $key) {
            foreach ($models as $model) {
                try {
                    return $this->callModel($model, $key, $imageBase64, $mime);
                } catch (RuntimeException $e) {
                    $lastError = $e;
                    $msg       = $e->getMessage();

                    if ($this->isDeprecatedModel($msg) && $model !== end($models)) {
                        if (function_exists('vs_log')) vs_log('[gemini] model "' . $model . '" no disponible → siguiente modelo');
                        continue;
                    }
                    if ($this->isOverloaded($msg) && isset($this->apiKeys[$kIdx + 1])) {
                        if (function_exists('vs_log')) vs_log('[gemini] 503/429 key#' . ($kIdx + 1) . ' → fallback key#' . ($kIdx + 2));
                        break; // abandona modelos, salta a siguiente key
                    }
                    throw $e;
                }
            }
        }
        throw $lastError ?: new RuntimeException('gemini_all_keys_exhausted');
    }

    /**
     * Extrae TODAS las filas de precios de un PDF de PeonyInc (fallback de pdftotext).
     * Se usa cuando el servidor no tiene pdftotext ni poppler instalados (Hostgator shared).
     * Gemini 2.5 acepta PDFs nativamente vía inline_data.
     *
     * Estrategia de reintentos en dos niveles:
     *   1. Modelo deprecado (404) → siguiente modelo, misma key.
     *   2. Sobrecarga/cuota (503/429/500) → misma posición de modelo, siguiente key.
     *
     * @return array{rows: array<int,array<string,?string>>, backend: string, warnings: array}
     */
    public function extractPeonyPdf(string $pdfBase64): array
    {
        if (!$this->isAvailable()) throw new RuntimeException('gemini_unavailable');

        $models = [$this->model];
        foreach (self::MODEL_FALLBACKS as $m) {
            if ($m !== $this->model) $models[] = $m;
        }

        $lastError = null;
        foreach ($this->apiKeys as $kIdx => $key) {
            foreach ($models as $model) {
                try {
                    return $this->callPdfModel($model, $key, $pdfBase64);
                } catch (RuntimeException $e) {
                    $lastError = $e;
                    $msg       = $e->getMessage();

                    if ($this->isDeprecatedModel($msg) && $model !== end($models)) {
                        if (function_exists('vs_log')) vs_log('[gemini-pdf] model "' . $model . '" no disponible → siguiente modelo');
                        continue;
                    }
                    if ($this->isOverloaded($msg) && isset($this->apiKeys[$kIdx + 1])) {
                        if (function_exists('vs_log')) vs_log('[gemini-pdf] 503/429 key#' . ($kIdx + 1) . ' → fallback key#' . ($kIdx + 2));
                        break; // abandona modelos, salta a siguiente key
                    }
                    throw $e;
                }
            }
        }
        throw $lastError ?: new RuntimeException('gemini_all_keys_exhausted');
    }

    private function callPdfModel(string $model, string $key, string $pdfBase64): array
    {
        $prompt = "Este PDF es una lista de precios de compra de chatarra metálica de PeonyInc. "
            . "Tiene 3 secciones: ALUMINUM, COPPER/BRASS, SS/HITEMP/OTHER. "
            . "Cada fila tiene columnas: material, precio, base de entrega (delivery basis), empresa, contacto (buyer), teléfono.\n\n"
            . "Extrae TODAS las filas de las 3 secciones y retorna EXCLUSIVAMENTE JSON válido:\n"
            . "{\n"
            . '  "rows": [' . "\n"
            . '    {"section":"ALUMINUM","material":"356 Auto Wheel-Dirty","price_raw":"1.40","delivery_basis":"Huntington IN","company":"Transmetco","buyer":"Ryan Gledhill","phone":"260-224-5817"}' . "\n"
            . "  ]\n"
            . "}\n\n"
            . "Reglas:\n"
            . '- Incluye TODAS las filas de las 3 secciones (suelen ser ~70 filas en total).' . "\n"
            . '- "section" DEBE ser exactamente uno de: ALUMINUM, COPPER/BRASS, SS/HITEMP/OTHER.' . "\n"
            . '- Preserva "price_raw" tal cual aparece (ej "0.10-0.60", "80%LMESpot", "500/KG", "Cmx May-85").' . "\n"
            . '- Preserva "phone" con su formato original (ej "260-224-5817", "+971-504833176").' . "\n"
            . '- Si un campo no se lee claramente, usa null (no inventes datos).' . "\n"
            . '- Retorna SOLO el JSON, sin markdown, sin ```.';

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfBase64]],
                ],
            ]],
            'generationConfig' => [
                'temperature'        => 0.05,
                'maxOutputTokens'    => 8192,  // ~70 filas × ~110 tokens/fila + overhead
                'response_mime_type' => 'application/json',
                'thinkingConfig'     => ['thinkingBudget' => 0],
            ],
        ];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model), rawurlencode($key)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,  // PDFs pueden tardar más
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOSIGNAL       => true,
        ]);
        $tStart   = microtime(true);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if (function_exists('vs_log')) {
            vs_log(sprintf('[gemini-pdf] %s  %.2fs  http=%d%s',
                $model, microtime(true) - $tStart, $httpCode,
                $err !== '' ? '  err=' . $err : ''
            ));
        }

        if ($resp === false) throw new RuntimeException("gemini_curl: $err");
        if ($httpCode !== 200) {
            $parsed = json_decode((string) $resp, true);
            $apiMsg = $parsed['error']['message'] ?? 'unknown';
            throw new RuntimeException("gemini_http_{$httpCode}: $apiMsg");
        }

        $parsed = json_decode((string) $resp, true);
        $text   = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $parsed['candidates'][0]['finishReason'] ?? '';

        $data = json_decode($text, true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
            $data = json_decode((string) $stripped, true);
            if (!is_array($data) || !isset($data['rows'])) {
                if ($finishReason === 'MAX_TOKENS' || !str_ends_with(rtrim($text), '}')) {
                    throw new RuntimeException('gemini_pdf_truncated: respuesta cortada. Reduce imagen o aumenta maxOutputTokens.');
                }
                throw new RuntimeException('gemini_pdf_bad_json: ' . substr($text, 0, 200));
            }
        }

        // Normaliza filas al esquema esperado por PeonyParser
        $rows = [];
        foreach ($data['rows'] as $r) {
            if (!is_array($r) || empty($r['material']) || empty($r['price_raw'])) continue;
            $section = strtoupper((string) ($r['section'] ?? ''));
            if (!in_array($section, ['ALUMINUM', 'COPPER/BRASS', 'SS/HITEMP/OTHER'], true)) continue;
            $rows[] = [
                'section'        => $section,
                'material'       => trim((string) $r['material']),
                'price_raw'      => trim((string) $r['price_raw']),
                'delivery_basis' => isset($r['delivery_basis']) ? trim((string) $r['delivery_basis']) : '',
                'company'        => isset($r['company'])        ? trim((string) $r['company'])        : '',
                'buyer'          => isset($r['buyer'])          ? trim((string) $r['buyer'])          : '',
                'phone'          => isset($r['phone'])          ? preg_replace('/\s+/', '', (string) $r['phone']) : '',
                'row_status'     => 'ok',
            ];
        }

        return ['rows' => $rows, 'backend' => 'gemini-pdf', 'warnings' => []];
    }

    private function callModel(string $model, string $key, string $imageBase64, string $mime): array
    {
        $prompt = "Eres un asistente de OCR para ValkamSync, analizando fotos de tarjetas de presentación, "
            . "agendas físicas o notas de clientes del sector de reciclaje de metales.\n\n"
            . "Extrae los campos identificables y retorna EXCLUSIVAMENTE JSON válido:\n"
            . "{\n"
            . "  \"phone\": \"teléfono tal como aparece (con código país si se ve)\",\n"
            . "  \"company\": \"nombre de empresa\",\n"
            . "  \"buyer\": \"nombre de persona de contacto\",\n"
            . "  \"material\": \"material mencionado o null\",\n"
            . "  \"price_raw\": \"precio tal como aparece (ej 1.40, 0.72/Al) o null\",\n"
            . "  \"scan_date\": \"fecha escrita en formato YYYY-MM-DD o null\",\n"
            . "  \"notes\": \"otra info relevante (dirección, email, etc.) o null\",\n"
            . "  \"confidence\": \"alta | media | baja\"\n"
            . "}\n\n"
            . "Reglas: usa null (no string vacío) si no se puede determinar. No inventes datos. "
            . "Retorna SOLO el JSON, sin markdown, sin ```.";

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $imageBase64]],
                ],
            ]],
            'generationConfig' => [
                'temperature'        => 0.1,
                // 2048 tokens cubre el JSON completo con notas largas; 800 truncaba con modelos 2.5
                'maxOutputTokens'    => 2048,
                'response_mime_type' => 'application/json',
                // Gemini 2.5 flash usa "thinking tokens" por default que se comen el presupuesto.
                // Para OCR no necesitamos razonamiento → desactivar thinking.
                // Ignorado silenciosamente por modelos <2.5.
                'thinkingConfig'     => ['thinkingBudget' => 0],
            ],
        ];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode($key)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOSIGNAL       => true,   // evita bloqueos con alarm() en shared hosting
        ]);
        $tStart   = microtime(true);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        $dlSize   = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        if (function_exists('vs_log')) {
            vs_log(sprintf('[gemini] curl  %.2fs  http=%d  dl=%dB%s',
                microtime(true) - $tStart, $httpCode, (int) $dlSize,
                $err !== '' ? '  err=' . $err : ''
            ));
        }

        if ($resp === false)         throw new RuntimeException("gemini_curl: $err");
        if ($httpCode !== 200) {
            $parsed = json_decode((string) $resp, true);
            $msg = $parsed['error']['message'] ?? 'unknown';
            throw new RuntimeException("gemini_http_$httpCode: $msg");
        }

        $parsed = json_decode((string) $resp, true);
        $text   = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $parsed['candidates'][0]['finishReason'] ?? '';

        $fields = json_decode($text, true);
        if (!is_array($fields)) {
            $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
            $fields = json_decode((string) $stripped, true);
            if (!is_array($fields)) {
                // Detección específica: respuesta truncada por MAX_TOKENS
                if ($finishReason === 'MAX_TOKENS' || !str_ends_with(rtrim($text), '}')) {
                    throw new RuntimeException('gemini_truncated: respuesta cortada a medio JSON. Aumentar maxOutputTokens o reducir tamaño de imagen.');
                }
                throw new RuntimeException('gemini_bad_json: ' . substr($text, 0, 120));
            }
        }

        return [
            'phone'      => isset($fields['phone'])     ? trim((string) $fields['phone'])     : null,
            'company'    => isset($fields['company'])   ? trim((string) $fields['company'])   : null,
            'buyer'      => isset($fields['buyer'])     ? trim((string) $fields['buyer'])     : null,
            'material'   => isset($fields['material'])  ? trim((string) $fields['material'])  : null,
            'price_raw'  => isset($fields['price_raw']) ? trim((string) $fields['price_raw']) : null,
            'scan_date'  => isset($fields['scan_date']) ? trim((string) $fields['scan_date']) : null,
            'notes'      => isset($fields['notes'])     ? trim((string) $fields['notes'])     : null,
            'confidence' => isset($fields['confidence'])? trim((string) $fields['confidence']): null,
        ];
    }
}

/**
 * Valida, reencoda y redimensiona una imagen subida (master prompt §7).
 * Devuelve binary JPEG listo para pasar a Gemini.
 */
function safe_reencode_image(string $tmpPath, int $maxDim = 1600, int $quality = 85): array
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('bad_mime');
    }
    $info = @getimagesize($tmpPath);
    if ($info === false) throw new RuntimeException('invalid_image');
    [$w, $h, $type] = $info;
    if ($w <= 0 || $h <= 0) throw new RuntimeException('invalid_dimensions');

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null,
        default        => null,
    };
    if (!$src) throw new RuntimeException('decode_failed');

    // EXIF orientation (solo JPEG)
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        $orient = (int) ($exif['Orientation'] ?? 1);
        $rotation = match ($orient) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($rotation !== 0) {
            $rotated = imagerotate($src, $rotation, 0);
            imagedestroy($src);
            $src = $rotated;
            $w = imagesx($src); $h = imagesy($src);
        }
    }

    // Resize
    $ratio = max($w, $h) / $maxDim;
    if ($ratio > 1) {
        $nw = (int) round($w / $ratio);
        $nh = (int) round($h / $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    ob_start();
    imagejpeg($src, null, $quality);
    $binary = ob_get_clean() ?: '';
    imagedestroy($src);

    if ($binary === '') throw new RuntimeException('encode_failed');

    return ['binary' => $binary, 'mime' => 'image/jpeg', 'size' => strlen($binary)];
}

/**
 * Normaliza teléfono a dígitos (+ leading opcional).
 */
function phone_normalize(string $p): string
{
    $p = trim($p);
    if ($p === '') return '';
    $leading = str_starts_with($p, '+') ? '+' : '';
    return $leading . preg_replace('/\D+/', '', $p);
}
