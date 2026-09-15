<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
try {
    db()->query('SELECT 1');
    jsonResponse(['status' => 'ok', 'database' => 'connected']);
} catch (Throwable $error) {
    jsonResponse(['status' => 'error', 'database' => 'unreachable'], 500);
}
