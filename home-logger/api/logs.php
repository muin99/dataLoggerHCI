<?php
declare(strict_types=1);

/**
 * Combined activity feed across all three log types, for the dashboard's
 * Activity page. Also handles CSV export.
 *
 * GET -> ?export=csv&type=objects|attendance|receipts downloads a CSV.
 *        Otherwise returns the 60 most recent events across all types,
 *        merged and sorted by time.
 */
require __DIR__ . '/common.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); jsonError('Method not allowed', 405); }
sweepExpired($pdo);

$exportType = (string) ($_GET['export'] ?? '') === 'csv' ? (string) ($_GET['type'] ?? '') : '';
if ($exportType !== '') { exportCsv($pdo, $exportType); }

$objects = $pdo->query(
    "SELECT ol.public_id AS id, ol.created_at, ol.status, c.name AS category, ol.quantity, ol.total_price
     FROM object_logs ol JOIN categories c ON c.id = ol.category_id ORDER BY ol.id DESC LIMIT 60"
)->fetchAll();
$attendance = $pdo->query(
    "SELECT l.public_id AS id, l.occurred_at AS created_at, l.status, w.name AS worker, l.event_type
     FROM attendance_logs l JOIN workers w ON w.id = l.worker_id ORDER BY l.id DESC LIMIT 60"
)->fetchAll();
$receipts = $pdo->query(
    "SELECT public_id AS id, created_at, status, merchant_name, total FROM receipts ORDER BY id DESC LIMIT 60"
)->fetchAll();

$feed = [];
foreach ($objects as $row) $feed[] = ['kind' => 'object', 'id' => $row['id'], 'created_at' => $row['created_at'], 'status' => $row['status'], 'summary' => $row['category'] . ' × ' . (float) $row['quantity'], 'amount' => (float) $row['total_price']];
foreach ($attendance as $row) $feed[] = ['kind' => 'attendance', 'id' => $row['id'], 'created_at' => $row['created_at'], 'status' => $row['status'], 'summary' => $row['worker'] . ' — ' . str_replace('_', ' ', $row['event_type']), 'amount' => null];
foreach ($receipts as $row) $feed[] = ['kind' => 'receipt', 'id' => $row['id'], 'created_at' => $row['created_at'], 'status' => $row['status'], 'summary' => ($row['merchant_name'] ?: 'Receipt'), 'amount' => (float) $row['total']];

usort($feed, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
jsonResponse(['feed' => array_slice($feed, 0, 60)]);

function exportCsv(PDO $pdo, string $type): void
{
    $filenames = ['objects' => 'object_logs.csv', 'attendance' => 'attendance_logs.csv', 'receipts' => 'receipts.csv'];
    if (!isset($filenames[$type])) jsonError('type must be objects, attendance, or receipts', 422);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenames[$type] . '"');
    $out = fopen('php://output', 'w');

    if ($type === 'objects') {
        fputcsv($out, ['Date', 'Category', 'Quantity', 'Unit Price', 'Total', 'Status', 'Source']);
        $rows = $pdo->query("SELECT ol.created_at, c.name, ol.quantity, ol.unit_price, ol.total_price, ol.status, ol.source FROM object_logs ol JOIN categories c ON c.id = ol.category_id ORDER BY ol.id DESC")->fetchAll();
        foreach ($rows as $row) fputcsv($out, [$row['created_at'], $row['name'], $row['quantity'], $row['unit_price'], $row['total_price'], $row['status'], $row['source']]);
    } elseif ($type === 'attendance') {
        fputcsv($out, ['Occurred At', 'Worker', 'Event', 'Status', 'Source', 'Note']);
        $rows = $pdo->query("SELECT l.occurred_at, w.name, l.event_type, l.status, l.source, l.note FROM attendance_logs l JOIN workers w ON w.id = l.worker_id ORDER BY l.id DESC")->fetchAll();
        foreach ($rows as $row) fputcsv($out, [$row['occurred_at'], $row['name'], $row['event_type'], $row['status'], $row['source'], $row['note']]);
    } else {
        fputcsv($out, ['Date', 'Merchant', 'Total', 'Status']);
        $rows = $pdo->query("SELECT created_at, merchant_name, total, status FROM receipts ORDER BY id DESC")->fetchAll();
        foreach ($rows as $row) fputcsv($out, [$row['created_at'], $row['merchant_name'], $row['total'], $row['status']]);
    }
    fclose($out);
    exit;
}
