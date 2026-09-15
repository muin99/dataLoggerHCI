<?php
declare(strict_types=1);

/**
 * Resolves one pending receipt from the device's on-screen B3/B4/B5 choice.
 *
 * POST JSON {"receipt_id": "...", "action": "confirm"|"reject"|"flag_modify"}
 *  - confirm     (B4) -> status='confirmed' — UNLESS the receipt's own
 *                        numbers don't add up (subtotal - discount + tax !=
 *                        total), in which case it's downgraded to
 *                        'needs_review' instead. The 16x2 LCD has no way to
 *                        show that discrepancy before the user presses
 *                        confirm, so this is the safety net: a human checks
 *                        it on the dashboard rather than a bad total quietly
 *                        landing as "confirmed".
 *  - reject      (B3) -> status='rejected', stored image removed.
 *  - flag_modify (B5) -> status='needs_review' — this is what surfaces in
 *                        the dashboard Notifications list for editing.
 * Idempotent: resolving an already-resolved receipt is a no-op.
 */
require __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); jsonError('Method not allowed', 405); }
$body = requestJson();
$receiptId = trim((string) ($body['receipt_id'] ?? ''));
$action = (string) ($body['action'] ?? '');
if ($receiptId === '') jsonError('receipt_id is required', 422);
if (!in_array($action, ['confirm', 'reject', 'flag_modify'], true)) jsonError('action must be confirm, reject, or flag_modify', 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT id, status, image_path, subtotal, discount, tax, total FROM receipts WHERE public_id = ? FOR UPDATE');
    $statement->execute([$receiptId]);
    $receipt = $statement->fetch();
    if (!$receipt) { $pdo->rollBack(); jsonError('Receipt not found', 404); }

    if ($receipt['status'] !== 'pending') {
        $pdo->commit();
        jsonResponse(['resolved' => true, 'status' => $receipt['status'], 'already_resolved' => true]);
    }

    $downgraded = false;
    if ($action === 'confirm') {
        $subtotal = $receipt['subtotal'] !== null ? (float) $receipt['subtotal'] : null;
        $discount = $receipt['discount'] !== null ? (float) $receipt['discount'] : null;
        $tax = $receipt['tax'] !== null ? (float) $receipt['tax'] : null;
        if (!receiptMathReconciles($subtotal, $discount, $tax, (float) $receipt['total'])) {
            $downgraded = true;
            error_log("resolve_receipt: confirm downgraded to needs_review for $receiptId — math mismatch");
        }
    }

    $newStatus = match (true) {
        $action === 'confirm' && $downgraded => 'needs_review',
        $action === 'confirm' => 'confirmed',
        $action === 'reject' => 'rejected',
        default => 'needs_review', // flag_modify
    };
    $pdo->prepare('UPDATE receipts SET status = ?, confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$newStatus, $receipt['id']]);
    $pdo->commit();

    if ($action === 'reject') deleteStoredImage($receipt['image_path']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonError('Could not resolve receipt', 500);
}

jsonResponse(['resolved' => true, 'status' => $newStatus, 'downgraded' => $downgraded]);
