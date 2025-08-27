<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'unauthorized']);
    exit;
}

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($productId <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'bad params']);
    exit;
}

/**
 * Returns the first image filename from a JSON array or a delimited string.
 * Supports separators: ',', ';', '|'. Also accepts URLs/paths but returns only basename.
 */
function firstImageFromField(?string $s): ?string {
    if ($s === null) return null;

    // Trim BOM and whitespace
    $s = trim(preg_replace('/^\xEF\xBB\xBF/', '', $s));
    if ($s === '') return null;

    // JSON array? (lenient)
    if ($s[0] === '[') {
        $arr = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($arr) && !empty($arr)) {
            return sanitizeImageName((string)$arr[0]);
        }
        // fall through if malformed
    }

    // Normalize separators -> comma
    $norm = str_replace(['|',';'], ',', $s);
    // Remove extra spaces around commas
    $norm = preg_replace('/\s*,\s*/', ',', $norm);
    $norm = trim($norm, ',');
    $first = strpos($norm, ',') !== false ? substr($norm, 0, strpos($norm, ',')) : $norm;

    return sanitizeImageName($first);
}
function sanitizeImageName(string $value): ?string {
    $value = trim($value, " \t\n\r\0\x0B\"'");

    if ($value === '') return null;

    // If URL, extract path component
    if (preg_match('#^https?://#i', $value)) {
        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $value = $path;
        }
    }

    // Ignore data URIs (no filename available)
    if (preg_match('#^data:image/[^;]+;base64,#i', $value)) {
        return null;
    }

    // Normalize slashes and strip directories
    $value = str_replace('\\', '/', $value);
    $base  = basename($value);

    // Keep only safe chars
    $base = preg_replace('/[^A-Za-z0-9 ._\-]/u', '', $base);

    return $base !== '' ? $base : null;
}

try {
    $pdo = new PDO(
        "mysql:host=sczfile.online;dbname=secondhand_web;charset=utf8mb4",
        "mix", "mix1234",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $st = $pdo->prepare("
        SELECT product_id, product_name, product_price, product_image
        FROM products
        WHERE product_id = ?
        LIMIT 1
    ");
    $st->execute([$productId]);
    $p = $st->fetch();

    if (!$p) {
        echo json_encode(['ok'=>false, 'error'=>'product not found']);
        exit;
    }

    // Compute first image safely
    $firstImg = firstImageFromField($p['product_image'] ?? null);
    // Adjust relative path to your structure (this file is inside ChatApp/ so go up one level)
    $imageUrl = $firstImg ? "../uploads/" . $firstImg : null;

    echo json_encode([
        'ok'         => true,
        'product_id' => (int)$p['product_id'],
        'name'       => (string)$p['product_name'],
        'price'      => (float)$p['product_price'],
        'image'      => $imageUrl,           // null if not available
    ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'db error: '.$e->getMessage()]);
}
