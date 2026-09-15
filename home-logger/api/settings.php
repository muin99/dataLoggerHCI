<?php
declare(strict_types=1);

/**
 * Simple key-value app configuration.
 * GET  -> all settings as an object.
 * POST -> {confirm_window_seconds?, sound_notifications?} merged in.
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    $settings = [];
    foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
    jsonResponse([
        'settings' => [
            'confirm_window_seconds' => (int) ($settings['confirm_window_seconds'] ?? DEFAULT_ATTENDANCE_WINDOW_SECONDS),
            'sound_notifications' => (bool) (int) ($settings['sound_notifications'] ?? 1),
        ],
    ]);
}

if ($method !== 'POST') { header('Allow: GET, POST'); jsonError('Method not allowed', 405); }

$body = requestJson();
$updates = [];
if (isset($body['confirm_window_seconds'])) {
    $seconds = (int) $body['confirm_window_seconds'];
    if ($seconds < 3 || $seconds > 120) jsonError('confirm_window_seconds must be between 3 and 120', 422);
    $updates['confirm_window_seconds'] = (string) $seconds;
}
if (isset($body['sound_notifications'])) {
    $updates['sound_notifications'] = $body['sound_notifications'] ? '1' : '0';
}
if (!$updates) jsonError('Nothing to update', 422);

$statement = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
foreach ($updates as $key => $value) $statement->execute([$key, $value]);

jsonResponse(['saved' => true, 'updated' => array_keys($updates)]);
