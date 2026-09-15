<?php
declare(strict_types=1);

/**
 * Object log dashboard: history + manual entry (an operator picking a
 * category directly, e.g. logging today's milk delivery by hand instead of
 * via the camera). Device captures go through api/device/capture.php.
 *
 * GET    ?id=...  -> one log's full detail, any status — powers the edit
 *                    dialog for both flagged (needs_review) and already-
 *                    confirmed logs, since a log is fully CRUD-able
 *                    regardless of how it got there.
 * GET    (no id)  -> object categories, recent logs (50), today/all totals.
 * POST   -> multipart/form-data manual entry: image, category_id, quantity?
 *           (saved confirmed immediately — an operator already chose the
 *           category, no review gate needed).
 * PUT    -> {id, category_id, quantity} reassigns ANY log's category/qty
 *           (not just needs_review ones) and (re)confirms it.
 * DELETE -> ?id=... removes a log (and its stored image) — also how a
 *           wrongly auto-confirmed match gets corrected after the fact.
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && trim((string) ($_GET['id'] ?? '')) !== '') {
    $id = trim((string) $_GET['id']);
    $statement = $pdo->prepare(
        "SELECT ol.public_id AS id, ol.quantity, ol.unit_price, ol.total_price, ol.image_path, ol.status, ol.source, ol.created_at,
                c.public_id AS category_id, c.name AS category, c.color, c.unit_label
         FROM object_logs ol JOIN categories c ON c.id = ol.category_id
         WHERE ol.public_id = ?"
    );
    $statement->execute([$id]);
    $log = $statement->fetch();
    if (!$log) jsonError('Log not found', 404);
    $log['image_url'] = $log['image_path'];
    $log['quantity'] = (float) $log['quantity'];
    $log['unit_price'] = (float) $log['unit_price'];
    $log['total_price'] = (float) $log['total_price'];
    jsonResponse(['log' => $log]);
}

if ($method === 'GET') {
    sweepExpired($pdo);
    $categories = $pdo->query("SELECT public_id AS id, name, unit_label, price_per_unit, color FROM categories WHERE type = 'object' ORDER BY name")->fetchAll();
    foreach ($categories as &$category) $category['price_per_unit'] = (float) $category['price_per_unit'];

    $logs = $pdo->query(
        "SELECT ol.public_id AS id, ol.quantity, ol.unit_price, ol.total_price, ol.image_path, ol.status, ol.source, ol.created_at,
                c.public_id AS category_id, c.name AS category, c.color, c.unit_label
         FROM object_logs ol
         JOIN categories c ON c.id = ol.category_id
         ORDER BY ol.id DESC
         LIMIT 50"
    )->fetchAll();
    foreach ($logs as &$log) {
        $log['image_url'] = $log['image_path'];
        $log['quantity'] = (float) $log['quantity'];
        $log['unit_price'] = (float) $log['unit_price'];
        $log['total_price'] = (float) $log['total_price'];
    }

    $today = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(total_price),0) AS value FROM object_logs WHERE DATE(created_at) = CURDATE() AND status = 'confirmed'")->fetch();
    $all = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(total_price),0) AS value FROM object_logs WHERE status = 'confirmed'")->fetch();
    jsonResponse([
        'categories' => $categories,
        'logs' => $logs,
        'totals' => [
            'today' => ['count' => (int) $today['total'], 'value' => (float) $today['value']],
            'all' => ['count' => (int) $all['total'], 'value' => (float) $all['value']],
        ],
    ]);
}

if ($method === 'POST') {
    $categoryId = trim((string) ($_POST['category_id'] ?? ''));
    if ($categoryId === '') jsonError('category_id is required', 422);
    $quantity = $_POST['quantity'] ?? 1;
    if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 9999.99) jsonError('Enter a valid quantity', 422);
    $quantity = round((float) $quantity, 2);
    $upload = uploadedImage();

    try {
        $pdo->beginTransaction();
        $category = categoryByTypeAndPublicId($pdo, $categoryId, 'object', true);
        if (!$category) throw new InvalidArgumentException('Object category not found');
        $imagePath = storedImage($upload['path'], 'objects');
        $unitPrice = (float) $category['price_per_unit'];
        $total = round($unitPrice * $quantity, 2);
        $logId = publicId();
        $pdo->prepare('INSERT INTO object_logs (public_id, category_id, quantity, unit_price, total_price, source, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$logId, $category['database_id'], $quantity, $unitPrice, $total, 'manual', $imagePath, 'confirmed']);
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError($error->getMessage(), 422);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (isset($imagePath)) deleteStoredImage($imagePath);
        error_log($error->getMessage());
        jsonError('Object log could not be saved', 500);
    }

    jsonResponse([
        'log' => ['id' => $logId, 'category_id' => $category['id'], 'category' => $category['name'], 'unit_label' => $category['unit_label'], 'quantity' => $quantity, 'unit_price' => $unitPrice, 'total_price' => $total, 'image_url' => $imagePath, 'status' => 'confirmed'],
        'message' => $category['name'] . ' logged.',
    ], 201);
}

if ($method === 'PUT') {
    $body = requestJson();
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $categoryId = trim((string) ($body['category_id'] ?? ''));
    if ($categoryId === '') jsonError('category_id is required', 422);
    $quantity = $body['quantity'] ?? 1;
    if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 9999.99) jsonError('Enter a valid quantity', 422);
    $quantity = round((float) $quantity, 2);

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT id FROM object_logs WHERE public_id = ? FOR UPDATE');
        $statement->execute([$id]);
        $log = $statement->fetch();
        if (!$log) throw new InvalidArgumentException('Object log not found');
        $category = categoryByTypeAndPublicId($pdo, $categoryId, 'object', true);
        if (!$category) throw new InvalidArgumentException('Object category not found');

        $unitPrice = (float) $category['price_per_unit'];
        $total = round($unitPrice * $quantity, 2);
        $pdo->prepare("UPDATE object_logs SET category_id = ?, quantity = ?, unit_price = ?, total_price = ?, status = 'confirmed', confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$category['database_id'], $quantity, $unitPrice, $total, $log['id']]);
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError($error->getMessage(), 404);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage());
        jsonError('Could not update object log', 500);
    }

    jsonResponse(['updated' => true, 'category' => $category['name'], 'quantity' => $quantity, 'total_price' => $total, 'status' => 'confirmed']);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $statement = $pdo->prepare('SELECT image_path FROM object_logs WHERE public_id = ?');
    $statement->execute([$id]);
    $log = $statement->fetch();
    if (!$log) jsonError('Log not found', 404);
    $pdo->prepare('DELETE FROM object_logs WHERE public_id = ?')->execute([$id]);
    deleteStoredImage($log['image_path']);
    jsonResponse(['deleted' => true]);
}

header('Allow: GET, POST, PUT, DELETE');
jsonError('Method not allowed', 405);
