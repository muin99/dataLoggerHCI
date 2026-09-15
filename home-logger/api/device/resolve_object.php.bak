<?php
declare(strict_types=1);

/**
 * Resolves one pending object match from the device's on-screen B3/B4/B5
 * choice — mirrors resolve_receipt.php, but the review window is short (5s,
 * see OBJECT_REVIEW_WINDOW_SECONDS) and auto-confirms on silence via
 * sweepExpired(), since objects are frequent/low-stakes unlike receipts.
 *
 * POST JSON {"log_id": "...", "action": "confirm"|"reject"|"flag_modify"}
 *  - confirm     (B4) -> status='confirmed'.
 *  - reject      (B3) -> status='rejected', stored image removed.
 *  - flag_modify (B5) -> status='needs_review' — surfaces in the dashboard
 *                        Notifications list for correcting the category/qty.
 * Idempotent: resolving an already-resolved log is a no-op.
 */
require __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); jsonError('Method not allowed', 405); }
$body = requestJson();
$logId = trim((string) ($body['log_id'] ?? ''));
$action = (string) ($body['action'] ?? '');
if ($logId === '') jsonError('log_id is required', 422);
if (!in_array($action, ['confirm', 'reject', 'flag_modify'], true)) jsonError('action must be confirm, reject, or flag_modify', 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT id, status, image_path FROM object_logs WHERE public_id = ? FOR UPDATE');
    $statement->execute([$logId]);
    $log = $statement->fetch();
    if (!$log) { $pdo->rollBack(); jsonError('Object log not found', 404); }

    if ($log['status'] !== 'pending') {
        $pdo->commit();
        jsonResponse(['resolved' => true, 'status' => $log['status'], 'already_resolved' => true]);
    }

    $newStatus = match ($action) {
        'confirm' => 'confirmed',
        'reject' => 'rejected',
        'flag_modify' => 'needs_review',
    };
    $pdo->prepare('UPDATE object_logs SET status = ?, confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$newStatus, $log['id']]);
    $pdo->commit();

    if ($action === 'reject') deleteStoredImage($log['image_path']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonError('Could not resolve object log', 500);
}

jsonResponse(['resolved' => true, 'status' => $newStatus]);
