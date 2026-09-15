<?php
declare(strict_types=1);

/**
 * Resolves one pending attendance hit. Called by the ESP32 itself, either
 * immediately on a B3 (cancel) press, or automatically the instant its local
 * 10-second countdown reaches zero (confirm) — the device decides, this
 * endpoint just records it. Idempotent: resolving an already-resolved row is
 * a no-op, not an error, so a retried/duplicate device call is harmless.
 *
 * POST JSON {"hit_id": "...", "action": "confirm"|"reject"}
 */
require __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); jsonError('Method not allowed', 405); }
$body = requestJson();
$hitId = trim((string) ($body['hit_id'] ?? ''));
$action = (string) ($body['action'] ?? '');
if ($hitId === '') jsonError('hit_id is required', 422);
if (!in_array($action, ['confirm', 'reject'], true)) jsonError('action must be confirm or reject', 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare("SELECT id, status FROM attendance_logs WHERE public_id = ? FOR UPDATE");
    $statement->execute([$hitId]);
    $log = $statement->fetch();
    if (!$log) { $pdo->rollBack(); jsonError('Attendance hit not found', 404); }

    if ($log['status'] !== 'pending') {
        $pdo->commit();
        jsonResponse(['resolved' => true, 'status' => $log['status'], 'already_resolved' => true]);
    }

    $newStatus = $action === 'confirm' ? 'confirmed' : 'rejected';
    $pdo->prepare("UPDATE attendance_logs SET status = ?, confirm_deadline = NULL WHERE id = ?")->execute([$newStatus, $log['id']]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonError('Could not resolve attendance hit', 500);
}

jsonResponse(['resolved' => true, 'status' => $newStatus]);
