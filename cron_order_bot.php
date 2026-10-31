<?php
// cron_order_bot.php - 24/7 Background Order Watchdog & Escalation Bot
// Setup in cPanel Cron: * * * * * php -q /home/oxydomain/control.jixsite.com/cron_order_bot.php >/dev/null 2>&1
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/order_bot_engine.php';

// Allow CLI execution or web execution with secret key
$isCli = (php_sapi_name() === 'cli');
$key = $_GET['key'] ?? '';
if (!$isCli && $key !== 'ambition_cron_2026') {
    $staff = current_staff_user();
    if (empty($staff['name'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied. Use CLI or valid key.']);
        exit;
    }
}

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Simple Lock mechanism to prevent overlapping executions
$lockFile = sys_get_temp_dir() . '/ambition_order_bot.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 55) {
    echo json_encode(['status' => 'skipped', 'message' => 'Another order watchdog run is active within 55s.']);
    exit;
}
touch($lockFile);

try {
    $result = AmbitionOrderBot::syncAndCheckOrders(false);
    @unlink($lockFile);
    echo json_encode($result);
} catch (Throwable $e) {
    @unlink($lockFile);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

