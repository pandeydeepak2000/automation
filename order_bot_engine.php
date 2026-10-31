<?php
// order_bot_engine.php - AmbitionHost 24/7 WHMCS Order Watchdog & Telegram Notification Bot
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whm_whmcs_sync.php';

class AmbitionOrderBot {

    /**
     * Auto-heal database schema for WHMCS orders cache & bot tracking
     */
    public static function ensureOrdersSchema(): void {
        $pdo = db();
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `whmcs_orders_cache` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `order_id` int(11) NOT NULL,
                  `order_num` varchar(50) DEFAULT '',
                  `client_id` int(11) NOT NULL DEFAULT 0,
                  `client_name` varchar(150) NOT NULL DEFAULT '',
                  `client_email` varchar(150) NOT NULL DEFAULT '',
                  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                  `payment_method` varchar(50) DEFAULT 'Online',
                  `invoice_id` int(11) DEFAULT 0,
                  `order_status` varchar(50) NOT NULL DEFAULT 'Pending',
                  `order_date` datetime DEFAULT NULL,
                  `has_hosting` tinyint(1) NOT NULL DEFAULT 0,
                  `has_domain` tinyint(1) NOT NULL DEFAULT 0,
                  `lineitems_summary` text DEFAULT NULL,
                  `first_detected_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `last_alerted_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `alert_count` int(11) NOT NULL DEFAULT 1,
                  `is_active_notified` tinyint(1) NOT NULL DEFAULT 0,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `order_id` (`order_id`),
                  KEY `order_status` (`order_status`),
                  KEY `client_id` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {
            error_log("AmbitionOrderBot::ensureOrdersSchema error: " . $e->getMessage());
        }
    }

    /**
     * Send Telegram message with safe HTML formatting
     */
    public static function sendTelegramHtml(string $htmlMessage, string $customChatId = '', string $customToken = ''): array {
        $token = !empty($customToken) ? $customToken : get_sys_setting('telegram_bot_token', defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
        $chatId = !empty($customChatId) ? $customChatId : get_sys_setting('order_bot_telegram_chat_id', get_sys_setting('aarav_telegram_chat_id', defined('AARAV_TELEGRAM_CHAT_ID') ? AARAV_TELEGRAM_CHAT_ID : ''));

        if (empty($token) || strpos($token, 'YOUR_') === 0) {
            return ['status' => 'error', 'message' => 'Telegram Bot Token configure nahi hai. Module 03 ya 25 me Token dalein.'];
        }
        if (empty($chatId) || strpos($chatId, 'YOUR_') === 0) {
            return ['status' => 'error', 'message' => 'Telegram Chat ID configure nahi hai.'];
        }

        $url = 'https://api.telegram.org/bot' . trim($token) . '/sendMessage';
        $postData = [
            'chat_id' => trim($chatId),
            'text' => $htmlMessage,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $rawRes = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($rawRes === false) {
            return ['status' => 'error', 'message' => 'cURL Error reaching Telegram: ' . $curlErr];
        }

        $decoded = json_decode((string)$rawRes, true);
        if (!empty($decoded['ok'])) {
            return ['status' => 'ok', 'result' => $decoded['result']];
        }

        $desc = $decoded['description'] ?? 'Unknown Telegram API error';
        return ['status' => 'error', 'message' => 'Telegram API Error: ' . $desc, 'raw' => $rawRes];
    }

    /**
     * Send 1-click Test Telegram Alert
     */
    public static function testTelegramConnection(string $customToken = '', string $customChatId = ''): array {
        $now = date('d M Y, h:i:s A');
        $msg = "🤖 <b>AmbitionHost 24/7 Order Bot - Connection Test</b>\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "✅ <b>Status:</b> Telegram Bot Connected Successfully!\n"
             . "⚡ <b>Speed:</b> Instant (< 1s Latency)\n"
             . "⏰ <b>Server Time:</b> {$now} (IST)\n"
             . "🛡 <b>Protected Queues:</b>\n"
             . "  • 🚀 Hosting Provisioning (Chandrika)\n"
             . "  • 🌐 Domain KYC & Registration (Priya/Sanju)\n"
             . "  • ⏳ Continuous Pending SLA Escalations Active\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "👉 <i>WHMCS me jab bhi new order aayega, isi Telegram chat me turant alert milega!</i>";

        return self::sendTelegramHtml($msg, $customChatId, $customToken);
    }

    /**
     * Main 24/7 Watchdog: Fetch WHMCS Orders, Alert New Orders, Escalate Pending, Confirm Activated
     */
    public static function syncAndCheckOrders(bool $manualTrigger = false): array {
        self::ensureOrdersSchema();
        $pdo = db();
        $nowTs = time();

        $botEnabled = get_sys_setting('order_bot_enabled', '1');
        $remindersEnabled = get_sys_setting('order_bot_pending_reminders', '1');
        $repeatIntervalMins = (int)get_sys_setting('order_bot_reminder_interval_mins', '15');
        if ($repeatIntervalMins < 5) $repeatIntervalMins = 15;

        // 1. Fetch pending orders from WHMCS
        $pendingRes = AmbitionInfraSync::callWhmcs('GetOrders', [
            'status'   => 'Pending',
            'limitnum' => 50
        ]);

        // 2. Also fetch recently placed orders (last 20) regardless of status
        $recentRes = AmbitionInfraSync::callWhmcs('GetOrders', [
            'limitnum' => 20
        ]);

        $rawOrders = [];
        if (!empty($pendingRes['orders']['order'])) {
            $pList = isset($pendingRes['orders']['order'][0]) ? $pendingRes['orders']['order'] : [$pendingRes['orders']['order']];
            foreach ($pList as $o) {
                if (!empty($o['id'])) $rawOrders[(int)$o['id']] = $o;
            }
        }
        if (!empty($recentRes['orders']['order'])) {
            $rList = isset($recentRes['orders']['order'][0]) ? $recentRes['orders']['order'] : [$recentRes['orders']['order']];
            foreach ($rList as $o) {
                if (!empty($o['id'])) $rawOrders[(int)$o['id']] = $o;
            }
        }

        $newOrdersAlerted = 0;
        $pendingAlerted = 0;
        $activatedAlerted = 0;
        $totalOrdersInspected = count($rawOrders);

        // Preload known orders
        $cachedOrders = [];
        $qCache = $pdo->query("SELECT * FROM whmcs_orders_cache")->fetchAll();
        foreach ($qCache as $co) {
            $cachedOrders[(int)$co['order_id']] = $co;
        }

        $insStmt = $pdo->prepare("
            INSERT INTO whmcs_orders_cache (
                order_id, order_num, client_id, client_name, client_email,
                amount, payment_method, invoice_id, order_status, order_date,
                has_hosting, has_domain, lineitems_summary,
                first_detected_at, last_alerted_at, alert_count, is_active_notified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, 0)
        ");

        $updStatusStmt = $pdo->prepare("
            UPDATE whmcs_orders_cache SET
                order_status = ?,
                is_active_notified = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $updAlertStmt = $pdo->prepare("
            UPDATE whmcs_orders_cache SET
                last_alerted_at = NOW(),
                alert_count = alert_count + 1,
                order_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        foreach ($rawOrders as $orderId => $o) {
            $orderNum = (string)($o['ordernum'] ?? $orderId);
            $clientId = (int)($o['userid'] ?? 0);
            $clientName = trim((string)($o['name'] ?? ''));
            $clientEmail = '';
            $amount = (float)($o['amount'] ?? 0);
            $payMethod = (string)($o['paymentmethod'] ?? 'Online');
            $invoiceId = (int)($o['invoiceid'] ?? 0);
            $orderStatus = (string)($o['status'] ?? 'Pending');
            $orderDate = !empty($o['date']) ? $o['date'] : date('Y-m-d H:i:s');

            // Parse Line Items
            $hasHosting = 0;
            $hasDomain = 0;
            $summaryLines = [];

            if (!empty($o['lineitems']['lineitem'])) {
                $items = isset($o['lineitems']['lineitem'][0]) ? $o['lineitems']['lineitem'] : [$o['lineitems']['lineitem']];
                foreach ($items as $li) {
                    $lType = strtolower((string)($li['type'] ?? ''));
                    $pType = strtolower((string)($li['producttype'] ?? ''));
                    $pName = (string)($li['product'] ?? ($li['name'] ?? ''));
                    $dName = (string)($li['domain'] ?? '');
                    $cycle = (string)($li['billingcycle'] ?? '');
                    $lAmt  = (float)($li['amount'] ?? 0);

                    if ($lType === 'domain' || (!empty($dName) && empty($pName))) {
                        $hasDomain = 1;
                        $summaryLines[] = "• 🌐 <b>Domain:</b> " . htmlspecialchars($dName ?: 'Domain Registration') . " (₹" . number_format($lAmt, 2) . ")";
                    } else {
                        $hasHosting = 1;
                        $desc = $pName ?: 'Web Hosting Package';
                        if (!empty($dName)) $desc .= " (" . $dName . ")";
                        if (!empty($cycle)) $desc .= " [{$cycle}]";
                        $summaryLines[] = "• 🚀 <b>Hosting:</b> " . htmlspecialchars($desc) . " (₹" . number_format($lAmt, 2) . ")";
                    }
                }
            }

            if (empty($summaryLines)) {
                $summaryLines[] = "• 📦 WHMCS Service Package (Order #{$orderId})";
            }
            $itemsSummaryText = implode("\n", $summaryLines);

            // Fetch Client Details if Name/Email missing
            if (empty($clientName) && $clientId > 0) {
                $cDet = AmbitionInfraSync::callWhmcs('GetClientsDetails', ['clientid' => $clientId]);
                if (!empty($cDet['client'])) {
                    $clientName = trim(($cDet['client']['firstname'] ?? '') . ' ' . ($cDet['client']['lastname'] ?? ''));
                    $clientEmail = (string)($cDet['client']['email'] ?? '');
                }
            }
            if (empty($clientName)) $clientName = "Client #{$clientId}";

            $orderLink = whmcs_deep_link('order', $orderId);

            // ==========================================
            // CASE 1: BRAND NEW ORDER DETECTED
            // ==========================================
            if (!isset($cachedOrders[$orderId])) {
                $insStmt->execute([
                    $orderId,
                    $orderNum,
                    $clientId,
                    $clientName,
                    $clientEmail,
                    $amount,
                    $payMethod,
                    $invoiceId,
                    $orderStatus,
                    $orderDate,
                    $hasHosting,
                    $hasDomain,
                    $itemsSummaryText
                ]);
                $cacheRecordId = (int)$pdo->lastInsertId();

                // Dispatch task into Master OS Tasks queue
                if ($hasHosting) {
                    $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, whmcs_link) VALUES (3, ?, ?, 'Chandrika', 'delivery', ?)")
                        ->execute([
                            "Order #{$orderId} Hosting Provisioning ({$clientName})",
                            "New WHMCS Order #{$orderId} placed. Please provision cPanel hosting, verify DNS and send credentials.",
                            $orderLink
                        ]);
                }
                if ($hasDomain) {
                    $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, whmcs_link) VALUES (3, ?, ?, 'Priya', 'delivery', ?)")
                        ->execute([
                            "Order #{$orderId} Domain Registration ({$clientName})",
                            "New WHMCS Order #{$orderId} includes domain. Priya & Sanju please verify 26-Registrars KYC & nameservers.",
                            $orderLink
                        ]);
                }

                // Fire Instant Telegram Notification
                if ($botEnabled === '1' || $manualTrigger) {
                    $badge = ($orderStatus === 'Pending') ? "⚠️ <b>PENDING PROVISIONING</b>" : "✅ <b>ACTIVE</b>";
                    $tgMsg = "🛒 <b>NEW WHMCS ORDER RECEIVED!</b>\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n"
                           . "📦 <b>Order ID:</b> <code>#{$orderId}</code> (Num: {$orderNum})\n"
                           . "👤 <b>Customer:</b> " . htmlspecialchars($clientName) . " (Client #{$clientId})\n"
                           . "💰 <b>Amount:</b> ₹" . number_format($amount, 2) . " (" . htmlspecialchars($payMethod) . ")\n"
                           . "📄 <b>WHMCS Invoice:</b> #" . ($invoiceId ?: 'N/A') . "\n"
                           . "⚡ <b>Status:</b> {$badge}\n"
                           . "⏰ <b>Time:</b> " . date('d M Y, h:i A', strtotime($orderDate)) . "\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n"
                           . "🏷 <b>Ordered Services:</b>\n"
                           . $itemsSummaryText . "\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n"
                           . "👥 <b>Immediate Delivery Queue:</b>\n"
                           . ($hasHosting ? "  • 🚀 Hosting: Chandrika (cPanel & Pass)\n" : "")
                           . ($hasDomain ? "  • 🌐 Domain: Priya / Sanju (KYC & DNS)\n" : "")
                           . "👉 <a href=\"{$orderLink}\">Open Order in WHMCS Admin</a>";

                    self::sendTelegramHtml($tgMsg);
                    $newOrdersAlerted++;
                }

                $cachedOrders[$orderId] = [
                    'id' => $cacheRecordId,
                    'order_id' => $orderId,
                    'order_status' => $orderStatus,
                    'first_detected_at' => date('Y-m-d H:i:s'),
                    'last_alerted_at' => date('Y-m-d H:i:s'),
                    'alert_count' => 1,
                    'is_active_notified' => 0
                ];
            }

            // ==========================================
            // CASE 2: EXISTING ORDER STILL PENDING (CONTINUOUS ESCALATION)
            // ==========================================
            else {
                $cached = $cachedOrders[$orderId];
                $cacheRecordId = (int)$cached['id'];

                if ($orderStatus === 'Pending') {
                    $firstTs = strtotime((string)$cached['first_detected_at']);
                    $lastAlertTs = strtotime((string)$cached['last_alerted_at']);
                    $elapsedMins = (int)floor(($nowTs - $firstTs) / 60);
                    $minsSinceLastAlert = (int)floor(($nowTs - $lastAlertTs) / 60);

                    if (($remindersEnabled === '1' && $minsSinceLastAlert >= $repeatIntervalMins) || $manualTrigger) {
                        $updAlertStmt->execute(['Pending', $cacheRecordId]);

                        $alertCountNext = (int)$cached['alert_count'] + 1;
                        $tgMsg = "⚠️ <b>PENDING ORDER ESCALATION ALERT (#{$alertCountNext})</b>\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "⏳ <b>Order #{$orderId} is still PENDING for {$elapsedMins} minutes!</b>\n"
                               . "👤 <b>Customer:</b> " . htmlspecialchars($clientName) . " (Client #{$clientId})\n"
                               . "💰 <b>Amount:</b> ₹" . number_format($amount, 2) . "\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "🏷 <b>Pending Items:</b>\n"
                               . $itemsSummaryText . "\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "🚨 <b>Action Required Until Order is Active:</b>\n"
                               . ($hasHosting ? "  • 🚀 <b>Chandrika:</b> Please provision cPanel & dispatch pass!\n" : "")
                               . ($hasDomain ? "  • 🌐 <b>Priya/Sanju:</b> Please verify Registrar KYC & Nameservers!\n" : "")
                               . "👉 <a href=\"{$orderLink}\">Open & Activate in WHMCS</a>";

                        self::sendTelegramHtml($tgMsg);
                        $pendingAlerted++;
                    }
                }

                // ==========================================
                // CASE 3: WAS PENDING, NOW ACTIVE (FULFILLED CONFIRMATION)
                // ==========================================
                elseif ($orderStatus === 'Active' && $cached['order_status'] === 'Pending') {
                    if ((int)$cached['is_active_notified'] === 0) {
                        $updStatusStmt->execute(['Active', 1, $cacheRecordId]);

                        $tgMsg = "✅ <b>ORDER FULFILLED & ACTIVE!</b>\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "🎉 <b>Order #{$orderId} has been ACTIVATED in WHMCS!</b>\n"
                               . "👤 <b>Customer:</b> " . htmlspecialchars($clientName) . "\n"
                               . "💰 <b>Amount:</b> ₹" . number_format($amount, 2) . "\n"
                               . "🏷 <b>Delivered Items:</b>\n"
                               . $itemsSummaryText . "\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "✨ <i>Service is now LIVE. Pending escalation reminders stopped.</i>";

                        self::sendTelegramHtml($tgMsg);
                        $activatedAlerted++;
                    }
                }
            }
        }

        // 3. Reconcile any locally cached 'Pending' orders not in raw list
        foreach ($cachedOrders as $cid => $co) {
            if ($co['order_status'] === 'Pending' && !isset($rawOrders[$cid])) {
                try {
                    $verify = AmbitionInfraSync::callWhmcs('GetOrders', ['id' => $cid]);
                    if (!empty($verify['orders']['order'][0])) {
                        $vo = $verify['orders']['order'][0];
                        $newSt = (string)($vo['status'] ?? 'Active');
                        if ($newSt !== 'Pending') {
                            $updStatusStmt->execute([$newSt, 1, (int)$co['id']]);
                            if ($newSt === 'Active' && (int)$co['is_active_notified'] === 0) {
                                $tgMsg = "✅ <b>ORDER FULFILLED & ACTIVE!</b>\n"
                                       . "━━━━━━━━━━━━━━━━━━━━━━\n"
                                       . "🎉 <b>Order #{$cid} is now ACTIVE!</b>\n"
                                       . "👤 <b>Customer:</b> " . htmlspecialchars($co['client_name']) . "\n"
                                       . "✨ <i>Pending escalation reminders stopped.</i>";
                                self::sendTelegramHtml($tgMsg);
                                $activatedAlerted++;
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        // Save last run timestamp
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('order_bot_last_run_at', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")
                ->execute();
        } catch (Throwable $e) {}

        return [
            'status' => 'ok',
            'orders_inspected' => $totalOrdersInspected,
            'new_orders_alerted' => $newOrdersAlerted,
            'pending_reminders_sent' => $pendingAlerted,
            'activated_confirmed' => $activatedAlerted,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Process 0-second Instant Webhook from WHMCS Hook
     */
    public static function processInstantWebhook(int $orderId, string $hookType = '', array $vars = []): array {
        if ($orderId <= 0) return ['status' => 'error', 'message' => 'Invalid order ID'];

        self::ensureOrdersSchema();
        $pdo = db();

        // Query WHMCS specifically for this Order
        $res = AmbitionInfraSync::callWhmcs('GetOrders', ['id' => $orderId]);
        if (empty($res['orders']['order'][0])) {
            // If API didn't return order array, trigger syncAndCheckOrders immediately
            return self::syncAndCheckOrders(true);
        }

        $o = $res['orders']['order'][0];
        $orderNum = (string)($o['ordernum'] ?? $orderId);
        $clientId = (int)($o['userid'] ?? 0);
        $clientName = trim((string)($o['name'] ?? ''));
        $amount = (float)($o['amount'] ?? 0);
        $payMethod = (string)($o['paymentmethod'] ?? 'Online');
        $invoiceId = (int)($o['invoiceid'] ?? 0);
        $orderStatus = (string)($o['status'] ?? 'Pending');

        // Check if already notified
        $chk = $pdo->prepare("SELECT id, is_active_notified FROM whmcs_orders_cache WHERE order_id = ?");
        $chk->execute([$orderId]);
        $existing = $chk->fetch();

        // Line items summary
        $summaryLines = [];
        $hasHosting = 0;
        $hasDomain = 0;
        if (!empty($o['lineitems']['lineitem'])) {
            $items = isset($o['lineitems']['lineitem'][0]) ? $o['lineitems']['lineitem'] : [$o['lineitems']['lineitem']];
            foreach ($items as $li) {
                $lType = strtolower((string)($li['type'] ?? ''));
                $pName = (string)($li['product'] ?? '');
                $dName = (string)($li['domain'] ?? '');
                if ($lType === 'domain' || (!empty($dName) && empty($pName))) {
                    $hasDomain = 1;
                    $summaryLines[] = "• 🌐 <b>Domain:</b> " . htmlspecialchars($dName ?: 'Domain Registration');
                } else {
                    $hasHosting = 1;
                    $summaryLines[] = "• 🚀 <b>Hosting:</b> " . htmlspecialchars($pName ?: 'Web Hosting') . (!empty($dName) ? " ({$dName})" : "");
                }
            }
        }
        $summaryText = implode("\n", $summaryLines) ?: "• 📦 WHMCS Order Package";

        $orderLink = whmcs_deep_link('order', $orderId);

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO whmcs_orders_cache (
                    order_id, order_num, client_id, client_name, amount, payment_method,
                    invoice_id, order_status, order_date, has_hosting, has_domain, lineitems_summary,
                    first_detected_at, last_alerted_at, alert_count, is_active_notified
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW(), 1, ?)
            ");
            $ins->execute([
                $orderId, $orderNum, $clientId, $clientName, $amount, $payMethod,
                $invoiceId, $orderStatus, $hasHosting, $hasDomain, $summaryText,
                ($orderStatus === 'Active' ? 1 : 0)
            ]);

            // Instant Telegram push!
            $tgMsg = "⚡ <b>INSTANT ORDER ALERT (0-Second Webhook)</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "📦 <b>Order ID:</b> <code>#{$orderId}</code> (Num: {$orderNum})\n"
                   . "👤 <b>Customer:</b> " . htmlspecialchars($clientName) . " (Client #{$clientId})\n"
                   . "💰 <b>Amount:</b> ₹" . number_format($amount, 2) . " (" . htmlspecialchars($payMethod) . ")\n"
                   . "📄 <b>Invoice:</b> #" . ($invoiceId ?: 'N/A') . "\n"
                   . "⚡ <b>Status:</b> <b>" . htmlspecialchars($orderStatus) . "</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🏷 <b>Ordered Items:</b>\n"
                   . $summaryText . "\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "👥 <b>Immediate Delivery Action:</b>\n"
                   . ($hasHosting ? "  • 🚀 Hosting: Chandrika (Provision cPanel & Send Pass)\n" : "")
                   . ($hasDomain ? "  • 🌐 Domain: Priya / Sanju (26-Registrars KYC Verification)\n" : "")
                   . "👉 <a href=\"{$orderLink}\">Open Order in WHMCS</a>";

            self::sendTelegramHtml($tgMsg);
        }

        return ['status' => 'ok', 'order_id' => $orderId, 'hook_type' => $hookType];
    }

    /**
     * Send manual 1-click urgent escalation for a specific order
     */
    public static function sendManualEscalation(int $orderId): array {
        self::ensureOrdersSchema();
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM whmcs_orders_cache WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch();

        if (!$ord) {
            return ['status' => 'error', 'message' => 'Order cache me nahi mila.'];
        }

        $nowTs = time();
        $firstTs = strtotime((string)$ord['first_detected_at']);
        $elapsedMins = (int)floor(($nowTs - $firstTs) / 60);
        $orderLink = whmcs_deep_link('order', $orderId);

        $msg = "🚨 <b>MANUAL URGENT ESCALATION FROM MASTER OS</b>\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "⏳ <b>Order #{$orderId} is PENDING for {$elapsedMins} mins!</b>\n"
             . "👤 <b>Customer:</b> " . htmlspecialchars($ord['client_name']) . " (Client #{$ord['client_id']})\n"
             . "💰 <b>Amount:</b> ₹" . number_format((float)$ord['amount'], 2) . "\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "🏷 <b>Unfulfilled Items:</b>\n"
             . ($ord['lineitems_summary'] ?: "• Service Items Pending") . "\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "⚠️ <b>Admin Notice:</b> Chandrika (Hosting) aur Priya/Sanju (Domain) is order ko turant activate karein!\n"
             . "👉 <a href=\"{$orderLink}\">Open in WHMCS Admin</a>";

        $pdo->prepare("UPDATE whmcs_orders_cache SET last_alerted_at = NOW(), alert_count = alert_count + 1 WHERE id = ?")
            ->execute([(int)$ord['id']]);

        return self::sendTelegramHtml($msg);
    }

    /**
     * Get live bot monitoring statistics
     */
    public static function getBotStatus(): array {
        self::ensureOrdersSchema();
        $pdo = db();

        $token = get_sys_setting('telegram_bot_token', defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
        $chatId = get_sys_setting('order_bot_telegram_chat_id', get_sys_setting('aarav_telegram_chat_id', defined('AARAV_TELEGRAM_CHAT_ID') ? AARAV_TELEGRAM_CHAT_ID : ''));
        $enabled = get_sys_setting('order_bot_enabled', '1');
        $reminders = get_sys_setting('order_bot_pending_reminders', '1');
        $interval = (int)get_sys_setting('order_bot_reminder_interval_mins', '15');
        $lastRun = get_sys_setting('order_bot_last_run_at', 'Never');

        $totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_orders_cache")->fetchColumn();
        $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_orders_cache WHERE order_status = 'Pending'")->fetchColumn();
        $activeOrders = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_orders_cache WHERE order_status = 'Active'")->fetchColumn();

        $hasToken = (!empty($token) && strpos($token, 'YOUR_') !== 0);
        $hasChatId = (!empty($chatId) && strpos($chatId, 'YOUR_') !== 0);

        return [
            'is_configured' => ($hasToken && $hasChatId),
            'has_token' => $hasToken,
            'has_chat_id' => $hasChatId,
            'is_enabled' => ($enabled === '1'),
            'reminders_enabled' => ($reminders === '1'),
            'reminder_interval_mins' => $interval,
            'last_run_at' => $lastRun,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'active_orders' => $activeOrders,
            'bot_token_preview' => $hasToken ? substr($token, 0, 8) . '••••' : '',
            'chat_id' => $chatId
        ];
    }
}
