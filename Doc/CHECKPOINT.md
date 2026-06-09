# CHECKPOINT — ValkamSync App
> **Instrucción de uso rápido**: Si hay ruptura de contexto, abre una nueva conversación y pega el bloque **"🚀 MEGAPROMPT DE INICIO"** que está justo abajo. Eso es todo — Claude tendrá contexto completo en segundos.
>
> **Protocolo de actualización**: Antes de cualquier cambio radical, copiar este archivo como `CHECKPOINT_YYYY-MM-DD_vN.md` y luego actualizar este archivo con el nuevo estado.

---

# 🚀 MEGAPROMPT DE INICIO
> Copia y pega este bloque completo en una conversación nueva con Claude Code para retomar exactamente donde quedamos.

---

```
Eres Claude Code trabajando en el proyecto ValkamSync para Jose Valenzuela / Valkam Capital.
Lee este checkpoint completo antes de responder cualquier cosa.

═══════════════════════════════════════════════════════════════
PROYECTO: ValkamSync
═══════════════════════════════════════════════════════════════

ValkamSync es una PWA interna de Valkam Capital para gestionar listas de precios
de chatarra metálica (PeonyInc) obtenidas de PDFs semanales. Permite:
- Importar PDFs → extraer precios automáticamente (3 backends: pdftotext bbox/lineprinter + Gemini)
- Explorar y comparar precios históricos por material, categoría y fecha
- Resolver fórmulas LME automáticamente (75%LMESpot, LLMEx60%, etc.) con matriz 4 puntos
- Gestionar clientes/compradores con CRM básico (máquina de estados)
- Detectar anomalías de datos con EDA por archivo

REPO LOCAL    : c:\Valkam Capital\Desarrollos\valkamsync-app
URL PRODUCCIÓN: https://petit.valkamgm.com/
ESTADO RAMA   : main — repo limpio, todo en producción
ÚLTIMO COMMIT : 348abdc — fix: conversión /MT /GT a USD/lb + detección de fórmulas irresolubles

═══════════════════════════════════════════════════════════════
STACK TECNOLÓGICO
═══════════════════════════════════════════════════════════════

- Frontend   : HTML5 + JS vanilla + Tailwind 4 → deploy/index.html (~4,500 LOC, single-file PWA)
- Backend    : PHP 8.1+ → deploy/api.php (router único, ~300 LOC)
- DB         : SQLite EXCLUSIVAMENTE en producción (HostGator shared hosting)
               Ruta: ~/petit.valkamgm.com/data/valkamsync.db
               (el .app_config.php.example menciona MySQL como opción pero prod usa SQLite)
- PDF Parser : pdftotext bbox-layout → lineprinter → Gemini Vision (3 backends, auto-fallback)
- IA         : Google Gemini API (deploy/lib/gemini_client.php) — OPCIONAL, degrada graciosamente
- CSS build  : bin\tailwindcss.exe -i src\css\input.css -o deploy\css\style.css --minify
- Hosting    : HostGator shared hosting + Cloudflare CDN

═══════════════════════════════════════════════════════════════
ESTRUCTURA DE ARCHIVOS CLAVE
═══════════════════════════════════════════════════════════════

deploy/
├── index.html              ← PWA completa (~4,500 LOC) — auth + EDA + material modal + LME UI
├── login.html              ← Pantalla de login (tabs Contraseña/PIN + soporte teclado físico)
├── api.php                 ← Router API (~300 LOC) — todos los endpoints
├── .app_config.php.example ← Template config (la real está en el server, nunca en git)
├── .htaccess               ← Apache (seguridad, rewrite, cache, HTTPS forzado)
├── css/style.css           ← CSS compilado (Tailwind 4)
└── lib/
    ├── auth.php            ← auth_login / auth_logout / auth_pin_change / auth_check_session
    ├── schema.php          ← Bootstrap idempotente de todas las tablas + migraciones ALTER TABLE
    ├── db.php              ← Conexión PDO SQLite/MySQL (función db())
    ├── peony_repo.php      ← Todas las queries (~1,700 LOC) — incluye peony_eda, peony_export_csv
    ├── peony_parser.php    ← Extractor PDF 3 backends + parsePriceNum/parsePriceRange (~390 LOC)
    ├── lme_client.php      ← Cliente LME multi-fuente (AV→MetalRadar→NASDAQ) + resolutor (~930 LOC)
    └── gemini_client.php   ← Cliente Gemini Vision + PDF fallback (~434 LOC)

Doc/
├── CHECKPOINT.md           ← Este archivo
└── ValkamSync_Notion.md    ← Blueprint completo del sistema

Raíz:
├── master-replication-prompt.md  ← Arquitectura y reglas de deploy ← LEER ANTES DE DEPLOY
├── Nuevos Cambios.txt            ← Roadmap oficial de features
├── ClavePublicaHostgator.txt     ← Clave pública SSH del servidor
└── Entrenamiento Inicial Claude.txt ← Prompt de entrenamiento original

═══════════════════════════════════════════════════════════════
DEPLOY — CÓMO HACERLO DESDE CLAUDE CODE
═══════════════════════════════════════════════════════════════

RUTA CORRECTA EN SERVIDOR: ~/petit.valkamgm.com/
  (NO es ~/public_html/petit — el subdominio tiene su propio document root)

CONFIGURACIÓN SSH (~/.ssh/config en la máquina de desarrollo):
  Host hostgator
      HostName 192.254.232.58
      User gzcapita
      Port 2222
      IdentityFile ~/.ssh/id_rsa_Jose    ← passphrase: Mushroom2026!
      IdentitiesOnly yes
      LogLevel QUIET
      PubkeyAcceptedAlgorithms +ssh-rsa

PATRÓN CORRECTO DE SCP (el eval ssh-agent NO funciona entre llamadas Bash):

  printf "#!/bin/sh\necho Mushroom2026!" > /tmp/ap.sh && chmod +x /tmp/ap.sh
  SSH_AUTH_SOCK="" DISPLAY=fake SSH_ASKPASS=/tmp/ap.sh SSH_ASKPASS_REQUIRE=force \
    scp -P 2222 -o StrictHostKeyChecking=no -o IdentitiesOnly=yes \
    -o PubkeyAcceptedAlgorithms=+ssh-rsa -i ~/.ssh/id_rsa_Jose \
    "LOCAL_FILE" gzcapita@192.254.232.58:~/petit.valkamgm.com/DEST
  rm -f /tmp/ap.sh

  # lib files van a ~/petit.valkamgm.com/lib/
  # api.php e index.html van a ~/petit.valkamgm.com/  (raíz)

PATRÓN PARA COMANDOS SSH REMOTOS:

  printf "#!/bin/sh\necho Mushroom2026!" > /tmp/ap.sh && chmod +x /tmp/ap.sh
  SSH_AUTH_SOCK="" DISPLAY=fake SSH_ASKPASS=/tmp/ap.sh SSH_ASKPASS_REQUIRE=force \
    ssh -p 2222 -o StrictHostKeyChecking=no -o IdentitiesOnly=yes \
    -o PubkeyAcceptedAlgorithms=+ssh-rsa -i ~/.ssh/id_rsa_Jose \
    gzcapita@192.254.232.58 "COMANDO" 2>&1
  rm -f /tmp/ap.sh

PATRÓN PARA EJECUTAR PHP EN SERVIDOR (para resolvers/migraciones):

  cat > /tmp/vs_r.php << 'PHPEOF'
  <?php
  chdir(getenv("HOME") . "/petit.valkamgm.com");
  require ".app_config.php"; require "lib/db.php"; require "lib/schema.php";
  require "lib/lme_client.php"; require "lib/peony_repo.php"; require "lib/peony_parser.php";
  $pdo = db(); initDB($pdo);
  // ... tu código ...
  PHPEOF
  # Luego SCP a /tmp/vs_r.php y ssh php /tmp/vs_r.php

IMPORTANTE: NUNCA sobreescribir ~/petit.valkamgm.com/.app_config.php del servidor
(contiene credenciales SQLite + Gemini + Alpha Vantage de producción).

SQLITE DIRECTO DESDE SSH:
  sqlite3 ~/petit.valkamgm.com/data/valkamsync.db "SELECT ..."

═══════════════════════════════════════════════════════════════
SISTEMA LME — ESTADO COMPLETO (v16, 2026-06-08)
═══════════════════════════════════════════════════════════════

FUENTES EN CASCADA (AV → MetalRadar → NASDAQ):
  P1: Alpha Vantage — promedio mensual IMF LME (USD/MT). Activa, 25 req/día.
      Key: ALPHAVANTAGE_API_KEY en .app_config.php del servidor
  P2: Metal Radar   — datos diarios. Activar al renovar cuenta (METALRADAR_EMAIL/PASSWORD)
  P3: NASDAQ Data Link — datos diarios 4 puntos. Key: NASDAQ_API_KEY

METALES SOPORTADOS:
  ALUMINUM        → LME-AL
  COPPER/BRASS    → LME-CU
  SS/HITEMP/OTHER → LME-CU (referencia base para chatarra mixta)

FÓRMULAS QUE SE RESUELVEN AUTOMÁTICAMENTE:
  "75%LMESpot"  "80%LME"  "75% de LME"    → Patrón 1: N%LME[Spot]
  "LLMEx60%"  "LLMEX60%"  "LMEx60%"        → Patrón 2: L+MEx{N}% (London LME ×)

FÓRMULAS IRRESOLUBLES (se marcan lme_resolved=-1 con mensaje descriptivo):
  "70%LMENi+Co"       → Níquel+Cobalto, sin fuente LME-NI configurada
  "3M+50/-pr"         → Spread 3 meses + prima/descuento, no resoluble con precio spot
  "Cmx May-85"        → Diferencial COMEX por mes, requiere datos de futuros
  "May-85", "Jul-85"  → Ídem

CONVERSIÓN DE UNIDADES (CRÍTICO — resuelto en esta sesión):
  Todas las APIs LME devuelven USD/MT. Los precios PeonyInc son USD/lb.
  Factor: LME_LB_PER_MT = 2204.62 (constante en lme_client.php)
  price_num = pct% × (base_USD_MT ÷ 2204.62)   ← precio FINAL en USD/lb
  lme_base_price_used y lme_cash_buyer/seller se guardan en USD/MT (auditoría)

FUNCIONES CLAVE en lme_client.php:
  lme_parse_formula($raw)              → parsea fórmula → {pct:float}|null
  lme_detect_unresolvable($raw)        → detecta irresolubles → mensaje|null
  lme_find_minimum_point($matrix)      → menor de los 4 puntos LME (criterio conservador)
  lme_resolve_formula_prices($pdo, $fileId, $fileDate) → post-import, best-effort
  lme_resolve_all_pending($pdo)        → re-procesa TODOS los lme_resolved=0 en DB
  lme_fix_unit_prices($pdo)            → migración: corrige price_num en escala USD/MT
  lme_get_matrix($pdo, $metal, $date)  → cache DB → AV → MetalRadar → NASDAQ

ENDPOINTS:
  POST /api.php?action=peony_lme_resolve_all   → re-resuelve toda la DB
  POST /api.php?action=peony_lme_fix_units     → migración one-shot USD/MT→USD/lb

ESTADO DB PRODUCCIÓN (2026-06-08):
  - Todos los %LMESpot y LLMEx60% resueltos (lme_resolved=1)
  - 22 fórmulas irresolubles marcadas (lme_resolved=-1) con mensaje en lme_error
  - 0 fórmulas pendientes mal tipadas en lme_resolved=0

═══════════════════════════════════════════════════════════════
SISTEMA DE PRECIOS — parsePriceNum y parsePriceRange (v16)
═══════════════════════════════════════════════════════════════

parsePriceNum(string $raw): ?float — retorna SIEMPRE en USD/lb
  - Rangos "0.28-5.00"      → punto medio 2.64 USD/lb
  - Rangos "270-330/MT"     → mid 300÷2204.62 = 0.1361 USD/lb
  - Simples "750/MT"        → 750÷2204.62 = 0.3401 USD/lb
  - Simples "245/GT"        → 245÷2240 = 0.1094 USD/lb
  - OCR cirílico "750/мт"  → ídem que /MT
  - Directo "1.28"          → 1.28 USD/lb (sin cambio)
  - Con coma "2,500/MT"     → 2500÷2204.62 = 1.1340 USD/lb
  - /Cu, /Al, NetCash       → extrae número, sin conversión (ya USD/lb)
  - /PC, /KG, fórmulas LME → sin conversión (unidad no convertible o fórmula)

parsePriceRange(string $raw): ?array{min,max,mid} — valores en USD/lb
  - Requiere $ al final del rango para no confundir "7-1400/PC"
  - Maneja rangos con unidad: "270-330/MT" → min=0.1225, max=0.1498, mid=0.1361

COLUMNAS DE RANGO EN DB (price_num_min, price_num_max):
  - Solo se llenan cuando el price_raw es un rango (ej. "0.28-5.00")
  - price_num = midpoint, min/max almacenados para auditoría y UI
  - Tarjeta de contacto muestra "2.64/lb · 0.28–5.00 · rango" cuando hay min/max

MIGRACIÓN DB PRODUCCIÓN (2026-06-08):
  - 151 filas /GT corregidas, 88 /MT simples, 6 rangos /MT
  - 165 filas range USD/lb con midpoint y min/max
  - 2 filas LME legacy (lme_base_price_used=NULL) corregidas ÷2204.62

═══════════════════════════════════════════════════════════════
SCHEMA DB — COLUMNAS RELEVANTES vsync_peony_prices
═══════════════════════════════════════════════════════════════

  price_raw              VARCHAR(100)   ← original del PDF ej. "75%LMESpot", "0.28-5.00"
  price_num              DECIMAL(12,4)  ← USD/lb: midpoint si rango, LME resuelto si fórmula
  price_num_min          DECIMAL(12,4)  ← NULL si no es rango; USD/lb límite inferior
  price_num_max          DECIMAL(12,4)  ← NULL si no es rango; USD/lb límite superior
  price_unit             VARCHAR(20)    ← /MT, /GT, /PC, /Cu, /Al, etc.
  lme_resolved           TINYINT(1)     ← 0=pendiente, 1=resuelto, -1=irresoluble
  lme_price              DECIMAL(14,4)  ← backward compat (= lme_base_price_used)
  lme_cash_buyer         DECIMAL(14,4)  ← USD/MT (auditoría)
  lme_cash_seller        DECIMAL(14,4)  ← USD/MT (auditoría)
  lme_3_months_buyer     DECIMAL(14,4)  ← USD/MT (auditoría)
  lme_3_months_seller    DECIMAL(14,4)  ← USD/MT (auditoría)
  lme_percentage_applied DECIMAL(7,4)   ← ej. 75.0
  lme_base_price_used    DECIMAL(14,4)  ← USD/MT — el mínimo de los 4 puntos usado
  lme_type_used          VARCHAR(20)    ← CASH_BUYER | CASH_SELLER | THREE_MONTHS_BUYER | ...
  lme_error              TEXT           ← descripción del fallo si lme_resolved=-1

═══════════════════════════════════════════════════════════════
GEMINI PDF — ESTADO (v16)
═══════════════════════════════════════════════════════════════

gemini_client.php — Doble loop keys × modelos:
  GEMINI_API_KEY   = key primaria  (AIzaSy...)
  GEMINI_API_KEY_2 = key fallback  (AQ.Ab8...)  — auto al recibir 503/429/500
  GEMINI_MODEL     = gemini-2.5-flash (con fallbacks automáticos a 2.0-flash, etc.)
  maxOutputTokens  = 16384 en callPdfModel (subido de 8192 — PDFs tienen ~70 filas)

Fallback chain en PeonyParser.parse():
  1. pdftotext -bbox-layout  (poppler, precisión coords)
  2. pdftotext -lineprinter  (xpdf, columnas fijas)
  3. Gemini PDF              (cuando pdftotext no disponible en HostGator)

Error messages correctos:
  gemini_pdf_truncated  → "El PDF tiene demasiadas filas para el límite de tokens"
  gemini_http_503/429   → "El modelo está saturado — reintenta en unos minutos"

═══════════════════════════════════════════════════════════════
SISTEMA DE AUTH — EN PRODUCCIÓN
═══════════════════════════════════════════════════════════════

Credenciales por defecto: admin / Valkam2026! / PIN: 11223344
BUG CORREGIDO: auth.php usa gmdate() (no date()) para expires_at — PHP TZ ≠ SQLite UTC

═══════════════════════════════════════════════════════════════
ROADMAP DE FEATURES — ESTADO COMPLETO
═══════════════════════════════════════════════════════════════

F1  ✅ Login con PIN             → producción
F2  ✅ Colores en categorías     → producción (categoryChip: azul/ámbar/gris)
F3  ✅ Compartir reporte EDA     → producción (PDF + PNG)
F4  ✅ Analítica mejorada        → producción (rango fechas + sparklines)
F5  ✅ EDA en descarga           → producción (modal EDA + outlier detection + CSV)
F6  ✅ Manual ValkamSync         → producción (Doc/ValkamSync_Notion.md v4)
F7  ✅ LME Spot Scraping         → producción
       - Cascada 3 fuentes: Alpha Vantage (P1 activa) → MetalRadar (P2 al renovar) → NASDAQ (P3)
       - Fórmulas soportadas: N%LMESpot, LLMEx60% y variantes
       - Fórmulas irresolubles: marcadas con error descriptivo (Ni+Co, 3M spread, COMEX)
       - Todos los precios en USD/lb (LME_LB_PER_MT = 2204.62)
F8  ✅ Gráfico tendencia interactivo → producción (5 temporalidades + SVG puro)
F9  ✅ Preview materiales por archivo → producción (commit 5e4c9b3)
F10 ✅ Matriz LME 4 puntos con auditoría → producción (commit 3d432fd)

═══════════════════════════════════════════════════════════════
REGLAS DE ARQUITECTURA — NO VIOLAR
═══════════════════════════════════════════════════════════════

1. Sin Composer ni Node.js en prod (HostGator cPanel jailshell)
2. Prefijo vsync_* en TODAS las tablas (coexiste con gzcapita_valkam existente)
3. PDO prepared statements en TODAS las queries (nunca interpolación)
4. CSS compilado localmente antes de deployar (bin\tailwindcss.exe)
5. Gemini es SIEMPRE opcional (graceful degradation sin API key)
6. Un solo index.html (app entera, single-file PWA)
7. Credenciales solo en .app_config.php (git-ignored, nunca en el código)
8. Fechas/timestamps hacia SQLite: usar gmdate() NO date() (PHP TZ = America/Chicago)
9. price_num SIEMPRE en USD/lb — jamás almacenar USD/MT directamente en price_num
10. lme_base_price_used y lme_cash_* se guardan en USD/MT (solo para auditoría)
11. parsePriceNum() maneja /MT y /GT con conversión automática — no hardcodear en imports

═══════════════════════════════════════════════════════════════
CONTEXTO DE NEGOCIO Y AMBIENTE DE DESARROLLO
═══════════════════════════════════════════════════════════════

- Desarrollador    : Jose Valenzuela
- Empresa          : Valkam Capital LLC (trading de metales no ferrosos)
- Email            : creative@mushroomalive.com
- OS               : Windows 11 Home
- Shell preferida  : PowerShell (deploy vía Bash tool de Claude Code)
- Repo local       : c:\Valkam Capital\Desarrollos\valkamsync-app
- Repo GitHub      : https://github.com/adelphinsucasa/ValkamSync.git
- Repo adicional   : C:\Desarrollos\SmartCart App (proyecto separado, no tocar)
- Idioma de trabajo: Español

PARA COMENZAR LA PRÓXIMA SESIÓN:
  Si Jose tiene una nueva feature → preguntar qué quiere hacer
  Si dice "deploy" → usar el patrón SCP de la sección DEPLOY
  Si hay que correr PHP en servidor → usar el patrón PHP en servidor
═══════════════════════════════════════════════════════════════
```

---

## Metadatos del Checkpoint

| Campo | Valor |
|-------|-------|
| **Fecha última actualización** | 2026-06-08 |
| **Versión** | v16 — F1-F10 en producción + correcciones LME USD/MT→USD/lb + rangos de precio |
| **Estado del repo** | Todo en producción y en git. Repo limpio. |
| **Último commit** | `348abdc` — fix: conversión /MT /GT a USD/lb + detección de fórmulas irresolubles |
| **Rama activa** | `main` |
| **Desarrollador** | Jose |

---

## Historial de Checkpoints

| Archivo | Fecha | Descripción del estado |
|---------|-------|----------------------|
| `CHECKPOINT.md` (este) | 2026-06-08 | v16 — F1-F10 producción. Correcciones USD/MT→USD/lb, rangos, fórmulas irresolubles |
| *(respaldo v15)* | 2026-06-08 | v15 — F1-F8 todos en producción. Commit `3db7e40`. Repo limpio. |
| *(respaldo v14)* | 2026-06-05 | v14 — F7 operativo (Alpha Vantage, 26/26 resueltos), F8 gráfico tendencia, doble key Gemini |
| *(respaldo v9)* | 2026-06-03 | v9 — F7 LME Scraping implementado. Credenciales configuradas. Deploy completo |
| *(respaldo v8)* | 2026-06-02 | v8 — F7 LME Scraping definido. Prompt de nueva sesión guardado |
| *(respaldo v7)* | 2026-06-02 | v7 — F6 (manual v2) completo. Roadmap inicial F1–F6 100% completado |
| *(respaldo v6)* | 2026-06-02 | v6 — F3 compartir EDA (Imprimir/PDF + Imagen PNG via Canvas) deployado |
| *(respaldo v5)* | 2026-06-02 | v5 — F2 (category badges) + F4 (rango fechas + sparklines) deployados |
| *(respaldo v4)* | 2026-06-02 | v4 — EDA+CSV deployado, bug timezone corregido, teclado PIN |
| *(respaldo v3)* | 2026-06-01 | v3 — auth deployado en producción, ruta servidor corregida |
