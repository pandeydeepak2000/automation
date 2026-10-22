<?php
// analytics_engine.php - AmbitionHost WHMCS Analytics & MRR Intelligence Engine
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whm_whmcs_sync.php';

$pdo = db();
$currentUser = $_SESSION['user_name'] ?? 'Aarav';
$syncMessage = '';
$syncError = '';

// FIX 3 & FIX 4: POST-only WHMCS Sync with Strict Error Guard (No ?seed_demo, No TRUNCATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sync_whmcs_action'] ?? '') === '1') {
    try {
        // 1. Fetch Hosting Services from WHMCS
        $prodRes = AmbitionInfraSync::callWhmcs('GetClientsProducts', ['limitnum' => 5000]);
        if (($prodRes['result'] ?? '') !== 'success') {
            $err = $prodRes['message'] ?? 'WHMCS GetClientsProducts API call failed';
            throw new Exception("WHMCS Product Sync Error: {$err}");
        }

        // 2. Fetch Domains from WHMCS
        $domRes = AmbitionInfraSync::callWhmcs('GetClientsDomains', ['limitnum' => 5000]);
        if (($domRes['result'] ?? '') !== 'success') {
            $err = $domRes['message'] ?? 'WHMCS GetClientsDomains API call failed';
            throw new Exception("WHMCS Domain Sync Error: {$err}");
        }

        // Safe upsert without truncate
        $upsert = $pdo->prepare("
            INSERT INTO whmcs_items_cache (
                item_type, whmcs_item_id, client_id, domain, package_name,
                billing_cycle, recurring_amount, status, reg_date, next_due_date, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                domain = VALUES(domain),
                package_name = VALUES(package_name),
                billing_cycle = VALUES(billing_cycle),
                recurring_amount = VALUES(recurring_amount),
                status = VALUES(status),
                reg_date = VALUES(reg_date),
                next_due_date = VALUES(next_due_date),
                updated_at = NOW()
        ");

        $syncedServices = 0;
        $products = $prodRes['products']['product'] ?? [];
        foreach ($products as $p) {
            $upsert->execute([
                'hosting',
                (int)$p['id'],
                (int)$p['clientid'],
                $p['domain'] ?? '',
                $p['name'] ?? 'Hosting Package',
                $p['billingcycle'] ?? 'Monthly',
                (float)($p['recurringamount'] ?? 0),
                $p['status'] ?? 'Active',
                !empty($p['regdate']) ? $p['regdate'] : null,
                !empty($p['nextduedate']) ? $p['nextduedate'] : null
            ]);
            $syncedServices++;
        }

        $syncedDomains = 0;
        $domains = $domRes['domains']['domain'] ?? [];
        foreach ($domains as $d) {
            $upsert->execute([
                'domain',
                (int)$d['id'],
                (int)$d['userid'],
                $d['domainname'] ?? '',
                'Domain Registration',
                $d['registrationperiod'] ? "{$d['registrationperiod']} Year" : 'Annually',
                (float)($d['recurringamount'] ?? 0),
                $d['status'] ?? 'Active',
                !empty($d['registrationdate']) ? $d['registrationdate'] : null,
                !empty($d['nextduedate']) ? $d['nextduedate'] : null
            ]);
            $syncedDomains++;
        }

        // FIX 6: Calculate Normalized MRR (Annually/12, Biennially/24, Triennially/36, Semi-Annually/6, Quarterly/3)
        $mrrStmt = $pdo->query("
            SELECT SUM(
                CASE 
                    WHEN billing_cycle = 'Monthly' THEN recurring_amount
                    WHEN billing_cycle = 'Quarterly' THEN recurring_amount / 3
                    WHEN billing_cycle = 'Semi-Annually' THEN recurring_amount / 6
                    WHEN billing_cycle = 'Annually' THEN recurring_amount / 12
                    WHEN billing_cycle = 'Biennially' THEN recurring_amount / 24
                    WHEN billing_cycle = 'Triennially' THEN recurring_amount / 36
                    ELSE 0
                END
            ) AS normalized_mrr
            FROM whmcs_items_cache
            WHERE status = 'Active' AND item_type = 'hosting'
        ");
        $calcMrr = (float)($mrrStmt->fetchColumn() ?: 0);

        // Record Daily Historical Snapshot (Only reached if both WHMCS API calls succeeded)
        $today = date('Y-m-d');
        $totalClients = (int)$pdo->query("SELECT COUNT(DISTINCT client_id) FROM whmcs_items_cache")->fetchColumn();
        $actServ = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type='hosting' AND status='Active'")->fetchColumn();
        $actDom  = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type='domain' AND status='Active'")->fetchColumn();

        $pdo->prepare("
            INSERT INTO whmcs_daily_snapshots (snapshot_date, total_clients, active_services, active_domains, mrr_inr)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_clients = VALUES(total_clients),
                active_services = VALUES(active_services),
                active_domains = VALUES(active_domains),
                mrr_inr = VALUES(mrr_inr)
        ")->execute([$today, $totalClients, $actServ, $actDom, $calcMrr]);

        $syncMessage = "✅ WHMCS Sync Complete: {$syncedServices} Services & {$syncedDomains} Domains synchronized securely!";
    } catch (Throwable $e) {
        $syncError = "⚠️ Sync Interrupted (Snapshots protected): " . $e->getMessage();
    }
}

// FIX 5: compare_periods() with Exact 7-Day & Fair MTD Comparisons + Zero-Base Fix
function compare_periods(PDO $pdo, string $metric = 'services'): array {
    $table = 'whmcs_items_cache';
    $whereType = ($metric === 'domains') ? "item_type='domain'" : "item_type='hosting'";

    // 1. Exact 7 Days (Week vs Week: 7 days each)
    $curr_start = date('Y-m-d', strtotime('-6 days'));
    $curr_end   = date('Y-m-d');
    $prev_start = date('Y-m-d', strtotime('-13 days'));
    $prev_end   = date('Y-m-d', strtotime('-7 days'));

    $q7Curr = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereType} AND reg_date BETWEEN ? AND ?");
    $q7Curr->execute([$curr_start, $curr_end]);
    $wCurr = (int)$q7Curr->fetchColumn();

    $q7Prev = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereType} AND reg_date BETWEEN ? AND ?");
    $q7Prev->execute([$prev_start, $prev_end]);
    $wPrev = (int)$q7Prev->fetchColumn();

    // 2. Fair Month-to-Date (MTD: Day 1 to Day d of current vs last month)
    $curDay = date('d');
    $mtd_curr_start = date('Y-m-01');
    $mtd_curr_end   = date('Y-m-d');
    $mtd_prev_start = date('Y-m-01', strtotime('first day of last month'));
    $mtd_prev_end   = date('Y-m-' . $curDay, strtotime('first day of last month'));

    $qMtdCurr = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereType} AND reg_date BETWEEN ? AND ?");
    $qMtdCurr->execute([$mtd_curr_start, $mtd_curr_end]);
    $mCurr = (int)$qMtdCurr->fetchColumn();

    $qMtdPrev = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereType} AND reg_date BETWEEN ? AND ?");
    $qMtdPrev->execute([$mtd_prev_start, $mtd_prev_end]);
    $mPrev = (int)$qMtdPrev->fetchColumn();

    // Helper: Zero Base Formatter
    $calcGrowth = function(int $curr, int $prev): string {
        if ($prev === 0) {
            return $curr > 0 ? "New (+{$curr})" : "0%";
        }
        $pct = round((($curr - $prev) / $prev) * 100, 1);
        return ($pct >= 0 ? "+{$pct}%" : "{$pct}%");
    };

    return [
        'w_curr' => $wCurr, 'w_prev' => $wPrev, 'w_growth' => $calcGrowth($wCurr, $wPrev),
        'm_curr' => $mCurr, 'm_prev' => $mPrev, 'm_growth' => $calcGrowth($mCurr, $mPrev),
    ];
}

$compServices = compare_periods($pdo, 'services');
$compDomains  = compare_periods($pdo, 'domains');

// FIX 6: Fetch MRR normalized figure
$mrrData = $pdo->query("
    SELECT SUM(
        CASE 
            WHEN billing_cycle = 'Monthly' THEN recurring_amount
            WHEN billing_cycle = 'Quarterly' THEN recurring_amount / 3
            WHEN billing_cycle = 'Semi-Annually' THEN recurring_amount / 6
            WHEN billing_cycle = 'Annually' THEN recurring_amount / 12
            WHEN billing_cycle = 'Biennially' THEN recurring_amount / 24
            WHEN billing_cycle = 'Triennially' THEN recurring_amount / 36
            ELSE 0
        END
    ) AS mrr,
    COUNT(*) as active_services
    FROM whmcs_items_cache
    WHERE status = 'Active' AND item_type = 'hosting'
")->fetch();
$activeMRR = (float)($mrrData['mrr'] ?? 0);
$activeServicesCount = (int)($mrrData['active_services'] ?? 0);

// FIX 6: 12-Month Cohort Data (Monthly Cohort Registrations vs Retained Active Today)
$cohortQuery = $pdo->query("
    SELECT 
        DATE_FORMAT(reg_date, '%Y-%m') AS cohort_month,
        COUNT(*) AS total_registered,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS retained_active
    FROM whmcs_items_cache
    WHERE reg_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND reg_date IS NOT NULL
    GROUP BY cohort_month
    ORDER BY cohort_month ASC
");
$cohorts = $cohortQuery->fetchAll();

// Daily Historical Snapshots (Last 14 Days)
$snapshots = $pdo->query("SELECT * FROM whmcs_daily_snapshots ORDER BY snapshot_date DESC LIMIT 14")->fetchAll();
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AmbitionHost OS · Analytics & MRR Intelligence</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;margin:0;padding:24px}
  .wrap{max-width:1300px;margin:auto}
  header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:24px;border-bottom:1px solid #334155;padding-bottom:16px}
  .grid4{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:24px}
  .box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px}
  .stat-val{font-size:28px;font-weight:800;color:#38bdf8;margin:8px 0 4px}
  .badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700}
  .badge-green{background:#065f46;color:#6ee7b7}
  .badge-blue{background:#1e3a8a;color:#93c5fd}
  table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #334155}
  th{color:#94a3b8;font-size:11px;text-transform:uppercase}
  button{background:#2563eb;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer}
  button:hover{background:#1d4ed8}
  a.nav{color:#38bdf8;text-decoration:none;font-size:13px;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <a href="index.php" class="nav">← Return to Master Dashboard</a>
      <h1 style="margin:6px 0 0">📊 WHMCS Business Analytics & Normalized MRR</h1>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <!-- FIX 3: POST button for safe sync (No GET ?sync=1, No seed_demo) -->
      <form method="post" style="margin:0">
        <input type="hidden" name="sync_whmcs_action" value="1">
        <button type="submit">🔄 Sync WHMCS Live Cache (POST)</button>
      </form>
    </div>
  </header>

  <?php if ($syncMessage): ?>
    <div style="background:rgba(16,185,129,.15);border:1px solid #10b981;color:#6ee7b7;padding:12px;border-radius:8px;margin-bottom:16px">
      <?=$syncMessage?>
    </div>
  <?php endif; ?>

  <?php if ($syncError): ?>
    <div style="background:rgba(239,68,68,.15);border:1px solid #ef4444;color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:16px">
      <?=$syncError?>
    </div>
  <?php endif; ?>

  <!-- METRIC CARDS -->
  <div class="grid4">
    <!-- Normalized MRR Card (FIX 6) -->
    <div class="box">
      <small style="color:#94a3b8">Normalized Monthly Recurring Revenue (MRR)</small>
      <div class="stat-val">₹<?=number_format($activeMRR, 2)?></div>
      <small style="color:#cbd5e1"><?=$activeServicesCount?> Active Hosting Accounts</small>
    </div>

    <!-- 7-Day Exact Comparison (FIX 5) -->
    <div class="box">
      <small style="color:#94a3b8">7-Day New Signups (Week vs Week)</small>
      <div class="stat-val"><?=$compServices['w_curr']?> <span class="badge badge-green"><?=$compServices['w_growth']?></span></div>
      <small style="color:#cbd5e1">Prev 7 Days: <?=$compServices['w_prev']?> accounts</small>
    </div>

    <!-- Fair Month-to-Date Comparison (FIX 5) -->
    <div class="box">
      <small style="color:#94a3b8">Fair Month-to-Date (MTD Signup Comparison)</small>
      <div class="stat-val"><?=$compServices['m_curr']?> <span class="badge badge-blue"><?=$compServices['m_growth']?></span></div>
      <small style="color:#cbd5e1">Last Month (Day 1 to <?=date('d')?>): <?=$compServices['m_prev']?></small>
    </div>

    <!-- Domain Registrations (FIX 5) -->
    <div class="box">
      <small style="color:#94a3b8">Domains 7-Day Signups</small>
      <div class="stat-val"><?=$compDomains['w_curr']?> <span class="badge badge-green"><?=$compDomains['w_growth']?></span></div>
      <small style="color:#cbd5e1">MTD Domains: <?=$compDomains['m_curr']?> (<?=$compDomains['m_growth']?>)</small>
    </div>
  </div>

  <!-- FIX 6: 12-Month Cohort Registrations vs Retained Active Today -->
  <div class="box" style="margin-bottom:24px">
    <h3 style="margin:0 0 4px;color:#38bdf8">Monthly Cohort Registrations vs Retained Active Today</h3>
    <small style="color:#94a3b8">उस महीने में रजिस्टर हुए अकाउंट्स में से आज कितने एक्टिव हैं, उसका ऐतिहासिक विश्लेषण</small>
    <table>
      <thead>
        <tr><th>Cohort Month</th><th>Registered in Cohort</th><th>Retained Active Today</th><th>Retention Rate</th></tr>
      </thead>
      <tbody>
        <?php if (empty($cohorts)): ?>
          <tr><td colspan="4" style="color:#94a3b8">No cohort data yet. Click 'Sync WHMCS Live Cache' above.</td></tr>
        <?php else: foreach ($cohorts as $ch): 
          $retPct = $ch['total_registered'] > 0 ? round(($ch['retained_active'] / $ch['total_registered']) * 100, 1) : 0;
        ?>
          <tr>
            <td><strong><?=$ch['cohort_month']?></strong></td>
            <td><?=$ch['total_registered']?> accounts</td>
            <td style="color:#38bdf8"><strong><?=$ch['retained_active']?></strong> active</td>
            <td><span class="badge badge-green"><?=$retPct?>% Retained</span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Daily Historical Snapshots (FIX 6) -->
  <div class="box">
    <h3 style="margin:0 0 4px;color:#38bdf8">Daily Historical Active Trend (whmcs_daily_snapshots)</h3>
    <small style="color:#94a3b8">डेली रिकॉर्डेड एक्टिव ट्रेंड और MRR स्नैपशॉट</small>
    <table>
      <thead>
        <tr><th>Date</th><th>Total Clients</th><th>Active Hosting</th><th>Active Domains</th><th>Recorded MRR</th></tr>
      </thead>
      <tbody>
        <?php if (empty($snapshots)): ?>
          <tr><td colspan="5" style="color:#94a3b8">No daily snapshots recorded yet. Sync runs automatically with daily updates.</td></tr>
        <?php else: foreach ($snapshots as $s): ?>
          <tr>
            <td><strong><?=$s['snapshot_date']?></strong></td>
            <td><?=$s['total_clients']?></td>
            <td><?=$s['active_services']?></td>
            <td><?=$s['active_domains']?></td>
            <td style="color:#38bdf8">₹<?=number_format((float)$s['mrr_inr'], 2)?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
