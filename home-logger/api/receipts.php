<?php
declare(strict_types=1);

/**
 * Receipt dashboard: history, single-receipt detail with line items, and
 * editing. Editing is how a `needs_review` receipt (flagged via the
 * device's B5 "modify") actually gets corrected — the one genuinely
 * unavoidable "modify through the app" flow, since there's no keyboard on
 * the device itself.
 *
 * GET    ?id=...         -> one receipt with items.
 * GET    (no id)         -> recent receipts, optionally ?status=.
 * PUT    {id, merchant?, items:[{name,quantity,unit_price,line_total}], discount?, tax?}
 *        -> replaces the item list, recomputes subtotal/total from
 *           items minus discount plus tax, marks confirmed.
 * DELETE ?id=...          -> removes the receipt and its stored image —
 *        works regardless of status, including already-confirmed receipts.
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    sweepExpired($pdo);
    $id = trim((string) ($_GET['id'] ?? ''));

    if ($id !== '') {
        $statement = $pdo->prepare('SELECT id AS database_id, public_id AS id, image_path, merchant_name, subtotal, discount, tax, total, status, created_at, resolved_at FROM receipts WHERE public_id = ?');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt) jsonError('Receipt not found', 404);
        $itemStatement = $pdo->prepare('SELECT id, name, quantity, unit_price, line_total FROM receipt_items WHERE receipt_id = ? ORDER BY id');
        $itemStatement->execute([$receipt['database_id']]);
        $items = $itemStatement->fetchAll();
        foreach ($items as &$item) {
            $item['quantity'] = (float) $item['quantity'];
            $item['unit_price'] = $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $item['line_total'] = $item['line_total'] !== null ? (float) $item['line_total'] : null;
        }
        unset($receipt['database_id']);
        $receipt['image_url'] = $receipt['image_path'];
        $receipt['subtotal'] = $receipt['subtotal'] !== null ? (float) $receipt['subtotal'] : null;
        $receipt['discount'] = $receipt['discount'] !== null ? (float) $receipt['discount'] : null;
        $receipt['tax'] = $receipt['tax'] !== null ? (float) $receipt['tax'] : null;
        $receipt['total'] = (float) $receipt['total'];
        $receipt['items'] = $items;
        jsonResponse(['receipt' => $receipt]);
    }

    $status = (string) ($_GET['status'] ?? '');
    $sql = "SELECT public_id AS id, image_path, merchant_name, subtotal, discount, tax, total, status, created_at FROM receipts";
    $params = [];
    if (in_array($status, ['pending', 'confirmed', 'needs_review', 'rejected'], true)) { $sql .= ' WHERE status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY id DESC LIMIT 50';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $receipts = $statement->fetchAll();
    foreach ($receipts as &$row) {
        $row['image_url'] = $row['image_path'];
        $row['subtotal'] = $row['subtotal'] !== null ? (float) $row['subtotal'] : null;
        $row['discount'] = $row['discount'] !== null ? (float) $row['discount'] : null;
        $row['tax'] = $row['tax'] !== null ? (float) $row['tax'] : null;
        $row['total'] = (float) $row['total'];
    }
    jsonResponse(['receipts' => $receipts]);
}

if ($method === 'PUT') {
    $body = requestJson();
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $items = $body['items'] ?? null;
    if (!is_array($items) || count($items) < 1) jsonError('items must be a non-empty array', 422);
    $merchant = isset($body['merchant']) ? trim((string) $body['merchant']) : null;
    if ($merchant !== null && mb_strlen($merchant) > 120) jsonError('merchant must be 120 characters or fewer', 422);

    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) jsonError('Each item must be an object', 422);
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) jsonError('Each item needs a name up to 150 characters', 422);
        $quantity = $item['quantity'] ?? 1;
        if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 9999.99) jsonError('Each item needs a valid quantity', 422);
        $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== '' && $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
        $lineTotal = isset($item['line_total']) && $item['line_total'] !== '' && $item['line_total'] !== null ? (float) $item['line_total'] : ($unitPrice !== null ? round($unitPrice * (float) $quantity, 2) : null);
        $cleanItems[] = ['name' => $name, 'quantity' => round((float) $quantity, 2), 'unit_price' => $unitPrice, 'line_total' => $lineTotal];
    }
    $discount = isset($body['discount']) && $body['discount'] !== '' && is_numeric($body['discount']) ? round((float) $body['discount'], 2) : null;
    $tax = isset($body['tax']) && $body['tax'] !== '' && is_numeric($body['tax']) ? round((float) $body['tax'], 2) : null;
    $itemSum = round(array_sum(array_column($cleanItems, 'line_total')), 2);
    $computedTotal = isset($body['total']) && is_numeric($body['total'])
        ? round((float) $body['total'], 2)
        : max(0, round($itemSum - ($discount ?? 0) + ($tax ?? 0), 2));

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT id FROM receipts WHERE public_id = ? FOR UPDATE');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt) throw new InvalidArgumentException('Receipt not found');

        $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$receipt['id']]);
        $itemInsert = $pdo->prepare('INSERT INTO receipt_items (receipt_id, name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
        foreach ($cleanItems as $item) {
            $itemInsert->execute([$receipt['id'], $item['name'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        }
        $pdo->prepare("UPDATE receipts SET merchant_name = ?, subtotal = ?, discount = ?, tax = ?, total = ?, status = 'confirmed', confirm_deadline = NULL, resolved_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$merchant, $itemSum, $discount, $tax, $computedTotal, $receipt['id']]);
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError($error->getMessage(), 404);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage());
        jsonError('Could not update receipt', 500);
    }

    jsonResponse(['updated' => true, 'subtotal' => $itemSum, 'discount' => $discount, 'tax' => $tax, 'total' => $computedTotal, 'status' => 'confirmed']);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $statement = $pdo->prepare('SELECT image_path FROM receipts WHERE public_id = ?');
    $statement->execute([$id]);
    $receipt = $statement->fetch();
    if (!$receipt) jsonError('Receipt not found', 404);
    $pdo->prepare('DELETE FROM receipts WHERE public_id = ?')->execute([$id]);
    deleteStoredImage($receipt['image_path']);
    jsonResponse(['deleted' => true]);
}

header('Allow: GET, PUT, DELETE');
jsonError('Method not allowed', 405);
