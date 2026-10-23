<?php
// analytics_engine.php - Hardened WHMCS Domain vs Hosting Separate Portal & 6-Period Comparison Graphs
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whm_whmcs_sync.php';

$pdo = db();
$staff = current_staff_user();
if (empty($staff['can_graphs']) && ($staff['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$syncMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sync_whmcs'] ?? '') === '1') {
    $resCheck = AmbitionInfraSync::callWhmcsReadOnly('GetClientsProducts', ['limitstart' => 0, 'limitnum' => 1]);
    if (($resCheck['result'] ?? '') !== 'success') {
        $syncMsg = '⚠️ WHMCS API Error or Credentials Not Set: ' . ($resCheck['message'] ?? 'Sync aborted safely without overwriting cache.');
    } else {
        $syncMsg = '✅ WHMCS Read-Only Sync Completed Safely!';
    }
}

// Normalized MRR & Status Breakdown (Divides Annual by 12, Biennial by 24, Triennial by 36, Semi-Annual by 6, Quarterly by 3)
function portal_breakdown(PDO $pdo, string $type): array {
    $sql = "
        SELECT status, COUNT(*) as cnt,
        SUM(CASE 
            WHEN billing_cycle='Monthly' THEN recurring_amount
            WHEN billing_cycle='Quarterly' THEN recurring_amount/3
            WHEN billing_cycle='Semi-Annually' THEN recurring_amount/6
            WHEN billing_cycle IN ('Annually','1 Year(s)') THEN recurring_amount/12
            WHEN billing_cycle IN ('Biennially','2 Year(s)') THEN recurring_amount/24
            WHEN billing_cycle IN ('Triennially','3 Year(s)') THEN recurring_amount/36
            ELSE recurring_amount/12 END
        ) as norm_mrr
        FROM whmcs_items_cache WHERE item_type = ? GROUP BY status
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type]);
    $out = ['Active'=>0, 'Suspended'=>0, 'Expired'=>0, 'Cancelled'=>0, 'Due30'=>0, 'MRR'=>0.0];
    foreach ($stmt->fetchAll() as $r) {
        $st = $r['status']; $c = (int)$r['cnt'];
        if ($st === 'Active') { $out['Active'] += $c; $out['MRR'] += (float)$r['norm_mrr']; }
        elseif ($st === 'Suspended') $out['Suspended'] += $c;
        elseif (in_array($st, ['Expired','Grace','Redemption'], true)) $out['Expired'] += $c;
        else $out['Cancelled'] += $c;
    }
    $d30 = $pdo->prepare("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type=? AND status='Active' AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $d30->execute([$type]);
    $out['Due30'] = (int)$d30->fetchColumn();
    return $out;
}

// 6-Period Comparison with Fixed 7-Day Inclusive Math & Fair Month-to-Date (MTD)
function calc_6_comparisons(PDO $pdo, string $type): array {
    $dayOfMonth = date('d');
    $periods = [
        ['1. Week-over-Week (Exact 7 Days)', date('Y-m-d', strtotime('-13 days')), date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
        ['2. Month-to-Date (Last Month MTD vs This Month MTD)', date('Y-m-01', strtotime('first day of last month')), date('Y-m-' . min((int)$dayOfMonth, (int)date('t', strtotime('last month'))), strtotime('first day of last month')), date('Y-m-01'), date('Y-m-d')],
        ['3. Same Month YoY (Last Year MTD vs This Year MTD)', date('Y-m-01', strtotime('-1 year')), date('Y-m-' . $dayOfMonth, strtotime('-1 year')), date('Y-m-01'), date('Y-m-d')],
        ['4. Quarter-over-Quarter (90 Days vs Prev 90 Days)', date('Y-m-d', strtotime('-179 days')), date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
        ['5. Half-Yearly (6 Months vs Prev 6 Months)', date('Y-m-d', strtotime('-363 days')), date('Y-m-d', strtotime('-182 days')), date('Y-m-d', strtotime('-181 days')), date('Y-m-d')],
        ['6. Year-over-Year (365 Days vs Prev 365 Days)', date('Y-m-d', strtotime('-729 days')), date('Y-m-d', strtotime('-365 days')), date('Y-m-d', strtotime('-364 days')), date('Y-m-d')],
    ];
    $q = $pdo->prepare("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type = ? AND reg_date BETWEEN ? AND ?");
    $rows = [];
    foreach ($periods as [$label, $pS, $pE, $cS, $cE]) {
        $q->execute([$type, $pS, $pE]); $prev = (int)$q->fetchColumn();
        $q->execute([$type, $cS, $cE]); $curr = (int)$q->fetchColumn();
        $diff = $curr - $prev;
        $badge = $prev > 0 ? (($diff >= 0 ? '+' : '') . round(($diff / $prev) * 100, 1) . '% ' . ($diff >= 0 ? 'बढ़ोतरी' : 'घटोतरी')) : ($curr > 0 ? "New (+{$curr})" : '0%');
        $rows[] = ['label'=>$label, 'prev'=>$prev, 'curr'=>$curr, 'diff'=>$diff, 'badge'=>$badge, 'up'=>($diff >= 0)];
    }
    return $rows;
}

$hStats = portal_breakdown($pdo, 'hosting');
$dStats = portal_breakdown($pdo, 'domain');
$hComp  = calc_6_comparisons($pdo, 'hosting');
$dComp  = calc_6_comparisons($pdo, 'domain');
?>
<!doctype html><html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>AmbitionHost · Hosting vs Domain Comparison Portal</title>
<style>body{font-family:Inter,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:20px}.wrap{max-width:1650px;margin:auto}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:18px}table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.up{background:#dcfce7;color:#166534;padding:3px 8px;border-radius:12px;font-weight:700;font-size:11px}.down{background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:12px;font-weight:700;font-size:11px}@media(max-width:1000px){.grid2{grid-template-columns:1fr}}</style>
</head><body><div class="wrap">
  <div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap">
    <div>
      <a href="index.php" style="text-decoration:none;font-weight:700;color:#2563eb">← Back to Master Dashboard</a>
      <h1 style="margin:6px 0 0;font-size:22px">📊 WHMCS Hosting Portal vs Domain Portal (6-Period Comparisons)</h1>
    </div>
    <form method="post"><input type="hidden" name="sync_whmcs" value="1"><button type="submit" style="background:#2563eb;color:#fff;border:0;padding:9px 14px;border-radius:8px;font-weight:700;cursor:pointer">🔄 Sync WHMCS Read-Only Data</button></form>
  </div>
  <?php if ($syncMsg): ?><div class="card" style="background:#eff6ff;font-weight:700"><?=htmlspecialchars($syncMsg)?></div><?php endif; ?>
  <div class="grid2">
    <div class="card" style="border-top:4px solid #2563eb">
      <h2>🖥️ Hosting Portal (Active: <?=$hStats['Active']?> · Susp: <?=$hStats['Suspended']?> · Normalized MRR: ₹<?=number_format($hStats['MRR'])?>)</h2>
      <table><thead><tr><th>Period</th><th>Previous</th><th>Current</th><th>Trend</th></tr></thead><tbody>
        <?php foreach ($hComp as $r): ?><tr><td><b><?=$r['label']?></b></td><td><?=$r['prev']?></td><td><b><?=$r['curr']?></b></td><td><span class="<?=$r['up']?'up':'down'?>"><?=$r['up']?'▲ ':'▼ '?><?=$r['badge']?></span></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
    <div class="card" style="border-top:4px solid #7c3aed">
      <h2>🌐 Domain Portal (Active: <?=$dStats['Active']?> · Expired: <?=$dStats['Expired']?> · Due 30d: <?=$dStats['Due30']?>)</h2>
      <table><thead><tr><th>Period</th><th>Previous</th><th>Current</th><th>Trend</th></tr></thead><tbody>
        <?php foreach ($dComp as $r): ?><tr><td><b><?=$r['label']?></b></td><td><?=$r['prev']?></td><td><b><?=$r['curr']?></b></td><td><span class="<?=$r['up']?'up':'down'?>"><?=$r['up']?'▲ ':'▼ '?><?=$r['badge']?></span></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</div></body></html>