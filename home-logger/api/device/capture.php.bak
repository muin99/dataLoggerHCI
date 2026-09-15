<?php
declare(strict_types=1);

/**
 * Camera capture endpoint. ESP32 forwards the phone-relayed image here after
 * B2 arms scanning mode. Classifies the image and branches three ways,
 * returning one JSON payload with everything the device's LCD needs:
 *
 *  - matched object  -> saved pending with a short (5s) review window;
 *                       device drives its own B3/B4/B5, auto-confirms on
 *                       silence since objects are frequent/low-stakes.
 *  - RECEIPT          -> read into structured items/total, saved pending,
 *                        device then drives its own B3/B4/B5 review (no
 *                        auto-confirm — financial data always waits for a
 *                        button, only ever auto-CANCELS if abandoned).
 *  - UNKNOWN          -> nothing logged yet; stored for dashboard triage.
 *
 * POST multipart/form-data, field "image".
 */
require __DIR__ . '/../common.php';
require __DIR__ . '/../ai.php';

// The AI call (ai.php) can legitimately take up to ~90s on a real photo,
// and now retries transient failures up to 3 times with backoff — worst
// case (3 slow failures) is ~280s. Shared hosts often default
// max_execution_time to 30s, which would kill this script mid-request and
// surface as a bare 502 with no error body — override it here so the
// retries actually get to run. Must stay in sync with the firmware's
// apiTimeoutMs (home_datalogger.ino), which needs to wait at least this long.
set_time_limit(290);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); jsonError('Method not allowed', 405); }

$upload = uploadedImage();
$pdo = db();

$objectCategories = $pdo->query("SELECT id, public_id, name, unit_label, price_per_unit, color FROM categories WHERE type='object' ORDER BY name")->fetchAll();
if (count($objectCategories) < 1) jsonError('Create at least one object category before sending camera captures', 422);

$trainingExamples = $pdo->query(
    'SELECT t.id, t.image_path, c.public_id AS category_public_id, c.name AS category_name
     FROM training_examples t JOIN categories c ON c.id = t.category_id
     WHERE t.active = 1 ORDER BY t.is_correction DESC, t.times_used ASC, t.created_at DESC LIMIT 8'
)->fetchAll();

try {
    $classification = classifyCapture($upload['path'], $upload['mime'], $objectCategories, $trainingExamples);
} catch (AiPipelineException $error) {
    // A hazy photo, an ambiguous multi-object scene, or a transient hiccup on
    // the AI provider's side (occasionally a literal resource error from
    // their infrastructure, not this server or the device) should read the
    // same as classifyCapture()'s own internal "model gave no clear answer"
    // case: not recognized, not a hard device-facing error. The raw
    // exception message is logged for diagnosis but never shown to the
    // device — it was never something the user could act on anyway.
    error_log('classifyCapture: ' . $error->getMessage());
    $classification = ['type' => 'unknown'];
}

if ($classification['type'] === 'object') {
    handleObjectMatch($pdo, $upload, $classification['category'], $trainingExamples);
}
if ($classification['type'] === 'receipt') {
    handleReceipt($pdo, $upload);
}
handleUnknown($pdo, $upload);

function handleObjectMatch(PDO $pdo, array $upload, array $category, array $trainingExamples): void
{
    $imagePath = storedImage($upload['path'], 'objects');
    $logId = publicId();
    try {
        $pdo->beginTransaction();
        $fresh = categoryByPublicId($pdo, $category['public_id'], true);
        if (!$fresh) throw new RuntimeException('Matched category was removed');
        $unitPrice = (float) $fresh['price_per_unit'];
        $quantity = 1.00;
        $total = round($unitPrice * $quantity, 2);
        $statement = $pdo->prepare('INSERT INTO object_logs (public_id, category_id, quantity, unit_price, total_price, source, image_path, status, confirm_deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . confirmDeadlineSql(OBJECT_REVIEW_WINDOW_SECONDS) . ')');
        $statement->execute([$logId, $fresh['database_id'], $quantity, $unitPrice, $total, 'device', $imagePath, 'pending']);
        $dbLogId = (int) $pdo->lastInsertId();
        if ($trainingExamples) {
            $ids = array_column($trainingExamples, 'id');
            $pdo->exec('UPDATE training_examples SET times_used = times_used + 1 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        deleteStoredImage($imagePath);
        error_log($error->getMessage());
        jsonError('Match found but the log could not be saved', 500);
    }

    jsonResponse([
        'type' => 'object',
        'log_id' => $logId,
        'name' => $fresh['name'],
        'unit_label' => $fresh['unit_label'],
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total' => $total,
        'seconds_remaining' => OBJECT_REVIEW_WINDOW_SECONDS,
        'message' => $fresh['name'] . ' matched — confirm within ' . OBJECT_REVIEW_WINDOW_SECONDS . 's or it auto-confirms.',
    ], 201);
}

function handleReceipt(PDO $pdo, array $upload): void
{
    try {
        $extracted = extractReceipt($upload['path'], $upload['mime']);
    } catch (AiPipelineException $error) {
        error_log('extractReceipt: ' . $error->getMessage());
        jsonError('Could not read this receipt: ' . $error->getMessage(), 502);
    }

    $imagePath = storedImage($upload['path'], 'receipts');
    $receiptId = publicId();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('INSERT INTO receipts (public_id, image_path, merchant_name, subtotal, discount, tax, total, raw_model_json, status, confirm_deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . confirmDeadlineSql(RECEIPT_SAFETY_WINDOW_SECONDS) . ')');
        $statement->execute([$receiptId, $imagePath, $extracted['merchant'], $extracted['subtotal'], $extracted['discount'], $extracted['tax'], $extracted['total'], json_encode($extracted, JSON_UNESCAPED_SLASHES), 'pending']);
        $receiptDbId = (int) $pdo->lastInsertId();

        $itemStatement = $pdo->prepare('INSERT INTO receipt_items (receipt_id, name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
        foreach ($extracted['items'] as $item) {
            $itemStatement->execute([$receiptDbId, $item['name'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        deleteStoredImage($imagePath);
        error_log($error->getMessage());
        jsonError('Receipt read but could not be saved', 500);
    }

    jsonResponse([
        'type' => 'receipt',
        'receipt_id' => $receiptId,
        'merchant' => $extracted['merchant'],
        'items' => $extracted['items'],
        'subtotal' => $extracted['subtotal'],
        'discount' => $extracted['discount'],
        'tax' => $extracted['tax'],
        'total' => $extracted['total'],
        'message' => 'Receipt read: ' . count($extracted['items']) . ' item(s), total ' . $extracted['total'] . '.',
    ], 201);
}

function handleUnknown(PDO $pdo, array $upload): void
{
    $imagePath = storedImage($upload['path'], 'unknown');
    $hitId = publicId();
    try {
        $pdo->prepare('INSERT INTO unknown_object_hits (public_id, image_path, status) VALUES (?, ?, ?)')->execute([$hitId, $imagePath, 'pending']);
    } catch (Throwable $error) {
        deleteStoredImage($imagePath);
        error_log($error->getMessage());
        jsonError('Could not save the unrecognized capture', 500);
    }

    jsonResponse([
        'type' => 'unknown',
        'hit_id' => $hitId,
        'message' => 'Not recognized. Check the app to add it.',
    ], 201);
}
