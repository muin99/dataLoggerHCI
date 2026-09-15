<?php
declare(strict_types=1);

header('Content-Type: application/json');

const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const ALLOWED_MIME_TO_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Only POST requests are allowed.');
}

if (!isset($_FILES['image'])) {
    fail(400, 'No file uploaded. Send the file under the "image" field.');
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'Upload error code: ' . $file['error']);
}

if ($file['size'] <= 0 || $file['size'] > MAX_BYTES) {
    fail(400, 'File is empty or exceeds the 10MB limit.');
}

if (!is_uploaded_file($file['tmp_name'])) {
    fail(400, 'Invalid upload.');
}

// Verify the file is actually an image and detect its real type (don't trust client-supplied name/type)
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    fail(400, 'Uploaded file is not a valid image.');
}

$mime = $imageInfo['mime'];
if (!isset(ALLOWED_MIME_TO_EXT[$mime])) {
    fail(400, 'Unsupported image type. Allowed: JPEG, PNG, GIF, WEBP.');
}

$extension = ALLOWED_MIME_TO_EXT[$mime];
$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$destination = __DIR__ . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    fail(500, 'Failed to save the uploaded file.');
}

http_response_code(200);
echo json_encode([
    'success'  => true,
    'message'  => 'Image uploaded successfully.',
    'filename' => $filename,
]);
