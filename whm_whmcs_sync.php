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
    public static function callWhmcs(string $action, array $params = []): array {
        $ident = get_sys_setting('whmcs_api_identifier', defined('WHMCS_API_IDENTIFIER') ? WHMCS_API_IDENTIFIER : '');
        $secret = get_sys_setting('whmcs_api_secret', defined('WHMCS_API_SECRET') ? WHMCS_API_SECRET : '');
        $urlBase = get_sys_setting('whmcs_url', defined('WHMCS_URL') ? WHMCS_URL : 'https://www.ambitionhost.in/user');

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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string)$res, true);
        return $decoded ?: ['result' => 'error', 'raw' => $res];
    }

    /**
     * Sync Active Hosting Products and Domains into whmcs_items_cache
     */
    public static function syncWhmcsItemsCache(int $limit = 500): array {
        $pdo = db();
        $synced = 0;

        // 1. Fetch Hosting Products
        $pRes = self::callWhmcs('GetClientsProducts', ['limitnum' => $limit]);
        if (!empty($pRes['products']['product'])) {
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

            foreach ($pRes['products']['product'] as $p) {
                $ins->execute([
                    (int)($p['id'] ?? 0),
                    (int)($p['clientid'] ?? 0),
                    (string)($p['domain'] ?? ''),
                    (string)($p['name'] ?? ($p['groupname'] ?? 'Hosting Plan')),
                    (string)($p['billingcycle'] ?? 'Monthly'),
                    (float)($p['recurringamount'] ?? 0),
                    (string)($p['status'] ?? 'Active'),
                    !empty($p['regdate']) ? $p['regdate'] : null,
                    !empty($p['nextduedate']) ? $p['nextduedate'] : null
                ]);
                $synced++;
            }
        }

        // 2. Fetch Domains from WHMCS
        $dRes = self::callWhmcs('GetClientsDomains', ['limitnum' => $limit]);
        if (!empty($dRes['domains']['domain'])) {
            $insD = $pdo->prepare("
                INSERT INTO whmcs_items_cache (
                    item_type, whmcs_item_id, client_id, domain, package_name,
                    billing_cycle, recurring_amount, status, reg_date, next_due_date, updated_at
                ) VALUES ('domain', ?, ?, ?, 'Domain Registration', 'Annually', ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    client_id = VALUES(client_id),
                    domain = VALUES(domain),
                    recurring_amount = VALUES(recurring_amount),
                    status = VALUES(status),
                    next_due_date = VALUES(next_due_date),
                    updated_at = NOW()
            ");

            foreach ($dRes['domains']['domain'] as $d) {
                $insD->execute([
                    (int)($d['id'] ?? 0),
                    (int)($d['userid'] ?? 0),
                    (string)($d['domainname'] ?? ''),
                    (float)($d['recurringamount'] ?? 0),
                    (string)($d['status'] ?? 'Active'),
                    !empty($d['registrationdate']) ? $d['registrationdate'] : null,
                    !empty($d['nextduedate']) ? $d['nextduedate'] : null
                ]);
                $synced++;
            }
        }

        // 3. Populate Hosting from WHM Accounts (327 Active Server Accounts from SGP)
        $whmAccounts = $pdo->query("SELECT * FROM whm_accounts")->fetchAll();
        if (!empty($whmAccounts)) {
            $insWhm = $pdo->prepare("
                INSERT INTO whmcs_items_cache (
                    item_type, whmcs_item_id, client_id, domain, package_name,
                    client_email, client_name, billing_cycle, recurring_amount, status, updated_at
                ) VALUES ('hosting', ?, ?, ?, ?, ?, ?, 'Monthly', 0.00, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    domain = VALUES(domain),
                    package_name = VALUES(package_name),
                    client_email = VALUES(client_email),
                    client_name = VALUES(client_name),
                    status = VALUES(status),
                    updated_at = NOW()
            ");

            foreach ($whmAccounts as $wa) {
                $status = !empty($wa['is_suspended']) ? 'Suspended' : 'Active';
                $insWhm->execute([
                    1000000 + (int)$wa['id'],
                    (int)($wa['server_id'] ?: 1),
                    (string)$wa['domain'],
                    (string)($wa['plan_name'] ?: 'Hosting cPanel'),
                    (string)($wa['owner_email'] ?: ''),
                    (string)$wa['cpanel_user'],
                    $status
                ]);
                $synced++;
            }
        }

        return ['status' => 'ok', 'synced_count' => $synced];
    }

    /**
     * Search Across WHMCS & Local Cache by Domain, Email ID, Plan or Client ID
     */
    public static function liveSearchWhmcs(string $query): array {
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
