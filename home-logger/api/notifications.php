<?php
declare(strict_types=1);

/**
 * Unified "needs a human" list — the one thing the device genuinely cannot
 * resolve on its own: unrecognized object captures, unregistered RFID tags,
 * object logs still 'pending' (waiting out their ~1hr auto-confirm window,
 * see OBJECT_REVIEW_WINDOW_SECONDS) or flagged via B5 "modify", and receipts
 * flagged via B5. Everything else (attendance, matched receipts) is
 * resolved on-device and never lands here.
 *
 * GET  -> pending unknown objects, pending unknown tags, object logs that
 *         are 'pending' or 'needs_review', needs_review receipts, plus
 *         object categories and workers to populate assignment pickers.
 * POST -> resolve one:
 *   {kind:'unknown_object', id, action:'assign'|'dismiss', category_id?}
 *   {kind:'unknown_tag',    id, action:'assign'|'dismiss', worker_id?}   (id = uid)
 *   {kind:'object',         id, action:'acknowledge'|'reject'}           (accept as logged, or discard; reassigning category/qty uses PUT api/objects.php)
 *   {kind:'receipt',        id, action:'acknowledge'|'reject'}           (accept as extracted, or discard; modifying items uses PUT api/receipts.php)
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    sweepExpired($pdo);

    $unknownObjects = $pdo->query("SELECT public_id AS id, image_path, created_at FROM unknown_object_hits WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll();
    foreach ($unknownObjects as &$row) $row['image_url'] = $row['image_path'];

    $unknownTags = $pdo->query("SELECT uid AS id, hit_count, last_seen_at FROM unknown_rfid_hits WHERE dismissed = 0 ORDER BY last_seen_at DESC LIMIT 20")->fetchAll();
    foreach ($unknownTags as &$row) $row['hit_count'] = (int) $row['hit_count'];

    $needsReviewObjects = $pdo->query(
        "SELECT ol.public_id AS id, ol.image_path, ol.quantity, ol.total_price, ol.status, ol.created_at,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), ol.confirm_deadline)) AS seconds_remaining, c.name AS category
         FROM object_logs ol JOIN categories c ON c.id = ol.category_id
         WHERE ol.status IN ('pending', 'needs_review') ORDER BY ol.created_at ASC"
    )->fetchAll();
    foreach ($needsReviewObjects as &$row) {
        $row['image_url'] = $row['image_path'];
        $row['quantity'] = (float) $row['quantity'];
        $row['total_price'] = (float) $row['total_price'];
        $row['seconds_remaining'] = (int) $row['seconds_remaining'];
    }

    $needsReviewReceipts = $pdo->query("SELECT public_id AS id, image_path, merchant_name, total, created_at FROM receipts WHERE status = 'needs_review' ORDER BY created_at ASC")->fetchAll();
    foreach ($needsReviewReceipts as &$row) { $row['image_url'] = $row['image_path']; $row['total'] = (float) $row['total']; }

    $objectCategories = $pdo->query("SELECT public_id AS id, name FROM categories WHERE type = 'object' ORDER BY name")->fetchAll();
    $workers = $pdo->query("SELECT public_id AS id, name FROM workers WHERE active = 1 ORDER BY name")->fetchAll();

    jsonResponse([
        'unknown_objects' => $unknownObjects,
        'unknown_tags' => $unknownTags,
        'needs_review_objects' => $needsReviewObjects,
        'needs_review_receipts' => $needsReviewReceipts,
        'object_categories' => $objectCategories,
        'workers' => $workers,
        'total_pending' => count($unknownObjects) + count($unknownTags) + count($needsReviewObjects) + count($needsReviewReceipts),
    ]);
}

if ($method !== 'POST') { header('Allow: GET, POST'); jsonError('Method not allowed', 405); }

$body = requestJson();
$kind = (string) ($body['kind'] ?? '');
$id = (string) ($body['id'] ?? '');
$action = (string) ($body['action'] ?? '');
if ($id === '') jsonError('id is required', 422);

if ($kind === 'unknown_object') resolveUnknownObject($pdo, $id, $action, $body);
if ($kind === 'unknown_tag') resolveUnknownTag($pdo, $id, $action, $body);
if ($kind === 'object') acknowledgeObject($pdo, $id, $action);
if ($kind === 'receipt') acknowledgeReceipt($pdo, $id, $action);
jsonError('kind must be unknown_object, unknown_tag, object, or receipt', 422);

function resolveUnknownObject(PDO $pdo, string $id, string $action, array $body): void
{
    if (!in_array($action, ['assign', 'dismiss'], true)) jsonError('action must be assign or dismiss', 422);
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare("SELECT id, image_path FROM unknown_object_hits WHERE public_id = ? AND status = 'pending' FOR UPDATE");
        $statement->execute([$id]);
        $hit = $statement->fetch();
        if (!$hit) { $pdo->rollBack(); jsonError('Unresolved capture not found', 404); }

        if ($action === 'dismiss') {
            $pdo->prepare("UPDATE unknown_object_hits SET status = 'dismissed', resolved_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$hit['id']]);
            $pdo->commit();
            jsonResponse(['resolved' => true, 'status' => 'dismissed']);
        }

        $categoryId = trim((string) ($body['category_id'] ?? ''));
        if ($categoryId === '') { $pdo->rollBack(); jsonError('category_id is required to assign', 422); }
        $category = categoryByTypeAndPublicId($pdo, $categoryId, 'object', true);
        if (!$category) { $pdo->rollBack(); jsonError('Object category not found', 404); }

        $logId = publicId();
        $unitPrice = (float) $category['price_per_unit'];
        $pdo->prepare('INSERT INTO object_logs (public_id, category_id, quantity, unit_price, total_price, source, image_path, status) VALUES (?, ?, 1, ?, ?, ?, ?, ?)')
            ->execute([$logId, $category['database_id'], $unitPrice, $unitPrice, 'dashboard_review', $hit['image_path'], 'confirmed']);
        $pdo->prepare("UPDATE unknown_object_hits SET status = 'resolved', resolved_category_id = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$category['database_id'], $hit['id']]);
        // Keep this correction as a few-shot training example so the same object is recognized next time.
        $pdo->prepare('INSERT INTO training_examples (public_id, category_id, log_id, image_path, is_correction) VALUES (?, ?, ?, ?, 1)')
            ->execute([publicId(), $category['database_id'], null, $hit['image_path']]);
        $pdo->commit();
        jsonResponse(['resolved' => true, 'status' => 'resolved', 'category' => $category['name'], 'log_id' => $logId]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage());
        jsonError('Could not resolve unknown object', 500);
    }
}

function resolveUnknownTag(PDO $pdo, string $uid, string $action, array $body): void
{
    if (!in_array($action, ['assign', 'dismiss'], true)) jsonError('action must be assign or dismiss', 422);
    $uid = strtoupper($uid);

    if ($action === 'dismiss') {
        $statement = $pdo->prepare('UPDATE unknown_rfid_hits SET dismissed = 1 WHERE uid = ?');
        $statement->execute([$uid]);
        if (!$statement->rowCount()) jsonError('Unknown tag not found', 404);
        jsonResponse(['resolved' => true, 'status' => 'dismissed']);
    }

    $workerId = trim((string) ($body['worker_id'] ?? ''));
    if ($workerId === '') jsonError('worker_id is required to assign', 422);
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('UPDATE workers SET rfid_uid = ? WHERE public_id = ? AND active = 1');
        $statement->execute([$uid, $workerId]);
        if (!$statement->rowCount()) { $pdo->rollBack(); jsonError('Active worker not found', 404); }
        $pdo->prepare('UPDATE unknown_rfid_hits SET dismissed = 1 WHERE uid = ?')->execute([$uid]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int) $error->getCode() === 23000) jsonError('This tag is already assigned to another worker', 409);
        error_log($error->getMessage());
        jsonError('Could not assign tag', 500);
    }
    jsonResponse(['resolved' => true, 'status' => 'assigned']);
}

// Mirrors the device's B4 (accept as-is) / B3 (reject) choice — usable on
// an object log that's either still 'pending' (waiting out its ~1hr
// auto-confirm window) or explicitly 'needs_review' (flagged via B5); both
// are "awaiting a decision" states from the dashboard's point of view.
// "Modify" is the third option (B5, or editing from here) — that's PUT
// api/objects.php, which reassigns the category/quantity and confirms in
// one step.
function acknowledgeObject(PDO $pdo, string $id, string $action): void
{
    if (!in_array($action, ['acknowledge', 'reject'], true)) {
        jsonError('action must be acknowledge or reject (use PUT api/objects.php to modify)', 422);
    }
    if ($action === 'reject') {
        $statement = $pdo->prepare("SELECT image_path FROM object_logs WHERE public_id = ? AND status IN ('pending', 'needs_review')");
        $statement->execute([$id]);
        $log = $statement->fetch();
        if (!$log) jsonError('Object log not found or already resolved', 404);
        $pdo->prepare("UPDATE object_logs SET status = 'rejected', confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE public_id = ?")->execute([$id]);
        deleteStoredImage($log['image_path']);
        jsonResponse(['resolved' => true, 'status' => 'rejected']);
    }
    $statement = $pdo->prepare("UPDATE object_logs SET status = 'confirmed', confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE public_id = ? AND status IN ('pending', 'needs_review')");
    $statement->execute([$id]);
    if (!$statement->rowCount()) jsonError('Object log not found or already resolved', 404);
    jsonResponse(['resolved' => true, 'status' => 'confirmed']);
}

// Mirrors the device's B4 (accept as-is) / B3 (reject) choice for a
// flagged receipt. "Modify" is the third option (B5) — that's PUT
// api/receipts.php, which edits the items and confirms in one step.
function acknowledgeReceipt(PDO $pdo, string $id, string $action): void
{
    if (!in_array($action, ['acknowledge', 'reject'], true)) {
        jsonError('action must be acknowledge or reject (use PUT api/receipts.php to modify items)', 422);
    }
    if ($action === 'reject') {
        $statement = $pdo->prepare("SELECT image_path FROM receipts WHERE public_id = ? AND status = 'needs_review'");
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt) jsonError('Receipt not found or not awaiting review', 404);
        $pdo->prepare("UPDATE receipts SET status = 'rejected', resolved_at = CURRENT_TIMESTAMP WHERE public_id = ?")->execute([$id]);
        deleteStoredImage($receipt['image_path']);
        jsonResponse(['resolved' => true, 'status' => 'rejected']);
    }
    $statement = $pdo->prepare("UPDATE receipts SET status = 'confirmed', resolved_at = CURRENT_TIMESTAMP WHERE public_id = ? AND status = 'needs_review'");
    $statement->execute([$id]);
    if (!$statement->rowCount()) jsonError('Receipt not found or not awaiting review', 404);
    jsonResponse(['resolved' => true, 'status' => 'confirmed']);
}
