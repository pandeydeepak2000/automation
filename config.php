<?php
// config.php - AmbitionHost Master Control System (Host on control.jixsite.com or any cPanel)
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. MySQL Database Credentials (Update with your cPanel MySQL details)
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_STRONG_DB_PASSWORD');

// 2. WHMCS Read-Only API & Custom Admin URL (devokedev)
define('WHMCS_URL', 'https://www.ambitionhost.in/user');
define('WHMCS_ADMIN_URL', 'https://www.ambitionhost.in/user/devokedev');
define('WHMCS_API_IDENTIFIER', 'YOUR_WHMCS_READONLY_IDENTIFIER');
define('WHMCS_API_SECRET', 'YOUR_WHMCS_READONLY_SECRET');

// 3. Razorpay Read-Only API Keys (For Auto-Matching Unpaid Invoices & CA Sheet)
define('RAZORPAY_KEY_ID', 'YOUR_RAZORPAY_KEY_ID');
define('RAZORPAY_KEY_SECRET', 'YOUR_RAZORPAY_KEY_SECRET');

// 4. Telegram Bot API (For Aarav's >30 Min SLA Alert & Critical Server Alerts)
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
define('AARAV_TELEGRAM_CHAT_ID', 'YOUR_TELEGRAM_CHAT_ID');

// 5. Pluggable Multi-LLM API Keys (Fill whichever provider you want to use)
define('API_KEY_DEEPSEEK', 'YOUR_DEEPSEEK_API_KEY');
define('API_KEY_QWEN',     'YOUR_QWEN_DASHSCOPE_API_KEY');
define('API_KEY_GEMINI',   'YOUR_GOOGLE_GEMINI_API_KEY');
define('API_KEY_OPENAI',   'YOUR_OPENAI_API_KEY');
define('API_KEY_CLAUDE',   'YOUR_ANTHROPIC_CLAUDE_API_KEY');

// PDO Database Connection Singleton
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// 1-Click WHMCS Admin Deep-Link Generator (Opens exact edit page in /user/devokedev/)
function whmcs_deep_link(string $type, $id = null): string {
    $base = rtrim(WHMCS_ADMIN_URL, '/');
    switch ($type) {
        case 'pending_orders':
            return $base . '/orders.php?status=Pending';
        case 'order':
            return $base . '/orders.php?action=view&id=' . urlencode((string)$id);
        case 'invoice':
            return $base . '/invoices.php?action=edit&id=' . urlencode((string)$id);
        case 'unpaid_invoices':
            return $base . '/invoices.php?status=Unpaid';
        case 'ticket':
            return $base . '/supporttickets.php?action=view&id=' . urlencode((string)$id);
        case 'tickets':
            return $base . '/supporttickets.php';
        case 'client':
            return $base . '/clientssummary.php?userid=' . urlencode((string)$id);
        case 'clients':
            return $base . '/clients.php';
        case 'domain':
            return $base . '/clientsdomains.php?id=' . urlencode((string)$id);
        case 'hosting':
            return $base . '/clientsservices.php?id=' . urlencode((string)$id);
        default:
            return $base . '/index.php';
    }
}

// Helper: Calculate Working Minutes between 9:00 AM and 6:00 PM IST (All 7 Days)
function calc_working_minutes(int $startTs, int $endTs): int {
    if ($endTs <= $startTs) return 0;
    $totalMinutes = 0;
    $cursor = $startTs;
    while ($cursor < $endTs) {
        $dayStr = date('Y-m-d', $cursor);
        $winStart = strtotime($dayStr . ' 09:00:00');
        $winEnd   = strtotime($dayStr . ' 18:00:00');
        $nextDay  = strtotime($dayStr . ' 00:00:00') + 86400;
        $overlapStart = max($cursor, $winStart);
        $overlapEnd   = min($endTs, $winEnd);
        if ($overlapEnd > $overlapStart) {
            $totalMinutes += (int)floor(($overlapEnd - $overlapStart) / 60);
        }
        $cursor = $nextDay;
    }
    return $totalMinutes;
}

// Helper: Send Instant Telegram Alert to Aarav
function send_telegram_alert(string $message): bool {
    if (empty(TELEGRAM_BOT_TOKEN) || strpos(TELEGRAM_BOT_TOKEN, 'YOUR_') === 0) {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => AARAV_TELEGRAM_CHAT_ID,
            'text' => "🚨 *AmbitionHost OS Alert*\n" . $message,
            'parse_mode' => 'Markdown'
        ])
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res !== false;
}