<?php
define('DB_DRIVER', 'sqlite');
define('DB_PATH',   __DIR__ . '/data/valkamsync.db');
define('PDFTOTEXT_BIN', 'pdftotext');
define('MAIL_ROOT', (getenv('HOME') ?: '/home/user') . '/mail/valkamgm.com/info');
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL',   'gemini-2.5-flash');
define('IS_PRODUCTION', false);
