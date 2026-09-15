<?php
declare(strict_types=1);

/**
 * Overview stats for the dashboard's landing page.
 */
require __DIR__ . '/common.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); jsonError('Method not allowed', 405); }
sweepExpired($pdo);

$onSite = $pdo->query(
    "SELECT COUNT(*) AS total FROM workers w WHERE w.active = 1 AND (
        SELECT l.event_type FROM attendance_logs l WHERE l.worker_id = w.id AND l.status != 'rejected' ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1
     ) = 'check_in'"
)->fetch();

$objectsToday = $pdo->query("SELECT COUNT(*) AS count, COALESCE(SUM(total_price),0) AS value FROM object_logs WHERE DATE(created_at) = CURDATE() AND status = 'confirmed'")->fetch();
$objectsMonth = $pdo->query("SELECT COALESCE(SUM(total_price),0) AS value FROM object_logs WHERE status = 'confirmed' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())")->fetch();
$receiptsMonth = $pdo->query("SELECT COALESCE(SUM(total),0) AS value FROM receipts WHERE status IN ('confirmed','needs_review') AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())")->fetch();

$pendingNotifications =
    (int) $pdo->query("SELECT COUNT(*) AS c FROM unknown_object_hits WHERE status = 'pending'")->fetch()['c']
    + (int) $pdo->query("SELECT COUNT(*) AS c FROM unknown_rfid_hits WHERE dismissed = 0")->fetch()['c']
    + (int) $pdo->query("SELECT COUNT(*) AS c FROM receipts WHERE status = 'needs_review'")->fetch()['c'];

$attendanceToday = $pdo->query("SELECT
    SUM(CASE WHEN event_type = 'check_in' THEN 1 ELSE 0 END) AS checked_in,
    SUM(CASE WHEN event_type = 'check_out' THEN 1 ELSE 0 END) AS checked_out
    FROM attendance_logs WHERE DATE(occurred_at) = CURDATE() AND status != 'rejected'")->fetch();

jsonResponse([
    'workers_on_site' => (int) $onSite['total'],
    'attendance_today' => ['checked_in' => (int) ($attendanceToday['checked_in'] ?? 0), 'checked_out' => (int) ($attendanceToday['checked_out'] ?? 0)],
    'objects_today' => ['count' => (int) $objectsToday['count'], 'value' => (float) $objectsToday['value']],
    'spend_this_month' => round((float) $objectsMonth['value'] + (float) $receiptsMonth['value'], 2),
    'pending_notifications' => $pendingNotifications,
]);
