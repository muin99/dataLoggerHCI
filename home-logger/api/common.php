<?php
declare(strict_types=1);

const DEFAULT_ATTENDANCE_WINDOW_SECONDS = 10;
// This is how long an object stays 'pending' and dashboard-actionable
// before silently auto-confirming — NOT the device's on-screen wait (that's
// the separate, short OBJECT_REVIEW_WINDOW_MS in the firmware, just long
// enough to read the result and react immediately if you're standing
// there). Walking away leaves the device free immediately and the object
// pending in the background for this full window, resolvable from
// Notifications at any point before it elapses.
const OBJECT_REVIEW_WINDOW_SECONDS = 3600; // 1 hour
const RECEIPT_SAFETY_WINDOW_SECONDS = 600; // 10 min — abandoned-device safety net only

function config(): array
{
    static $config;
    if (isset($config)) return $config;
    $file = dirname(__DIR__) . '/.env';
    $values = is_readable($file) ? parse_ini_file($file, false, INI_SCANNER_RAW) : [];
    $values = is_array($values) ? $values : [];
    $get = static fn(string $key, string $default = ''): string => (string) (getenv($key) ?: ($values[$key] ?? $default));
    return $config = [
        'db_host' => $get('DB_HOST', '127.0.0.1'),
        'db_port' => $get('DB_PORT', '3306'),
        'db_name' => $get('DB_NAME', 'homelogger'),
        'db_user' => $get('DB_USER', 'root'),
        'db_pass' => $get('DB_PASS'),
        'hf_token' => $get('HF_TOKEN'),
        'hf_model' => $get('HF_MODEL', 'inclusionAI/Ling-3.0-flash-VL'),
    ];
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_mysql')) jsonError('Server requires the pdo_mysql PHP extension', 500);
    $c = config();
    try {
        $pdo = new PDO("mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    } catch (PDOException $error) {
        error_log($error->getMessage());
        jsonError('Database connection failed. Check the server configuration.', 500);
    }
    return $pdo;
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function jsonError(string $message, int $status): void
{
    jsonResponse(['error' => $message], $status);
}

function requestJson(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) jsonError('Send a valid JSON body', 400);
    return $body;
}

function publicId(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * SQL fragment for a confirm_deadline column, computed by MySQL's own clock
 * (NOW()) rather than PHP's, so PHP/DB timezone mismatches can't land a
 * deadline in the past. $seconds is a fixed internal value, never user input
 * — safe to inline directly into the SQL string.
 */
function confirmDeadlineSql(int $seconds): string
{
    return 'DATE_ADD(NOW(), INTERVAL ' . $seconds . ' SECOND)';
}

/**
 * Safety-net sweep for anything the device should have resolved itself but
 * didn't (crash, power loss, dead WiFi mid-flow). Attendance and object
 * silence both read as "it happened, no objection" -> confirmed (low-stakes,
 * routine). Receipt silence must never commit financial data -> rejected.
 * Called opportunistically at the top of every dashboard read.
 */
function sweepExpired(PDO $pdo): void
{
    $pdo->exec("UPDATE attendance_logs SET status='confirmed', confirm_deadline=NULL WHERE status='pending' AND confirm_deadline IS NOT NULL AND confirm_deadline <= NOW()");
    $pdo->exec("UPDATE object_logs SET status='confirmed', confirm_deadline=NULL, resolved_at=NOW() WHERE status='pending' AND confirm_deadline IS NOT NULL AND confirm_deadline <= NOW()");
    $pdo->exec("UPDATE receipts SET status='rejected', confirm_deadline=NULL WHERE status='pending' AND confirm_deadline IS NOT NULL AND confirm_deadline <= NOW()");
}

/**
 * Checks whether a receipt's own numbers add up: subtotal - discount + tax
 * should equal total. This catches the AI contradicting itself (e.g. it read
 * a discount but the total doesn't reflect it) — it can NOT catch the AI
 * simply failing to notice a discount/tax line was on the page at all, since
 * every field is self-consistent with the others in that case, just
 * factually incomplete. Returns true when there's no subtotal to check
 * against (nothing to flag, not confirmed clean either).
 */
function receiptMathReconciles(?float $subtotal, ?float $discount, ?float $tax, float $total): bool
{
    if ($subtotal === null) return true;
    $expected = round($subtotal - ($discount ?? 0) + ($tax ?? 0), 2);
    return abs($expected - $total) <= 1.00; // small tolerance for rounding
}

function categoryByPublicId(PDO $pdo, string $id, bool $lock = false): ?array
{
    $statement = $pdo->prepare('SELECT id AS database_id, public_id AS id, name, type, unit_label, price_per_unit, color, created_at FROM categories WHERE public_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function categoryByTypeAndPublicId(PDO $pdo, string $id, string $type, bool $lock = false): ?array
{
    $statement = $pdo->prepare('SELECT id AS database_id, public_id AS id, name, type, unit_label, price_per_unit, color FROM categories WHERE public_id = ? AND type = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id, $type]);
    return $statement->fetch() ?: null;
}

function publicCategory(array $category): array
{
    unset($category['database_id']);
    if (isset($category['price_per_unit'])) $category['price_per_unit'] = (float) $category['price_per_unit'];
    return $category;
}

/**
 * Convert an accepted image into a reasonably sized JPEG and store it under
 * storage/<subdir>/. Used for object hits, unknown hits, and receipts alike
 * — the same file doubles as the display thumbnail and, for objects, as a
 * few-shot reference example for the classifier.
 */
function storedImage(string $temporaryPath, string $subdir): string
{
    $directory = dirname(__DIR__) . '/storage/' . $subdir;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) jsonError('Could not create image storage', 500);
    $name = publicId() . '.jpg';
    $destination = $directory . '/' . $name;
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $source = @imagecreatefromstring((string) file_get_contents($temporaryPath));
        if ($source !== false) {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, 900 / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $background = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $background);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagejpeg($target, $destination, 84);
            imagedestroy($target);
            imagedestroy($source);
            return 'storage/' . $subdir . '/' . $name;
        }
    }
    if (!copy($temporaryPath, $destination)) jsonError('Could not retain the image', 500);
    return 'storage/' . $subdir . '/' . $name;
}

function deleteStoredImage(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with($relativePath, 'storage/')) return;
    $path = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($path)) @unlink($path);
}

function imageDataUrl(string $relativePath): ?string
{
    $path = dirname(__DIR__) . '/' . $relativePath;
    if (!is_file($path) || filesize($path) > 1024 * 1024) return null;
    $data = file_get_contents($path);
    return $data === false ? null : 'data:image/jpeg;base64,' . base64_encode($data);
}

/**
 * @return array{path:string,mime:string,size:int}
 */
function uploadedImage(string $field = 'image'): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) jsonError('Upload an image using the "' . $field . '" field', 400);
    $upload = $_FILES[$field];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jsonError(uploadMessage((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)), 400);
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > 8 * 1024 * 1024) jsonError('Image must be between 1 byte and 8 MB', 413);
    $path = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($path)) jsonError('Invalid upload', 400);
    if (!class_exists('finfo')) jsonError('Server requires the fileinfo PHP extension', 500);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) jsonError('Only JPEG, PNG, WebP, and GIF images are supported', 415);
    return ['path' => $path, 'mime' => (string) $mime, 'size' => $size];
}

function uploadMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image is too large',
        UPLOAD_ERR_PARTIAL => 'Image upload was interrupted',
        UPLOAD_ERR_NO_FILE => 'No image was uploaded',
        default => 'Image upload failed',
    };
}
