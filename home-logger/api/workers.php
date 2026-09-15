<?php
declare(strict_types=1);

/**
 * Household-worker management (registration, not attendance recording —
 * that's api/attendance.php and api/device/rfid.php).
 *
 * GET  -> all workers with role and last attendance action.
 * POST -> actions: save_worker, archive_worker, assign_tag.
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $workers = $pdo->query(
        "SELECT w.public_id AS id, w.name, w.phone, w.notes, w.rfid_uid, w.active, w.created_at,
                c.public_id AS role_id, COALESCE(c.name, 'Household worker') AS role, COALESCE(c.color, '#E4E8FB') AS color,
                (SELECT l.event_type FROM attendance_logs l WHERE l.worker_id = w.id AND l.status != 'rejected' ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1) AS last_action,
                (SELECT l.occurred_at FROM attendance_logs l WHERE l.worker_id = w.id AND l.status != 'rejected' ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1) AS last_seen_at
         FROM workers w
         LEFT JOIN categories c ON c.id = w.category_id
         WHERE w.active = 1
         ORDER BY w.name"
    )->fetchAll();
    foreach ($workers as &$worker) $worker['active'] = (bool) $worker['active'];
    jsonResponse(['workers' => $workers]);
}

if ($method !== 'POST') { header('Allow: GET, POST'); jsonError('Method not allowed', 405); }

$body = requestJson();
$action = (string) ($body['action'] ?? '');
if ($action === 'save_worker') saveWorker($pdo, $body);
if ($action === 'archive_worker') archiveWorker($pdo, $body);
if ($action === 'assign_tag') assignTag($pdo, $body);
jsonError('Use action save_worker, archive_worker, or assign_tag', 422);

function saveWorker(PDO $pdo, array $body): void
{
    $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
    if ($name === '' || mb_strlen($name) > 80) jsonError('Worker name must be between 1 and 80 characters', 422);
    $phone = trim((string) ($body['phone'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    if (mb_strlen($phone) > 40 || mb_strlen($notes) > 300) jsonError('Phone or notes are too long', 422);
    $rfidUid = strtoupper(trim((string) ($body['rfid_uid'] ?? '')));
    if (mb_strlen($rfidUid) > 64) jsonError('Tag UID is too long', 422);
    $id = trim((string) ($body['id'] ?? ''));

    try {
        $pdo->beginTransaction();
        $role = attendanceRole($pdo, trim((string) ($body['role_id'] ?? '')), trim((string) ($body['role'] ?? '')));
        if ($id === '') {
            $id = publicId();
            $pdo->prepare('INSERT INTO workers (public_id, category_id, name, phone, notes, rfid_uid) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $role['database_id'], $name, $phone === '' ? null : $phone, $notes === '' ? null : $notes, $rfidUid === '' ? null : $rfidUid]);
        } else {
            $update = $pdo->prepare('UPDATE workers SET category_id = ?, name = ?, phone = ?, notes = ?, rfid_uid = ?, active = 1 WHERE public_id = ?');
            $update->execute([$role['database_id'], $name, $phone === '' ? null : $phone, $notes === '' ? null : $notes, $rfidUid === '' ? null : $rfidUid, $id]);
            if (!$update->rowCount()) {
                $exists = $pdo->prepare('SELECT 1 FROM workers WHERE public_id = ?');
                $exists->execute([$id]);
                if (!$exists->fetch()) throw new InvalidArgumentException('Worker not found');
            }
        }
        if ($rfidUid !== '') $pdo->prepare('UPDATE unknown_rfid_hits SET dismissed = 1 WHERE uid = ?')->execute([$rfidUid]);
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError($error->getMessage(), 422);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int) $error->getCode() === 23000) {
            $message = str_contains($error->getMessage(), 'rfid_uid') ? 'This tag is already assigned to another worker' : 'A worker with this name already exists';
            jsonError($message, 409);
        }
        error_log($error->getMessage());
        jsonError('Could not save worker', 500);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage());
        jsonError('Could not save worker', 500);
    }

    $status = trim((string) ($body['id'] ?? '')) !== '' ? 200 : 201;
    jsonResponse(['worker' => ['id' => $id, 'name' => $name, 'role_id' => $role['id'], 'role' => $role['name'], 'phone' => $phone, 'notes' => $notes, 'rfid_uid' => $rfidUid]], $status);
}

function archiveWorker(PDO $pdo, array $body): void
{
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $statement = $pdo->prepare('UPDATE workers SET active = 0 WHERE public_id = ? AND active = 1');
    $statement->execute([$id]);
    if (!$statement->rowCount()) jsonError('Active worker not found', 404);
    jsonResponse(['archived' => true]);
}

function assignTag(PDO $pdo, array $body): void
{
    $workerId = trim((string) ($body['worker_id'] ?? ''));
    $uid = strtoupper(trim((string) ($body['rfid_uid'] ?? '')));
    if ($workerId === '' || $uid === '') jsonError('worker_id and rfid_uid are required', 422);
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
    jsonResponse(['assigned' => true]);
}

function attendanceRole(PDO $pdo, string $roleId, string $roleName): array
{
    if ($roleId !== '') {
        $role = categoryByTypeAndPublicId($pdo, $roleId, 'attendance', true);
        if (!$role) throw new InvalidArgumentException('Attendance role not found');
        return $role;
    }
    $roleName = trim(preg_replace('/\s+/', ' ', $roleName) ?? '');
    if ($roleName === '') $roleName = 'Household worker';
    if (mb_strlen($roleName) > 50) throw new InvalidArgumentException('Worker role must be 50 characters or fewer');
    $statement = $pdo->prepare("SELECT id AS database_id, public_id AS id, name, color, type FROM categories WHERE type = 'attendance' AND LOWER(name) = LOWER(?) FOR UPDATE");
    $statement->execute([$roleName]);
    $role = $statement->fetch();
    if ($role) return $role;
    $id = publicId();
    try {
        $pdo->prepare("INSERT INTO categories (public_id, name, type, unit_label, price_per_unit, color) VALUES (?, ?, 'attendance', 'pcs', 0, '#E4E8FB')")->execute([$id, $roleName]);
    } catch (PDOException $error) {
        if ((int) $error->getCode() !== 23000) throw $error;
    }
    $role = categoryByTypeAndPublicId($pdo, $id, 'attendance', true);
    if (!$role) { $statement->execute([$roleName]); $role = $statement->fetch(); }
    if (!$role) throw new RuntimeException('Could not create the attendance role');
    return $role;
}
