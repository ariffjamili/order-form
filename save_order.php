<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Only accept POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Read JSON body ──
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

// ── Sanitise helper ──
function clean(mixed $v, int $maxLen = 255): string {
    if (!isset($v)) return '';
    return mb_substr(trim(strip_tags((string)$v)), 0, $maxLen);
}

// ── Validate required fields ──
$order_no     = clean($data['order_no'] ?? '', 20);
$nama         = clean($data['nama']     ?? '');
$telefon      = clean($data['telefon']  ?? '', 20);
$saiz         = clean($data['saiz']     ?? '', 10);
$jumlah_raw   = $data['jumlah_bayaran'] ?? null;
$penghantaran = isset($data['penghantaran']) && $data['penghantaran'] === true;

$allowed_sizes   = ['S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];
$allowed_amounts = [90, 102, 115, 127];

$errors = [];
if ($order_no === '')                                                              $errors[] = 'order_no diperlukan.';
if ($nama     === '')                                                              $errors[] = 'nama diperlukan.';
if ($telefon  === '' || !preg_match('/^\+?[\d]{9,15}$/', $telefon))               $errors[] = 'telefon tidak sah.';
if (!in_array($saiz, $allowed_sizes, true))                                        $errors[] = 'saiz tidak sah.';
if (!is_numeric($jumlah_raw) || !in_array((int)$jumlah_raw, $allowed_amounts, true)) $errors[] = 'jumlah_bayaran tidak sah.';
if (!preg_match('/^\d{8}-\d{2}$/', $order_no))                                    $errors[] = 'format order_no tidak sah.';

// Address required when penghantaran = true
$alamat = null;
$poskod = null;
$bandar = null;
$negeri = null;
if ($penghantaran) {
    $alamat = clean($data['alamat'] ?? '') ?: null;
    $poskod = clean($data['poskod'] ?? '', 10) ?: null;
    $bandar = clean($data['bandar'] ?? '') ?: null;
    $negeri = clean($data['negeri'] ?? '') ?: null;
    if ($alamat === null)                          $errors[] = 'alamat diperlukan untuk penghantaran.';
    if ($poskod === null || !preg_match('/^\d{5}$/', $poskod)) $errors[] = 'poskod tidak sah.';
    if ($bandar === null)                          $errors[] = 'bandar diperlukan.';
    if ($negeri === null)                          $errors[] = 'negeri diperlukan.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$timestamp      = date('Y-m-d H:i:s');
$jumlah_bayaran = (float)$jumlah_raw;
$penghantaran_i = $penghantaran ? 1 : 0;

// ── Database connection ──
/** @var mysqli $mysqli */
$mysqli = require __DIR__ . '/db.php';

// ── Insert with prepared statement ──
$sql = "INSERT INTO `orders`
            (order_no, timestamp, nama, telefon, saiz,
             penghantaran, alamat, poskod, bandar, negeri, jumlah_bayaran)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal menyediakan query.']);
    exit;
}

// Types: s=string, i=integer, d=double
$stmt->bind_param(
    'sssssissssd',
    $order_no,
    $timestamp,
    $nama,
    $telefon,
    $saiz,
    $penghantaran_i,
    $alamat,
    $poskod,
    $bandar,
    $negeri,
    $jumlah_bayaran
);

if (!$stmt->execute()) {
    // Duplicate order_no (errno 1062) means the order was already saved
    if ($mysqli->errno === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'No. pesanan sudah wujud.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal simpan pesanan.']);
    }
    $stmt->close();
    $mysqli->close();
    exit;
}

$stmt->close();
$mysqli->close();

// ── Email notification ──
$order = [
    'order_no'      => $order_no,
    'timestamp'     => $timestamp,
    'nama'          => $nama,
    'telefon'       => $telefon,
    'saiz'          => $saiz,
    'penghantaran'  => $penghantaran,
    'jumlah_bayaran'=> $jumlah_bayaran,
    'alamat'        => $alamat ?? '',
    'poskod'        => $poskod ?? '',
    'bandar'        => $bandar ?? '',
    'negeri'        => $negeri ?? '',
];

function sendOrderEmail(array $order): bool {
    $to      = 'urusetia@sdar90.net';
    $subject = '[GLORIOUS90] Pesanan Baru — ' . $order['order_no'];

    $addressBlock = '';
    if ($order['penghantaran']) {
        $addressBlock =
            "Alamat        : {$order['alamat']}\n" .
            "Poskod        : {$order['poskod']}\n" .
            "Bandar        : {$order['bandar']}\n" .
            "Negeri        : {$order['negeri']}\n";
    }

    $deliveryLine = $order['penghantaran']
        ? "Ya (Penghantaran ke alamat)"
        : "Tidak (Pengambilan sendiri)";

    $body =
        "=========================================\n" .
        "  GLORIOUS90 — SDAR 1986-1990           \n" .
        "  Pesanan Jaket Bomber (Limited Edition) \n" .
        "=========================================\n\n" .
        "No. Pesanan   : {$order['order_no']}\n" .
        "Tarikh        : {$order['timestamp']}\n\n" .
        "--- Maklumat Pembeli ---\n" .
        "Nama          : {$order['nama']}\n" .
        "Telefon       : {$order['telefon']}\n\n" .
        "--- Butiran Pesanan ---\n" .
        "Saiz          : {$order['saiz']}\n" .
        "Penghantaran  : {$deliveryLine}\n" .
        $addressBlock .
        "Jumlah Bayaran: RM{$order['jumlah_bayaran']}\n\n" .
        "-----------------------------------------\n" .
        "Dari laman web sdar90.net/order-form\n";

    $headers = implode("\r\n", [
        'From: GLORIOUS90 Order System <no-reply@sdar90.net>',
        'Reply-To: no-reply@sdar90.net',
        'Bcc: ariffjamili@gmail.com',
        'X-Mailer: PHP/' . PHP_VERSION,
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    return mail($to, $subject, $body, $headers);
}

$emailSent = sendOrderEmail($order);

// ── Success ──
echo json_encode(['success' => true, 'order_no' => $order_no, 'email_sent' => $emailSent]);
exit;
