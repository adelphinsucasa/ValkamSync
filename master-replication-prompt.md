# Master Prompt: Replicate a Local-Service Business Website + CRM on HostGator Shared Hosting

You are building a local-service business website with lead generation, a CRM, and email delivery -- all on HostGator shared hosting behind Cloudflare CDN. This prompt gives you everything you need to replicate the proven architecture of carolinaglassreplacement.com for a NEW business on the SAME server.

---

## Server Access

- **Host**: HostGator shared hosting
- **SSH**: `ssh -p 2222 gzcapita@192.254.232.58` (alias: `hostgator`)
- **Shell**: cPanel jailshell (restricted -- no sudo, no root, no uapi CLI)
- **cPanel account**: `gzcapita` (prefix for all DBs/users: `gzcapita_`)
- **Server hostname**: `gator3215.hostgator.com`
- **PHP version**: 8.1-8.3 (check with `ssh hostgator 'php -v'`)
- **Home directory**: `/home/gzcapita` or `/home4/gzcapita` (varies after migrations -- always use `$_SERVER['HOME']` in PHP, never hardcode)

### SSH Key Setup (REQUIRED FIRST STEP)

You do NOT have SSH access yet. You need to generate a key pair so the operator can authorize it on the server.

**Step 1: Generate an SSH key pair on YOUR machine:**
```bash
ssh-keygen -t rsa -b 4096 -f ~/.ssh/hostgator_key -C "ai-project-access"
```
This creates two files:
- `~/.ssh/hostgator_key` (private key -- stays on your machine)
- `~/.ssh/hostgator_key.pub` (public key -- needs to be uploaded to server)

**Step 2: Show the public key to the operator:**
```bash
cat ~/.ssh/hostgator_key.pub
```
Print the FULL contents of this file and tell the operator:
> "Please upload this public key to cPanel > SSH Access > Import Key, then click Authorize next to it. Once done, tell me and I'll test the connection."

The operator will:
1. Go to cPanel > Security > SSH Access > Manage SSH Keys
2. Click "Import Key"
3. Paste the public key contents into the "Public Key" field
4. Give it a name (e.g., `ai_project_key`)
5. Click "Import"
6. Click "Manage" next to the new key, then "Authorize"

**Step 3: Once the operator confirms the key is authorized, configure SSH:**
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh

cat >> ~/.ssh/config << 'EOF'
Host hostgator
    HostName 192.254.232.58
    User gzcapita
    Port 2222
    IdentityFile ~/.ssh/hostgator_key
    LogLevel QUIET
EOF

chmod 600 ~/.ssh/config
```

**Step 4: Test the connection:**
```bash
ssh hostgator 'echo "SSH connection successful" && php -v && echo "Home: $HOME"'
```
If this prints PHP version and home directory, you're in. If it hangs at banner exchange, see SSH Gotchas below.

### SSH Config (final state after setup):
```
Host hostgator
    HostName 192.254.232.58
    User gzcapita
    Port 2222
    IdentityFile ~/.ssh/hostgator_key
    LogLevel QUIET
```

### What SSH Access Gives You
Once authorized, this single key unlocks everything:
- Full shell access to all sites on the server
- Read/write any file under `~/` (all domains, all configs, all logs)
- Read any email folder: `ls ~/mail/<domain>/hello/new/`
- Create databases: `/usr/local/cpanel/bin/cpmysqlwrap ADDDB name`
- Create DB users: via Perl `Cpanel::AdminBin::Call` (see Section 6)
- Deploy via rsync over SSH
- Read Apache access logs: `~/access-logs/`
- Read PHP error logs: `~/<domain>/error_log`
- Manage cPanel email, SSL, and subdomains via AdminBin calls

### SSH Gotchas
- fail2ban bans your IP after a few failed/rapid connections. If SSH hangs at banner exchange but `nc -zv 192.254.232.58 2222` shows port OPEN, your IP is rate-limited. Switch to VPN/hotspot for instant bypass; or wait ~1h.
- Batch SSH work into single commands with `&&` chains to minimize connections.
- NEVER put secrets on SSH command lines (they leak to shell history + `ps`).

---

## Architecture Overview

This stack is intentionally simple. NO Node.js runtime, NO React, NO Docker, NO build system beyond a shell script. Everything runs on vanilla PHP + Apache + MySQL.

### Main Site (`src/` -> `deploy/` -> server)
```
src/
  pages/          # PHP page templates (index.php, about.php, contact.php, etc.)
  pages/services/ # Service-specific pages
  pages/blog/     # Blog posts
  pages/service-areas/  # City-specific SEO pages
  includes/       # Shared components (header.php, footer.php, config.php, schema.php, form.php)
  data/           # Static JSON (cities.json, services.json, zip-codes.json)
  css/            # Tailwind input CSS
  js/             # Vanilla JS (form validation, mobile menu, analytics)

deploy/           # Built output (NEVER edit directly except api.php and api-*.php)
  api.php         # Quote form handler (rate limiting, validation, email via Resend)
  api-email.php   # Email body builders (extracted for testability)
  api-smtp-transport.php  # SMTP transport helpers
  .glass_config.php       # Secrets (Resend key, DB creds) -- excluded from rsync
  .htaccess               # Apache config (rewrites, security, caching)
  vendor/phpmailer/       # Vendored PHPMailer (3 files, no Composer)
```

### CRM (`deploy-crm/` -- edited directly, no build step)
```
deploy-crm/
  index.html      # Complete frontend (HTML + CSS + vanilla JS, single PWA file)
  crm_api.php     # Complete backend (~1300 lines, 25+ endpoints)
  .crm_config.php # MySQL creds + PIN codes + JWT secret -- excluded from rsync
  .htaccess       # Security headers
  sw.js           # Service worker (offline cache)
  manifest.json   # PWA manifest
  router.php      # PHP dev server router (NOT deployed)
  uploads/        # Job photos
    .htaccess     # Blocks PHP execution in uploads dir
    photos/<job_id>/  # Photos per job
```

### Email
- **Provider**: Resend (free tier: 100 emails/day, 3000/month)
- **Transport**: PHPMailer via SMTP to `smtp.resend.com:465` (implicit TLS)
- **Vendored**: PHPMailer 6.9.x -- 3 files downloaded via `download-phpmailer.sh`, no Composer
- **From address**: Business email on cPanel Dovecot mailbox (real mailbox, NOT a forwarder)

### CDN / Security
- **Cloudflare**: Free tier, Full SSL mode, page caching, gzip, HTTP/2+3
- **WAF**: US-only geo-block with exemptions for bots, health endpoints, webhooks
- **Cache Rules**: `Bypass cache for API` paths, `Cache everything 1 day` for static

### Testing
- **Framework**: Playwright (E2E)
- **Main site tests**: `npx playwright test` (uses `php -S` dev server + `tests/router.php`)
- **CRM tests**: `npx playwright test --config playwright.crm.config.ts`
- **Unit tests**: `php tests/unit/*.test.php` (standalone, no framework)

---

## Step-by-Step: Create a New Project

### 1. Domain + Cloudflare Setup

```bash
# On HostGator cPanel web UI (or via cPanel File Manager):
# 1. Add the domain as an "Addon Domain" or "Alias" in cPanel
# 2. Point it to ~/newdomain.com/
# 3. In Cloudflare: add domain, set nameservers, enable "Full" SSL mode
# 4. Add A record pointing to 192.254.232.58
```

### 2. Create Project Structure Locally

```
newproject/
  src/
    pages/          # Your PHP pages
    includes/       # header.php, footer.php, config.php, schema.php, form.php
    data/           # JSON data files
    css/
      input.css     # Tailwind input (imports + custom theme)
    js/             # Vanilla JS files
  deploy/
    api.php         # Lead form handler
    api-email.php   # Email body builder
    .htaccess       # Apache config
    vendor/phpmailer/  # PHPMailer files
  deploy-crm/       # CRM app (if needed)
  tests/
    e2e/            # Playwright specs
    unit/           # PHP unit tests
    router.php      # Dev server router (mirrors .htaccess rewrites)
  build.sh          # Build script
  deploy.sh         # Deploy script
  deploy-crm.sh     # CRM deploy script
  download-phpmailer.sh
  playwright.config.ts
  .gitignore
  CLAUDE.md
```

### 3. Tailwind CSS 4 (Standalone Binary, No Node)

```bash
# Download for your platform
curl -sLO https://github.com/tailwindlabs/tailwindcss/releases/latest/download/tailwindcss-macos-arm64
chmod +x tailwindcss-macos-arm64
mkdir -p bin
mv tailwindcss-macos-arm64 bin/tailwindcss

# Build CSS
./bin/tailwindcss -i src/css/input.css -o deploy/css/style.css --minify
```

Your `src/css/input.css` defines the theme:
```css
@import "tailwindcss";

@theme {
  --color-brand: #1B4D3E;
  --color-brand-dark: #0F2E25;
  --color-accent: #2E86AB;
  --color-accent-dark: #1A5C7A;
  --color-warm-white: #FAF8F5;
  --color-warm-gray: #F0ECE6;
  --color-warm-gray-dark: #D4CFC7;
  --color-text: #2D2A26;
  --color-text-light: #5C5650;
  --color-text-muted: #8C857D;
  --color-error: #DC2626;
  --color-success: #16A34A;
  /* ... your brand colors ... */
}
```

### 4. PHPMailer (Vendored, No Composer)

```bash
#!/bin/bash
# download-phpmailer.sh
PHPMAILER_VERSION="6.9.3"
DOWNLOAD_URL="https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v${PHPMAILER_VERSION}.tar.gz"
TARGET_DIR="deploy/vendor/phpmailer"
TEMP_DIR=$(mktemp -d)
curl -sL "$DOWNLOAD_URL" -o "${TEMP_DIR}/phpmailer.tar.gz"
tar -xzf "${TEMP_DIR}/phpmailer.tar.gz" -C "${TEMP_DIR}"
SRC_DIR="${TEMP_DIR}/PHPMailer-${PHPMAILER_VERSION}/src"
mkdir -p "$TARGET_DIR"
cp "${SRC_DIR}/PHPMailer.php" "${TARGET_DIR}/"
cp "${SRC_DIR}/SMTP.php" "${TARGET_DIR}/"
cp "${SRC_DIR}/Exception.php" "${TARGET_DIR}/"
rm -rf "${TEMP_DIR}"
```

### 5. Server Config File (`.glass_config.php` equivalent)

Create ON THE SERVER (never commit real values):
```php
<?php
// ~/newdomain.com/.app_config.php (chmod 600)

// Resend SMTP
define('RESEND_API_KEY', 're_REAL_KEY_HERE');
define('SMTP_HOST', 'smtp.resend.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');
define('SMTP_USERNAME', 'resend');
define('SMTP_PASSWORD', 're_REAL_KEY_HERE'); // Same as RESEND_API_KEY for Resend

// Business
define('BUSINESS_EMAIL', 'hello@newdomain.com');
define('BUSINESS_PHONE', '7045551234');
define('BUSINESS_PHONE_DISPLAY', '(704) 555-1234');

// CRM database (created via step 6)
define('CRM_DB_HOST', 'localhost');
define('CRM_DB_NAME', 'gzcapita_NEWDB');
define('CRM_DB_USER', 'gzcapita_NEWUSER');
define('CRM_DB_PASS', 'STRONG_PASSWORD_HERE');

// IMAP archive (loopback to cPanel Dovecot)
define('IMAP_HOST', 'localhost');
define('IMAP_PORT', 143);
define('IMAP_FLAGS', '/imap/notls');
define('IMAP_USERNAME', 'hello@newdomain.com');
define('IMAP_PASSWORD', 'MAILBOX_PASSWORD_HERE');
define('IMAP_SENT_FOLDER', 'INBOX.Sent');
define('IMAP_ARCHIVE_ENABLED', true);

// Email observability
define('IS_PRODUCTION', true);
define('HEALTHCHECK_TOKEN', 'RANDOM_64_HEX');
define('EMAIL_HEALTH_TOKEN', 'RANDOM_64_HEX');
define('RESEND_WEBHOOK_SECRET', 'whsec_BASE64_FROM_RESEND_DASHBOARD');
define('MONITORING_EMAIL', 'alerts@youremail.com');
```

### 6. Create MySQL Database (from SSH jailshell)

```bash
ssh hostgator

# Create database
/usr/local/cpanel/bin/cpmysqlwrap ADDDB newdb

# Create user + assign privileges (Perl one-liner)
perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{CREATE_USER}, q{gzcapita_newuser}, q{STRONG_PASSWORD}, 7);
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{SET_USER_PRIVILEGES_ON_DATABASE}, q{gzcapita_newuser}, q{gzcapita_newdb}, [q{ALL PRIVILEGES}]);
"

# Test connection
mysql -u gzcapita_newuser -p'STRONG_PASSWORD' gzcapita_newdb -e "SELECT 1;"
```

### 7. Create cPanel Email Mailbox

In cPanel web UI > Email Accounts:
1. Create `hello@newdomain.com` with a strong password and ~500MB quota
2. MX record auto-configured by cPanel (points to HostGator IP)
3. Test: `ssh hostgator 'ls ~/mail/newdomain.com/hello/new/'`

### 8. AutoSSL for New Subdomain (if adding crm.newdomain.com)

```bash
ssh hostgator

# Exclude www.crm variant (no DNS record -> would fail AutoSSL for whole zone)
perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{ssl_call}, q{ADD_AUTOSSL_EXCLUDED_DOMAINS}, [q{www.crm.newdomain.com}]);
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{ssl_call}, q{START_AUTOSSL_CHECK});
"

# Check progress
perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  print Cpanel::AdminBin::Call::call(q{Cpanel}, q{ssl_call}, q{IS_AUTOSSL_CHECK_IN_PROGRESS});
"
```

### 9. Build Script (`build.sh`)

```bash
#!/bin/bash
set -euo pipefail
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC_DIR="$PROJECT_DIR/src"
DEPLOY_DIR="$PROJECT_DIR/deploy"
TAILWIND_BIN="$PROJECT_DIR/bin/tailwindcss"

# Build Tailwind CSS
"$TAILWIND_BIN" -i "$SRC_DIR/css/input.css" -o "$DEPLOY_DIR/css/style.css" --minify

# Copy PHP pages (auto-discover)
shopt -s nullglob
for src_file in "$SRC_DIR/pages/"*.php; do
    cp "$src_file" "$DEPLOY_DIR/$(basename "$src_file")"
done
shopt -u nullglob

# Copy subdirectories
[ -d "$SRC_DIR/pages/services" ] && cp "$SRC_DIR/pages/services/"*.php "$DEPLOY_DIR/services/" 2>/dev/null || true
[ -d "$SRC_DIR/pages/blog" ] && cp "$SRC_DIR/pages/blog/"*.php "$DEPLOY_DIR/blog/" 2>/dev/null || true

# Copy includes, data, JS
cp "$SRC_DIR/includes/"*.php "$DEPLOY_DIR/includes/"
cp "$SRC_DIR/data/"*.json "$DEPLOY_DIR/data/"
[ -d "$SRC_DIR/js" ] && cp "$SRC_DIR/js/"*.js "$DEPLOY_DIR/js/" 2>/dev/null || true
```

### 10. Deploy Script (`deploy.sh`)

```bash
#!/bin/bash
set -euo pipefail
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
DEPLOY_DIR="$PROJECT_DIR/deploy/"

REMOTE_USER="gzcapita"
REMOTE_HOST="192.254.232.58"
REMOTE_PORT="2222"
REMOTE_PATH="/home/$REMOTE_USER/newdomain.com"

# Load Cloudflare credentials if present
[ -f "$PROJECT_DIR/.env" ] && source "$PROJECT_DIR/.env"

# Build first
bash "$PROJECT_DIR/build.sh"

# rsync to server -- CRITICAL excludes
RSYNC_EXCLUDES=(
    --exclude='.*_config.php'   # secrets
    --exclude='.DS_Store'
    --exclude='*.md'
    --exclude='.gitkeep'
    --exclude='.quote_log'      # runtime: lead log
    --exclude='.error_log'      # runtime: app errors
    --exclude='error_log'       # runtime: Apache errors
    --exclude='*.db'            # dev SQLite
    --exclude='.webhook_log'
    --exclude='.webhook_log.1'
    --exclude='.webhook_log.lock'
    --exclude='.alarm_log'
)

rsync -avz --delete "${RSYNC_EXCLUDES[@]}" \
    -e "ssh -p $REMOTE_PORT" \
    "$DEPLOY_DIR" \
    "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/"

# Purge Cloudflare cache
if [ -n "${CF_ZONE_ID:-}" ] && [ -n "${CF_API_TOKEN:-}" ]; then
    curl -s -X POST \
        "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
        -H "Authorization: Bearer ${CF_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data '{"purge_everything":true}'
fi

echo "Deploy complete: https://newdomain.com"
```

### 11. .htaccess (Production Apache Config)

```apache
# PHP settings
php_value memory_limit 256M
php_value max_execution_time 30
php_value upload_max_filesize 5M
php_value post_max_size 6M

Options -Indexes
FileETag None

# MIME types
<IfModule mod_mime.c>
    AddType image/webp .webp
    AddType font/woff2 .woff2
</IfModule>

# Block sensitive files
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "\.(bak|backup|sql|log|ini|sh|tar|gz|zip|rar)$">
    Require all denied
</FilesMatch>

# Block protected directories
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^includes/ - [F,L]
    RewriteRule ^data/ - [F,L]
    RewriteRule ^vendor/ - [F,L]
</IfModule>

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>

# URL rewriting
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Force HTTPS (works behind Cloudflare)
    RewriteCond %{HTTPS} off
    RewriteCond %{HTTP:X-Forwarded-Proto} !https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # www -> apex
    RewriteCond %{HTTP_HOST} ^www\.newdomain\.com$ [NC]
    RewriteRule ^ https://newdomain.com%{REQUEST_URI} [L,R=301]

    # Remove trailing slashes
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # API routing
    RewriteRule ^api/quote$ api.php?action=quote [L,QSA]
    RewriteRule ^api/email-health$ api.php?action=email_health [L,QSA]
    RewriteRule ^api/resend-webhook$ api.php?action=resend_webhook [L,QSA]

    # Remove .php extensions
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME}.php -f
    RewriteRule ^(.*)$ $1.php [L]
</IfModule>

# Gzip + Cache (same pattern as reference project)
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresDefault "access plus 0 seconds"
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
    ExpiresByType text/html "access plus 0 seconds"
</IfModule>

ErrorDocument 404 /404.php
ErrorDocument 500 /500.php
```

### 12. Website Page Template Pattern

Every page follows the same structure. The header/footer/config are shared PHP includes. Pages set variables BEFORE including header.php, which renders `<head>`, nav, and opens `<main>`. Footer closes `<main>` and adds the sticky mobile CTA bar + scripts.

**How a page is built:**
```php
<?php
// 1. Load config FIRST (for PRICE_*, BUSINESS_* constants)
require_once __DIR__ . '/includes/config.php';

// 2. Set page-specific variables BEFORE including header
$page_title       = 'About Us | Your Business Name';
$page_description = 'Meta description under 160 chars with primary keyword.';
$page_canonical   = SITE_URL . '/about';
$page_schema      = 'about';   // triggers the right JSON-LD schema
$current_nav      = 'about';   // highlights the active nav item

// 3. Include header (renders <!DOCTYPE>, <head>, nav, opens <main>)
include __DIR__ . '/includes/header.php';
?>

<!-- 4. Page content goes here with Tailwind classes -->
<section class="bg-warm-white py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-4xl font-extrabold text-brand-dark">About Us</h1>
        <!-- ... -->
    </div>
</section>

<?php
// 5. Include the quote form (reusable component)
include __DIR__ . '/includes/form.php';

// 6. Include footer (closes <main>, adds sticky CTA, loads JS)
include __DIR__ . '/includes/footer.php';
?>
```

**For pages in subdirectories** (e.g., `services/foggy-window-repair.php`):
```php
require_once __DIR__ . '/../includes/config.php';
// ... set variables ...
include __DIR__ . '/../includes/header.php';
```

**Key includes:**
| File | Purpose |
|---|---|
| `config.php` | Non-secret constants: business name, phone, prices, GA ID, URLs |
| `header.php` | DOCTYPE, `<head>`, meta tags, OG tags, nav, opens `<main>`, loads `schema.php` |
| `footer.php` | Closes `<main>`, sticky mobile CTA bar (Call/Text/Quote), JS loading, GA4 |
| `schema.php` | JSON-LD functions: `render_local_business_schema()`, `render_service_schema()`, `render_faq_schema()` |
| `form.php` | Reusable quote form with client-side zip validation, honeypot, CSRF |
| `cta-section.php` | Reusable CTA block |
| `faq-section.php` | FAQ using native `<details>/<summary>` |
| `service-card.php` | Service card component for grids |
| `disclaimer.php` | Brand independence disclaimer |

**JS files (all vanilla, no framework):**
| File | Purpose |
|---|---|
| `form-validation.js` | Client-side form validation + fetch submission + zip code check |
| `mobile-menu.js` | Hamburger menu toggle for mobile nav |
| `analytics.js` | GA4 event tracking (phone clicks, form submits, etc.) |
| `phone-click.js` | Tracks phone number clicks as conversions |
| `cost-calculator.js` | Interactive pricing calculator |
| `exit-intent.js` | Exit-intent popup for lead capture |
| `before-after-slider.js` | Before/after image comparison slider |

### 13. CRM Architecture (Single-File PWA + Single-File API)

The CRM is a complete customer/job management system built as TWO files:

**Frontend: `deploy-crm/index.html`** (~5000+ lines, single file)
- Complete HTML + CSS + vanilla JavaScript in one file
- PWA with service worker for offline access
- Mobile-first responsive design
- CSS custom properties for theming (see `:root` block)
- No framework, no build step, no dependencies
- Client-side routing via hash (#dashboard, #customers, #jobs, etc.)
- Photo upload with client-side resize (1920px JPEG-82) + EXIF orientation fix
- Generates 400px thumbnails on-device before upload

**Backend: `deploy-crm/crm_api.php`** (~1300 lines, single file)
- All 25+ endpoints in one file, dispatched via `?action=` query parameter
- Auto-creates tables on first run via `initDB()` (works with both SQLite and MySQL)
- SQLite for local dev/tests, MySQL for production (same code, driver switch in config)
- PIN-based auth with HMAC tokens (no user accounts needed for 1-2 person operation)
- Server-enforced state machine for job status transitions
- Photo upload pipeline: finfo_file() -> GD re-encode -> EXIF rotation -> thumbnail generation
- File-based rate limiting with flock()
- Full audit log for every write operation

**CRM API Endpoints (all via `crm_api.php?action=`):**

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `auth` | POST | No | PIN login, returns HMAC token |
| `check` | GET | Yes | Verify token is valid |
| `customer_create` | POST | Admin | Create new customer |
| `customer_update` | POST | Admin | Update customer fields |
| `customer` | GET | Yes | Get single customer with jobs |
| `customers` | GET | Yes | List/search customers |
| `lead_create` | POST | Admin | Create customer + job from lead form |
| `job` | GET | Yes | Get single job with items + activities |
| `jobs` | GET | Yes | List/filter jobs |
| `job_update` | POST | Admin | Update job fields |
| `job_status` | POST | Admin | Transition job status (state machine enforced) |
| `job_items` | GET | Yes | List line items for a job |
| `job_item_create` | POST | Admin | Add line item to job |
| `job_item_update` | POST | Admin | Update line item |
| `job_item_delete` | POST | Admin | Remove line item |
| `activities` | GET | Yes | List activities for customer/job |
| `activity_create` | POST | Admin | Log a call, note, status change, etc. |
| `follow_up_done` | POST | Admin | Mark follow-up as completed |
| `photo_upload` | POST | Admin | Upload job photo (with GD re-encode) |
| `job_photos` | GET | Yes | List photos for a job |
| `photo` | GET | Yes | Serve a specific photo file |
| `dashboard` | GET | Yes | Aggregate stats (pipeline, revenue, follow-ups) |
| `calendar` | GET | Yes | Jobs with scheduled dates |
| `reports` | GET | Yes | Sub-reports: revenue, pipeline, sources, referrals, reviews |
| `audit_log` | GET | Admin | View audit trail |
| `export` | GET | Admin | Export data as CSV |

**Job Status State Machine (server-enforced):**
```
Standard:  new_lead -> contacted -> estimate_scheduled -> quoted -> approved
           -> materials_ordered -> install_scheduled -> completed

Emergency: new_lead -> emergency_scheduled -> boardup_done
           -> materials_ordered -> install_scheduled -> completed

Any status can transition to "lost" (with reason required).
```

### 14. Database Management (Full Reference)

```bash
# --- List existing databases ---
ssh hostgator "/usr/local/cpanel/bin/cpmysqlwrap LISTDBS"

# --- List existing database users ---
ssh hostgator "/usr/local/cpanel/bin/cpmysqlwrap LISTUSERS"

# --- Create a new database ---
# Name will be prefixed: gzcapita_<name>
ssh hostgator "/usr/local/cpanel/bin/cpmysqlwrap ADDDB newdb"

# --- Create a new database user + assign privileges ---
# MUST use fully prefixed names (gzcapita_newuser, gzcapita_newdb)
ssh hostgator 'perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{CREATE_USER}, q{gzcapita_newuser}, q{STRONG_PASSWORD_HERE}, 7);
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{SET_USER_PRIVILEGES_ON_DATABASE}, q{gzcapita_newuser}, q{gzcapita_newdb}, [q{ALL PRIVILEGES}]);
"'

# --- Test the connection ---
ssh hostgator "mysql -u gzcapita_newuser -p'STRONG_PASSWORD_HERE' gzcapita_newdb -e 'SELECT 1;'"

# --- Run SQL on an existing database ---
ssh hostgator "mysql -u gzcapita_newuser -p'PASS' gzcapita_newdb -e 'SHOW TABLES;'"
ssh hostgator "mysql -u gzcapita_newuser -p'PASS' gzcapita_newdb -e 'SELECT COUNT(*) FROM crm_customers;'"

# --- Delete a database ---
ssh hostgator 'perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{DELETE_DATABASE}, q{gzcapita_olddb});
"'

# --- Delete a database user ---
ssh hostgator 'perl -I/usr/local/cpanel -MCpanel::AdminBin::Call -e "
  Cpanel::AdminBin::Call::call(q{Cpanel}, q{mysql}, q{DELETE_USER}, q{gzcapita_olduser});
"'

# --- Import a SQL file ---
ssh hostgator "mysql -u gzcapita_user -p'PASS' gzcapita_db < ~/schema.sql"

# --- Dump/backup a database ---
ssh hostgator "mysqldump -u gzcapita_user -p'PASS' gzcapita_db > ~/backup_$(date +%Y%m%d).sql"
```

**Existing databases on this server (DO NOT TOUCH):**
| Database | User | App |
|---|---|---|
| `gzcapita_crm` | `gzcapita_crmapp` | Carolina Glass CRM |
| `gzcapita_loan` | `gzcapita_loanapp` | Loan Trajano (DO NOT TOUCH) |
| `gzcapita_valkam` | `gzcapita_vlkapp` | Valkam (DO NOT TOUCH) |

### 15. CRM Authentication Pattern (PIN-based, no user accounts)

The CRM uses PIN-based auth (no user accounts -- it's a 1-2 person operation):

```php
// .crm_config.php on server
define('CRM_ADMIN_CODE', '####');     // Full access
define('CRM_VIEWER_CODE', '####');    // Read-only
define('CRM_TOKEN_SECRET', 'random_64_hex_string');
define('CRM_TOKEN_EXPIRY_DAYS', 30);
```

Auth flow: POST PIN -> server validates against config -> returns JWT-like HMAC token -> client stores in localStorage -> sends as `Authorization: Bearer <token>` on every request.

### 16. Main Site API Pattern (api.php)

The main site API is a single-file router:

```php
<?php
// Load config (tries outside-docroot first, then in-docroot)
$configPaths = [
    dirname(__DIR__) . '/secrets/app_config.php',
    __DIR__ . '/.app_config.php',
];
foreach ($configPaths as $path) {
    if (is_file($path) && is_readable($path)) {
        require_once $path;
        break;
    }
}

// Vendored PHPMailer
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

// Global exception handler (NON-NEGOTIABLE)
set_exception_handler(function (\Throwable $e) {
    @error_log('[api.php] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error. Please call us.']);
    exit;
});

// Route dispatch from REQUEST_URI (NOT from $_GET['action'] -- see security notes)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/quote') { handleQuote(); }
elseif ($uri === '/api/email-health') { handleEmailHealth(); }
else { http_response_code(404); echo json_encode(['error' => 'Not found']); }

function handleQuote() {
    // POST only
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Body size check
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 10240) {
        http_response_code(413);
        echo json_encode(['error' => 'Request too large']);
        exit;
    }

    // Rate limiting (file-based with flock)
    // CSRF check (Origin/Referer header)
    // Honeypot check
    // Input validation
    // Send email via PHPMailer + Resend SMTP
    // Write to CRM database
    // Log to .quote_log
}
```

### 17. Resend Email Setup

1. Create account at resend.com
2. Add your domain, get SPF + DKIM + return-path DNS records
3. Add records in Cloudflare DNS dashboard
4. Wait 15min-4h for propagation (DO NOT drive paid traffic until verified)
5. Create API key at resend.com/api-keys
6. Use the API key as both `RESEND_API_KEY` and `SMTP_PASSWORD`

PHPMailer config:
```php
function createMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.resend.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'resend';
    $mail->Password = RESEND_API_KEY; // or SMTP_PASSWORD
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->XMailer = ' '; // Suppress X-Mailer header (SpamAssassin penalizes PHPMailer)
    $mail->setFrom('hello@newdomain.com', 'Your Business Name');
    return $mail;
}
```

### 18. Cloudflare WAF Geo-Block

```bash
# US-only block with exemptions for bots and API endpoints
# Rule chain (order matters):
# 1. SKIP if verified bot
# 2. SKIP if path = /api/email-health
# 3. SKIP if path = /api/resend-webhook
# 4. BLOCK if ip.src.country ne "US"

# Create via Cloudflare API or dashboard
# Any new webhook/healthcheck endpoint MUST be added as SKIP before rule 4
```

### 19. Playwright Test Setup

```bash
npm init -y
npm install -D @playwright/test
npx playwright install
```

`playwright.config.ts`:
```typescript
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const USE_LOCAL_SERVER = !process.env.BASE_URL;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  use: { baseURL: BASE_URL },
  ...(USE_LOCAL_SERVER ? {
    webServer: {
      command: 'php -S localhost:8080 -t deploy/ tests/router.php',
      url: 'http://localhost:8080/',
      reuseExistingServer: true,
      timeout: 10_000,
    },
  } : {}),
});
```

`tests/router.php` mirrors `.htaccess` rewrites for the PHP dev server (which doesn't read `.htaccess`).

---

## Existing Projects on This Server (DO NOT TOUCH)

| Domain | DB | DB User | Notes |
|---|---|---|---|
| carolinaglassreplacement.com | (email-only leads, writes to gzcapita_crm) | - | Main glass replacement site |
| crm.carolinaglassreplacement.com | gzcapita_crm | gzcapita_crmapp | CRM app |
| carolinafoggywindowrepair.com | - | - | 301 redirect to main site |
| (Loan Trajano app) | gzcapita_loan | gzcapita_loanapp | DO NOT TOUCH |
| (Valkam app) | gzcapita_valkam | gzcapita_vlkapp | DO NOT TOUCH |

---

## Non-Negotiable Security Rules

1. **SQL**: PDO prepared statements ONLY. Never concatenate/interpolate user input.
2. **XSS**: `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` on ALL user-controlled output.
3. **Input**: Explicit allow-list loop for API update endpoints. Don't spot-sanitize.
4. **Includes**: Always `__DIR__ . '/path'`. Never bare relative paths.
5. **Comparison**: `===` (strict) everywhere. Never `==` for security.
6. **CSRF**: Every POST form needs Origin/Referer validation.
7. **File uploads**: Validate with `finfo_file()` AND re-encode via GD. NEVER raw `move_uploaded_file()`.
8. **PHPMailer**: Verify TLS in production. Never disable SSL verification.
9. **Exception handler**: Register `set_exception_handler()` at module scope BEFORE routing.
10. **Transactions**: Multi-row writes MUST use `beginTransaction()/commit()/rollBack()`.
11. **Config files**: `chmod 600`, blocked by `.htaccess`, excluded from rsync.
12. **Secrets on command line**: NEVER. Use env vars or temp scripts.

---

## Secrets You Need to Obtain/Create

These are NOT in the repo. Create them directly on the server:

| Secret | Where it lives | How to get it |
|---|---|---|
| Resend API key | Server `.app_config.php` | resend.com/api-keys |
| CRM DB password | Server `.crm_config.php` | You generate it |
| CRM admin PIN | Server `.crm_config.php` | You choose it |
| CRM viewer PIN | Server `.crm_config.php` | You choose it |
| CRM token secret | Server `.crm_config.php` | `openssl rand -hex 32` |
| Email mailbox password | cPanel Email Accounts | You create it |
| Healthcheck token | Server `.app_config.php` | `openssl rand -hex 32` |
| Email health token | Server `.app_config.php` | `openssl rand -hex 32` |
| Resend webhook secret | Server `.app_config.php` | Resend dashboard > Webhooks |
| Cloudflare Zone ID | Local `.env` | CF dashboard > Overview sidebar |
| Cloudflare API token | Local `.env` | CF dashboard > Profile > API Tokens |

To create secrets:
```bash
# Generate random tokens
openssl rand -hex 32

# Create config on server
ssh hostgator "cat > ~/newdomain.com/.app_config.php << 'PHPEOF'
<?php
define('RESEND_API_KEY', 're_YOUR_KEY');
// ... all other defines ...
PHPEOF
chmod 600 ~/newdomain.com/.app_config.php"
```

---

## Key Gotchas (Lessons Learned the Hard Way)

1. **OPcache has a ~60s revalidation window.** After rsync, old PHP bytecode serves for up to 60s. Don't trust a health check that runs 2s after deploy.
2. **`dirname(__DIR__)` changes after HostGator account migrations.** Use `$_SERVER['HOME']` or `getenv('HOME')`.
3. **SpamAssassin on cPanel penalizes `X-Mailer: PHPMailer` by 2.5 points.** Always suppress: `$mail->XMailer = ' ';`
4. **Resend returns `535 Could not authenticate` for BOTH bad keys AND unverified domains.** Disambiguate via REST API before rotating keys.
5. **Cloudflare Cache Rules stack field-by-field.** A catch-all after a bypass silently re-caches API endpoints. Always verify with `curl -sI`.
6. **`.htaccess` rewrites are NOT read by `php -S` dev server.** Mirror every rewrite in `tests/router.php`.
7. **rsync `--exclude` list is critical.** Missing an exclude on `.*_config.php` overwrites production secrets with dev placeholders.
8. **`email_sent:true` means Resend accepted it, NOT that it was delivered.** Use Resend webhooks for delivery confirmation.
9. **IMAP folder names on cPanel Dovecot use `INBOX.Sent` format** (not just `Sent`). Use `imap_list()` to discover.
10. **Static HTML returns 200 even when PHP is broken.** Health checks MUST hit a PHP endpoint.

---

## cPanel Email System (Dovecot Maildir)

All domains on this server use cPanel's Dovecot IMAP with Maildir format. Understanding this structure is essential for reading, filtering, and managing email via SSH.

### Maildir Layout

Every email account lives under `~/mail/<domain>/<user>/`:

```
~/mail/<domain>/<user>/
  new/          # Unread messages (fresh arrivals from Exim)
  cur/          # Read messages (moved here after IMAP client marks them)
  tmp/          # Temporary (in-flight writes, ignore)
  .Sent/        # Sent folder (Maildir++ dotfolder)
    new/
    cur/
  .Drafts/
    new/
    cur/
  .Trash/
    new/
    cur/
  .spam/        # SpamAssassin spam folder
    new/
    cur/
  .Archive/
    new/
    cur/
  .Junk/
    new/
    cur/
  .Sent Messages/   # Some clients create this variant
    new/
    cur/
```

**Important**: Folder names use Maildir++ convention -- a leading dot (`.Sent/`, `.spam/`) is the hierarchy separator, NOT a hidden file. IMAP clients see these as `INBOX.Sent`, `INBOX.spam`, etc. Use `imap_list()` in PHP to discover actual names, never guess.

### Existing Mailboxes on This Server

All domains hosted on this cPanel account:

```
~/mail/
  carolinaglassreplacement.com/    # Glass replacement business
    hello/                          # hello@carolinaglassreplacement.com
  carolinafoggywindowrepair.com/
  carolinahvacconstruction.com/
  funandtrendy.com/
  gabyfostercare.com/
  globallearninglabs.org/
  gzcapitalgroup.com/
  inmigraciondirecta.com/
  mushroomalive.com/
  myjourneytocounseling.com/
  pureonenutrition.com/
  pureonesolutions.com/
  signaturecarecounseling.com/
  valkamgm.com/                    # Valkam business
    account/
    ceo/
    chatgpt/
    commercial/
    contract/
    devops/
    emanuel/
    gabriella/
    info/                           # info@valkamgm.com (main inbox)
    javier/
    logistic/
    purchase/
    sales/
  xtremert.com/
```

### How to Locate and Read the info@valkamgm.com Inbox

```bash
# Path to the mailbox
MAILBOX=~/mail/valkamgm.com/info

# Count messages
ssh hostgator "echo 'Unread:' && ls $MAILBOX/new/ | wc -l && echo 'Read:' && ls $MAILBOX/cur/ | wc -l"

# List recent emails (newest first, by filename timestamp)
ssh hostgator 'ls -lt ~/mail/valkamgm.com/info/cur/ | head -20'
ssh hostgator 'ls -lt ~/mail/valkamgm.com/info/new/ | head -20'

# Read a specific email (Maildir files are raw RFC822 text)
ssh hostgator 'head -50 ~/mail/valkamgm.com/info/cur/<filename>'

# Search email subjects
ssh hostgator "grep -l 'Subject:.*invoice' ~/mail/valkamgm.com/info/cur/ | head -10"

# Read full headers of the most recent email
ssh hostgator 'ls -t ~/mail/valkamgm.com/info/cur/ | head -1 | xargs -I{} head -100 ~/mail/valkamgm.com/info/cur/{}'

# List all subfolders (Sent, Drafts, Trash, spam, etc.)
ssh hostgator 'ls -d ~/mail/valkamgm.com/info/.*/ 2>/dev/null'

# Check spam folder
ssh hostgator 'ls -lt ~/mail/valkamgm.com/info/.spam/cur/ | head -10'
```

### How to Filter All Emails From info@peonyinc.com

Emails from a specific sender are scattered across `cur/` (read) and `new/` (unread) as individual Maildir files. Each file contains full RFC822 headers + body. Use `grep` to find them by the `From:` header.

```bash
# --- Find all emails from info@peonyinc.com in the info@valkamgm.com inbox ---

# Count emails from peonyinc in read mail
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | wc -l"

# Count in unread mail
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/new/ | wc -l"

# List all matching files (read mail)
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/"

# List all matching files (unread mail)
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/new/"

# Show the Subject line of each peonyinc email (read mail)
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | while read f; do echo \"--- \$f ---\"; grep -m1 '^Subject:' \"\$f\"; grep -m1 '^Date:' \"\$f\"; echo; done"

# Show Subject + Date for peonyinc emails, sorted by date
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | while read f; do date=\$(grep -m1 '^Date:' \"\$f\" | sed 's/^Date: //'); subj=\$(grep -m1 '^Subject:' \"\$f\" | sed 's/^Subject: //'); echo \"\$date | \$subj | \$f\"; done | sort"

# Read the full content of a specific peonyinc email
ssh hostgator 'cat ~/mail/valkamgm.com/info/cur/<FILENAME>'

# --- Copy all peonyinc emails to a temp folder for bulk processing ---
ssh hostgator "mkdir -p /tmp/peonyinc_emails && grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | xargs -I{} cp {} /tmp/peonyinc_emails/"

# --- Extract just headers from all peonyinc emails (for a summary) ---
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | while read f; do echo '========'; sed -n '1,/^\$/p' \"\$f\"; done" > peonyinc_headers.txt

# --- Also check Sent folder (replies TO peonyinc) ---
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/.Sent/cur/ 2>/dev/null | wc -l"
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/.Sent\\ Messages/cur/ 2>/dev/null | wc -l"

# --- Search across ALL valkamgm.com mailboxes for peonyinc ---
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/*/cur/ ~/mail/valkamgm.com/*/new/ 2>/dev/null | head -50"
```

### How to Filter by Date Range

Maildir filenames encode the Unix timestamp as the first number before the first dot. Example:
`1774616546.M54850P515376.gator3215.hostgator.com,S=198759,W=201071:2,S`

The `1774616546` is the Unix epoch timestamp of delivery.

```bash
# Find peonyinc emails from the last 30 days
ssh hostgator "
CUTOFF=\$(date -d '30 days ago' +%s 2>/dev/null || date -v-30d +%s)
grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ | while read f; do
  base=\$(basename \"\$f\")
  ts=\${base%%.*}
  if [ \"\$ts\" -gt \"\$CUTOFF\" ] 2>/dev/null; then
    subj=\$(grep -m1 '^Subject:' \"\$f\" | sed 's/^Subject: //')
    echo \"\$(date -d @\$ts '+%Y-%m-%d' 2>/dev/null || date -r \$ts '+%Y-%m-%d') | \$subj\"
  fi
done | sort
"
```

### How to Read Any Mailbox on This Server

The same pattern works for ANY domain/user on this cPanel account:

```bash
# Generic pattern
DOMAIN="valkamgm.com"      # or carolinaglassreplacement.com, mushroomalive.com, etc.
USER="info"                  # or hello, emanuel, sales, etc.
MAILBOX=~/mail/$DOMAIN/$USER

# List all email accounts for a domain
ssh hostgator "ls ~/mail/valkamgm.com/"

# List all domains with email
ssh hostgator "ls ~/mail/ | grep -v '^[a-z]*$' | grep '\\.'"

# Quick inbox summary for any account
ssh hostgator "echo 'Unread:' && ls ~/mail/$DOMAIN/$USER/new/ 2>/dev/null | wc -l && echo 'Read:' && ls ~/mail/$DOMAIN/$USER/cur/ 2>/dev/null | wc -l && echo 'Spam:' && ls ~/mail/$DOMAIN/$USER/.spam/cur/ 2>/dev/null | wc -l"
```

### SpamAssassin Headers

When debugging delivery, check the spam headers inside the raw email file:
```bash
# Check if a specific email was flagged as spam
ssh hostgator "grep -A5 'X-Spam-Status' ~/mail/valkamgm.com/info/cur/<FILENAME>"

# Find all spam-flagged emails from a sender
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/.spam/cur/ 2>/dev/null"
```

---

## Quick Reference: Common Operations

```bash
# SSH into server
ssh hostgator

# Check PHP version on server
ssh hostgator 'php -v'

# Check if a config constant exists on production
ssh hostgator "grep -n 'CONSTANT_NAME' ~/newdomain.com/.app_config.php"

# Read recent PHP errors
ssh hostgator 'tail -30 ~/newdomain.com/error_log'

# Read app errors
ssh hostgator 'tail -30 ~/newdomain.com/.error_log'

# Check recent leads
ssh hostgator 'tail -10 ~/newdomain.com/.quote_log'

# Check email delivery (cPanel mailbox)
ssh hostgator 'ls -lt ~/mail/newdomain.com/hello/new/ | head -10'

# Check spam folder
ssh hostgator 'ls -lt ~/mail/newdomain.com/hello/.spam/new/ | head -10'

# Check Apache access logs
ssh hostgator 'tail -20 ~/access-logs/newdomain.com.gzcapitalgroup.com-ssl_log'

# Test MySQL connection
ssh hostgator "mysql -u gzcapita_USER -p'PASS' gzcapita_DB -e 'SHOW TABLES;'"

# --- Email operations ---
# List all domains with email on this server
ssh hostgator "ls ~/mail/ | grep '\\.'"

# List all mailbox users for a domain
ssh hostgator 'ls ~/mail/valkamgm.com/'

# Read info@valkamgm.com inbox (recent 20)
ssh hostgator 'ls -lt ~/mail/valkamgm.com/info/cur/ | head -20'

# Find all emails from info@peonyinc.com in info@valkamgm.com
ssh hostgator "grep -rl 'info@peonyinc.com' ~/mail/valkamgm.com/info/cur/ ~/mail/valkamgm.com/info/new/ 2>/dev/null"

# Build locally
./build.sh

# Deploy
./deploy.sh

# Run tests locally
npx playwright test

# Run tests against production
BASE_URL=https://newdomain.com npx playwright test
```
