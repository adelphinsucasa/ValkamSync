# ValkamCapital_Notion

# 🏭 Valkam Capital — Blueprint del Sistema (v2 · Final)

> **Para:** Javier Valenzuela Kamiche · equipo de desarrollo
**De:** Emanuel Valenzuela
**Fecha:** 18 de mayo de 2026
**Estado:** versión final — incorpora el feedback de Javier de la llamada del 8 de mayo
**Versión anterior:** v1 (blueprint enviado el 5 de mayo para revisión)
> 

---

## 👋 Léeme primero — qué es este documento

Esta es la **versión final del blueprint** del sistema de Valkam Capital. Consolida dos fuentes:
1. El **Zoom del 1 de mayo** (33 min, 62 imágenes) donde Javier explicó el sistema modelo.
2. La **llamada de feedback del 8 de mayo** (1 h) donde Javier revisó la v1 punto por punto.

Todas las correcciones de Javier ya están aplicadas. Las 12 decisiones bloqueantes están **cerradas** (sección 17). Lo que sigue marcado como pendiente requiere la confirmación de la dirección (el papá), no de Javier.

**Contexto del negocio**: Valkam Capital es una empresa de **trading internacional de chatarra de metal y cobre** (metal scrap / commodities). Compra y vende commodities entre países (Colombia, EEUU, Perú, etc.). Este sistema gestiona toda la operativa: solicitudes, documentación, embarques, contratación y facturación.

**Cómo está organizado**:
1. **Parte A (secciones 1-15)** — cómo funciona el sistema, validado por Javier.
2. **Parte B (secciones 16-19)** — suposiciones, decisiones cerradas y edge cases.
3. **Parte C (secciones 20-23)** — la apuesta de producto y el plan de construcción.

**Audiencia**: la narrativa está en español de negocio para Javier y la dirección; la parte técnica está dentro de toggles colapsables para el equipo de desarrollo.

---

## 🖱️ Cómo se ve este documento en Notion (léelo, importa)

Este doc usa 3 elementos interactivos de Notion. Si nunca los has usado:

### 1. Toggles colapsables (dropdowns)

A lo largo del doc verás bloques con un **triángulo gris** a la izquierda y un texto que dice *“Click aquí para abrir → …”*. Esos son **toggles**: si haces click sobre el triángulo o el texto, se abren y muestran más contenido. Si no haces click, quedan colapsados.

Se ven así (ejemplo):

```
▸  Click aquí para abrir → Schema completo en Mermaid + DBML
```

Cuando los abres se vuelven `▾` y aparece todo el contenido debajo.

> **Importante**: las secciones marcadas como *“para devs”* están dentro de toggles. **No necesitas abrirlas tú** — quedan ahí para los developers cuando empecemos a construir. Pero si tienes curiosidad técnica, ábrelas.
> 

### 2. Checkboxes `[x]`

A lo largo del doc verás listas con cuadritos. En esta versión final, los que están marcados `[x]` reflejan lo que Javier ya decidió en la llamada del 8 de mayo. Los pocos que siguen vacíos `[ ]` están a la espera de la dirección (el papá), no de Javier — están claramente señalados.

### 3. Comentarios sobre bloques específicos

Esta es la parte más importante:

- **Pasa el cursor sobre cualquier párrafo, tabla o diagrama**
- Aparece un **icono `💬`** a la derecha (puede ser pequeño)
- Click ahí → escribes tu comentario → click `Send`
- Tu comentario queda anclado a ese bloque exacto
- Yo recibo notificación por email cada vez que comentes
- Puedo responderte en el mismo hilo

**Por qué esto importa**: si me dices “en la sección del wizard hay algo mal” yo no sé qué exactamente. Si comentas sobre el párrafo exacto, sé qué corregir. **Comenta donde el problema está, no me mandes WhatsApp general**.

---

## 📚 Tabla de contenidos

```
🟢 PARTE A — LO QUE ENTENDÍ (tú validas)
   1.  TL;DR — el resumen en 60 segundos
   2.  Mapa de las 3 piezas y cómo se conectan
   3.  Glosario ES ↔ EN
   4.  El viaje de un caso (la historia completa)
   5.  Quién hace qué (Swimlane)
   6.  Estados del caso (4, no 7)
   7.  La WEB pública — el área de clientes
   8.  El PORTAL del cliente — qué ve cuando se loguea
   9.  El INBOX unificado — la pantalla principal del equipo
   10. Workflow de documentos
   11. Casos asociados (mismo embarque)
   12. Plantillas + notificaciones automáticas
   13. Reportes y dashboards
   14. Modelo de datos — vista narrativa (para ti)
   15. Modelo de datos — vista técnica (para devs)

🟡 PARTE B — SUPOSICIONES, DECISIONES Y EDGE CASES
   16. Suposiciones validadas (S1-S15)
   17. Decisiones cerradas con Javier (B1-B12)
   18. 🚨 Edge cases — ¿qué pasa si...?
   19. Preguntas resueltas (consolidado por sección)

🔵 PARTE C — CÓMO LO CONSTRUIMOS
   20. La apuesta de producto de Valkam Capital
   21. Stack técnico + roadmap 12 semanas
   22. FAQ — preguntas que probablemente tengas
   23. Próximos pasos
```

---

# 🟢 PARTE A — LO QUE ENTENDÍ

---

## 1. ▶ TL;DR — el resumen en 60 segundos

En una frase: **el sistema de Valkam Capital es un CRM moderno para gestionar la operativa de trading internacional de chatarra de metal y cobre** (compra/venta de commodities, embarques, contenedores). Toma la lógica del sistema modelo que mostró Javier, pero **simplificado y con IA** donde aporta valor.

Más concretamente:
- **3 piezas conectadas**: una **Web pública / área de clientes** por donde entran las solicitudes, un **Workspace** donde trabaja el equipo, y un **Backoffice** que contrata, factura y cobra.
- Todo gira alrededor del **Caso** (lo que el sistema modelo llama “ID” — esos números largos como 25388033 que vimos en las imágenes; al cliente se le muestran solo los últimos 4 dígitos).
- Simplificamos el modelo: **de 7+3 estados a 4**, **de ~14 entidades a 8**, **de 6 pantallas por trámite a 3 taps**.
- Javier validó este blueprint el 8 de mayo. Las 12 decisiones bloqueantes están **cerradas** (sección 17).

> Esta versión es la base para que el equipo de desarrollo empiece a construir la versión 0.
> 

---

## 2. 🗺️ Mapa de las 3 piezas y cómo se conectan

Cuando me explicaste el sistema modelo, lo dividiste así: *“comenzamos por la web, después entramos al CRM, y al final el sistema comercial”*. Yo vi exactamente lo mismo en las imágenes. Lo bauticé como 3 piezas que **comparten una sola base de datos** pero tienen interfaces distintas:

```
   ┌────────────────────────────────────────────────────────────────────┐
   │                      EL SISTEMA VALKAM CAPITAL                       │
   └────────────────────────────────────────────────────────────────────┘

   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
   │   1. WEB         │   │   2. WORKSPACE   │   │   3. BACKOFFICE  │
   │   PÚBLICA        │   │   (interno)      │   │   (interno)      │
   │                  │   │                  │   │                  │
   │  Para CLIENTES   │   │  Para el equipo  │   │  Para FINANZAS   │
   │  externos        │   │  equipo          │   │  y contabilidad  │
   │                  │   │                  │   │                  │
   │  ┌────────────┐  │   │  ┌────────────┐  │   │  ┌────────────┐  │
   │  │ Landing    │  │   │  │ Inbox      │  │   │  │ Contratos  │  │
   │  │ Wizard de  │──┼──►│  │ unificado  │  │   │  │ Facturas   │  │
   │  │ solicitud  │  │   │  │ Casos      │──┼──►│  │ Cartera    │  │
   │  │ Portal     │◄─┼───┼──│ Documentos │  │   │  │ Cobros     │  │
   │  │ cliente    │  │   │  │ Activities │  │   │  │ Reportes   │  │
   │  └────────────┘  │   │  └────────────┘  │   │  └────────────┘  │
   │                  │   │                  │   │                  │
   │  Tech: Next.js   │   │  Tech: Next.js   │   │  Tech: Next.js   │
   │  + Supabase Auth │   │  + Supabase RLS  │   │  + Stripe        │
   └────────┬─────────┘   └────────┬─────────┘   └────────┬─────────┘
            │                      │                      │
            └──────────────┬───────┴──────────────────────┘
                           │
                           ▼
                ┌────────────────────┐
                │  POSTGRES + AI     │
                │  (una sola DB)     │
                │  · multi-tenant    │
                │  · pgvector        │
                │  · audit log       │
                └────────────────────┘
```

**Cómo viaja un trámite por las 3 piezas (ejemplo real con datos como los tuyos):**

```
1. El cliente entra al área de clientes en valkamcapital.com
   → registra una solicitud (seguimiento de embarque, cambio de BL, etc.)

2. WEB envía al WORKSPACE
   → Caso #VLK-2026-0421 aparece en TRIAGE en el inbox del equipo
   → Junto con: email del contacto, llamada perdida, upload de BL

3. El equipo trabaja el caso en WORKSPACE
   → Inicia sesión de atención (la "personita" pasa de verde a naranja)
   → Pide docs (cert de origen, factura comercial, packing list)
   → Cliente sube por portal o WhatsApp
   → El equipo valida/rechaza con motivo
   → Caso pasa de TRIAGE → ACTIVA → ESPERA → ACTIVA → RESUELTA

4. Caso resuelto pasa al BACKOFFICE
   → Se genera contrato + factura
   → Stripe gestiona el cobro

5. BACKOFFICE notifica al cliente vía WEB
   → Cliente paga en su portal
   → Recibe factura por email + WhatsApp
   → El caso queda en histórico (consultable; pasa a solo lectura
     30 días después de la entrega del contenedor)
```

**Lo que cambió vs el sistema modelo:**
- En el modelo, las 3 piezas eran 3 apps separadas con datos duplicados. Aquí es una sola DB con 3 fachadas.
- El “sistema comercial” del modelo (la app Windows legacy) lo reemplazamos por un Backoffice web moderno.

> ✅ **Validado por Javier (8 may):** el mapa de 3 piezas es correcto. El Backoffice de facturación/cobros es parte del mismo sistema (lo opera la persona de back office).
> 

---

## 3. 📖 Glosario ES ↔︎ EN

**Por qué este glosario importa**: el feedback de negocio se da en español, pero el equipo de desarrollo programa en inglés. Este glosario es el “puente” — cuando el negocio dice `Caso`, el dev escribe `Case` en el código. Cuando el negocio dice `Sede`, el dev escribe `Tenant (kind=location)`.

| ES (lenguaje de negocio) | EN (código) | Definición corta |
| --- | --- | --- |
| Caso / ID | `Case` | Una solicitud o incidencia del cliente |
| Sesión de atención | `AttentionSession` | Bloque de trabajo de un usuario sobre uno o varios casos |
| Personita verde / naranja | `session_indicator` | Indicador visual de la sesión de atención: **verde** = sin atención iniciada (solo consulta); **naranja** = atención en curso (todo queda registrado con el usuario) |
| Filial | `Tenant (kind=filial)` | Valkam-Holding, Valkam-Colombia, Valkam-USA (lo que tú llamas “Sociedad”) |
| Sede | `Tenant (kind=location)` | Ciudad+país (Medellín, Lima, Houston) — lo que tú llamas “Explotación” |
| Punto de servicio | `ServicePoint` | Dirección física del servicio (ej: UR.ZAGALETA 20-S3-A2) |
| Contrato | `Contract` | Acuerdo comercial activo |
| Cliente | `Customer` | Empresa o persona contratante (ej: Construcciones Bonifacio) |
| Contacto autorizado | `AuthorizedContact` | Persona habilitada por el cliente (ej: Juan de finanzas) |
| Grupo de resolución | `ResolutionGroup` | Departamento que resuelve (finanzas, contratación, navieras, etc.) |
| Plantilla de mensaje | `MessageTemplate` | Mensaje predefinido con variables (ej: “Contigo07-Cambio titular”) |
| Visión 360 | `Customer360` | Búsqueda global del cliente |
| Espacio virtual / OV | `CustomerPortal` | Donde el cliente sube documentos |
| Casos asociados | `LinkedCases` | Casos vinculados al mismo embarque |
| Actividad | `Activity` | Cualquier evento del timeline (mensaje, llamada, nota) |
| Solicitud de documento | `DocumentRequest` | Pedido de un documento concreto al cliente |
| Estado del documento | `DocumentStatus` | pendiente / subido / validado / rechazado |
| Inbox unificado | `UnifiedInbox` | Feed de email + WhatsApp + llamadas + portal |
| Embarque | `Shipment` | Operación logística que agrupa varios casos |
| Bulk action | `BulkAction` | Acción aplicada a varios casos a la vez (asignar, asociar, cerrar) |
| Audit log | `AuditLog` | Registro inmutable de quién hizo qué y cuándo |
| Webhook | `Webhook` | URL que el sistema golpea cuando pasa un evento (ej. Stripe paga) |
| SLA | `SLA` | Service Level Agreement — tiempo máximo de respuesta esperado |

**Términos del modelo que ELIMINAMOS porque no aplican a Valkam Capital**:

| Término viejo | Por qué se elimina |
| --- | --- |
| Plan 12 gotas | Específico de utility de agua |
| Cuadro de caja por operación | Específico de utility, lo reemplaza el módulo de Backoffice |
| Señas de cobro | Específico de banca española, lo reemplaza IBAN normal |
| Telelectura, alcantarillado | Específico de utility de agua |
| Canon de desalación | Específico de utility de agua |

> ✅ **Validado por Javier (8 may):**
- Renombrar “Sociedad” → “Filial” y “Explotación” → “Sede”: **aprobado**.
- La **“personita verde/naranja” SE MANTIENE**. No es un gimmick: es la marca visual de si la sesión de atención está iniciada. Sin iniciar atención (personita verde) se puede consultar, pero no modificar ni gestionar; al iniciar atención se pone naranja y todo queda registrado con el usuario. Ya está reincorporada al glosario de arriba.
> 

---

## 4. 🗺️ El viaje de un Caso (la historia completa)

Esta sección es **la más importante del doc**. Es el flujo end-to-end de un caso desde que el cliente entra a la web hasta que se cierra. Si esta historia está mal, todo lo demás está mal.

Te lo cuento como historia narrada, paso por paso, y luego te lo muestro en diagrama.

### Narrativa del caso

> Marta López, de Construcciones Bonifacio, gestiona un embarque de cobre de Medellín a Houston. Entra al área de clientes de valkamcapital.com, registra su solicitud, y al terminar **el sistema le genera el ID #VLK-2026-0421** (al cliente se le muestran solo los últimos 4 dígitos). Marta recibe un email y un WhatsApp con el link a su portal y un resumen del proceso que acaba de iniciar.
> 
> 
> Ese mismo segundo, **el caso aparece en el inbox del equipo** con estado `TRIAGE`. El gestor abre el caso, **inicia la sesión de atención** (la “personita” pasa de verde a naranja), y el caso pasa a `ACTIVA`.
> 
> Revisa el caso y necesita más documentación: cert. de origen, factura comercial, packing list. Le manda a Marta el enlace a su área de clientes con una **plantilla** (“VLK-02 Solicitud de documentos pendientes”) por email + WhatsApp. El caso pasa a `ESPERA` con `waiting_on=cliente`.
> 
> Marta entra a su portal con su teléfono + PIN, sube los 3 documentos. El sistema le muestra al gestor un **resumen y un nivel de confianza** de cada documento (apoyo de IA — ver sección 10). El caso pasa a `ESPERA` con `waiting_on=documento` mientras el sistema procesa.
> 
> El gestor revisa los 3 docs en el caso. **El gestor siempre valida o rechaza cada documento** — el nivel de confianza es solo una ayuda visual, no aprueba nada solo. Aprueba los 3.
> 
> Caso pasa de `ESPERA` → `ACTIVA`. Le manda a Marta la cotización con la plantilla VLK-06. Marta acepta. **Inicia el proceso de contratación**.
> 
> El BACKOFFICE genera el contrato y la factura, gestiona el cobro y notifica al cliente. El caso pasa a `RESUELTA`. **30 días después de que el contenedor se entrega al cliente, el caso pasa a solo lectura** y queda en histórico.
> 

### Diagrama del viaje

```
                    ┌──────────────────────────────────┐
                    │         EL VIAJE DE UN CASO      │
                    └──────────────────────────────────┘

  ① CLIENTE registra solicitud en el área de clientes
       │
       │ rellena los datos de la solicitud (sin documentos todavía)
       ▼
  ② WEB genera trámite con ID #VLK-2026-0421
       │  ← lo dispara el CLIENTE; el sistema solo reacciona
       │ envía email + WhatsApp con link al portal + resumen
       │ envía notificación al workspace
       ▼
  ③ WORKSPACE recibe el caso → estado: TRIAGE
       │
       │ aparece en el inbox unificado (sin asignar)
       ▼
  ④ EL GESTOR inicia SESIÓN DE ATENCIÓN (personita naranja)
       │
       │ todo lo que haga ahora queda registrado en activities
       │ Caso pasa a estado: ACTIVA, assignee_id = gestor
       ▼
  ⑤ Si falta info → estado: ESPERA, waiting_on=cliente
       │                                          ┌─────────────┐
       │ envía plantilla "VLK-02 Solicitud docs"  │ recordatorio│
       ▼                                          │ configurable│
  ⑥ CLIENTE entra al PORTAL                       │ por sensibi-│
       │ (login con teléfono + PIN)               │ lidad doc.  │
       │ sube documentos                          └─────────────┘
       ▼
  ⑦ SISTEMA procesa el doc → estado: ESPERA, waiting_on=documento
       │
       │ IA muestra resumen + nivel de confianza (solo ayuda visual)
       │ → en el MVP NO auto-aprueba: el gestor siempre revisa
       ▼
  ⑧ EL GESTOR valida o rechaza cada documento
       │                                          ┌─────────────┐
       │ rechazo → email automático (VLK-04)      │  vuelve a   │
       │                                          │     ⑤       │
       ▼                                          └─────────────┘
  ⑨ Estado → ACTIVA → cierra la parte documental
       │
       │ inicia el proceso de contratación
       ▼
  ⑩ BACKOFFICE crea CONTRATO → genera FACTURA → cobro
       │
       ▼
  ⑪ Cliente paga → caso pasa a RESUELTA
       │
       ▼
  ⑫ 30 días tras la entrega → caso en SOLO LECTURA, histórico
```

### Datos de ejemplo a lo largo del viaje

| Paso | Caso ejemplo | Estado | Quién actúa |
| --- | --- | --- | --- |
| ② | Caso #25388033 — CONSTRUCCIONES BONIFACIO | TRIAGE | Cliente (vía web) |
| ④ | Sesión de atención iniciada por javier.valenzuela | ACTIVA | Gestor |
| ⑤ | Pide cert. origen, factura comercial, packing list | ESPERA | Gestor |
| ⑥ | Marta sube cert_origen.pdf desde portal | ESPERA | Cliente |
| ⑦ | El sistema procesa el doc y muestra el resumen IA | ESPERA | Sistema |
| ⑧ | El gestor revisa y aprueba el doc | ACTIVA | Gestor |
| ⑪ | El cliente paga la factura | RESUELTA | Cliente + Backoffice |

> ✅ **Validado por Javier (8 may):** cualquier acción de **modificación o gestión** del caso requiere sesión de atención iniciada (para que quede registrado quién hizo qué — incluso quién abrió el caso). **Solo consultar/buscar información** no requiere iniciar atención.
> 

---

## 5. 🏊 Quién hace qué (Swimlane)

Cuando me explicaste el flujo, mencionaste 4 actores: el cliente externo, tú/el usuario interno, el sistema (que dispara cosas automáticas), y “otro departamento” (cuando tú reasignas). Aquí los pongo en carriles paralelos para que veas quién hace qué momento por momento:

```
        CLIENTE       │  USUARIO      │   SISTEMA      │ DEPARTAMENTO
                      │  (Javier)     │   (automático) │  (otro grupo)
  ────────────────────┼───────────────┼────────────────┼──────────────
  ┌──────────────┐    │               │                │
  │ envía        │────┼──────────────►│ ┌───────────┐  │
  │ solicitud    │    │               │ │ genera ID │  │
  └──────────────┘    │               │ └─────┬─────┘  │
                      │               │       │        │
                      │  ┌─────────┐  │◄──────┘        │
                      │  │ inicia  │  │                │
                      │  │ sesión  │  │                │
                      │  └────┬────┘  │                │
                      │       │       │                │
                      │  ┌────▼────┐  │                │
                      │  │ pide    │──┼───►┌────────┐  │
                      │  │ docs    │  │    │ envía  │  │
                      │  └─────────┘  │    │ email  │  │
  ┌──────────────┐    │               │    └────────┘  │
  │ sube docs    │────┼──────────────►│                │
  └──────────────┘    │  ┌─────────┐  │ ┌───────────┐  │
                      │  │ valida  │◄─┼─│ procesa   │  │
                      │  │ /rechaza│  │ │ doc + IA  │  │
                      │  └────┬────┘  │ └───────────┘  │
                      │       │       │                │
                      │  ┌────▼────┐  │                │  ┌──────────┐
                      │  │ ¿tema   │  │                │  │ reasigna │
                      │  │mío?  NO │──┼────────────────┼─►│ a otro   │
                      │  └────┬────┘  │                │  │ depto    │
                      │       │       │                │  └────┬─────┘
                      │       │ SÍ    │                │       │
                      │       │       │   resuelve     │  ┌────▼─────┐
                      │  ┌────▼────┐  │   o devuelve   │  │ el depto │
                      │  │ resuelve│◄─┼────────────────┼──│ resuelve │
                      │  │ y cierra│  │                │  │ o devuel-│
                      │  └────┬────┘  │                │  │ ve caso  │
                      │       │       │                │  └──────────┘
                      │       ▼       │                │
                      │  ┌─────────┐  │                │
                      │  │ pasa a  │  │                │
                      │  │BACKOFFICE  │                │
                      │  └─────────┘  │                │
```

**Lo que está claro:**
- Cliente y usuario nunca se ven directamente — todo pasa por el sistema.
- El sistema dispara emails y recordatorios sin intervención humana.
- Otro departamento solo aparece cuando el gestor reasigna explícitamente.

> ✅ **Validado por Javier (8 may):**
- Cuando reasignas a otro departamento, **el otro departamento pasa a ser el responsable** — para eso lo reasignaste. (Una persona puede asignarse el caso a sí misma, reasignarlo, o quitar el responsable para que quede libre.)
- El otro departamento **puede devolver el caso** al gestor original indicándole que es él quien debe resolverlo, o resolverlo ellos directamente. Por eso el diagrama lleva la flecha de retorno “resuelve o devuelve”.
- **Casos resueltos → Backoffice**: al cerrar la parte documental, el caso pasa al Backoffice. Idea de Javier: que el sistema distribuya automáticamente los casos cerrados al usuario de Backoffice con menor carga, para que nunca queden casos sin dueño.
> 

---

## 6. 🚦 Estados del Caso (4, no 7)

En tu sistema modelo conté **7 estados de caso** + 3 sub-estados. Cuando los analicé, me di cuenta que la mitad eran metadata disfrazada de estado. Los CRMs modernos (Linear, Plain.com, HubSpot Service Hub) usan 3-4 estados con metadata para los matices. Te propongo bajar a 4:

### Diagrama de máquina de estados (cómo se mueve un caso)

```
                     ┌──────────────┐
   cliente envía     │              │     usuario asigna
   solicitud  ──────►│  🆕 TRIAGE   │────────────────┐
                     │  (sin dueño) │                │
                     └──────────────┘                │
                                                     ▼
   ┌─────────────────────────────────────┐   ┌──────────────┐
   │                                     │◄──│  🟡 ACTIVA   │
   │  reactiva (cliente respondió,       │   │  (en trabajo)│
   │           nuevo doc subido,         │   └───┬───────┬──┘
   │           SLA bajó a alerta)        │       │       │
   │                                     │   pause│       │ resolve
   │                                     │       │       │
   │                                     │       ▼       ▼
   │                                     │  ┌───────────┐ ┌──────────┐
   └─────────────────────────────────────┴──│ 🟠 ESPERA │ │✅ RESUELTA│
                                            │ waiting_on│ │ +30 días │
                                            │ =cliente  │ │ tras la  │
                                            │ =interno  │ │ entrega →│
                                            │ =provee-  │ │ solo lec.│
                                            │  dor      │ └──────────┘
                                            │ =doc      │       ▲
                                            └─────┬─────┘       │
                                                  │  resolve()  │
                                                  └─────────────┘

         ↑ se puede reabrir desde RESUELTA mientras el caso siga
           editable: hasta 30 días tras la entrega del contenedor.
           Después queda en solo lectura (el admin aún puede ajustar).
```

### Tabla de los 4 estados

| Estado | Quién lo pone | ¿Qué significa? | Siguiente |
| --- | --- | --- | --- |
| 🆕 TRIAGE | Cliente (vía web); el sistema reacciona | Recién entró, sin asignar | ACTIVA |
| 🟡 ACTIVA | Usuario asignado | Alguien está trabajando | ESPERA / RESUELTA |
| 🟠 ESPERA | Usuario | Esperando algo (ver `waiting_on`) | ACTIVA |
| ✅ RESUELTA | Usuario | Trabajo hecho, queda histórico | Solo lectura 30 d. tras entrega |

> **Nota de asignación**: en el MVP, con poco volumen, el sistema **asigna el caso automáticamente** al entrar (TRIAGE → ACTIVA es casi inmediato). Cuando crezca el equipo, la asignación pasa a ser manual o por reglas.
> 

### Qué pasó con los 3 estados que se eliminaron

Los 3 que en tu sistema eran estados, aquí son **metadatos del mismo estado**:

```
case.status        = 'ESPERA'
case.waiting_on    = 'cliente' | 'interno' | 'proveedor' | 'documento'
                                  ↑
                          esto reemplaza
                          "Pte. Cliente" + "Pte. Interno"
                          como dos estados separados

case.waiting_since = timestamp   ← alimenta SLA y recordatorios

case.assignee_id   = uuid        ← antes era estado "Asignado"

case.last_message_direction = 'inbound' | 'outbound'
                              ↑
                       esto reemplaza
                       "Respondido"

case.reopened_at   = timestamp   ← antes era "Reabierto"
```

### ¿Por qué bajar a 4 estados es mejor?

1. **Menos confusión**: en tu sistema, ¿cuál es la diferencia entre “Respondido” y “En curso”? Yo no lo tengo claro y tú lo explicaste 3 veces distintas.
2. **Menos clicks**: hoy un usuario tiene que cambiar manualmente el estado cuando llega una respuesta del cliente. Aquí el sistema lo hace solo cuando detecta `last_message_direction='inbound'`.
3. **Reportes más limpios**: agrupar por `(status, waiting_on)` te da los mismos buckets que antes, pero la lógica de SLA/escalado es trivial.
4. **Estándar de la industria**: Linear lo hace. Plain.com lo hace. HubSpot Service Hub lo hace. Funciona.

### “Reprocesar” — resuelto

En el sistema modelo, “Reprocesar” era un estado. Aquí es `status=ACTIVA` + `reason_code='reprocesar'`. ✅ **Javier confirmó (8 may)** que con eso basta — no necesita ser estado propio.

> ✅ **Validado por Javier (8 may): los 4 estados son correctos.** Una precisión importante:
- **SÍ se necesita distinguir “esperando al cliente” vs “esperando a un proveedor externo”**. Los proveedores externos de Valkam Capital son 4: **naviera, agente logístico, agente de transporte y agente de aduanas**. Esto se modela con `waiting_on=proveedor` + un subcampo `provider_type` (naviera | logistico | transporte | aduanas).
- En la interfaz, cada tipo de espera lleva su **icono** (ej: un barquito para naviera) para que de un vistazo se sepa de quién se está esperando. Eso es capa visual (Front End); el backend solo guarda los 4 estados + metadata.
> 

---

## 7. 🌐 La WEB pública — el área de clientes

Esta es la puerta de entrada para los clientes. La web no se describió en detalle en el Zoom; aquí está la propuesta validada con Javier el 8 de mayo.

> ✅ **Validado por Javier (8 may):** el acceso es un **“área de clientes”**. Solo entran clientes que ya están operando con Valkam Capital — con su usuario/teléfono y un PIN. No es un alta abierta tipo e-commerce: los clientes ya saben si compran o venden, así que no se les pregunta “importar/exportar”. La web pública sirve para registrar solicitudes y dar seguimiento.
> 

### Landing inicial

```
┌────────────────────────────────────────────────────────────────────┐
│  valkamcapital.com                                       [ES] [EN]  │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│           Área de clientes — Valkam Capital                        │
│           Gestiona tus operaciones y tu documentación.             │
│                                                                    │
│           [ Acceder al área de clientes ]                          │
│                                                                    │
│           ── ¿nueva solicitud? ──                                  │
│           [ Iniciar solicitud ]                                    │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

### Paso 1 del wizard

```
┌────────────────────────────────────────────────────────────────────┐
│  Paso 1 de 5  ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─  20%                       │
│                                                                    │
│           ¿Qué necesitas hacer?                                    │
│                                                                    │
│           ⚪ Seguimiento de embarque actual                        │
│           ⚪ Cambio de BL (Switch BL)                              │
│           ⚪ Adjuntar documentación                                │
│           ⚪ Presentar queja                                       │
│           ⚪ Otro                                                  │
│                                                                    │
│                                                  [ Siguiente → ]   │
└────────────────────────────────────────────────────────────────────┘
```

### Pasos 2-5 (resumen)

```
Paso 2: ¿Cuándo? + contexto de la operación   (datos blandos)
Paso 3: Descripción libre                      (qué necesita el cliente)
Paso 4: Datos de la empresa + identificador    (razón social + tax ID)
Paso 5: Email + WhatsApp                       (contacto — ambos)

         ↓ submit

✅ ID #VLK-2026-0421 creado
📧 Email con link al portal + resumen del proceso iniciado
📱 WhatsApp con el mismo link + resumen
```

> **Importante:** en el wizard **no se piden documentos**. Solo los datos de la solicitud. Toda la documentación se gestiona después en el área de clientes (sección 8 y 10).
> 

### 5 reglas del wizard (basadas en research de conversión)

1. **Step 1 = una pregunta de selección múltiple** (mucho mejor completion que empezar con texto libre).
2. **Datos de la empresa en pasos 4-5**, nunca en el 1 (sunk cost psychology — el cliente ya invirtió tiempo).
3. **Save & resume automático** — email de retorno a las 24 h si abandona.
4. **Identificador fiscal flexible** — cada país tiene su formato (CIF, RUC, EIN, NIT…); el sistema acepta todos.
5. **Microcopy honesto** — explicar por qué se pide cada dato.

> ✅ **Validado por Javier (8 may):**
1. **Tipos de solicitud**: Seguimiento de embarque, Cambio de BL (Switch BL), Adjuntar documentación, Presentar queja, Otro. *(La lista definitiva la confirma la dirección — ver B12.)*
2. **Solo empresas (B2B)** — no se aceptan personas físicas con DNI. Se pide el identificador fiscal de la empresa.
3. **Se aceptan clientes de cualquier país**: cada país tiene su identificador (RUC en Perú, EIN en EEUU, NIT en Colombia, CIF en España…). El sistema no se limita a CIF español/EU.
4. **El wizard solo recoge datos**, no documentos. Los documentos van en el portal.
> 

---

## 8. 👤 El PORTAL del cliente — qué ve cuando se loguea

Esto es lo que ve el cliente DESPUÉS de tener su trámite creado. Tú lo describiste como “espacio virtual” y dijiste algo importante: *“la idea de que todo se gestione por el espacio virtual es que quede registro de todas las conversaciones que estamos teniendo”*.

### Mockup del portal

```
┌────────────────────────────────────────────────────────────────────┐
│ Valkam Capital  Mis trámites  Documentos  Mensajes  [ES|EN] Avatar │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ ← Volver        Trámite #VLK-2026-0421                             │
│                 Seguimiento embarque cobre Medellín → Houston      │
│                                                                    │
│  Estado actual:  EN REVISIÓN POR GESTOR                            │
│  Iniciado: 02 May 2026   ETA: 12 May 2026   [WhatsApp ON]          │
│                                                                    │
│  ┌────┬────┬────┬────┬────┐                                        │
│  │ OK │ OK │ -- │    │    │  Recibido > Docs > Revisión > Firma > OK│
│  └────┴────┴────┴────┴────┘                                        │
│                                                                    │
│  ▶ Próximo paso (TÚ)                                               │
│    ⚠ Falta: certificado de origen                                  │
│        [  Subir documento  ]   o  arrastrar aquí                   │
│                                                                    │
│  ▶ Documentos (4)                                       [+ Añadir] │
│    📄 CIF B12345678.pdf            subido 02 May    ✅ validado    │
│    📄 BL-MSCU1234567.pdf           subido 02 May    ✅ validado    │
│    📄 DNI representante.jpg        subido 03 May    🟡 en revisión │
│    📄 Cert. de origen              ⏳ pendiente                    │
│                                                                    │
│  ▶ Mensajes (2 nuevos)                                             │
│    Ana (gestora) — hace 2h:                                        │
│     "Hola Marta, todo correcto, solo nos falta el cert. de origen. │
│      Si no lo tienes, podemos pedirlo por ti (+45 EUR)."           │
│     [ Responder ]   [ Aceptar gestión ]                            │
│                                                                    │
│  ▶ Línea de tiempo                                                 │
│    02 May 09:14  Trámite creado                                    │
│    02 May 09:18  Documentos iniciales subidos                      │
│    03 May 11:02  Asignado a Ana M.                                 │
│    04 May 16:30  Solicitud cert. de origen  ← ahora                │
└────────────────────────────────────────────────────────────────────┘
```

### Cómo entra el cliente al portal

✅ **Decisión (8 may):** el acceso debe ser **muy simple** para el cliente — la gente del sector de la chatarra no está acostumbrada a logins complejos. Como las comunicaciones ya son por WhatsApp, el login es:

- **Teléfono + PIN** (un PIN corto, fácil de recordar).
- Además, cada solicitud genera un **link directo** al portal: si el cliente entra por ese link, el caso se carga solo y no tiene que memorizar ni teclear el ID largo.
- Recuperación de acceso por WhatsApp / email del contacto autorizado.

> El ID interno del caso es largo (lleva información codificada). Al cliente solo se le muestran los **últimos 4 dígitos**. El acceso real es por teléfono + PIN o por el link directo. *(Emanuel afina el detalle final de este flujo — ver B11.)*
> 

### Flujo visual del login del cliente

```
   ┌─────────────────┐
   │ 1. Cliente abre │   valkamcapital.com  (o el link directo
   │ el link / web   │   que recibió por WhatsApp/email)
   └────────┬────────┘
            │
            ▼
   ┌─────────────────┐
   │ 2. Mete su      │   +57 320 123 4567
   │ teléfono        │   [ Recibir código ]
   └────────┬────────┘
            │
            ▼
   ┌─────────────────────────────────────┐
   │ 3. Sistema le envía un código /     │
   │    valida el PIN por WhatsApp       │
   │                                     │
   │    📱 "Tu código de acceso: 4827"   │
   └────────┬────────────────────────────┘
            │
            ▼
   ┌─────────────────┐
   │ 4. Mete el PIN  │   [ 4 ] [ 8 ] [ 2 ] [ 7 ]
   │ / código        │
   └────────┬────────┘
            │
            ▼
   ┌─────────────────┐
   │ 5. Ya dentro    │   Ve sus trámites
   │                 │   Si entró por link directo, va al caso
   └─────────────────┘

   Si el cliente entra por el LINK DIRECTO de su solicitud,
   el caso se abre sin tener que teclear nada — el link ya
   lleva el contexto.
```

### Lo que el cliente puede hacer en el portal

| Acción | Permitido | Notas |
| --- | --- | --- |
| Ver estado de su(s) trámite(s) | ✅ | Tracking visual estilo Shopify |
| Subir documentos | ✅ | Drag & drop |
| Reemplazar documento rechazado | ✅ | Versionado no destructivo |
| Borrar un documento que subió | ✅ | **Soft-delete**: desaparece para el cliente, pero Valkam Capital conserva el registro de lo que subió y borró |
| Enviar mensajes a la gestora | ✅ | Pestaña de mensajes, separada de la de documentos |
| Ver factura y pagar | ✅ | Adjunta el comprobante de transferencia; el Backoffice valida |
| Descargar todos los documentos | ✅ | ZIP con un click |
| Solicitar la cancelación del trámite | ✅ | **Manda una solicitud de cancelación** — no cancela él directamente (borraría información). Valkam Capital decide si la acepta |
| Cancelar el trámite por sí mismo | ❌ | No — ver fila anterior |
| Cambiar datos clave del trámite (ID) | ❌ | Tiene que mandar mensaje a la gestora |

> ✅ **Validado por Javier (8 may):**
- El cliente **no puede cancelar ni borrar información directamente** — solo solicita la cancelación; el equipo decide.
- El cliente **sí puede borrar documentos que él subió**, pero con **soft-delete**: en su portal desaparecen, pero en el sistema interno queda el registro completo (qué subió, cuándo, qué borró). Nada se pierde de verdad.
- La interfaz separa **Documentos** y **Mensajes** en dos pestañas distintas para que no se confundan (detalle de Front End).
> 

---

## 9. 📥 El INBOX unificado — la pantalla principal del equipo

Esta es la **pantalla principal del workspace interno** y la feature diferenciadora del sistema. Reemplaza la lista de Casos del sistema modelo.

**Por qué es clave**: Javier explicó varias veces que en el sistema modelo la información estaba dispersa — emails por un lado, llamadas por otro, mensajes del portal por otro, observaciones internas por otro. **Unificar todo eso en un solo feed es el mayor diferenciador.** ✅ Javier (8 may): *“perfecto, está bien”*.

### Mockup del inbox

```
┌────────────────────────────────────────────────────────────────────┐
│ Valkam Capital Workspace         [Cmd+K para buscar/actuar]   👤    │
├──────────┬─────────────────────────────────────────────────────────┤
│          │ INBOX UNIFICADO                          12 sin leer    │
│ 📥 Inbox │                                                         │
│ 📂 Casos │ ┌─────────────────────────────────────────────────────┐ │
│ 👥 Clien │ │📧 Marta López     ← email                  hace 2 h │ │
│ 📊 Repor │ │ Re: embarque cobre Medellín → Houston              │ │
│ ⚙ Confi │ │ "Adjunto el certificado, gracias por…"              │ │
│          │ │ 📎 cert_origen.pdf  · Caso #VLK-2026-0421           │ │
│          │ │ IA: "Cert. de origen — resumen y confianza listos"  │ │
│          │ │ [ Revisar y validar ]  [ Responder ]                │ │
│          │ └─────────────────────────────────────────────────────┘ │
│          │                                                         │
│          │ ┌─────────────────────────────────────────────────────┐ │
│          │ │📞 +34 612 345 678  ← llamada perdida       hace 4 h │ │
│          │ │ Cliente: Industrias Cobre SL                        │ │
│          │ │ AI: "Quería confirmar fecha del embarque y pidió..."│ │
│          │ │ [ Devolver llamada ]   [ Ver transcripción ]        │ │
│          │ └─────────────────────────────────────────────────────┘ │
│          │                                                         │
│          │ ┌─────────────────────────────────────────────────────┐ │
│          │ │💬 WhatsApp +57 320…  ← mensaje              hace 1d │ │
│          │ │ "Hola Ana, ya subí el doc al portal"                │ │
│          │ │ Caso #VLK-2026-0418 · pasó a RESPONDIDO             │ │
│          │ └─────────────────────────────────────────────────────┘ │
│          │                                                         │
│          │ ┌─────────────────────────────────────────────────────┐ │
│          │ │🌐 Portal upload    ← documento                hace 1d│ │
│          │ │ Cliente subió: factura_proforma.pdf                 │ │
│          │ │ IA: "Resumen del doc listo · confianza 94%"          │ │
│          │ │ [ Revisar y validar ]                                │ │
│          │ └─────────────────────────────────────────────────────┘ │
└──────────┴─────────────────────────────────────────────────────────┘
```

### Lo que hace especial este inbox

1. **Email + WhatsApp + llamadas + portal en un solo feed** — no abres 4 apps
2. Cada item ya viene **clasificado por IA** y vinculado al caso correcto
3. **Cmd+K** abre command palette: “asigna este caso a Juan”, “responde con plantilla X”
4. **Optimistic UI** sin spinners ni botón “Guardar” — todo se guarda solo
5. **3 vistas intercambiables**: Tabla / Kanban / Timeline (la misma data, distinta presentación)

### Casos vs Inbox vs Clientes — ¿cuál vista uso cuándo?

| Vista | Cuándo la uso |
| --- | --- |
| 📥 Inbox | Llegada del día — qué hay nuevo, qué necesita atención |
| 📂 Casos | Trabajo profundo en un trámite específico |
| 👥 Clientes | Visión 360 de un cliente con todos sus casos juntos |
| 📊 Reportes | Métricas semanales/mensuales del equipo |

### Las 3 vistas alternativas de la misma data (Tabla / Kanban / Timeline)

Una de las reglas UX no negociables: **misma data, 3 layouts**. El usuario elige cuál le sirve hoy.

```
┌─ VISTA TABLA (lista densa, ideal para revisar muchos casos) ────────┐
│                                                                     │
│ ID            │ Cliente               │ Estado    │ Asignado        │
│───────────────┼───────────────────────┼───────────┼─────────────────│
│ #VLK-2026-0421│ Construcciones Bonif. │ 🟡 ACTIVA │ Ana M.          │
│ #VLK-2026-0422│ Tramitax Marbella SL  │ 🟠 ESPERA │ Javier          │
│ #VLK-2026-0423│ Industrias Cobre SL   │ 🆕 TRIAGE │ —               │
└─────────────────────────────────────────────────────────────────────┘

┌─ VISTA KANBAN (drag & drop, ideal para mover casos entre estados) ─┐
│                                                                    │
│ 🆕 TRIAGE   │ 🟡 ACTIVA      │ 🟠 ESPERA      │ ✅ RESUELTA         │
│─────────────┼────────────────┼────────────────┼─────────────────────│
│ ┌──────────┐│ ┌────────────┐ │ ┌────────────┐ │ ┌─────────────┐    │
│ │VLK-0423  ││ │VLK-0421    │ │ │VLK-0422    │ │ │VLK-0418     │    │
│ │Industrias││ │Construc.B. │ │ │Tramitax    │ │ │Embarque OK  │    │
│ │SLA 4h    ││ │SLA 3d      │ │ │espera doc  │ │ │cerrado ayer │    │
│ └──────────┘│ └────────────┘ │ └────────────┘ │ └─────────────┘    │
└────────────────────────────────────────────────────────────────────┘

┌─ VISTA TIMELINE (cronológica, ideal para ver actividad reciente) ──┐
│                                                                    │
│ Hoy 16:30   📧 Email de Marta López — adjuntó cert. origen        │
│ Hoy 14:12   📞 Llamada perdida +57 320…                            │
│ Hoy 11:02   ✅ Caso #VLK-0421 asignado a Ana M.                    │
│ Ayer 18:45  📄 Doc rechazado en #VLK-0422 (factura ilegible)      │
│ Ayer 09:14  🆕 Caso #VLK-0421 creado desde web pública             │
└────────────────────────────────────────────────────────────────────┘
```

> Cmd+1, Cmd+2, Cmd+3 alterna entre vistas.
> 

### 🔍 Búsqueda + Command Palette (Cmd+K)

En lugar de menús anidados (como tu sistema modelo, con su árbol gigante de “Operativa comercial > Gestión de cartera > Consultas…”), Valkam Capital tiene **una sola tecla mágica: `Cmd+K`** (o `Ctrl+K` en Windows).

```
   ┌─ El usuario presiona Cmd+K en cualquier pantalla ─────────────────┐
   │                                                                   │
   │  ┌───────────────────────────────────────────────────────────────┐│
   │  │ 🔍  Buscar o ejecutar acción...                               ││
   │  └───────────────────────────────────────────────────────────────┘│
   │                                                                   │
   │  💡 Sugerencias contextuales:                                     │
   │   ▸ Crear caso nuevo                          [N]                 │
   │   ▸ Buscar cliente "Construcciones Bonifac…"                      │
   │   ▸ Asignar este caso a Juan                  [A]                 │
   │   ▸ Cerrar este caso                          [C]                 │
   │   ▸ Cambiar a vista Kanban                    [Cmd+2]             │
   │   ▸ Ir a Inbox                                [G I]               │
   │   ▸ Ir a Casos                                [G C]               │
   │                                                                   │
   └───────────────────────────────────────────────────────────────────┘

   Búsqueda inteligente — sabe interpretar:

   "marta lopez"          → busca cliente o contacto
   "VLK-0421"             → busca caso específico
   "cobre Houston"        → busca embarques con ese término
   "casos abiertos"       → filtra por estado
   "Bonifacio sin doc"    → cliente + filtro semántico (AI)
   "facturas mes pasado"  → busca + entiende fechas relativas
```

**Por qué es killer**: tu sistema modelo tenía 6 niveles de menú anidado y ~30 opciones laterales. Cmd+K reemplaza TODO eso. Linear, Notion, Slack y Plain.com lo hacen así. Una vez que el usuario lo prueba, no vuelve a usar el menú.

> ✅ **Validado por Javier (8 may):** la organización del menú (Inbox / Casos / Clientes / Reportes) y las 3 vistas intercambiables (Tabla / Kanban / Timeline) tienen sentido. Javier no conocía el Kanban — es cuestión de acostumbrarse, pero le pareció bien. Los atajos rápidos personalizados quedan para afinar en construcción.
> 

---

## 10. 📄 Workflow de documentos

Es donde el equipo dedica más tiempo. Aquí está el flujo end-to-end.

> ⚠️ **Importante — alcance del MVP (validado con Javier el 8 may):** en el MVP, **toda la validación de documentos la hace una persona**. La IA aporta un **resumen y un nivel de confianza** como ayuda visual, pero **nunca aprueba ni rechaza sola**. La aprobación/rechazo automático por IA es una **fase futura**, no entra en la versión 0. Esto evita el riesgo de que la IA rechace un documento correcto solo porque no sigue la estructura esperada.
> 

```
                  ┌───────────┐
                  │ REQUIRED  │  Sistema o usuario pide un doc
                  └─────┬─────┘  (ej: "cert. de origen")
                        │
                        │ cliente sube (portal | WhatsApp | email)
                        ▼
              ┌─────────────────┐
              │    UPLOADED     │  ← la IA prepara resumen + confianza
              └────────┬────────┘
                       │
              ┌────────▼─────────┐
              │ APOYO DE IA      │  (no decide — solo informa)
              ├──────────────────┤
              │ resumen del doc  │ → se muestra al gestor
              │ nivel de confianza│ → se muestra como barra de color
              │ posibles flags   │ → se resaltan para el gestor
              └────────┬─────────┘
                       │
              ┌────────▼────────┐
              │ PENDING REVIEW  │
              └────┬───────┬────┘
                   │       │
       approve()   │       │  reject(motivo)
                   ▼       ▼
            ┌──────────┐  ┌───────────┐
            │ APPROVED │  │ REJECTED  │ ← email/WhatsApp auto al cliente
            └─────┬────┘  └─────┬─────┘   con motivo + magic link reupload
                  │             │
                  │             └──────────► vuelve a UPLOADED (versión nueva)
                  │
                  ▼ todos approved
            ┌────────────┐
            │ CASE PUEDE │
            │  CERRARSE  │
            └────────────┘
```

### Reglas clave del workflow

- **El gestor siempre valida**: ningún documento se aprueba o rechaza sin que una persona lo revise (en el MVP).
- **Versionado no destructivo**: cuando el cliente reemplaza un doc, el viejo queda con `is_current=false`. Auditoría completa.
- **Idempotencia por SHA256**: el mismo archivo subido 2 veces es el mismo `Document.id`.
- **Magic link sin login**: cuando se rechaza un doc, el cliente recibe un link que funciona 7 días sin tener que loguearse. También funciona por WhatsApp con código corto `VLK-2026-0421`.
- **Apoyo de IA por tipo de doc** (resumen y extracción de datos, NO decisión):
    - 📄 BL → lectura de shipper/consignee/HS codes
    - 💰 Factura → lectura de importes (resalta si el total difiere de lo declarado)
    - 📦 Foto container → lectura de container#, sellos, daños visibles
    - 🏛 Certificado → lectura aunque el documento esté degradado/fotocopiado

### Cómo se ve la validación en pantalla

```
┌─────────────────────────────────────────────────────────────────────┐
│ Caso #VLK-2026-0421 · Documentos                                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  📄 cert_origen_marta_lopez.pdf                                     │
│     Subido: hace 2 min · Por: Marta López · Vía: Portal             │
│                                                                     │
│     🤖 RESUMEN DE IA  (ayuda — el gestor decide)                    │
│     ┌────────────────────────────────────────────────────────────┐  │
│     │ Confianza: ████████████████████░░  94%   (sugerencia)      │  │
│     │                                                            │  │
│     │ Detectado:                                                 │  │
│     │  · Emisor: Cámara de Comercio Medellín                    │  │
│     │  · Vigencia: 12/03/2026 → 12/09/2026  (válido)            │  │
│     │  · País origen: Colombia                                  │  │
│     │  · Producto: Cobre catódico (HS 7403.11)                  │  │
│     │  · Coincide con BL declarado: ✅                          │  │
│     │  · Firma presente: ✅                                     │  │
│     └────────────────────────────────────────────────────────────┘  │
│                                                                     │
│     [ Aprobar ]  [ Rechazar ]  [ Ver original ]                    │
│                                                                     │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│  📄 packing_list.jpg                                                │
│     🤖 Confianza: ████████████░░░░░░░░  71%   ⚠ revisar con ojo    │
│     Razón: imagen tiene mancha en zona de cantidades                │
│                                                                     │
│     [ Aprobar ]  [ Rechazar ]  [ Pedir nuevo ]                     │
└─────────────────────────────────────────────────────────────────────┘

   En el MVP, el gestor SIEMPRE pulsa Aprobar o Rechazar.
   La barra de confianza es solo un semáforo de ayuda:
     verde alto  → probablemente OK, revísalo rápido
     amarillo    → revísalo con más cuidado
     rojo        → revísalo a fondo
   (La aprobación automática por IA es una fase futura.)
```

### Catálogo de documentos por tipo de trámite

Propuesta de partida. La lista definitiva se cierra con la dirección al cruzar la información con el sistema actual de la empresa:

| Tipo de trámite | Documentos requeridos (a confirmar con dirección) |
| --- | --- |
| Seguimiento embarque | Número de BL existente, copia del contrato |
| Cambio de BL (Switch BL) | BL original, datos del nuevo titular, autorización |
| Adjuntar documentación | Lo que se pida según contexto |
| Queja | Descripción + evidencia (fotos, videos, BL afectado) |

> ✅ **Validado por Javier (8 may):**
- La **lista de documentos por tipo de trámite la define la dirección** (el papá). Se cerrará al cruzar la info con el sistema actual de la empresa — no es una decisión de Javier.
- **Documentos que vencen** (ej: certificado de origen): sí, hay que avisar cuando estén por vencer.
- El **mismo embarque** agrupa toda la documentación que el cliente pide sobre esa operación (ver sección 11).
- Cuando se rechaza un documento, el cliente **solo vuelve a subir el rechazado**, no todos.
> 

---

## 11. 🔗 Casos asociados (mismo embarque)

Esto es algo que tú repetiste varias veces: *“todo lo que el cliente ha pedido en relación a este embarque y listo… lo ideal es de que respecto a este embarque, por ejemplo, vamos a exportar cobre, todo el todo lo que el cliente ha pedido en relación a este embarque”*. Es uno de los conceptos más importantes y donde más confusión hay.

### Mi modelo propuesto

```
        Embarque "Cobre Medellín → Houston"
        (esto es un Caso PADRE tipo "shipment")
                     │
         ┌───────────┼───────────┬───────────┐
         ▼           ▼           ▼           ▼
   ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
   │ Caso #1  │ │ Caso #2  │ │ Caso #3  │ │ Caso #4  │
   │Cotización│ │Documentos│ │Inspección│ │Facturación│
   │ ACTIVA   │ │ ESPERA   │ │ ACTIVA   │ │ TRIAGE   │
   └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬─────┘
        │            │            │            │
        └────────────┴─────┬──────┴────────────┘
                           │
                  Todos comparten:
                  · parent_case_id = embarque
                  · customer_id = mismo cliente
                  · shipment_ref = "VLK-SHIP-2026-0421"
                  · pueden compartir documentos (ver pregunta B6)
                  · aparecen juntos en timeline del cliente
                  · buscables como grupo
```

### Cómo se ve un Embarque en tu pantalla

```
┌────────────────────────────────────────────────────────────────────┐
│ 🚢 Embarque #VLK-SHIP-2026-0421                                     │
│    "Cobre Medellín → Houston"                                       │
│    Cliente: Construcciones Bonifacio · 18 contenedores              │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ Progreso global: ████████████░░░░░░░░  62%                         │
│                                                                    │
│ Casos asociados (4):                                               │
│                                                                    │
│ ┌────────────────────────────────────────────────────────────────┐ │
│ │ 📋 #VLK-0421 Cotización        🟡 ACTIVA     Asignado: Ana M.  │ │
│ │ 📋 #VLK-0422 Documentos        🟠 ESPERA     waiting_on=client │ │
│ │ 📋 #VLK-0423 Inspección        🆕 TRIAGE     —                 │ │
│ │ 📋 #VLK-0424 Facturación       🆕 TRIAGE     —                 │ │
│ └────────────────────────────────────────────────────────────────┘ │
│                                                                    │
│ Documentos del embarque (compartidos):                             │
│   📄 BL-MSCU1234567.pdf            ✅ validado                     │
│   📄 Factura proforma USD 47k      ✅ validado                     │
│   📄 Cert. origen Colombia         ⏳ pendiente cliente            │
│   📄 Packing list                  🟡 en revisión                  │
│                                                                    │
│ Timeline reciente:                                                 │
│   Hace 2h   📧 Marta López adjuntó cert origen                     │
│   Hace 4h   📞 Llamada de seguimiento                              │
│   Ayer      📋 Caso Inspección creado automáticamente              │
│                                                                    │
│ [ Crear caso asociado ]  [ Ver línea completa ]  [ Cerrar embarq.] │
└────────────────────────────────────────────────────────────────────┘
```

### Cómo se asocian visualmente en la UI

```
Caso #VLK-2026-0421 — Cotización
├─ 🚢 Parte del embarque "Cobre Medellín → Houston"
│   ├─ 📋 Caso #VLK-2026-0422 — Documentos
│   ├─ 🔍 Caso #VLK-2026-0423 — Inspección
│   └─ 💰 Caso #VLK-2026-0424 — Facturación
└─ Cliente: Construcciones Bonifacio
```

### Reglas de asociación — cerradas con Javier

> ✅ **B4 — Casos asociados son SIEMPRE del mismo cliente y del mismo embarque.** No se asocian casos de clientes distintos ni de embarques distintos (eso mezclaría la información). La imagen 21 con checkboxes de empresas distintas era solo un ejemplo de pantalla, no una regla.
> 
> 
> **Excepción válida**: un mismo cliente puede tener un caso de **embarque** y un caso de **queja** asociados — son dos tipos de caso distintos del mismo cliente, y tiene sentido vincularlos.
> 
> **Casos reales de “varios casos, un embarque”** que el sistema debe soportar:
> - Una orden de compra con **2 bookings** → genera **2 BLs**.
> - Un mismo **BL con 2 contenedores** → cada contenedor con su propio código.
> El embarque agrupa todo eso; los datos de una orden de compra nunca se mezclan con los de otra.
> 

> ✅ **B6 — Documentos: modelo híbrido.** Los documentos a nivel **embarque** (BL, factura) se comparten entre los casos asociados de ese embarque; los documentos de un **caso específico** (ej: acta de inspección) quedan en ese caso. La idea es no duplicar trabajo ni crear casos de más para una misma operación.
> 

### Operaciones masivas (bulk actions)

En tu sistema modelo (imagen 20) vi que seleccionabas **varios casos con checkboxes** y luego “Asociar casos”. Esto es un patrón que mantenemos pero modernizamos:

```
┌──────────────────────────────────────────────────────────────────────┐
│ Casos · Valkam-Colombia · Filtro: TRIAGE + ESPERA                   │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ ☑ ID            │ Cliente               │ Estado    │ Asignado       │
│ ─┬──────────────┼───────────────────────┼───────────┼────────────────│
│ ☑ #VLK-2026-0421│ Construcciones Bonif. │ 🟡 ACTIVA │ Ana M.         │
│ ☑ #VLK-2026-0422│ Construcciones Bonif. │ 🟠 ESPERA │ Javier         │
│ ☑ #VLK-2026-0423│ Construcciones Bonif. │ 🆕 TRIAGE │ —              │
│ ☐ #VLK-2026-0424│ Tramitax Marbella SL  │ 🆕 TRIAGE │ —              │
│ ☑ #VLK-2026-0425│ Construcciones Bonif. │ 🟡 ACTIVA │ Carlos R.      │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│ 🔗 4 casos seleccionados                                             │
│                                                                      │
│ Acciones disponibles:                                                │
│   [ Asociar como embarque ]  [ Asignar a... ]  [ Cambiar estado ]    │
│   [ Aplicar plantilla ]      [ Cerrar todos ]  [ Exportar a CSV ]    │
└──────────────────────────────────────────────────────────────────────┘
```

**Reglas de bulk operations**:
- **Asociar como embarque**: el sistema valida que sean del mismo cliente y embarque (regla B4).
- **Cerrar todos**: confirma con un modal que muestra cuántos se cerrarán y qué SLAs afecta.
- **Auditoría**: cada bulk action queda como **1 entry en `audit_log`** (no N entries).
- **Reversible**: dentro de los primeros 5 minutos, hay botón **“Deshacer”** (undo) — patrón Linear/Gmail.

> ✅ **Validado por Javier (8 may):** el patrón de seleccionar varios casos y asociarlos como embarque es correcto. Las bulk operations concretas del día a día se afinan en construcción.
> 

---

## 12. 📨 Plantillas + notificaciones automáticas

Tú dijiste algo importante: *“Nosotros tenemos que tener plantillas predeterminadas… contestación de solicitud de documentación, contestación de listo para contratación, contestación para solicitud de presupuesto”*. En tus screenshots vi códigos como `Contigo01`, `Contigo07`. Aquí está mi propuesta:

### Catálogo de plantillas

```
   ┌────────────────────────────────────────────────────────────┐
   │                  CATÁLOGO DE PLANTILLAS                    │
   └────────────────────────────────────────────────────────────┘

   VLK-01  Bienvenida + acceso al portal
   VLK-02  Solicitud de documentos pendientes
   VLK-03  "Te estamos esperando" (recordatorio cada 2-3 días)
   VLK-04  Documento rechazado + motivo + link reupload
   VLK-05  Documento aprobado + siguiente paso
   VLK-06  Cotización lista + link al portal
   VLK-07  Listo para contratación
   VLK-08  Factura emitida + link de pago
   VLK-09  Cobro recibido + agradecimiento
   VLK-10  Caso resuelto + encuesta de satisfacción
   VLK-11  Reasignación interna (a otro depto)
   VLK-12  Escalado por SLA vencido (al manager)

   Cada plantilla tiene:
   · Variables: {nombre_cliente}, {id_caso}, {motivo_rechazo}, etc.
   · Idioma: ES | EN | PT (auto-detect del cliente)
   · Canal: email | WhatsApp | SMS | push
   · Versión (git-versioned, audit trail)
```

### Reglas de notificación automática

| Trigger | Plantilla | Canal | Cuándo |
| --- | --- | --- | --- |
| Cliente crea trámite | VLK-01 | email + WhatsApp | inmediato |
| Faltan documentos | VLK-02 | email + WhatsApp | inmediato |
| Cliente no respondió | VLK-03 | email + WhatsApp | según sensibilidad doc. |
| Documento rechazado | VLK-04 | email + WhatsApp | inmediato |
| Documento aprobado | VLK-05 | email + WhatsApp | inmediato |
| Cotización lista | VLK-06 | email + WhatsApp | manual o trigger DB |
| Todos los docs OK | VLK-07 | email + WhatsApp | trigger DB |
| Factura emitida | VLK-08 | email + WhatsApp | trigger Backoffice |
| Pago recibido | VLK-09 | email + WhatsApp | trigger Backoffice |
| Caso resuelto | VLK-10 | email + WhatsApp | 24 h después de RESUELTA |
| Reasignación interna | VLK-11 | email interno | inmediato |
| SLA vencido | VLK-12 | email interno | según escalado configurado |

> ✅ **Regla validada por Javier (8 may):** todo lo importante para el cliente se envía **siempre por email Y por WhatsApp** (se duplica). Nunca solo por un canal.
> 

### Stack de envío

- **Email** → Resend (transaccional, alta entregabilidad, ~$0.001/email)
- **WhatsApp** → Twilio WhatsApp Cloud API + plantillas pre-aprobadas por Meta
- **SMS** → Twilio (solo fallback si email + WhatsApp fallan)
- **Servicio único `Notifier`** que respeta la preferencia del cliente

### Cómo viaja una notificación automática

```
   ┌──────────────────────┐
   │ EVENTO EN EL SISTEMA │  ej: cliente no respondió en 2 días
   │ (trigger DB)         │
   └──────────┬───────────┘
              │
              ▼
   ┌──────────────────────┐
   │ SERVICIO `NOTIFIER`  │  decide qué plantilla y qué canal
   │                      │
   │ 1. ¿qué plantilla?   │  → VLK-03 "Te estamos esperando"
   │ 2. ¿qué idioma?      │  → ES (auto-detect del cliente)
   │ 3. ¿qué canal?       │  → ver preferencia cliente
   │ 4. ¿variables?       │  → {nombre}, {id_caso}, {días_espera}
   └──────────┬───────────┘
              │
              ▼
   ┌──────────────────────┐
   │ RENDERIZA PLANTILLA  │  reemplaza {variables} con datos reales
   │                      │
   │ "Hola Marta, tu      │
   │  trámite #VLK-0421   │
   │  lleva 3 días        │
   │  esperando..."       │
   └──────────┬───────────┘
              │
       ┌──────┼──────┐
       │      │      │
       ▼      ▼      ▼
   ┌──────┐┌──────┐┌──────┐
   │EMAIL ││WHATS-││ SMS  │
   │Resend││App   ││fallb.│
   └───┬──┘└──Tw──┘└──┬───┘
       │      │      │
       └──────┴──────┘
              │
              ▼
   ┌──────────────────────┐
   │ REGISTRA EN ACTIVITY │  queda en timeline del caso
   │ + AUDIT LOG          │  + log inmutable
   └──────────────────────┘

   El cliente recibe → si responde → trigger detiene los recordatorios
```

> ✅ **Validado por Javier (8 may):**
- **Plantillas globales**, no por filial — mismo tono para todos.
- **Recordatorio (VLK-03)**: no hay un intervalo fijo. Es **configurable según la sensibilidad del documento** (alta / media / baja). El gestor del caso configura cada cuánto se recuerda y cuándo escala. A medida que se acerca el plazo, la sensibilidad sube → más recordatorios → y, si es muy sensible, una **alerta al gestor** para que llame al cliente directamente. *(Ver B7.)*
- **El recordatorio se detiene cuando el cliente responde** — confirmado.
- **Quién aprueba las plantillas ante Meta**: pendiente de definir con la dirección (proceso de Meta Business).
> 

---

## 13. 📊 Reportes y dashboards

> ✅ **Validado por Javier (8 may):** sí se necesitan reportes. El **operativo** es importante siempre (tiempo de primera respuesta, distribución de casos por estado, casos por canal). El **ejecutivo** para la dirección también. Todo queda registrado: siempre se puede saber quién hizo qué documento.
> 

### Dashboards propuestos

**Dashboard 1 — Operativo del día (para ti)**
- Casos en TRIAGE sin asignar (idealmente 0)
- Casos en ESPERA con SLA por vencer
- Documentos pendientes de validar
- Llamadas perdidas / mensajes sin responder

**Dashboard 2 — Performance del equipo (para Filial-Admin)**
- Casos resueltos esta semana / mes
- Tiempo promedio de resolución por tipo de caso
- Casos rechazados o reabiertos
- Top 10 clientes por volumen / facturación

**Dashboard 3 — Ejecutivo (para Holding-Admin)**
- Casos por filial / sede
- Facturación mensual consolidada
- Comparativo año-tras-año
- Clientes en riesgo de churn (predicción IA)

### Métricas que asumo importan

```
- Tiempo de primera respuesta            (TRIAGE → ACTIVA)
- Tiempo de resolución total              (TRIAGE → RESUELTA)
- % de casos con SLA cumplido
- % de docs revisados con apoyo de IA     (ahorro de tiempo)
- NPS / satisfacción del cliente
- Casos reabiertos                        (calidad del trabajo)
- Volumen por canal de origen             (web, email, WhatsApp, llamada)
```

### Mockup del Dashboard Operativo (lo que ves cada mañana)

```
┌────────────────────────────────────────────────────────────────────┐
│ Valkam Capital · Dashboard Operativo · Valkam-Colombia · 5 May 2026│
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐        │
│ │ EN TRIAGE       │ │ ESPERA SLA RIESGO│ │ DOCS PENDIENTES │        │
│ │                 │ │                 │ │                 │        │
│ │      3          │ │     ⚠ 2         │ │      8          │        │
│ │   sin asignar   │ │  vencen <4h     │ │  validar hoy    │        │
│ └─────────────────┘ └─────────────────┘ └─────────────────┘        │
│                                                                    │
│ ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐        │
│ │ LLAMADAS PERD.  │ │ RESUELTOS HOY   │ │ NPS SEMANA      │        │
│ │      4          │ │      11         │ │   8.2 / 10  ↑   │        │
│ └─────────────────┘ └─────────────────┘ └─────────────────┘        │
│                                                                    │
│ ▶ Distribución de casos por estado (esta semana)                   │
│                                                                    │
│   TRIAGE   ███░░░░░░░░░░░░░░░░░░░  3 (12%)                         │
│   ACTIVA   ████████████░░░░░░░░░░  12 (48%)                        │
│   ESPERA   ███████░░░░░░░░░░░░░░░  7 (28%)                         │
│   RESUELT  ███░░░░░░░░░░░░░░░░░░░  3 (12%)                         │
│                                                                    │
│ ▶ Casos por canal de origen                                        │
│                                                                    │
│   Web pública     ████████████  48%                                │
│   Email           ██████        24%                                │
│   WhatsApp        █████         20%                                │
│   Llamada         ██             8%                                │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

### Mockup del Dashboard Ejecutivo (para tu jefe)

```
┌────────────────────────────────────────────────────────────────────┐
│ Valkam Capital · Dashboard Ejecutivo · Holding · Mayo 2026         │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ ┌─────────────────────┐ ┌─────────────────────┐                    │
│ │ FACTURACIÓN MES     │ │ CLIENTES ACTIVOS    │                    │
│ │   USD 1.2M  ↑ 18%   │ │      127  ↑ 5       │                    │
│ │   vs abril          │ │   vs mes anterior   │                    │
│ └─────────────────────┘ └─────────────────────┘                    │
│                                                                    │
│ ▶ Casos por filial (este mes)                                      │
│                                                                    │
│   Valkam-Colombia ██████████████████  142                          │
│   Valkam-USA      ███████████         87                           │
│   Valkam-Perú     █████               41                           │
│                                                                    │
│ ▶ ⚠ Clientes en riesgo de churn (predicción IA)                    │
│                                                                    │
│   1. Industrias Cobre SL    │ -42% pedidos vs trim. anterior       │
│   2. Tramitax Marbella SL   │ no responde hace 18 días             │
│   3. Construcciones Bonif.  │ NPS bajó de 9 a 6                    │
│                                                                    │
│   [ Ver next-best-action sugerida ]                                │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

### Audit log — quién hizo qué y cuándo

Tú dijiste varias veces *“todo queda registrado”*. Lo formalizo así:

```
┌────────────────────────────────────────────────────────────────────┐
│ Caso #VLK-2026-0421 · Pestaña: Audit                                │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ Hoy 16:45  ✏  ana.molina editó descripción                         │
│             antes: "necesito cotización"                           │
│             después: "necesito cotización urgente"                 │
│                                                                    │
│ Hoy 14:30  📎 ana.molina aprobó documento BL-MSCU1234567.pdf       │
│             revisado con apoyo de IA (confianza 94%)               │
│                                                                    │
│ Hoy 11:02  👤 javier.valenzuela asignó caso a ana.molina           │
│             motivo: especialidad transporte marítimo               │
│                                                                    │
│ Ayer 18:00 📧 sistema envió plantilla VLK-02 a Marta López         │
│             canal: email + whatsapp                                │
│                                                                    │
│ Ayer 09:14 🆕 sistema creó caso desde web pública                  │
│             IP origen: 190.85.x.x · User-Agent: Chrome 124         │
│                                                                    │
│ [ Exportar audit log a PDF ]  [ Filtrar por usuario ]              │
└────────────────────────────────────────────────────────────────────┘
```

**Reglas del audit log**:
- **Append-only**: nadie (ni admin) puede borrar entries — es legalmente requerido.
- **Encadenado**: cada entry tiene hash de la anterior (estilo blockchain básico).
- **Retención**: según la legislación de cada país en el que opera Valkam Capital (no hay un valor fijo único — lo ajusta la dirección por país). Ver B9.
- **Exportable** a PDF firmado para auditorías externas.

### GDPR / LOPD — derechos del cliente

```
   El cliente puede solicitar (vía email a privacy@valkamcapital.com):

   📋 Acceso        → exportamos TODOS sus datos en JSON + PDF
                      (casos, mensajes, documentos, llamadas)
                      tiempo: ≤30 días por ley

   ✏ Rectificación → corrige datos personales incorrectos
                      tiempo: ≤30 días

   🗑 Supresión     → "derecho al olvido"
                      ATENCIÓN: solo aplica a datos NO requeridos
                      por ley fiscal/comercial. Los casos con factura
                      pagada se conservan el plazo legal de cada país
                      (ver B9). Implementación: soft-delete con tombstone.

   📦 Portabilidad  → recibe todos sus datos en formato máquina
                      (JSON + estándar comercio CSV)

   🛑 Oposición     → opt-out de ciertos tratamientos
                      (ej: enriquecimiento AI del Research Agent)

   Audit trail de cada solicitud:
   · quién la pidió
   · qué se hizo
   · quién la firmó (cumplimiento)
   · timestamp
```

> ✅ **Validado por Javier (8 may):**
- **Reportes operativos**: sí, siempre — tiempo de primera respuesta, distribución de casos por estado, casos por canal.
- **Reportes ejecutivos** para la dirección: sí.
- **Exportar a Excel/PDF**: sí.
- **Responsable de protección de datos (DPO)**: aún no hay una persona designada — *“al que le toque”*. Queda pendiente de asignar por la dirección.
- La **retención de datos personales depende de la legislación de cada país** — no se fija un único número.
> 

---

## 14. 🗄️ Modelo de datos — vista narrativa (para ti)

Esto es **CÓMO se conectan las piezas en la base de datos**, explicado sin jerga técnica. Si entiendes este diagrama, entiendes el sistema.

```
                     ┌─────────────┐
                     │   FILIAL    │  Valkam-Holding, Valkam-Colombia
                     │  (tenant)   │  (jerarquía: holding → filial → sede)
                     └──────┬──────┘
                            │ opera
                            │ (1 a varias)
                            ▼
                     ┌─────────────┐
                     │    SEDE     │  Medellín, Houston, Lima
                     │  (tenant)   │
                     └──────┬──────┘
                            │ tiene
                            │ (1 a varios)
                            ▼
   ┌──────────┐   tiene   ┌─────────────┐  pertenece  ┌──────────┐
   │ CLIENTE  │──────────►│  CONTRATO   │◄────────────│  PUNTO   │
   │(empresa) │ (1 a N)   │             │             │ SERVICIO │
   └────┬─────┘           └──────┬──────┘             └──────────┘
        │                        │
        │ autoriza               │ origina                  ┌──────────┐
        │ (1 a N)                │ (1 a varios)             │ FACTURA  │
        ▼                        ▼          ┌─────────────►│          │
   ┌──────────┐              ┌──────────┐   │ factura      └──────────┘
   │ CONTACTO │              │   CASO   │───┘ (1 a N)
   │ (Juan,   │              │   (ID)   │
   │  finanzas)              └────┬─────┘
   └──────────┘                   │
                                  │
        ┌────────────┬────────────┼────────────┬───────────┐
        │            │            │            │           │
        ▼            ▼            ▼            ▼           ▼
  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
  │ACTIVITY  │ │DOC. REQ. │ │SESIÓN DE │ │CASO      │ │PLANTILLA │
  │ (timeline│ │ + DOCS   │ │ATENCIÓN  │ │ASOCIADO  │ │USADA     │
  │unificado)│ │+ EVENTS  │ │          │ │(M:N)     │ │          │
  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘
       ↑
       │ todo lo que pasa con el cliente:
       │ · email recibido / enviado
       │ · llamada (con grabación + transcripción)
       │ · mensaje WhatsApp
       │ · nota interna del usuario
       │ · evento del sistema (cambio de estado)
       │ · documento subido / aprobado / rechazado

   USUARIO INTERNO  ──atiende──► CASO         (4 roles: Holding/Filial/Op/Cli)
   USUARIO DIGITAL  ──accede──► PORTAL CLIENTE
```

### Lo más importante de este diagrama

1. **`Activity` es la tabla más usada** — TODO el timeline cuelga ahí. Esto reemplaza las 3 tablas separadas que tenía el modelo viejo (Comunicación + Interacción + Observación).
2. **`Caso` es el centro** — todo se vincula a él. Si no sabes qué tabla consultar, empieza por Caso.
3. **`Tenant` es jerárquico** — el mismo árbol da matriz/filial/sede. No hay 3 tablas distintas.
4. **`Documentos` viven en su propia tabla** con su workflow propio (sección 10).

### “Caso 360” — todo lo que se acumula bajo un solo caso

Cuando abres un caso, puedes ver de un vistazo TODO lo que pasó. Así se conecta:

```
                           ┌─────────────────┐
                           │ CASO #VLK-0421  │
                           │ Cotización cobre│
                           │ 🟡 ACTIVA       │
                           │ Asignado: Ana M.│
                           └────────┬────────┘
                                    │
        ┌────────┬─────────┬────────┼────────┬─────────┬─────────┐
        │        │         │        │        │         │         │
        ▼        ▼         ▼        ▼        ▼         ▼         ▼
    ┌──────┐ ┌──────┐ ┌────────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌────────┐
    │CLIENT│ │CONTAC│ │ACTIVITY│ │DOCS  │ │CASOS │ │PLANTI│ │SESIONES│
    │      │ │TOS   │ │TIMELINE│ │      │ │ASOCS │ │USADAS│ │ATENC.  │
    │Constr│ │Marta │ │ 12 evts│ │ 4 docs │ embarq│ VLK-02│ Ana 2h │
    │Bonifac│ │López │ │ AI sum │ │ 2 ok  │ con 3 │ VLK-04│ Javier │
    │       │ │Juan F│ │        │ │ 1 OCR │ otros │       │ 30 min │
    └──────┘ └──────┘ └────────┘ └──────┘ └──────┘ └──────┘ └────────┘

   Lo que tú VES como pestañas en la UI:

   ┌─────────────────────────────────────────────────────────────────┐
   │ #VLK-0421                                                       │
   ├─────────────────────────────────────────────────────────────────┤
   │ [Resumen] [Timeline] [Documentos] [Mensajes] [Asociados] [Audit]│
   ├─────────────────────────────────────────────────────────────────┤
   │                                                                 │
   │ Resumen IA (auto-generado):                                     │
   │ "Cliente Construcciones Bonifacio pidió cotización para         │
   │  embarque de 18 contenedores Medellín → Houston. Cert. origen   │
   │  vencido pendiente. ETA respuesta cliente: 2 días."             │
   │                                                                 │
   │ Próxima acción sugerida:                                        │
   │ ⚠ Recordatorio cliente VLK-03 en 18 horas                       │
   │                                                                 │
   └─────────────────────────────────────────────────────────────────┘
```

### Comparación: modelo viejo vs modelo nuevo

| Concepto | Modelo viejo | Valkam Capital |
| --- | --- | --- |
| Comunicaciones del cliente | 3 tablas (Comunicación + Interacción + Observación) | 1 tabla (Activity) |
| Estados del caso | 7 estados + 3 sub-estados = 10 | 4 estados + metadata |
| Sociedad / Explotación / Sede | 3 tablas separadas | 1 tabla `tenants` con `path ltree` |
| Quejas | Entidad propia | `cases.case_type='complaint'` |
| Sesión de atención | “Personita verde/naranja” + tabla | Tabla simple de log |

### Antes vs Después — visualizado

```
   ANTES (modelo viejo)                 DESPUÉS (Valkam Capital)
   ─────────────────────                ───────────────────────

   📊 14 tablas                          📊 8 tablas (-43%)
   📊 10 estados                         📊  4 estados (-60%)
   📊 6 pantallas/trámite                📊  3 taps/trámite (-50%)

   ┌──────────────┐                     ┌──────────────┐
   │ Comunicación │ ──┐                 │              │
   ├──────────────┤   │                 │   Activity   │
   │ Interacción  │ ──┼──fusión──►      │  (polimorf.) │
   ├──────────────┤   │                 │              │
   │ Observación  │ ──┘                 └──────────────┘

   ┌──────────────┐                     ┌──────────────┐
   │  Sociedad    │ ──┐                 │              │
   ├──────────────┤   │                 │   Tenants    │
   │ Explotación  │ ──┼──fusión──►      │ (jerárquico) │
   ├──────────────┤   │                 │              │
   │   Matriz     │ ──┘                 └──────────────┘

   Estados:                              Estados:
   Nuevo, En curso, Pte.Cliente,         🆕 TRIAGE
   Pte.Interno, Respondido,              🟡 ACTIVA
   Reprocesar, Resuelto,                 🟠 ESPERA  (con waiting_on)
   Cerrado, Asignado, Reabierto          ✅ RESUELTA
   = 10 estados                          = 4 estados
```

### Cómo se ve la jerarquía multi-tenant

Tú me dijiste que tenías filiales en distintos países. Lo modelo así (un solo árbol):

```
                       🏢 Holding (matriz USA)
                            │
              ┌─────────────┼──────────────┐
              │             │              │
              ▼             ▼              ▼
       🏢 Filial          🏢 Filial      🏢 Filial
        Colombia            USA           Perú
            │                 │              │
       ┌────┼────┐        ┌───┴───┐       ┌──┴──┐
       │    │    │        │       │       │     │
       ▼    ▼    ▼        ▼       ▼       ▼     ▼
      📍   📍   📍       📍      📍      📍    📍
    Medellín Bogotá Cali  Houston  Miami  Lima  Arequipa
    (sede)  (sede) (sede) (sede)   (sede) (sede)(sede)

   path en BD:                      Quién accede:
   ─────────────────                ─────────────────────────────
   "holding"                        Holding-Admin (ve todo)
   "holding.colombia"               Filial-Admin Colombia (ve sus 3)
   "holding.colombia.medellin"      Operador Medellín (ve solo Medellín)
```

### Matriz visual de roles y permisos

```
                  │ Ver  │ Crear │ Editar│ Cerrar│ Reasig│ Confg │ Reportes│
                  │ caso │ caso  │ caso  │ caso  │ caso  │ filial│ ejecut. │
   ───────────────┼──────┼───────┼───────┼───────┼───────┼───────┼─────────┤
   Holding-Admin  │  ✅  │   ✅  │   ✅  │   ✅  │   ✅  │   ✅  │   ✅    │
   Filial-Admin   │  🔵  │   ✅  │   🔵  │   ✅  │   ✅  │   🔵  │   🔵    │
   Operador       │  🔵  │   ✅  │   🔵  │   🔵  │   🔵  │   ❌  │   ❌    │
   Cliente-extern │  🟢  │   🟢  │   ❌  │   ❌  │   ❌  │   ❌  │   ❌    │

   Leyenda:
   ✅ = puede en TODO el holding
   🔵 = puede SOLO en su filial/sede (lo que su scope_path le permite)
   🟢 = puede SOLO sus propios casos (filtro extra cliente_id=self)
   ❌ = no puede

   Ejemplo: "Javier Operador en Medellín"
            ve casos de Medellín (no de Bogotá ni de USA)
            crea casos en Medellín
            edita los suyos asignados
            no ve reportes ejecutivos del holding
```

> ✅ **Validado por Javier (8 may):** los 4 roles y la matriz son correctos como diseño. **Matiz para el MVP:** por ahora el equipo es pequeño y **todos ven todo** — el filtrado jerárquico por filial/sede (que un operador de Medellín no vea Bogotá) se activa **más adelante**, cuando crezca el equipo. La estructura de datos (`scope_path`, RLS) ya queda lista para soportarlo sin migración.
> 

> **Nota sobre sedes:** hoy Valkam Capital opera con **una sola sede** (Estados Unidos). El modelo multi-sede/multi-filial ya está soportado en la base de datos, pero en el MVP solo se usa una.
> 

---

## 15. 🔧 Modelo de datos — vista técnica (para devs)

> 🖱️ **Esto es un dropdown — haz click en el bloque de abajo (`▸ Schema completo...`) para expandirlo.**
👀 **No necesitas abrirlo, Javier.** Está aquí para los developers. Pero si quieres curiosear, click y se abre.
> 
- Click aquí para abrir → Schema completo en Mermaid + DBML
    
    ### Diagrama de relaciones
    
    ```mermaid
    erDiagram
        TENANTS ||--o{ TENANTS : "parent_id (ltree path)"
        TENANTS ||--o{ USERS : "scope"
        TENANTS ||--o{ CUSTOMERS : "scope"
        TENANTS ||--o{ MESSAGE_TEMPLATES : "scope (NULL = global)"
        CUSTOMERS ||--o{ CONTACTS : "autoriza"
        CUSTOMERS ||--o{ CONTRACTS : "tiene"
        CONTRACTS ||--o{ CASES : "origina"
        CONTRACTS ||--o{ INVOICES : "factura"
        CONTRACTS }o--|| SERVICE_POINTS : "asociado a"
        CASES ||--o{ ACTIVITIES : "registra"
        CASES ||--o{ DOCUMENT_REQUESTS : "exige"
        CASES }o--o{ CASES : "asociado (embarque)"
        CASES ||--o{ CASE_LINKS : "vincula"
        DOCUMENT_REQUESTS ||--o{ DOCUMENTS : "satisface"
        DOCUMENTS ||--o{ DOCUMENT_EVENTS : "audita"
        USERS ||--o{ ACTIVITIES : "ejecuta"
        USERS ||--o{ ATTENTION_SESSIONS : "abre"
        ATTENTION_SESSIONS ||--o{ ACTIVITIES : "agrupa"
        MESSAGE_TEMPLATES ||--o{ ACTIVITIES : "usa"
        NOTIFICATIONS }o--|| MESSAGE_TEMPLATES : "renderiza"
        NOTIFICATIONS }o--|| ACTIVITIES : "registra"
    ```
    
    ### Tablas principales (Postgres)
    
    ```sql
    -- =========================================================
    -- 1. MULTI-TENANT JERÁRQUICO
    -- =========================================================
    CREATE EXTENSION ltree;
    CREATE EXTENSION pgvector;
    CREATE EXTENSION pg_trgm;
    
    CREATE TABLE tenants (
      id           uuid PRIMARY KEY,
      parent_id    uuid REFERENCES tenants(id),
      kind         text CHECK (kind IN ('holding','filial','location')),
      path         ltree NOT NULL,         -- 'holding.colombia.medellin'
      name         text NOT NULL,
      country      text,
      currency     text,                    -- ISO 4217
      timezone     text,                    -- IANA
      fiscal_year_start date,
      created_at   timestamptz DEFAULT now()
    );
    CREATE INDEX ON tenants USING gist(path);
    
    -- =========================================================
    -- 2. USUARIOS Y ROLES
    -- =========================================================
    CREATE TABLE users (
      id           uuid PRIMARY KEY,
      email        text UNIQUE NOT NULL,
      name         text,
      scope_path   ltree NOT NULL,          -- determina qué ve por RLS
      role         text CHECK (role IN ('holding_admin','filial_admin','operator','external_customer')),
      active       boolean DEFAULT true,
      created_at   timestamptz DEFAULT now()
    );
    
    -- =========================================================
    -- 3. CLIENTES Y CONTACTOS
    -- =========================================================
    CREATE TABLE customers (
      id           uuid PRIMARY KEY,
      tenant_id    uuid REFERENCES tenants(id),
      legal_name   text NOT NULL,
      tax_id       text NOT NULL,           -- CIF / EIN / NIT
      tax_id_type  text,                    -- ES_CIF | US_EIN | CO_NIT
      country      text,
      email        text,
      phone        text,
      address      jsonb,
      ai_dossier   jsonb,                   -- enriquecimiento del Research Agent
      risk_score   numeric,                 -- OFAC, sanciones, etc.
      created_at   timestamptz DEFAULT now()
    );
    
    CREATE TABLE contacts (
      id           uuid PRIMARY KEY,
      customer_id  uuid REFERENCES customers(id),
      name         text NOT NULL,
      email        text,
      phone        text,
      role         text,                    -- finanzas, ops, legal
      authorized   boolean DEFAULT false,
      created_at   timestamptz DEFAULT now()
    );
    
    -- =========================================================
    -- 4. CONTRATOS Y PUNTOS DE SERVICIO
    -- =========================================================
    CREATE TABLE service_points (
      id           uuid PRIMARY KEY,
      tenant_id    uuid REFERENCES tenants(id),
      customer_id  uuid REFERENCES customers(id),
      address      jsonb,
      identifier   text                     -- número externo, contador, etc.
    );
    
    CREATE TABLE contracts (
      id              uuid PRIMARY KEY,
      tenant_id       uuid REFERENCES tenants(id),
      customer_id     uuid REFERENCES customers(id),
      service_point_id uuid REFERENCES service_points(id),
      contract_number text UNIQUE,
      status          text CHECK (status IN ('draft','active','suspended','closed')),
      start_date      date,
      end_date        date,
      currency        text,
      iban            text,
      metadata        jsonb,
      created_at      timestamptz DEFAULT now()
    );
    
    -- =========================================================
    -- 5. CASOS (4 estados)
    -- =========================================================
    CREATE TABLE cases (
      id                       uuid PRIMARY KEY,
      tenant_id                uuid REFERENCES tenants(id),
      customer_id              uuid REFERENCES customers(id),
      contract_id              uuid REFERENCES contracts(id),
      case_number              text UNIQUE,             -- VLK-2026-0421
      case_type                text,                    -- query|complaint|application|incident|shipment
      status                   text CHECK (status IN ('triage','activa','espera','resuelta')),
      waiting_on               text,                    -- cliente|interno|proveedor|documento
      provider_type            text,                    -- si waiting_on=proveedor: naviera|logistico|transporte|aduanas
      waiting_since            timestamptz,
      assignee_id              uuid REFERENCES users(id),
      resolution_group         text,
      reason_code              text,
      parent_case_id           uuid REFERENCES cases(id),
      shipment_ref             text,                    -- agrupa "Cobre Medellín-Houston"
      channel_origin           text,                    -- portal|email|whatsapp|phone|walk_in
      priority                 text DEFAULT 'normal',
      sla_due_at               timestamptz,
      reopened_at              timestamptz,
      resolved_at              timestamptz,
      closed_at                timestamptz,
      description              text,
      ai_summary               text,
      embedding                vector(1536),
      search_tsv               tsvector,
      created_at               timestamptz DEFAULT now()
    );
    CREATE INDEX ON cases (status, sla_due_at);
    CREATE INDEX ON cases USING gin(search_tsv);
    
    CREATE TABLE case_links (
      case_a_id    uuid REFERENCES cases(id),
      case_b_id    uuid REFERENCES cases(id),
      link_type    text DEFAULT 'shipment',
      PRIMARY KEY (case_a_id, case_b_id)
    );
    
    -- =========================================================
    -- 6. ACTIVITIES (Comunicación + Interacción + Observación unificados)
    -- =========================================================
    CREATE TABLE activities (
      id              uuid PRIMARY KEY,
      case_id         uuid REFERENCES cases(id),
      customer_id     uuid REFERENCES customers(id),
      contract_id     uuid REFERENCES contracts(id),
      activity_type   text,                  -- call|email|whatsapp|sms|in_person|note|system_event|status_change
      direction       text,                  -- inbound|outbound|internal
      channel         text,                  -- phone|email|whatsapp|portal|walk_in
      subject         text,
      body            text,
      metadata        jsonb,                 -- per-channel: recording_url, message_id, duration_sec, etc.
      agent_id        uuid REFERENCES users(id),
      attention_session_id uuid,
      template_id     uuid REFERENCES message_templates(id),
      occurred_at     timestamptz NOT NULL,
      ai_summary      text,
      ai_sentiment    text,
      embedding       vector(1536),
      search_tsv      tsvector,
      created_at      timestamptz DEFAULT now(),
      CHECK (case_id IS NOT NULL OR contract_id IS NOT NULL OR customer_id IS NOT NULL)
    );
    CREATE INDEX ON activities (case_id, occurred_at DESC);
    CREATE INDEX ON activities USING gin(search_tsv);
    CREATE INDEX ON activities USING ivfflat (embedding vector_cosine_ops);
    
    -- =========================================================
    -- 7. SESIONES DE ATENCIÓN
    -- =========================================================
    CREATE TABLE attention_sessions (
      id           uuid PRIMARY KEY,
      user_id      uuid REFERENCES users(id),
      customer_id  uuid REFERENCES customers(id),
      channel      text,
      started_at   timestamptz NOT NULL,
      ended_at     timestamptz
    );
    
    -- =========================================================
    -- 8. DOCUMENTOS (workflow de validación)
    -- =========================================================
    CREATE TABLE document_requests (
      id            uuid PRIMARY KEY,
      case_id       uuid REFERENCES cases(id),
      template_code text,                    -- 'cert_origen', 'bl', 'factura'
      label         text NOT NULL,
      mandatory     boolean DEFAULT true,
      status        text CHECK (status IN ('required','uploaded','pending_review','approved','rejected','expired')),
      due_at        timestamptz,
      magic_token   text UNIQUE,
      token_expires_at timestamptz,
      rejection_reason text,
      reviewer_id   uuid REFERENCES users(id),
      reviewed_at   timestamptz,
      created_at    timestamptz DEFAULT now()
    );
    
    CREATE TABLE documents (
      id                uuid PRIMARY KEY,
      request_id        uuid REFERENCES document_requests(id),
      version           int DEFAULT 1,
      parent_version_id uuid REFERENCES documents(id),
      is_current        boolean DEFAULT true,
      file_url          text,
      sha256            text,
      mime              text,
      size_bytes        bigint,
      uploaded_by       uuid,
      uploaded_at       timestamptz,
      source_channel    text,
      ocr_payload       jsonb,
      ai_confidence     numeric,                 -- MVP: solo informativo, NO auto-aprueba
      ai_flags          text[],
      reviewed_by       uuid REFERENCES users(id), -- MVP: SIEMPRE un humano valida
      status            text
    );
    CREATE UNIQUE INDEX ON documents (request_id) WHERE is_current = true;
    
    CREATE TABLE document_events (
      id           uuid PRIMARY KEY,
      document_id  uuid REFERENCES documents(id),
      actor_id     uuid,
      actor_type   text,
      event        text,
      payload      jsonb,
      created_at   timestamptz DEFAULT now()
    );
    
    -- =========================================================
    -- 9. PLANTILLAS Y NOTIFICACIONES
    -- =========================================================
    CREATE TABLE message_templates (
      id           uuid PRIMARY KEY,
      tenant_id    uuid REFERENCES tenants(id),  -- NULL = plantilla GLOBAL (por defecto). Decisión: plantillas globales, no por filial
      code         text UNIQUE,
      language     text,
      channel      text,
      subject      text,
      body         text,
      variables    jsonb,
      version      int,
      active       boolean DEFAULT true
    );
    
    CREATE TABLE notifications (
      id           uuid PRIMARY KEY,
      case_id      uuid REFERENCES cases(id),
      recipient    text,
      channel      text,
      template_id  uuid REFERENCES message_templates(id),
      status       text,
      scheduled_at timestamptz,
      sent_at      timestamptz,
      metadata     jsonb,
      activity_id  uuid REFERENCES activities(id)
    );
    
    -- =========================================================
    -- 10. FACTURAS
    -- =========================================================
    CREATE TABLE invoices (
      id            uuid PRIMARY KEY,
      contract_id   uuid REFERENCES contracts(id),
      case_id       uuid REFERENCES cases(id),
      number        text UNIQUE,
      amount        numeric(15,2),
      currency      text,
      fx_rate       numeric,
      fx_date       date,
      status        text,
      issued_at     timestamptz,
      due_at        timestamptz,
      stripe_invoice_id text,
      metadata      jsonb
    );
    
    -- =========================================================
    -- 11. AUDIT LOG GLOBAL (append-only, GDPR-ready)
    -- =========================================================
    CREATE TABLE audit_log (
      id           bigserial PRIMARY KEY,
      user_id      uuid,
      entity_type  text,
      entity_id    uuid,
      action       text,
      before       jsonb,
      after        jsonb,
      ip_address   inet,
      user_agent   text,
      occurred_at  timestamptz DEFAULT now()
    );
    ```
    
    ### Row-Level Security (multi-tenant)
    
    ```sql
    CREATE POLICY tenant_isolation ON cases
    USING (
      tenant_id IN (
        SELECT id FROM tenants
        WHERE path <@ (SELECT scope_path FROM users WHERE id = auth.uid())
      )
    );
    ```
    

---

# 🟡 PARTE B — LO QUE NO ME QUEDÓ CLARO

---

## 16. ✅ Suposiciones validadas (S1-S15)

Estas eran las suposiciones de la v1. Esta es la **respuesta de Javier** del 8 de mayo. ✅ = confirmada · ⚠️ = corregida.

| ID | Suposición | Resultado |
| --- | --- | --- |
| S1 | Cada CASO pertenece a UN solo CLIENTE | ✅ Confirmado |
| S2 | Una SESIÓN DE ATENCIÓN puede tocar VARIOS casos del mismo cliente | ✅ Confirmado |
| S3 | Un CASO solo tiene UN responsable a la vez (los demás son lectores) | ✅ Confirmado |
| S4 | Las QUEJAS son un tipo especial de CASO, no algo aparte | ✅ Confirmado |
| S5 | La SEDE se hereda al CASO desde el CONTRATO | ⚠️ Por ahora hay **una sola sede** (EEUU); el modelo lo soporta pero no se usa multi-sede en el MVP |
| S6 | Los CASOS ASOCIADOS son siempre del mismo CLIENTE y embarque | ✅ Confirmado (ver B4) |
| S7 | El cliente entra al portal con teléfono + PIN (o link directo) | ✅ Confirmado (ver B11) |
| S8 | Las PLANTILLAS son por FILIAL | ⚠️ **MAL — son globales**, no por filial |
| S9 | El estado RESUELTA se vuelve inmutable después de 7 días | ⚠️ **MAL — pasa a solo lectura 30 días tras la entrega del contenedor**, no 7 días |
| S10 | El cierre del caso es **manual**, no automático al iniciar contrato | ✅ Confirmado |
| S11 | Un EMBARQUE puede tener N casos hijos asociados (sección 11) | ✅ Confirmado |
| S12 | Documentos se versionan, nunca se borran (auditoría) | ✅ Confirmado |
| S13 | El cliente puede subir docs por portal, email Y WhatsApp | ✅ Confirmado |
| S14 | Las llamadas se graban, transcriben y guardan en `activities` | ✅ Confirmado |
| S15 | Multi-moneda: importes en local + USD reporting (con fx_rate) | ✅ Confirmado |

---

## 17. ✅ Decisiones cerradas con Javier (B1-B12)

Las 12 decisiones bloqueantes de la v1, **cerradas en la llamada del 8 de mayo**. Lo que queda marcado “→ dirección” lo confirma el papá, no es bloqueante para empezar.

### B1 — ¿El cierre de un caso es inmutable?

✅ **Editable hasta 30 días después de la entrega del contenedor al cliente; después pasa a solo lectura.** El plazo de 30 días es el margen real de reclamación: una vez que el cliente recibe y procesa el material, si no hay reclamo en 30 días, ya no lo hay. El **admin** puede seguir haciendo ajustes sobre un caso en solo lectura.

### B2 — ¿El cierre es manual o automático?

✅ **Manual.** El usuario decide cuándo cerrar — solo cuando ya no hay nada más que hacer en la operación.

### B3 — Una sesión de atención, ¿uno o varios casos?

✅ **Una sesión por cliente, que puede tocar varios casos del mismo cliente.**

### B4 — ¿Se pueden asociar casos de clientes distintos?

✅ **No.** Los casos asociados son siempre del **mismo cliente y mismo embarque**. Única excepción: un mismo cliente con un caso de embarque + un caso de queja (ver sección 11).

### B5 — ¿Co-responsables o uno solo?

✅ **Un responsable principal** por ahora. Se contempla añadir **observadores / un supervisor** con permiso de lectura por encima. Co-responsabilidad real (varios editan) no.

### B6 — ¿Casos asociados comparten documentos?

✅ **Híbrido.** Documentos a nivel **embarque** (BL, factura) compartidos entre los casos asociados; documentos de un **caso específico** quedan en ese caso.

### B7 — SLA: tiempos por estado

✅ **No hay tiempos fijos.** Los recordatorios y el escalado son **configurables según la sensibilidad del documento** (alta / media / baja), y el gestor del caso puede ajustarlos. A medida que se acerca el plazo, la sensibilidad sube → más recordatorios automáticos → y, si es muy sensible, una alerta al gestor para que llame al cliente. El TRIAGE en el MVP es casi inmediato (asignación automática).

### B8 — Roles y permisos

✅ Los **4 roles** (Holding-Admin, Filial-Admin, Operador, Cliente-externo) son correctos como diseño. **En el MVP todos ven todo**; el filtrado jerárquico por filial/sede se activa más adelante. No falta ningún rol intermedio por ahora.

### B9 — Retención de datos personales

✅ **Depende de la legislación de cada país** donde opera Valkam Capital. No se fija un único número — lo ajusta la dirección por país. → dirección.

### B10 — Idiomas iniciales del sistema

✅ **ES + EN.**

### B11 — Login del cliente al portal

✅ **Teléfono + PIN simple**, con **link directo** desde cada solicitud para que el cliente no tenga que teclear nada. El acceso debe ser lo más simple posible. *(Emanuel afina el detalle final del flujo.)*

### B12 — Tipos de solicitud

✅ Lista de partida confirmada por Javier: **Seguimiento de embarque · Cambio de BL (Switch BL) · Adjuntar documentación · Presentar queja · Otro.** La lista definitiva (y qué documentos pide cada tipo) la cierra la **dirección** al cruzar la info con el sistema actual. → dirección.

### B12b — Política de reembolsos

La cancelación a medias es una **solicitud** que Valkam Capital aprueba o no. Si hay reembolso de dinero ya pagado y bajo qué condiciones → lo define la **dirección**. → dirección.

---

## 18. 🚨 Edge cases — ¿qué pasa si…?

Cómo responde el sistema cuando las cosas fallan o se complican. El detalle técnico de cada caso lo cierra el equipo de desarrollo.

```
   ESCENARIO                          PROPUESTA Valkam Capital
   ─────────────────────              ──────────────────────────────────

   🚨 Cliente sube archivo            Bloqueamos antes de subir (validación
      malicioso (virus)               cliente + servidor con ClamAV edge fn)

   🚨 OCR falla con doc raro          AI confidence baja a 0% → forzamos
                                      revisión humana, no rompe el flow

   🚨 WhatsApp Cloud API caída        Fallback a SMS o solo email
                                      Notifier reintenta 3x con backoff

   🚨 Cliente pierde acceso al        Recuperación por:
      portal (cambió email/teléfono)   1. WhatsApp con CIF
                                       2. Email a contacto autorizado
                                       3. Llamada manual al gestor

   🚨 SLA por vencer (4h restantes)   Alerta al asignado + a su Filial-Admin
                                      Push + WhatsApp + email simultáneos

   🚨 SLA vencido sin acción          Auto-escala a Filial-Admin
                                      Notificación al Holding-Admin si >2 días

   🚨 Usuario interno deja la         Sus casos se reasignan a su jefe
      empresa                          Acceso revocado en <5 min
                                       Audit log marca quien era el dueño

   🚨 Cliente quiere cancelar         El cliente envía una SOLICITUD de
      trámite a medias                 cancelación; el equipo la aprueba o no.
                                       Si se aprueba → estado=CANCELLED, queda
                                       histórico. Reembolsos: ver B12b

   🚨 Documento aprobado pero         Botón "Marcar incorrecto" en doc aprobado
      después se descubre que es      Genera caso nuevo + audit + notif al cliente
      incorrecto                      No borra el doc original (auditoría)

   🚨 Cliente sube doc duplicado      Detectado por SHA256 → mostramos
      (mismo archivo)                 "ya está subido en caso #X"

   🚨 Conflicto de edición            Optimistic locking → último gana
      (2 usuarios editando            avisa al otro: "Ana editó hace 2 seg,
      mismo caso simultáneo)          ¿quieres ver sus cambios?"

   🚨 Cliente reclama "yo nunca       Audit log + activity log + IP + UA
      autoricé esto"                  Servimos PDF firmado en <1h

   🚨 OCR detecta sanción OFAC        BLOQUEA caso. Notif a Compliance Officer.
      en cliente                      Caso queda en estado COMPLIANCE_HOLD
                                       hasta resolución manual

   🚨 El pago es rechazado            Notif al cliente + intento con otro método
                                       Caso queda en ESPERA waiting_on=pago
                                       después de varios días sin pago → escalado
```

> **B12b — cancelación a medias**: la cancelación es una **solicitud** que Valkam Capital aprueba o no. Si hay reembolso de dinero ya pagado y bajo qué condiciones lo define la **dirección**.
> 

> ✅ **Validado por Javier (8 may):** los edge cases tienen sentido. El cómo se resuelve cada uno técnicamente (antivirus, OCR que falla, caída de WhatsApp, etc.) lo afina el equipo de desarrollo.
> 

---

## 19. ✅ Preguntas resueltas (consolidado por sección)

Consolidado de las preguntas pequeñas que estaban repartidas por el documento, con la respuesta de Javier del 8 de mayo.

| Sección | Pregunta | Respuesta |
| --- | --- | --- |
| § 2 | ¿Las 3 piezas (Web/Workspace/Backoffice) tienen sentido? | ✅ Sí |
| § 3 | ¿Renombrar “Sociedad” → “Filial” y “Explotación” → “Sede”? | ✅ Sí |
| § 3 | ¿Se mantiene la “personita verde/naranja”? | ✅ Sí — es la marca de la sesión de atención |
| § 4 | ¿Cualquier acción en un caso requiere sesión de atención? | ✅ Sí para modificar/gestionar; consultar no |
| § 5 | Al reasignar a otro depto, ¿quién es el responsable? | El otro depto; puede devolver el caso |
| § 6 | ¿Distinción “esperando cliente” vs “proveedor externo”? | ✅ Sí — 4 tipos de proveedor con icono |
| § 7 | ¿Cuáles son los tipos de solicitud? | Seguimiento, Cambio de BL, Adjuntar docs, Queja, Otro (→ dirección) |
| § 7 | ¿Personas físicas con DNI? | ❌ No — solo empresas (B2B) |
| § 7 | ¿Clientes sin CIF español/EU? | ✅ Sí — RUC, EIN, NIT, etc. |
| § 8 | ¿El cliente puede cancelar un trámite por sí mismo? | ❌ No — solo solicita la cancelación |
| § 9 | ¿La organización del menú tiene sentido? | ✅ Sí |
| § 10 | ¿Lista de documentos por tipo de trámite? | → dirección (al cruzar con el sistema actual) |
| § 10 | ¿Hay documentos que vencen? | ✅ Sí — avisar antes del vencimiento |
| § 10 | Al rechazar un doc, ¿el cliente sube solo el rechazado? | ✅ Sí, solo el rechazado |
| § 12 | ¿Cada cuánto el recordatorio? | Configurable por sensibilidad del documento |
| § 12 | ¿El recordatorio se detiene cuando el cliente responde? | ✅ Sí |
| § 12 | ¿Quién aprueba las plantillas de WhatsApp ante Meta? | → dirección |
| § 12 | ¿Plantillas por filial o globales? | Globales |
| § 13 | ¿Reportes operativos y ejecutivos? ¿Exportar a Excel/PDF? | ✅ Sí a todo |

---

# 🔵 PARTE C — CÓMO LO CONSTRUIMOS

---

## 20. 🚀 La apuesta de producto de Valkam Capital

> 💡 Esta sección es la apuesta de innovación. Javier la revisó el 8 de mayo y la aprobó (“perfecto”). La idea: no replicar el sistema modelo tal cual, sino modernizarlo con IA donde aporta valor real.
> 
> 
> ⚠️ **Importante:** las features de IA de abajo son la **visión completa**. En el **MVP (versión 0)** la IA **asiste** (resúmenes, clasificación, borradores) pero **no decide sola** — en particular, la validación de documentos siempre la hace una persona (ver sección 10). La automatización plena es una fase posterior.
> 

### Tagline propuesto

> **Valkam Capital: el CRM que prepara el trabajo. La IA resume, clasifica y propone; la persona aprueba y decide.**
> 

### 3 pilares de la narrativa para el jurado

1. **Inbox cero por default**: cada email/llamada/documento entrante llega ya **clasificado, enriquecido y con borrador de respuesta**. El humano aprueba, no procesa.
2. **Cuentas que se enriquecen solas**: ningún formulario manual; el agente investiga al crear la cuenta.
3. **Compliance proactivo**: el agente bloquea operaciones riesgosas antes de cerrar (sanciones, HS codes mal, docs vencidos).

### Las 7 features de IA killer

| # | Feature | Caso concreto |
| --- | --- | --- |
| 1 | **Ingesta documental multimodal** | Pegas un BL, extrae shipper/consignee/HS codes, valida vs factura, abre tarea si hay discrepancia |
| 2 | **Account Research Agent** | Crear cliente nuevo → busca registro mercantil, web, LinkedIn, OFAC, datos abiertos de aduana → dossier |
| 3 | **Voice AI** | Llamada → transcripción + diarización + extracción de compromisos + creación auto de tareas con due date |
| 4 | **Email triage + draft replies** | Email entrante → clasifica + rutea + redacta respuesta en idioma del remitente con datos del shipment |
| 5 | **Predictor de churn + dormant whales** | Detecta cliente que bajó pedidos 40% en 90 días → genera next-best-action explicable |
| 6 | **Workflow Agent multi-step** | Comando “verifica este BL” → en paralelo: HS + OFAC + cert. origen + duties + docs faltantes |
| 7 | **NL Query** | “muéstrame importadores brasileños con deals stuck >30 días” → SQL + sugerencia de acción |

### Demo killer para el jurado (28 segundos)

```
00:00  Demo arranca con Valkam Capital en blanco.
00:05  Usuario arrastra un PDF de Bill of Lading real al canvas.
00:08  Sistema dice: "Detecté un BL Shanghái → Cartagena, 18 contenedores
                     de cobre catódico. ¿Creo el shipment?"
00:11  Usuario: "sí"
00:12  En 3 segundos:
        ✅ Shipment creado y linkeado al cliente
        ✅ Cuenta del importador enriquecida (OFAC clean)
        ✅ HS codes validados contra arancel Colombia 2026
        ✅ Certificado de origen detectado vencido → tarea auto
        ✅ Duties estimados: USD 47,230
        ✅ Email draft al cliente generado en español
00:25  Usuario aprueba con un click. Email enviado por WhatsApp + email.
00:28  Sistema: "Si no responde en 48 h, escalo a tu jefe."

Total: 28 segundos. Cero formularios. Cero menús anidados.
       Lo que en el sistema viejo toma 25 minutos y 6 pantallas.
```

### Las 3 pantallas que ven el jurado (mockup paso a paso)

```
PANTALLA 1: ARRASTRA EL PDF
─────────────────────────────────────────────────
┌──────────────────────────────────────────────┐
│ Valkam Capital                       [Cmd+K] │
├──────────────────────────────────────────────┤
│                                              │
│           ┌─ ─ ─ ─ ─ ─ ─ ─ ─ ─┐              │
│           │                   │              │
│           │    ⬇ Arrastra     │              │
│           │     un BL aquí    │              │
│           │                   │              │
│           │   o pega texto,   │              │
│           │   audio, imagen   │              │
│           │                   │              │
│           └─ ─ ─ ─ ─ ─ ─ ─ ─ ─┘              │
│                                              │
└──────────────────────────────────────────────┘

PANTALLA 2: AI ANALIZA Y PROPONE
─────────────────────────────────────────────────
┌──────────────────────────────────────────────┐
│ 🤖 Analizando BL-MSCU1234567.pdf...           │
├──────────────────────────────────────────────┤
│                                              │
│  ✅ Shipper: COBRE COLOMBIA SAS               │
│  ✅ Consignee: HOUSTON COPPER LLC             │
│  ✅ Ruta: Shanghái → Cartagena (18 cont.)     │
│  ✅ HS Code: 7403.11 (cobre catódico)         │
│  ⚠ Cert. de origen vencido                   │
│                                              │
│  ▶ Acciones propuestas:                      │
│   ▸ Crear shipment + 4 casos asociados       │
│   ▸ Validar OFAC del consignee               │
│   ▸ Calcular duties estimados                │
│   ▸ Pedir nuevo cert. origen al cliente      │
│   ▸ Draft email en español                   │
│                                              │
│              [ Aprobar todo ]                │
└──────────────────────────────────────────────┘

PANTALLA 3: TODO HECHO EN 3 SEGUNDOS
─────────────────────────────────────────────────
┌──────────────────────────────────────────────┐
│ ✅ Shipment VLK-SHIP-2026-0421 creado         │
├──────────────────────────────────────────────┤
│                                              │
│  Casos creados:                              │
│   📋 #VLK-0421 Cotización      ACTIVA        │
│   📋 #VLK-0422 Documentos      ESPERA(client)│
│   📋 #VLK-0423 Inspección      TRIAGE        │
│   📋 #VLK-0424 Facturación     TRIAGE        │
│                                              │
│  Cliente enriquecido:                        │
│   🏢 HOUSTON COPPER LLC                       │
│   ✅ OFAC clean (verificado AI)               │
│   📊 risk_score: 12/100 (bajo)               │
│                                              │
│  Email enviado:                              │
│   📧 a Marta López + WhatsApp                │
│   "Hola Marta, recibimos tu BL. Necesitamos  │
│    un cert. de origen vigente. Aquí el link  │
│    para subirlo: valkamcapital.com/u/xyz123"   │
│                                              │
│  ⏰ Duties estimados: USD 47,230              │
│                                              │
└──────────────────────────────────────────────┘
```

### Caso resuelto en 3 taps (mobile) — mockup pantalla por pantalla

```
   TAP 1                  TAP 2                  TAP 3
   ─────────              ─────────              ─────────

   ┌──────────────┐      ┌──────────────┐      ┌──────────────┐
   │ 📲 9:42      │      │ 📲 9:42      │      │ 📲 9:43      │
   │ VLK-2026-0421│      │ VLK-2026-0421│      │ VLK-2026-0421│
   ├──────────────┤      ├──────────────┤      ├──────────────┤
   │ 🤖 Resumen IA│      │ ✅ Tomado    │      │ ¿Qué hiciste?│
   │              │      │              │      │              │
   │ Cliente:     │      │ Estado: 🟡   │      │  ⚪ Resuelto │
   │ Construc.    │      │ Asignado: tú │      │     en sitio │
   │ Bonifacio    │      │              │      │  ⚪ Re-cita  │
   │              │      │ ✉ Draft IA: │      │  ⚪ Escalado │
   │ Reporte hace │      │ "Hola Marta, │      │  ⚪ Otro     │
   │ 2 días, está │      │  voy en      │      │              │
   │ a 4 km de tu │      │  camino,     │      │ [Cerrar]     │
   │ ruta.        │      │  ETA 25 min" │      │              │
   │              │      │              │      │              │
   │ [Tomar caso] │      │ [Editar][📤] │      │              │
   └──────────────┘      └──────────────┘      └──────────────┘

   👆 push notif         👆 1 click [📤]        👆 1 click chip
   abre directo          envía con WhatsApp     cierra el caso
   al caso
```

**Total: 3 taps · 0 campos a mano · sentiment + categoría auto-capturados**

Comparación con sistema viejo: el mismo caso requeriría:
- 6 pantallas distintas
- ~12 clicks
- Llenar 8-10 campos a mano
- Cambiar estado manualmente 3 veces

### Las 10 reglas UX no negociables

1. Cmd+K command palette contextual
2. Keyboard-first con shortcuts mnemónicos
3. Optimistic UI sin spinners ni botón “Guardar”
4. Inbox unificado (email + WhatsApp + llamadas en un feed)
5. Progressive disclosure — máximo 5 campos en creación
6. 3 vistas intercambiables (Tabla / Kanban / Timeline)
7. Time-to-first-value < 5 min con caso demo sembrado
8. Mobile-first PWA con offline-sync
9. Diseño consistente, microanimaciones tipo Linear
10. Smart defaults + autocompletado contextual

> ✅ **Validado por Javier (8 may):** apuesta aprobada. La automatización plena de la IA llega por fases — en el MVP la IA asiste y la persona decide.
> 

---

## 21. 🛠️ Stack técnico + roadmap 12 semanas

> 🖱️ **Esto es un dropdown — haz click en el bloque de abajo (`▸ Stack y roadmap`) para expandirlo.**
👀 **No es para ti, Javier.** Lo abre el equipo dev. Pero si quieres ver con qué tecnología lo construimos, click y se abre.
> 
- Click aquí para abrir → Stack y roadmap
    
    ### Stack
    
    ```
    Frontend       Next.js 15 App Router + RSC + TypeScript + Tailwind + shadcn/ui
                   + Framer Motion · next-intl (ES/EN route-based)
    
    Auth           Interno: usuario + contraseña. Cliente: teléfono + PIN /
                   link directo. Supabase Auth + Custom JWT con scope_path
    
    DB             Postgres 16 + ltree + Row-Level Security + pgvector
                   · Single-schema, NO schema-per-tenant
    
    BaaS           Supabase (BD + Storage + Realtime + Edge Functions)
    
    Notifs         Resend (email) + Twilio WhatsApp Cloud API + SMS fallback
    
    Pagos          Stripe Customer Portal embebido
    
    OCR / Vision   AWS Textract + Google Document AI + GPT-4V / Gemini 2.5 Pro
    
    LLM (agents)   Claude Opus 4.7 + Sonnet 4.5 + Haiku 4.5 + Gemini 2.5 Flash audio
    
    Búsqueda       Postgres GIN tsvector + pgvector
    ```
    
    **Costo estimado LLM**: USD 800-1500/mes para 50 usuarios B2B activos (con prompt caching, -90%).
    
    ### Roadmap 12 semanas
    
    | Semanas | Bloque |
    | --- | --- |
    | 1-2 | Schema + auth + multi-tenant (RLS + ltree + 4 roles) |
    | 3-4 | Workspace + Casos + Activities + Inbox unificado |
    | 5-6 | Portal cliente + Document validation workflow + magic links |
    | 7-8 | AI Agents 1-2 (ingesta documental + email triage) |
    | 9 | Backoffice (contratos + facturas + Stripe) |
    | 10 | Mobile PWA + offline-sync |
    | 11 | AI Agents 3-7 (voice, research, churn, workflow agent, NL query) |
    | 12 | Polish + demo killer + onboarding role-based |

---

## 22. ❓ FAQ — preguntas que probablemente tengas

### “¿Por qué tantos cambios vs el sistema modelo? Ese funciona.”

Funciona, sí. Pero es un sistema diseñado en los 2000s para utility de agua. Si lo replicamos tal cual, heredamos ~40% de complejidad que no aplica al trading internacional de chatarra. La idea es tomar lo bueno (la lógica de Casos, Atenciones, Plantillas) y modernizarlo (IA, mobile, inbox unificado).

### “¿Por qué 4 estados y no 7?”

Porque la mitad eran metadata. En el sistema modelo había 7 etiquetas, pero lo que cambia operativamente son 4 cosas: nadie lo tocó (TRIAGE), alguien trabaja (ACTIVA), alguien espera algo (ESPERA), o terminó (RESUELTA). Las otras eran indicadores de “qué” se está esperando o “quién” lo tiene asignado — eso ahora son columnas separadas. La interfaz puede seguir mostrando varios colores e iconos; la base de datos solo guarda 4 estados.

### “¿Vamos a construir esto en 12 semanas? Suena agresivo.”

Sí, es agresivo, pero realista con el stack moderno (Next.js + Supabase). El factor crítico es **no inventar lo que ya está resuelto**: usamos Stripe en vez de programar pagos, usamos Resend en vez de programar email, usamos Clerk en vez de programar auth, usamos OCR de Google/AWS en vez de programar OCR. Cada uno cuesta USD 50-300/mes pero ahorra meses de desarrollo.

### “¿Qué pasa si un cliente no tiene WhatsApp?”

El sistema respeta la preferencia. Si el cliente solo dejó email, mandamos email. WhatsApp es opt-in. El servicio `Notifier` decide el canal en base a `customer.communication_preferences`.

### “¿Y si Meta rechaza nuestras plantillas de WhatsApp?”

Hay un proceso de aprobación con Meta Business que toma 1-3 días. Las plantillas las redactamos siguiendo sus guidelines (transaccionales sí, marketing no sin opt-in explícito). Nunca rechazan plantillas correctas. Si rechazan, se ajustan y reenvían — yo gestiono esto.

### “¿Necesitamos un equipo grande para esto?”

No. Para el MVP de 12 semanas: 1 fullstack senior + 1 designer + 1 PM (tú o yo). Para escalar después: 2-3 más. La IA (Claude, Cursor) acelera 3-4× el desarrollo en stacks modernos.

### “¿Qué pasa si me equivoco en una respuesta de las B1-B12?”

Hablamos de iteraciones. Mejor responder algo y corregir después que no responder y bloquearnos. Cada decisión es reversible los primeros 30 días de implementación.

### “¿Esto va a competir con Salesforce / HubSpot?”

No directamente. Estos CRMs son horizontales (sirven para todo). El sistema de Valkam Capital es vertical: específico para el trading internacional de chatarra y cobre, con lectura de BL, validación de HS codes, control de proveedores logísticos, etc. **Vertical wins**: encaja con la operación real de la empresa, no al revés.

### “¿Y la migración de datos del sistema modelo?”

Si quieres traer datos del sistema viejo, lo hacemos en semana 11-12 con scripts ETL. Si empezamos en blanco, más rápido. Tu decisión.

---

## 23. 🔁 Próximos pasos

```
1 May    → Zoom con Javier (sistema modelo, 62 imágenes)            ✅ hecho
5 May    → v1 del blueprint enviada para revisión                   ✅ hecho
8 May    → Llamada de feedback de Javier (revisión punto por punto) ✅ hecho
18 May   → v2 final del blueprint (este documento)                  ✅ hecho
Próximo  → Cruzar este blueprint con el sistema actual de la empresa
           y cerrar con la dirección los puntos marcados "→ dirección"
Después  → Arranca la construcción de la versión 0
~12 sem  → MVP listo
```

### 🧪 Plan de testing v0 — Javier es el usuario 0

```
   Semana 4-5  →  Construimos workspace básico
                  Javier es el primer usuario que prueba
                  Cuenta de "Operador" con 5 casos de prueba reales
                  Medimos: ¿cuántos clicks? ¿cuánto tiempo?

   Semana 6-7  →  Construimos portal cliente
                  Cuenta ficticia sube docs reales; el equipo valida
                  Medimos: ¿se entiende el flujo?

   Semana 8-9  →  Apoyo de IA (resúmenes de documentos)
                  Probamos lectura de BLs, facturas, packing lists reales
                  Medimos: ¿la IA acierta el resumen? ¿cuánto?

   Semana 10   →  Beta privada con 2-3 clientes reales de confianza
                  Feedback real de usuarios externos

   Semana 11   →  Iteración rápida según feedback

   Semana 12   →  Pulido final + onboarding
```

### 📦 Migración de datos del sistema actual

Decisión a cerrar con la dirección al cruzar este blueprint con el sistema actual de la empresa:

```
   OPCIÓN A — Empezar en blanco
   ─────────────────────────────
   ✅ Más rápido (no hay ETL que hacer)
   ✅ Datos limpios desde día 1
   ❌ Se pierde el histórico de los clientes actuales

   OPCIÓN B — Migrar todos los datos del sistema actual
   ─────────────────────────────────────────────────────
   ✅ Histórico completo (contratos, casos, facturas)
   ❌ +2-3 semanas de trabajo

   OPCIÓN C — Híbrida (recomendada)
   ─────────────────────────────────
   ✅ Migrar solo CLIENTES + CONTRATOS activos (lo crítico)
   ✅ Histórico de casos cerrados queda en el sistema actual (consulta)
   ✅ Acelera el go-live sin perder lo importante
```

> **Decisión**: A / B / C → la cierra la dirección.
> 

### Pendientes para cerrar con la dirección (no bloquea a Javier)

1. Lista definitiva de tipos de solicitud y de documentos por tipo (B12, § 10).
2. Política de retención de datos por país (B9).
3. Política de reembolsos en cancelaciones (B12b).
4. Quién aprueba las plantillas de WhatsApp ante Meta (§ 12).
5. Responsable de protección de datos / DPO (§ 13).
6. Decisión de migración A/B/C.

### Próximo paso del equipo de desarrollo

1. Tomar este blueprint v2 como base.
2. Documentar el sistema actual de la empresa con el mismo formato.
3. Cruzar ambos para definir qué se reutiliza y qué se construye nuevo.
4. Arrancar la versión 0 con el roadmap de la sección 21.

### 🔌 APIs y webhooks (para devs y futuras integraciones)

> Toggle colapsable. **No es para ti, Javier.**
> 
- Click aquí para abrir → APIs públicas y webhooks
    
    Valkam Capital expone una API REST + webhooks desde día 1 para futuras integraciones:
    
    **API REST (autenticada con API key por filial):**
    - `GET /v1/cases` — listar casos con filtros
    - `POST /v1/cases` — crear caso (útil para integrar con otro CRM legacy)
    - `GET /v1/cases/{id}` — detalle del caso
    - `POST /v1/documents` — upload documento
    - `GET /v1/customers/{id}/timeline` — timeline unificado del cliente
    - `POST /v1/notifications` — disparar notificación manual
    
    **Webhooks salientes** (Valkam Capital golpea URL del partner cuando):
    - `case.created` — nuevo caso entró al sistema
    - `case.assigned` — asignado a un usuario
    - `case.status_changed` — cambió de estado
    - `case.resolved` — resuelto
    - `document.uploaded` — cliente subió doc
    - `document.approved` / `document.rejected`
    - `invoice.paid` (vía Stripe)
    - `sla.breach_warning` — SLA por vencer (según el plazo configurado del caso)
    - `sla.breached` — SLA vencido
    
    **Casos de uso futuros**:
    - Integrar con sistema de aduanas
    - Conectar con tracking de navieras (Maersk, MSC, CMA-CGM APIs)
    - Sincronizar con contabilidad (Holded, Xero, QuickBooks)
    - Disparar workflows en Zapier/Make/n8n
    
    **Seguridad**:
    - HMAC signature en cada webhook
    - Reintentos con backoff exponencial
    - Dashboard de webhooks fallidos
    

---

> 📌 **Versión 2 — final.** Incorpora todo el feedback de Javier de la llamada del 8 de mayo.
📌 Los puntos marcados “→ dirección” se cierran con el papá; no bloquean el arranque del desarrollo.
📌 El siguiente paso es cruzar este blueprint con el sistema actual de la empresa.
>