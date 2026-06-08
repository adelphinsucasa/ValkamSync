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
de materiales de construcción ("Peony") obtenidas de PDFs. Permite:
- Importar PDFs → extraer precios automáticamente (3 backends: pdftotext + Gemini)
- Explorar y comparar precios históricos por material, categoría y fecha
- Gestionar clientes/compradores con CRM básico (máquina de estados)
- Calcular rentabilidad y detectar anomalías de datos

REPO LOCAL    : c:\Valkam Capital\Desarrollos\valkamsync-app
URL PRODUCCIÓN: https://petit.valkamgm.com/
ESTADO RAMA   : main (cambios locales sin commitear — todo está deployado en prod)

═══════════════════════════════════════════════════════════════
STACK TECNOLÓGICO
═══════════════════════════════════════════════════════════════

- Frontend   : HTML5 + JS vanilla + Tailwind 4 → deploy/index.html (~4,350 LOC, single-file PWA)
- Backend    : PHP 8.1+ → deploy/api.php (router único, ~280 LOC)
- DB         : SQLite (dev local) / MySQL gzcapita_valkam (prod HostGator), prefijo vsync_*
- PDF Parser : pdftotext bbox-layout → lineprinter → Gemini Vision (3 backends, auto-fallback)
- IA         : Google Gemini API (deploy/lib/gemini_client.php) — OPCIONAL, degrada graciosamente
- CSS build  : bin\tailwindcss.exe -i src\css\input.css -o deploy\css\style.css --minify
- Hosting    : HostGator shared hosting + Cloudflare CDN

═══════════════════════════════════════════════════════════════
ESTRUCTURA DE ARCHIVOS CLAVE
═══════════════════════════════════════════════════════════════

deploy/
├── index.html              ← PWA completa (~4,350 LOC) — auth guard + EDA modal + botón settings
├── login.html              ← Pantalla de login (tabs Contraseña/PIN + soporte teclado físico)
├── api.php                 ← Router API (~280 LOC) — guard 401 + rutas auth_* + peony_eda + peony_export_csv
├── .app_config.php.example ← Template config (la real está en el server, nunca en git)
├── .htaccess               ← Apache (seguridad, rewrite /api/<action>, cache, HTTPS forzado)
├── css/style.css           ← CSS compilado (Tailwind 4, recompilado 2026-06-01)
└── lib/
    ├── auth.php            ← auth_login / auth_logout / auth_pin_change / auth_check_session
    ├── schema.php          ← Tablas vsync_auth + vsync_sessions + seed admin + resto del schema
    ├── db.php              ← Conexión PDO SQLite/MySQL
    ├── peony_repo.php      ← Todas las queries + peony_eda() + peony_export_csv() (~1,550 LOC)
    ├── peony_parser.php    ← Extractor PDF 3 backends (322 LOC)
    └── gemini_client.php   ← Cliente Gemini Vision (388 LOC)

Doc/
├── CHECKPOINT.md           ← Este archivo (punto de restauración de contexto)
└── ValkamSync_Notion.md    ← Blueprint completo del sistema (23 secciones)

Raíz:
├── master-replication-prompt.md  ← Arquitectura y reglas de deploy ← LEER ANTES DE DEPLOY
├── Nuevos Cambios.txt            ← Roadmap oficial de features
├── ClavePublicaHostgator.txt     ← Clave pública SSH del servidor
└── Entrenamiento Inicial Claude.txt ← Prompt de entrenamiento original del proyecto

═══════════════════════════════════════════════════════════════
SISTEMA DE AUTH — EN PRODUCCIÓN
═══════════════════════════════════════════════════════════════

Autenticación completa funcionando. Credenciales por defecto:
  - Usuario: admin  |  Contraseña: Valkam2026!  |  PIN: 11223344

Archivos involucrados:
  deploy/lib/auth.php    → auth_login / auth_logout / auth_pin_change / auth_check_session
  deploy/lib/schema.php  → tablas vsync_auth + vsync_sessions, seed automático en primer arranque
  deploy/api.php         → guard 401 (acciones abiertas: auth_login, auth_check, auth_logout, health)
  deploy/login.html      → UI login (tabs Contraseña/PIN, teclado físico para PIN, "Recordar 30 días")
  deploy/index.html      → guard en <head>, botón usuario, dropdown PIN/logout, interceptor fetch 401

FLUJO DE AUTH:
  1. Usuario visita / → guard checa localStorage/sessionStorage → si no hay → /login.html
  2. Login exitoso → servidor crea sesión en vsync_sessions + cookie HttpOnly vsync_tok
  3. Cliente guarda señal en localStorage (remember=true) o sessionStorage (remember=false)
  4. index.html hace auth_check al arrancar → si falla → logout
  5. Sesión expirada → API devuelve 401 → fetch interceptor → logout → /login.html

BUG CORREGIDO (2026-06-02):
  auth.php usaba date() para calcular expires_at (zona horaria PHP = America/Chicago UTC-5).
  MySQL compara con NOW() en UTC → la sesión llegaba "expirada" desde el momento de creación.
  FIX: cambiado date() → gmdate() en auth.php línea 63. Deployado y verificado.

═══════════════════════════════════════════════════════════════
EDA + DESCARGA CSV — EN PRODUCCIÓN (2026-06-02)
═══════════════════════════════════════════════════════════════

Cada tarjeta de archivo importado tiene ahora un botón "EDA" que abre un modal con:

1. SCORE DE CALIDAD (0–100%) — porcentaje de filas sin anomalías
2. ESTADÍSTICAS DESCRIPTIVAS — min, max, promedio, mediana, desv. est., Q1, Q3, IQR,
   lower_fence y upper_fence (rango normal IQR)
3. ANOMALÍAS DETECTADAS — lista de filas con problemas, cada una con tipo y detalle:
   - formula_price     → price_raw contiene %, lme, spot, basis, comex, cwt, shfe, formula
                         (caso real: "75%LMESpot" parseado como 75 → flag automático)
   - iqr_outlier       → precio fuera del rango [Q1-1.5*IQR, Q3+1.5*IQR] del archivo
   - historical_outlier→ desviación >60% del promedio histórico del mismo material
   - zero_or_negative  → precio ≤ 0
4. BOTÓN "DESCARGAR CSV" — descarga directa desde el modal

Funciones PHP nuevas en peony_repo.php:
  peony_eda($pdo, $params)         → análisis completo, retorna JSON
  peony_export_csv($pdo, $params)  → stream directo de CSV al browser

Rutas nuevas en api.php:
  GET /api/peony_eda?file_id=N         → JSON con análisis EDA
  GET /api/peony_export_csv?file_id=N  → descarga CSV

═══════════════════════════════════════════════════════════════
F3 — COMPARTIR REPORTE EDA — DEPLOYADO 2026-06-02 (v6)
═══════════════════════════════════════════════════════════════

Botón "Compartir" (dropdown) en el footer del modal EDA. No requiere cambios en backend.

NUEVO BOTÓN en footer del modal EDA:
  - Dropdown con dos opciones: "Imprimir / PDF" y "Imagen PNG"
  - Se cierra al hacer clic fuera del dropdown

OPCIÓN "IMPRIMIR / PDF":
  - Función shareEdaAsPdf() → abre nueva pestaña con HTML standalone bien formateado
  - El HTML tiene print CSS para que Ctrl+P / Guardar como PDF quede limpio
  - Auto-dispara setTimeout → window.print() al cargar la página
  - Contiene: header con filename, score de calidad con color, audit grid (fórmula/IQR/hist/cero),
    tabla de estadísticas descriptivas (8 métricas), lista de anomalías con colores error/warning

OPCIÓN "IMAGEN PNG":
  - Función shareEdaAsImage() → genera canvas 800×H px (2× retina) y descarga como PNG
  - Dibujado 100% con Canvas 2D API, sin librerías externas
  - Secciones: barra oscura con nombre archivo, fila meta, score card coloreado,
    grid de estadísticas 4×2, lista de anomalías con tarjetas coloreadas, footer
  - Nombre de descarga: EDA_{filename}.png

Nuevas funciones JS (dentro del IIFE del modal EDA en index.html):
  toggleEdaShareMenu()  → abre/cierra el dropdown
  shareEdaAsPdf()       → ventana de impresión
  shareEdaAsImage()     → canvas PNG
  _buildEdaPrintHtml(d) → construye el HTML standalone del reporte
  _downloadEdaCanvas(d) → dibuja en canvas y descarga PNG
  _rrect(ctx,...)       → helper rounded rect para canvas
  _card(ctx,...)        → helper tarjeta con fondo/borde para canvas

Variable nueva: _edaData (almacena el último objeto d de renderEda para que los share functions
puedan acceder a él sin re-fetch)

═══════════════════════════════════════════════════════════════
F2 + F4 — DEPLOYADO 2026-06-02 (v5)
═══════════════════════════════════════════════════════════════

F2 — BADGES DE COLOR EN CATEGORÍAS:
  Nueva función JS: categoryChip(cat) en deploy/index.html
    ALUMINUM       → badge azul    (bg-blue-100 text-blue-800)
    COPPER/BRASS   → badge ámbar   (bg-amber-100 text-amber-700)
    SS/HITEMP/OTHER→ badge gris    (bg-slate-100 text-slate-600)
  Aplicado en: renderPricesTable (vista Materiales) y renderTrends (vista Tendencias)

F4 — ANALÍTICA MEJORADA:
  1. FILTRO DE RANGO DESDE/HASTA
     - Nuevo chip "Rango" en renderFilters() → muestra inputs "Desde" y "Hasta"
     - Estado: s.dateFrom y s.dateTo (strings ISO YYYY-MM-DD)
     - Frontend: matchFilter() actualizado para filtrar por rango en modo bootstrap/JSON
     - Frontend: loadPrices, loadMaterials, loadFiles pasan date_from/date_to a la API
     - Backend: peony_build_date_where() extendida con filter='range' + $dateFrom + $dateTo
       (funciona en MySQL Y SQLite con BETWEEN :df AND :dt)
     - peony_prices, peony_files, peony_materials actualizadas para extraer date_from/date_to

  2. SPARKLINE SVG EN MODAL DE MATERIAL
     - Nueva función JS: renderSparkline(timeline) → SVG de 240×48px sin librerías externas
     - Muestra historial de precios (avg_price por fecha) ordenado cronológicamente
     - Verde si precio subió, rojo si bajó; punto final destacado; etiquetas inicio/fin
     - Insertado en renderMaterialModal() entre el bloque de stats y los contactos
     - Usa m.detail.timeline ya existente (endpoint peony_material_detail, 60 puntos max)

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

SNIPPET DE DEPLOY DESDE CLAUDE CODE (Bash tool, funciona sin Git Bash manual):

  # 1. Cargar clave SSH en agente
  eval $(ssh-agent -s) 2>/dev/null
  printf '#!/bin/sh\necho "Mushroom2026!"' > /tmp/askpass_v.sh && chmod +x /tmp/askpass_v.sh
  DISPLAY=fake SSH_ASKPASS=/tmp/askpass_v.sh SSH_ASKPASS_REQUIRE=force ssh-add ~/.ssh/id_rsa_Jose < /dev/null 2>/dev/null

  # 2a. Deploy completo (si cambiaron muchos archivos):
  cd "/c/Valkam Capital/Desarrollos/valkamsync-app"
  ./bin/tailwindcss.exe -i src/css/input.css -o deploy/css/style.css --minify
  cd deploy
  tar --exclude='./.app_config.php' --exclude='./data' --exclude='./*.db' --exclude='./*.sqlite' \
    -czf - . | ssh hostgator "tar -xzf - -C ~/petit.valkamgm.com"

  # 2b. Deploy de un solo archivo (más rápido para cambios puntuales):
  scp "/c/Valkam Capital/Desarrollos/valkamsync-app/deploy/lib/auth.php" \
    hostgator:~/petit.valkamgm.com/lib/auth.php

  # 3. Limpieza
  rm -f /tmp/askpass_v.sh && kill $SSH_AGENT_PID 2>/dev/null

IMPORTANTE: NO sobreescribir ~/petit.valkamgm.com/.app_config.php del servidor
(contiene credenciales MySQL de producción).

═══════════════════════════════════════════════════════════════
ROADMAP DE FEATURES (Nuevos Cambios.txt)
═══════════════════════════════════════════════════════════════

F1 ✅ Login con PIN           → COMPLETADO (admin/Valkam2026! + PIN 11223344 + teclado físico)
F2 ✅ Colores en categorías   → COMPLETADO (función categoryChip() con badges color: azul=Al, ámbar=Cu/Brass, gris=SS/Other)
F3 ✅ Compartir reporte EDA   → COMPLETADO (Opción B): botón "Compartir" en modal EDA con dos opciones:
       - "Imprimir / PDF": abre ventana de impresión limpia con reporte completo (activa print dialog)
       - "Imagen PNG": genera card 800px con Canvas API (header, score, stats, anomalías) y descarga PNG
F4 ✅ Analítica mejorada      → COMPLETADO: filtro Rango desde/hasta + sparkline SVG historial precios en modal material
F5 ✅ EDA en descarga          → COMPLETADO (modal EDA + outlier detection + CSV download)
F6 ✅ Manual ValkamSync        → COMPLETADO — Doc/ValkamSync_Notion.md actualizado a v2 (2026-06-02):
       sección 19 actualizada con estado real de F1–F6, S8 corregido (auth implementada),
       sección 23 con timeline real, historial de versiones, footer y TL;DR actualizados

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
8. Sin rutas hardcodeadas en PHP (usar dirname(__FILE__) o $_SERVER['HOME'])
9. Fechas/timestamps hacia MySQL: usar gmdate() NO date() (PHP TZ = America/Chicago ≠ MySQL UTC)

═══════════════════════════════════════════════════════════════
CONTEXTO DE NEGOCIO Y AMBIENTE DE DESARROLLO
═══════════════════════════════════════════════════════════════

- Desarrollador    : Jose Valenzuela
- Empresa          : Valkam Capital LLC (trading de metales no ferrosos)
- Email            : creative@mushroomalive.com
- OS               : Windows 11 Home
- Shell preferida  : PowerShell (deploy vía Bash tool de Claude Code — no necesita Git Bash manual)
- Repo local       : c:\Valkam Capital\Desarrollos\valkamsync-app
- Repo adicional   : C:\Desarrollos\SmartCart App (proyecto separado, no tocar)
- Idioma de trabajo: Español
- PHP en servidor  : America/Chicago (UTC-5) — MySQL corre en UTC → siempre usar gmdate()

═══════════════════════════════════════════════════════════════
DOCUMENTOS DE REFERENCIA EN EL PROYECTO
═══════════════════════════════════════════════════════════════

- master-replication-prompt.md  → arquitectura, SSH, reglas prod
- Doc/ValkamSync_Notion.md      → blueprint completo del sistema (23 secciones)
- Doc/CHECKPOINT.md             → este archivo
- Nuevos Cambios.txt            → roadmap oficial
- ClavePublicaHostgator.txt     → clave pública SSH (ya autorizada en el server)
- Entrenamiento Inicial Claude.txt → contexto inicial del proyecto

═══════════════════════════════════════════════════════════════
F7 — LME SPOT SCRAPING — NASDAQ DATA LINK (v11, 2026-06-04)
═══════════════════════════════════════════════════════════════

FUENTE: NASDAQ Data Link (data.nasdaq.com)
  API key permanente: define('NASDAQ_API_KEY', 'TU_KEY') en .app_config.php del servidor
  Obtener clave gratuita en: https://data.nasdaq.com/sign-up
  NO hay tokens que expiren — solo una clave que va y viene.

CATEGORÍAS SOPORTADAS (las 3 únicas que existen en la DB):
  ALUMINUM        → LME-AL (Cash Bid / OfficialBid)
  COPPER/BRASS    → LME-CU
  SS/HITEMP/OTHER → LME-CU (referencia base para chatarra mixta: E-Scrap, Solder Dross, etc.)

REGEX VÁLIDA para price_raw: /^\d+(\.\d+)?%LMESpot$/i
  ✅ Matchea: "75%LMESpot", "80%LMESpot", "90%LMESpot"
  ❌ Excluye: "70%LMENi+Co", "LLMEx60%", "LME 3M+46/-pr"

ARCHIVOS MODIFICADOS (todos en producción):
  deploy/lib/lme_client.php      ← NUEVO: cliente MetalRadar completo
  deploy/lib/schema.php          ← vsync_lme_cache + ALTER TABLE lme_resolved/lme_price
  deploy/lib/peony_repo.php      ← llama resolve post-import; EDA excluye lme_resolved=1
  deploy/api.php                 ← require lme_client + endpoint peony_lme_resolve_all
  deploy/.app_config.php.example ← sección MetalRadar documentada

FUNCIONES CLAVE en lme_client.php:
  lme_resolve_formula_prices($pdo, $fileId, $fileDate) → corre post-import (best-effort)
  lme_resolve_all_pending($pdo)                        → re-procesa TODOS los pendientes en DB
  lme_get_spot($pdo, $metal, $date)                    → consulta cache → NASDAQ Data Link → guarda
  lme_fetch_nasdaq($dataset, $date)                    → fetch directo a data.nasdaq.com/api/v3

ENDPOINT NUEVO:
  POST /api.php?action=peony_lme_resolve_all  → re-resuelve toda la DB sin reimportar

CAMBIOS EN DB:
  vsync_lme_cache: nueva tabla (metal, price_date, cash_usd) UNIQUE(metal, price_date)
  vsync_peony_prices: +lme_resolved TINYINT(1) DEFAULT 0, +lme_price DECIMAL(14,4)

CAMBIOS EN UI (index.html):
  - Tabla precios: badge azul "LME" + precio calculado en $/MT para filas resueltas
  - Modal EDA: 5ª columna "🔗 LME resueltos N" en el audit grid
  - Reporte PDF e imagen PNG: incluyen contador LME

═══════════════════════════════════════════════════════════════
✅  F7 COMPLETAMENTE OPERATIVO — ALPHA VANTAGE (2026-06-05)
═══════════════════════════════════════════════════════════════

FUENTE ACTIVA: Alpha Vantage (IMF Primary Commodity Prices)
  API key en servidor: define('ALPHAVANTAGE_API_KEY', '...') en .app_config.php
  Clave gratis (25 req/día): https://www.alphavantage.co/support/#api-key
  Sin tokens que expiran. Datos: LME Copper + Aluminum, USD/MT, promedio mensual.

ESTADO RESUELTO (2026-06-05):
  26/26 fórmulas históricas resueltas (16 fechas, abr-jun 2026)
  0 pendientes en vsync_peony_prices

OPTIMIZACIÓN IMPLEMENTADA (lme_client.php):
  lme_av_dataset() — variable static: 1 HTTP call por metal por ejecución PHP
  Ej: 16 fechas CU + 1 fecha AL → solo 2 llamadas a Alpha Vantage (no 17)
  
FLUJO PARA NUEVOS IMPORTS:
  PDF importado → lme_resolve_formula_prices() auto → cache DB → AV (si no hay cache)
  Re-procesar todo: POST /api.php?action=peony_lme_resolve_all

═══════════════════════════════════════════════════════════════
DATO CRÍTICO DE INFRAESTRUCTURA DESCUBIERTO (2026-06-04)
═══════════════════════════════════════════════════════════════

PRODUCCIÓN USA SQLite, NO MySQL:
  El .app_config.php real del servidor tiene:
    define('DB_DRIVER', 'sqlite');
    define('DB_PATH',   __DIR__ . '/data/valkamsync.db');
  
  La DB SQLite está en: ~/petit.valkamgm.com/data/valkamsync.db
  Para inspeccionar en producción: sqlite3 ~/petit.valkamgm.com/data/valkamsync.db "SELECT ..."
  El checkpoint anterior decía "MySQL gzcapita_valkam" — eso NO es correcto para ValkamSync.
  (MySQL gzcapita_valkam es la DB del Deal Calculator y el Lot Sheet Generator, no de ValkamSync)

SSH — PATRÓN CORRECTO PARA COMANDOS REMOTOS:
  Usar ssh-agent bash con heredoc (los env vars del agente no persisten entre llamadas Bash):
  
  ssh-agent bash << 'ENDBASH'
    printf "#!/bin/sh\necho Mushroom2026!" > /tmp/ap.sh && chmod +x /tmp/ap.sh
    DISPLAY=fake SSH_ASKPASS=/tmp/ap.sh SSH_ASKPASS_REQUIRE=force ssh-add ~/.ssh/id_rsa_Jose < /dev/null 2>/dev/null
    rm -f /tmp/ap.sh
    ssh -p 2222 -o StrictHostKeyChecking=no -o IdentitiesOnly=yes gzcapita@192.254.232.58 "COMANDO"
  ENDBASH

  NOTA: NO usar "eval $(ssh-agent)" + kill al final en llamadas separadas — el agente muere
  entre llamadas Bash del tool. Todo debe ir en un solo bloque ssh-agent bash.

═══════════════════════════════════════════════════════════════
ROADMAP DE FEATURES — ESTADO FINAL
═══════════════════════════════════════════════════════════════

F1 ✅ Login con PIN           → COMPLETADO y en producción
F2 ✅ Colores en categorías   → COMPLETADO y en producción
F3 ✅ Compartir reporte EDA   → COMPLETADO y en producción
F4 ✅ Analítica mejorada      → COMPLETADO y en producción
F5 ✅ EDA en descarga          → COMPLETADO y en producción
F6 ✅ Manual ValkamSync        → COMPLETADO y en producción
F7 ✅ LME Spot Scraping        → ALPHA VANTAGE ACTIVO Y FUNCIONANDO (2026-06-05)
       - Fuente: Alpha Vantage (IMF LME Copper/Aluminum, USD/MT, mensual)
       - 26/26 fórmulas históricas resueltas (0 pendientes)
       - Próximos imports: resolve automático post-import vía lme_resolve_formula_prices()
       - Key: ALPHAVANTAGE_API_KEY en .app_config.php del servidor (25 req/día gratis)

═══════════════════════════════════════════════════════════════
GEMINI CONTINGENCIA DE KEY — DEPLOYADO 2026-06-05
  gemini_client.php refactorizado con doble loop: keys (externo) × modelos (interno).
  GEMINI_API_KEY   = key primaria  (AIzaSy...)
  GEMINI_API_KEY_2 = key fallback  (AQ.Ab8...)  — se activa automáticamente al recibir 503/429/500
  Errores que saltan al fallback: gemini_http_503, gemini_http_429, gemini_http_500
  Errores que no saltan (fallan inmediato): 401 auth, 400 bad request, bad_json

PARA COMENZAR LA PRÓXIMA SESIÓN:
  Si Jose tiene una nueva feature → preguntar qué quiere hacer
  Si dice "deploy" → usar el snippet de la sección DEPLOY
═══════════════════════════════════════════════════════════════
```

---

## Metadatos del Checkpoint

| Campo | Valor |
|-------|-------|
| **Fecha última actualización** | 2026-06-04 |
| **Versión** | v14 — Gráfico tendencia interactivo: 5 temporalidades + rango desde/hasta |
| **Estado del repo** | Todo en producción. Cambios F7 sin commitear en local. |
| **Último commit** | `836cf6f` — F7 y bug fixes deployados pero sin git commit aún |
| **Rama activa** | `main` |
| **Desarrollador** | Jose |

---

## Historial de Checkpoints

| Archivo | Fecha | Descripción del estado |
|---------|-------|----------------------|
| `CHECKPOINT.md` (este) | 2026-06-05 | v14 — F7 operativo (Alpha Vantage, 26/26 resueltos), F8 gráfico tendencia, doble key Gemini. Manual v3. |
| *(respaldo v9)* | 2026-06-03 | v9 — F7 LME Scraping implementado. Credenciales configuradas. Deploy completo |
| *(respaldo v8)* | 2026-06-02 | v8 — F7 LME Scraping definido. Prompt de nueva sesión guardado |
| *(respaldo v7)* | 2026-06-02 | v7 — F6 (manual v2) completo. Roadmap inicial F1–F6 100% completado |
| *(respaldo v6)* | 2026-06-02 | v6 — F3 compartir EDA (Imprimir/PDF + Imagen PNG via Canvas) deployado |
| *(respaldo v5)* | 2026-06-02 | v5 — F2 (category badges) + F4 (rango fechas + sparklines) deployados |
| *(respaldo v4)* | 2026-06-02 | v4 — EDA+CSV deployado, bug timezone corregido, teclado PIN |
| *(respaldo v3)* | 2026-06-01 | v3 — auth deployado en producción, ruta servidor corregida |
| *(respaldo v2)* | 2026-06-01 | v2 — auth implementado localmente, deploy pendiente |
| *(respaldo v1)* | 2026-05-29 | Estado inicial — repo limpio, features pendientes sin iniciar |
