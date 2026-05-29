# CLAUDE.md — Folio Take-Home

## What this project is
A small PHP document-sharing app. Staff create documents and share them with
recipients via one-time links. This file tells you how to work in this codebase
without breaking things.

## Stack
- PHP (no framework) + SQLite via PDO
- Runs inside Docker — do not try to run PHP or SQLite directly on the host
- All public pages live in `public/`
- Shared helpers live in `lib/bootstrap.php` and `lib/layout.php`

## Key conventions

### Database
- Always use PDO prepared statements — never interpolate variables into SQL
- Foreign keys are ON — respect the chain: staff → documents → shares
- Schema changes go in a new migration file under `migrations/` — never edit
  `schema.sql` directly
- Migrations run in filename order and must be idempotent where possible

### Audit logging
Every create or mutating action must call `audit_log()` from `lib/bootstrap.php`:
```php
audit_log('create', 'document', $docId, ['title' => $title]);
audit_log('create', 'share', $shareId, ['document_id' => $docId, 'recipient' => $email]);
```
Do not skip audit logging. It is a hard requirement.

### Authentication
`current_staff()` hardcodes `WHERE id = 1` — there is no real auth. Do not
work around this or add fake auth. Flag it as a known limitation.

### Timezone
Bootstrap sets `America/Chicago`. All `datetime('now')` calls in SQLite use
UTC. Be explicit about timezone handling in scheduled publishing logic.

### HTML escaping
Always use `h()` from bootstrap when rendering user-supplied content.
Never echo raw user input.

### Token generation
Use `random_token()` from bootstrap for any new token needs. Do not roll
your own.

## File structure
```
public/
  admin.php       — document creation + listing
  share.php       — share link generation (recipient email input)
  view.php        — recipient-facing document view (token-based)
  index.php       — redirects to admin.php
lib/
  bootstrap.php   — db(), current_staff(), audit_log(), random_token(), h()
  layout.php      — render_header(), render_footer()
migrations/       — YOU CREATE THIS — numbered SQL migration files
tests/
  test.php        — test runner, re-seeds db on every run
schema.sql        — baseline schema, do not edit directly
seed.php          — seeds db.sqlite from scratch
```

## Running the app
```bash
docker compose up
# open http://localhost:8000
```

## Running tests
```bash
docker compose exec app php tests/test.php
```
Tests re-seed the database every run — do not rely on persistent state in tests.

## What I am building (features)

### 1. Search by title (admin.php)
- Add a search input to the documents list in admin.php
- Filter using SQL LIKE — prefix match is sufficient (`title LIKE 'query%'`)
- No migration needed — query-only change

### 2. Scheduled publishing (view.php + admin.php + migration)
- Add `publish_at TEXT` column to documents (nullable — null means publish immediately)
- In view.php, check if `publish_at` is set and in the future → show "not yet available"
- In admin.php, add optional datetime input on document creation form
- Migration: `migrations/001_add_publish_at.sql`
- Audit log the scheduled time on document creation

### 3. Human-readable document IDs (admin.php + view.php + migration)
- Add `slug TEXT UNIQUE` column to documents
- Generated at creation time: slugify title + short random suffix (e.g. `welcome-2026-7qx4`)
- Share URL format: `/view/{slug}/{token}` — both required, token still enforces access control
- Migration: `migrations/002_add_slug.sql`
- Readable part is recognizable; token part prevents guessing

### Testing
After every feature, add at least one test to tests/test.php.
Follow the existing test() and assert_true() pattern exactly.
Run tests with: docker compose exec app php tests/test.php
Tests must pass before a feature is considered done.

## What NOT to do
- Do not edit schema.sql directly
- Do not skip audit_log() calls
- Do not echo unescaped user input
- Do not add SMTP/email delivery — flag as future work instead
- Do not over-engineer the migration system — simple numbered files are enough
