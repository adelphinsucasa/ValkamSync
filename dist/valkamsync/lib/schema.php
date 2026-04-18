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

    // Idempotent unique + indexes
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
}
