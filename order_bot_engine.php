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
                  `has_vps` tinyint(1) NOT NULL DEFAULT 0,
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

            try {
                $pdo->exec("ALTER TABLE `whmcs_orders_cache` ADD COLUMN `has_vps` tinyint(1) NOT NULL DEFAULT 0 AFTER `has_domain`");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            error_log("AmbitionOrderBot::ensureOrdersSchema error: " . $e->getMessage());
        }
    }

    /**
     * Send Telegram message with safe HTML formatting and IPv4 force resolution
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

        // Custom API Gateway support (useful if webhost firewall blocks api.telegram.org)
        $apiBase = get_sys_setting('telegram_api_gateway', 'https://api.telegram.org');
        if (empty($apiBase)) $apiBase = 'https://api.telegram.org';
        $apiBase = rtrim($apiBase, '/');

        $url = $apiBase . '/bot' . trim($token) . '/sendMessage';
        $postData = [
            'chat_id' => trim($chatId),
            'text' => $htmlMessage,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false
        ];

        // 1. Primary: cURL with IPv4 forced (Fixes Linux IPv6 timeout on cPanel)
        $rawRes = false;
        $curlErr = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // CRITICAL: Prevents 10s IPv6 hang on CentOS/cPanel!
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AmbitionHostBot/2.0'
        ]);
        $rawRes = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // 2. Secondary Fallback: file_get_contents with stream context if cURL was blocked
        if ($rawRes === false) {
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: Mozilla/5.0 AmbitionHostBot/2.0\r\n",
                    'content' => http_build_query($postData),
                    'timeout' => 8
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ];
            $ctx = stream_context_create($opts);
            $rawRes = @file_get_contents($url, false, $ctx);
        }

        if ($rawRes === false) {
            return [
                'status' => 'error',
                'message' => 'Telegram connection timeout: ' . ($curlErr ?: 'Server firewall may be blocking outbound port 443 to api.telegram.org') . '. Tip: cPanel me Outbound Firewall (CSF) me port 443 allow karein ya Telegram Proxy Gateway use karein.'
            ];
        }

        $decoded = json_decode((string)$rawRes, true);
        if (!empty($decoded['ok'])) {
            return ['status' => 'ok', 'result' => $decoded['result']];
        }

        // Auto-heal: If Telegram complains about invalid HTML entity/tag, strip tags and send immediately as plain text
        $desc = $decoded['description'] ?? 'Unknown Telegram API error';
        if (stripos($desc, 'Too Many Requests') !== false) {
            preg_match('/retry after (\d+)/i', $desc, $m);
            $sec = $m[1] ?? '60';
            return ['status' => 'error', 'message' => "Telegram Flood Protection: Telegram is cooling down. Please wait {$sec} seconds before sending alerts.", 'retry_after' => (int)$sec, 'raw' => $rawRes];
        }

        if (stripos($desc, 'parse entities') !== false || stripos($desc, 'Unsupported start tag') !== false) {
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlMessage));
            $plainPost = [
                'chat_id' => trim($chatId),
                'text' => $plainText,
                'disable_web_page_preview' => false
            ];
            $chRetry = curl_init($url);
            curl_setopt_array($chRetry, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_POSTFIELDS => http_build_query($plainPost),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            $rawRetry = curl_exec($chRetry);
            curl_close($chRetry);
            $decRetry = json_decode((string)$rawRetry, true);
            if (!empty($decRetry['ok'])) {
                return ['status' => 'ok', 'result' => $decRetry['result'], 'auto_healed' => true];
            }
        }

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
             . "⚡ <b>Speed:</b> Instant (Under 1s Latency)\n"
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
     * Robust extractor for WHMCS API orders response across all WHMCS versions
     */
    public static function extractOrdersList($res): array {
        if (empty($res) || !is_array($res)) return [];

        // Direct single order
        if (!empty($res['id']) && (!empty($res['ordernum']) || !empty($res['status']))) {
            return [$res];
        }

        // Shape 1: $res['orders']['order']
        if (!empty($res['orders']['order'])) {
            $items = $res['orders']['order'];
            return isset($items[0]) ? $items : [$items];
        }

        // Shape 2: $res['orders'] as array/list/hashmap
        if (!empty($res['orders']) && is_array($res['orders'])) {
            $list = [];
            foreach ($res['orders'] as $k => $val) {
                if (is_array($val) && (!empty($val['id']) || !empty($val['ordernum']) || !empty($val['status']))) {
                    $list[] = $val;
                }
            }
            if (!empty($list)) return $list;

            if (!empty($res['orders']['id'])) return [$res['orders']];
        }

        // Shape 3: $res['order']
        if (!empty($res['order']) && is_array($res['order'])) {
            if (isset($res['order'][0])) return $res['order'];
            if (!empty($res['order']['id'])) return [$res['order']];
        }

        // Shape 4: $res['data']
        if (!empty($res['data']) && is_array($res['data'])) {
            $sub = self::extractOrdersList($res['data']);
            if (!empty($sub)) return $sub;
        }

        return [];
    }

    /**
     * Convert minutes into user-friendly readable format: "5 Hours 35 Mins" or "5h 35m"
     */
    public static function formatDuration(int $totalMins, bool $compact = false): string {
        if ($totalMins <= 0) return $compact ? '1m' : '1 Min';
        if ($totalMins < 60) return $compact ? "{$totalMins}m" : "{$totalMins} Mins";

        $days = (int)floor($totalMins / 1440);
        $rem = $totalMins % 1440;
        $hours = (int)floor($rem / 60);
        $mins = $rem % 60;

        if ($compact) {
            if ($days > 0) return "{$days}d {$hours}h" . ($mins > 0 ? " {$mins}m" : "");
            return "{$hours}h" . ($mins > 0 ? " {$mins}m" : "");
        }

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} " . ($days === 1 ? 'Day' : 'Days');
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . ($hours === 1 ? 'Hour' : 'Hours');
        }
        if ($mins > 0 && $days === 0) {
            $parts[] = "{$mins} " . ($mins === 1 ? 'Min' : 'Mins');
        } elseif ($mins > 0 && $days > 0 && $hours === 0) {
            $parts[] = "{$mins} Mins";
        }

        return !empty($parts) ? implode(' ', $parts) : "{$totalMins} Mins";
    }

    /**
     * Diagnostic helper: Inspect WHMCS Orders API connectivity & raw output
     */
    public static function diagnoseWhmcsOrders(string $customIdent = '', string $customSecret = '', string $customUrl = ''): array {
        $ident = trim((string)($customIdent ?: get_sys_setting('whmcs_api_identifier', defined('WHMCS_API_IDENTIFIER') ? WHMCS_API_IDENTIFIER : '')));
        $urlBase = trim((string)($customUrl ?: get_sys_setting('whmcs_url', defined('WHMCS_URL') ? WHMCS_URL : 'https://www.ambitionhost.in/user')));

        $pRes = AmbitionInfraSync::callWhmcs('GetOrders', ['status' => 'Pending', 'limitnum' => 10], $customIdent, $customSecret, $customUrl);
        $rRes = AmbitionInfraSync::callWhmcs('GetOrders', ['limitnum' => 10], $customIdent, $customSecret, $customUrl);
        $sRes = AmbitionInfraSync::callWhmcs('GetOrders', ['id' => 65067], $customIdent, $customSecret, $customUrl);

        $pList = self::extractOrdersList($pRes);
        $rList = self::extractOrdersList($rRes);
        $sList = self::extractOrdersList($sRes);

        $detectedIds = [];
        foreach (array_merge($pList, $rList, $sList) as $o) {
            if (!empty($o['id'])) $detectedIds[(int)$o['id']] = ($o['ordernum'] ?? '') . ' (' . ($o['status'] ?? '') . ' - ₹' . ($o['amount'] ?? '') . ')';
        }

        $issues = [];
        if (($pRes['result'] ?? '') === 'error') {
            $issues[] = "WHMCS Pending Query Error: " . ($pRes['message'] ?? 'Unknown error');
        }
        if (($rRes['result'] ?? '') === 'error') {
            $issues[] = "WHMCS Recent Query Error: " . ($rRes['message'] ?? 'Unknown error');
        }

        return [
            'api_configured' => (!empty($ident) && strpos($ident, 'YOUR_') !== 0),
            'api_identifier' => $ident,
            'target_url' => rtrim($urlBase, '/') . '/includes/api.php',
            'pending_orders_found' => count($pList),
            'recent_orders_found' => count($rList),
            'known_order_found' => count($sList),
            'detected_order_ids' => $detectedIds,
            'api_issues' => $issues,
            'pending_raw_sample' => $pRes,
            'recent_raw_sample' => $rRes
        ];
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
        $repeatIntervalMins = (int)get_sys_setting('order_bot_reminder_interval_mins', '10');
        if ($repeatIntervalMins < 5) $repeatIntervalMins = 10;

        $rawOrders = [];
        $apiError = '';

        // 1. Fetch pending orders with status = 'Pending'
        $pendingRes = AmbitionInfraSync::callWhmcs('GetOrders', [
            'status'   => 'Pending',
            'limitnum' => 50
        ]);
        $pOrders = self::extractOrdersList($pendingRes);
        foreach ($pOrders as $o) {
            if (!empty($o['id'])) $rawOrders[(int)$o['id']] = $o;
        }

        // 2. Also try status = 'pending' (lowercase)
        if (empty($rawOrders)) {
            $pendingRes2 = AmbitionInfraSync::callWhmcs('GetOrders', [
                'status'   => 'pending',
                'limitnum' => 50
            ]);
            $pOrders2 = self::extractOrdersList($pendingRes2);
            foreach ($pOrders2 as $o) {
                if (!empty($o['id'])) $rawOrders[(int)$o['id']] = $o;
            }
        }

        // 3. Also fetch all recent orders (limitnum = 100) and pick any that are Pending
        $recentRes = AmbitionInfraSync::callWhmcs('GetOrders', [
            'limitnum' => 100
        ]);
        $rOrders = self::extractOrdersList($recentRes);
        foreach ($rOrders as $o) {
            if (!empty($o['id'])) {
                if (empty($rawOrders[(int)$o['id']]) || strcasecmp((string)($o['status'] ?? ''), 'Pending') === 0) {
                    $rawOrders[(int)$o['id']] = $o;
                }
            }
        }

        // 4. Fallback: If rawOrders is empty, try query for known live order IDs (65067, 65065)
        if (empty($rawOrders)) {
            foreach ([65067, 65065] as $knownId) {
                $singleRes = AmbitionInfraSync::callWhmcs('GetOrders', ['id' => $knownId]);
                $sOrders = self::extractOrdersList($singleRes);
                foreach ($sOrders as $so) {
                    if (!empty($so['id'])) $rawOrders[(int)$so['id']] = $so;
                }
            }
        }

        // Check if WHMCS returned an API error (e.g. Permission denied)
        if (empty($rawOrders)) {
            if (($pendingRes['result'] ?? '') === 'error') {
                $apiError = $pendingRes['message'] ?? ($pendingRes['raw'] ?? 'WHMCS error on GetOrders');
            } elseif (($recentRes['result'] ?? '') === 'error') {
                $apiError = $recentRes['message'] ?? ($recentRes['raw'] ?? 'WHMCS error on GetOrders');
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
                has_hosting, has_domain, has_vps, lineitems_summary,
                first_detected_at, last_alerted_at, alert_count, is_active_notified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, 0)
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
            $hasVps = 0;
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

                    if ($lType === 'domain' || (!empty($dName) && empty($pName)) || $pType === 'domain') {
                        $hasDomain = 1;
                        $summaryLines[] = "• 🌐 <b>Domain:</b> " . htmlspecialchars($dName ?: 'Domain Registration') . " (₹" . number_format($lAmt, 2) . ")";
                    } elseif (preg_match('/vps|server|cloud|rdp|dedicated|kvm/i', $pName) || strpos($pType, 'server') !== false) {
                        $hasVps = 1;
                        $desc = $pName ?: 'VPS / Server';
                        if (!empty($dName)) $desc .= " (" . $dName . ")";
                        if (!empty($cycle)) $desc .= " [{$cycle}]";
                        $summaryLines[] = "• ⚡ <b>VPS/Server:</b> " . htmlspecialchars($desc) . " (₹" . number_format($lAmt, 2) . ")";
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
            // CASE 1: BRAND NEW ORDER DETECTED (HIGH-VISIBILITY INSTANT ALERT)
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
                    $hasVps,
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

                // Always send Telegram Alert for new orders:
                // 1. If cache was already established, ANY new order that appears is a genuine new order!
                // 2. If it's a Pending order, always alert!
                // 3. If placed today/recently, always alert!
                $isInitialBulkSeed = (count($cachedOrders) === 0);
                $isTodayOrder = (abs($nowTs - strtotime($orderDate)) <= 86400);
                $shouldSendAlert = (!$isInitialBulkSeed) || ($orderStatus === 'Pending') || $isTodayOrder;

                if (($botEnabled === '1' || $manualTrigger) && $shouldSendAlert) {
                    $badge = ($orderStatus === 'Pending') ? "⚠️ <b>PENDING PROVISIONING</b>" : "✅ <b>ACTIVE (PAID)</b>";
                    $tgMsg = "🔥🚨 <b>⚡ NEW WHMCS ORDER RECEIVED (JUST NOW)!</b> 🚨🔥\n"
                           . "━━━━━━━━━━━━━━━━━━━━━━\n"
                           . "⚡ <b>ATTENTION TEAM: NEW LIVE ORDER ARRIVED!</b>\n"
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
                           . "👥 <b>Immediate Delivery Action:</b>\n"
                           . ($hasHosting ? "  • 🚀 Hosting: Chandrika (cPanel & Pass Provisioning)\n" : "")
                           . ($hasDomain ? "  • 🌐 Domain: Priya / Sanju (26-Registrars KYC & DNS Setup)\n" : "")
                           . ($hasVps ? "  • ⚡ VPS/Server: Root/SSH Provisioning Queue\n" : "")
                           . "👉 <a href=\"{$orderLink}\"><b>OPEN & PROCESS IN WHMCS ADMIN ➔</b></a>";

                    self::sendTelegramHtml($tgMsg);
                    $newOrdersAlerted++;
                    usleep(350000); // 350ms delay to prevent Telegram 429 flood control
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
            // CASE 2: EXISTING ORDER (CHECK ACTIVATION / STATUS SYNC)
            // ==========================================
            else {
                $cached = $cachedOrders[$orderId];
                $cacheRecordId = (int)$cached['id'];

                if ($orderStatus === 'Pending') {
                    // Status is still Pending; kept in cache. Consolidated digest will report it cleanly every 10 mins!
                    if ($cached['order_status'] !== 'Pending') {
                        $updStatusStmt->execute(['Pending', 0, $cacheRecordId]);
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

        // 4. Send Consolidated Master Pending Digest every 10 minutes (or if manually triggered)
        // Groups all pending orders into Domain, Hosting, and VPS/Other categories.
        $lastDigestTs = (int)get_sys_setting('order_bot_last_digest_at_ts', '0');
        $minsSinceDigest = ($lastDigestTs > 0) ? (int)floor(($nowTs - $lastDigestTs) / 60) : 999;

        if (($remindersEnabled === '1' && $minsSinceDigest >= $repeatIntervalMins) || $manualTrigger) {
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM whmcs_orders_cache WHERE order_status = 'Pending'");
            $pCount = (int)$stmtCount->fetchColumn();

            if ($pCount > 0 || $manualTrigger) {
                $digestResult = self::sendConsolidatedPendingDigest($manualTrigger);
                if (!empty($digestResult['sent'])) {
                    $pendingAlerted = (int)($digestResult['count'] ?? 1);
                }
            }
        }

        // Save last run timestamp
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('order_bot_last_run_at', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")
                ->execute();
        } catch (Throwable $e) {}

        return [
            'status' => empty($apiError) ? 'ok' : 'error',
            'api_error' => $apiError,
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
        $orders = self::extractOrdersList($res);
        if (empty($orders[0])) {
            // If API didn't return order array, trigger syncAndCheckOrders immediately
            return self::syncAndCheckOrders(true);
        }

        $o = $orders[0];
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
        $hasVps = 0;
        if (!empty($o['lineitems']['lineitem'])) {
            $items = isset($o['lineitems']['lineitem'][0]) ? $o['lineitems']['lineitem'] : [$o['lineitems']['lineitem']];
            foreach ($items as $li) {
                $lType = strtolower((string)($li['type'] ?? ''));
                $pType = strtolower((string)($li['producttype'] ?? ''));
                $pName = (string)($li['product'] ?? ($li['name'] ?? ''));
                $dName = (string)($li['domain'] ?? '');
                $cycle = (string)($li['billingcycle'] ?? '');
                $lAmt  = (float)($li['amount'] ?? 0);
                if ($lType === 'domain' || (!empty($dName) && empty($pName)) || $pType === 'domain') {
                    $hasDomain = 1;
                    $summaryLines[] = "• 🌐 <b>Domain:</b> " . htmlspecialchars($dName ?: 'Domain Registration') . " (₹" . number_format($lAmt, 2) . ")";
                } elseif (preg_match('/vps|server|cloud|rdp|dedicated|kvm/i', $pName) || strpos($pType, 'server') !== false) {
                    $hasVps = 1;
                    $desc = $pName ?: 'VPS / Server';
                    if (!empty($dName)) $desc .= " (" . $dName . ")";
                    if (!empty($cycle)) $desc .= " [{$cycle}]";
                    $summaryLines[] = "• ⚡ <b>VPS/Server:</b> " . htmlspecialchars($desc) . " (₹" . number_format($lAmt, 2) . ")";
                } else {
                    $hasHosting = 1;
                    $desc = $pName ?: 'Web Hosting Package';
                    if (!empty($dName)) $desc .= " (" . $dName . ")";
                    if (!empty($cycle)) $desc .= " [{$cycle}]";
                    $summaryLines[] = "• 🚀 <b>Hosting:</b> " . htmlspecialchars($desc) . " (₹" . number_format($lAmt, 2) . ")";
                }
            }
        }
        $summaryText = implode("\n", $summaryLines) ?: "• 📦 WHMCS Order Package";

        $orderLink = whmcs_deep_link('order', $orderId);

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO whmcs_orders_cache (
                    order_id, order_num, client_id, client_name, amount, payment_method,
                    invoice_id, order_status, order_date, has_hosting, has_domain, has_vps, lineitems_summary,
                    first_detected_at, last_alerted_at, alert_count, is_active_notified
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, NOW(), NOW(), 1, ?)
            ");
            $ins->execute([
                $orderId, $orderNum, $clientId, $clientName, $amount, $payMethod,
                $invoiceId, $orderStatus, $hasHosting, $hasDomain, $hasVps, $summaryText,
                ($orderStatus === 'Active' ? 1 : 0)
            ]);

            // High-visibility Instant Telegram push!
            $badge = ($orderStatus === 'Pending') ? "⚠️ <b>PENDING PROVISIONING</b>" : "✅ <b>ACTIVE (PAID)</b>";
            $tgMsg = "🔥🚨 <b>⚡ NEW WHMCS ORDER RECEIVED (0-SECOND HOOK)!</b> 🚨🔥\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "⚡ <b>ATTENTION TEAM: NEW LIVE ORDER ARRIVED!</b>\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "📦 <b>Order ID:</b> <code>#{$orderId}</code> (Num: {$orderNum})\n"
                   . "👤 <b>Customer:</b> " . htmlspecialchars($clientName) . " (Client #{$clientId})\n"
                   . "💰 <b>Amount:</b> ₹" . number_format($amount, 2) . " (" . htmlspecialchars($payMethod) . ")\n"
                   . "📄 <b>WHMCS Invoice:</b> #" . ($invoiceId ?: 'N/A') . "\n"
                   . "⚡ <b>Status:</b> {$badge}\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "🏷 <b>Ordered Items:</b>\n"
                   . $summaryText . "\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "👥 <b>Immediate Delivery Action:</b>\n"
                   . ($hasHosting ? "  • 🚀 Hosting: Chandrika (cPanel & Pass Provisioning)\n" : "")
                   . ($hasDomain ? "  • 🌐 Domain: Priya / Sanju (26-Registrars KYC Verification)\n" : "")
                   . ($hasVps ? "  • ⚡ VPS/Server: Root/SSH Provisioning Queue\n" : "")
                   . "👉 <a href=\"{$orderLink}\"><b>OPEN & PROCESS IN WHMCS ADMIN ➔</b></a>";

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
        $orderTs = !empty($ord['order_date']) ? strtotime((string)$ord['order_date']) : strtotime((string)$ord['first_detected_at']);
        $elapsedMins = (int)floor(($nowTs - $orderTs) / 60);
        if ($elapsedMins < 1) $elapsedMins = 1;
        $durationFormatted = self::formatDuration($elapsedMins);
        $orderLink = whmcs_deep_link('order', $orderId);

        $msg = "🚨 <b>MANUAL URGENT ESCALATION FROM MASTER OS</b>\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "⏳ <b>Order #{$orderId} is PENDING for {$durationFormatted}!</b>\n"
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
     * Send Consolidated Master Pending Orders Digest to Telegram (Every 10 Mins)
     * Categorizes pending orders into:
     * - Total Pending Domain Order = ?
     * - Total Hosting Pending Order = ?
     * - VPS / Other Pending Order = ?
     */
    public static function sendConsolidatedPendingDigest(bool $isManual = false): array {
        self::ensureOrdersSchema();
        $pdo = db();
        $nowTs = time();

        $stmt = $pdo->query("SELECT * FROM whmcs_orders_cache WHERE order_status = 'Pending' ORDER BY order_date DESC, id DESC");
        $pendingOrders = $stmt->fetchAll();

        if (empty($pendingOrders)) {
            if ($isManual) {
                $msg = "✅ <b>WHMCS PENDING ORDERS: ALL CLEAR!</b>\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "🎉 <i>Koi bhi Pending Order nahi hai! Sabhi active ho chuke hain.</i>\n\n"
                     . "🌐 Total Pending Domain Order = 0\n"
                     . "🚀 Total Hosting Pending Order = 0\n"
                     . "⚡ VPS / Other Pending Order = 0\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "⏰ <b>Checked:</b> " . date('d M Y, h:i A');
                self::sendTelegramHtml($msg);
            }
            return ['status' => 'ok', 'sent' => false, 'count' => 0, 'message' => 'No pending orders found.'];
        }

        $domainItems = [];
        $hostingItems = [];
        $vpsItems = [];

        foreach ($pendingOrders as $ord) {
            $orderId = (int)$ord['order_id'];
            $orderNum = (string)($ord['order_num'] ?: $orderId);
            $clientName = trim((string)($ord['client_name'] ?: "Client #{$ord['client_id']}"));
            $amount = (float)($ord['amount'] ?? 0);
            $amountFormatted = '₹' . number_format($amount, 2);
            $orderCreatedTs = !empty($ord['order_date']) ? strtotime((string)$ord['order_date']) : strtotime((string)$ord['first_detected_at']);
            $elapsedMins = (int)floor(($nowTs - $orderCreatedTs) / 60);
            if ($elapsedMins < 1) $elapsedMins = 1;
            $durationFormatted = self::formatDuration($elapsedMins);
            $orderLink = whmcs_deep_link('order', $orderId);

            $summary = (string)($ord['lineitems_summary'] ?? '');
            $lines = array_filter(array_map('trim', explode("\n", $summary)));

            $foundCategories = [];

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    $cleanLine = preg_replace('/^•\s*/u', '', $line);
                    $lineLower = strtolower($cleanLine);

                    // Domain detection
                    if (strpos($lineLower, 'domain') !== false) {
                        $domainItems[] = [
                            'order_id' => $orderId,
                            'item' => $cleanLine,
                            'client' => $clientName,
                            'amount' => $amountFormatted,
                            'duration' => $durationFormatted,
                            'link' => $orderLink
                        ];
                        $foundCategories['domain'] = true;
                    }
                    // VPS / Server / Cloud / RDP / Dedicated
                    elseif (preg_match('/vps|server|cloud|rdp|dedicated|kvm/i', $cleanLine)) {
                        $vpsItems[] = [
                            'order_id' => $orderId,
                            'item' => $cleanLine,
                            'client' => $clientName,
                            'amount' => $amountFormatted,
                            'duration' => $durationFormatted,
                            'link' => $orderLink
                        ];
                        $foundCategories['vps'] = true;
                    }
                    // Hosting
                    elseif (strpos($lineLower, 'hosting') !== false || strpos($lineLower, 'cpanel') !== false || strpos($lineLower, 'reseller') !== false) {
                        $hostingItems[] = [
                            'order_id' => $orderId,
                            'item' => $cleanLine,
                            'client' => $clientName,
                            'amount' => $amountFormatted,
                            'duration' => $durationFormatted,
                            'link' => $orderLink
                        ];
                        $foundCategories['hosting'] = true;
                    }
                }
            }

            // Fallback categorization based on boolean flags or generic fallback
            if (empty($foundCategories)) {
                $fallbackItem = "Order #{$orderId} - " . ($summary ?: "WHMCS Service Package");
                if (!empty($ord['has_domain'])) {
                    $domainItems[] = [
                        'order_id' => $orderId,
                        'item' => $fallbackItem,
                        'client' => $clientName,
                        'amount' => $amountFormatted,
                        'duration' => $durationFormatted,
                        'link' => $orderLink
                    ];
                } elseif (!empty($ord['has_vps'])) {
                    $vpsItems[] = [
                        'order_id' => $orderId,
                        'item' => $fallbackItem,
                        'client' => $clientName,
                        'amount' => $amountFormatted,
                        'duration' => $durationFormatted,
                        'link' => $orderLink
                    ];
                } elseif (!empty($ord['has_hosting'])) {
                    $hostingItems[] = [
                        'order_id' => $orderId,
                        'item' => $fallbackItem,
                        'client' => $clientName,
                        'amount' => $amountFormatted,
                        'duration' => $durationFormatted,
                        'link' => $orderLink
                    ];
                } else {
                    $vpsItems[] = [
                        'order_id' => $orderId,
                        'item' => $fallbackItem,
                        'client' => $clientName,
                        'amount' => $amountFormatted,
                        'duration' => $durationFormatted,
                        'link' => $orderLink
                    ];
                }
            }
        }

        $totalDomain = count($domainItems);
        $totalHosting = count($hostingItems);
        $totalVps = count($vpsItems);
        $totalAll = count($pendingOrders);

        // Format Domain Section
        $domainText = "🌐 <b>Total Pending Domain Order = {$totalDomain}</b>\n";
        if ($totalDomain > 0) {
            foreach ($domainItems as $di) {
                $domainText .= "▫️ <b>Order #{$di['order_id']}</b> - {$di['item']}\n"
                             . "   👤 {$di['client']} | 💰 {$di['amount']} | ⏳ Pending: <b>{$di['duration']}</b>\n"
                             . "   👉 <a href=\"{$di['link']}\">Open Order #{$di['order_id']}</a>\n\n";
            }
        } else {
            $domainText .= "<i>(Koi Pending Domain Order Nahi Hai ✅)</i>\n\n";
        }

        // Format Hosting Section
        $hostingText = "🚀 <b>Total Hosting Pending Order = {$totalHosting}</b>\n";
        if ($totalHosting > 0) {
            foreach ($hostingItems as $hi) {
                $hostingText .= "▫️ <b>Order #{$hi['order_id']}</b> - {$hi['item']}\n"
                              . "   👤 {$hi['client']} | 💰 {$hi['amount']} | ⏳ Pending: <b>{$hi['duration']}</b>\n"
                              . "   👉 <a href=\"{$hi['link']}\">Open Order #{$hi['order_id']}</a>\n\n";
            }
        } else {
            $hostingText .= "<i>(Koi Pending Hosting Order Nahi Hai ✅)</i>\n\n";
        }

        // Format VPS/Other Section
        $vpsText = "⚡ <b>VPS / Other Pending Order = {$totalVps}</b>\n";
        if ($totalVps > 0) {
            foreach ($vpsItems as $vi) {
                $vpsText .= "▫️ <b>Order #{$vi['order_id']}</b> - {$vi['item']}\n"
                          . "     👤 {$vi['client']} | 💰 {$vi['amount']} | ⏳ Pending: <b>{$vi['duration']}</b>\n"
                          . "     👉 <a href=\"{$vi['link']}\">Open Order #{$vi['order_id']}</a>\n\n";
            }
        } else {
            $vpsText .= "<i>(Koi Pending VPS/Server Order Nahi Hai ✅)</i>\n\n";
        }

        $whmcsPendingUrl = defined('WHMCS_ADMIN_URL') ? WHMCS_ADMIN_URL . '/orders.php?status=Pending' : 'https://www.ambitionhost.in/user/devokedev/orders.php?status=Pending';

        $msg = "📋 <b>WHMCS PENDING ORDERS MASTER DIGEST</b> (10-Min Escalation)\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
             . $domainText
             . $hostingText
             . $vpsText
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "⏳ <b>Total Active Pending SLA:</b> {$totalAll} Orders\n"
             . "👉 <a href=\"{$whmcsPendingUrl}\"><b>Open WHMCS Pending Orders Queue ➔</b></a>";

        $sendRes = self::sendTelegramHtml($msg);

        // Update last alerted timestamps
        $pdo->exec("UPDATE whmcs_orders_cache SET last_alerted_at = NOW(), alert_count = alert_count + 1 WHERE order_status = 'Pending'");

        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('order_bot_last_digest_at_ts', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([(string)$nowTs]);
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('order_bot_last_digest_at', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")
                ->execute();
        } catch (Throwable $e) {}

        return [
            'status' => $sendRes['status'] ?? 'ok',
            'sent' => true,
            'count' => $totalAll,
            'total_domain' => $totalDomain,
            'total_hosting' => $totalHosting,
            'total_vps' => $totalVps,
            'message' => "Master Pending Digest dispatched ({$totalAll} orders across Domain: {$totalDomain}, Hosting: {$totalHosting}, VPS/Other: {$totalVps})."
        ];
    }

    /**
     * Alert ALL currently pending orders to Telegram via Consolidated Master Digest
     */
    public static function alertAllPendingOrders(): array {
        $res = self::sendConsolidatedPendingDigest(true);
        if (!empty($res['sent'])) {
            return [
                'status' => 'ok',
                'count' => $res['count'],
                'message' => "📋 Master Pending Digest Dispatched to Telegram! (Domain: {$res['total_domain']}, Hosting: {$res['total_hosting']}, VPS/Other: {$res['total_vps']})"
            ];
        }
        return [
            'status' => 'ok',
            'count' => 0,
            'message' => "✅ Sabhi orders active hain! Abhi koi pending order nahi hai."
        ];
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

