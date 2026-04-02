<?php
/**
 * admin.php — GLORIOUS90 Order Management (Admin CRUD)
 * ─────────────────────────────────────────────────────
 * Single-file admin panel. Session-based auth, CSRF protection,
 * prepared statements on every query. Zero Composer dependencies.
 */
declare(strict_types=1);
session_start();

// ── DB + .env (loadEnv defined inside db.php) ────────────────────────────────
/** @var mysqli $db */
$db = require __DIR__ . '/db.php';

// ── Admin credentials from .env ───────────────────────────────────────────────
$ADMIN_USER = $_ENV['ADMIN_USER'] ?? 'admin';
$ADMIN_PASS = $_ENV['ADMIN_PASS'] ?? '';

// ── CSRF helpers ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') . '">';
}
function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Token CSRF tidak sah. Sila muat semula halaman.');
    }
}

// ── Auth helpers ──────────────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['g90_admin']);
}
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: admin.php?action=login');
        exit;
    }
}

/**
 * Supports both bcrypt hashes ($2y$...) and plain-text passwords.
 * Upgrade path: run  php -r "echo password_hash('pass', PASSWORD_BCRYPT);"
 * and paste the result into ADMIN_PASS in .env.
 */
function checkPassword(string $input, string $stored): bool {
    if (strlen($stored) >= 60 && $stored[0] === '$') {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

// ── Sanitise / output helpers ─────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function clean(mixed $v, int $maxLen = 255): string {
    return mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $maxLen);
}

// ── Flash messages ────────────────────────────────────────────────────────────
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ── Auto-generate next order_no for today ─────────────────────────────────────
function nextOrderNo(mysqli $db): string {
    $prefix = date('Ymd') . '-%';
    $stmt = $db->prepare('SELECT order_no FROM orders WHERE order_no LIKE ? ORDER BY order_no DESC LIMIT 1');
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $stmt->bind_result($last);
    $stmt->fetch();
    $stmt->close();
    $seq = $last ? ((int)explode('-', $last)[1] + 1) : 1;
    return date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// ── Domain constants ──────────────────────────────────────────────────────────
$SIZES   = ['S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];
$AMOUNTS = [90, 102, 115, 127];
$NEGERI  = [
    'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
    'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor',
    'Terengganu', 'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya',
];

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS  (all mutating actions — redirect after success)
// ═════════════════════════════════════════════════════════════════════════════
$action = trim($_GET['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // ── Login ──────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if ($u === $ADMIN_USER && checkPassword($p, $ADMIN_PASS)) {
            session_regenerate_id(true);
            $_SESSION['g90_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        setFlash('danger', 'Nama pengguna atau kata laluan tidak sah.');
        header('Location: admin.php?action=login');
        exit;
    }

    // ── Logout ─────────────────────────────────────────────────────────────
    if ($action === 'logout') {
        session_destroy();
        header('Location: admin.php?action=login');
        exit;
    }

    // All actions below require login
    requireLogin();

    // ── Delete ─────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM orders WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        setFlash($ok ? 'success' : 'warning', $ok ? 'Pesanan berjaya dipadam.' : 'Pesanan tidak dijumpai.');
        header('Location: admin.php');
        exit;
    }

    // ── Shared validation for Create & Update ──────────────────────────────
    if (in_array($action, ['create', 'update'], true)) {
        $errors = [];

        $id           = (int)($_POST['id'] ?? 0);          // 0 for create
        $order_no     = clean($_POST['order_no']  ?? '', 20);
        $ts_raw       = clean($_POST['timestamp'] ?? '', 20);
        $nama         = clean($_POST['nama']      ?? '');
        $telefon      = clean($_POST['telefon']   ?? '', 20);
        $saiz         = clean($_POST['saiz']      ?? '', 10);
        $jumlah       = (float)($_POST['jumlah_bayaran'] ?? 0);
        $penghantaran = isset($_POST['penghantaran']) ? 1 : 0;

        // Convert datetime-local (YYYY-MM-DDTHH:MM) → MySQL DATETIME
        $timestamp = str_replace('T', ' ', $ts_raw);
        if (strlen($timestamp) === 16) $timestamp .= ':00';

        // Optional address fields (required when penghantaran = 1)
        $alamat = $penghantaran ? (clean($_POST['alamat'] ?? '', 1000) ?: null) : null;
        $poskod = $penghantaran ? (clean($_POST['poskod'] ?? '', 10)   ?: null) : null;
        $bandar = $penghantaran ? (clean($_POST['bandar'] ?? '')        ?: null) : null;
        $negeri = $penghantaran ? (clean($_POST['negeri'] ?? '')        ?: null) : null;

        // Validation
        if (!preg_match('/^\d{8}-\d{2}$/', $order_no))
            $errors[] = 'Format order_no tidak sah (YYYYMMDD-NN).';
        if ($nama === '')
            $errors[] = 'Nama diperlukan.';
        if (!preg_match('/^\+?[\d]{9,15}$/', $telefon))
            $errors[] = 'Nombor telefon tidak sah.';
        if (!in_array($saiz, $SIZES, true))
            $errors[] = 'Saiz tidak sah.';
        if (!in_array((int)$jumlah, $AMOUNTS, true))
            $errors[] = 'Jumlah bayaran tidak sah.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp))
            $errors[] = 'Format tarikh/masa tidak sah.';
        if ($penghantaran) {
            if (!$alamat)                                          $errors[] = 'Alamat diperlukan untuk penghantaran.';
            if (!$poskod || !preg_match('/^\d{5}$/', $poskod))    $errors[] = 'Poskod mesti 5 digit.';
            if (!$bandar)                                          $errors[] = 'Bandar diperlukan.';
            if (!$negeri)                                          $errors[] = 'Negeri diperlukan.';
        }

        if ($errors) {
            setFlash('danger', implode(' ', $errors));
            $_SESSION['form_data'] = $_POST; // repopulate on redirect
            $back = $action === 'update' ? "admin.php?action=edit&id={$id}" : 'admin.php?action=create';
            header("Location: $back");
            exit;
        }

        // ── INSERT ─────────────────────────────────────────────────────────
        if ($action === 'create') {
            $stmt = $db->prepare(
                'INSERT INTO orders
                 (order_no, timestamp, nama, telefon, saiz, penghantaran,
                  alamat, poskod, bandar, negeri, jumlah_bayaran)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'sssssissssd',
                $order_no, $timestamp, $nama, $telefon, $saiz,
                $penghantaran, $alamat, $poskod, $bandar, $negeri, $jumlah
            );
            if ($stmt->execute()) {
                $stmt->close();
                setFlash('success', "Pesanan <strong>{$order_no}</strong> berjaya ditambah.");
                header('Location: admin.php');
                exit;
            }
            $errMsg = ($db->errno === 1062)
                ? 'No. pesanan sudah wujud dalam sistem.'
                : 'Gagal menyimpan pesanan. Cuba lagi.';
            $stmt->close();
            setFlash('danger', $errMsg);
            $_SESSION['form_data'] = $_POST;
            header('Location: admin.php?action=create');
            exit;
        }

        // ── UPDATE ─────────────────────────────────────────────────────────
        if ($action === 'update') {
            if (!$id) {
                setFlash('danger', 'ID pesanan tidak sah.');
                header('Location: admin.php');
                exit;
            }
            $stmt = $db->prepare(
                'UPDATE orders
                 SET order_no=?, timestamp=?, nama=?, telefon=?, saiz=?,
                     penghantaran=?, alamat=?, poskod=?, bandar=?, negeri=?,
                     jumlah_bayaran=?
                 WHERE id=?'
            );
            $stmt->bind_param(
                'sssssissssdi',
                $order_no, $timestamp, $nama, $telefon, $saiz,
                $penghantaran, $alamat, $poskod, $bandar, $negeri, $jumlah, $id
            );
            if ($stmt->execute()) {
                $stmt->close();
                setFlash('success', "Pesanan <strong>{$order_no}</strong> berjaya dikemas kini.");
                header('Location: admin.php');
                exit;
            }
            $stmt->close();
            setFlash('danger', 'Gagal mengemas kini pesanan. Cuba lagi.');
            header("Location: admin.php?action=edit&id={$id}");
            exit;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// GET HANDLERS  (views + CSV export)
// ═════════════════════════════════════════════════════════════════════════════

// Redirect to login for any non-login GET
if (!isLoggedIn() && $action !== 'login') {
    header('Location: admin.php?action=login');
    exit;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if ($action === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel compatibility)
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'No. Pesanan', 'Tarikh', 'Nama', 'Telefon', 'Saiz',
                   'Penghantaran', 'Alamat', 'Poskod', 'Bandar', 'Negeri',
                   'Jumlah (RM)', 'Dicipta Pada']);
    $res = $db->query('SELECT * FROM orders ORDER BY timestamp DESC');
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['id'],
            $row['order_no'],
            $row['timestamp'],
            $row['nama'],
            $row['telefon'],
            $row['saiz'],
            $row['penghantaran'] ? 'Ya' : 'Tidak',
            $row['alamat']  ?? '',
            $row['poskod']  ?? '',
            $row['bandar']  ?? '',
            $row['negeri']  ?? '',
            number_format((float)$row['jumlah_bayaran'], 2),
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Dashboard stats (list view) ───────────────────────────────────────────────
$stats = ['total' => 0, 'revenue' => 0, 'today' => 0, 'delivery' => 0];
if ($action === 'list') {
    $r = $db->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(jumlah_bayaran), 0) AS revenue,
                SUM(DATE(timestamp) = CURDATE()) AS today,
                SUM(penghantaran = 1) AS delivery
         FROM orders'
    );
    $stats = $r->fetch_assoc();
}

// ── List — paginated, searchable, sortable ────────────────────────────────────
$orders     = [];
$totalRows  = 0;
$perPage    = 25;
$page       = max(1, (int)($_GET['page'] ?? 1));
$search     = clean($_GET['q'] ?? '', 100);
$offset     = ($page - 1) * $perPage;

$allowedSorts = ['order_no', 'timestamp', 'nama', 'saiz', 'jumlah_bayaran'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'timestamp';
$dir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

if ($action === 'list') {
    if ($search !== '') {
        $like = '%' . $search . '%';
        // Count
        $cs = $db->prepare('SELECT COUNT(*) FROM orders WHERE order_no LIKE ? OR nama LIKE ? OR telefon LIKE ?');
        $cs->bind_param('sss', $like, $like, $like);
        $cs->execute();
        $cs->bind_result($totalRows);
        $cs->fetch();
        $cs->close();
        // Rows — column name is safe (validated above), so interpolation is OK
        $ls = $db->prepare("SELECT * FROM orders WHERE order_no LIKE ? OR nama LIKE ? OR telefon LIKE ? ORDER BY $sort $dir LIMIT ? OFFSET ?");
        $ls->bind_param('sssii', $like, $like, $like, $perPage, $offset);
    } else {
        [$totalRows] = $db->query('SELECT COUNT(*) FROM orders')->fetch_row();
        $ls = $db->prepare("SELECT * FROM orders ORDER BY $sort $dir LIMIT ? OFFSET ?");
        $ls->bind_param('ii', $perPage, $offset);
    }
    $ls->execute();
    $res = $ls->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $ls->close();
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Edit — fetch single row ───────────────────────────────────────────────────
$editOrder = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$editOrder) {
        setFlash('warning', 'Pesanan tidak dijumpai.');
        header('Location: admin.php');
        exit;
    }
}

// ── Create — default field values ────────────────────────────────────────────
$newOrderNo = '';
$formData   = [];
if ($action === 'create') {
    $newOrderNo = nextOrderNo($db);
    $formData   = $_SESSION['form_data'] ?? [];
    unset($_SESSION['form_data']);
}

// ── Form data helper ──────────────────────────────────────────────────────────
function fv(string $key, array $formData, ?array $order, mixed $default = ''): mixed {
    if ($formData && array_key_exists($key, $formData)) return $formData[$key];
    if ($order  && array_key_exists($key, $order))      return $order[$key];
    return $default;
}

$flash = getFlash();

// ═════════════════════════════════════════════════════════════════════════════
// RENDER
// ═════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — GLORIOUS90</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
      crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body          { background: #f0f2f5; font-size: .9375rem; }
  .sidebar      { width: 220px; min-height: 100vh; background: #111827; flex-shrink: 0; }
  .sidebar .brand { font-weight: 700; letter-spacing: .5px; font-size: 1.05rem; }
  .sidebar .nav-link { color: #9ca3af; border-radius: .375rem; padding: .45rem .75rem; }
  .sidebar .nav-link:hover,
  .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.08); }
  .sidebar .nav-link i     { width: 1.25rem; }
  .main         { flex: 1; min-width: 0; padding: 1.75rem 1.5rem; }
  .stat-card    { border: none; border-radius: .875rem; color: #fff; padding: 1.25rem 1.5rem; }
  .stat-card .label { font-size: .78rem; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
  .stat-card .value { font-size: 2rem; font-weight: 700; line-height: 1.1; margin-top: .25rem; }
  .table th     { font-size: .78rem; text-transform: uppercase; letter-spacing: .4px;
                  color: #6b7280; white-space: nowrap; border-bottom-width: 1px; }
  .table td     { vertical-align: middle; }
  .sort-link    { color: inherit; text-decoration: none; }
  .sort-link:hover { color: #2563eb; }
  #addressBlock { display: none; }
  .badge-saiz   { min-width: 2.5rem; }
  .login-wrap   { max-width: 380px; }

  /* Login page uses full-screen centred layout */
  .login-page   { min-height: 100vh; display: flex; align-items: center;
                  justify-content: center; background: #111827; }
  .login-card   { background: #1f2937; border: 1px solid #374151; border-radius: 1rem; }
  .login-card .form-control { background: #111827; border-color: #374151; color: #f9fafb; }
  .login-card .form-control:focus { background: #111827; border-color: #3b82f6; color: #f9fafb; box-shadow: 0 0 0 .2rem rgba(59,130,246,.25); }
  .login-card .form-label { color: #d1d5db; }
</style>
</head>
<body>

<?php if ($action === 'login'): // ════════════════════ LOGIN PAGE ══════════ ?>

<div class="login-page">
  <div class="login-wrap w-100 px-3">
    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> mb-3"><?= $flash['msg'] ?></div>
    <?php endif; ?>
    <div class="card login-card shadow-lg">
      <div class="card-body p-4 p-sm-5">
        <div class="mb-4">
          <p class="text-uppercase text-secondary mb-1" style="font-size:.7rem;letter-spacing:2px">Panel Pentadbiran</p>
          <h4 class="fw-bold text-white mb-0">GLORIOUS90</h4>
        </div>
        <form method="POST" action="admin.php?action=login" autocomplete="off">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Nama Pengguna</label>
            <input type="text" name="username" class="form-control" autofocus required autocomplete="username">
          </div>
          <div class="mb-4">
            <label class="form-label small fw-semibold">Kata Laluan</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
          </div>
          <button class="btn btn-primary w-100 fw-semibold">Log Masuk</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php else: // ══════════════════════ MAIN LAYOUT (with sidebar) ═══════════ ?>

<div class="d-flex">

  <!-- Sidebar -->
  <aside class="sidebar d-none d-lg-flex flex-column p-3 gap-1">
    <div class="brand text-white px-2 py-3 mb-2">
      <i class="bi bi-shield-lock-fill me-2 text-primary"></i>GLORIOUS90
    </div>
    <nav class="nav flex-column gap-1">
      <a href="admin.php" class="nav-link <?= $action === 'list' ? 'active' : '' ?>">
        <i class="bi bi-list-ul"></i> Semua Pesanan
      </a>
      <a href="admin.php?action=create" class="nav-link <?= $action === 'create' ? 'active' : '' ?>">
        <i class="bi bi-plus-circle"></i> Tambah Pesanan
      </a>
      <a href="admin.php?action=export" class="nav-link">
        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
      </a>
    </nav>
    <div class="mt-auto">
      <form method="POST" action="admin.php?action=logout">
        <?= csrfField() ?>
        <button class="nav-link btn btn-link w-100 text-start text-danger">
          <i class="bi bi-box-arrow-left"></i> Log Keluar
        </button>
      </form>
    </div>
  </aside>

  <!-- Top bar (mobile) -->
  <div class="d-lg-none w-100 position-fixed top-0 start-0 z-3"
       style="background:#111827;padding:.75rem 1rem;display:flex;align-items:center;gap:1rem">
    <span class="text-white fw-bold flex-grow-1">
      <i class="bi bi-shield-lock-fill me-2 text-primary"></i>GLORIOUS90 Admin
    </span>
    <a href="admin.php?action=create" class="btn btn-sm btn-success">
      <i class="bi bi-plus-lg"></i>
    </a>
    <a href="admin.php?action=export" class="btn btn-sm btn-outline-light">
      <i class="bi bi-download"></i>
    </a>
    <form method="POST" action="admin.php?action=logout" class="m-0">
      <?= csrfField() ?>
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i></button>
    </form>
  </div>

  <!-- Main content -->
  <main class="main" style="padding-top: 4.5rem" id="mainContent">

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= $flash['msg'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php // ══════════════════════ LIST VIEW ═══════════════════════════════
    if ($action === 'list'): ?>

    <!-- Page header -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
      <h5 class="fw-bold mb-0 me-auto">Senarai Pesanan</h5>
      <a href="admin.php?action=create" class="btn btn-success btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Pesanan
      </a>
      <a href="admin.php?action=export" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download me-1"></i>Export CSV
      </a>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#1d4ed8,#2563eb)">
          <div class="label">Jumlah Pesanan</div>
          <div class="value"><?= number_format((int)$stats['total']) ?></div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#065f46,#059669)">
          <div class="label">Jumlah Hasil</div>
          <div class="value" style="font-size:1.5rem">RM <?= number_format((float)$stats['revenue'], 2) ?></div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6d28d9,#7c3aed)">
          <div class="label">Hari Ini</div>
          <div class="value"><?= number_format((int)$stats['today']) ?></div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#b45309,#d97706)">
          <div class="label">Penghantaran</div>
          <div class="value"><?= number_format((int)$stats['delivery']) ?></div>
        </div>
      </div>
    </div>

    <!-- Search bar -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body py-2 px-3">
        <form method="GET" action="admin.php" class="d-flex gap-2 align-items-center flex-wrap">
          <input type="hidden" name="action" value="list">
          <i class="bi bi-search text-muted"></i>
          <input type="search" name="q" value="<?= h($search) ?>"
                 class="form-control form-control-sm border-0 flex-grow-1"
                 placeholder="Cari nama, no. pesanan atau telefon…"
                 style="min-width:180px">
          <button class="btn btn-sm btn-dark">Cari</button>
          <?php if ($search): ?>
          <a href="admin.php" class="btn btn-sm btn-outline-secondary">Reset</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Orders table -->
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <?php
              function sortTh(string $col, string $label, string $cs, string $cd, string $q): void {
                  $active  = $cs === $col;
                  $nextDir = ($active && $cd === 'DESC') ? 'asc' : 'desc';
                  $icon    = $active
                      ? ($cd === 'DESC' ? 'bi-sort-down-alt' : 'bi-sort-up-alt')
                      : 'bi-arrow-down-up opacity-25';
                  $qs = http_build_query(array_filter(['action' => 'list', 'sort' => $col, 'dir' => $nextDir, 'q' => $q]));
                  echo "<th><a href=\"admin.php?$qs\" class=\"sort-link\">$label <i class=\"bi $icon\"></i></a></th>";
              }
              ?>
              <th class="ps-3">ID</th>
              <?php sortTh('order_no',      'No. Pesanan', $sort, $dir, $search) ?>
              <?php sortTh('timestamp',     'Tarikh',      $sort, $dir, $search) ?>
              <?php sortTh('nama',          'Nama',        $sort, $dir, $search) ?>
              <th>Telefon</th>
              <?php sortTh('saiz',          'Saiz',        $sort, $dir, $search) ?>
              <th class="text-center">Hantar</th>
              <?php sortTh('jumlah_bayaran','Jumlah (RM)', $sort, $dir, $search) ?>
              <th class="text-center pe-3">Tindakan</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>
                <?= $search ? 'Tiada pesanan sepadan dengan carian.' : 'Belum ada pesanan.' ?>
              </td>
            </tr>
            <?php else: foreach ($orders as $o): ?>
            <tr>
              <td class="ps-3 text-muted small"><?= h($o['id']) ?></td>
              <td><code class="text-primary"><?= h($o['order_no']) ?></code></td>
              <td class="small text-nowrap"><?= h($o['timestamp']) ?></td>
              <td class="fw-medium"><?= h($o['nama']) ?></td>
              <td class="small text-nowrap"><?= h($o['telefon']) ?></td>
              <td><span class="badge bg-secondary badge-saiz"><?= h($o['saiz']) ?></span></td>
              <td class="text-center">
                <?php if ($o['penghantaran']): ?>
                  <i class="bi bi-truck-front-fill text-primary" title="Penghantaran"></i>
                <?php else: ?>
                  <i class="bi bi-person-walking text-muted" title="Ambil sendiri"></i>
                <?php endif; ?>
              </td>
              <td class="fw-semibold text-nowrap"><?= number_format((float)$o['jumlah_bayaran'], 2) ?></td>
              <td class="text-center pe-3 text-nowrap">
                <a href="admin.php?action=edit&id=<?= h($o['id']) ?>"
                   class="btn btn-sm btn-outline-primary py-0 me-1" title="Kemaskini">
                  <i class="bi bi-pencil"></i>
                </a>
                <button type="button" class="btn btn-sm btn-outline-danger py-0 js-delete-btn"
                        title="Padam"
                        data-id="<?= h($o['id']) ?>"
                        data-orderno="<?= h($o['order_no']) ?>"
                        data-bs-toggle="modal" data-bs-target="#deleteModal">
                  <i class="bi bi-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1 || $totalRows > 0): ?>
      <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <small class="text-muted">
          Menunjukkan <?= h(count($orders)) ?> daripada <?= h($totalRows) ?> rekod
        </small>
        <?php if ($totalPages > 1): ?>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $totalPages; $p++):
              $qs = http_build_query(array_filter([
                  'action' => 'list', 'page' => $p,
                  'sort' => $sort, 'dir' => strtolower($dir), 'q' => $search,
              ]));
            ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="admin.php?<?= $qs ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Delete confirm modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
          <div class="modal-body p-4 text-center">
            <i class="bi bi-exclamation-circle-fill text-danger fs-1 mb-3 d-block"></i>
            <h6 class="fw-bold mb-1">Padam Pesanan?</h6>
            <p class="text-muted small mb-3">
              Pesanan <strong id="deleteOrderNo" class="text-dark"></strong> akan dipadam
              secara kekal dan tidak boleh dipulihkan.
            </p>
            <form method="POST" action="admin.php?action=delete" id="deleteForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" id="deleteId">
              <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger btn-sm">Ya, Padam</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php // ══════════════════ CREATE / EDIT FORM ═══════════════════════════
    elseif (in_array($action, ['create', 'edit'], true)):
      $isEdit  = ($action === 'edit');
      $d       = $isEdit ? $editOrder : null; // source of truth for edit
      $fd      = $isEdit ? [] : $formData;    // repopulated form data (create only)

      // Shorthand: fv() picks formData > editOrder > default
      $v = fn(string $k, mixed $def = '') => fv($k, $fd, $d, $def);

      // Compute datetime-local value from stored DATETIME (YYYY-MM-DD HH:MM:SS → YYYY-MM-DDTHH:MM)
      $tsDisplay = $v('timestamp', date('Y-m-d H:i:s'));
      if ($isEdit && strpos($tsDisplay, 'T') === false) {
          $tsDisplay = str_replace(' ', 'T', substr($tsDisplay, 0, 16));
      }

      $currentSaiz   = $v('saiz',           'M');
      $currentJumlah = (int)$v('jumlah_bayaran', 90);
      $isPenghantaran = $isEdit
          ? (bool)$d['penghantaran']
          : isset($fd['penghantaran']);
    ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
      <a href="admin.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
      </a>
      <h5 class="fw-bold mb-0">
        <?= $isEdit ? 'Kemaskini Pesanan' : 'Tambah Pesanan Baru' ?>
      </h5>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <form method="POST"
              action="admin.php?action=<?= $isEdit ? 'update' : 'create' ?>"
              novalidate>
          <?= csrfField() ?>
          <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= h($d['id']) ?>">
          <?php endif; ?>

          <!-- Section: Maklumat Pesanan -->
          <p class="text-uppercase text-muted fw-semibold mb-3"
             style="font-size:.72rem;letter-spacing:.8px">
            Maklumat Pesanan
          </p>
          <div class="row g-3 mb-4">
            <div class="col-sm-4">
              <label class="form-label fw-medium">No. Pesanan <span class="text-danger">*</span></label>
              <input type="text" name="order_no" class="form-control"
                     value="<?= h($v('order_no', $newOrderNo)) ?>"
                     pattern="\d{8}-\d{2}" placeholder="20260402-01" required maxlength="20">
              <div class="form-text">Format: YYYYMMDD-NN</div>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-medium">Tarikh &amp; Masa <span class="text-danger">*</span></label>
              <input type="datetime-local" name="timestamp" class="form-control"
                     value="<?= h($tsDisplay) ?>" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-medium">Saiz <span class="text-danger">*</span></label>
              <select name="saiz" class="form-select" required>
                <?php foreach ($SIZES as $sz): ?>
                <option value="<?= $sz ?>" <?= $currentSaiz === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Section: Maklumat Pembeli -->
          <p class="text-uppercase text-muted fw-semibold mb-3"
             style="font-size:.72rem;letter-spacing:.8px">
            Maklumat Pembeli
          </p>
          <div class="row g-3 mb-4">
            <div class="col-sm-6">
              <label class="form-label fw-medium">Nama <span class="text-danger">*</span></label>
              <input type="text" name="nama" class="form-control"
                     value="<?= h($v('nama')) ?>" required maxlength="255">
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-medium">Telefon <span class="text-danger">*</span></label>
              <input type="tel" name="telefon" class="form-control"
                     value="<?= h($v('telefon')) ?>"
                     pattern="^\+?[\d]{9,15}$" required maxlength="20">
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-medium">Jumlah Bayaran <span class="text-danger">*</span></label>
              <select name="jumlah_bayaran" class="form-select" required>
                <?php foreach ($AMOUNTS as $amt): ?>
                <option value="<?= $amt ?>" <?= $currentJumlah === $amt ? 'selected' : '' ?>>
                  RM <?= number_format($amt, 2) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Section: Penghantaran -->
          <p class="text-uppercase text-muted fw-semibold mb-3"
             style="font-size:.72rem;letter-spacing:.8px">
            Penghantaran
          </p>
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     name="penghantaran" id="penghantaranSwitch" value="1"
                     <?= $isPenghantaran ? 'checked' : '' ?>>
              <label class="form-check-label" for="penghantaranSwitch">
                Penghantaran ke alamat
                <span class="text-muted small">(nyahcentang = ambil sendiri)</span>
              </label>
            </div>
          </div>

          <div id="addressBlock" class="row g-3 mb-2">
            <div class="col-12">
              <label class="form-label fw-medium">Alamat <span class="text-danger">*</span></label>
              <textarea name="alamat" class="form-control" rows="2"
                        maxlength="1000"><?= h($v('alamat')) ?></textarea>
            </div>
            <div class="col-sm-2">
              <label class="form-label fw-medium">Poskod <span class="text-danger">*</span></label>
              <input type="text" name="poskod" class="form-control"
                     value="<?= h($v('poskod')) ?>"
                     pattern="\d{5}" inputmode="numeric" maxlength="5">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-medium">Bandar <span class="text-danger">*</span></label>
              <input type="text" name="bandar" class="form-control"
                     value="<?= h($v('bandar')) ?>" maxlength="100">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-medium">Negeri <span class="text-danger">*</span></label>
              <select name="negeri" class="form-select">
                <option value="">— Pilih Negeri —</option>
                <?php foreach ($NEGERI as $neg): ?>
                <option value="<?= h($neg) ?>" <?= $v('negeri') === $neg ? 'selected' : '' ?>>
                  <?= h($neg) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex gap-2 justify-content-end">
            <a href="admin.php" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-dark px-4 fw-semibold">
              <i class="bi bi-<?= $isEdit ? 'check-lg' : 'plus-lg' ?> me-1"></i>
              <?= $isEdit ? 'Simpan Perubahan' : 'Tambah Pesanan' ?>
            </button>
          </div>

        </form>
      </div>
    </div>

    <?php endif; // end views ?>

  </main><!-- /.main -->
</div><!-- /.d-flex -->

<?php endif; // end main layout ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmtrBO+JSwDYJLjJiQq9c2KoUfm2"
        crossorigin="anonymous"></script>
<script>
// ── Penghantaran toggle ───────────────────────────────────────────────────────
(function () {
  const sw    = document.getElementById('penghantaranSwitch');
  const block = document.getElementById('addressBlock');
  if (!sw || !block) return;

  const requiredFields = ['alamat', 'poskod', 'bandar', 'negeri'];

  function sync() {
    const on = sw.checked;
    block.style.display = on ? '' : 'none';
    block.querySelectorAll('input, select, textarea').forEach(el => {
      el.required = on && requiredFields.includes(el.name);
    });
  }

  sw.addEventListener('change', sync);
  sync(); // run on load
}());

// ── Delete modal — populate ID & order_no ────────────────────────────────────
(function () {
  const modal = document.getElementById('deleteModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', e => {
    const btn = e.relatedTarget;
    document.getElementById('deleteId').value          = btn.dataset.id;
    document.getElementById('deleteOrderNo').textContent = btn.dataset.orderno;
  });
}());

// ── Fix top padding for mobile nav ───────────────────────────────────────────
(function () {
  const main = document.getElementById('mainContent');
  if (!main) return;
  function adjustPadding() {
    main.style.paddingTop = window.innerWidth < 992 ? '4.5rem' : '1.75rem';
  }
  window.addEventListener('resize', adjustPadding);
  adjustPadding();
}());
</script>
</body>
</html>
