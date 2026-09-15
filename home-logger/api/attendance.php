<?php
declare(strict_types=1);

/**
 * Attendance dashboard read + manual (operator-entered) check-in/check-out.
 * Device-driven attendance goes through api/device/rfid.php +
 * api/device/resolve_attendance.php instead.
 *
 * GET    ?date=YYYY-MM-DD -> that single day's logs + summary.
 * GET    ?days=N          -> logs from the last N days through today
 *                            (default when neither param is given: 14).
 * POST   {action: check_in|check_out, worker_id, occurred_at?, note?}
 * PUT    {id, worker_id, event_type, occurred_at, note?} edits ANY log
 *        (not just pending ones) and (re)confirms it — a log is fully
 *        CRUD-able regardless of how it got there, same as objects/receipts.
 * DELETE ?id=... removes a log outright, regardless of status.
 */
require __DIR__ . '/common.php';
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    sweepExpired($pdo);

    if (isset($_GET['date'])) {
        $date = (string) $_GET['date'];
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateTime || $dateTime->format('Y-m-d') !== $date) jsonError('date must use YYYY-MM-DD', 422);
        $fromDate = $toDate = $date;
    } else {
        $days = max(1, min(90, (int) ($_GET['days'] ?? 14)));
        $toDate = date('Y-m-d');
        $fromDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    }

    $statement = $pdo->prepare(
        "SELECT l.public_id AS id, l.event_type, l.occurred_at, l.note, l.source, l.status, l.created_at,
                w.public_id AS worker_id, w.name AS worker, COALESCE(c.name, 'Household worker') AS role, COALESCE(c.color, '#E4E8FB') AS color
         FROM attendance_logs l
         JOIN workers w ON w.id = l.worker_id
         LEFT JOIN categories c ON c.id = w.category_id
         WHERE DATE(l.occurred_at) BETWEEN ? AND ?
         ORDER BY l.occurred_at DESC, l.id DESC"
    );
    $statement->execute([$fromDate, $toDate]);
    $logs = $statement->fetchAll();

    $summary = ['from' => $fromDate, 'to' => $toDate, 'checked_in' => 0, 'checked_out' => 0, 'on_site' => 0];
    foreach ($logs as $log) {
        if ($log['status'] === 'rejected') continue;
        if ($log['event_type'] === 'check_in') $summary['checked_in']++;
        if ($log['event_type'] === 'check_out') $summary['checked_out']++;
    }
    $onSite = $pdo->query(
        "SELECT COUNT(*) AS total FROM workers w WHERE w.active = 1 AND (
            SELECT l.event_type FROM attendance_logs l WHERE l.worker_id = w.id AND l.status != 'rejected' ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1
         ) = 'check_in'"
    )->fetch();
    $summary['on_site'] = (int) $onSite['total'];

    jsonResponse(['logs' => $logs, 'summary' => $summary]);
}

if ($method === 'PUT') {
    $body = requestJson();
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $workerId = trim((string) ($body['worker_id'] ?? ''));
    if ($workerId === '') jsonError('worker_id is required', 422);
    $eventType = (string) ($body['event_type'] ?? '');
    if (!in_array($eventType, ['check_in', 'check_out'], true)) jsonError('event_type must be check_in or check_out', 422);
    $occurredAt = attendanceTime((string) ($body['occurred_at'] ?? ''));
    $note = trim((string) ($body['note'] ?? ''));
    if (mb_strlen($note) > 300) jsonError('note must be 300 characters or fewer', 422);

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT id FROM attendance_logs WHERE public_id = ? FOR UPDATE');
        $statement->execute([$id]);
        $log = $statement->fetch();
        if (!$log) throw new InvalidArgumentException('Attendance log not found');

        $workerStatement = $pdo->prepare('SELECT id, name FROM workers WHERE public_id = ? AND active = 1 FOR UPDATE');
        $workerStatement->execute([$workerId]);
        $worker = $workerStatement->fetch();
        if (!$worker) throw new InvalidArgumentException('Active household worker not found');

        $pdo->prepare("UPDATE attendance_logs SET worker_id = ?, event_type = ?, occurred_at = ?, note = ?, status = 'confirmed', confirm_deadline = NULL WHERE id = ?")
            ->execute([$worker['id'], $eventType, $occurredAt, $note === '' ? null : $note, $log['id']]);
        $pdo->commit();
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError($error->getMessage(), 404);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage());
        jsonError('Could not update attendance log', 500);
    }

    jsonResponse(['updated' => true, 'worker' => $worker['name'], 'event_type' => $eventType, 'occurred_at' => $occurredAt, 'status' => 'confirmed']);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') jsonError('id is required', 422);
    $statement = $pdo->prepare('DELETE FROM attendance_logs WHERE public_id = ?');
    $statement->execute([$id]);
    if (!$statement->rowCount()) jsonError('Attendance log not found', 404);
    jsonResponse(['deleted' => true]);
}

if ($method !== 'POST') { header('Allow: GET, POST, PUT, DELETE'); jsonError('Method not allowed', 405); }

$body = requestJson();
$action = (string) ($body['action'] ?? '');
if (!in_array($action, ['check_in', 'check_out'], true)) jsonError('action must be check_in or check_out', 422);

$workerId = trim((string) ($body['worker_id'] ?? ''));
if ($workerId === '') jsonError('worker_id is required', 422);
$occurredAt = attendanceTime((string) ($body['occurred_at'] ?? ''));
$note = trim((string) ($body['note'] ?? ''));
if (mb_strlen($note) > 300) jsonError('note must be 300 characters or fewer', 422);

try {
    $pdo->beginTransaction();
    $workerStatement = $pdo->prepare('SELECT id, name FROM workers WHERE public_id = ? AND active = 1 FOR UPDATE');
    $workerStatement->execute([$workerId]);
    $worker = $workerStatement->fetch();
    if (!$worker) throw new InvalidArgumentException('Active household worker not found');

    $date = substr($occurredAt, 0, 10);
    $lastStatement = $pdo->prepare("SELECT event_type FROM attendance_logs WHERE worker_id = ? AND DATE(occurred_at) = ? AND status != 'rejected' ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE");
    $lastStatement->execute([$worker['id'], $date]);
    $last = $lastStatement->fetch();
    if ($action === 'check_out' && !$last) throw new InvalidArgumentException('Record a check-in before checking this worker out');
    if ($last && $last['event_type'] === $action) throw new InvalidArgumentException('This worker is already marked ' . str_replace('_', ' ', $action) . ' for ' . $date);

    $id = publicId();
    $pdo->prepare('INSERT INTO attendance_logs (public_id, worker_id, event_type, occurred_at, note, source, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $worker['id'], $action, $occurredAt, $note === '' ? null : $note, 'manual', 'confirmed']);
    $pdo->commit();
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError($error->getMessage(), 422);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonError('Attendance could not be saved', 500);
}

jsonResponse([
    'attendance' => ['id' => $id, 'worker_id' => $workerId, 'worker' => $worker['name'], 'event_type' => $action, 'occurred_at' => $occurredAt, 'note' => $note],
    'message' => ucfirst(str_replace('_', ' ', $action)) . ' saved.',
], 201);

function attendanceTime(string $value): string
{
    if (trim($value) === '') return date('Y-m-d H:i:s');
    try {
        $time = new DateTimeImmutable($value);
    } catch (Throwable) {
        jsonError('occurred_at must be a valid ISO-8601 date/time', 422);
    }
    return $time->format('Y-m-d H:i:s');
}
