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
$order_no   = clean($data['order_no'] ?? '', 30);
$nama       = clean($data['nama']     ?? '');
$telefon    = clean($data['telefon']  ?? '', 20);
$saiz       = clean($data['saiz']     ?? '', 5);
$jumlah_raw = $data['jumlah_bayaran'] ?? null;
$penghantaran = isset($data['penghantaran']) && $data['penghantaran'] === true;

$allowed_sizes   = ['S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];
$allowed_amounts = [90, 102, 115, 127];

$errors = [];
if ($order_no === '')                             $errors[] = 'order_no diperlukan.';
if ($nama     === '')                             $errors[] = 'nama diperlukan.';
if ($telefon  === '' || !preg_match('/^\+?[\d]{9,15}$/', $telefon)) $errors[] = 'telefon tidak sah.';
if (!in_array($saiz, $allowed_sizes, true))       $errors[] = 'saiz tidak sah.';
if (!is_numeric($jumlah_raw) || !in_array((int)$jumlah_raw, $allowed_amounts, true)) $errors[] = 'jumlah_bayaran tidak sah.';

// Validate order_no format: YYYYMMDD-NN
if (!preg_match('/^\d{8}-\d{2}$/', $order_no))   $errors[] = 'format order_no tidak sah.';

// Address required when penghantaran = true
$alamat = '';
$poskod = '';
$bandar = '';
$negeri = '';
if ($penghantaran) {
    $alamat = clean($data['alamat'] ?? '');
    $poskod = clean($data['poskod'] ?? '', 10);
    $bandar = clean($data['bandar'] ?? '');
    $negeri = clean($data['negeri'] ?? '');
    if ($alamat === '') $errors[] = 'alamat diperlukan untuk penghantaran.';
    if (!preg_match('/^\d{5}$/', $poskod)) $errors[] = 'poskod tidak sah.';
    if ($bandar === '') $errors[] = 'bandar diperlukan.';
    if ($negeri === '') $errors[] = 'negeri diperlukan.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ── Build order object ──
$order = [
    'order_no'       => $order_no,
    'timestamp'      => date('Y-m-d H:i:s'),
    'nama'           => $nama,
    'telefon'        => $telefon,
    'saiz'           => $saiz,
    'penghantaran'   => $penghantaran,
    'jumlah_bayaran' => (int)$jumlah_raw,
];
if ($penghantaran) {
    $order['alamat'] = $alamat;
    $order['poskod'] = $poskod;
    $order['bandar'] = $bandar;
    $order['negeri'] = $negeri;
}

// ── Email notification helper ──
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

// ── Restrict file path to same directory ──
$dir  = __DIR__;
$file = $dir . DIRECTORY_SEPARATOR . 'orders.json';

// Ensure the resolved path stays within __DIR__
if (strpos(realpath($dir) ?: $dir, realpath($dir) ?: $dir) !== 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ralat laluan fail.']);
    exit;
}

// ── Load existing orders ──
$orders = [];
if (file_exists($file)) {
    $contents = file_get_contents($file);
    if ($contents !== false && trim($contents) !== '') {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $orders = $decoded;
        }
    }
}

// ── Append and save ──
$orders[] = $order;

$json = json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal encode JSON.']);
    exit;
}

$written = file_put_contents($file, $json, LOCK_EX);
if ($written === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal simpan pesanan. Sila semak kebenaran folder.']);
    exit;
}

// ── Send email notification ──
$emailSent = sendOrderEmail($order);

// ── Success ──
echo json_encode(['success' => true, 'order_no' => $order_no, 'email_sent' => $emailSent]);
exit;
