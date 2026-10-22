<?php
// index.php - AmbitionHost 25-Module Production Control System with Per-Module AI Brain & WHMCS Deep-Links
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$pdo = db();
$currentUser = $_SESSION['user_name'] ?? 'Aarav';
$activeModuleId = isset($_GET['m']) ? (int)$_GET['m'] : 0; // 0 = Overview + All 25 Modules Grid

// Fetch Global Counts from Live MySQL Database
$pendingTasksCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending'")->fetchColumn();
$unpaidMismatchCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_records WHERE whmcs_invoice_status='Unpaid' OR service_delivered=0")->fetchColumn();
$suspendedWhmCount = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE is_suspended=1")->fetchColumn();
$over20GbCount = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE backup_eligible=0")->fetchColumn();
$domainVaultCount = (int)$pdo->query("SELECT COUNT(*) FROM domain_vault")->fetchColumn();
$activeLlm = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='active_llm_provider'")->fetchColumn() ?: 'deepseek';

$unreadNotices = $pdo->prepare("SELECT * FROM notifications WHERE recipient = ? ORDER BY id DESC LIMIT 30");
$unreadNotices->execute([$currentUser]);
$myNotices = $unreadNotices->fetchAll();
$unreadCount = count(array_filter($myNotices, fn($n) => (int)$n['is_read'] === 0));

// All 25 Modules Definition (With Exact WHMCS devokedev Deep-Links)
$MODULES = [
    1  => ['Overview & Urgent Command', 'Business', 'Paid-but-undelivered, outages, backups, renewals और daily priorities।', whmcs_deep_link('pending_orders')],
    2  => ['Customers & Service History', 'Business', '915 active hosting accounts और हर ग्राहक की registration से support तक की कुंडली।', whmcs_deep_link('clients')],
    3  => ['Orders & Delivery Queue', 'Operations', 'Payment आने के बाद hosting (Chandrika) और domain (Priya/Sanju) की पक्की डिलीवरी।', whmcs_deep_link('pending_orders')],
    4  => ['Invoices, UPI Proofs & CA Sheet', 'Finance', 'Razorpay मिलान, संजू मैम का बैंक वेरिफिकेशन, और 1-Click CA GST CSV Export।', whmcs_deep_link('unpaid_invoices')],
    5  => ['Renewals (30-15-7-3-1 Pipeline)', 'Business', 'Customer due date और registrar expiry का अलग मिलान ताकि 40% renewal बढ़कर 75%+ हो।', whmcs_deep_link('clients')],
    6  => ['Retention & 120 Suspended Win-back', 'Business', '120 suspended accounts और छोड़ चुके ग्राहकों को वापस लाने का ऑफर व ट्रैकिंग।', whmcs_deep_link('clients')],
    7  => ['Support Desk & SLA Tracker', 'Operations', 'WHMCS tickets, WhatsApp complaints और resolution proof एक साथ।', whmcs_deep_link('tickets')],
    8  => ['WhatsApp & Email Channels', 'Operations', 'Sales (7688855600) और Support (8696518872) की अलग-अलग queues।', whmcs_deep_link('tickets')],
    9  => ['Team & Role Permissions', 'Operations', 'सभी pending काम सबको दिखें; दूसरे के काम Read-only रहें; Refund सिर्फ Aarav के पास।', whmcs_deep_link('pending_orders')],
    10 => ['WHM Servers & Capacity (14 Privileges)', 'Operations', 'OVH SGP, Verpex, NameCrane के Active/Suspended counts, Load और Packages।', whmcs_deep_link('clients')],
    11 => ['Backups, >20GB Alert & Security', 'Operations', 'SGP ≤20GB नियम, >18GB storage upsell alert, और दीपक का Daily 10-cPanel MySQL check।', whmcs_deep_link('clients')],
    12 => ['Master Domain Vault (26 Registrars)', 'Operations', '2,000+ registrar accounts के लिए WhatsApp .txt Importer और तुरंत लॉगिन सर्च।', whmcs_deep_link('clients')],
    13 => ['Leads & Sales CRM (10m/30m/2h)', 'Growth', '30-min human reply SLA (Telegram alert) और चन्द्रिका के 10m, 30m, 2h price follow-ups।', whmcs_deep_link('pending_orders')],
    14 => ['Offers, Pricing & Upsell Packs', 'Growth', 'Shared, Cloud, VPS, Agency plans और ₹199–₹499 के High-Margin Add-ons।', whmcs_deep_link('clients')],
    15 => ['Ads Center (Meta CTWA & Google)', 'Growth', '₹10,000 Meta Click-to-WhatsApp कैंपेन और High-Intent Google Search Ads ट्रैकिंग।', whmcs_deep_link('pending_orders')],
    16 => ['Marketing Strategy & 60-Day Plan', 'Growth', '2 महीने में रेवेन्यू मैक्सिमाइज़ करने और टीम की सैलरी बढ़ाने का एक्शन प्लान।', whmcs_deep_link('clients')],
    17 => ['Attribution & Channel ROI', 'Growth', 'किस एड, YouTuber या रेफरल से कितना खर्च हुआ और कितना शुद्ध मुनाफ़ा आया।', whmcs_deep_link('clients')],
    18 => ['Content, SEO & Website Bug Fixes', 'Growth', 'SaluteHost leak fix, Canada VPS heading fix और हिंदी/इंग्लिश SEO ट्यूटोरियल्स।', whmcs_deep_link('clients')],
    19 => ['YouTube Creators & Affiliates 2.0', 'Growth', 'WHMCS aff.php के ज़रिए बिना झूठे वादे के 25-30% कमीशन पर क्रिएटर पार्टनरशिप।', whmcs_deep_link('clients')],
    20 => ['Reviews & Trustpilot Shield (2.8➔4.4)', 'Growth', '1-3 Star शिकायतों को डैशबोर्ड में रोकना और 4-5 Star को +15 Days बोनस के साथ Trustpilot भेजना।', 'rate.php'],
    21 => ['Costs, ₹64k Payroll & Net Profit', 'Finance', 'चन्द्रिका ₹15k, प्रिया ₹12k, संजू ₹12k, दीपक ₹25k, नाहर ₹3k और सर्वर बिल के बाद असली बचत।', whmcs_deep_link('unpaid_invoices')],
    22 => ['Forecasting & Salary Hike Targets', 'Finance', 'आने वाले रिन्यूअल, सर्वर खर्च और आपके Owner Pay के साथ सैलरी बढ़ोतरी का गणित।', whmcs_deep_link('unpaid_invoices')],
    23 => ['Daily 10 AM Briefing & History', 'Operations', 'सुबह 10 बजे के रिमाइंडर्स, पेंडिंग काम और नीचे सुरक्षित Resolved History।', whmcs_deep_link('pending_orders')],
    24 => ['Automation & Cron Control', 'Operations', 'Telegram SLA bot, 10m/30m/2h timers और बैकअप ऑडिट नियमों की निगरानी।', 'cron_engine.php'],
    25 => ['Integrations, WHM & Multi-LLM Brain', 'Operations', 'DeepSeek, Qwen, Gemini, Claude, OpenAI स्विचर और WHM/WHMCS कनेक्शन सेंटर।', whmcs_deep_link('pending_orders')],
];
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Control Room · Operations Center</title>
<style>
:root{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1e293b;background:#f1f5f9}
*{box-sizing:border-box}body{margin:0}button,input,select,textarea{font:inherit;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;background:#fff;color:inherit}
button{cursor:pointer;font-weight:600}button:hover{border-color:#2563eb;background:#eff6ff}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}.btn-primary:hover{background:#1d4ed8;color:#fff}
.btn-whmcs{background:#0f172a;color:#38bdf8;border-color:#0f172a;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700}
.btn-whmcs:hover{background:#1e293b;color:#7dd3fc}
header{position:sticky;top:0;background:#fff;z-index:20;border-bottom:1px solid #e2e8f0;padding:12px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.brand{font-size:20px;font-weight:800;display:flex;align-items:center;gap:10px;text-decoration:none;color:#0f172a}
.brand b{display:grid;place-items:center;width:34px;height:34px;background:#2563eb;color:#fff;border-radius:9px}
.pill{background:#fef3c7;color:#92400e;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:700}
.pill-green{background:#dcfce7;color:#166534}
.layout{display:grid;grid-template-columns:260px 1fr;max-width:1750px;margin:auto}
aside{padding:18px 12px;border-right:1px solid #e2e8f0;position:sticky;top:61px;height:calc(100vh - 61px);overflow-y:auto;background:#f8fafc}
.nav{display:block;text-decoration:none;color:#475569;padding:8px 10px;border-radius:7px;font-size:12px;margin:2px 0}
.nav.active{background:#dbeafe;color:#1d4ed8;font-weight:700}
.navnum{color:#94a3b8;font-size:10px;margin-right:6px;font-weight:700}
main{padding:24px 28px;min-width:0}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:16px}
.stat strong{font-size:22px;display:block;margin:6px 0 2px;color:#0f172a}
.queue{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:20px}
.queue a{text-decoration:none;color:inherit;background:#fff;border:1px solid #cbd5e1;padding:14px;border-radius:11px;display:block}
.queue a:hover{border-color:#2563eb;background:#f8fafc}
.priority{font-size:10px;color:#dc2626;font-weight:800;text-transform:uppercase}
.module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.module-card{text-decoration:none;color:inherit;background:#fff;border:1px solid #e2e8f0;padding:16px;border-radius:12px;display:flex;flex-direction:column;justify-content:space-between;gap:10px}
.module-card:hover{border-color:#2563eb;box-shadow:0 6px 18px rgba(37,99,235,.08)}
.twocol{display:grid;grid-template-columns:1.35fr 1fr;gap:18px;align-items:start}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
th{color:#64748b;font-size:10px;text-transform:uppercase}
.ai-panel{background:#0f172a;color:#f8fafc;border-radius:14px;padding:18px;position:sticky;top:80px}
.ai-panel h3{margin:0 0 8px;color:#38bdf8;display:flex;justify-content:space-between;align-items:center}
.ai-log{max-height:360px;overflow-y:auto;background:#1e293b;border-radius:10px;padding:12px;margin:10px 0;font-size:12.5px;line-height:1.7}
.ai-msg{margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #334155}
.ai-msg:last-child{border-bottom:0}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0}
dialog{border:1px solid #cbd5e1;border-radius:14px;padding:22px;max-width:680px;width:92%}
@media(max-width:1024px){.layout{grid-template-columns:1fr}aside{position:static;height:auto;display:flex;overflow-x:auto;gap:6px}.cards,.queue,.module-grid,.twocol{grid-template-columns:1fr}}
</style>
</head>
<body>

<header>
  <a href="index.php" class="brand"><b>A</b> AmbitionHost Master OS</a>
  <div class="toolbar" style="margin:0">
    <span class="pill pill-green">Active AI: <?=strtoupper(htmlspecialchars($activeLlm))?></span>
    <a class="btn-whmcs" href="<?=htmlspecialchars(whmcs_deep_link('pending_orders'))?>" target="_blank">WHMCS Pending Orders ↗</a>
    <a class="btn-whmcs" href="<?=htmlspecialchars(whmcs_deep_link('tickets'))?>" target="_blank">WHMCS Tickets ↗</a>
    <a class="btn-whmcs" href="analytics_engine.php" style="background:#1e293b;color:#38bdf8">Analytics & MRR 📊</a>
    <button id="profileBtn" style="font-size:12px;padding:6px 10px">🔑 Profile / Password</button>
    <button id="bellBtn">🔔 Alerts (<?=$unreadCount?>)</button>
    <a href="index.php?logout=1" class="btn-whmcs" style="background:#dc2626;color:#fff;border-color:#dc2626">Logout 🚪</a>
  </div>
</header>

<div class="layout">
  <aside>
    <a href="index.php" class="nav <?=$activeModuleId===0?'active':''?>">⌂ 00 / All 25 Modules Overview</a>
    <?php foreach ($MODULES as $id => $m): ?>
      <a href="index.php?m=<?=$id?>" class="nav <?=$activeModuleId===$id?'active':''?>">
        <span class="navnum"><?=sprintf('%02d', $id)?></span><?=htmlspecialchars($m[0])?>
      </a>
    <?php endforeach; ?>
  </aside>

  <main>
    <?php if ($activeModuleId === 0): ?>
      <!-- MASTER OVERVIEW + TOP 5 PRIORITIES + 25 MODULES GRID -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <span class="pill">Logged in as: <?=htmlspecialchars($currentUser)?> · <?=($currentUser==='Aarav'?'Full Owner Control & Refund Access':'Department Access (Others Read-Only)')?></span>
          <h1 style="margin:8px 0 4px">आज किस काम पर तुरंत ध्यान देना है?</h1>
        </div>
        <div class="toolbar">
          <a class="btn-whmcs" href="api.php?action=export_ca_csv">⬇ Download CA GST Sheet (CSV)</a>
          <a class="btn-whmcs" href="rate.php" target="_blank">🛡️ Open Trustpilot Shield Page ↗</a>
        </div>
      </div>

      <div class="cards">
        <div class="box stat"><small>Pending Team Tasks</small><strong><?=$pendingTasksCount?> Tasks</strong><small>Daily 10 AM reminder active</small></div>
        <div class="box stat"><small>Paid / Unpaid Mismatch & Delivery</small><strong><?=$unpaidMismatchCount?> Pending</strong><small>Verify in Module 03 & 04</small></div>
        <div class="box stat"><small>WHM Suspended / >20GB Backup Alert</small><strong><?=$suspendedWhmCount?> Susp · <?=$over20GbCount?> >20GB</strong><small>Synced via 14 WHM Privileges</small></div>
        <div class="box stat"><small>Domain Vault & Monthly Payroll</small><strong><?=$domainVaultCount?> Domains · ₹64k/mo</strong><small>26 Registrars searchable</small></div>
      </div>

      <h2>Top 5 Priorities (एक क्लिक में संबंधित Control Module खोलें)</h2>
      <div class="queue">
        <a href="index.php?m=3"><span class="priority">Priority 1 · Urgent</span><strong style="display:block;margin:6px 0">Paid, Service बाकी</strong><small>Chandrika (Hosting) · Priya/Sanju (Domain)</small></a>
        <a href="index.php?m=4"><span class="priority">Priority 2 · Billing</span><strong style="display:block;margin:6px 0">Payment Mismatch & UPI</strong><small>Sanju Bank Verify · Razorpay Sync</small></a>
        <a href="index.php?m=10"><span class="priority">Priority 3 · Infra</span><strong style="display:block;margin:6px 0">WHM Suspended & Outages</strong><small>Deepak L1 · Nahar L2 Escalation</small></a>
        <a href="index.php?m=11"><span class="priority">Priority 4 · Backups</span><strong style="display:block;margin:6px 0">JetBackup & >20GB Alert</strong><small>Daily 10 cPanel MySQL Audit</small></a>
        <a href="index.php?m=5"><span class="priority">Priority 5 · Retention</span><strong style="display:block;margin:6px 0">Renewals & 120 Win-Back</strong><small>Take Renewal Rate 40% ➔ 75%+</small></a>
      </div>

      <h2>पूरे Business के 25 Control Modules (हर Module के अंदर Live AI Advisor मौजूद है)</h2>
      <div class="module-grid">
        <?php foreach ($MODULES as $id => $m): ?>
          <div class="module-card">
            <div>
              <span class="pill" style="background:#e0e7ff;color:#1e40af"><?=sprintf('%02d', $id)?> · <?=$m[1]?></span>
              <h3 style="margin:10px 0 6px"><?=htmlspecialchars($m[0])?></h3>
              <p style="font-size:12.5px;color:#475569;margin:0"><?=htmlspecialchars($m[2])?></p>
            </div>
            <div class="toolbar" style="margin:8px 0 0;justify-content:space-between">
              <a href="index.php?m=<?=$id?>" class="btn-primary" style="text-decoration:none;padding:7px 12px;border-radius:8px;font-size:12px">Open Module + AI Chat →</a>
              <a href="<?=htmlspecialchars($m[3])?>" target="_blank" class="btn-whmcs">WHMCS ↗</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: 
      $curMod = $MODULES[$activeModuleId] ?? $MODULES[1];
      $userRole = $_SESSION['user_role'] ?? 'Staff';
      $userDept = $_SESSION['user_dept'] ?? 'All';

      $allowed = true;
      if ($userRole !== 'Owner' && $userDept !== 'All' && $activeModuleId !== 1) {
          $allowedMap = [
              'Sales' => [1, 2, 3, 13, 14, 15, 16, 17, 19, 20],
              'Domain' => [1, 2, 3, 5, 12],
              'Billing_Domain' => [1, 2, 3, 4, 5, 12, 21],
              'Support_Tech' => [1, 2, 7, 8, 10, 11, 20, 24],
          ];
          $allowed = in_array($activeModuleId, $allowedMap[$userRole] ?? [1], true);
      }

      if (!$allowed):
    ?>
      <div class="box" style="text-align:center;padding:48px 24px">
        <h2 style="color:#dc2626;margin:0 0 10px">⛔ Department Restricted</h2>
        <p style="color:#64748b;max-width:520px;margin:0 auto 20px">यह मॉड्यूल आपके डिपार्टमेंट (<strong><?=htmlspecialchars($userDept)?></strong>) के कार्यक्षेत्र में नहीं है। केवल Aarav (Owner) ही सभी 25 डिपार्टमेंट देख सकते हैं।</p>
        <a href="index.php" class="btn-whmcs">← Return to Master Overview</a>
      </div>
    <?php else: ?>
      <!-- INDIVIDUAL MODULE WORKSPACE (1 TO 25) + EMBEDDED CONTEXTUAL AI ADVISOR -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <div>
          <a href="index.php" style="font-size:12px;font-weight:700;text-decoration:none">← Back to All 25 Modules</a>
          <h1 style="margin:6px 0">Module <?=sprintf('%02d', $activeModuleId)?>: <?=htmlspecialchars($curMod[0])?></h1>
          <p style="margin:0;color:#475569;font-size:13px"><?=htmlspecialchars($curMod[2])?></p>
        </div>
        <div class="toolbar">
          <a href="<?=htmlspecialchars($curMod[3])?>" target="_blank" class="btn-whmcs">Edit Directly in WHMCS (/user/devokedev/) ↗</a>
        </div>
      </div>

      <div class="twocol">
        <!-- LEFT COLUMN: LIVE MODULE OPERATIONS, TABLES & ACTIONS -->
        <div>

          <?php if (in_array($activeModuleId, [3, 4])): ?>
            <!-- MODULE 3 & 4: PAYMENTS, DIRECT UPI SCREENSHOT UPLOADER & DELIVERY TRACKER -->
            <div class="box">
              <h3>➕ Record Payment / Upload WhatsApp UPI Proof (No Google Photos Needed)</h3>
              <form id="paymentForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="payment_action">
                <input type="hidden" name="pay_sub" value="add_payment">
                <div class="toolbar">
                  <input name="whmcs_invoice_id" type="number" placeholder="WHMCS Invoice #" required style="width:140px">
                  <input name="customer_name" placeholder="Customer Name / Domain" required>
                  <input name="amount" type="number" step="0.01" placeholder="Amount (₹)" required style="width:120px">
                  <select name="payment_source"><option value="WhatsApp_UPI">WhatsApp UPI Screenshot</option><option value="Razorpay">Razorpay Unpaid Fix</option></select>
                  <input name="payment_id_or_utr" placeholder="Payment ID / UTR">
                  <select name="delivery_owner"><option>Chandrika</option><option>Priya</option><option>Sanju</option><option>Deepak</option></select>
                  <input type="file" name="proof_image" accept="image/*">
                  <button type="submit" class="btn-primary">Save Payment Record</button>
                </div>
              </form>
              <div class="toolbar">
                <a class="btn-whmcs" href="api.php?action=export_ca_csv">⬇ Download CA GST Sheet (CSV)</a>
                <a class="btn-whmcs" href="<?=htmlspecialchars(whmcs_deep_link('unpaid_invoices'))?>" target="_blank">View Unpaid in WHMCS ↗</a>
              </div>
              <table>
                <thead><tr><th>Invoice #</th><th>Customer / Amount</th><th>Source & Proof</th><th>Sanju Bank Check</th><th>Delivery</th><th>WHMCS Edit</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM payment_records ORDER BY id DESC LIMIT 25") as $pr): ?>
                    <tr>
                      <td><strong>#<?=$pr['whmcs_invoice_id']?></strong></td>
                      <td><?=htmlspecialchars((string)$pr['customer_name'])?><br><strong>₹<?=$pr['amount']?></strong></td>
                      <td><?=$pr['payment_source']?><br><?=($pr['proof_file_url'] ? '<a href="'.htmlspecialchars($pr['proof_file_url']).'" target="_blank">View Proof Screenshot ↗</a>' : htmlspecialchars((string)$pr['payment_id_or_utr']))?></td>
                      <td><?=$pr['sanju_verified'] ? '✅ Verified' : '<button onclick="verifyPay('.$pr['id'].',\'sanju_verify\')">Verify (Sanju)</button>'?></td>
                      <td><?=$pr['service_delivered'] ? '✅ Delivered' : '<button onclick="verifyPay('.$pr['id'].',\'mark_delivered\')">Mark Delivered ('.$pr['delivery_owner'].')</button>'?></td>
                      <td><a class="btn-whmcs" href="<?=htmlspecialchars(whmcs_deep_link('invoice', $pr['whmcs_invoice_id']))?>" target="_blank">Edit Invoice ↗</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (in_array($activeModuleId, [6, 10, 11])): ?>
            <!-- MODULE 6, 10, 11: WHM SERVERS (14 TICKED PRIVILEGES), SUSPENDED LIST + REASONS & >20GB BACKUP ALERT -->
            <div class="box">
              <h3>🖥️ WHM Servers & Live Sync (Strictly Using Your 14 Ticked Privileges)</h3>
              <form id="addWhmForm" class="toolbar">
                <input type="hidden" name="action" value="whm_action">
                <input type="hidden" name="whm_sub" value="add_server">
                <input name="server_name" placeholder="Server Name (e.g. SGP Dedicated)" required>
                <input name="provider" placeholder="Provider (OVH / Verpex / NameCrane)" required>
                <input name="hostname" placeholder="Hostname / IP (e.g. sgp.ambitionhost.in)" required>
                <input name="whm_username" value="root" placeholder="WHM User" style="width:90px">
                <input name="api_token" placeholder="Paste WHM API Token" required>
                <button type="submit" class="btn-primary">Connect & Sync WHM</button>
              </form>
              <table>
                <thead><tr><th>Server / Provider</th><th>Total / Active / Suspended</th><th>Server Load</th><th>Services Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM whm_servers") as $srv): ?>
                    <tr>
                      <td><strong><?=htmlspecialchars($srv['server_name'])?></strong> (<?=htmlspecialchars($srv['provider'])?>)</td>
                      <td>Total: <b><?=$srv['total_accounts']?></b> · Active: <b><?=$srv['active_accounts']?></b> · Susp: <b style="color:#dc2626"><?=$srv['suspended_accounts']?></b></td>
                      <td><?=htmlspecialchars((string)$srv['load_avg'])?></td>
                      <td><small><?=htmlspecialchars((string)$srv['service_status_json'])?></small></td>
                      <td><button onclick="syncWhm(<?=$srv['id']?>)">🔄 Sync Now</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <h3 style="margin-top:18px">🚨 Suspended Accounts (With Exact WHM Reason) & >20GB Backup Exclusion Alert</h3>
              <table>
                <thead><tr><th>cPanel User / Domain</th><th>Plan</th><th>Disk Used / 20GB Rule</th><th>Status & Suspend Reason</th><th>Suspended Time</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM whm_accounts WHERE is_suspended=1 OR backup_eligible=0 ORDER BY is_suspended DESC, disk_used_mb DESC LIMIT 50") as $ac): ?>
                    <tr>
                      <td><strong><?=htmlspecialchars($ac['cpanel_user'])?></strong><br><?=htmlspecialchars($ac['domain'])?></td>
                      <td><?=htmlspecialchars((string)$ac['plan_name'])?></td>
                      <td><strong><?=$ac['disk_used_mb']?> MB</strong><br><?=$ac['backup_eligible'] ? '<span class="pill pill-green">≤20GB Covered</span>' : '<span class="pill" style="background:#fee2e2;color:#991b1b">>20GB (Excluded from Nahar Backup! Pitch ₹199/mo Upgrade)</span>'?></td>
                      <td><?=$ac['is_suspended'] ? '<b style="color:#dc2626">SUSPENDED: '.htmlspecialchars((string)$ac['suspend_reason']).'</b>' : 'Active'?></td>
                      <td><?=htmlspecialchars((string)$ac['suspend_time'])?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($activeModuleId === 12): ?>
            <!-- MODULE 12: MASTER DOMAIN VAULT & WHATSAPP .TXT CHAT IMPORTER -->
            <div class="box">
              <h3>🔐 Master Domain Vault (Kill the WhatsApp Group Search across 2,000 Accounts)</h3>
              <p style="font-size:12px">अपने डोमेन टीम के WhatsApp Group की चैट (या लिस्ट) नीचे पेस्ट करें—सिस्टम अपने-आप <strong>Domain + Registrar + Account Email</strong> निकालकर डेटाबेस में सेव कर लेगा:</p>
              <textarea id="vaultRawText" rows="3" style="width:100%" placeholder="Paste WhatsApp group messages here (e.g. example.com bought on Spaceship login priya12@gmail.com)..."></textarea>
              <button class="btn-primary" onclick="importVault()" style="margin-top:6px">⚡ Auto-Extract & Save to Domain Vault</button>
              <table style="margin-top:14px">
                <thead><tr><th>Domain Name</th><th>Registrar</th><th>Account Login / Email</th><th>Notes</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM domain_vault ORDER BY updated_at DESC LIMIT 40") as $dv): ?>
                    <tr>
                      <td><strong><?=htmlspecialchars($dv['domain_name'])?></strong></td>
                      <td><span class="pill"><?=htmlspecialchars($dv['registrar_name'])?></span></td>
                      <td><code><?=htmlspecialchars($dv['account_email_or_id'])?></code></td>
                      <td><small><?=htmlspecialchars((string)$dv['notes'])?></small></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($activeModuleId === 13): ?>
            <!-- MODULE 13: SALES CRM (30-MIN SLA & 10m / 30m / 2h FOLLOW-UPS) -->
            <div class="box">
              <h3>💬 Sales WhatsApp CRM (30-Min Human Reply SLA & 10m / 30m / 2h Follow-ups)</h3>
              <div class="toolbar">
                <input id="leadIdent" placeholder="Customer Name / Phone / Domain">
                <input id="leadMsg" placeholder="Initial Enquiry Message">
                <button class="btn-primary" onclick="crmCall('new_lead')">+ Capture Incoming Lead</button>
              </div>
              <table>
                <thead><tr><th>Lead / Identity</th><th>Wait Time (9AM-6PM)</th><th>Human Reply</th><th>Price Sent (+10m/30m/2h)</th><th>Customer Replied</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM sales_leads ORDER BY id DESC LIMIT 20") as $ld): 
                    $waitM = calc_working_minutes(strtotime($ld['first_msg_at']), $ld['first_human_reply_at'] ? strtotime($ld['first_human_reply_at']) : time());
                  ?>
                    <tr>
                      <td><strong>#<?=$ld['id']?> · <?=htmlspecialchars($ld['customer_identity'])?></strong><br><small><?=$ld['first_msg_at']?></small></td>
                      <td><b style="color:<?=$waitM>30?'#dc2626':'#166534'?>"><?=$waitM?> working mins</b></td>
                      <td><?=$ld['first_human_reply_at'] ? '✅ '.$ld['first_human_reply_at'] : '<button onclick="crmLead('.$ld['id'].',\'human_reply\')">Mark Replied</button>'?></td>
                      <td><?=$ld['price_sent_at'] ? '✅ '.$ld['price_sent_at'] : '<button onclick="crmLead('.$ld['id'].',\'price_sent\')">Mark Price Sent (Start 10m/30m/2h)</button>'?></td>
                      <td><?=$ld['customer_replied_after_price'] ? '✅ Replied (Follow-ups Stopped)' : '<button onclick="crmLead('.$ld['id'].',\'customer_replied\')">Customer Replied</button>'?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <!-- UNIVERSAL TASK & ACCOUNTABILITY QUEUE FOR THIS MODULE (ALL 25 MODULES) -->
          <div class="box">
            <h3>📋 Tasks & Accountability Queue (Module <?=sprintf('%02d', $activeModuleId)?>)</h3>
            <form id="newTaskForm" class="toolbar">
              <input type="hidden" name="action" value="create_task">
              <input type="hidden" name="module_id" value="<?=$activeModuleId?>">
              <input name="title" placeholder="Task title (e.g. Verify WHMCS Pending Order #412)" required style="flex:1">
              <input name="description" placeholder="Instructions / Details" style="flex:1">
              <select name="owner">
                <?php foreach (['Chandrika','Priya','Sanju','Deepak','Aarav'] as $p): ?><option value="<?=$p?>"><?=$p?></option><?php endforeach; ?>
              </select>
              <input name="whmcs_link" placeholder="Optional WHMCS URL" value="<?=htmlspecialchars($curMod[3])?>">
              <button type="submit" class="btn-primary">+ Assign Task</button>
            </form>

            <table>
              <thead><tr><th>Task & Timeline</th><th>Owner & Access</th><th>WHMCS Deep-Link</th><th>Controls (Owner Only; Others Read-Only)</th></tr></thead>
              <tbody>
                <?php
                $tQuery = $pdo->prepare("SELECT * FROM tasks WHERE (module_id = ? OR ? = 1) ORDER BY status ASC, id DESC LIMIT 35");
                $tQuery->execute([$activeModuleId, $activeModuleId]);
                foreach ($tQuery->fetchAll() as $t):
                  $grants = array_filter(array_map('trim', explode(',', (string)$t['granted_users'])));
                  $canEdit = ($t['owner'] === $currentUser || in_array($currentUser, $grants, true));
                ?>
                  <tr>
                    <td>
                      <strong>#<?=$t['id']?> · <?=htmlspecialchars($t['title'])?></strong> <span class="pill"><?=$t['status']?></span>
                      <p style="margin:4px 0;font-size:12px;color:#475569"><?=htmlspecialchars((string)$t['description'])?></p>
                      <?php if ($t['resolution_evidence']): ?>
                        <div style="background:#dcfce7;color:#166534;padding:6px;border-radius:6px;font-size:11px">✅ Evidence: <?=htmlspecialchars($t['resolution_evidence'])?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong><?=htmlspecialchars($t['owner'])?></strong>
                      <?php if ($t['previous_owners']): ?><br><small>Prev: <?=htmlspecialchars($t['previous_owners'])?></small><?php endif; ?>
                      <br><small style="color:<?=$canEdit?'#166534':'#b45309'?>"><?=$canEdit ? '✔ Editable by you' : '🔒 Read-Only (Other Member Task)'?></small>
                    </td>
                    <td>
                      <?php if (!empty($t['whmcs_link'])): ?>
                        <a class="btn-whmcs" href="<?=htmlspecialchars($t['whmcs_link'])?>" target="_blank">Open in WHMCS ↗</a>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($canEdit && $t['status'] === 'Pending'): ?>
                        <div class="toolbar" style="margin:0">
                          <button class="btn-primary" onclick="resolveTaskPrompt(<?=$t['id']?>)">✔ Mark Resolved</button>
                          <button onclick="transferTaskPrompt(<?=$t['id']?>)">⇄ Transfer</button>
                        </div>
                      <?php endif; ?>
                      <?php if ($currentUser === 'Aarav' && !$canEdit && $t['status'] === 'Pending'): ?>
                        <button onclick="grantAccess(<?=$t['id']?>, 'Aarav')">🔓 Grant Self Edit Access</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>

        <!-- RIGHT COLUMN: EMBEDDED MULTI-LLM AI ADVISOR FOR THIS EXACT MODULE -->
        <div class="ai-panel">
          <h3>
            <span>🤖 Module <?=sprintf('%02d', $activeModuleId)?> AI Advisor</span>
            <select id="llmSwitcher" style="background:#1e293b;color:#38bdf8;border-color:#334155;font-size:11px">
              <?php foreach (['deepseek'=>'DeepSeek','qwen'=>'Qwen','gemini'=>'Gemini','openai'=>'ChatGPT','claude'=>'Claude'] as $k=>$v): ?>
                <option value="<?=$k?>" <?=$activeLlm===$k?'selected':''?>><?=$v?></option>
              <?php endforeach; ?>
            </select>
          </h3>
          <small style="color:#94a3b8;display:block;margin-bottom:8px">
            यह AI सीधे आपके MySQL Database से <strong><?=htmlspecialchars($curMod[0])?></strong> का लाइव डेटा पढ़कर आपको गाइड करेगा।
          </small>

          <div class="ai-log" id="aiChatLog">
            <?php
            $cStmt = $pdo->prepare("SELECT * FROM ai_module_chats WHERE module_id = ? ORDER BY id DESC LIMIT 8");
            $cStmt->execute([$activeModuleId]);
            $chats = array_reverse($cStmt->fetchAll());
            if (empty($chats)):
            ?>
              <div class="ai-msg">नमस्ते <?=htmlspecialchars($currentUser)?>! मैं <strong><?=htmlspecialchars($curMod[0])?></strong> का AI Advisor हूँ। आप मुझसे इस मॉड्यूल के डेटा, रणनीति, या WhatsApp/Email ड्राफ्ट के बारे में कुछ भी पूछ सकते हैं।</div>
            <?php else: foreach ($chats as $c): ?>
              <div class="ai-msg">
                <strong style="color:#fbbf24"><?=htmlspecialchars($c['user_name'])?>:</strong> <?=nl2br(htmlspecialchars($c['user_prompt']))?><br>
                <strong style="color:#38bdf8">AI (<?=htmlspecialchars($c['llm_provider'])?>):</strong><br><?=nl2br(htmlspecialchars($c['ai_response']))?>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <form id="aiAskForm">
            <input type="hidden" name="action" value="ask_ai">
            <input type="hidden" name="module_id" value="<?=$activeModuleId?>">
            <input type="hidden" name="module_title" value="<?=htmlspecialchars($curMod[0])?>">
            <textarea name="prompt" id="aiPromptInput" rows="3" style="width:100%;background:#1e293b;color:#fff;border-color:#334155;margin-bottom:8px" placeholder="यहाँ अपना सवाल पूछें (जैसे: इस Module में अभी सबसे ज़रूरी काम क्या है और उसे कैसे पूरा करें?)..." required></textarea>
            <button type="submit" class="btn-primary" style="width:100%" id="aiSendBtn">Ask Connected LLM →</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </main>

</div>

<!-- BELL NOTIFICATIONS DIALOG -->
<dialog id="bellModal">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3>🔔 Notifications for <?=htmlspecialchars($currentUser)?></h3>
    <button onclick="document.getElementById('bellModal').close()">✕</button>
  </div>
  <p style="font-size:12px;color:#64748b">नोट: Notification पढ़ने से काम Resolved नहीं माना जाता।</p>
  <?php foreach ($myNotices as $n): ?>
    <div style="padding:10px;border-bottom:1px solid #e2e8f0;border-left:4px solid <?=$n['is_read']?'#cbd5e1':'#2563eb'?>;margin-bottom:6px">
      <div style="font-size:13px"><?=htmlspecialchars($n['message'])?></div>
      <small style="color:#64748b"><?=$n['created_at']?> · <?=$n['is_read']?'Read':'Unread'?></small>
      <?php if (!$n['is_read']): ?>
        <button style="float:right;font-size:11px;padding:3px 8px" onclick="markNoticeRead(<?=$n['id']?>)">Mark Read</button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</dialog>

<!-- PROFILE & PASSWORD CHANGE MODAL -->
<dialog id="profileModal" style="max-width:440px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3 style="margin:0">🔑 Change Email or Password</h3>
    <button onclick="document.getElementById('profileModal').close()">✕</button>
  </div>
  <form id="profileForm">
    <input type="hidden" name="action" value="change_credentials">
    <?php if ($currentUser === 'Aarav' || ($_SESSION['user_role'] ?? '') === 'Owner'): ?>
      <label style="font-size:12px;font-weight:700">Select Team Member:</label>
      <select name="target_user_id" style="width:100%;margin-bottom:10px">
        <?php foreach ($pdo->query("SELECT id, name, email FROM users ORDER BY id ASC") as $u): ?>
          <option value="<?=$u['id']?>"><?=$u['name']?> (<?=$u['email']?>)</option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="target_user_id" value="<?=(int)$_SESSION['user_id']?>">
      <p style="font-size:13px;margin:0 0 10px">Logged in as: <b><?=htmlspecialchars($currentUser)?></b></p>
    <?php endif; ?>

    <label style="font-size:12px;font-weight:700">New Email (Optional):</label>
    <input type="email" name="new_email" placeholder="Leave blank if unchanged" style="width:100%;margin-bottom:10px">

    <label style="font-size:12px;font-weight:700">New Password:</label>
    <input type="password" name="new_password" placeholder="At least 6 characters" style="width:100%;margin-bottom:14px" required>

    <button type="submit" class="btn-primary" style="width:100%">Save New Password →</button>
  </form>
</dialog>

<script>
async function postApi(formData) {
  const res = await fetch('api.php', { method: 'POST', body: formData });
  const data = await res.json();
  if (data.status !== 'ok') alert(data.message || 'Error');
  return data;
}

document.getElementById('bellBtn').onclick = () => document.getElementById('bellModal').showModal();
const profBtn = document.getElementById('profileBtn');
if (profBtn) profBtn.onclick = () => document.getElementById('profileModal').showModal();

const profForm = document.getElementById('profileForm');
if (profForm) profForm.onsubmit = async (e) => {
  e.preventDefault();
  const r = await postApi(new FormData(profForm));
  if (r.status === 'ok') {
    alert('✅ Password / Email successfully updated!');
    location.reload();
  }
};

async function markNoticeRead(id) {
  const fd = new FormData(); fd.append('action', 'read_notification'); fd.append('notification_id', id);
  await postApi(fd); location.reload();
}

const newTaskForm = document.getElementById('newTaskForm');
if (newTaskForm) newTaskForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(newTaskForm)); location.reload();
};

async function resolveTaskPrompt(id) {
  const ev = prompt('काम पूरा होने का प्रमाण (Resolution Evidence / Link / Note) दर्ज करें:');
  if (!ev) return;
  const fd = new FormData();
  fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'resolve'); fd.append('evidence', ev);
  await postApi(fd); location.reload();
}

async function transferTaskPrompt(id) {
  const to = prompt('किस टीम मेंबर को Transfer करना है? (Chandrika, Priya, Sanju, Deepak, Aarav)');
  if (!to) return;
  const fd = new FormData();
  fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'transfer'); fd.append('new_owner', to);
  await postApi(fd); location.reload();
}

async function grantAccess(id, person) {
  const fd = new FormData();
  fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'grant_access'); fd.append('grant_to', person);
  await postApi(fd); location.reload();
}

const payForm = document.getElementById('paymentForm');
if (payForm) payForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(payForm)); location.reload();
};

async function verifyPay(id, sub) {
  const fd = new FormData(); fd.append('action', 'payment_action'); fd.append('pay_sub', sub); fd.append('payment_row_id', id);
  await postApi(fd); location.reload();
}

const whmForm = document.getElementById('addWhmForm');
if (whmForm) whmForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(whmForm)); location.reload();
};

async function syncWhm(id) {
  const fd = new FormData(); fd.append('action', 'whm_action'); fd.append('whm_sub', 'sync_server'); fd.append('server_id', id);
  await fetch('api.php', { method: 'POST', body: fd }); location.reload();
}

async function importVault() {
  const txt = document.getElementById('vaultRawText').value;
  const fd = new FormData(); fd.append('action', 'import_domain_vault'); fd.append('vault_text', txt);
  const r = await postApi(fd); alert('Imported ' + (r.imported_count || 0) + ' domains into Vault!'); location.reload();
}

async function crmCall(sub) {
  const fd = new FormData(); fd.append('action', 'crm_action'); fd.append('crm_sub', sub);
  fd.append('customer_identity', document.getElementById('leadIdent').value);
  fd.append('message', document.getElementById('leadMsg').value);
  await postApi(fd); location.reload();
}

async function crmLead(id, sub) {
  const fd = new FormData(); fd.append('action', 'crm_action'); fd.append('crm_sub', sub); fd.append('lead_id', id);
  await postApi(fd); location.reload();
}

const llmSw = document.getElementById('llmSwitcher');
if (llmSw) llmSw.onchange = async (e) => {
  const fd = new FormData(); fd.append('action', 'set_llm_provider'); fd.append('provider', e.target.value);
  await postApi(fd); location.reload();
};

const aiForm = document.getElementById('aiAskForm');
if (aiForm) aiForm.onsubmit = async (e) => {
  e.preventDefault();
  const btn = document.getElementById('aiSendBtn');
  btn.textContent = 'Thinking with Live DB Data...'; btn.disabled = true;
  await postApi(new FormData(aiForm));
  location.reload();
};
</script>
</body>
</html>