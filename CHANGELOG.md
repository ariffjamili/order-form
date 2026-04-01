# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- **Flat-file order storage** — Validated orders are appended to `orders.json` (auto-created if absent) using `file_put_contents()` with `LOCK_EX` to prevent concurrent write corruption.
- **Order record schema** — Each record stores: `order_no`, `timestamp` (server `Y-m-d H:i:s`), `nama`, `telefon`, `saiz`, `penghantaran` (boolean), `jumlah_bayaran` (integer); delivery records additionally include `alamat`, `poskod`, `bandar`, `negeri`.
- **JSON output** — Orders file written with `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE` flags for human-readable storage of Malay text.
- **Responsive layout** — `max-width: 560px` centred layout; address row switches to single column below 420 px; size grid maintains 4-column layout at all breakpoints.
- **Footer** — Copyright line: "© 2026 GLORIOUS90 — SDAR 1986–1990".

[1.0.0]: https://github.com/your-org/order-form/releases/tag/v1.0.0
