<?php
// ValkamSync — petit.valkamgm.com @ Hostgator (producción)
// chmod 600 en el server.

// ---------- DB: SQLite en data/ (dentro del docroot, bloqueada por .htaccess) ----------
define('DB_DRIVER', 'sqlite');
define('DB_PATH',   __DIR__ . '/data/valkamsync.db');

// ---------- Parser PDF ----------
define('PDFTOTEXT_BIN', 'pdftotext');

// ---------- Maildir PeonyInc (Hostgator local filesystem) ----------
define('MAIL_ROOT', ($_SERVER['HOME'] ?? getenv('HOME')) . '/mail/valkamgm.com/info');

// ---------- Gemini API ----------
// PEGA TU KEY NUEVA AQUÍ (tras revocar la anterior):
//    https://aistudio.google.com/app/apikey
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL',   'gemini-2.5-flash');

// ---------- Prod flag ----------
define('IS_PRODUCTION', true);
