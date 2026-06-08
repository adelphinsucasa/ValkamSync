# ValkamSync_Notion

# 📊 ValkamSync — Blueprint del Sistema (v4 · Post-F8 · Repo limpio)

> **Para:** Jose Valenzuela · equipo de desarrollo
**De:** Jose Valenzuela
**Fecha:** 8 de junio de 2026
**Estado:** v4 — F1-F8 todos completados y en producción. Repo commiteado (commit `3db7e40`). Próxima feature: por definir.
**Versión anterior:** v3 · 5 de junio de 2026 (F7 LME Spot + F8 gráfico tendencia interactivo)
>

---

## 👋 Léeme primero — qué es este documento

Este es el **blueprint maestro de ValkamSync**. Consolida dos fuentes:
1. El conocimiento operativo del negocio Valkam Capital y cómo se usan las listas de precios Peony.
2. El código existente en `deploy/` (~4,450 LOC de frontend + API PHP + librerías).

Es el documento de referencia principal. Antes de hacer cualquier cambio radical (nueva feature, refactor mayor, cambio de arquitectura), se actualiza este documento para que el equipo tenga siempre el estado real del sistema.

**Contexto del negocio**: Valkam Capital es una empresa de **inversión y capital en materiales de construcción**. Monitorea regularmente las listas de precios "Peony" — publicaciones periódicas en PDF que contienen precios de materiales por categoría, empresa compradora, y fecha. Este sistema automatiza la recepción, extracción y análisis de esos PDFs para que el equipo tome decisiones de compra/venta con datos actualizados.

**Cómo está organizado**:
1. **Parte A (secciones 1–15)** — cómo funciona el sistema, cada vista y cada flujo.
2. **Parte B (secciones 16–19)** — suposiciones, decisiones cerradas y edge cases.
3. **Parte C (secciones 20–23)** — apuesta de producto, stack y roadmap.

**Audiencia**: la narrativa está en español de negocio para el equipo de Valkam Capital; la parte técnica está dentro de toggles colapsables para el equipo de desarrollo.

---

## 🖱️ Cómo se ve este documento en Notion

Este doc usa 3 elementos interactivos de Notion. Si nunca los has usado:

### 1. Toggles colapsables
A lo largo del doc verás bloques con `▸ Click aquí para abrir →`. Esos son **toggles**: haz click sobre el texto y se despliega el contenido técnico. Las secciones marcadas *"para devs"* están dentro de toggles — no las necesitas para entender el sistema.

### 2. Checkboxes
- `[x]` = decisión cerrada o tarea completada
- `[ ]` = pendiente de confirmar o de implementar

### 3. Comentarios sobre bloques
Pasa el cursor sobre cualquier párrafo → aparece un icono `💬` → escribe tu comentario → queda anclado a ese bloque exacto. Úsalo para señalar qué está mal en vez de mandar mensajes generales.

---

## 📚 Tabla de contenidos

```
🟢 PARTE A — CÓMO FUNCIONA EL SISTEMA
   1.  TL;DR — el resumen en 60 segundos
   2.  Mapa del sistema (arquitectura y módulos)
   3.  Glosario ES ↔ EN
   4.  El viaje de un PDF (la historia completa)
   5.  Actores del sistema (quién hace qué)
   6.  Estados de un archivo Peony (máquina de estados)
   7.  Vista Peony Files — importar y gestionar PDFs
   8.  Vista Peony Prices — explorador de precios
   9.  Vista Analytics — tendencias y comparación
   10. Vista Client List — gestión de compradores
   11. Calculadora de Rentabilidad
   12. PWA y experiencia móvil
   13. Exportación e impresión
   14. Modelo de datos — vista narrativa
   15. Modelo de datos — vista técnica (para devs)

🟡 PARTE B — DECISIONES Y PENDIENTES
   16. Suposiciones del sistema actual
   17. Decisiones técnicas cerradas
   18. 🚨 Edge cases — ¿qué pasa si…?
   19. Features del roadmap — estado al 5 de junio de 2026

🔵 PARTE C — CÓMO LO CONSTRUIMOS
   20. La apuesta del producto
   21. Stack técnico + reglas de arquitectura
   22. FAQ — preguntas frecuentes
   23. Próximos pasos
```

---

# 🟢 PARTE A — CÓMO FUNCIONA EL SISTEMA

---

## 1. ▶ TL;DR — el resumen en 60 segundos

En una frase: **ValkamSync es una PWA interna para Valkam Capital que convierte PDFs de listas de precios en una base de datos analítica, y que además gestiona una cartera de clientes compradores descubiertos en esos datos**.

Más concretamente:
- Recibe archivos PDF de listas de precios (las listas "Peony") periódicamente.
- Los procesa automáticamente con un extractor de 3 backends (sin intervención humana para PDFs estándar).
- Almacena los precios por material, categoría, empresa y fecha para análisis histórico.
- **Resuelve automáticamente precios fórmula** del tipo `75%LMESpot` consultando el precio real del London Metal Exchange (LME) vía Alpha Vantage.
- Permite comparar precios entre períodos, filtrar por categoría, y calcular rentabilidad.
- Incluye un **gráfico interactivo de tendencia** por material con 5 temporalidades (diario, semanal, quincenal, mensual, anual) y rango de fechas personalizable.
- Identifica compradores potenciales en los datos y gestiona el proceso de contacto con un CRM básico.

**Lo que NO es**: no es un e-commerce, no es un sistema de facturación, no es público. Es una herramienta **interna del equipo** de Valkam Capital.

> Esta v3 captura el sistema al 5 de junio de 2026. Todas las features F1–F8 han sido implementadas y deployadas en producción.

---

## 2. 🗺️ Mapa del sistema (arquitectura y módulos)

El sistema tiene una arquitectura simple de **2 capas** más **servicios de soporte**. Todo corre en un solo servidor Apache (HostGator):

```
   ┌────────────────────────────────────────────────────────────────┐
   │                      VALKAMSYNC                                │
   └────────────────────────────────────────────────────────────────┘

   ┌──────────────────────────────────────────────────────────────┐
   │  FRONTEND (PWA)                                              │
   │  deploy/index.html — un solo archivo, ~4,450 LOC             │
   │                                                              │
   │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────┐ ┌───────┐  │
   │  │ Peony    │ │ Peony    │ │Analytics │ │Client│ │Rentab.│  │
   │  │ Files    │ │ Prices   │ │/ Compare │ │ List │ │Calc.  │  │
   │  └──────────┘ └──────────┘ └──────────┘ └──────┘ └───────┘  │
   └───────────────────────┬──────────────────────────────────────┘
                           │ HTTP JSON API
                           ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  BACKEND (PHP 8.1+)                                          │
   │  deploy/api.php — router único, ~310 LOC                     │
   │                                                              │
   │  ┌──────────────────┐  ┌──────────────────────────────────┐  │
   │  │  lib/peony_repo  │  │  lib/peony_parser.php            │  │
   │  │  ~1,650 LOC      │  │  3 backends PDF                  │  │
   │  │  Todas las       │  │  pdftotext bbox → lineprinter    │  │
   │  │  queries a DB    │  │  → Gemini Vision fallback         │  │
   │  └──────────────────┘  └──────────────────────────────────┘  │
   │  ┌──────────────────┐  ┌──────────────────────────────────┐  │
   │  │  lib/lme_client  │  │  lib/gemini_client.php           │  │
   │  │  ~200 LOC        │  │  ~390 LOC                        │  │
   │  │  LME spot prices │  │  Doble key: primaria + fallback  │  │
   │  │  vía Alpha Vant. │  │  Loop keys × modelos             │  │
   │  └──────────────────┘  └──────────────────────────────────┘  │
   └────────────────────┬───────────────────────────────────────┬─┘
                        │                                       │
                        ▼                                       ▼
   ┌─────────────────────────────┐   ┌──────────────────────────────┐
   │  DATABASE                   │   │  SERVICIOS EXTERNOS          │
   │  SQLite (dev Y prod actual) │   │  Google Gemini Vision API    │
   │  MySQL (opcional, no activo)│   │  Alpha Vantage (LME spot)    │
   │  Prefijo: vsync_*           │   │  Cloudflare CDN              │
   │  7 tablas principales       │   │  (cache, SSL, geo-blocking)  │
   └─────────────────────────────┘   └──────────────────────────────┘
```

**Cómo se despliega:**

```
   DEV LOCAL                   PRODUCCIÓN (HostGator)
   ──────────────              ──────────────────────────────────────────
   php -S localhost:8080        https://petit.valkamgm.com/
   SQLite: data/valkamsync.db   gzcapita@192.254.232.58:2222
   Tailwind: bin/tailwindcss    SQLite: ~/petit.valkamgm.com/data/valkamsync.db
   .app_config.php local        Apache 8.1-8.3 (cPanel jailshell)
                                CDN: Cloudflare free tier
```

> ⚠️ **Nota importante**: producción usa **SQLite**, no MySQL. El `DB_PATH` en el `.app_config.php` del servidor apunta a `data/valkamsync.db`. La referencia a MySQL en versiones anteriores del documento era incorrecta — MySQL solo se usa en otras apps del mismo host (Deal Calculator, Lot Sheet Generator), no en ValkamSync.

---

## 3. 📖 Glosario ES ↔ EN

**Por qué este glosario importa**: el negocio habla en español, el código está en inglés. Este puente evita confusiones.

| ES (negocio) | EN (código / BD) | Definición corta |
| --- | --- | --- |
| Lista de precios / Peony | `PeonyFile` | Un PDF con precios de materiales publicado periódicamente |
| Fila de precio | `PeonyPrice` | Una línea de datos extraída del PDF (material + precio + categoría + fecha) |
| Archivo sincronizado | `sync_status = 'synced'` | PDF procesado y sus precios cargados en BD |
| Categoría de material | `category` | Agrupación de materiales (ej: "Cobre", "Aluminio") |
| Base de entrega | `delivery_basis` | Condición de entrega asociada al precio |
| Rango de precio | `price_range` | Formato "1.50–2.00" en el PDF |
| Precio fórmula | `formula_price` | Precio indexado al LME: p.ej. `75%LMESpot` |
| Precio LME resuelto | `lme_resolved = 1` | Precio fórmula con valor calculado real almacenado |
| Precio spot LME | `lme_price` | Precio de referencia del London Metal Exchange (USD/MT) |
| Cliente comprador | `ClientManual` | Empresa compradora descubierta en los datos |
| Estado de contacto | `client_status` | Etapa en el proceso de contacto (pending→contacted) |
| Escaneo de cliente | `ClientScan` | Registro inmutable de una observación sobre un cliente |
| Bootstrap dataset | `peony_prices.json` | Semilla de datos para inicializar la BD offline |
| Parser | `PeonyParser` | Módulo que extrae texto de PDFs |
| Confianza de extracción | `confidence_score` | Porcentaje de fiabilidad de los datos extraídos por IA |
| Vista / Módulo | `view` | Sección de la UI (Peony Files, Prices, Analytics, etc.) |
| Rentabilidad | `profitability` | Cálculo de margen Material → Empresa → Precio de venta |
| Audit log | `vsync_logs` | Registro inmutable de importaciones y eventos clave |

---

## 4. 🗺️ El viaje de un PDF (la historia completa)

Esta sección es **la más importante del doc**. Es el flujo end-to-end desde que llega un PDF hasta que sus datos están disponibles para análisis.

### Narrativa del caso

> El equipo de Valkam Capital recibe por email (o descarga manualmente) el nuevo PDF de precios Peony del mes. El archivo se sube desde la vista **Peony Files**. En segundos, el sistema extrae automáticamente las filas de precios usando `pdftotext`. Si el PDF es estándar, el 100% de las filas se extraen sin intervención humana. Si hay anomalías (PDF escaneado, kerning raro), el sistema escala al backend de IA (Gemini Vision) como último recurso.
>
> Los precios procesados quedan disponibles inmediatamente en la vista **Peony Prices** para exploración. Si algún material tiene precio en formato fórmula (ej. `75%LMESpot`), el sistema **automáticamente resuelve el precio real** consultando el London Metal Exchange (LME) y almacena tanto el precio calculado como el precio spot de referencia.
>
> En **Analytics**, el equipo puede ver el gráfico de tendencia histórica por material en la temporalidad que necesite — diario, semanal, quincenal, mensual o anual — filtrando por rango de fechas personalizado.

### Diagrama del viaje

```
   ① EQUIPO sube PDF desde Vista "Peony Files"
        │
        │  archivo .pdf → servidor
        ▼
   ② SISTEMA registra metadata del archivo
        │  vsync_peony_files: filename, date, status='pending'
        ▼
   ③ PARSER intenta extraer precios (cadena de 3 backends)
        │
        │  Backend 1: pdftotext -bbox-layout (poppler)
        │      ↓ éxito (mayoría de PDFs)
        │  Backend 2: pdftotext -lineprinter (xpdf)
        │      ↓ solo si B1 falla
        │  Backend 3: Gemini Vision API (doble key)
        │      ↓ solo si B1 y B2 fallan
        ▼
   ④ SISTEMA valida y normaliza filas extraídas
        │  · agrupa palabras por coordenada Y (tolerancia 3px)
        │  · snaps a columnas aprendidas del header
        │  · conserva price_raw tal como aparece en el PDF
        │  · asigna row_status: ok / parsed_with_warnings / failed
        ▼
   ⑤ SISTEMA inserta en vsync_peony_prices
        │  · N filas con: material, precio, categoría, fecha, empresa
        │  · actualiza vsync_peony_files.sync_status = 'synced'
        ▼
   ⑥ RESOLUCIÓN LME (automática, best-effort)
        │  · detecta filas con price_raw tipo "75%LMESpot"
        │  · consulta precio LME Cash del mes (Alpha Vantage / cache)
        │  · calcula precio neto = spot × (pct/100)
        │  · guarda en price_num (calculado) y lme_price (referencia)
        │  · marca lme_resolved = 1
        ▼
   ⑦ DATOS disponibles en Peony Prices + Analytics
        │
        ▼
   ⑧ EQUIPO analiza, filtra y compara precios
        │  · Vista Materiales: modal con gráfico interactivo
        │  · 5 temporalidades + rango desde/hasta personalizable
        ▼
   ⑨ (OPCIONAL) EQUIPO registra compradores en Client List
        └── si hay datos de empresas en el PDF, se capturan manualmente
            o con ayuda de Gemini Vision desde la vista Client List
```

### Datos de ejemplo a lo largo del viaje

| Paso | Ejemplo | Estado |
| --- | --- | --- |
| ② | `20260601CBE.pdf` subido | `pending` |
| ③ | Gemini PDF backend (no pdftotext en HostGator) | — |
| ④ | 68 filas válidas extraídas | `synced` |
| ⑥ | `E-Scrap-Mxd: 75%LMESpot` → LME-CU Mar-26 $12,528/MT → **$9,396/MT** | `lme_resolved=1` |
| ⑧ | Gráfico mensual de E-Scrap-Mxd, abr-jun 2026 | — |

---

## 5. 🏊 Actores del sistema (quién hace qué)

```
        EQUIPO VALKAM      │    SISTEMA          │  SERVICIOS EXTERNOS
        (usuario interno)  │    (automático)     │  (Gemini, Alpha Vantage, etc.)
   ────────────────────────┼─────────────────────┼────────────────────────────
   ┌──────────────────┐    │                     │
   │ Sube PDF         │────┼──► ┌─────────────┐  │
   └──────────────────┘    │    │ Registra    │  │
                           │    │ metadata    │  │
                           │    └──────┬──────┘  │
                           │           │         │
                           │    ┌──────▼──────┐  │
                           │    │ Extrae con  │──┼──► Gemini Vision
                           │    │ B1 → B2 → B3│  │    (si B1+B2 fallan)
                           │    └──────┬──────┘  │
                           │           │         │
                           │    ┌──────▼──────┐  │
                           │    │ Resuelve    │──┼──► Alpha Vantage
                           │    │ precios LME │  │    (LME Cash USD/MT)
                           │    └──────┬──────┘  │
                           │           │         │
                           │    ┌──────▼──────┐  │
                           │    │ Inserta en  │  │
                           │    │ BD          │  │
                           │    └──────┬──────┘  │
   ┌──────────────────┐    │           │         │
   │ Explora precios  │◄───┼───────────┘         │
   │ Ve gráfico trend.│    │                     │
   │ Filtra materiales│    │                     │
   └──────────────────┘    │                     │
                           │                     │
   ┌──────────────────┐    │                     │
   │ Gestiona clientes│────┼──► ┌─────────────┐  │
   │ Registra scans   │    │    │ Actualiza   │  │
   │ Cambia estado    │    │    │ estado de   │  │
   └──────────────────┘    │    │ contacto    │  │
                           │    └─────────────┘  │
```

**Roles actuales**: el sistema es de **acceso único** (un equipo, sin autenticación diferenciada). Login con contraseña + PIN implementado (F1). Ver sección 19 y credenciales en sección 17-D6.

---

## 6. 🚦 Estados de un Archivo Peony (máquina de estados)

```
                  ┌──────────────┐
   archivo subido │              │    parser finaliza OK
   ──────────────►│  ⏳ PENDING  │──────────────────────────►  ✅ SYNCED
                  │  (procesando)│
                  └──────┬───────┘
                         │
                         │ parser falla parcialmente
                         ▼
                  ┌──────────────┐
                  │ ⚠ WARNED     │  datos extraídos con advertencias
                  │              │  filas fallidas = registradas
                  └──────────────┘

                         │ error total
                         ▼
                  ┌──────────────┐
                  │ ❌ FAILED    │  ninguna fila extraída
                  │              │  requiere revisión manual
                  └──────────────┘
```

### Tabla de estados

| Estado | Quién lo pone | ¿Qué significa? | Acción del equipo |
| --- | --- | --- | --- |
| ⏳ PENDING | Sistema (al subir) | En proceso de extracción | Esperar (segundos) |
| ✅ SYNCED | Sistema | Todas las filas extraídas | Listo para explorar |
| ⚠ WARNED | Sistema | Extracción parcial | Revisar filas fallidas |
| ❌ FAILED | Sistema | Error total de extracción | Revisar PDF o reintentar con B3 (Gemini) |

---

## 7. 📂 Vista Peony Files — importar y gestionar PDFs

Esta es la **pantalla de entrada**: donde el equipo sube nuevos PDFs y monitorea su estado de procesamiento.

### Mockup de la vista

```
┌─────────────────────────────────────────────────────────────────────┐
│  ValkamSync · Peony Files                            [+ Subir PDF]  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Filtros:  [Hoy]  [Esta semana]  [Este mes]  [Todo]                 │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 📄 20260601CBE.pdf          2026-06-01    ✅ SYNCED          │   │
│  │    68 precios · Backend: gemini-pdf · LME: 2 resueltos      │   │
│  │    [ EDA ] [ Ver precios ] [ Descargar CSV ]                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 📄 20260527CBE.pdf          2026-05-27    ✅ SYNCED          │   │
│  │    70 precios · Backend: gemini-pdf · LME: 1 resuelto       │   │
│  │    [ EDA ] [ Ver precios ] [ Descargar CSV ]                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  Dashboard rápido:                                                  │
│  📅 Último sync: hace 4 días                                        │
│  📊 Total archivos: 24 · Total precios en BD: 1,680+               │
│  📆 Rango de fechas: Marzo 2026 — Junio 2026                        │
└─────────────────────────────────────────────────────────────────────┘
```

### Lo que hace especial esta vista

1. **Procesamiento automático**: subir el PDF es todo lo que hace el equipo. El parser extrae los datos sin intervención.
2. **EDA por archivo**: cada tarjeta tiene un botón "EDA" con score de calidad, estadísticas descriptivas, detección de anomalías y contador de precios LME resueltos.
3. **Resolución LME automática**: inmediatamente después de importar, el sistema detecta precios `%LMESpot` y los resuelve con el precio LME Cash oficial.
4. **3 backends transparentes**: el equipo no necesita saber cuál backend funcionó — el sistema elige el mejor automáticamente.

---

## 8. 💰 Vista Peony Prices — explorador de precios

Esta es la **vista de datos**: donde el equipo explora los precios extraídos de todos los PDFs.

### Mockup de la vista

```
┌─────────────────────────────────────────────────────────────────────┐
│  ValkamSync · Peony Prices                                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Filtros:                                                           │
│  Archivo: [Jun 2026 ▼]  Categoría: [SS/HiTemp ▼]  🔍 [buscar...  ] │
│                                                                     │
│  ┌────────────────┬───────────────────┬──────────┬───────────────┐  │
│  │ Categoría      │ Material          │ Empresa  │ Precio        │  │
│  ├────────────────┼───────────────────┼──────────┼───────────────┤  │
│  │ ⬜ SS/HiTemp   │ E-Scrap-Mxd       │ Transm.  │ 🔵 LME        │  │
│  │                │                   │          │ $9,396.53/MT  │  │
│  │                │                   │          │ (75%LME·ref   │  │
│  │                │                   │          │  $12,528/MT)  │  │
│  │ ⬜ SS/HiTemp   │ Solder Dross-LF   │ Transm.  │ 🔵 LME        │  │
│  │                │                   │          │ $10,022.97/MT │  │
│  │ 🟡 Copper      │ Bare Bright Cu    │ HCL Corp │ 4.50-4.80/lb  │  │
│  └────────────────┴───────────────────┴──────────┴───────────────┘  │
│                                                                     │
│  Mostrando 25 de 68 resultados  ◄ 1 2 3 ►                           │
└─────────────────────────────────────────────────────────────────────┘
```

### Funcionalidades clave

| Función | Descripción |
| --- | --- |
| Filtro por archivo | Ver precios de un período específico |
| Filtro por categoría | Agrupar por tipo de material (Cobre, Aluminio, SS/HiTemp) |
| Búsqueda libre | Buscar por nombre de material o empresa |
| Paginación | Navegar grandes volúmenes (~70 filas por archivo típico) |
| Badge LME | Filas con precio fórmula resuelto muestran badge azul `🔵 LME` + precio calculado y referencia spot |
| Badges de categoría | Azul = ALUMINUM · Ámbar = COPPER/BRASS · Gris = SS/HITEMP/OTHER |

---

## 9. 📈 Vista Analytics — tendencias y comparación

Esta vista permite **comparar precios entre períodos** y analizar la tendencia histórica de cualquier material con un **gráfico interactivo completo**.

### Gráfico de tendencia interactivo (F8)

Al hacer clic en cualquier material, el modal muestra un gráfico de tendencia con controles completos:

```
┌─────────────────────────────────────────────────────────────────────┐
│  E-Scrap-Mxd · SS/HITEMP/OTHER                                      │
│  Máx: $12,528  Mín: $9,200  Prom: $10,891                          │
├─────────────────────────────────────────────────────────────────────┤
│  TENDENCIA DE PRECIO                            18 puntos           │
│  [ Diario ] [ Semanal ] [Quincenal] [ Mensual ] [ Anual ]           │
│  Desde [____________]  Hasta [____________]  [Aplicar]  [✕]        │
│                                                                     │
│  12,500 ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─                      │
│  11,250 ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─                      │
│  10,000 ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─                      │
│         ┌──────────────────────────────────────────                 │
│         │ ╲  ╱╲  /╲    /╲  ___/                                    │
│         │  ╲╱  ╲╱  ╲__╱  ╲╱                    ● 9,396.53         │
│  Apr  May   Jun                                                     │
│  S15 2026                                      ↓ 9,396 (-3.1%)     │
│                                                Jun 2026             │
├─────────────────────────────────────────────────────────────────────┤
│  CONTACTOS QUE LO OFRECEN                                           │
│  ...                                                                │
└─────────────────────────────────────────────────────────────────────┘
```

### Temporalidades disponibles

| Intervalo | Agrupación SQL | Etiqueta en gráfico | Ejemplo |
| --- | --- | --- | --- |
| **Diario** | `file_date` (sin agrupación) | `2026-04-14` | Cada valor importado |
| **Semanal** | Semana ISO del año | `S15 2026` | Promedio de la semana 15 |
| **Quincenal** | 1-15 / 16-31 del mes | `1-15 Abr 2026` | Promedio de la quincena |
| **Mensual** | Mes calendario | `Abr 2026` | Promedio mensual |
| **Anual** | Año calendario | `2026` | Promedio anual |

**Banda min-max**: en vistas agregadas (semanal, quincenal, mensual, anual), cuando hay múltiples registros dentro del período, el gráfico muestra una banda semitransparente entre el precio mínimo y máximo del período. Esto da visibilidad a la dispersión de precios.

> **Implementado (F4 ✅ + F8 ✅)**: filtro de rango desde/hasta disponible en la vista Analytics y en el gráfico de tendencia del modal de material.

---

## 10. 👥 Vista Client List — gestión de compradores

Esta vista gestiona la **cartera de empresas compradoras** descubiertas en los datos Peony, con un CRM básico de seguimiento de contacto.

### Máquina de estados del cliente

```
                ┌──────────────┐
   cliente       │              │
   descubierto  │  ⚪ PENDING  │  recién registrado, sin acción
   ─────────────►│              │
                └──────┬───────┘
                       │
                       │ se agenda llamada/contacto
                       ▼
                ┌──────────────┐
                │ 📅 SCHEDULED │  contacto programado con fecha
                └──────┬───────┘
                       │
                       │ contacto realizado
                       ▼
                ┌──────────────┐
                │ ✅ CONTACTED │  cliente contactado, resultado registrado
                └──────┬───────┘
                       │
                       │ no viable / sin interés
                       ▼
                ┌──────────────┐
                │ 🗑 DISCARDED │  descartado del pipeline activo
                └──────────────┘
```

### Mockup de la vista

```
┌─────────────────────────────────────────────────────────────────────┐
│  ValkamSync · Client List                       [+ Nuevo cliente]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Filtros: [Todos ▼]  Estado: [Pending ▼]  🔍 [buscar empresa...]    │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 🏢 Transmetco                ⚪ PENDING                        │  │
│  │    📞 260-224-5817  ·  Descubierto: jun 2026                   │  │
│  │    Material: E-Scrap-Mxd  ·  Precio ref: $9,396/MT (LME)      │  │
│  │    [ Ver historial ] [ Agendar ] [ Descartar ]                │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 🏢 Ryan Gledhill / Transmetco  📅 SCHEDULED                    │  │
│  │    📞 260-224-5817  ·  Próxima llamada: 15 Jun 2026            │  │
│  │    [ Ver historial ] [ Registrar contacto ] [ Descartar ]     │  │
│  └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### Cómo se captura la info del cliente

Hay 3 métodos:
1. **Manual**: el equipo llena los campos directamente.
2. **Gemini Vision**: el equipo sube una foto o imagen con datos del comprador → la IA extrae campos automáticamente (teléfono, empresa, contacto, material, precio, fecha). La IA usa doble key con fallback automático.
3. **Desde precios**: la vista puede pre-llenar empresa y material desde los datos de `vsync_peony_prices`.

**Audit trail de clientes**: cada observación (scan) queda registrada en `vsync_client_scans` como un log append-only. Nadie puede borrar el historial.

---

## 11. 🧮 Calculadora de Rentabilidad

Permite calcular el margen de una operación de compra/venta a partir de los precios Peony.

### Flujo de cálculo

```
   PASO 1: Material        PASO 2: Empresa/Comprador   PASO 3: Precio venta
   ─────────────────       ──────────────────────────  ─────────────────────
   Seleccionar material    Seleccionar empresa          Ingresar precio de
   de Peony Prices         de la cartera                venta objetivo
         │                        │                           │
         └──────────┬─────────────┘                           │
                    ▼                                         │
           Precio Peony actual                                │
           (costo de referencia)                              │
                    └───────────────────────────┬─────────────┘
                                                ▼
                                        MARGEN = Venta - Costo Peony
                                        % Rentabilidad = Margen / Costo
```

---

## 12. 📱 PWA y experiencia móvil

ValkamSync es una **Progressive Web App instalable** en cualquier dispositivo.

### Características PWA

- **Instalable**: aparece como app nativa en móvil y escritorio (icono en pantalla de inicio)
- **Offline parcial**: la app carga y permite explorar datos pre-cargados sin conexión
- **Dataset bootstrap**: incluye un JSON embebido con datos iniciales para inicializar la BD offline
- **Mobile-first**: diseño responsive adaptado a pantallas pequeñas
- **Sticky mobile CTA**: botones de acción fijos en la parte inferior en móvil

### Compatibilidad

| Dispositivo | Soporte |
| --- | --- |
| Desktop (Chrome/Edge/Firefox) | ✅ Completo |
| iOS Safari (iPhone/iPad) | ✅ Completo (instalable) |
| Android Chrome | ✅ Completo (instalable) |
| Impresión / exportación PDF | ✅ Soporte de impresión CSS |

---

## 13. 🖨️ Exportación e impresión

Cualquier vista puede imprimirse o exportarse como PDF usando la función nativa del navegador.

- Los elementos de navegación se ocultan automáticamente al imprimir (CSS `@media print`)
- Las tablas se formatean para papel A4
- Los filtros aplicados se respetan en la exportación
- El modal EDA tiene opción "Imprimir/PDF" que genera un reporte standalone listo para imprimir
- El modal EDA tiene opción "Imagen PNG" que genera una tarjeta de resumen 800×H px en Canvas 2D

---

## 14. 🗄️ Modelo de datos — vista narrativa

Esto es **cómo se conectan las piezas en la base de datos**, explicado sin jerga técnica.

```
              ┌─────────────────────┐
              │  vsync_peony_files  │  Un PDF subido
              │                     │  filename, date, sync_status
              └──────────┬──────────┘
                         │ contiene
                         │ (1 a ~70 filas por archivo)
                         ▼
              ┌─────────────────────┐
              │  vsync_peony_prices │  Una fila de precio extraída
              │                     │  material, category, price, company, date
              │                     │  + lme_resolved, lme_price (si es fórmula)
              └─────────────────────┘

              ┌─────────────────────┐
              │  vsync_lme_cache    │  Precios spot LME por metal y fecha
              │                     │  metal (LME-CU/LME-AL), date, cash_usd
              │                     │  source ('alphavantage')
              └─────────────────────┘

              ┌─────────────────────┐
              │vsync_clients_manual │  Una empresa compradora
              │                     │  company, phone, email, material
              └──────────┬──────────┘
                         │ registra historial
                         ▼
              ┌─────────────────────┐
              │ vsync_client_scans  │  Una observación sobre el cliente
              │  (append-only)      │  texto libre, fecha, quién la hizo
              └──────────┬──────────┘
                         │ estado actual
                         ▼
              ┌─────────────────────┐
              │vsync_client_status  │  Estado de contacto actual
              │                     │  pending / scheduled / contacted / discarded
              └─────────────────────┘
```

### Lo más importante de este diagrama

1. **`vsync_peony_files`** es el padre — todo precio tiene un archivo origen.
2. **`vsync_peony_prices`** es la tabla más grande — aquí están los datos analíticos. Incluye `lme_resolved` y `lme_price` para precios fórmula resueltos.
3. **`vsync_lme_cache`** almacena precios spot LME históricos para evitar llamadas repetidas al API.
4. **`vsync_client_scans`** es append-only — el historial de un cliente no se puede borrar.
5. **`vsync_client_status`** es la capa de estado — se puede actualizar; los scans no.

### Comparación: antes (manual) vs después (ValkamSync)

| Proceso | Antes (manual) | Con ValkamSync |
| --- | --- | --- |
| Revisar nueva lista de precios | Abrir PDF, hojear páginas, anotar | Subir PDF → datos en BD en segundos |
| Interpretar precio "75%LMESpot" | Buscar precio LME manualmente, calcular | Automático: precio resuelto y guardado |
| Comparar vs mes anterior | Abrir 2 PDFs, comparar manualmente | Vista Analytics, comparación automática |
| Ver tendencia de un material | — | Modal material: gráfico con 5 temporalidades |
| Encontrar precio de un material | Ctrl+F en PDF | Búsqueda + filtros instantáneos |
| Seguimiento de compradores | Hoja de cálculo o notas | Vista Client List con estados |
| Calcular margen | Calculadora o Excel | Calculadora de Rentabilidad integrada |

---

## 15. 🔧 Modelo de datos — vista técnica (para devs)

> 🖱️ **Esto es un dropdown — haz click en el bloque de abajo para expandirlo.**
> 👀 **No lo necesitas para entender el sistema.** Está aquí para los developers.
>
- Click aquí para abrir → Schema completo de las tablas vsync_*

    ### Tablas activas en producción (SQLite)

    ```sql
    -- =========================================================
    -- 1. ARCHIVOS PEONY (PDFs importados)
    -- =========================================================
    CREATE TABLE vsync_peony_files (
      id              INTEGER PRIMARY KEY AUTOINCREMENT,
      filename        TEXT NOT NULL,
      file_date       DATE NOT NULL,
      email_ts        BIGINT,
      sha256          TEXT(64) NOT NULL UNIQUE,
      size            INT NOT NULL,
      row_count       INT DEFAULT 0,
      parser_version  TEXT,
      imported_at     TEXT DEFAULT CURRENT_TIMESTAMP,
      sync_status     TEXT DEFAULT 'pending',
      synced_at       TEXT,
      notes           TEXT
    );

    -- =========================================================
    -- 2. PRECIOS EXTRAÍDOS
    -- =========================================================
    CREATE TABLE vsync_peony_prices (
      id              INTEGER PRIMARY KEY AUTOINCREMENT,
      file_id         INT NOT NULL,
      file_date       DATE NOT NULL,
      category        TEXT(32) NOT NULL,       -- ALUMINUM | COPPER/BRASS | SS/HITEMP/OTHER
      material        TEXT(200) NOT NULL,
      price_raw       TEXT(100) NOT NULL,       -- "1.50" | "75%LMESpot" | "0.10-0.60"
      price_num       DECIMAL(12,4),            -- valor calculado (o porcentaje si no resuelto)
      price_unit      TEXT(20),
      delivery_basis  TEXT(100),
      company         TEXT(150),
      buyer           TEXT(150),
      phone           TEXT(40),
      row_status      TEXT(20) DEFAULT 'ok',
      lme_resolved    TINYINT(1) DEFAULT 0,     -- 1 si price_num fue calculado vía LME
      lme_price       DECIMAL(14,4),            -- precio spot LME Cash USD/MT de referencia
      created_at      TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- =========================================================
    -- 3. CACHE DE PRECIOS LME (Alpha Vantage)
    -- =========================================================
    CREATE TABLE vsync_lme_cache (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      metal       TEXT(10) NOT NULL,    -- 'LME-CU' | 'LME-AL'
      price_date  DATE NOT NULL,
      cash_usd    DECIMAL(14,4) NOT NULL,   -- USD por tonelada métrica
      source      TEXT(20) DEFAULT 'alphavantage',
      fetched_at  TEXT DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(metal, price_date)
    );

    -- =========================================================
    -- 4. AUTENTICACIÓN
    -- =========================================================
    CREATE TABLE vsync_auth (
      id             INTEGER PRIMARY KEY AUTOINCREMENT,
      username       TEXT(50) NOT NULL UNIQUE,
      password_hash  TEXT(255) NOT NULL,  -- bcrypt de 'Valkam2026!'
      pin_hash       TEXT(255) NOT NULL,  -- bcrypt de '11223344'
      updated_at     TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE vsync_sessions (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      token       TEXT(64) NOT NULL UNIQUE,
      remember    TINYINT(1) DEFAULT 0,
      expires_at  TEXT,
      created_at  TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- =========================================================
    -- 5. CLIENTES / COMPRADORES
    -- =========================================================
    CREATE TABLE vsync_clients_manual (
      id            INTEGER PRIMARY KEY AUTOINCREMENT,
      phone         TEXT(40) NOT NULL UNIQUE,
      company       TEXT(150),
      buyer         TEXT(150),
      source        TEXT(20) DEFAULT 'scan',
      first_scan_at TEXT DEFAULT CURRENT_TIMESTAMP,
      last_scan_at  TEXT DEFAULT CURRENT_TIMESTAMP,
      scan_count    INT DEFAULT 0
    );

    -- =========================================================
    -- 6. OBSERVACIONES DE CLIENTES (append-only)
    -- =========================================================
    CREATE TABLE vsync_client_scans (
      id                INTEGER PRIMARY KEY AUTOINCREMENT,
      phone             TEXT(40) NOT NULL,
      scan_date         DATE NOT NULL,
      material          TEXT(200),
      price_raw         TEXT(100),
      price_num         DECIMAL(12,4),
      notes             TEXT,
      company_snapshot  TEXT(150),
      buyer_snapshot    TEXT(150),
      image_sha256      TEXT(64),
      confidence        TEXT(20),
      created_at        TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- =========================================================
    -- 7. ESTADO DE CONTACTO
    -- =========================================================
    CREATE TABLE vsync_client_status (
      phone              TEXT(40) NOT NULL PRIMARY KEY,
      status             TEXT(20) NOT NULL DEFAULT 'pending',
      next_contact_date  DATE,
      last_contact_at    TEXT,
      note_summary       TEXT(200),
      updated_at         TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE vsync_client_notes (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      phone      TEXT(40) NOT NULL,
      note       TEXT NOT NULL,
      author     TEXT(80),
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- =========================================================
    -- 8. LOGS DE IMPORTACIÓN
    -- =========================================================
    CREATE TABLE vsync_logs (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      event      TEXT(50) NOT NULL,
      status     TEXT(20) NOT NULL,
      details    TEXT,
      file_count INT DEFAULT 0,
      row_count  INT DEFAULT 0,
      fecha      TEXT DEFAULT CURRENT_TIMESTAMP
    );
    ```

    ### API Actions disponibles

    | Endpoint | Método | Descripción |
    | --- | --- | --- |
    | `?action=auth_login` | POST | Login con contraseña o PIN |
    | `?action=auth_logout` | POST | Cerrar sesión |
    | `?action=auth_check` | GET | Verificar sesión activa |
    | `?action=auth_pin_change` | POST | Cambiar PIN |
    | `?action=peony_dashboard` | GET | Resumen: último sync, conteos, rango de fechas |
    | `?action=peony_files` | GET | Listado de archivos con filtro día/semana/mes/todo |
    | `?action=peony_prices` | GET | Precios paginados con filtros categoría/fecha/búsqueda |
    | `?action=peony_materials` | GET | Vista agrupada por material con estadísticas |
    | `?action=peony_material_detail` | GET | Stats + timeline con `interval`, `date_from`, `date_to` |
    | `?action=peony_material_contacts` | GET | Contactos que ofrecen un material |
    | `?action=peony_compare` | GET | Comparación de precios entre dos períodos |
    | `?action=peony_validate` | POST | Transiciones de estado de sync |
    | `?action=peony_eda` | GET | Análisis EDA completo de un archivo |
    | `?action=peony_export_csv` | GET | Descarga CSV de un archivo |
    | `?action=peony_lme_resolve_all` | POST | Re-procesa todos los precios fórmula pendientes |
    | `?action=peony_available_files` | GET | PDFs disponibles en el maildir |
    | `?action=peony_import_files` | POST | Importar PDFs seleccionados del maildir |
    | `?action=peony_clients` | GET | Listado de clientes con filtros y CRM quick-filters |
    | `?action=peony_clients_stats` | GET | Conteos por estado para los quick-filters |
    | `?action=peony_client_status_update` | POST | Upsert estado de cliente |
    | `?action=peony_client_detail` | GET | Historial de precios de un cliente |
    | `?action=peony_client_notes` | GET | Notas de conversación de un cliente |
    | `?action=peony_client_note_create` | POST | Agregar nota a un cliente |
    | `?action=scan_extract_image` | POST | OCR de imagen con Gemini Vision |
    | `?action=scan_lookup` | GET | Lookup de cliente por teléfono |
    | `?action=scan_save` | POST | Guardar escaneo de cliente |
    | `?action=health` | GET | Health check del servidor |

---

# 🟡 PARTE B — DECISIONES Y PENDIENTES

---

## 16. ✅ Suposiciones del sistema actual

Suposiciones sobre cómo funciona el sistema. ✅ = confirmada en el código · ⚠️ = a validar

| ID | Suposición | Estado |
| --- | --- | --- |
| S1 | Cada `PeonyPrice` pertenece a un único `PeonyFile` | ✅ Confirmado en schema |
| S2 | Un archivo PDF puede tener N precios (~70 filas típico, máx 7000) | ✅ Confirmado (diseño paginado) |
| S3 | El parser usa siempre B1 primero, solo escala si falla | ✅ Confirmado en `peony_parser.php` |
| S4 | Gemini API es opcional — la app funciona sin ella | ✅ Confirmado (graceful degradation) |
| S5 | Las credenciales nunca van en el código | ✅ Confirmado (`.app_config.php` git-ignored) |
| S6 | Los scans de clientes nunca se borran (append-only) | ✅ Confirmado en diseño |
| S7 | El mismo archivo PDF no se puede importar dos veces | ✅ Implementado por SHA256 — si ya existe, sobrescribe (reimporta) |
| S8 | El sistema requiere autenticación | ✅ Login con contraseña + PIN. Sesiones con cookie HttpOnly `vsync_tok`. "Recordar 30 días". |
| S9 | El prefijo `vsync_*` coexiste con la BD `gzcapita_valkam` de HostGator | ✅ Confirmado (aunque ValkamSync usa SQLite, no MySQL) |
| S10 | El CSS se compila localmente antes de deployar | ✅ Confirmado (`bin/tailwindcss.exe`) |
| S11 | La producción usa SQLite, no MySQL | ✅ Confirmado — `.app_config.php` del servidor define `DB_DRIVER=sqlite` y `DB_PATH=data/valkamsync.db` |
| S12 | Los precios `%LMESpot` se resuelven automáticamente post-import | ✅ Implementado — `lme_resolve_formula_prices()` corre inmediatamente tras cada import |
| S13 | Los precios LME se cachean en DB para evitar llamadas repetidas | ✅ `vsync_lme_cache` — cada metal+fecha se guarda una sola vez |
| S14 | Gemini tiene contingencia de clave secundaria | ✅ `GEMINI_API_KEY_2` activa — se usa automáticamente si la primaria devuelve 503/429/500 |

---

## 17. ✅ Decisiones técnicas cerradas

### D1 — ¿Por qué PHP y no Node.js?
✅ **HostGator shared hosting**. El servidor tiene PHP 8.1-8.3 disponible sin configuración. Node.js requiere procesos permanentes que cPanel jailshell no permite. PHP es la opción correcta para este entorno.

### D2 — ¿Por qué un solo `index.html` en vez de componentes?
✅ **Simplicidad de deploy**. Un solo archivo no tiene dependencias de build para el frontend. Se sube con `scp` y funciona. Sin webpack, sin npm en prod. El trade-off es un archivo grande (~4,450 LOC) que se compensa con buen naming y comentarios de sección.

### D3 — ¿Por qué SQLite y no MySQL en producción?
✅ **Simplicidad operativa en HostGator**. El `.app_config.php` real del servidor usa `DB_DRIVER=sqlite`. La base de datos SQLite está en `~/petit.valkamgm.com/data/valkamsync.db`. MySQL de HostGator (`gzcapita_valkam`) se reserva para otras apps (Deal Calculator, Lot Sheet Generator). PDO abstraction garantiza que el mismo código PHP funcione en ambas BDs sin cambios.

### D4 — ¿Por qué 3 backends de PDF?
✅ **Cobertura máxima de formatos**. Los PDFs Peony varían en calidad y estructura. `bbox-layout` funciona para PDFs con texto nativo y coordenadas. `lineprinter` es fallback para PDFs con columnas fijas. Gemini es el último recurso para PDFs escaneados o cuando pdftotext no está disponible en el servidor (caso HostGator shared).

### D5 — ¿Por qué Tailwind standalone binary?
✅ **Sin Node.js en ningún lado**. `bin/tailwindcss.exe` compila el CSS sin instalar nada. Ideal para un proyecto con un solo desarrollador y sin pipeline de CI/CD complejo.

### D6 — Credenciales del sistema (acceso y auth)

> ⚠️ **Cambiar en producción** antes de hacer el sistema accesible externamente.

| Credencial | Valor actual | Dónde se almacena |
| --- | --- | --- |
| **Usuario** | `admin` | `vsync_auth.username` |
| **Contraseña** | `Valkam2026!` | `vsync_auth.password_hash` (bcrypt) |
| **PIN numérico** | `11223344` | `vsync_auth.pin_hash` (bcrypt) |
| **URL producción** | `https://petit.valkamgm.com/` | — |
| **SSH servidor** | `gzcapita@192.254.232.58:2222` | `~/.ssh/id_rsa_Jose` |
| **SSH passphrase** | `Mushroom2026!` | Clave privada local |

El PIN se usa en la pantalla de login (tab "PIN") y soporta teclado físico (útil en tablets/kiosks). La sesión puede durar 30 días ("Recordar dispositivo") o solo hasta cerrar el navegador.

### D7 — ¿Por qué Alpha Vantage para precios LME y no NASDAQ Data Link / MetalRadar?
✅ **Accesibilidad desde HostGator**. Se evaluaron 3 proveedores:
- **MetalRadar**: token JWT que expira, nuevo endpoint retorna 401. Descartado.
- **NASDAQ Data Link** (Quandl): protegido por Incapsula WAF — bloquea peticiones cURL sin JavaScript desde cualquier servidor. Descartado.
- **Alpha Vantage**: accesible desde HostGator ✅, devuelve precios LME oficiales del FMI en USD/MT ✅, plan gratuito (25 req/día) ✅, API key permanente sin expiración ✅.

Los precios son promedios mensuales LME (promedio de todos los días del mes). Para fines prácticos del trading de chatarra, el promedio mensual es estándar en la industria.

### D8 — ¿Por qué doble key Gemini y no solo una?
✅ **Alta demanda de Gemini**. Los PDFs Peony son procesados con Gemini Vision cuando pdftotext no está disponible (HostGator shared). Los errores 503 "high demand" y 429 "rate limit" son frecuentes en horas pico. La segunda key (de segunda cuenta Google) tiene cuota independiente. El sistema cambia de key automáticamente — el usuario no nota ninguna diferencia.

**Lógica de fallback** (doble loop keys × modelos):
```
Por cada KEY (key#1 → key#2):
  Por cada MODELO (gemini-2.5-flash → 2.0-flash → 2.5-flash-lite):
    ├── 200 OK           → retorna resultado
    ├── 503/429/500      → salta a siguiente KEY (sobrecarga/cuota)
    ├── 404 modelo       → salta a siguiente MODELO (misma key)
    └── 401/400 bad req  → falla inmediato (no reintenta)
```

---

## 18. 🚨 Edge cases — ¿qué pasa si…?

```
   ESCENARIO                          MANEJO ACTUAL
   ─────────────────────              ──────────────────────────────────
   🚨 PDF en blanco o corrupto        Parser falla → status='failed'
                                      Error logueado en vsync_logs
                                      UI muestra mensaje de error

   🚨 PDF escaneado (imagen)          B1 y B2 fallan → escalada a B3
                                      (Gemini Vision, doble key)
                                      Si Gemini falla → status='failed'

   🚨 Mismo archivo subido 2 veces    SHA256 detecta duplicado
                                      Sistema sobrescribe (re-importa)
                                      con datos frescos

   🚨 Gemini API sobrecargada (503)   Key primaria falla →
                                      key secundaria activada auto.
                                      Si ambas fallan → status='failed'
                                      Reintentar manualmente más tarde

   🚨 Precio en formato fórmula       price_raw guardado tal cual
   (ej: "75%LMESpot", "80%LMESpot")   lme_resolve_formula_prices() corre
                                      post-import y resuelve al precio real
                                      Si AV falla → formula_price en EDA
                                      sin crash ni pérdida de datos

   🚨 Alpha Vantage sin datos         lme_get_spot() devuelve null
   para ese mes (mes en curso)        Sistema usa el mes más reciente
                                      disponible como fallback
                                      → precio estimado, no exacto

   🚨 Alpha Vantage cuota agotada     lme_resolve devuelve skipped=N
   (25 req/día free tier)             Precios fórmula quedan sin resolver
                                      Llamar peony_lme_resolve_all al
                                      día siguiente se resuelve solo

   🚨 HostGator cae                   App en modo offline parcial
                                      Datos pre-cacheados accesibles
                                      Al volver en línea, re-sincroniza

   🚨 Precio en formato inusual       row_status='parsed_with_warnings'
   (ej: "NQ", "–", "consultar")       Fila registrada con price_raw
                                      price_num = NULL

   🚨 BD SQLite llena / lenta         Verificar tamaño con:
                                      sqlite3 ~/petit.valkamgm.com/
                                        data/valkamsync.db ".dbinfo"
                                      Los archivos viejos se pueden
                                      archivar cambiando sync_status

   🚨 Sesión expirada durante uso     API devuelve 401
                                      fetch interceptor en index.html
                                      redirige a /login.html
```

---

## 19. 🗂️ Features del roadmap — estado al 5 de junio de 2026

Todas las features F1–F8 han sido implementadas y deployadas en producción.

| # | Feature | Estado | Descripción / Notas de implementación |
| --- | --- | --- | --- |
| **F1** | **Login con PIN** | ✅ Completado | Auth completa: contraseña + PIN numérico. Cookie HttpOnly `vsync_tok`, sesiones en `vsync_sessions`, "Recordar 30 días". Teclado físico para PIN. **Credenciales: admin / Valkam2026! / PIN 11223344**. Fix timezone gmdate() aplicado (prod = UTC). |
| **F2** | **Colores en categorías** | ✅ Completado | Función `categoryChip()` con badges de color: azul = ALUMINUM, ámbar = COPPER/BRASS, gris = SS/HITEMP/OTHER. Aplicado en vista Materiales y Tendencias. |
| **F3** | **Compartir reporte EDA** | ✅ Completado | Botón "Compartir" en modal EDA con dropdown: "Imprimir/PDF" (ventana standalone) y "Imagen PNG" (canvas 800px retina con score, stats, anomalías). Sin cambios en backend. |
| **F4** | **Analítica mejorada** | ✅ Completado | Filtro rango Desde/Hasta en todas las vistas. Backend: `peony_build_date_where()` con filter='range'. |
| **F5** | **EDA al descargar archivos** | ✅ Completado | Modal EDA por archivo: score de calidad 0–100%, estadísticas descriptivas (min/max/prom/med/std/Q1/Q3/IQR), detección de anomalías (formula_price, iqr_outlier, historical_outlier, zero_or_negative, lme_resolved). Botón CSV en el mismo modal. |
| **F6** | **Manual ValkamSync** | ✅ Completado | Este documento — actualizado a v3 el 5 de junio de 2026. |
| **F7** | **LME Spot Scraping** | ✅ Completado | Resolución automática de precios `{N}%LMESpot`. Fuente: Alpha Vantage (IMF LME Cash, USD/MT, promedio mensual). Tablas: `vsync_lme_cache` + columnas `lme_resolved`/`lme_price` en `vsync_peony_prices`. Cacheado en DB — solo 1 llamada HTTP por metal por ejecución gracias a `static $cache` en PHP. 26/26 precios históricos resueltos. |
| **F8** | **Gráfico de tendencia interactivo** | ✅ Completado | Modal de material reemplaza sparkline 48px por gráfico SVG full-width con: 5 temporalidades (Diario/Semanal/Quincenal/Mensual/Anual), rango Desde/Hasta, gridlines Y, etiquetas X rotadas, banda min-max en vistas agregadas, delta %. Backend: `peony_material_detail` acepta `interval`, `date_from`, `date_to`; GROUP BY SQL por intervalo, compatible SQLite y MySQL. |

### Contingencias implementadas (sin número de feature)

| Componente | Mejora | Estado |
| --- | --- | --- |
| **Gemini doble key** | `GEMINI_API_KEY_2` como fallback automático de `GEMINI_API_KEY`. Se activa al recibir 503/429/500. Lógica: loop keys × modelos. | ✅ En producción |

---

# 🔵 PARTE C — CÓMO LO CONSTRUIMOS

---

## 20. 🚀 La apuesta del producto

**ValkamSync** no es un ERP genérico ni un sistema de gestión de inventario. Es una herramienta **vertical y específica** para el trabajo exacto que hace Valkam Capital:

> **Tagline**: *ValkamSync convierte las listas de precios Peony en decisiones de negocio en segundos, no en horas.*

### Los 3 pilares del valor

1. **Extracción automática**: nadie tiene que leer PDFs ni copiar precios a mano. El sistema lo hace en segundos.
2. **Memoria histórica**: todos los precios de todos los meses están en la misma base de datos. La comparación es instantánea.
3. **Pipeline de clientes**: los compradores descubiertos en los datos se convierten en un pipeline de ventas gestionado.

### Lo que hace ValkamSync que Excel no puede

```
   EXCEL                                VALKAMSYNC
   ─────────────────────                ──────────────────────────────
   Abrir PDF → copiar datos             Subir PDF → extracción automática
   (30-60 minutos por archivo)          (segundos)

   "75%LMESpot" = calcular a mano      Precio resuelto automáticamente
   con precio LME del día               al precio spot LME real

   Comparar 2 archivos = abrir 2 tabs   Analytics: selección de período
   y buscar manualmente                 → comparación instantánea

   Historial = N archivos .xlsx         Historial = una sola BD consultable
   sin búsqueda unificada               con búsqueda y filtros

   Tendencia = graficar manualmente     Modal material: gráfico interactivo
   en Excel                             5 temporalidades + rango fechas

   Pipeline de clientes = notas         Client List con estados,
   en celdas o WhatsApp                 historial inmutable, CRM básico
```

---

## 21. 🛠️ Stack técnico + reglas de arquitectura

> 🖱️ **Esto es un dropdown — haz click en el bloque de abajo para expandirlo.**
> 👀 **Para el equipo de desarrollo.**
>
- Click aquí para abrir → Stack completo y reglas de arquitectura

    ### Stack

    ```
    Frontend       HTML5 + CSS + JavaScript vanilla (ES6+)
                   Tailwind CSS 4 (standalone binary bin/tailwindcss.exe, sin Node.js)
                   PWA (Service Worker + Web App Manifest)
                   Un único archivo: deploy/index.html (~4,450 LOC)

    Backend        PHP 8.1+ (Apache, HostGator shared hosting)
                   Router único: deploy/api.php (~310 LOC)
                   Sin Composer, sin frameworks PHP

    Base de datos  SQLite (dev y PRODUCCIÓN actual)
                   MySQL 5.7+ soportado (mismo PDO code, no activo en ValkamSync)
                   PDO con prepared statements (cero interpolación directa)
                   Prefijo de tablas: vsync_*
                   8 tablas: peony_files, peony_prices, lme_cache, auth,
                             sessions, clients_manual, client_scans,
                             client_status, client_notes, logs

    PDF Parsing    pdftotext -bbox-layout (poppler-utils) → Backend 1
                   pdftotext -lineprinter (xpdf) → Backend 2
                   Google Gemini Vision API → Backend 3 (fallback, doble key)
                   Archivo: lib/peony_parser.php (~322 LOC)

    IA / Vision    Google Gemini API — lib/gemini_client.php (~390 LOC)
                   Modelos: 2.5-flash → 2.0-flash → 2.5-flash-lite → flash-latest
                   Doble clave: GEMINI_API_KEY (primaria) + GEMINI_API_KEY_2 (fallback)
                   Fallback de key: 503/429/500 → siguiente key automáticamente
                   Solo server-side (clave NUNCA expuesta al cliente)

    LME Spot       Alpha Vantage API — lib/lme_client.php (~200 LOC)
                   Fuente: IMF Primary Commodity Prices (LME Cash USD/MT mensual)
                   Clave: ALPHAVANTAGE_API_KEY en .app_config.php (25 req/día gratis)
                   Optimización: static PHP cache → 1 HTTP call por metal por ejecución
                   Metales: LME-CU (COPPER/BRASS + SS/HITEMP/OTHER) · LME-AL (ALUMINUM)

    Hosting        HostGator shared hosting (cPanel jailshell)
                   Apache 8.1-8.3, memoria 512M
                   SSH: gzcapita@192.254.232.58:2222 (clave: ~/.ssh/id_rsa_Jose)
                   SQLite DB: ~/petit.valkamgm.com/data/valkamsync.db

    CDN            Cloudflare free tier
                   Full SSL, caché de páginas, geo-blocking

    Deploy         SCP manual por archivo o tar+gzip completo
                   Ver snippets en Doc/CHECKPOINT.md (sección DEPLOY)
                   Sin CI/CD automatizado
    ```

    ### 9 Reglas de arquitectura — NO violar

    ```
    R1  Sin Composer ni Node.js en producción.
        El servidor HostGator es cPanel jailshell — no hay npm ni composer.

    R2  Prefijo vsync_* en TODAS las tablas.
        La BD gzcapita_valkam en HostGator ya tiene otras tablas.
        Sin prefijo = colisión garantizada.

    R3  PDO prepared statements en TODAS las queries.
        Nunca: "SELECT * FROM t WHERE id = " . $id
        Siempre: $stmt = $pdo->prepare("SELECT * FROM t WHERE id = ?")

    R4  CSS compilado localmente antes de deployar.
        Ejecutar: bin/tailwindcss.exe -i src/css/input.css -o deploy/css/style.css
        Nunca commitear cambios de CSS sin re-compilar.

    R5  Gemini es SIEMPRE opcional.
        Todo flujo del sistema funciona sin API key de Gemini.
        Si Gemini falla → graceful degradation, no error 500.

    R6  Un solo index.html — la app entera en un archivo.
        No dividir en múltiples archivos HTML.
        Organizar el JS con secciones bien comentadas dentro del mismo archivo.

    R7  Credenciales SOLO en .app_config.php (git-ignored).
        Nunca en index.html, api.php, ni en ningún archivo commiteado.
        Ver .app_config.php.example como template.

    R8  Sin rutas hardcodeadas en PHP.
        Usar dirname(__FILE__) o __DIR__ para rutas relativas.
        HostGator puede cambiar el path real tras migraciones.

    R9  Fechas hacia BD: usar gmdate() no date().
        PHP TZ del servidor = America/Chicago (UTC-5).
        SQLite/MySQL comparan en UTC. gmdate() garantiza coherencia.
    ```

---

## 22. ❓ FAQ — preguntas frecuentes

### "¿Por qué no usamos un framework moderno (React, Vue)?"
Porque el servidor es HostGator shared hosting. El deploy debe ser subir archivos con `scp` — sin build pipeline, sin npm, sin node_modules. Vanilla JS + Tailwind compilado localmente es la opción correcta para este entorno.

### "¿Por qué el frontend entero está en un solo archivo de 4,450 líneas?"
Por la misma razón de arriba: deploy simple. Un archivo HTML se sube, se cachea en Cloudflare, y funciona. Múltiples archivos JS/CSS separados añaden complejidad sin beneficio real para este tamaño de app.

### "¿Podemos añadir usuarios y roles?"
El feature F1 (Login con PIN) implementa autenticación básica de un solo usuario (admin). En v1 es suficiente para el equipo de Valkam Capital. Para múltiples usuarios con roles, se necesitaría ampliar `vsync_auth` con una tabla de usuarios y roles — posible en el futuro.

### "¿Qué pasa si Gemini cambia su API o se vuelve de pago?"
El sistema funciona sin Gemini. Los backends B1 y B2 cubren los PDFs con texto nativo. Gemini solo se usa como último recurso (cuando pdftotext no está disponible). Con la doble key implementada, la disponibilidad es mucho más alta.

### "¿Qué pasa si Alpha Vantage cambia su API?"
Solo afecta a la resolución de precios fórmula (`%LMESpot`). El resto del sistema sigue funcionando. Los precios ya resueltos quedan guardados en `vsync_lme_cache` y en `vsync_peony_prices.lme_price`. Cambiar de proveedor requiere solo modificar `lib/lme_client.php` (la función `lme_fetch_alphavantage`).

### "¿Por qué los precios LME son promedios mensuales y no precios del día exacto?"
Alpha Vantage publica el precio promedio mensual del LME (fuente: FMI). El precio diario requiere acceso a feeds profesionales (LMELive, Bloomberg, Refinitiv) que son de pago y bloqueados desde shared hosting por WAFs. Para el trading de chatarra metálica, el promedio mensual LME es estándar contractual en la industria.

### "¿Podemos importar los datos históricos que ya tenemos en Excel?"
Sí — el sistema acepta datos vía `peony_import_files` desde el maildir de HostGator. Los datos de Excel se pueden convertir a JSON con el formato esperado para importación directa.

---

## 23. 🔁 Próximos pasos

```
   29 May 2026  → v1 del blueprint + CHECKPOINT.md creados           ✅ hecho
   01 Jun 2026  → F1 (Login PIN) + F2 (Colores) deployados           ✅ hecho
   02 Jun 2026  → F3 (Compartir EDA) + F4 (Analítica) deployados     ✅ hecho
   02 Jun 2026  → F5 (EDA+CSV) + F6 (Manual v2) deployados           ✅ hecho
   03 Jun 2026  → F7 (LME Spot) código deployado                     ✅ hecho
   04 Jun 2026  → F7 migrado a Alpha Vantage, 26 fórmulas resueltas  ✅ hecho
   05 Jun 2026  → Gemini doble key + F8 (gráfico interactivo)        ✅ hecho
   05 Jun 2026  → Manual v3 actualizado (este documento)             ✅ hecho
   Próximo      → Iniciar siguiente ciclo de features con Jose
                  (nuevos reportes, mejoras UX, o integraciones)
   Ongoing      → Actualizar CHECKPOINT.md antes de cada cambio
                  radical siguiendo el protocolo de la sección
                  "Protocolo para Próximo Claude"
```

### Protocolo de actualización de documentos

Antes de **cualquier cambio radical** al sistema:

```
   PASO 1: Copiar Doc/CHECKPOINT.md como:
           Doc/CHECKPOINT_YYYY-MM-DD_vN.md
           (preserva el estado anterior)

   PASO 2: Actualizar Doc/CHECKPOINT.md con:
           · Qué cambio se va a hacer
           · Por qué (requerimiento que lo originó)
           · Qué queda pendiente o inconcluso

   PASO 3: Actualizar este documento (ValkamSync_Notion.md)
           con las secciones afectadas por el cambio

   PASO 4: Hacer el cambio en el código

   PASO 5: Commitear todo junto:
           git add Doc/ deploy/
           git commit -m "feat: [descripción del cambio]"
```

### Historial de versiones de este documento

| Versión | Fecha | Cambios principales |
| --- | --- | --- |
| **v3** | **2026-06-05** | **F7 (LME Spot / Alpha Vantage), F8 (gráfico interactivo 5 temporalidades), doble key Gemini, corrección SQLite en prod, credenciales documentadas, schema actualizado, 7 nuevas API actions documentadas, nuevas decisiones D6–D8, suposiciones S11–S14.** |
| v2 | 2026-06-02 | F1–F6 completados y deployados. Secciones 8, 16 (S8), 19, 23 actualizadas. |
| v1 | 2026-05-29 | Documento inicial — sistema existente documentado, 6 features pendientes. |

---

> 📌 **Versión 3 — F1–F8 completados.** Captura el estado al 5 de junio de 2026.
> 📌 Producción usa **SQLite** en `~/petit.valkamgm.com/data/valkamsync.db`.
> 📌 Credenciales actuales: `admin` / `Valkam2026!` / PIN `11223344`.
> 📌 El sistema está en https://petit.valkamgm.com/ con todas las features activas.
