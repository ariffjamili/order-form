# GLORIOUS90 — Borang Tempahan Jaket Bomber

![HTML](https://img.shields.io/badge/HTML-5-E34F26?style=flat-square&logo=html5&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php&logoColor=white)
![Deployable on cPanel](https://img.shields.io/badge/Deployable%20on-cPanel-FF6C2C?style=flat-square)

A self-contained merchandise order form for the GLORIOUS90 limited-edition bomber jacket drop. Built for the SDAR 1986–1990 alumni community, the app collects customer details, calculates pricing, persists orders server-side, and falls back to a `mailto:` link — all without any external framework or database.

---

## Features

- **Bahasa Melayu UI** with a dark military/streetwear aesthetic (`#0f0f1a` background, `#c9a84c` gold accents)
- **Size selection** via toggle buttons — S, M, L, XL, XXL, 3XL, 4XL, 5XL
- **Automatic surcharge** — RM90 base price for S–XXL; RM115 for 3XL–5XL
- **Optional delivery** — checkbox reveals animated address fields (alamat, poskod, bandar, negeri); adds RM12 shipping charge
- **Live order summary** — item price, shipping line, and total update instantly as the user interacts
- **Order number generation** — client-side via `localStorage`, format `YYYYMMDD-NN` (resets daily, increments per device)
- **Server-side persistence** — JSON `POST` to `save_order.php`; orders appended to `orders.json` with `LOCK_EX`
- **`mailto:` fallback** — triggers the user's mail client to send order details to `urusetia@sdar90.net` regardless of PHP outcome
- **Acknowledgement card** — animated success screen displaying the order number after submission
- **Client-side validation** — required fields, phone format (`/^\+?[\d]{9,15}$/`), 5-digit postcode; inline error messages clear on input
- **Server-side validation** — PHP re-validates all fields, enforces allowed size and amount whitelists, and checks order number format
- **No frameworks** — plain HTML, vanilla JavaScript, and plain PHP; zero dependencies beyond Google Fonts

---

## Tech Stack

| Layer       | Technology                                      |
|-------------|-------------------------------------------------|
| Markup      | HTML5 (`lang="ms"`)                             |
| Styling     | CSS custom properties, CSS Grid, CSS transitions |
| Scripting   | Vanilla JavaScript (ES2017+, `async/await`)     |
| Fonts       | Google Fonts — Bebas Neue, Barlow Condensed, Barlow |
| Backend     | PHP 8.x (no framework)                          |
| Storage     | Flat-file JSON (`orders.json`)                  |
| Hosting     | Any PHP-capable shared host / cPanel            |

---

## File Structure

```
order-form/
├── index.html       # Order form — all markup, styles, and JS in one file
├── save_order.php   # API endpoint — validates POST body, appends to orders.json
├── orders.json      # Auto-created on first order; stores all order records
├── README.md        # This file
└── CHANGELOG.md     # Version history
```

> `orders.json` is created automatically by `save_order.php` on the first successful submission. It does **not** need to exist beforehand.

---

## Deployment

These steps apply to any shared hosting environment with cPanel file access and PHP support.

1. **Upload files** — Copy `index.html` and `save_order.php` into your target directory (e.g. `public_html/order-form/`) using cPanel File Manager or an FTP client.

2. **Create `orders.json`** (recommended) — Creating the file manually before the first order gives you explicit control over its permissions:
   - In cPanel File Manager, create a new file named `orders.json` in the same directory.
   - Set its content to `[]` (an empty JSON array).

3. **Set file permissions** — `save_order.php` must be able to write to `orders.json`.
   - If you created `orders.json` manually: set its permissions to **`644`** (owner read/write, group/world read). The web server process (typically `www-data` or the cPanel user) writes as the file owner on most shared hosts.
   - If you prefer to let PHP create the file automatically: ensure the **directory** itself is writable (`755` is usually sufficient on cPanel accounts where the web server runs as your user).

4. **Verify PHP version** — The backend uses `declare(strict_types=1)` and typed function parameters. PHP **8.0 or later** is required. Check via cPanel > "Select PHP Version" or by viewing `phpinfo()`.

5. **Test the form** — Open `index.html` in a browser, complete the form, and submit. Confirm that:
   - `orders.json` is created/updated with a new record.
   - The acknowledgement card is displayed.
   - Your mail client opens a pre-filled email to `urusetia@sdar90.net`.

---

## Configuration

All configurable values are constants or literal strings that can be edited directly in the source files.

### Pricing (`index.html`)

```js
const LARGE_SIZES    = ['3XL', '4XL', '5XL'];  // sizes that attract a surcharge
const PRICE_BASE     = 90;                       // RM — S through XXL
const PRICE_LARGE    = 115;                      // RM — 3XL through 5XL
const PRICE_SHIPPING = 12;                       // RM — delivery charge
```

### Allowed amounts whitelist (`save_order.php`)

The PHP backend independently validates that `jumlah_bayaran` is one of four permitted values. Update this array whenever pricing constants change:

```php
$allowed_amounts = [90, 102, 115, 127];
// 90  = base, no delivery
// 102 = base + shipping (90 + 12)
// 115 = large size, no delivery
// 127 = large size + shipping (115 + 12)
```

### Contact email (`index.html`)

The `mailto:` fallback sends to a hardcoded address. Change it at the bottom of the `<script>` block:

```js
window.location.href = `mailto:urusetia@sdar90.net?subject=${emailSubject}&body=${emailBody}`;
```

### Order number format

The format `YYYYMMDD-NN` is generated client-side in `generateOrderNo()` and validated server-side with the regex `/^\d{8}-\d{2}$/`. The counter resets to `01` each calendar day and is stored in `localStorage` under the key `g90_order_counter`.

---

## Order Data

All orders are stored in `orders.json` as a top-level JSON array. Each element is an object with the following fields:

| Field            | Type      | Always present | Description                                      |
|------------------|-----------|----------------|--------------------------------------------------|
| `order_no`       | `string`  | Yes            | Unique order identifier, e.g. `"20260401-01"`    |
| `timestamp`      | `string`  | Yes            | Server time of submission, `"YYYY-MM-DD HH:MM:SS"` |
| `nama`           | `string`  | Yes            | Customer's full name                             |
| `telefon`        | `string`  | Yes            | Phone number, digits only (leading `+` optional) |
| `saiz`           | `string`  | Yes            | Jacket size: one of `S M L XL XXL 3XL 4XL 5XL`  |
| `penghantaran`   | `boolean` | Yes            | `true` if delivery requested, `false` otherwise  |
| `jumlah_bayaran` | `integer` | Yes            | Total amount in RM: `90`, `102`, `115`, or `127` |
| `alamat`         | `string`  | Delivery only  | Street address                                   |
| `poskod`         | `string`  | Delivery only  | 5-digit Malaysian postcode                       |
| `bandar`         | `string`  | Delivery only  | City / town                                      |
| `negeri`         | `string`  | Delivery only  | State                                            |

**Example record:**

```json
{
  "order_no": "20260401-01",
  "timestamp": "2026-04-01 10:30:00",
  "nama": "Ahmad Faris",
  "telefon": "0123456789",
  "saiz": "XL",
  "penghantaran": true,
  "jumlah_bayaran": 102,
  "alamat": "12 Jalan Damai, Taman Maju",
  "poskod": "43000",
  "bandar": "Kajang",
  "negeri": "Selangor"
}
```

---

## Security Notes

- **Input sanitisation** — All string inputs are processed with `strip_tags()`, `trim()`, and `mb_substr()` (max 255 chars by default) before use.
- **Whitelist validation** — Jacket size is checked against `$allowed_sizes`; total amount is checked against `$allowed_amounts`. Any value outside these lists returns HTTP 422.
- **Phone format** — Validated with `/^\+?[\d]{9,15}$/` on both client and server.
- **Order number format** — Validated server-side with `/^\d{8}-\d{2}$/` to prevent path traversal or injection via that field.
- **Atomic writes** — `file_put_contents()` is called with `LOCK_EX`, preventing data corruption under concurrent submissions.
- **Method enforcement** — `save_order.php` rejects all non-`POST` requests with HTTP 405.
- **Content-Type header** — `X-Content-Type-Options: nosniff` is set to prevent MIME sniffing.
- **No database** — The flat-file approach eliminates SQL injection risk entirely.
- **Recommended:** Place `orders.json` outside the web root, or add a `.htaccess` rule to deny direct access, to prevent public enumeration of order data:
  ```apache
  <Files "orders.json">
      Require all denied
  </Files>
  ```

---

## License

MIT License. Copyright (c) 2026 GLORIOUS90 / SDAR 1986–1990.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED.
