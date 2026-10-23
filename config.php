<?php
// config.php - AmbitionHost Master Control System (Host on control.jixsite.com or any cPanel)
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. MySQL Database Credentials (Update with your cPanel MySQL details)
define('DB_HOST', 'localhost');
define('DB_NAME', 'oxydomain_ambition');
define('DB_USER', 'oxydomain_ambition');
define('DB_PASS', ']lKpI[r0Ns03RCz_');

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

// PDO Database Connection Singleton with Auto-Schema Repair
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Auto-heal missing tables and columns on the fly
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `notifications` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `recipient` varchar(50) NOT NULL,
                  `task_id` int(11) DEFAULT 0,
                  `dedup_key` varchar(120) DEFAULT NULL,
                  `message` text NOT NULL,
                  `is_read` tinyint(1) DEFAULT 0,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `dedup_recipient` (`recipient`, `dedup_key`),
                  KEY `recipient_read` (`recipient`, `is_read`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `activity_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `actor` varchar(100) NOT NULL,
                  `module_id` int(11) NOT NULL DEFAULT 0,
                  `event_summary` text NOT NULL,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `module_actor` (`module_id`, `actor`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `support_tickets_local` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `ticket_code` varchar(50) NOT NULL,
                  `customer_email` varchar(120) NOT NULL,
                  `subject` varchar(255) NOT NULL,
                  `status` enum('Open','Answered','Customer-Reply','Closed') DEFAULT 'Open',
                  `assigned_to` varchar(50) DEFAULT 'Support',
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `ticket_code` (`ticket_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `ticket_messages_local` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `ticket_id` int(11) NOT NULL,
                  `sender_type` enum('Customer','Staff') NOT NULL,
                  `sender_name` varchar(100) NOT NULL,
                  `message` text NOT NULL,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `ticket_id` (`ticket_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `login_attempts` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `ip_address` varchar(45) NOT NULL,
                  `attempted_at` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `ip_idx` (`ip_address`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `whmcs_items_cache` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `item_type` enum('hosting','domain') NOT NULL DEFAULT 'hosting',
                  `whmcs_item_id` int(11) NOT NULL,
                  `client_id` int(11) NOT NULL,
                  `domain` varchar(255) DEFAULT NULL,
                  `package_name` varchar(150) DEFAULT NULL,
                  `billing_cycle` varchar(50) DEFAULT 'Monthly',
                  `recurring_amount` decimal(10,2) DEFAULT 0.00,
                  `status` varchar(50) DEFAULT 'Active',
                  `reg_date` date DEFAULT NULL,
                  `next_due_date` date DEFAULT NULL,
                  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `item_unique` (`item_type`, `whmcs_item_id`),
                  KEY `status` (`status`),
                  KEY `client_id` (`client_id`),
                  KEY `domain` (`domain`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {}

        // Safe column migrations (isolated try-catch so existing columns never error)
        try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `display_title` varchar(100) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `allowed_modules` varchar(255) DEFAULT '1'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `can_graphs` tinyint(1) DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` enum('Admin','Owner','Sales','Domain','Billing','Billing_Domain','Support_Tech','B.D.E.') NOT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD COLUMN `client_email` varchar(150) DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD COLUMN `client_name` varchar(150) DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD INDEX `email_idx` (`client_email`)"); } catch (Throwable $e) {}
    }
    return $pdo;
}

function get_sys_setting(string $key, string $default = ''): string {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null && $val !== '') ? (string)$val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function log_system_activity(string $actor, int $moduleId, string $summary): void {
    try {
        db()->prepare("INSERT INTO activity_logs (actor, module_id, event_summary) VALUES (?, ?, ?)")
            ->execute([$actor, $moduleId, $summary]);
    } catch (Throwable $e) {}
}

// 1-Click WHMCS Admin Deep-Link Generator (Opens exact edit page in /user/devokedev/)
function whmcs_deep_link(string $type, $id = null): string {
    $base = rtrim(WHMCS_ADMIN_URL, '/');
    switch ($type) {
        case 'pending_orders':  return $base . '/orders.php?status=Pending';
        case 'order':           return $base . '/orders.php?action=view&id=' . urlencode((string)$id);
        case 'invoice':         return $base . '/invoices.php?action=edit&id=' . urlencode((string)$id);
        case 'unpaid_invoices': return $base . '/invoices.php?status=Unpaid';
        case 'ticket':          return $base . '/supporttickets.php?action=view&id=' . urlencode((string)$id);
        case 'tickets':         return $base . '/supporttickets.php';
        case 'client':          return $base . '/clientssummary.php?userid=' . urlencode((string)$id);
        case 'clients':         return $base . '/clients.php';
        case 'domains_list':    return $base . '/clientsdomainlist.php';
        case 'hosting_list':    return $base . '/clientshostinglist.php';
        case 'affiliates':      return $base . '/affiliates.php';
        default:                return $base . '/index.php';
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
    $token = get_sys_setting('telegram_bot_token', TELEGRAM_BOT_TOKEN);
    $chatId = get_sys_setting('aarav_telegram_chat_id', AARAV_TELEGRAM_CHAT_ID);
    if (empty($token) || strpos($token, 'YOUR_') === 0) {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => "🚨 *AmbitionHost OS Alert*\n" . $message,
            'parse_mode' => 'Markdown'
        ])
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res !== false;
}

/**
 * STRICT ZERO-LINK PLAIN-TEXT EMAIL SENDER (Anti-Spam Rule by Aarav):
 * Strips any accidental URLs/links so Gmail delivers 100% to Primary Inbox!
 */
function send_zero_link_email(string $toEmail, string $subject, string $plainMessage): bool {
    $cleanBody = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $plainMessage);
    $headers = "From: AmbitionHost Support <noreply@ambitionhost.in>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "X-Mailer: AmbitionHost-Support-Notifier";
    return @mail($toEmail, $subject, trim((string)$cleanBody), $headers);
}