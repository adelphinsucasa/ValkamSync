<?php
declare(strict_types=1);

/**
 * Schema bootstrap — idempotent. Runs on every API call in < 1ms after first.
 * Table prefix `vsync_` isolates ValkamSync from the pre-existing Valkam app.
 */
function initDB(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // Dialect tokens
    $PK  = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $TS  = $driver === 'sqlite' ? "TEXT DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
    $TSN = $driver === 'sqlite' ? "TEXT" : "TIMESTAMP NULL";
    $ENG = $driver === 'sqlite' ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_peony_files (
            id              $PK,
            filename        VARCHAR(255) NOT NULL,
            file_date       DATE NOT NULL,
            email_ts        BIGINT,
            sha256          VARCHAR(64) NOT NULL,
            size            INT NOT NULL,
            row_count       INT DEFAULT 0,
            parser_version  VARCHAR(32),
            imported_at     $TS,
            sync_status     VARCHAR(20) DEFAULT 'pending',
            synced_at       $TSN,
            notes           TEXT
        ) $ENG
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_peony_prices (
            id              $PK,
            file_id         INT NOT NULL,
            file_date       DATE NOT NULL,
            category        VARCHAR(32) NOT NULL,
            material        VARCHAR(200) NOT NULL,
            price_raw       VARCHAR(100) NOT NULL,
            price_num       DECIMAL(12,4),
            price_unit      VARCHAR(20),
            delivery_basis  VARCHAR(100),
            company         VARCHAR(150),
            buyer           VARCHAR(150),
            phone           VARCHAR(40),
            row_status      VARCHAR(20) DEFAULT 'ok',
            created_at      $TS
        ) $ENG
    ");

    // Clientes capturados por escaneo (IA Gemini). Key = phone.
    // Coexiste con el directorio derivado de peony_prices; la UI los une por phone.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_clients_manual (
            id            $PK,
            phone         VARCHAR(40) NOT NULL,
            company       VARCHAR(150),
            buyer         VARCHAR(150),
            source        VARCHAR(20) DEFAULT 'scan',
            first_scan_at $TS,
            last_scan_at  $TS,
            scan_count    INT DEFAULT 0
        ) $ENG
    ");

    // Eventos append-only: cada escaneo crea una fila, con snapshot de datos al momento.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_client_scans (
            id                $PK,
            phone             VARCHAR(40) NOT NULL,
            scan_date         DATE NOT NULL,
            material          VARCHAR(200),
            price_raw         VARCHAR(100),
            price_num         DECIMAL(12,4),
            notes             TEXT,
            company_snapshot  VARCHAR(150),
            buyer_snapshot    VARCHAR(150),
            image_sha256      VARCHAR(64),
            confidence        VARCHAR(20),
            created_at        $TS
        ) $ENG
    ");

    // Estado de contacto por cliente (key natural: phone)
    // status: pending | scheduled | contacted | discarded
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_client_status (
            phone              VARCHAR(40) NOT NULL,
            status             VARCHAR(20) NOT NULL DEFAULT 'pending',
            next_contact_date  DATE,
            last_contact_at    $TSN,
            note_summary       VARCHAR(200),
            updated_at         $TS,
            PRIMARY KEY (phone)
        ) $ENG
    ");

    // Notas de conversación por cliente (key natural: phone)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_client_notes (
            id         $PK,
            phone      VARCHAR(40) NOT NULL,
            note       TEXT NOT NULL,
            author     VARCHAR(80),
            created_at $TS
        ) $ENG
    ");

    // Per manual V1.3: SELECT fecha FROM logs ORDER BY id DESC LIMIT 1
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_logs (
            id         $PK,
            event      VARCHAR(50) NOT NULL,
            status     VARCHAR(20) NOT NULL,
            details    TEXT,
            file_count INT DEFAULT 0,
            row_count  INT DEFAULT 0,
            fecha      $TS
        ) $ENG
    ");

    // =========================================================
    // AUTH — credenciales y sesiones
    // =========================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_auth (
            id             $PK,
            username       VARCHAR(50)  NOT NULL,
            password_hash  VARCHAR(255) NOT NULL,
            pin_hash       VARCHAR(255) NOT NULL,
            updated_at     $TS
        ) $ENG
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_sessions (
            id          $PK,
            token       VARCHAR(64)  NOT NULL,
            remember    TINYINT(1)   DEFAULT 0,
            expires_at  $TSN,
            created_at  $TS
        ) $ENG
    ");

    // Seed default admin (solo si la tabla está vacía)
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM vsync_auth")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO vsync_auth (username, password_hash, pin_hash) VALUES (?, ?, ?)"
            );
            $stmt->execute([
                'admin',
                password_hash('Valkam2026!',  PASSWORD_DEFAULT),
                password_hash('11223344',     PASSWORD_DEFAULT),
            ]);
        }
    } catch (Throwable $e) {}

    // Limpiar sesiones expiradas (housekeeping ligero en cada request)
    try {
        $expSql = $driver === 'sqlite'
            ? "DELETE FROM vsync_sessions WHERE expires_at < datetime('now')"
            : "DELETE FROM vsync_sessions WHERE expires_at < NOW()";
        $pdo->exec($expSql);
    } catch (Throwable $e) {}

    // =========================================================
    // LME Cache — precios spot LME por metal y fecha
    // =========================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vsync_lme_cache (
            id          $PK,
            metal       VARCHAR(10) NOT NULL,
            price_date  DATE NOT NULL,
            cash_usd    DECIMAL(14,4) NOT NULL,
            source      VARCHAR(20) DEFAULT 'metalradar',
            fetched_at  $TS
        ) $ENG
    ");

    // =========================================================
    // LME — Migraciones idempotentes (ALTER TABLE ignorado si columna ya existe)
    // =========================================================

    // Columnas originales F7 (retrocompatibles)
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_resolved TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_price    DECIMAL(14,4) DEFAULT NULL"); }    catch (Throwable $e) {}

    // Matriz completa de 4 puntos LME (valores crudos de la API — auditoría)
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_cash_buyer         DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_cash_seller        DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_3_months_buyer     DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_3_months_seller    DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}

    // Campos de decisión del cálculo (trazabilidad)
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_percentage_applied DECIMAL(7,4)  DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_base_price_used    DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_type_used          VARCHAR(20)   DEFAULT NULL"); } catch (Throwable $e) {}

    // Registro de error cuando la resolución LME falló (sin detener el import)
    // lme_resolved = -1 indica fallo permanente; lme_error describe la causa.
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN lme_error              TEXT          DEFAULT NULL"); } catch (Throwable $e) {}

    // Rango de precio: min y max para price_raw con formato "0.28-5.00".
    // price_num almacena el punto medio; price_num_min/max guardan los extremos para auditoría y UI.
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN price_num_min DECIMAL(12,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_peony_prices ADD COLUMN price_num_max DECIMAL(12,4) DEFAULT NULL"); } catch (Throwable $e) {}

    // Columnas de la matriz 4 puntos en vsync_lme_cache (extiende el formato legacy cash_usd)
    try { $pdo->exec("ALTER TABLE vsync_lme_cache ADD COLUMN cash_buyer          DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_lme_cache ADD COLUMN cash_seller         DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_lme_cache ADD COLUMN three_months_buyer  DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE vsync_lme_cache ADD COLUMN three_months_seller DECIMAL(14,4) DEFAULT NULL"); } catch (Throwable $e) {}
    // trade_date: fecha real de cotización LME usada (puede diferir de price_date en fines de semana)
    try { $pdo->exec("ALTER TABLE vsync_lme_cache ADD COLUMN trade_date          DATE          DEFAULT NULL"); } catch (Throwable $e) {}

    // Idempotent unique + indexes
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_vsync_auth_user    ON vsync_auth(username)"); }     catch (Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_vsync_sessions_tok ON vsync_sessions(token)"); }    catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX        IF NOT EXISTS ix_vsync_sessions_exp ON vsync_sessions(expires_at)"); } catch (Throwable $e) {}

    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_peony_files_sha ON vsync_peony_files(sha256)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_peony_prices_date_cat ON vsync_peony_prices(file_date, category)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_peony_prices_material ON vsync_peony_prices(material)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_peony_prices_company ON vsync_peony_prices(company)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_peony_files_sync ON vsync_peony_files(sync_status)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_peony_files_date ON vsync_peony_files(file_date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_logs_fecha ON vsync_logs(fecha)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_client_notes_phone ON vsync_client_notes(phone)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_client_status_next ON vsync_client_status(next_contact_date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_client_status_status ON vsync_client_status(status)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_clients_manual_phone ON vsync_clients_manual(phone)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_client_scans_phone ON vsync_client_scans(phone)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_client_scans_date  ON vsync_client_scans(scan_date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_lme_cache_metal_date ON vsync_lme_cache(metal, price_date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_lme_cache_date ON vsync_lme_cache(price_date)"); }       catch (Throwable $e) {}
}
