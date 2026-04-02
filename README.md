# GLORIOUS90 — Borang Tempahan Jaket Bomber

![HTML](https://img.shields.io/badge/HTML-5-E34F26?style=flat-square&logo=html5&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Deployable on cPanel](https://img.shields.io/badge/Deployable%20on-cPanel-FF6C2C?style=flat-square)

A self-contained merchandise order form for the GLORIOUS90 limited-edition bomber jacket drop. Built for the SDAR 1986–1990 alumni community, the app collects customer details, calculates pricing, persists orders to a MySQL database, and falls back to a `mailto:` link — all without any external framework or Composer dependencies.

---

## Features

- **Bahasa Melayu UI** with a dark military/streetwear aesthetic (`#0f0f1a` background, `#c9a84c` gold accents)
- **Size selection** via toggle buttons — S, M, L, XL, XXL, 3XL, 4XL, 5XL
- **Automatic surcharge** — RM90 base price for S–XXL; RM115 for 3XL–5XL
- **Optional delivery** — checkbox reveals animated address fields (alamat, poskod, bandar, negeri); adds RM12 shipping charge
- **Live order summary** — item price, shipping line, and total update instantly as the user interacts
- **Order number generation** — client-side via `localStorage`, format `YYYYMMDD-NN` (resets daily, increments per device)
- **MySQL persistence** — orders saved via prepared statements through `save_order.php` → `db.php`
- **Email notification** — server-side `mail()` dispatch to `urusetia@sdar90.net` on every successful submission
- **`mailto:` fallback** — triggers the user's mail client to send order details regardless of PHP outcome
- **Acknowledgement card** — animated success screen displaying the order number after submission
- **Client-side validation** — required fields, phone format, 5-digit postcode; inline error messages clear on input
- **Server-side validation** — PHP re-validates all fields, enforces allowed size and amount whitelists
- **Admin panel** — password-protected CRUD interface at `admin.php` with search, pagination, CSV export, and delivery stats
- **No Composer** — zero PHP dependencies beyond MySQLi (bundled with PHP)

---

## Tech Stack

| Layer       | Technology                                           |
|-------------|------------------------------------------------------|
| Markup      | HTML5 (`lang="ms"`)                                  |
| Styling     | CSS custom properties, CSS Grid, CSS transitions     |
| Scripting   | Vanilla JavaScript (ES2017+, `async/await`)          |
| Fonts       | Google Fonts — Bebas Neue, Barlow Condensed, Barlow  |
| Backend     | PHP 7.4+ (no framework)                              |
| Database    | MySQL 8.x via MySQLi (prepared statements)           |
| Admin UI    | Bootstrap 5.3 + Bootstrap Icons (CDN)                |
| Hosting     | Any PHP + MySQL shared host / cPanel                 |

---

## File Structure

```
order-form/
├── index.html        # Order form — all markup, styles, and JS in one file
├── save_order.php    # API endpoint — validates POST body, inserts into MySQL
├── db.php            # Database connection — reads credentials from .env
├── admin.php         # Admin panel — CRUD, search, pagination, CSV export
├── setup_db.sql      # Run once in phpMyAdmin to create the orders table
├── .env              # Credentials (gitignored — never commit this)
├── .env.example      # Safe template to copy and fill in
├── .htaccess         # Disables directory listing; blocks direct access to .env and db.php
├── README.md         # This file
└── CHANGELOG.md      # Version history
```

> `.env` is listed in `.gitignore` and must never be committed. Copy `.env.example` to `.env` and fill in your cPanel MySQL credentials.

---

## Deployment

### 1. Create the database

1. Log in to cPanel → **MySQL Databases**.
2. Create a new database (e.g. `cpanelusername_g90`).
3. Create a new user with a strong password.
4. Add the user to the database and grant **All Privileges**.

### 2. Create the table

1. In cPanel → **phpMyAdmin**, select your database from the left sidebar.
2. Click **Import**, choose `setup_db.sql`, and click **Go**.

### 3. Configure credentials

```bash
cp .env.example .env
```

Edit `.env` and fill in your cPanel values:

```ini
DB_HOST=localhost
DB_NAME=cpanelusername_g90
DB_USER=cpanelusername_user
DB_PASS=your_strong_db_password

ADMIN_USER=admin
ADMIN_PASS=changeme
```

> **Tip — upgrade to bcrypt password (recommended):**
> ```bash
> php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT);"
> ```
> Paste the resulting `$2y$...` hash as `ADMIN_PASS`. `admin.php` detects and uses the right comparison method automatically.

### 4. Upload files

Upload all project files to your target directory (e.g. `public_html/order-form/`) via cPanel File Manager or FTP. Do **not** upload `.env` to a public git repository.

### 5. Protect sensitive files

The `.htaccess` already blocks direct HTTP access to `.env` and `db.php`. Optionally move `.env` one level **above** `public_html` for stronger isolation, then update the `loadEnv()` path in `db.php`:

```php
loadEnv(dirname(__DIR__) . '/.env');
```

### 6. Test

- Submit the order form and verify the acknowledgement card appears.
- Check phpMyAdmin to confirm a row was inserted in the `orders` table.
- Open `admin.php`, log in, and confirm the order appears in the admin list.

---

## Configuration

### Pricing (`index.html`)

```js
const LARGE_SIZES    = ['3XL', '4XL', '5XL'];  // sizes that attract a surcharge
const PRICE_BASE     = 90;                       // RM — S through XXL
const PRICE_LARGE    = 115;                      // RM — 3XL through 5XL
const PRICE_SHIPPING = 12;                       // RM — delivery charge
```

### Allowed amounts whitelist (`save_order.php`)

The PHP backend independently validates that `jumlah_bayaran` is one of four permitted values. Update whenever pricing constants change:

```php
$allowed_amounts = [90, 102, 115, 127];
// 90  = base, no delivery
// 102 = base + shipping (90 + 12)
// 115 = large size, no delivery
// 127 = large size + shipping (115 + 12)
```

### Contact email (`save_order.php`)

```php
$to = 'urusetia@sdar90.net';
```

### Order number format

Format `YYYYMMDD-NN` — generated client-side in `generateOrderNo()` and validated server-side with `/^\d{8}-\d{2}$/`. Counter resets to `01` each calendar day and is stored in `localStorage` under `g90_order_counter`.

---

## Database Schema

Orders are stored in a single `orders` table:

| Column           | Type              | Description                                        |
|------------------|-------------------|----------------------------------------------------|
| `id`             | INT AUTO_INCREMENT | Internal primary key                              |
| `order_no`       | VARCHAR(20) UNIQUE | `YYYYMMDD-NN` order identifier                   |
| `timestamp`      | DATETIME           | Server time of submission                         |
| `nama`           | VARCHAR(255)       | Customer full name                                |
| `telefon`        | VARCHAR(20)        | Phone number                                      |
| `saiz`           | VARCHAR(10)        | Jacket size — S M L XL XXL 3XL 4XL 5XL           |
| `penghantaran`   | TINYINT(1)         | `1` = delivery, `0` = self-collection             |
| `alamat`         | TEXT               | Street address (delivery only)                    |
| `poskod`         | VARCHAR(10)        | 5-digit postcode (delivery only)                  |
| `bandar`         | VARCHAR(100)       | City / town (delivery only)                       |
| `negeri`         | VARCHAR(100)       | State (delivery only)                             |
| `jumlah_bayaran` | DECIMAL(8,2)       | Total amount in RM: 90, 102, 115, or 127          |
| `created_at`     | TIMESTAMP          | Auto-set by database on insert                    |

---

## Admin Panel

Access `admin.php` in any browser. Log in with the credentials set in `.env`.

| Feature         | Details                                                         |
|-----------------|-----------------------------------------------------------------|
| Dashboard stats | Total orders, total revenue, today's orders, delivery count    |
| Order list      | Paginated table (25/page), sortable columns, keyword search    |
| Search          | Matches `order_no`, `nama`, or `telefon`                       |
| Create          | Add an order manually (auto-suggests next `order_no`)          |
| Edit            | Update any field on any existing order                         |
| Delete          | Confirm-modal hard delete                                       |
| CSV export      | Downloads all orders as a UTF-8 BOM CSV (Excel-friendly)       |

---

## Security Notes

- **Prepared statements** — every database query (insert, update, delete, select) uses MySQLi prepared statements with bound parameters; no raw SQL string interpolation on user data.
- **`.env` for credentials** — DB credentials and admin password live in `.env` (gitignored) and are never hardcoded in source files.
- **Input sanitisation** — all string inputs processed with `strip_tags()`, `trim()`, and `mb_substr()` before use.
- **Whitelist validation** — jacket size checked against `$allowed_sizes`; total amount checked against `$allowed_amounts`; values outside either list return HTTP 422.
- **CSRF protection** — all admin forms include a CSRF token stored in the PHP session; mismatches return HTTP 403.
- **Session hardening** — session ID is regenerated on login (`session_regenerate_id(true)`).
- **bcrypt support** — `ADMIN_PASS` can be stored as a bcrypt hash; `admin.php` detects the `$2y$` prefix and uses `password_verify()` automatically.
- **HTTP access blocking** — `.htaccess` denies direct HTTP requests to `.env` and `db.php` via `<FilesMatch>`.
- **Directory listing disabled** — `Options -Indexes` in `.htaccess`.
- **Method enforcement** — `save_order.php` rejects all non-`POST` requests with HTTP 405.
- **Duplicate order protection** — `UNIQUE KEY` on `order_no` at the database level; a duplicate returns HTTP 409.

---

## License

MIT License. Copyright (c) 2026 GLORIOUS90 / SDAR 1986–1990.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED.
