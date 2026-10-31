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
        $repeatIntervalMins = (int)get_sys_setting('order_bot_reminder_interval_mins', '15');
        if ($repeatIntervalMins < 5) $repeatIntervalMins = 15;

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

                // Fire Instant Telegram Notification ONLY if order is Pending or placed recently (< 2 hours ago)
                $orderAgeHours = ($nowTs - strtotime($orderDate)) / 3600;
                $shouldSendAlert = ($orderStatus === 'Pending' || $orderAgeHours <= 2);

                if (($botEnabled === '1' || $manualTrigger) && $shouldSendAlert) {
                    $badge = ($orderStatus === 'Pending') ? "⚠️ <b>PENDING PROVISIONING</b>" : "✅ <b>ACTIVE</b>";
                    $tgMsg = ($orderStatus === 'Pending' ? "🚨 <b>NEW PENDING ORDER RECEIVED!</b>\n" : "🛒 <b>NEW WHMCS ORDER RECEIVED!</b>\n")
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
            // CASE 2: EXISTING ORDER STILL PENDING (CONTINUOUS ESCALATION)
            // ==========================================
            else {
                $cached = $cachedOrders[$orderId];
                $cacheRecordId = (int)$cached['id'];

                if ($orderStatus === 'Pending') {
                    $orderCreatedTs = !empty($o['date']) ? strtotime((string)$o['date']) : (!empty($cached['order_date']) ? strtotime((string)$cached['order_date']) : strtotime((string)$cached['first_detected_at']));
                    $lastAlertTs = strtotime((string)$cached['last_alerted_at']);
                    $elapsedMins = (int)floor(($nowTs - $orderCreatedTs) / 60);
                    if ($elapsedMins < 1) $elapsedMins = 1;
                    $durationFormatted = self::formatDuration($elapsedMins);
                    $minsSinceLastAlert = (int)floor(($nowTs - $lastAlertTs) / 60);

                    if (($remindersEnabled === '1' && $minsSinceLastAlert >= $repeatIntervalMins) || $manualTrigger) {
                        $updAlertStmt->execute(['Pending', $cacheRecordId]);

                        $alertCountNext = (int)$cached['alert_count'] + 1;
                        $tgMsg = "⚠️ <b>PENDING ORDER ESCALATION ALERT (#{$alertCountNext})</b>\n"
                               . "━━━━━━━━━━━━━━━━━━━━━━\n"
                               . "⏳ <b>Order #{$orderId} is still PENDING for {$durationFormatted}!</b>\n"
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
                        usleep(350000); // 350ms delay to prevent Telegram 429
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
     * Alert ALL currently pending orders to Telegram with flood-control throttling
     */
    public static function alertAllPendingOrders(): array {
        self::ensureOrdersSchema();
        $pdo = db();
        $pending = $pdo->query("SELECT * FROM whmcs_orders_cache WHERE order_status = 'Pending' ORDER BY order_date DESC")->fetchAll();

        if (empty($pending)) {
            return ['status' => 'ok', 'count' => 0, 'message' => 'No pending orders found to alert.'];
        }

        $sent = 0;
        $failed = 0;
        $lastErr = '';

        foreach ($pending as $ord) {
            $orderId = (int)$ord['order_id'];
            $res = self::sendManualEscalation($orderId);
            if ($res['status'] === 'ok') {
                $sent++;
            } else {
                $failed++;
                $lastErr = $res['message'] ?? 'Failed';
            }
            usleep(500000); // 500ms throttle between orders to respect Telegram limit
        }

        if ($sent > 0) {
            return ['status' => 'ok', 'count' => $sent, 'message' => "🚨 Success! {$sent} Pending Order Alerts dispatched to Telegram!"];
        }
        return ['status' => 'error', 'message' => "Failed to send alerts: " . $lastErr];
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

