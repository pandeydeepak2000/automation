<?php
// whm_whmcs_sync.php - AmbitionHost WHM 14-Privilege Live Sync & WHMCS Read-Only Engine
declare(strict_types=1);
require_once __DIR__ . '/config.php';

class AmbitionInfraSync {

    /**
     * Call WHM API v1 with Authorization Token
     */
    public static function callWhmApi(string $hostname, string $username, string $apiToken, string $command, array $params = []): array {
        $host = trim($hostname);
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $host = rtrim($host, '/');
        // Ensure standard WHM port 2087 if not specified
        $parsed = parse_url($host);
        $port = $parsed['port'] ?? 2087;
        $cleanHost = $parsed['scheme'] . '://' . $parsed['host'] . ':' . $port;

        $url = $cleanHost . '/json-api/' . $command . '?api.version=1';
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Allow connection via direct IP or self-signed WHM cert
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: whm ' . trim($username) . ':' . trim($apiToken)
            ]
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['status' => 0, 'error' => "cURL Error ({$url}): {$err}"];
        }

        $decoded = json_decode((string)$response, true);
        if (!$decoded) {
            return ['status' => 0, 'error' => "HTTP {$httpCode}: WHM did not return JSON. (Response: " . substr(strip_tags((string)$response), 0, 150) . ")", 'raw' => $response];
        }

        return $decoded;
    }

    /**
     * 1-Click WHM Connection Test
     */
    public static function testWhmServer(string $hostname, string $username, string $apiToken): array {
        $res = self::callWhmApi($hostname, $username, $apiToken, 'version');
        if (isset($res['version'])) {
            $acctRes = self::callWhmApi($hostname, $username, $apiToken, 'listaccts');
            $accts = $acctRes['data']['acct'] ?? ($acctRes['acct'] ?? []);
            $acctCount = is_array($accts) ? count($accts) : 0;
            return [
                'status' => 'ok',
                'version' => $res['version'],
                'accounts' => $acctCount,
                'message' => "✅ WHM Connected! Version: {$res['version']}, Found {$acctCount} cPanel accounts."
            ];
        }
        $err = $res['error'] ?? ($res['metadata']['reason'] ?? ($res['cpanelresult']['error'] ?? 'Failed to authenticate with WHM API Token'));
        return ['status' => 'error', 'message' => "WHM Error: {$err}"];
    }

    public static function syncSingleServer(int $serverId): array {
        return self::syncWhmServer($serverId);
    }

    /**
     * 14-Privilege Live Sync for a specific WHM Server
     * Syncs: Active/Suspended Accounts, Suspend Reasons, Bandwidth, Disk Usage (>20GB Alert), Load, Services
     */
    public static function syncWhmServer(int $serverId): array {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM whm_servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            throw new Exception("Server with ID #{$serverId} not found");
        }

        $host = $server['hostname'];
        $user = $server['whm_username'] ?: 'root';
        $token = $server['api_token'];
        $maxBackupGb = (int)($server['max_backup_gb'] ?: 20);
        $maxBackupMb = $maxBackupGb * 1024;

        // 1. Fetch Accounts List (listaccts) with strict Error Guard
        $acctRes = self::callWhmApi($host, $user, $token, 'listaccts');
        $accounts = [];
        if (isset($acctRes['data']['acct']) && is_array($acctRes['data']['acct'])) {
            $accounts = $acctRes['data']['acct'];
        } elseif (isset($acctRes['acct']) && is_array($acctRes['acct'])) {
            $accounts = $acctRes['acct'];
        } elseif (!isset($acctRes['metadata']['result']) || (int)$acctRes['metadata']['result'] !== 1) {
            $reason = $acctRes['metadata']['reason'] ?? ($acctRes['error'] ?? ($acctRes['cpanelresult']['error'] ?? 'WHM API listaccts failed'));
            return ['status' => 'error', 'error' => "WHM API Failed: {$reason}"];
        }

        // 2. Fetch System Load Average (systemloadavg)
        $loadRes = self::callWhmApi($host, $user, $token, 'systemloadavg');
        $loadAvg = 'N/A';
        if (isset($loadRes['data']['one'])) {
            $loadAvg = "{$loadRes['data']['one']}, {$loadRes['data']['five']}, {$loadRes['data']['fifteen']}";
        }

        // 3. Fetch Service Status (servicestatus)
        $serviceRes = self::callWhmApi($host, $user, $token, 'servicestatus');
        $serviceJson = null;
        if (!empty($serviceRes['data']['service'])) {
            $services = [];
            foreach ($serviceRes['data']['service'] as $s) {
                $services[$s['name']] = $s['monitored'] ? ($s['running'] ? 'running' : 'down') : 'unmonitored';
            }
            $serviceJson = json_encode($services);
        }

        $total = count($accounts);
        $active = 0;
        $suspended = 0;
        $over20gb = 0;

        $upsertAcct = $pdo->prepare("
            INSERT INTO whm_accounts (
                server_id, cpanel_user, domain, owner_email, plan_name,
                disk_used_mb, disk_limit_mb, bw_used_mb, is_suspended,
                suspend_reason, suspend_time, backup_eligible, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                domain = VALUES(domain),
                owner_email = VALUES(owner_email),
                plan_name = VALUES(plan_name),
                disk_used_mb = VALUES(disk_used_mb),
                disk_limit_mb = VALUES(disk_limit_mb),
                bw_used_mb = VALUES(bw_used_mb),
                is_suspended = VALUES(is_suspended),
                suspend_reason = VALUES(suspend_reason),
                suspend_time = VALUES(suspend_time),
                backup_eligible = VALUES(backup_eligible),
                updated_at = NOW()
        ");

        foreach ($accounts as $a) {
            $cUser = (string)($a['user'] ?? '');
            $domain = (string)($a['domain'] ?? '');
            $email = (string)($a['email'] ?? '');
            $plan = (string)($a['plan'] ?? '');

            // Disk Used MB conversion
            $diskUsedRaw = (string)($a['diskused'] ?? '0');
            $diskUsedMb = (float)preg_replace('/[^0-9.]/', '', $diskUsedRaw);
            if (stripos($diskUsedRaw, 'G') !== false) {
                $diskUsedMb = $diskUsedMb * 1024;
            }

            $diskLimit = (string)($a['disklimit'] ?? 'unlimited');
            $bwUsedMb = (float)($a['bandwidthused'] ?? 0);

            $isSusp = !empty($a['suspended']) ? 1 : 0;
            $suspendReason = $isSusp ? (string)($a['suspendreason'] ?? 'Suspended via WHM') : null;
            $suspendTime = (!empty($a['suspendtime']) && $isSusp) ? date('Y-m-d H:i:s', (int)$a['suspendtime']) : null;

            // Backup eligibility: >20GB excluded from SGP backup rule
            $isEligible = ($diskUsedMb > $maxBackupMb) ? 0 : 1;

            if ($isSusp) {
                $suspended++;
            } else {
                $active++;
            }

            if ($isEligible === 0) {
                $over20gb++;
            }

            $upsertAcct->execute([
                $serverId,
                $cUser,
                $domain,
                $email,
                $plan,
                $diskUsedMb,
                $diskLimit,
                $bwUsedMb,
                $isSusp,
                $suspendReason,
                $suspendTime,
                $isEligible
            ]);
        }

        // Update Server Stats in whm_servers table
        $pdo->prepare("
            UPDATE whm_servers SET
                total_accounts = ?,
                active_accounts = ?,
                suspended_accounts = ?,
                load_avg = ?,
                service_status_json = ?,
                last_synced_at = NOW()
            WHERE id = ?
        ")->execute([$total, $active, $suspended, $loadAvg, $serviceJson, $serverId]);

        return [
            'status' => 'ok',
            'server_id' => $serverId,
            'server_name' => $server['server_name'],
            'total_accounts' => $total,
            'active_accounts' => $active,
            'suspended_accounts' => $suspended,
            'over_20gb_accounts' => $over20gb,
            'load_avg' => $loadAvg,
            'synced_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Run Diagnostics on WHM Server (Packages, SSL, Mail, Services)
     */
    public static function fetchWhmDiagnostic(int $serverId, string $type, string $target = ''): array {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM whm_servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            throw new Exception("Server not found");
        }

        $host = $server['hostname'];
        $user = $server['whm_username'] ?: 'root';
        $token = $server['api_token'];

        switch ($type) {
            case 'packages':
                $res = self::callWhmApi($host, $user, $token, 'listpkgs');
                return ['status' => 'ok', 'type' => 'packages', 'data' => $res['data']['package'] ?? []];

            case 'services':
                $res = self::callWhmApi($host, $user, $token, 'servicestatus');
                return ['status' => 'ok', 'type' => 'services', 'data' => $res['data']['service'] ?? []];

            case 'ssl':
                $res = self::callWhmApi($host, $user, $token, 'fetch_ssl_certificates_for_fqdns');
                return ['status' => 'ok', 'type' => 'ssl', 'data' => $res['data'] ?? []];

            case 'email':
                $res = self::callWhmApi($host, $user, $token, 'emailtrack_search', ['search' => $target ?: 'ambitionhost']);
                return ['status' => 'ok', 'type' => 'email', 'data' => $res['data'] ?? []];

            default:
                return ['status' => 'error', 'message' => "Unknown diagnostic type: {$type}"];
        }
    }

    /**
     * WHMCS Read-Only API Caller (Uses WHMCS_API_IDENTIFIER & WHMCS_API_SECRET)
     */
    public static function callWhmcs(string $action, array $params = [], string $customIdent = '', string $customSecret = '', string $customUrl = ''): array {
        $ident = trim((string)($customIdent ?: get_sys_setting('whmcs_api_identifier', defined('WHMCS_API_IDENTIFIER') ? WHMCS_API_IDENTIFIER : '')));
        $secret = trim((string)($customSecret ?: get_sys_setting('whmcs_api_secret', defined('WHMCS_API_SECRET') ? WHMCS_API_SECRET : '')));
        $urlBase = trim((string)($customUrl ?: get_sys_setting('whmcs_url', defined('WHMCS_URL') ? WHMCS_URL : 'https://www.ambitionhost.in/user')));

        if (empty($ident) || strpos($ident, 'YOUR_') === 0) {
            return ['result' => 'error', 'message' => 'WHMCS Read-Only API Identifier not configured in settings or config.php'];
        }

        $url = rtrim($urlBase, '/') . '/includes/api.php';
        $postData = array_merge([
            'action' => $action,
            'identifier' => $ident,
            'secret' => $secret,
            'responsetype' => 'json'
        ], $params);

        // Fallback: If identifier is a staff username (e.g. controlos / admin), also pass username & password for legacy WHMCS auth
        if (strlen($ident) <= 24 && strpos($ident, ' ') === false) {
            $postData['username'] = $ident;
            $postData['password'] = $secret;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AmbitionHostMasterOS/2.0',
            CURLOPT_USERPWD => $ident . ':' . $secret
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string)$res, true);
        return $decoded ?: ['result' => 'error', 'raw' => $res];
    }

    /**
     * Self-healing: Ensure whmcs_items_cache exists and has client_email and client_name columns
     */
    public static function ensureWhmcsCacheColumns(): void {
        $pdo = db();
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `whmcs_items_cache` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `item_type` enum('hosting','domain') NOT NULL DEFAULT 'hosting',
                  `whmcs_item_id` int(11) NOT NULL,
                  `client_id` int(11) NOT NULL DEFAULT 0,
                  `domain` varchar(255) DEFAULT NULL,
                  `package_name` varchar(150) DEFAULT NULL,
                  `client_email` varchar(150) NOT NULL DEFAULT '',
                  `client_name` varchar(150) NOT NULL DEFAULT '',
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Inspect columns on live table
            $cols = [];
            $res = $pdo->query("SHOW COLUMNS FROM `whmcs_items_cache`");
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower((string)($row['Field'] ?? ''));
                }
            }

            if (!in_array('client_email', $cols, true)) {
                $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD `client_email` varchar(150) NOT NULL DEFAULT ''");
            }
            if (!in_array('client_name', $cols, true)) {
                $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD `client_name` varchar(150) NOT NULL DEFAULT ''");
            }
            try {
                $pdo->exec("ALTER TABLE `whmcs_items_cache` ADD INDEX `email_idx` (`client_email`)");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            error_log("whmcs_items_cache schema auto-heal: " . $e->getMessage());
        }
    }

    /**
     * Sync Active Hosting Products and Domains into whmcs_items_cache with Pagination & Real Client Matching
     */
    public static function syncWhmcsItemsCache(int $limit = 500): array {
        self::ensureWhmcsCacheColumns();
        $pdo = db();
        $synced = 0;

        // 1. Fetch Hosting Products from WHMCS with Pagination
        try {
            $ins = $pdo->prepare("
                INSERT INTO whmcs_items_cache (
                    item_type, whmcs_item_id, client_id, domain, package_name,
                    billing_cycle, recurring_amount, status, reg_date, next_due_date, updated_at
                ) VALUES ('hosting', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    client_id = VALUES(client_id),
                    domain = VALUES(domain),
                    package_name = VALUES(package_name),
                    billing_cycle = VALUES(billing_cycle),
                    recurring_amount = VALUES(recurring_amount),
                    status = VALUES(status),
                    next_due_date = VALUES(next_due_date),
                    updated_at = NOW()
            ");

            $page = 0;
            $perPage = 100;
            do {
                $pRes = self::callWhmcs('GetClientsProducts', [
                    'limitstart' => $page * $perPage,
                    'limitnum'   => $perPage
                ]);

                if (empty($pRes['products']['product']) && $page === 0) {
                    $pRes = self::callWhmcs('GetClientsProducts', [
                        'status'     => 'Active',
                        'limitstart' => 0,
                        'limitnum'   => $perPage
                    ]);
                }

                if (empty($pRes['products']['product'])) {
                    break;
                }

                $products = $pRes['products']['product'];
                foreach ($products as $p) {
                    $cid = (int)($p['clientid'] ?? 0);
                    $dom = trim((string)($p['domain'] ?? ''));
                    $pkg = (string)($p['name'] ?? ($p['groupname'] ?? 'Hosting Package'));
                    $cycle = (string)($p['billingcycle'] ?? 'Monthly');
                    $amt = (float)($p['recurringamount'] ?? 0);
                    $st = (string)($p['status'] ?? 'Active');
                    $reg = !empty($p['regdate']) ? $p['regdate'] : null;
                    $due = !empty($p['nextduedate']) ? $p['nextduedate'] : null;

                    $ins->execute([
                        (int)($p['id'] ?? 0),
                        $cid,
                        $dom,
                        $pkg,
                        $cycle,
                        $amt,
                        $st,
                        $reg,
                        $due
                    ]);
                    $synced++;
                }

                $total = (int)($pRes['totalresults'] ?? 0);
                $returned = (int)($pRes['numreturned'] ?? count($products));
                $page++;
            } while ($returned >= $perPage && ($page * $perPage) < $total && $page < 30);
        } catch (Throwable $e) {
            error_log("WHMCS GetClientsProducts error: " . $e->getMessage());
        }

        // 2. Fetch ALL Managed Domains from WHMCS with Pagination
        try {
            $insD = $pdo->prepare("
                INSERT INTO whmcs_items_cache (
                    item_type, whmcs_item_id, client_id, domain, package_name,
                    billing_cycle, recurring_amount, status, reg_date, next_due_date, updated_at
                ) VALUES ('domain', ?, ?, ?, 'Domain Registration', ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    client_id = VALUES(client_id),
                    domain = VALUES(domain),
                    billing_cycle = VALUES(billing_cycle),
                    recurring_amount = VALUES(recurring_amount),
                    status = VALUES(status),
                    next_due_date = VALUES(next_due_date),
                    updated_at = NOW()
            ");

            $page = 0;
            $perPage = 100;
            do {
                $dRes = self::callWhmcs('GetClientsDomains', [
                    'limitstart' => $page * $perPage,
                    'limitnum'   => $perPage
                ]);

                if (empty($dRes['domains']['domain'])) {
                    break;
                }

                $domains = $dRes['domains']['domain'];
                foreach ($domains as $d) {
                    $cid = (int)($d['userid'] ?? 0);
                    $domName = trim((string)($d['domainname'] ?? ''));
                    $amt = (float)($d['recurringamount'] ?? ($d['firstpaymentamount'] ?? 0));
                    $cycle = !empty($d['regperiod']) ? "{$d['regperiod']} Year(s)" : 'Annually';
                    $st = (string)($d['status'] ?? 'Active');
                    $reg = !empty($d['registrationdate']) ? $d['registrationdate'] : null;
                    $due = !empty($d['nextduedate']) ? $d['nextduedate'] : (!empty($d['expirydate']) ? $d['expirydate'] : null);

                    $insD->execute([
                        (int)($d['id'] ?? 0),
                        $cid,
                        $domName,
                        $cycle,
                        $amt,
                        $st,
                        $reg,
                        $due
                    ]);
                    $synced++;
                }

                $total = (int)($dRes['totalresults'] ?? 0);
                $returned = (int)($dRes['numreturned'] ?? count($domains));
                $page++;
            } while ($returned >= $perPage && ($page * $perPage) < $total && $page < 40);
        } catch (Throwable $e) {
            error_log("WHMCS GetClientsDomains error: " . $e->getMessage());
        }

        // 3. Populate WHM Server Accounts and match to Real WHMCS Clients & Pricing
        try {
            $whmAccounts = $pdo->query("SELECT * FROM whm_accounts")->fetchAll();
            if (empty($whmAccounts)) {
                $srv = $pdo->query("SELECT id FROM whm_servers ORDER BY id ASC LIMIT 1")->fetch();
                if ($srv) {
                    self::syncWhmServer((int)$srv['id']);
                    $whmAccounts = $pdo->query("SELECT * FROM whm_accounts")->fetchAll();
                }
            }

            if (!empty($whmAccounts)) {
                $insWhm = $pdo->prepare("
                    INSERT INTO whmcs_items_cache (
                        item_type, whmcs_item_id, client_id, domain, package_name,
                        client_email, client_name, billing_cycle, recurring_amount, status, updated_at
                    ) VALUES ('hosting', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        client_id = VALUES(client_id),
                        domain = VALUES(domain),
                        package_name = VALUES(package_name),
                        client_email = VALUES(client_email),
                        client_name = VALUES(client_name),
                        billing_cycle = VALUES(billing_cycle),
                        recurring_amount = VALUES(recurring_amount),
                        status = VALUES(status),
                        updated_at = NOW()
                ");

                // Pre-map cached items by domain
                $domainMap = [];
                $cachedItems = $pdo->query("SELECT domain, client_id, package_name, billing_cycle, recurring_amount, next_due_date, client_email, client_name FROM whmcs_items_cache")->fetchAll();
                foreach ($cachedItems as $ci) {
                    if (!empty($ci['domain'])) {
                        $domainMap[strtolower(trim($ci['domain']))] = $ci;
                    }
                }

                foreach ($whmAccounts as $wa) {
                    $domainLower = strtolower(trim($wa['domain']));
                    $status = !empty($wa['is_suspended']) ? 'Suspended' : 'Active';
                    $ownerEmail = (string)($wa['owner_email'] ?: '');
                    $cUser = (string)$wa['cpanel_user'];
                    $planName = (string)($wa['plan_name'] ?: 'Hosting cPanel');

                    $realClientId = 0;
                    $realPkg = $planName;
                    $realCycle = 'Monthly';
                    $realAmount = 0.00;
                    $clientName = $cUser;

                    // A. Check if already matched in WHMCS cache by domain
                    if (isset($domainMap[$domainLower])) {
                        $matched = $domainMap[$domainLower];
                        $realClientId = (int)$matched['client_id'];
                        $realAmount = (float)$matched['recurring_amount'];
                        $realCycle = (string)($matched['billing_cycle'] ?: 'Monthly');
                        $realPkg = (string)($matched['package_name'] ?: $planName);
                        if (!empty($matched['client_name'])) {
                            $clientName = $matched['client_name'];
                        }
                    }

                    // B. If not matched, try WHMCS lookup by domain
                    if ($realClientId === 0 && !empty($domainLower)) {
                        try {
                            $lookup = self::callWhmcs('GetClientsProducts', ['domain' => $wa['domain']]);
                            if (!empty($lookup['products']['product'][0])) {
                                $lp = $lookup['products']['product'][0];
                                $realClientId = (int)($lp['clientid'] ?? 0);
                                $realAmount = (float)($lp['recurringamount'] ?? 0);
                                $realCycle = (string)($lp['billingcycle'] ?? 'Monthly');
                                $realPkg = (string)($lp['name'] ?? ($lp['groupname'] ?? $planName));
                            }
                        } catch (Throwable $e) {}
                    }

                    // C. If still not matched, try WHMCS client lookup by owner_email
                    if ($realClientId === 0 && !empty($ownerEmail) && strpos($ownerEmail, '@') !== false) {
                        try {
                            $cLookup = self::callWhmcs('GetClients', ['search' => $ownerEmail, 'limitnum' => 1]);
                            if (!empty($cLookup['clients']['client'][0])) {
                                $cl = $cLookup['clients']['client'][0];
                                $realClientId = (int)($cl['id'] ?? 0);
                                $clientName = trim(($cl['firstname'] ?? '') . ' ' . ($cl['lastname'] ?? ''));
                            }
                        } catch (Throwable $e) {}
                    }

                    // D. If recurring amount is 0 and client ID found, check their latest invoice
                    if ($realClientId > 0 && $realAmount <= 0) {
                        try {
                            $invLookup = self::callWhmcs('GetInvoices', ['userid' => $realClientId, 'limitnum' => 1, 'status' => 'Paid']);
                            if (!empty($invLookup['invoices']['invoice'][0])) {
                                $realAmount = (float)($invLookup['invoices']['invoice'][0]['total'] ?? 0);
                            }
                        } catch (Throwable $e) {}
                    }

                    $insWhm->execute([
                        1000000 + (int)$wa['id'],
                        $realClientId, // REAL WHMCS CLIENT ID (or 0 if unlinked), NEVER fake 1!
                        (string)$wa['domain'],
                        $realPkg,
                        $ownerEmail,
                        $clientName,
                        $realCycle,
                        $realAmount,
                        $status
                    ]);
                    $synced++;
                }
            }
        } catch (Throwable $e) {
            error_log("WHM Accounts cache sync error: " . $e->getMessage());
        }

        // 4. Batch enrich client names and emails for client_ids
        try {
            self::enrichClientDetails();
        } catch (Throwable $e) {}

        // 5. Sync Invoices from WHMCS
        try {
            self::syncWhmcsInvoices(100);
        } catch (Throwable $e) {}

        return ['status' => 'ok', 'synced_count' => $synced];
    }

    /**
     * Batch enrich client name and email for cached items
     */
    public static function enrichClientDetails(): void {
        $pdo = db();
        $missing = $pdo->query("
            SELECT DISTINCT client_id 
            FROM whmcs_items_cache 
            WHERE client_id > 0 AND (client_name = '' OR client_name = 'Ambition Client' OR client_email = '')
            LIMIT 25
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($missing)) return;

        $upd = $pdo->prepare("
            UPDATE whmcs_items_cache 
            SET client_name = ?, client_email = ? 
            WHERE client_id = ? AND (client_name = '' OR client_name = 'Ambition Client' OR client_email = '')
        ");

        foreach ($missing as $cid) {
            $cRes = self::callWhmcs('GetClientsDetails', ['clientid' => (int)$cid]);
            if (!empty($cRes['client'])) {
                $cl = $cRes['client'];
                $fullName = trim(($cl['firstname'] ?? '') . ' ' . ($cl['lastname'] ?? ''));
                if (!empty($cl['companyname'])) {
                    $fullName .= ' (' . $cl['companyname'] . ')';
                }
                $email = (string)($cl['email'] ?? '');
                $upd->execute([$fullName ?: 'Ambition Client', $email, (int)$cid]);
            }
        }
    }

    /**
     * Sync latest WHMCS Invoices into payment_records
     */
    public static function syncWhmcsInvoices(int $limit = 100): int {
        $pdo = db();
        $synced = 0;
        try {
            $res = self::callWhmcs('GetInvoices', [
                'limitnum' => $limit,
                'orderby' => 'id',
                'order' => 'desc'
            ]);

            if (!empty($res['invoices']['invoice'])) {
                $check = $pdo->prepare("SELECT id FROM payment_records WHERE whmcs_invoice_id = ? LIMIT 1");
                $ins = $pdo->prepare("
                    INSERT INTO payment_records (
                        whmcs_invoice_id, customer_name, customer_email, amount,
                        payment_source, whmcs_invoice_status, sanju_verified, service_delivered, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $upd = $pdo->prepare("
                    UPDATE payment_records 
                    SET customer_name = ?, customer_email = ?, amount = ?, whmcs_invoice_status = ?, sanju_verified = ?
                    WHERE id = ?
                ");

                foreach ($res['invoices']['invoice'] as $inv) {
                    $invId = (int)$inv['id'];
                    $cName = trim(($inv['firstname'] ?? '') . ' ' . ($inv['lastname'] ?? ''));
                    $cEmail = (string)($inv['email'] ?? '');
                    $amt = (float)($inv['total'] ?? 0);
                    $status = (string)($inv['status'] ?? 'Unpaid');
                    $sanjuVer = ($status === 'Paid') ? 1 : 0;
                    $date = !empty($inv['date']) ? $inv['date'] . ' 00:00:00' : date('Y-m-d H:i:s');
                    $source = (!empty($inv['paymentmethod']) && stripos($inv['paymentmethod'], 'razor') !== false) ? 'Razorpay' : 'Bank_Transfer';

                    $check->execute([$invId]);
                    $existingId = $check->fetchColumn();

                    if ($existingId) {
                        $upd->execute([$cName, $cEmail, $amt, $status, $sanjuVer, $existingId]);
                    } else {
                        $ins->execute([$invId, $cName, $cEmail, $amt, $source, $status, $sanjuVer, 1, $date]);
                    }
                    $synced++;
                }
            }
        } catch (Throwable $e) {
            error_log("syncWhmcsInvoices error: " . $e->getMessage());
        }
        return $synced;
    }

    /**
     * Search Across WHMCS & Local Cache by Domain, Email ID, Plan or Client ID
     */
    public static function liveSearchWhmcs(string $query): array {
        self::ensureWhmcsCacheColumns();
        $pdo = db();
        $q = trim($query);
        if ($q === '') return [];

        // 1. Search local cache
        $stmt = $pdo->prepare("
            SELECT * FROM whmcs_items_cache 
            WHERE domain LIKE ? 
               OR client_email LIKE ? 
               OR client_name LIKE ? 
               OR package_name LIKE ? 
               OR client_id = ? 
            ORDER BY id DESC LIMIT 50
        ");
        $stmt->execute(["%$q%", "%$q%", "%$q%", "%$q%", (int)$q]);
        $rows = $stmt->fetchAll();

        // 2. If query looks like email or domain, perform on-demand WHMCS API lookup
        if (empty($rows) || strpos($q, '@') !== false) {
            $cRes = self::callWhmcs('GetClients', ['search' => $q, 'limitnum' => 5]);
            if (!empty($cRes['clients']['client'])) {
                foreach ($cRes['clients']['client'] as $cl) {
                    $cid = (int)$cl['id'];
                    $cEmail = (string)($cl['email'] ?? '');
                    $cName = trim(($cl['firstname'] ?? '') . ' ' . ($cl['lastname'] ?? ''));

                    // Fetch this client's products
                    $pRes = self::callWhmcs('GetClientsProducts', ['clientid' => $cid]);
                    if (!empty($pRes['products']['product'])) {
                        $ins = $pdo->prepare("
                            INSERT INTO whmcs_items_cache (
                                item_type, whmcs_item_id, client_id, client_email, client_name,
                                domain, package_name, billing_cycle, recurring_amount, status, next_due_date, updated_at
                            ) VALUES ('hosting', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                client_email = VALUES(client_email),
                                client_name = VALUES(client_name),
                                domain = VALUES(domain),
                                status = VALUES(status),
                                next_due_date = VALUES(next_due_date),
                                updated_at = NOW()
                        ");
                        foreach ($pRes['products']['product'] as $p) {
                            $ins->execute([
                                (int)($p['id'] ?? 0),
                                $cid,
                                $cEmail,
                                $cName,
                                (string)($p['domain'] ?? ''),
                                (string)($p['name'] ?? 'Hosting Package'),
                                (string)($p['billingcycle'] ?? 'Monthly'),
                                (float)($p['recurringamount'] ?? 0),
                                (string)($p['status'] ?? 'Active'),
                                !empty($p['nextduedate']) ? $p['nextduedate'] : null
                            ]);
                        }
                    }

                    // Fetch this client's domains
                    $dRes = self::callWhmcs('GetClientsDomains', ['clientid' => $cid]);
                    if (!empty($dRes['domains']['domain'])) {
                        $insD = $pdo->prepare("
                            INSERT INTO whmcs_items_cache (
                                item_type, whmcs_item_id, client_id, client_email, client_name,
                                domain, package_name, billing_cycle, recurring_amount, status, next_due_date, updated_at
                            ) VALUES ('domain', ?, ?, ?, ?, ?, 'Domain Registration', 'Annually', ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                client_email = VALUES(client_email),
                                client_name = VALUES(client_name),
                                domain = VALUES(domain),
                                status = VALUES(status),
                                next_due_date = VALUES(next_due_date),
                                updated_at = NOW()
                        ");
                        foreach ($dRes['domains']['domain'] as $d) {
                            $insD->execute([
                                (int)($d['id'] ?? 0),
                                $cid,
                                $cEmail,
                                $cName,
                                (string)($d['domainname'] ?? ''),
                                (float)($d['recurringamount'] ?? 0),
                                (string)($d['status'] ?? 'Active'),
                                !empty($d['nextduedate']) ? $d['nextduedate'] : null
                            ]);
                        }
                    }
                }

                // Re-query local cache with fresh synced items
                $stmt->execute(["%$q%", "%$q%", "%$q%", "%$q%", (int)$q]);
                $rows = $stmt->fetchAll();
            }
        }

        return $rows;
    }
}

// Global Procedural Helper for WHMCS API Calls
function call_whmcs_api(string $action, array $params = []): array {
    return AmbitionInfraSync::callWhmcs($action, $params);
}

function get_server_public_ip(): string {
    static $cachedIp = null;
    if ($cachedIp !== null) return $cachedIp;
    $ip = @file_get_contents('https://api.ipify.org');
    if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
        return $cachedIp = trim($ip);
    }
    return $cachedIp = ($_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()) ?: '190.92.174.190'));
}
