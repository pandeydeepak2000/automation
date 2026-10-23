<?php
// index.php - AmbitionHost Master Control System (Complete 25 Modules + Role Hiding + Autonomous Brainstorm + Live Support Inbox)
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$pdo = db();
$staff = current_staff_user();
$currentUser  = $staff['name'] ?? 'Aarav';
$currentTitle = $staff['display_title'] ?? 'Aarav (Admin)';
$isAdmin      = (($staff['role'] ?? '') === 'Admin' || $currentUser === 'Aarav');
$view         = $_GET['v'] ?? 'overview'; // 'overview' | 'brainstorm' | 'm'
$activeModId  = isset($_GET['m']) ? (int)$_GET['m'] : 0;

if ($activeModId > 0) {
    if (!is_module_allowed($activeModId, $staff)) {
        $activeModId = 0;
        $view = 'overview';
    } else {
        $view = 'm';
    }
}
if ($view === 'brainstorm' && !$isAdmin) {
    $view = 'overview';
}

$activeLlm = get_sys_setting('active_llm_provider', 'deepseek');
$myNotices = [];
$unreadCount = 0;
try {
    $nStmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient = ? ORDER BY id DESC LIMIT 40");
    $nStmt->execute([$currentUser]);
    $myNotices = $nStmt->fetchAll();
    $unreadCount = count(array_filter($myNotices, fn($n) => (int)$n['is_read'] === 0));
} catch (Throwable $e) {}

$MODULES = [
    1  => ['Overview & Pending Tasks',            'Business',   'आपके डिपार्टमेंट के आज के ज़रूरी काम और पूरी टीम के Pending Tasks (Read-Only)।', whmcs_deep_link('pending_orders')],
    2  => ['Customers & Service History',         'Business',   '915 active hosting accounts और हर ग्राहक की registration से लेकर tickets तक की पूरी कुंडली।', whmcs_deep_link('clients')],
    3  => ['Orders & Delivery Queue',             'Operations', 'Payment आने के बाद hosting (Chandrika) और domain (Priya/Sanju) की पक्की डिलीवरी।', whmcs_deep_link('pending_orders')],
    4  => ['Invoices, UPI Proofs & CA Sheet',     'Finance',    'Razorpay मिलान, WhatsApp UPI स्क्रीनशॉट अपलोडर, संजू मैम का वेरिफिकेशन और 1-Click CA CSV Export।', whmcs_deep_link('unpaid_invoices')],
    5  => ['Renewals (30-15-7-3-1 Pipeline)',     'Business',   'Customer due date और registrar expiry का अलग मिलान ताकि 40% renewal बढ़कर 75%+ हो।', whmcs_deep_link('domains_list')],
    6  => ['Retention & 120 Suspended Win-back',  'Business',   '120 suspended accounts से तुरंत ₹45,000+ रिकवर करने का Reactivation अभियान।', whmcs_deep_link('hosting_list')],
    7  => ['Support Desk & Website Live Tickets', 'Operations', 'Website Chat से बने Tickets, Zero-Link Email Reply और WHMCS Tickets एक साथ।', whmcs_deep_link('tickets')],
    8  => ['WhatsApp & Email Channels',           'Operations', 'Sales (7688855600) और Support (8696518872) की अलग-अलग queues और Verified Replies।', whmcs_deep_link('tickets')],
    9  => ['Team & Module Visibility Control',    'Operations', 'Aarav (Admin) यहाँ से तय करें कि किस टीम मेंबर को कौन-सा Module दिखेगा और कौन-सा छुपेगा।', whmcs_deep_link('default')],
    10 => ['WHM Servers (14 Ticked Privileges)',  'Operations', 'OVH SGP, Verpex, NameCrane के Active/Suspended counts, Suspend Reasons और Load।', whmcs_deep_link('default')],
    11 => ['Backups, >20GB Alert & Security',     'Operations', 'SGP ≤20GB नियम, >18GB storage upsell alert, और दीपक का Daily 10-cPanel MySQL check।', whmcs_deep_link('hosting_list')],
    12 => ['Master Domain Vault (26 Registrars)', 'Operations', '2,000+ registrar accounts के लिए WhatsApp .txt Importer और 1-सेकंड लॉगिन सर्च।', whmcs_deep_link('domains_list')],
    13 => ['Leads & Sales CRM (10m/30m/2h)',      'Growth',     '30-min human reply SLA (Admin Telegram alert) और चन्द्रिका के 10m, 30m, 2h price follow-ups।', whmcs_deep_link('pending_orders')],
    14 => ['Offers, Pricing & Prorated Upgrade',  'Growth',     'Unlimited Plans (0/0 Email/Backup) से Premium/Cloud में Prorated Upgrade Calculator।', whmcs_deep_link('clients')],
    15 => ['Ads Center (Meta CTWA & Google)',     'Growth',     '₹10,000 Meta Click-to-WhatsApp कैंपेन और High-Intent Google Search Ads ट्रैकिंग।', whmcs_deep_link('pending_orders')],
    16 => ['Marketing Strategy & 60-Day Plan',    'Growth',     '2 महीने में रेवेन्यू मैक्सिमाइज़ करने और टीम की सैलरी बढ़ाने का एक्शन प्लान।', whmcs_deep_link('default')],
    17 => ['Attribution & Channel ROI',           'Growth',     'किस एड, YouTuber या रेफरल से कितना खर्च हुआ और कितना शुद्ध मुनाफ़ा आया।', whmcs_deep_link('default')],
    18 => ['Content, SEO & Website Bug Fixes',    'Growth',     'SaluteHost lock, Canada VPS heading fix, WhatsApp link fix और SEO ट्यूटोरियल्स।', whmcs_deep_link('default')],
    19 => ['YouTube Creators & Affiliates 2.0',   'Growth',     'WHMCS aff.php के ज़रिए बिना झूठे वादे के 25-30% कमीशन पर क्रिएटर पार्टनरशिप।', whmcs_deep_link('affiliates')],
    20 => ['Reviews & CSAT Escalation Desk',      'Growth',     'Internal CSAT feedback (rate.php), शिकायतों का तुरंत समाधान और निष्पक्ष Trustpilot आमंत्रण।', 'rate.php'],
    21 => ['Costs, ₹64k Payroll & Net Profit',    'Finance',    'चन्द्रिका ₹15k, प्रिया ₹12k, संजू ₹12k, दीपक ₹25k, नाहर ₹3k और सर्वर बिल के बाद बचत।', whmcs_deep_link('unpaid_invoices')],
    22 => ['Forecasting & Salary Hike Math',      'Finance',    'आने वाले रिन्यूअल, सर्वर खर्च और Admin टारगेट के साथ सैलरी बढ़ोतरी का गणित।', whmcs_deep_link('unpaid_invoices')],
    23 => ['Daily 10 AM Briefing & History',      'Operations', 'सुबह 10 बजे के रिमाइंडर्स, पेंडिंग काम और नीचे सुरक्षित Resolved History।', whmcs_deep_link('pending_orders')],
    24 => ['Automation & 10m Snooze Control',     'Operations', '10-Min Unread Offer Snooze, Telegram SLA bot और 10m/30m/2h timers की निगरानी।', 'cron_engine.php'],
    25 => ['Integrations, WHM & Multi-LLM Keys',  'Operations', 'DeepSeek, Qwen, Gemini, Claude, ChatGPT API Keys और Telegram/WHM कनेक्शन सेंटर।', whmcs_deep_link('default')],
];

$allowedModuleIds = [];
foreach ($MODULES as $id => $m) {
    if (is_module_allowed($id, $staff)) {
        $allowedModuleIds[] = $id;
    }
}
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>AmbitionHost Master OS · <?=htmlspecialchars($currentTitle)?></title>
<style>
:root{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#0f172a;background:#f1f5f9}
*{box-sizing:border-box}body{margin:0}
button,input,select,textarea{font:inherit;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;background:#fff;color:inherit}
button{cursor:pointer;font-weight:600}button:hover{border-color:#2563eb;background:#eff6ff}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}.btn-primary:hover{background:#1d4ed8;color:#fff}
.btn-dark{background:#0f172a;color:#38bdf8;border-color:#0f172a;text-decoration:none;display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:8px;font-size:12px;font-weight:700}
.btn-dark:hover{background:#1e293b;color:#7dd3fc}
.btn-brain{background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#38bdf8;border:1px solid #38bdf8;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:800}
.btn-purple{background:#7c3aed;color:#fff;border-color:#7c3aed;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700}
header{position:sticky;top:0;background:#fff;z-index:20;border-bottom:1px solid #e2e8f0;padding:11px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.brand{font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px;text-decoration:none;color:#0f172a}
.brand b{display:grid;place-items:center;width:34px;height:34px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;border-radius:9px}
.pill{background:#fef3c7;color:#92400e;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:700;display:inline-block}
.pill-green{background:#dcfce7;color:#166534}.pill-red{background:#fee2e2;color:#991b1b}.pill-blue{background:#dbeafe;color:#1e40af}
.layout{display:grid;grid-template-columns:265px 1fr;max-width:1800px;margin:auto}
aside{padding:14px 10px;border-right:1px solid #e2e8f0;position:sticky;top:59px;height:calc(100vh - 59px);overflow-y:auto;background:#f8fafc}
.nav{display:block;text-decoration:none;color:#334155;padding:8px 10px;border-radius:8px;font-size:12px;margin:2px 0;font-weight:500}
.nav:hover{background:#e2e8f0}
.nav.active{background:#dbeafe;color:#1d4ed8;font-weight:700}
.navnum{color:#94a3b8;font-size:10px;margin-right:7px;font-weight:800}
main{padding:22px 26px;min-width:0}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:18px;margin-bottom:16px}
.stat strong{font-size:22px;display:block;margin:6px 0 3px}
.module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.module-card{background:#fff;border:1px solid #e2e8f0;padding:16px;border-radius:12px;display:flex;flex-direction:column;justify-content:space-between;gap:10px}
.module-card:hover{border-color:#2563eb;box-shadow:0 6px 18px rgba(37,99,235,.08)}
.twocol{display:grid;grid-template-columns:1.42fr 1fr;gap:18px;align-items:start}
table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
th{color:#64748b;font-size:10px;text-transform:uppercase;background:#f8fafc}
.ai-panel{background:#0f172a;color:#f8fafc;border-radius:14px;padding:18px;position:sticky;top:76px}
.ai-panel h3{margin:0 0 6px;color:#38bdf8;display:flex;justify-content:space-between;align-items:center;font-size:15px}
.ai-log{max-height:400px;overflow-y:auto;background:#1e293b;border-radius:10px;padding:12px;margin:10px 0;font-size:12.5px;line-height:1.65}
.ai-msg{margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #334155}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0}
dialog{border:1px solid #cbd5e1;border-radius:14px;padding:22px;max-width:680px;width:92%}
@media(max-width:1100px){.layout{grid-template-columns:1fr}aside{position:static;height:auto;display:flex;overflow-x:auto;gap:6px}.cards,.module-grid,.twocol{grid-template-columns:1fr}}
</style>
</head>
<body>

<header>
  <a href="index.php" class="brand"><b>A</b> AmbitionHost Master OS <span class="pill pill-blue"><?=htmlspecialchars($currentTitle)?></span></a>
  <div class="toolbar" style="margin:0">
    <?php if ($isAdmin): ?>
      <a href="index.php?v=brainstorm" class="btn-brain">🧠 Brainstorm & Offer Launcher (360° AI)</a>
    <?php endif; ?>
    <?php if (!empty($staff['can_graphs']) || $isAdmin): ?>
      <a href="analytics_engine.php" class="btn-purple">📊 Domain vs Hosting Graphs (WoW/MoM/YoY)</a>
    <?php endif; ?>
    <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('pending_orders'))?>" target="_blank">WHMCS Orders ↗</a>
    <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('tickets'))?>" target="_blank">WHMCS Tickets ↗</a>
    <button onclick="document.getElementById('profileModal').showModal()" style="font-size:12px;padding:6px 10px">🔑 Profile / Password</button>
    <button onclick="document.getElementById('bellModal').showModal()">🔔 Alerts (<?=$unreadCount?>)</button>
    <a href="index.php?logout=1" class="btn-dark" style="background:#991b1b;color:#fff">Logout</a>
  </div>
</header>

<div class="layout">
  <aside>
    <a href="index.php" class="nav <?=$view==='overview'?'active':''?>">⌂ 00 / My Workspace Overview</a>
    <?php if ($isAdmin): ?>
      <a href="index.php?v=brainstorm" class="nav <?=$view==='brainstorm'?'active':''?>" style="background:#0f172a;color:#38bdf8;font-weight:800;margin:4px 0">🧠 360° Brainstorm & Offer Engine</a>
    <?php endif; ?>
    <?php if (!empty($staff['can_graphs']) || $isAdmin): ?>
      <a href="analytics_engine.php" class="nav" style="color:#7c3aed;font-weight:700">📊 Domain vs Hosting Graphs</a>
    <?php endif; ?>
    <div style="font-size:10px;font-weight:800;color:#64748b;margin:10px 8px 4px;text-transform:uppercase">Allowed Modules (<?=count($allowedModuleIds)?>/25)</div>
    <?php foreach ($allowedModuleIds as $id): $m = $MODULES[$id]; ?>
      <a href="index.php?m=<?=$id?>" class="nav <?=($view==='m' && $activeModId===$id)?'active':''?>">
        <span class="navnum"><?=sprintf('%02d', $id)?></span><?=htmlspecialchars($m[0])?>
      </a>
    <?php endforeach; ?>
  </aside>

  <main>
    <?php if ($view === 'brainstorm' && $isAdmin): ?>
      <!-- 🧠 360° GLOBAL BRAINSTORM & AUTONOMOUS OFFER LAUNCHER (EXCLUSIVE TO AARAV ADMIN) -->
      <div class="twocol">
        <div>
          <div class="box" style="border-top:4px solid #2563eb">
            <span class="pill pill-blue">🔒 Exclusive to Aarav (Admin) · Autonomous Team Activation</span>
            <h1 style="margin:8px 0 4px;font-size:24px">🧠 Master Brainstorm & Autonomous Offer Engine</h1>
            <p style="font-size:12.5px;color:#475569;margin-top:0">
              यहाँ आप जैसे ही कोई नया <strong>Offer</strong> (जैसे: <em>".IN का नॉर्मल प्राइस 600 है, आज 299 का ऑफर लॉन्च करना है, प्लानिंग करो"</em>) या <strong>Urgent Action</strong> लिखेंगे—यह इंजन पूरी रणनीति बनाकर <strong>बिना आपसे पूछे अपने-आप Chandrika (Sales) और Deepak (B.D.E.) को टास्क व अलर्ट भेज देगा</strong>! अगर वे 10 मिनट में Read नहीं करेंगे, तो उन्हें हर 10 मिनट पर लगातार अलार्म जाएगा।
            </p>
            <h3>📜 Latest System Activity & Audit Logs (Live Across All 25 Modules)</h3>
            <table>
              <thead><tr><th>Time</th><th>Actor</th><th>Module</th><th>Event Summary</th></tr></thead>
              <tbody>
                <?php foreach ($pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 20") as $lg): ?>
                  <tr>
                    <td><small><?=$lg['created_at']?></small></td>
                    <td><b><?=htmlspecialchars($lg['actor'])?></b></td>
                    <td><span class="pill pill-blue">M<?=sprintf('%02d', (int)$lg['module_id'])?></span></td>
                    <td><?=htmlspecialchars($lg['event_summary'])?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ai-panel" style="border:2px solid #38bdf8">
          <h3><span>🧠 360° Brainstorm & Offer Launcher</span> <span class="pill pill-green"><?=strtoupper(htmlspecialchars($activeLlm))?></span></h3>
          <div class="toolbar" style="margin:6px 0">
            <button type="button" onclick="sendBrainstormQuick('Hello')" style="background:#1e293b;color:#38bdf8;border-color:#334155;font-size:11px">👋 Say "Hello" (Start Daily Check)</button>
            <button type="button" onclick="sendBrainstormQuick('.IN domain का normal price 600 आता है, आज 299 है—इस Offer की पूरी planning करके लॉन्च करो!')" style="background:#1e293b;color:#fbbf24;border-color:#334155;font-size:11px">🚀 Launch ₹299 .IN Offer (Auto-Activate Team)</button>
          </div>
          <div class="ai-log" id="brainLog">
            <?php
            $bChats = array_reverse($pdo->query("SELECT * FROM ai_module_chats WHERE module_id = 0 ORDER BY id DESC LIMIT 10")->fetchAll());
            if (empty($bChats)):
            ?>
              <div class="ai-msg"><strong style="color:#38bdf8">GLOBAL AI BRAIN:</strong><br>नमस्ते <b>Aarav (Admin)</b>! आज तुम <b>AmbitionHost</b> में क्या नया करने वाले हो? या तुम्हारा कोई नया <b>Offer</b> या <b>Planning</b> है तो बताओ!</div>
            <?php else: foreach ($bChats as $bc): ?>
              <div class="ai-msg">
                <strong style="color:#fbbf24"><?=htmlspecialchars($bc['user_name'])?>:</strong> <?=nl2br(htmlspecialchars($bc['user_prompt']))?><br>
                <strong style="color:#38bdf8">GLOBAL BRAIN (<?=htmlspecialchars($bc['llm_provider'])?>):</strong><br><?=nl2br(htmlspecialchars($bc['ai_response']))?>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <form id="brainForm">
            <input type="hidden" name="action" value="ask_global_brainstorm">
            <label style="font-size:11.5px;color:#38bdf8;display:flex;align-items:center;gap:6px;margin-bottom:6px">
              <input type="checkbox" name="force_offer_launch" value="1" checked>
              🚀 Auto-Dispatch Strategy & 10-Min Snooze Alert to Chandrika (Sales) & Deepak (B.D.E.)
            </label>
            <textarea name="prompt" id="brainPrompt" rows="3" style="width:100%;background:#1e293b;color:#fff;border-color:#334155;margin-bottom:8px" placeholder="यहाँ 'Hello' लिखें या कोई भी नया Offer / Urgent Action बताएं..." required></textarea>
            <button type="submit" class="btn-primary" style="width:100%" id="brainBtn">Execute in Brainstorm Engine →</button>
          </form>
        </div>
      </div>

    <?php elseif ($view === 'overview'): 
      $myPend = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND owner=" . $pdo->quote($currentUser))->fetchColumn();
      $allPend = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending'")->fetchColumn();
    ?>
      <!-- ROLE-FILTERED OVERVIEW (STAFF ONLY SEE THEIR ALLOWED MODULES!) -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <span class="pill pill-blue">Logged in: <?=htmlspecialchars($currentTitle)?> · Allowed Modules: <?=count($allowedModuleIds)?> / 25</span>
          <h1 style="margin:8px 0 2px;font-size:24px">नमस्ते <?=htmlspecialchars($currentTitle)?> — आज के ज़रूरी काम</h1>
          <small style="color:#64748b">यहाँ सिर्फ आपके डिपार्टमेंट से जुड़े Modules दिख रहे हैं (अन्य गैर-ज़रूरी Modules छुपा दिए गए हैं)।</small>
        </div>
        <div class="toolbar">
          <?php if (is_module_allowed(4, $staff)): ?>
            <a class="btn-dark" href="api.php?action=export_ca_csv">⬇ Download CA GST Sheet (CSV)</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="cards">
        <div class="box stat" style="border-top:4px solid #2563eb"><small>मेरे खुद के Pending Tasks</small><strong><?=$myPend?> My Tasks</strong><small>10-Min snooze active on Urgent Offers</small></div>
        <div class="box stat" style="border-top:4px solid #64748b"><small>पूरी टीम के Pending Tasks (Read-Only)</small><strong><?=$allPend?> Total Pending</strong><small>Shared visibility</small></div>
        <div class="box stat" style="border-top:4px solid #16a34a"><small>मेरे डिपार्टमेंट के Modules</small><strong><?=count($allowedModuleIds)?> Modules</strong><small>Department-scoped</small></div>
        <div class="box stat" style="border-top:4px solid #7c3aed"><small>Discount / Refund Rule</small><strong>Max <?=$staff['max_discount_pct']?>%</strong><small>Refunds: Aarav (Admin) Only</small></div>
      </div>

      <h2>आपके डिपार्टमेंट के उपलब्ध Control Modules (<?=count($allowedModuleIds)?> Modules)</h2>
      <div class="module-grid">
        <?php foreach ($allowedModuleIds as $id): $m = $MODULES[$id]; ?>
          <div class="module-card">
            <div>
              <span class="pill pill-blue"><?=sprintf('%02d', $id)?> · <?=$m[1]?></span>
              <h3 style="margin:9px 0 5px;font-size:15px"><?=htmlspecialchars($m[0])?></h3>
              <p style="font-size:12px;color:#475569;margin:0"><?=htmlspecialchars($m[2])?></p>
            </div>
            <div class="toolbar" style="margin:8px 0 0;justify-content:space-between">
              <a href="index.php?m=<?=$id?>" class="btn-primary" style="text-decoration:none;padding:7px 12px;border-radius:8px;font-size:12px">Open Module + AI →</a>
              <a href="<?=htmlspecialchars($m[3])?>" target="_blank" class="btn-dark">WHMCS ↗</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: 
      $curMod = $MODULES[$activeModId];
    ?>
      <!-- INDIVIDUAL MODULE WORKSPACE (01 TO 25) + DEDICATED TOOLS + SCOPED MODULE AI -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div>
          <a href="index.php" style="font-size:12px;font-weight:700;text-decoration:none">← Back to Allowed Modules</a>
          <h1 style="margin:6px 0 2px;font-size:24px">Module <?=sprintf('%02d', $activeModId)?>: <?=htmlspecialchars($curMod[0])?></h1>
          <small style="color:#64748b"><?=htmlspecialchars($curMod[2])?></small>
        </div>
        <a href="<?=htmlspecialchars($curMod[3])?>" target="_blank" class="btn-dark">Edit Directly in WHMCS (/user/devokedev/) ↗</a>
      </div>

      <div class="twocol">
        <!-- LEFT COLUMN: LOADS ALL 25 DEDICATED MODULE ENGINES FROM modules_workspace.php + TASK QUEUE -->
        <div>
          <?php
          if (file_exists(__DIR__ . '/modules_workspace.php')) {
              require __DIR__ . '/modules_workspace.php';
          }
          ?>

          <?php if ($activeModId === 7): ?>
            <!-- MODULE 07: WEBSITE LIVE CHAT TICKETS INBOX (TRIGGERS STRICT ZERO-LINK EMAIL ON REPLY) -->
            <div class="box" style="border-left:4px solid #2563eb">
              <h3>🎫 Website Chat & Support Tickets Inbox (Replies Send Strict ZERO-LINK Email to Customer)</h3>
              <table>
                <thead><tr><th>Ticket #</th><th>Customer Email & Subject</th><th>Status</th><th>Send Reply (Triggers Zero-Link Email)</th></tr></thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM support_tickets_local ORDER BY updated_at DESC LIMIT 25") as $tk): ?>
                    <tr>
                      <td><b><?=htmlspecialchars($tk['ticket_code'])?></b></td>
                      <td><b><?=htmlspecialchars($tk['customer_email'])?></b><br><?=htmlspecialchars($tk['subject'])?></td>
                      <td><span class="pill"><?=$tk['status']?></span></td>
                      <td>
                        <div class="toolbar" style="margin:0">
                          <input id="rep_<?=$tk['id']?>" placeholder="Type reply...">
                          <button class="btn-primary" onclick="sendTicketReply(<?=$tk['id']?>)">Reply + Zero-Link Mail</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($activeModId === 9 && $isAdmin): ?>
            <!-- MODULE 09: DYNAMIC ROLE VISIBILITY MATRIX & STAFF MANAGEMENT FOR AARAV (ADMIN) -->
            <div class="box" style="border-left:4px solid #0f172a">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
                <div>
                  <h3 style="margin:0">👥 Team Staff Profiles & Credentials</h3>
                  <small style="color:#64748b">स्टाफ मेंबर्स के प्रोफाइल्स, सैलरी, डिस्काउंट लिमिट और पासवर्ड यहाँ से मैनेज करें:</small>
                </div>
                <button class="btn-primary" onclick="document.getElementById('profileModal').showModal()">🔑 Change Password / Email</button>
              </div>

              <table>
                <thead>
                  <tr>
                    <th>Staff Member</th>
                    <th>Email</th>
                    <th>Role & Dept</th>
                    <th>Salary</th>
                    <th>Permissions</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pdo->query("SELECT * FROM users ORDER BY id ASC") as $usr): 
                    $roleBadges = [
                      'Admin' => '<span class="pill pill-red">👑 Admin</span>',
                      'B.D.E.' => '<span class="pill pill-blue">🛡️ B.D.E.</span>',
                      'Sales' => '<span class="pill" style="background:#fef3c7;color:#92400e">💼 Sales</span>',
                      'Domain' => '<span class="pill pill-blue">🌐 Domain</span>',
                      'Billing' => '<span class="pill pill-green">💳 Billing</span>',
                    ];
                  ?>
                    <tr>
                      <td><b><?=htmlspecialchars($usr['display_title'] ?? $usr['name'])?></b></td>
                      <td><code><?=htmlspecialchars($usr['email'])?></code></td>
                      <td><?=$roleBadges[$usr['role']] ?? htmlspecialchars($usr['role'])?><br><small><?=htmlspecialchars($usr['department'])?></small></td>
                      <td><b>₹<?=number_format((float)$usr['monthly_salary'], 2)?></b>/mo</td>
                      <td>
                        <small>
                          Refund: <?=$usr['can_refund'] ? '✅ Yes' : '❌ No'?><br>
                          Discount: <?=$usr['can_discount'] ? '✅ Up to '.$usr['max_discount_pct'].'%' : '❌ No'?>
                        </small>
                      </td>
                      <td>
                        <button style="font-size:11px;padding:4px 8px" onclick="openCredsFor(<?=$usr['id']?>)">🔑 Edit</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <h3 style="margin-top:22px">🛡️ 25x4 Role-Based Module Visibility Matrix (Aarav Admin Control)</h3>
              <p style="font-size:12px;color:#64748b;margin:0 0 10px">चेकबॉक्स टिक या अनटिक करते ही संबंधित स्टाफ के साइडबार व डैशबोर्ड में वह मॉड्यूल रियल-टाइम में दिखेगा या छुप जाएगा:</p>
              <table>
                <thead><tr><th>Module</th><th>Deepak (B.D.E.)</th><th>Chandrika (Sales)</th><th>Priya (Domain)</th><th>Sanju (Billing)</th></tr></thead>
                <tbody>
                  <?php
                  $allUsers = [];
                  foreach ($pdo->query("SELECT * FROM users") as $ur) $allUsers[$ur['name']] = $ur;
                  foreach ($MODULES as $mid => $mInfo):
                  ?>
                    <tr>
                      <td><b><?=sprintf('%02d', $mid)?> · <?=htmlspecialchars($mInfo[0])?></b></td>
                      <?php foreach (['Deepak','Chandrika','Priya','Sanju'] as $sName): 
                        $hasMod = is_module_allowed($mid, $allUsers[$sName] ?? []);
                      ?>
                        <td>
                          <label style="cursor:pointer">
                            <input type="checkbox" <?=$hasMod?'checked':''?> <?=$mid===1?'disabled':''?> onchange="toggleVisibility('<?=$sName?>', <?=$mid?>)">
                            <?=$hasMod ? '<span style="color:#16a34a;font-weight:700">Visible</span>' : '<span style="color:#94a3b8">Hidden</span>'?>
                          </label>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($activeModId === 25 && $isAdmin): ?>
            <!-- MODULE 25: WHMCS API, MULTI-LLM & TELEGRAM CREDENTIALS CENTER -->
            <div class="box" style="border-left:4px solid #2563eb;margin-bottom:18px">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                <div>
                  <h3 style="margin:0 0 4px">🌐 WHMCS Read-Only API Integration</h3>
                  <small style="color:#64748b">Clients, Invoices, Renewals, Suspended Accounts aur Tickets sync karne ke liye.</small>
                </div>
                <button type="button" id="btnTestWhmcs" onclick="testWhmcsConn()" class="btn-primary" style="background:#059669;border-color:#059669">🔍 Test WHMCS Connection</button>
              </div>

              <div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;margin:14px 0;font-size:13px;line-height:1.5;color:#334155">
                💡 <b>WHMCS Me Key Kaise Banayein:</b><br>
                1. WHMCS Admin me jayein: <code>Setup ➔ Staff Management ➔ Manage API Credentials</code> (ya WHMCS 8.x me <code>System Settings ➔ API Credentials</code>)।<br>
                2. <b>Generate New API Credential</b> par click karein aur sirf <b>Read-Only</b> permissions select karein.<br>
                3. Apne cPanel Server ka IP (<code><?=gethostbyname($_SERVER['SERVER_NAME'] ?? 'control.jixsite.com')?></code>) WHMCS API IP Access Restriction me whitelist karein.
              </div>

              <form id="whmcsSettingsForm" class="toolbar" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
                <input type="hidden" name="action" value="save_system_settings">
                <div>
                  <label style="font-size:12px;color:#64748b;font-weight:700">WHMCS URL</label>
                  <input name="whmcs_url" value="<?=htmlspecialchars(get_sys_setting('whmcs_url', defined('WHMCS_URL')?WHMCS_URL:'https://www.ambitionhost.in/user'))?>" placeholder="https://www.ambitionhost.in/user" required>
                </div>
                <div>
                  <label style="font-size:12px;color:#64748b;font-weight:700">API Identifier</label>
                  <input name="whmcs_api_identifier" value="<?=htmlspecialchars(get_sys_setting('whmcs_api_identifier', (defined('WHMCS_API_IDENTIFIER') && strpos(WHMCS_API_IDENTIFIER,'YOUR_')!==0)?WHMCS_API_IDENTIFIER:''))?>" placeholder="Alphanumeric Identifier Key" required>
                </div>
                <div>
                  <label style="font-size:12px;color:#64748b;font-weight:700">API Secret</label>
                  <input type="password" name="whmcs_api_secret" value="<?=htmlspecialchars(get_sys_setting('whmcs_api_secret', (defined('WHMCS_API_SECRET') && strpos(WHMCS_API_SECRET,'YOUR_')!==0)?WHMCS_API_SECRET:''))?>" placeholder="••••••••••••••••" required>
                </div>
                <div style="display:flex;align-items:flex-end">
                  <button type="submit" class="btn-primary" style="width:100%">💾 Save WHMCS Keys</button>
                </div>
              </form>
            </div>

            <div class="box" style="border-left:4px solid #7c3aed;margin-bottom:18px">
              <h3 style="margin:0 0 6px">🤖 Multi-LLM AI Brainstorm Keys & Active Provider</h3>
              <small style="color:#64748b;display:block;margin-bottom:14px">DeepSeek, Qwen, Gemini, Claude, OpenAI keys ko live database me save karein.</small>
              <form id="settingsForm">
                <input type="hidden" name="action" value="save_system_settings">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Active AI Brain</label>
                    <select name="active_llm_provider" style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid #cbd5e1">
                      <?php foreach (['deepseek','qwen','gemini','openai','claude'] as $p): ?>
                        <option value="<?=$p?>" <?=$activeLlm===$p?'selected':''?>>Active Brain: <?=strtoupper($p)?> <?=($p==='deepseek'?'(Recommended Primary)':'')?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">DeepSeek API Key (Primary)</label>
                    <input type="password" name="api_key_deepseek" value="<?=htmlspecialchars(get_sys_setting('api_key_deepseek',''))?>" placeholder="sk-...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Qwen / DashScope Key</label>
                    <input type="password" name="api_key_qwen" value="<?=htmlspecialchars(get_sys_setting('api_key_qwen',''))?>" placeholder="sk-...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Google Gemini API Key</label>
                    <input type="password" name="api_key_gemini" value="<?=htmlspecialchars(get_sys_setting('api_key_gemini',''))?>" placeholder="AIzaSy...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">OpenAI API Key</label>
                    <input type="password" name="api_key_openai" value="<?=htmlspecialchars(get_sys_setting('api_key_openai',''))?>" placeholder="sk-...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Anthropic Claude Key</label>
                    <input type="password" name="api_key_claude" value="<?=htmlspecialchars(get_sys_setting('api_key_claude',''))?>" placeholder="sk-ant-...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Telegram Bot Token (Alerts)</label>
                    <input type="password" name="telegram_bot_token" value="<?=htmlspecialchars(get_sys_setting('telegram_bot_token',''))?>" placeholder="bot123456:ABC-DEF...">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#64748b;font-weight:700">Aarav Telegram Chat ID</label>
                    <input name="aarav_telegram_chat_id" value="<?=htmlspecialchars(get_sys_setting('aarav_telegram_chat_id',''))?>" placeholder="123456789">
                  </div>
                </div>
                <div style="margin-top:14px;text-align:right">
                  <button type="submit" class="btn-primary" style="background:#7c3aed;border-color:#7c3aed">💾 Save AI & Telegram Keys</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <!-- UNIVERSAL TASK & ACCOUNTABILITY QUEUE FOR THIS MODULE -->
          <div class="box">
            <h3>📋 Tasks & Accountability Queue (Module <?=sprintf('%02d', $activeModId)?>)</h3>
            <form id="newTaskForm" class="toolbar">
              <input type="hidden" name="action" value="create_task">
              <input type="hidden" name="module_id" value="<?=$activeModId?>">
              <input name="title" placeholder="Task title..." required style="flex:1">
              <input name="description" placeholder="Details / Instructions..." style="flex:1">
              <select name="owner">
                <?php foreach (['Chandrika','Priya','Sanju','Deepak','Aarav'] as $p): ?><option value="<?=$p?>"><?=$p?></option><?php endforeach; ?>
              </select>
              <input name="whmcs_link" placeholder="Optional WHMCS URL" value="<?=htmlspecialchars($curMod[3])?>">
              <button type="submit" class="btn-primary">+ Assign Task</button>
            </form>
            <table>
              <thead><tr><th>Task & Proof</th><th>Owner & Access</th><th>WHMCS</th><th>Controls</th></tr></thead>
              <tbody>
                <?php
                $tStmt = $pdo->prepare("SELECT * FROM tasks WHERE (module_id = ? OR ? IN (1,23)) ORDER BY status ASC, id DESC LIMIT 35");
                $tStmt->execute([$activeModId, $activeModId]);
                foreach ($tStmt->fetchAll() as $t):
                  $grants = array_filter(array_map('trim', explode(',', (string)$t['granted_users'])));
                  $canEdit = ($t['owner'] === $currentUser || in_array($currentUser, $grants, true));
                ?>
                  <tr>
                    <td>
                      <b>#<?=$t['id']?> · <?=htmlspecialchars($t['title'])?></b> <span class="pill"><?=$t['status']?></span>
                      <p style="margin:4px 0;font-size:12px;color:#475569"><?=nl2br(htmlspecialchars((string)$t['description']))?></p>
                      <?php if ($t['resolution_evidence']): ?><div class="pill pill-green">✅ Proof: <?=htmlspecialchars($t['resolution_evidence'])?></div><?php endif; ?>
                    </td>
                    <td>
                      <b><?=htmlspecialchars($t['owner'])?></b>
                      <?php if ($t['previous_owners']): ?><br><small>Prev: <?=htmlspecialchars($t['previous_owners'])?></small><?php endif; ?>
                      <br><small style="color:<?=$canEdit?'#166534':'#b45309'?>"><?=$canEdit?'✔ Editable':'🔒 Read-Only'?></small>
                    </td>
                    <td><?php if ($t['whmcs_link']): ?><a class="btn-dark" href="<?=htmlspecialchars($t['whmcs_link'])?>" target="_blank">WHMCS ↗</a><?php endif; ?></td>
                    <td>
                      <?php if ($canEdit && $t['status'] === 'Pending'): ?>
                        <button class="btn-primary" onclick="resolveTaskPrompt(<?=$t['id']?>)">✔ Resolve</button>
                        <button onclick="transferTaskPrompt(<?=$t['id']?>)">⇄ Transfer</button>
                      <?php endif; ?>
                      <?php if ($isAdmin && !$canEdit && $t['status'] === 'Pending'): ?>
                        <button onclick="grantAccess(<?=$t['id']?>, 'Aarav')">🔓 Admin Grant Edit</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- RIGHT COLUMN: SCOPED MODULE AI ADVISOR -->
        <div class="ai-panel">
          <h3><span>🤖 Module <?=sprintf('%02d', $activeModId)?> Scoped AI</span> <span class="pill pill-green"><?=strtoupper(htmlspecialchars($activeLlm))?></span></h3>
          <div class="ai-log">
            <?php
            $cStmt = $pdo->prepare("SELECT * FROM ai_module_chats WHERE module_id = ? ORDER BY id DESC LIMIT 8");
            $cStmt->execute([$activeModId]);
            $chats = array_reverse($cStmt->fetchAll());
            if (empty($chats)):
            ?>
              <div class="ai-msg">नमस्ते <b><?=htmlspecialchars($currentTitle)?></b>! मैं <b><?=htmlspecialchars($curMod[0])?></b> का Scoped AI Advisor हूँ। आप मुझसे इस मॉड्यूल के किसी भी टास्क, पॉलिसी या कस्टमर रिप्लाई के बारे में पूछ सकते हैं।</div>
            <?php else: foreach ($chats as $c): ?>
              <div class="ai-msg">
                <strong style="color:#fbbf24"><?=htmlspecialchars($c['user_name'])?>:</strong> <?=nl2br(htmlspecialchars($c['user_prompt']))?><br>
                <strong style="color:#38bdf8">AI:</strong><br><?=nl2br(htmlspecialchars($c['ai_response']))?>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <form id="modAiForm">
            <input type="hidden" name="action" value="ask_ai">
            <input type="hidden" name="module_id" value="<?=$activeModId?>">
            <input type="hidden" name="module_title" value="<?=htmlspecialchars($curMod[0])?>">
            <textarea name="prompt" rows="3" style="width:100%;background:#1e293b;color:#fff;border-color:#334155;margin-bottom:8px" placeholder="इस Module के बारे में सवाल पूछें..." required></textarea>
            <button type="submit" class="btn-primary" style="width:100%">Ask Module AI →</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<!-- BELL NOTIFICATIONS DIALOG -->
<dialog id="bellModal">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3>🔔 Notifications for <?=htmlspecialchars($currentTitle)?></h3>
    <button onclick="document.getElementById('bellModal').close()">✕</button>
  </div>
  <?php foreach ($myNotices as $n): ?>
    <div style="padding:10px;border-bottom:1px solid #e2e8f0;border-left:4px solid <?=$n['is_read']?'#cbd5e1':'#dc2626'?>;margin-bottom:6px">
      <div style="font-size:13px;font-weight:<?=$n['is_read']?'400':'700'?>"><?=htmlspecialchars($n['message'])?></div>
      <small style="color:#64748b"><?=$n['created_at']?> · <?=$n['is_read']?'Read':'Unread (10m Snooze Active if Urgent Offer)'?></small>
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
    <?php if ($isAdmin): ?>
      <label style="font-size:12px;font-weight:700">Select Team Member:</label>
      <select name="target_user_id" id="profileTargetUser" style="width:100%;margin-bottom:10px">
        <?php foreach ($pdo->query("SELECT id, name, email, display_title FROM users ORDER BY id ASC") as $u): ?>
          <option value="<?=$u['id']?>"><?=$u['display_title'] ?? $u['name']?> (<?=$u['email']?>)</option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="target_user_id" value="<?=(int)$_SESSION['user_id']?>">
      <p style="font-size:13px;margin:0 0 10px">Logged in as: <b><?=htmlspecialchars($currentTitle)?></b></p>
    <?php endif; ?>

    <label style="font-size:12px;font-weight:700">New Email (Optional):</label>
    <input type="email" name="new_email" placeholder="Leave blank if unchanged" style="width:100%;margin-bottom:10px">

    <label style="font-size:12px;font-weight:700">New Password:</label>
    <input type="password" name="new_password" placeholder="At least 6 characters" style="width:100%;margin-bottom:14px" required>

    <button type="submit" class="btn-primary" style="width:100%">Save New Password →</button>
  </form>
</dialog>

<script>
async function postApi(fd) {
  const r = await fetch('api.php', { method: 'POST', body: fd });
  const d = await r.json();
  if (d.status !== 'ok') alert(d.message || 'Error');
  return d;
}

function openCredsFor(userId) {
  const modal = document.getElementById('profileModal');
  const sel = document.getElementById('profileTargetUser');
  if (sel) sel.value = userId;
  modal.showModal();
}

const profForm = document.getElementById('profileForm');
if (profForm) profForm.onsubmit = async (e) => {
  e.preventDefault();
  const r = await postApi(new FormData(profForm));
  if (r.status === 'ok') {
    alert('✅ Credentials / Password successfully updated!');
    location.reload();
  }
};

const brainForm = document.getElementById('brainForm');
if (brainForm) brainForm.onsubmit = async (e) => {
  e.preventDefault();
  const btn = document.getElementById('brainBtn');
  btn.textContent = 'Planning & Auto-Dispatching to Chandrika & Deepak...'; btn.disabled = true;
  const d = await postApi(new FormData(brainForm));
  if (d.auto_dispatched) alert('🚀 Offer/Urgent Strategy Auto-Dispatched to Chandrika (Sales) & Deepak (B.D.E.) with 10-Min Repeating Snooze Alarm!');
  location.reload();
};

function sendBrainstormQuick(txt) {
  document.getElementById('brainPrompt').value = txt;
  brainForm.requestSubmit();
}

const modAiForm = document.getElementById('modAiForm');
if (modAiForm) modAiForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(modAiForm)); location.reload();
};

const newTaskForm = document.getElementById('newTaskForm');
if (newTaskForm) newTaskForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(newTaskForm)); location.reload();
};

const setForm = document.getElementById('settingsForm');
if (setForm) setForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(setForm)); alert('✅ Settings Saved!'); location.reload();
};

const whmcsSetForm = document.getElementById('whmcsSettingsForm');
if (whmcsSetForm) whmcsSetForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(whmcsSetForm)); alert('✅ WHMCS API Settings Saved to Database!'); location.reload();
};

async function testWhmcsConn() {
  const btn = document.getElementById('btnTestWhmcs');
  const oldText = btn ? btn.textContent : '';
  if (btn) { btn.textContent = 'Connecting...'; btn.disabled = true; }
  const fd = new FormData();
  fd.append('action', 'test_whmcs_connection');
  const r = await postApi(fd);
  if (btn) { btn.textContent = oldText; btn.disabled = false; }
  alert(r.message || 'WHMCS Connection Test Completed');
}

const payForm = document.getElementById('paymentForm');
if (payForm) payForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(payForm)); location.reload();
};

const whmForm = document.getElementById('addWhmForm');
if (whmForm) whmForm.onsubmit = async (e) => {
  e.preventDefault(); await postApi(new FormData(whmForm)); location.reload();
};

async function verifyPay(id, sub) {
  const fd = new FormData(); fd.append('action', 'payment_action'); fd.append('pay_sub', sub); fd.append('payment_row_id', id);
  await postApi(fd); location.reload();
}

async function syncWhm(id) {
  const fd = new FormData(); fd.append('action', 'whm_action'); fd.append('whm_sub', 'sync_server'); fd.append('server_id', id);
  await fetch('api.php', { method: 'POST', body: fd }); location.reload();
}

async function importVault() {
  const txt = document.getElementById('vaultRawText').value;
  const fd = new FormData(); fd.append('action', 'import_domain_vault'); fd.append('vault_text', txt);
  const r = await postApi(fd); alert('⚡ Imported ' + (r.imported_count || 0) + ' domains into Master Domain Vault!'); location.reload();
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

async function toggleVisibility(staffName, modId) {
  const fd = new FormData(); fd.append('action', 'toggle_module_visibility'); fd.append('staff_name', staffName); fd.append('module_id', modId);
  await postApi(fd);
}

async function markNoticeRead(id) {
  const fd = new FormData(); fd.append('action', 'read_notification'); fd.append('notification_id', id);
  await postApi(fd); location.reload();
}

async function resolveTaskPrompt(id) {
  const ev = prompt('काम पूरा होने का प्रमाण (Resolution Evidence) दर्ज करें:');
  if (!ev) return;
  const fd = new FormData(); fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'resolve'); fd.append('evidence', ev);
  await postApi(fd); location.reload();
}

async function transferTaskPrompt(id) {
  const to = prompt('किस टीम मेंबर को Transfer करना है? (Chandrika, Priya, Sanju, Deepak, Aarav)');
  if (!to) return;
  const fd = new FormData(); fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'transfer'); fd.append('new_owner', to);
  await postApi(fd); location.reload();
}

async function grantAccess(id, person) {
  const fd = new FormData(); fd.append('action', 'update_task'); fd.append('task_id', id); fd.append('sub_action', 'grant_access'); fd.append('grant_to', person);
  await postApi(fd); location.reload();
}

async function sendTicketReply(tid) {
  const msg = document.getElementById('rep_' + tid).value;
  const fd = new FormData(); fd.append('action', 'reply_local_ticket'); fd.append('ticket_id', tid); fd.append('reply_message', msg);
  await postApi(fd); alert('✅ Reply saved & Zero-Link Email sent to customer!'); location.reload();
}

function calcProrate() {
  const o = parseFloat(document.getElementById('oldRate').value) || 0;
  const n = parseFloat(document.getElementById('newRate').value) || 0;
  const m = parseInt(document.getElementById('remMonths').value) || 0;
  const d = parseInt(document.getElementById('extraDays').value) || 0;
  const diffMonth = n - o;
  const diffDay = (n / 30) - (o / 30);
  const total = Math.max(0, (diffMonth * m) + (diffDay * d));
  document.getElementById('prorateResult').innerHTML = '⚡ ग्राहक द्वारा देय प्रोरैटेड अपग्रेड अंतर: <b>₹' + total.toFixed(2) + ' + 18% GST (यदि लागू)</b>';
}

function copyRenewalTemplate(dom, due, amt) {
  const txt = `नमस्ते! आपका AmbitionHost पर डोमेन/होस्टिंग (${dom}) दिनांक ${due} को एक्सपायर हो रहा है। अनवरत सेवा के लिए कृपया ₹${amt} का रिन्यूअल पूरा करें। सहायता के लिए +91 7688855600 पर संपर्क करें।`;
  navigator.clipboard.writeText(txt);
  alert('📋 WhatsApp Reminder कॉपी हो गया:\n\n' + txt);
}

function copyWinbackTemplate(dom) {
  const txt = `नमस्ते! आपका cPanel अकाउंट (${dom}) पेमेंट ओवरड्यू के कारण रुका हुआ है। AmbitionHost की ओर से आज आपके लिए 'Zero Late Fee' स्पेशल ऑफर है। अभी रिन्यू करवाएं और अपनी साइट 10 मिनट में दोबारा लाइव पाएं!`;
  navigator.clipboard.writeText(txt);
  alert('📋 Win-Back Offer कॉपी हो गया:\n\n' + txt);
}

function copyCanned(id) {
  const el = document.getElementById('canned_' + id);
  if (el) {
    navigator.clipboard.writeText(el.textContent.trim());
    alert('📋 Canned Reply क्लिपबोर्ड में कॉपी हो गया!');
  }
}

function copyBackupPitch(dom) {
  const txt = `नमस्ते! आपके cPanel अकाउंट (${dom}) का डिस्क साइज़ 20 GB से अधिक हो चुका है। सर्वर पॉलिसी के तहत >20GB अकाउंट्स ऑटोमैटिक जेटबैकअप से बाहर रहते हैं। अपने डेटा की 100% सुरक्षा के लिए हमारा ₹199/माह का Extended Backup Add-on तुरंत चालू करवाएं।`;
  navigator.clipboard.writeText(txt);
  alert('📋 Backup Pitch कॉपी हो गया:\n\n' + txt);
}
</script>
</body>
</html>