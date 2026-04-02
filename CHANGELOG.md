# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] - 2026-04-02

### Added

- **MySQL backend (`db.php`)** — New dedicated connection file reads credentials from `.env` via a lightweight built-in parser (no Composer required). Guards duplicate `loadEnv()` definition with `function_exists()` so both `save_order.php` and `admin.php` can include it safely. Exits with a JSON error response on connection failure.
- **Database setup script (`setup_db.sql`)** — One-shot SQL file creates the `orders` table with `id`, `order_no` (UNIQUE), `timestamp`, `nama`, `telefon`, `saiz`, `penghantaran`, `alamat`, `poskod`, `bandar`, `negeri`, `jumlah_bayaran`, and `created_at` columns. Includes step-by-step phpMyAdmin import instructions as comments.
- **Admin panel (`admin.php`)** — Single-file, session-based CRUD interface built with Bootstrap 5.3 and Bootstrap Icons (CDN). Features:
  - Login / logout with CSRF-protected forms and `session_regenerate_id()` on authentication.
  - Dashboard stat cards — total orders, total revenue, today's orders, delivery count.
  - Paginated order list (25 per page) with sortable columns (order_no, timestamp, nama, saiz, jumlah_bayaran) and keyword search across `order_no`, `nama`, and `telefon`.
  - Create order form — auto-suggests next available `order_no` for today; repopulates fields on validation error.
  - Edit order form — pre-fills all fields from the database record; delivery address block toggled by a Bootstrap switch.
  - Delete with confirmation modal — hard delete, non-reversible.
  - CSV export — streams all orders as a UTF-8 BOM `.csv` for Excel compatibility.
  - Responsive layout — sidebar navigation on `≥ lg`, top action bar on mobile.
- **Environment-based credentials (`.env` + `.env.example`)** — All sensitive values (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `ADMIN_USER`, `ADMIN_PASS`) moved to `.env`. `.env.example` committed as a safe template. `.env` already present in `.gitignore`.
- **bcrypt admin password support** — `admin.php` detects whether `ADMIN_PASS` starts with `$2y$` and uses `password_verify()` or `hash_equals()` accordingly, allowing a plain-text quick-start that can be upgraded to a hashed credential without changing any code.

### Changed

- **`save_order.php`** — Replaced flat-file `orders.json` read/write block with a single `INSERT` prepared statement using `mysqli::bind_param()`. Includes `db.php` via `require`. Handles MySQL duplicate key error (errno 1062) with a specific HTTP 409 response. All other logic (validation, sanitisation, email notification, response contract) is unchanged.
- **`db.php`** — Rewritten from hardcoded `define()` constants to `.env`-based credential loading. Returns `$mysqli` via `return` so callers receive the connection object directly.
- **`.htaccess`** — Added `<FilesMatch>` directive to deny direct HTTP access to `.env.*` and `db.php`.
- **README** — Updated tech stack, file structure, full deployment guide, database schema reference, admin panel feature table, and security notes to reflect the MySQL migration.

### Removed

- **`orders.json` as storage** — Flat-file JSON persistence replaced by MySQL. The file may remain on disk as a historical snapshot but is no longer read or written by any PHP script.
- **Hardcoded DB constants** — `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` defines removed from `db.php` in favour of `.env` loading.

### Security

- All database queries use prepared statements with bound parameters — no raw SQL string interpolation on user-supplied data.
- CSRF tokens on every admin form (create, update, delete, logout).
- `.env` blocked from direct HTTP access via `.htaccess`.
- `UNIQUE KEY` constraint on `order_no` at the database level prevents duplicate inserts regardless of application state.

---

## [1.1.0] - 2026-04-01

### Added

- **Server-side email notification (`save_order.php`)** — After a successful write, PHP's `mail()` dispatches a plain-text order summary to `urusetia@sdar90.net` with a BCC to `ariffjamili@gmail.com`. The email includes a formatted order block with all fields; delivery address is appended only when `penghantaran` is `true`. The `sendOrderEmail()` return value is surfaced in the JSON response as `email_sent`.

---

## [1.0.0] - 2026-04-01

### Added

- **Order form UI (`index.html`)** — Single-file HTML app in Bahasa Melayu with dark military/streetwear aesthetic using CSS custom properties (`--bg: #0f0f1a`, `--gold: #c9a84c`).
- **Google Fonts integration** — Bebas Neue (display headings), Barlow Condensed (labels, badges), and Barlow (body text) loaded via Google Fonts CDN.
- **Branded header** — GLORIOUS90 logotype with "Limited Edition Drop" eyebrow, "40 Years of Brotherhood" subtitle, JAKET BOMBER product label, and "Borang Tempahan Rasmi" drop badge.
- **Name and phone fields** — Free-text inputs for full name (`nama`) and telephone number (`telefon`) with `autocomplete` attributes and Bahasa Melayu placeholder text.
- **Size toggle buttons** — 4-column grid of eight toggle buttons (S, M, L, XL, XXL, 3XL, 4XL, 5XL) with active/inactive states; only one size selectable at a time.
- **Surcharge note** — Contextual note ("Saiz ini dikenakan tambahan RM25") displayed automatically when a large size (3XL–5XL) is selected.
- **Pricing constants** — `PRICE_BASE = 90` (S–XXL), `PRICE_LARGE = 115` (3XL–5XL), `PRICE_SHIPPING = 12`; all defined as named constants in the script.
- **Delivery checkbox** — Custom-styled checkbox that toggles delivery requirement; reveals a shipping cost note (RM12) on activation.
- **Animated address fields** — CSS `max-height` / `opacity` transition reveals address sub-fields (alamat, poskod, bandar, negeri) when delivery is selected; collapses smoothly when deselected.
- **Live order summary card** — Dynamically updates jacket size, base price, shipping line (shown/hidden), and total amount as the user changes selections; displays `RM—` placeholders until a size is chosen.
- **Client-side validation** — Enforces: non-empty name; phone matching `/^\+?[\d]{9,15}$/`; size selection; non-empty address fields and 5-digit postcode when delivery is enabled. Inline field-level error messages clear individually on input.
- **Toast notification** — Fixed-position error banner slides up from the bottom when submission is attempted with invalid data.
- **Order number generation** — Client-side function using `localStorage` (`g90_order_counter`) produces `YYYYMMDD-NN` identifiers that increment per device per day and reset at midnight.
- **Async form submission** — `fetch()` POSTs a JSON payload to `save_order.php`; button is disabled and relabelled "Menghantar..." during the request; errors are caught gracefully without blocking the `mailto:` step.
- **`mailto:` fallback** — After the PHP call (success or failure), the browser opens a pre-filled email to `urusetia@sdar90.net` with subject `[GLORIOUS90] Pesanan Jaket Bomber — {order_no}` and a plain-text order summary in the body.
- **Acknowledgement card (`#ack-card`)** — Replaces the form on success; displays a gold checkmark icon, order number in large Bebas Neue type, and a follow-up message; animates in via `fadeSlideUp` keyframe.
- **PHP backend (`save_order.php`)** — Accepts `POST` requests only; reads raw `php://input`; returns `application/json` responses with `X-Content-Type-Options: nosniff`.
- **Server-side input sanitisation** — `clean()` helper applies `strip_tags()`, `trim()`, and `mb_substr()` with configurable max length to all string fields.
- **Server-side validation** — Validates: non-empty `order_no`, `nama`; phone regex; size against `$allowed_sizes` whitelist; `jumlah_bayaran` against `$allowed_amounts` whitelist `[90, 102, 115, 127]`; order number format `/^\d{8}-\d{2}$/`; full address fields when `penghantaran` is `true`.
- **HTTP status codes** — Returns 405 for non-POST, 400 for empty/invalid JSON body, 422 for validation failures, 500 for file write errors, 200 for success.
- **Flat-file order storage** — Validated orders appended to `orders.json` (auto-created if absent) using `file_put_contents()` with `LOCK_EX` to prevent concurrent write corruption.
- **Order record schema** — Each record stores: `order_no`, `timestamp` (server `Y-m-d H:i:s`), `nama`, `telefon`, `saiz`, `penghantaran` (boolean), `jumlah_bayaran` (integer); delivery records additionally include `alamat`, `poskod`, `bandar`, `negeri`.
- **JSON output** — Orders file written with `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE` flags for human-readable storage of Malay text.
- **Responsive layout** — `max-width: 560px` centred layout; address row switches to single column below 420 px; size grid maintains 4-column layout at all breakpoints.
- **Footer** — Copyright line: "© 2026 GLORIOUS90 — SDAR 1986–1990".

---

[2.0.0]: https://github.com/your-org/order-form/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/your-org/order-form/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/your-org/order-form/releases/tag/v1.0.0
