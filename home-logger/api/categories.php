<?php
declare(strict_types=1);

/**
 * Category CRUD. `type` distinguishes object categories (milk, newspaper —
 * carry price/unit, matched against camera captures) from attendance roles
 * (Cleaner, Cook — matched against RFID hits).
 *
 * GET    -> all categories, optionally filtered by ?type=
 * POST   -> create {name, type, unit_label?, price_per_unit?, color?}
 * PUT    -> update {id, name?, unit_label?, price_per_unit?, color?}
 * DELETE -> ?id=... (blocked if still referenced by logs/workers)
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $type = (string) ($_GET['type'] ?? '');
    $sql = "SELECT c.public_id AS id, c.name, c.type, c.unit_label, c.price_per_unit, c.color, c.created_at,
                   (SELECT COUNT(*) FROM object_logs ol WHERE ol.category_id = c.id) AS object_log_count,
                   (SELECT COUNT(*) FROM workers w WHERE w.category_id = c.id AND w.active = 1) AS worker_count
            FROM categories c";
    $params = [];
    if (in_array($type, ['object', 'attendance'], true)) { $sql .= ' WHERE c.type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY c.type, c.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        $row['price_per_unit'] = (float) $row['price_per_unit'];
        $row['object_log_count'] = (int) $row['object_log_count'];
        $row['worker_count'] = (int) $row['worker_count'];
    }
    jsonResponse(['categories' => $rows]);
}

if ($method === 'POST') {
    $body = requestJson();
    $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
    $type = (string) ($body['type'] ?? 'object');
    if ($name === '' || mb_strlen($name) > 50) jsonError('Category name must be between 1 and 50 characters', 422);
    if (!in_array($type, ['object', 'attendance'], true)) jsonError('type must be object or attendance', 422);
    $unitLabel = trim((string) ($body['unit_label'] ?? 'pcs')) ?: 'pcs';
    if (mb_strlen($unitLabel) > 20) jsonError('unit_label must be 20 characters or fewer', 422);
    $price = (float) ($body['price_per_unit'] ?? 0);
    if ($price < 0 || $price > 999999.99) jsonError('price_per_unit must be a valid non-negative amount', 422);
    $color = (string) ($body['color'] ?? '#DDF2E6');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#DDF2E6';

    $id = publicId();
    try {
        $pdo->prepare('INSERT INTO categories (public_id, name, type, unit_label, price_per_unit, color) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, $type, $unitLabel, $price, $color]);
    } catch (PDOException $error) {
        if ((int) $error->getCode() === 23000) jsonError('A category with this name already exists for this type', 409);
        error_log($error->getMessage());
        jsonError('Could not create category', 500);
    }
    jsonResponse(['category' => ['id' => $id, 'name' => $name, 'type' => $type, 'unit_label' => $unitLabel, 'price_per_unit' => $price, 'color' => $color]], 201);
}

if ($method === 'PUT') {
    $body = requestJson();
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $category = categoryByPublicId($pdo, $id, true);
    if (!$category) jsonError('Category not found', 404);

    $name = isset($body['name']) ? trim(preg_replace('/\s+/', ' ', (string) $body['name']) ?? '') : $category['name'];
    if ($name === '' || mb_strlen($name) > 50) jsonError('Category name must be between 1 and 50 characters', 422);
    $unitLabel = isset($body['unit_label']) ? trim((string) $body['unit_label']) : $category['unit_label'];
    if ($unitLabel === '' || mb_strlen($unitLabel) > 20) jsonError('unit_label must be between 1 and 20 characters', 422);
    $price = isset($body['price_per_unit']) ? (float) $body['price_per_unit'] : (float) $category['price_per_unit'];
    if ($price < 0 || $price > 999999.99) jsonError('price_per_unit must be a valid non-negative amount', 422);
    $color = isset($body['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $body['color']) ? (string) $body['color'] : $category['color'];

    try {
        $pdo->prepare('UPDATE categories SET name = ?, unit_label = ?, price_per_unit = ?, color = ? WHERE public_id = ?')
            ->execute([$name, $unitLabel, $price, $color, $id]);
    } catch (PDOException $error) {
        if ((int) $error->getCode() === 23000) jsonError('A category with this name already exists for this type', 409);
        error_log($error->getMessage());
        jsonError('Could not update category', 500);
    }
    jsonResponse(['category' => ['id' => $id, 'name' => $name, 'type' => $category['type'], 'unit_label' => $unitLabel, 'price_per_unit' => $price, 'color' => $color]]);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $category = categoryByPublicId($pdo, $id, true);
    if (!$category) jsonError('Category not found', 404);

    $usage = $pdo->prepare('SELECT
        (SELECT COUNT(*) FROM object_logs WHERE category_id = ?) AS logs,
        (SELECT COUNT(*) FROM workers WHERE category_id = ? AND active = 1) AS workers');
    $usage->execute([$category['database_id'], $category['database_id']]);
    $counts = $usage->fetch();
    if ((int) $counts['logs'] > 0 || (int) $counts['workers'] > 0) {
        jsonError('Category is still in use (' . $counts['logs'] . ' log(s), ' . $counts['workers'] . ' worker(s)) — cannot delete', 409);
    }

    $pdo->prepare('DELETE FROM categories WHERE public_id = ?')->execute([$id]);
    jsonResponse(['deleted' => true]);
}

header('Allow: GET, POST, PUT, DELETE');
jsonError('Method not allowed', 405);
