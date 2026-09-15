<?php
declare(strict_types=1);

/**
 * RFID feed endpoint. ESP32 posts the tag UID on every reader hit.
 *
 * POST JSON {"uid": "04A3B2C1"}
 *  - Unknown tag: logged to unknown_rfid_hits (assignable from the
 *    dashboard) and a 404 is returned; the device shows "Unregistered card".
 *  - Known tag: the next action (check_in/check_out) is inferred from the
 *    worker's last log today, saved as status=pending with a 10-second
 *    confirm window, and returned so the device can drive its own countdown.
 *  - A repeat hit of the same tag within 5 seconds is treated as reader
 *    bounce and returns the existing pending row instead of a duplicate.
 */
require __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); jsonError('Method not allowed', 405); }
$body = requestJson();
$uid = strtoupper(trim((string) ($body['uid'] ?? '')));
if ($uid === '' || strlen($uid) > 64) jsonError('uid is required and must be 64 characters or fewer', 422);

$pdo = db();
$statement = $pdo->prepare('SELECT w.id AS database_id, w.public_id AS id, w.name, c.public_id AS role_id, COALESCE(c.name, "Household worker") AS role FROM workers w LEFT JOIN categories c ON c.id = w.category_id WHERE w.rfid_uid = ? AND w.active = 1');
$statement->execute([$uid]);
$worker = $statement->fetch();

if (!$worker) {
    $pdo->prepare('INSERT INTO unknown_rfid_hits (uid) VALUES (?) ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = CURRENT_TIMESTAMP, dismissed = 0')->execute([$uid]);
    jsonResponse(['matched' => false, 'uid' => $uid, 'message' => 'Unregistered card. Assign it from the dashboard.'], 404);
}

try {
    $pdo->beginTransaction();
    // Debounce: a duplicate hit within 5s is reader bounce, not a new tap.
    // Compared using MySQL's own clock (NOW()), not PHP's.
    $recent = $pdo->prepare("SELECT public_id AS id, event_type, status FROM attendance_logs WHERE worker_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND) ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $recent->execute([$worker['database_id']]);
    $last = $recent->fetch();
    if ($last) {
        $pdo->commit();
        jsonResponse([
            'matched' => true, 'duplicate' => true,
            'attendance' => ['hit_id' => $last['id'], 'worker' => $worker['name'], 'role' => $worker['role'], 'event_type' => $last['event_type'], 'status' => $last['status']],
            'message' => 'Already logged — ignoring rapid repeat tap.',
        ]);
    }

    $todayLast = $pdo->prepare("SELECT event_type FROM attendance_logs WHERE worker_id = ? AND DATE(occurred_at) = CURDATE() AND status != 'rejected' ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE");
    $todayLast->execute([$worker['database_id']]);
    $todayLastRow = $todayLast->fetch();
    $nextAction = ($todayLastRow && $todayLastRow['event_type'] === 'check_in') ? 'check_out' : 'check_in';

    $hitId = publicId();
    $insert = $pdo->prepare('INSERT INTO attendance_logs (public_id, worker_id, event_type, occurred_at, source, status, confirm_deadline) VALUES (?, ?, ?, NOW(), ?, ?, ' . confirmDeadlineSql(DEFAULT_ATTENDANCE_WINDOW_SECONDS) . ')');
    $insert->execute([$hitId, $worker['database_id'], $nextAction, 'device', 'pending']);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonError('Attendance hit could not be saved', 500);
}

jsonResponse([
    'matched' => true,
    'attendance' => ['hit_id' => $hitId, 'worker' => $worker['name'], 'role' => $worker['role'], 'event_type' => $nextAction, 'status' => 'pending'],
    'seconds_remaining' => DEFAULT_ATTENDANCE_WINDOW_SECONDS,
    'message' => $worker['name'] . ' — ' . str_replace('_', ' ', $nextAction) . ' pending.',
], 201);
