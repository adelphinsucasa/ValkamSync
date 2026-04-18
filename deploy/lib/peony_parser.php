<?php
declare(strict_types=1);

/**
 * PeonyParser — parser de producción para PDFs de precios PeonyInc.
 *
 * Estrategia: dos backends con auto-detección.
 *   1) Primario: pdftotext -bbox-layout (poppler-utils) → HTML con coordenadas
 *      exactas por palabra. Agrupamos por Y (misma fila) y asignamos a columnas
 *      según el X-inicio aprendido de la cabecera. Inmune al kerning.
 *   2) Fallback: pdftotext -lineprinter + normalización de kerning heurística.
 *      Cubre el caso HostGator shared donde poppler no esté disponible.
 *
 * Requisitos del servidor: pdftotext en PATH (o PDFTOTEXT_BIN en config).
 */
final class PeonyParser
{
    public const VERSION = '1.0.0';

    private const CATEGORIES = ['ALUMINUM', 'COPPER/BRASS', 'SS/HITEMP/OTHER'];
    private const ROW_Y_TOLERANCE = 3.0;      // px tolerance to group words into a row
    private const COL_SNAP_OFFSET = 2.0;      // allow small drift when snapping to column boundaries

    private string $pdftotextBin;
    public ?string $lastBackend = null;       // 'bbox' | 'lineprinter'

    public function __construct(?string $pdftotextBin = null)
    {
        $this->pdftotextBin = $pdftotextBin
            ?? (defined('PDFTOTEXT_BIN') ? PDFTOTEXT_BIN : 'pdftotext');
    }

    /**
     * @return array{rows: array<int,array<string,string>>, backend: string, warnings: array<int,string>}
     *
     * Cadena de backends:
     *   1. pdftotext -bbox-layout (poppler, precisión por coordenadas)
     *   2. pdftotext -lineprinter (xpdf, columnas fijas)
     *   3. Gemini PDF (IA, cuando el servidor no tiene pdftotext — ej Hostgator)
     */
    public function parse(string $pdfPath): array
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException("PDF not readable: $pdfPath");
        }

        $warnings = [];

        // Try bbox-layout (poppler-utils)
        $bbox = $this->runPdftotext($pdfPath, '-bbox-layout');
        if ($bbox !== null && stripos($bbox, '<word') !== false) {
            $this->lastBackend = 'bbox';
            $rows = $this->parseByBbox($bbox, $warnings);
            return ['rows' => $rows, 'backend' => 'bbox', 'warnings' => $warnings];
        }

        // Fallback 1: lineprinter + kerning normalization
        $text = $this->runPdftotext($pdfPath, '-lineprinter');
        if ($text !== null) {
            $this->lastBackend = 'lineprinter';
            $warnings[] = 'Usando backend lineprinter (poppler no detectado).';
            $rows = $this->parseByLines($text);
            return ['rows' => $rows, 'backend' => 'lineprinter', 'warnings' => $warnings];
        }

        // Fallback 2: Gemini PDF (cuando pdftotext no está disponible)
        if (class_exists('GeminiClient')) {
            try {
                $ai = new GeminiClient();
                if ($ai->isAvailable()) {
                    if (function_exists('vs_log')) vs_log('[PeonyParser] pdftotext unavailable → Gemini PDF fallback');
                    $pdfData = file_get_contents($pdfPath);
                    if ($pdfData !== false) {
                        $result = $ai->extractPeonyPdf(base64_encode($pdfData));
                        $this->lastBackend = 'gemini-pdf';
                        $result['warnings'] = array_merge(
                            $warnings,
                            ['Backend: Gemini (pdftotext no disponible en servidor).']
                        );
                        return $result;
                    }
                }
            } catch (Throwable $e) {
                if (function_exists('vs_log')) vs_log('[PeonyParser] gemini fallback failed: ' . $e->getMessage());
                throw new RuntimeException(
                    'pdftotext unavailable y Gemini PDF falló: ' . $e->getMessage() .
                    '. Configura GEMINI_API_KEY en .app_config.php.'
                );
            }
        }

        throw new RuntimeException(
            'Ningún backend de PDF disponible. Instala pdftotext o configura GEMINI_API_KEY. Archivo: ' .
            basename($pdfPath)
        );
    }

    /** Runs pdftotext; returns stdout string or null on failure. */
    private function runPdftotext(string $pdfPath, string $mode): ?string
    {
        $cmd = escapeshellcmd($this->pdftotextBin)
            . ' ' . $mode . ' '
            . escapeshellarg($pdfPath) . ' - 2>/dev/null';
        $out = @shell_exec($cmd);
        if ($out === null || $out === false || $out === '') return null;
        return $out;
    }

    // ======================================================================
    // Backend 1 — bbox-layout (coordenadas exactas)
    // ======================================================================
    private function parseByBbox(string $html, array &$warnings): array
    {
        if (!preg_match_all(
            '/<word\s+xMin="([^"]+)"\s+yMin="([^"]+)"\s+xMax="([^"]+)"\s+yMax="([^"]+)">([^<]*)<\/word>/i',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $words = [];
        foreach ($m as $w) {
            $t = html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = trim($t);
            if ($t === '') continue;
            $words[] = [
                'x'  => (float) $w[1],
                'y'  => (float) $w[2],
                'x2' => (float) $w[3],
                'y2' => (float) $w[4],
                't'  => $t,
            ];
        }

        // Sort by Y, then X
        usort($words, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

        // Group words into rows by Y proximity
        $rowsRaw = [];
        $cur = [];
        $curY = null;
        foreach ($words as $w) {
            if ($curY === null || abs($w['y'] - $curY) <= self::ROW_Y_TOLERANCE) {
                $cur[] = $w;
                if ($curY === null) $curY = $w['y'];
            } else {
                usort($cur, fn($a, $b) => $a['x'] <=> $b['x']);
                $rowsRaw[] = $cur;
                $cur = [$w];
                $curY = $w['y'];
            }
        }
        if ($cur) {
            usort($cur, fn($a, $b) => $a['x'] <=> $b['x']);
            $rowsRaw[] = $cur;
        }

        // Walk rows: detect section headers to learn column X-starts.
        $parsed = [];
        $section = null;
        $colX = null;

        foreach ($rowsRaw as $row) {
            $joined = implode(' ', array_column($row, 't'));
            $joinedU = strtoupper($joined);

            foreach (self::CATEGORIES as $cat) {
                $catU = strtoupper($cat);
                if (stripos($joinedU, $catU) === 0
                    && strpos($joinedU, 'PRICE') !== false
                    && strpos($joinedU, 'DELIVERY') !== false) {
                    $section = $cat;
                    $colX = $this->learnColumns($row);
                    continue 2;
                }
            }
            if (stripos($joined, 'Copyright') !== false) continue;
            if (stripos($joined, 'PEONY,') === 0) continue;
            if ($section === null || $colX === null) continue;

            $rowData = $this->assignToColumns($row, $colX);
            if ($rowData === null) continue;
            $rowData['section'] = $section;
            $rowData['row_status'] = $this->validateRow($rowData) ? 'ok' : 'anomaly';
            $parsed[] = $rowData;
        }

        return $parsed;
    }

    /** Learn X-starts of PRICE, DELIVERY, COMPANY, BUYER, TELEPHONE from header row. */
    private function learnColumns(array $headerRow): array
    {
        $cols = [];
        foreach ($headerRow as $w) {
            $u = strtoupper($w['t']);
            if ($u === 'PRICE')                              $cols['price']    = $w['x'];
            elseif ($u === 'DELIVERY')                       $cols['delivery'] = $w['x'];
            elseif ($u === 'COMPANY')                        $cols['company']  = $w['x'];
            elseif ($u === "BUYER'S" || $u === 'BUYER')      $cols['buyer']    = $w['x'];
            elseif ($u === 'TELEPHONE')                      $cols['phone']    = $w['x'];
        }
        return $cols;
    }

    private function assignToColumns(array $row, array $colX): ?array
    {
        $off = self::COL_SNAP_OFFSET;
        $bucket = ['material'=>[], 'price'=>[], 'delivery'=>[], 'company'=>[], 'buyer'=>[], 'phone'=>[]];

        foreach ($row as $w) {
            $x = $w['x'];
            if     (isset($colX['price'])    && $x < $colX['price']    - $off) $bucket['material'][] = $w['t'];
            elseif (isset($colX['delivery']) && $x < $colX['delivery'] - $off) $bucket['price'][]    = $w['t'];
            elseif (isset($colX['company'])  && $x < $colX['company']  - $off) $bucket['delivery'][] = $w['t'];
            elseif (isset($colX['buyer'])    && $x < $colX['buyer']    - $off) $bucket['company'][]  = $w['t'];
            elseif (isset($colX['phone'])    && $x < $colX['phone']    - $off) $bucket['buyer'][]    = $w['t'];
            else                                                                $bucket['phone'][]    = $w['t'];
        }

        $material = trim(implode(' ', $bucket['material']));
        $price    = trim(implode(' ', $bucket['price']));
        if ($material === '' || $price === '') return null;

        return [
            'material'       => $material,
            'price_raw'      => $price,
            'delivery_basis' => trim(implode(' ', $bucket['delivery'])),
            'company'        => trim(implode(' ', $bucket['company'])),
            'buyer'          => trim(implode(' ', $bucket['buyer'])),
            // Phone: strip internal whitespace (handles kerning like "423-507- 4903")
            'phone'          => preg_replace('/\s+/', '', implode('', $bucket['phone'])),
        ];
    }

    private function validateRow(array $r): bool
    {
        return (bool) preg_match('/^(\+?\d[\d\-()]{6,})$/', $r['phone'] ?? '');
    }

    // ======================================================================
    // Backend 2 — lineprinter + normalización de kerning (fallback)
    // ======================================================================
    private function parseByLines(string $text): array
    {
        $section = null;
        $parsed  = [];
        $lines = preg_split("/\r\n|\r|\n/", $text);

        foreach ($lines as $raw) {
            $line = ltrim($raw);
            if ($line === '') continue;

            // Detect section headers
            if (preg_match('#^(ALUMINUM|COPPER/BRASS|SS/HITEMP/OTHER)\s+PRICE#', $line, $mm)) {
                $section = $mm[1];
                continue;
            }
            if (stripos($line, 'Copyright') !== false) continue;
            if (stripos($line, 'PEONY,') === 0) continue;
            if ($section === null) continue;

            // Kerning normalization heuristics (safe — only within columns)
            $line = preg_replace('/(\d+)-\s+(\d+)/', '$1-$2', $line);                  // "423- 4903" → "423-4903"
            $line = preg_replace('/([A-Z])\s([A-Z])(?=\s{2,}|$)/', '$1$2', $line);     // "A L" → "AL" at end of chunk
            $line = preg_replace('/([a-z])\s([a-z])(?=\s{2,}|$)/', '$1$2', $line);     // "Midwes t" → "Midwest" at end

            // Split by 3+ spaces (lineprinter convention)
            $cols = preg_split('/[ \t]{3,}/', $line);
            if (count($cols) < 6) continue;

            [$material, $price, $delivery, $company, $buyer, $phone] = array_map(
                'trim',
                array_slice($cols, 0, 6)
            );
            if ($material === '' || $price === '' || $phone === '') continue;

            $phone = preg_replace('/\s+/', '', $phone);

            $row = compact('material', 'price', 'delivery', 'company', 'buyer', 'phone');
            $parsed[] = [
                'section'        => $section,
                'material'       => $material,
                'price_raw'      => $price,
                'delivery_basis' => $delivery,
                'company'        => $company,
                'buyer'          => $buyer,
                'phone'          => $phone,
                'row_status'     => preg_match('/^(\+?\d[\d\-()]{6,})$/', $phone) ? 'ok' : 'kerning_fix',
            ];
        }
        return $parsed;
    }

    // ======================================================================
    // Helpers públicos
    // ======================================================================
    public static function parsePriceNum(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/^([\d.]+)/', $raw, $m)) return (float) $m[1];
        return null;
    }

    public static function parsePriceUnit(string $raw): ?string
    {
        if (preg_match('#/(\w+)#', $raw, $m)) return '/' . $m[1];
        if (preg_match('/(NetCash|Net|LMESpot|LMEx|Cmx|KG|GT|MT|PC)/i', $raw, $m)) return $m[1];
        return null;
    }

    public static function dateFromFilename(string $filename): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})CBE\.pdf$/i', basename($filename), $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return null;
    }
}
