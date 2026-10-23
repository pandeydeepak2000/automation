<?php
// modules_workspace.php - Dedicated Operational Workspaces for All 25 Modules
declare(strict_types=1);

// Ensure required variables exist
if (!isset($activeModId, $pdo, $currentUser, $staff, $isAdmin)) {
    return;
}

// ==========================================
// MODULE 01: OVERVIEW & PENDING TASKS
// ==========================================
if ($activeModId === 1):
?>
<div class="box" style="border-left:4px solid #2563eb">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <h3 style="margin:0">📌 My Priority Queue & Department Pending Tasks</h3>
      <small style="color:#64748b">आपका काम Editable है; दूसरे सदस्यों का काम Read-Only रहता है।</small>
    </div>
    <span class="pill pill-blue">Role: <?=htmlspecialchars($staff['display_title'] ?? $currentUser)?></span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:12px">
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">मेरे जिम्मे पेंडिंग काम</small>
      <strong style="display:block;font-size:20px;color:#2563eb;margin-top:4px">
        <?=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND owner=" . $pdo->quote($currentUser))->fetchColumn()?>
      </strong>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">कंपनी के कुल Urgent Tasks</small>
      <strong style="display:block;font-size:20px;color:#dc2626;margin-top:4px">
        <?=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND task_kind='urgent'")->fetchColumn()?>
      </strong>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Follow-up & Reminders</small>
      <strong style="display:block;font-size:20px;color:#d97706;margin-top:4px">
        <?=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND task_kind='followup'")->fetchColumn()?>
      </strong>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 02: CUSTOMERS & SERVICE HISTORY
// ==========================================
if ($activeModId === 2):
  $qClient = trim($_GET['search_client'] ?? '');
  $clientRows = [];
  if ($qClient !== '') {
      $cStmt = $pdo->prepare("SELECT * FROM whmcs_items_cache WHERE domain LIKE ? OR package_name LIKE ? OR client_id = ? LIMIT 30");
      $cStmt->execute(["%$qClient%", "%$qClient%", (int)$qClient]);
      $clientRows = $cStmt->fetchAll();
  } else {
      $clientRows = $pdo->query("SELECT * FROM whmcs_items_cache ORDER BY id DESC LIMIT 20")->fetchAll();
  }
?>
<div class="box" style="border-left:4px solid #2563eb">
  <h3>🔍 915 Active Customers & Service History Search</h3>
  <form method="get" class="toolbar">
    <input type="hidden" name="m" value="2">
    <input name="search_client" value="<?=htmlspecialchars($qClient)?>" placeholder="Domain / Plan / Client ID दर्ज करें..." style="flex:1">
    <button type="submit" class="btn-primary">खोजें 🔍</button>
    <?php if ($qClient): ?><a href="index.php?m=2" class="btn-dark">Reset</a><?php endif; ?>
  </form>
  <table>
    <thead><tr><th>Client ID</th><th>Type & Plan</th><th>Domain</th><th>Billing / Cycle</th><th>Status</th><th>WHMCS Action</th></tr></thead>
    <tbody>
      <?php if (empty($clientRows)): ?>
        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px">कोई रिकॉर्ड नहीं मिला। WHMCS API सिंक करें या दूसरा सर्च टर्म डालें।</td></tr>
      <?php else: foreach ($clientRows as $cr): ?>
        <tr>
          <td><b>#<?=$cr['client_id']?></b></td>
          <td><span class="pill pill-blue"><?=strtoupper($cr['item_type'])?></span><br><small><?=htmlspecialchars((string)$cr['package_name'])?></small></td>
          <td><b><?=htmlspecialchars((string)$cr['domain'])?></b></td>
          <td>₹<?=$cr['recurring_amount']?> / <small><?=$cr['billing_cycle']?></small></td>
          <td><span class="pill <?=$cr['status']==='Active'?'pill-green':'pill-red'?>"><?=$cr['status']?></span></td>
          <td>
            <a href="<?=htmlspecialchars(whmcs_deep_link('client', $cr['client_id']))?>" target="_blank" class="btn-dark" style="font-size:11px;padding:4px 8px">Client ↗</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 03 & 04: ORDERS, DELIVERY, INVOICES & CA SHEET
// ==========================================
if (in_array($activeModId, [3, 4], true)):
?>
<div class="box" style="border-left:4px solid #16a34a">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <h3 style="margin:0">💳 Payment Proof Uploader, Sanju Bank Verification & Delivery Queue</h3>
      <small style="color:#64748b">WhatsApp UPI स्क्रीनशॉट सुरक्षित सेव करें (Google Photos की ज़रूरत नहीं) और 1-Click CA Sheet निकालें।</small>
    </div>
    <div class="toolbar" style="margin:0">
      <a class="btn-dark" href="api.php?action=export_ca_csv">⬇ Download CA GST Sheet (CSV)</a>
      <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('unpaid_invoices'))?>" target="_blank">WHMCS Unpaid ↗</a>
    </div>
  </div>

  <form id="paymentForm" enctype="multipart/form-data" style="margin-top:14px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
    <input type="hidden" name="action" value="payment_action">
    <input type="hidden" name="pay_sub" value="add_payment">
    <div class="toolbar">
      <input name="whmcs_invoice_id" type="number" placeholder="WHMCS Invoice #" required style="width:140px">
      <input name="customer_name" placeholder="ग्राहक का नाम / डोमेन" required style="flex:1">
      <input name="amount" type="number" step="0.01" placeholder="राशि (₹)" required style="width:120px">
      <select name="payment_source">
        <option value="WhatsApp_UPI">WhatsApp UPI Screenshot</option>
        <option value="Razorpay">Razorpay Unpaid Fix</option>
        <option value="Bank_Transfer">Bank / IMPS Transfer</option>
      </select>
      <input name="payment_id_or_utr" placeholder="UTR / UPI Ref Number" style="flex:1">
      <select name="delivery_owner">
        <option value="Chandrika">Chandrika (Hosting Delivery)</option>
        <option value="Priya">Priya (Domain Registration)</option>
        <option value="Sanju">Sanju (Billing / Domain)</option>
        <option value="Deepak">Deepak (Tech / Server Setup)</option>
      </select>
      <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
      <button type="submit" class="btn-primary">+ Save Payment Record</button>
    </div>
  </form>

  <table>
    <thead><tr><th>Invoice #</th><th>Customer & Amount</th><th>Source & Proof</th><th>Sanju Bank Verification</th><th>Service Delivery</th><th>WHMCS Edit</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM payment_records ORDER BY id DESC LIMIT 25") as $pr): ?>
        <tr>
          <td><b>#<?=$pr['whmcs_invoice_id']?></b></td>
          <td><b><?=htmlspecialchars((string)$pr['customer_name'])?></b><br><strong style="color:#16a34a">₹<?=$pr['amount']?></strong></td>
          <td>
            <?=$pr['payment_source']?><br>
            <?php if ($pr['proof_file_url']): ?>
              <a href="<?=htmlspecialchars($pr['proof_file_url'])?>" target="_blank" style="color:#2563eb;font-weight:700">📸 View Screenshot ↗</a>
            <?php else: ?>
              <code><?=htmlspecialchars((string)$pr['payment_id_or_utr'])?></code>
            <?php endif; ?>
          </td>
          <td>
            <?=$pr['sanju_verified'] ? '<span class="pill pill-green">✅ Verified in Bank</span>' : '<button class="btn-primary" style="font-size:11px;padding:4px 8px" onclick="verifyPay('.$pr['id'].',\'sanju_verify\')">Verify (Sanju)</button>'?>
          </td>
          <td>
            <?=$pr['service_delivered'] ? '<span class="pill pill-green">✅ Delivered</span>' : '<button style="font-size:11px;padding:4px 8px" onclick="verifyPay('.$pr['id'].',\'mark_delivered\')">Mark Delivered ('.$pr['delivery_owner'].')</button>'?>
          </td>
          <td><a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('invoice', $pr['whmcs_invoice_id']))?>" target="_blank" style="font-size:11px;padding:4px 8px">Edit Invoice ↗</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 05: RENEWALS (30-15-7-3-1 PIPELINE)
// ==========================================
if ($activeModId === 5):
?>
<div class="box" style="border-left:4px solid #d97706">
  <h3>📅 Renewals Tracking (30-15-7-3-1 Pipeline)</h3>
  <p style="font-size:12.5px;color:#475569">WHMCS Pending Orders में रिन्यूअल नहीं दिखता—इसलिए यहाँ से 30, 15, 7, 3, 1 दिन पहले वाले सभी डोमेन और होस्टिंग का फॉलो-अप लें।</p>
  
  <div style="background:#fef3c7;border:1px solid #fde68a;padding:12px;border-radius:8px;font-size:12.5px;color:#92400e;margin-bottom:14px">
    <strong>⚠️ Redemption Period Rule:</strong> यदि कोई डोमेन एक्सपायर होकर Redemption Period में चला गया है, तो सामान्य रिन्यूअल फीस (₹1,199 / ₹1,600) न लें। कस्टमर को बताएं कि रजिस्ट्री रिलीज़ 75 से 85 दिन बाद होती है।
  </div>

  <table>
    <thead><tr><th>Item Type</th><th>Domain / Plan</th><th>Expiry / Due Date</th><th>Days Left</th><th>Action</th></tr></thead>
    <tbody>
      <?php
      $renList = $pdo->query("SELECT * FROM whmcs_items_cache WHERE next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY next_due_date ASC LIMIT 30")->fetchAll();
      if (empty($renList)):
      ?>
        <tr><td colspan="5" style="text-align:center;color:#64748b;padding:20px">अगले 30 दिनों में कोई रिन्यूअल ड्यू नहीं है।</td></tr>
      <?php else: foreach ($renList as $rn): 
        $daysLeft = (int)floor((strtotime((string)$rn['next_due_date']) - time()) / 86400);
      ?>
        <tr>
          <td><span class="pill pill-blue"><?=strtoupper($rn['item_type'])?></span></td>
          <td><b><?=htmlspecialchars((string)$rn['domain'])?></b><br><small><?=htmlspecialchars((string)$rn['package_name'])?></small></td>
          <td><?=$rn['next_due_date']?></td>
          <td><b style="color:<?=$daysLeft<=3?'#dc2626':'#d97706'?>"><?=$daysLeft?> Days</b></td>
          <td>
            <button style="font-size:11px;padding:4px 8px" onclick="copyRenewalTemplate('<?=htmlspecialchars((string)$rn['domain'])?>', '<?=$rn['next_due_date']?>', <?=$rn['recurring_amount']?>)">📋 Copy WhatsApp Reminder</button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 06: RETENTION & 120 SUSPENDED WIN-BACK
// ==========================================
if ($activeModId === 6):
?>
<div class="box" style="border-left:4px solid #dc2626">
  <h3>🚨 Retention & 120 Suspended Accounts Win-Back</h3>
  <p style="font-size:12.5px;color:#475569">120 सस्पेंड पड़े cPanel अकाउंट्स से ₹45,000+ रिकवर करने का अभियान। इन्हें "Zero Late Fee + Immediate Unsuspension" का ऑफर दें:</p>
  <table>
    <thead><tr><th>cPanel User</th><th>Domain</th><th>Plan</th><th>Suspend Reason</th><th>Suspended Time</th><th>Action</th></tr></thead>
    <tbody>
      <?php
      $suspList = $pdo->query("SELECT * FROM whm_accounts WHERE is_suspended=1 ORDER BY id DESC LIMIT 40")->fetchAll();
      if (empty($suspList)):
      ?>
        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px">कोई सस्पेंडेड अकाउंट डेटा नहीं मिला। Module 10 में WHM सिंक करें।</td></tr>
      <?php else: foreach ($suspList as $sa): ?>
        <tr>
          <td><b><?=htmlspecialchars($sa['cpanel_user'])?></b></td>
          <td><?=htmlspecialchars($sa['domain'])?></td>
          <td><?=htmlspecialchars((string)$sa['plan_name'])?></td>
          <td><span class="pill pill-red"><?=htmlspecialchars((string)$sa['suspend_reason'])?></span></td>
          <td><small><?=$sa['suspend_time']?></small></td>
          <td>
            <button style="font-size:11px;padding:4px 8px" onclick="copyWinbackTemplate('<?=htmlspecialchars($sa['domain'])?>')">📋 Copy Win-Back Offer</button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 08: WHATSAPP & EMAIL CANNED REPLIES
// ==========================================
if ($activeModId === 8):
?>
<div class="box" style="border-left:4px solid #16a34a">
  <h3>💬 WhatsApp & Email Verified Canned Replies (1-Click Copy)</h3>
  <p style="font-size:12.5px;color:#475569">Aarav के 28+ रियल PDFs से निकले आधिकारिक सत्यापित रिप्लाई:</p>
  <div style="display:flex;flex-direction:column;gap:10px">
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <strong>1. Unlimited Shared Plans (0/0 Email & No Backup Rule):</strong>
        <button style="font-size:11px;padding:4px 8px" onclick="copyCanned(1)">📋 Copy</button>
      </div>
      <p id="canned_1" style="font-size:12px;color:#334155;margin:0">नमस्ते! AmbitionHost के Unlimited Shared Plans (Seed/Grow/Connect/Apex) बजट होस्टिंग हैं जिनमें Email (0/0 Limit) और Automatic Backups शामिल नहीं होते। यदि आपको बिज़नेस ईमेल या ऑटो-बैकअप चाहिए, तो आप सिर्फ अंतर का भुगतान (Prorated Difference) करके Starter Plan (₹59/mo) या Cloud Plan में अपग्रेड कर सकते हैं।</p>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <strong>2. Hostinger "Domain Already Registered" TXT Request:</strong>
        <button style="font-size:11px;padding:4px 8px" onclick="copyCanned(2)">📋 Copy</button>
      </div>
      <p id="canned_2" style="font-size:12px;color:#334155;margin:0">Hi Hostinger Support, I am getting 'Domain already registered' error while adding my domain {DOMAIN}. Kindly provide the ownership verification TXT/A record so I can verify ownership from my registrar DNS.</p>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <strong>3. ₹299 .IN Promo Aadhaar KYC & NIXI Adult Ban:</strong>
        <button style="font-size:11px;padding:4px 8px" onclick="copyCanned(3)">📋 Copy</button>
      </div>
      <p id="canned_3" style="font-size:12px;color:#334155;margin:0">नमस्ते! ₹299 .IN ऑफर NIXI नियमों के तहत आता है जिसके लिए फ्रेश आधार विवरण (पूरा नाम, पिनकोड सहित पता और आधार-लिंक्ड मोबाइल नंबर पर DigiLocker OTP) आवश्यक है। कृपया ध्यान दें कि .in/.co.in पर 18+/डेटिंग/अडल्ट नाम प्रतिबंधित हैं।</p>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <strong>4. Free Let's Encrypt AutoSSL Wizard Steps:</strong>
        <button style="font-size:11px;padding:4px 8px" onclick="copyCanned(4)">📋 Copy</button>
      </div>
      <p id="canned_4" style="font-size:12px;color:#334155;margin:0">Free SSL चालू करने के लिए: cPanel लॉगिन करें -> 'SSL/TLS Status' पर जाएं -> 'Run AutoSSL' Wizard में अपने डोमेन के सामने चेकबॉक्स टिक करें -> 'Continue' दबाएं। 5-10 मिनट में ग्रीन पैडलॉक एक्टिव हो जाएगा। (नोट: शेयर्ड प्लान में Wildcard *.domain.com सपोर्टेड नहीं है)।</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 10 & 11: WHM SERVERS (14 PRIVILEGES) & >20GB ALERT
// ==========================================
if (in_array($activeModId, [10, 11], true)):
?>
<div class="box" style="border-left:4px solid #2563eb">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <h3 style="margin:0">🖥️ WHM Servers & Accounts (Strictly 14 Ticked Privileges)</h3>
      <small style="color:#64748b">OVH SGP, Verpex (50 Empty Slots), NameCrane Servers Live Health & Backups.</small>
    </div>
    <button class="btn-primary" onclick="document.getElementById('whmAddModal').showModal()">+ Connect WHM Server</button>
  </div>

  <table>
    <thead><tr><th>Server Name</th><th>Provider & Host</th><th>Accounts (Total / Active / Susp)</th><th>Load Average</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM whm_servers") as $ws): ?>
        <tr>
          <td><b><?=htmlspecialchars($ws['server_name'])?></b></td>
          <td><?=htmlspecialchars($ws['provider'])?><br><code><?=htmlspecialchars($ws['hostname'])?></code></td>
          <td>Total: <b><?=$ws['total_accounts']?></b> · Active: <b><?=$ws['active_accounts']?></b> · Susp: <b style="color:#dc2626"><?=$ws['suspended_accounts']?></b></td>
          <td><?=htmlspecialchars((string)$ws['load_avg'])?></td>
          <td><button class="btn-dark" style="font-size:11px;padding:4px 8px" onclick="syncWhm(<?=$ws['id']?>)">🔄 Sync 14 Privileges</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3 style="margin-top:20px;color:#dc2626">🚨 SGP >20GB JetBackup Exclusion Alert (>18GB Pitch ₹199/mo)</h3>
  <table>
    <thead><tr><th>cPanel User</th><th>Domain</th><th>Disk Used</th><th>JetBackup Status</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM whm_accounts WHERE backup_eligible=0 OR disk_used_mb > 18000 ORDER BY disk_used_mb DESC LIMIT 30") as $bg): ?>
        <tr>
          <td><b><?=htmlspecialchars($bg['cpanel_user'])?></b></td>
          <td><?=htmlspecialchars($bg['domain'])?></td>
          <td><b style="color:#dc2626"><?=$bg['disk_used_mb']?> MB</b></td>
          <td><span class="pill pill-red">>20GB Excluded from Nahar Backup</span></td>
          <td>
            <button style="font-size:11px;padding:4px 8px" onclick="copyBackupPitch('<?=htmlspecialchars($bg['domain'])?>')">📋 Pitch ₹199/mo Backup Pack</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<dialog id="whmAddModal" style="max-width:520px;border-radius:12px;border:1px solid #334155;background:#0f172a;color:#f8fafc;padding:24px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="margin:0;color:#fff">🖥️ Connect WHM Server</h3>
    <button onclick="document.getElementById('whmAddModal').close()" style="background:transparent;border:0;color:#94a3b8;font-size:18px;cursor:pointer">✕</button>
  </div>

  <div style="background:#1e293b;padding:12px 14px;border-radius:8px;border:1px solid #334155;margin-bottom:14px;font-size:13px;line-height:1.5;color:#cbd5e1">
    💡 <b>WHM Me Token Kaha Milega:</b><br>
    1. WHM me login karein (<code>Port 2087</code>).<br>
    2. Search karein: <b>Manage API Tokens</b> ➔ <b>Generate Token</b>.<br>
    3. Privileges me 14 ticks select karein (Account Information, List Accounts, Bandwidth, Load, etc.)<br>
    4. Hostname me direct Server IP (e.g. <code>51.79.xxx.xxx</code>) ya Domain daal sakte hain.
  </div>

  <form id="addWhmForm">
    <input type="hidden" name="action" value="whm_action">
    <input type="hidden" name="whm_sub" value="add_server">
    
    <label style="font-size:12px;color:#94a3b8">Server Display Name</label>
    <input name="server_name" placeholder="e.g. OVH Singapore Dedicated / Verpex" required style="width:100%;margin:4px 0 10px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">

    <label style="font-size:12px;color:#94a3b8">Hosting Provider</label>
    <input name="provider" placeholder="OVH / Verpex / NameCrane / HostHatch" required style="width:100%;margin:4px 0 10px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">

    <label style="font-size:12px;color:#94a3b8">Hostname or Server IP</label>
    <input name="hostname" placeholder="e.g. 51.79.162.100 ya sgp.ambitionhost.in" required style="width:100%;margin:4px 0 10px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div>
        <label style="font-size:12px;color:#94a3b8">WHM Username</label>
        <input name="whm_username" value="root" required style="width:100%;margin:4px 0 10px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">
      </div>
      <div>
        <label style="font-size:12px;color:#94a3b8">JetBackup Limit (GB)</label>
        <input name="max_backup_gb" type="number" value="20" required style="width:100%;margin:4px 0 10px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">
      </div>
    </div>

    <label style="font-size:12px;color:#94a3b8">WHM API Token</label>
    <input type="password" name="api_token" placeholder="Paste your alphanumeric WHM API token" required style="width:100%;margin:4px 0 16px;padding:8px 10px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff">

    <div style="display:flex;gap:10px">
      <button type="button" id="btnTestWhmModal" onclick="testWhmInModal()" class="btn-primary" style="background:#059669;border-color:#059669;flex:1">🔍 Test Connection</button>
      <button type="submit" class="btn-primary" style="flex:1">💾 Save & Connect Server</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php
// ==========================================
// MODULE 12: MASTER DOMAIN VAULT (26 REGISTRARS)
// ==========================================
if ($activeModId === 12):
?>
<div class="box" style="border-left:4px solid #7c3aed">
  <h3>🔐 Master Domain Vault (~2,000 Accounts Across 26 Registrars)</h3>
  <p style="font-size:12.5px;color:#475569">WhatsApp ग्रुप में लॉगिन ढूँढना बंद! ग्रुप चैट या डोमेन लिस्ट नीचे पेस्ट करें—सिस्टम अपने-आप डोमेन, रजिस्ट्रार और लॉगिन आईडी निकाल लेगा:</p>
  
  <textarea id="vaultRawText" rows="3" style="width:100%" placeholder="WhatsApp चैट यहाँ पेस्ट करें (जैसे: example.com bought on Spaceship login priya12@gmail.com)..."></textarea>
  <button class="btn-primary" onclick="importVault()" style="margin-top:8px">⚡ Auto-Extract & Save to Vault</button>

  <div style="background:#fee2e2;border:1px solid #fecaca;padding:10px;border-radius:8px;font-size:12px;color:#991b1b;margin:14px 0">
    <strong>⚠️ Tuxler Residential VPN Alert:</strong> <code>Register.it</code> और <code>Nominalia.com</code> बिना इटली के रेजिडेंशियल आईपी के लॉगिन ब्लॉक कर देते हैं। इन्हें केवल Priya या Aarav हैंडल करते हैं (Sanju नहीं)।
  </div>

  <table>
    <thead><tr><th>Domain Name</th><th>Registrar</th><th>Account Email / Login ID</th><th>Notes</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM domain_vault ORDER BY id DESC LIMIT 35") as $dv): ?>
        <tr>
          <td><b><?=htmlspecialchars($dv['domain_name'])?></b></td>
          <td>
            <span class="pill <?=in_array($dv['registrar_name'],['Register.it','Nominalia'],true)?'pill-red':'pill-blue'?>">
              <?=htmlspecialchars($dv['registrar_name'])?>
            </span>
          </td>
          <td><code><?=htmlspecialchars($dv['account_email_or_id'])?></code></td>
          <td><small><?=htmlspecialchars((string)$dv['notes'])?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 13: LEADS & SALES CRM (30m SLA & 10m/30m/2h)
// ==========================================
if ($activeModId === 13):
?>
<div class="box" style="border-left:4px solid #2563eb">
  <h3>💬 Sales CRM (9AM-6PM 30-Min SLA & +10m/+30m/+2h Follow-ups)</h3>
  <div class="toolbar" style="margin-bottom:14px">
    <input id="leadIdent" placeholder="Lead Name / WhatsApp Number / Domain" style="flex:1">
    <input id="leadMsg" placeholder="Inquiry Message" style="flex:1">
    <button class="btn-primary" onclick="crmCall('new_lead')">+ Capture New Lead</button>
  </div>

  <table>
    <thead><tr><th>Lead # & Identity</th><th>Wait Time (9AM-6PM)</th><th>Human Reply</th><th>Price Sent</th><th>Customer Replied</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM sales_leads ORDER BY id DESC LIMIT 20") as $sl): 
        $waitM = calc_working_minutes(strtotime($sl['first_msg_at']), $sl['first_human_reply_at'] ? strtotime($sl['first_human_reply_at']) : time());
      ?>
        <tr>
          <td><b>#<?=$sl['id']?> · <?=htmlspecialchars($sl['customer_identity'])?></b><br><small><?=$sl['first_msg_at']?></small></td>
          <td><b style="color:<?=$waitM>30?'#dc2626':'#16a34a'?>"><?=$waitM?> working mins</b></td>
          <td><?=$sl['first_human_reply_at'] ? '✅ '.$sl['first_human_reply_at'] : '<button class="btn-primary" style="font-size:11px;padding:3px 7px" onclick="crmLead('.$sl['id'].',\'human_reply\')">Mark Replied</button>'?></td>
          <td><?=$sl['price_sent_at'] ? '✅ '.$sl['price_sent_at'] : '<button style="font-size:11px;padding:3px 7px" onclick="crmLead('.$sl['id'].',\'price_sent\')">Mark Price Sent (Start 10m/30m/2h)</button>'?></td>
          <td><?=$sl['customer_replied_after_price'] ? '✅ Replied (F-ups Stopped)' : '<button style="font-size:11px;padding:3px 7px" onclick="crmLead('.$sl['id'].',\'customer_replied\')">Customer Replied</button>'?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 14: PRORATED PLAN UPGRADE CALCULATOR
// ==========================================
if ($activeModId === 14):
?>
<div class="box" style="border-left:4px solid #16a34a">
  <h3>⚡ Prorated Plan Upgrade Calculator (Prorated Difference)</h3>
  <p style="font-size:12.5px;color:#475569">जब कोई ग्राहक सस्ते Unlimited प्लान (Seed/Grow) से Starter/Basic/Cloud में अपग्रेड मांगे, तो बचे हुए समय का सटीक अंतर 1 सेकंड में निकालें:</p>
  
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
    <div>
      <label style="font-size:11px;font-weight:700">वर्तमान प्लान का मासिक रेट (₹):</label>
      <input type="number" id="oldRate" value="29" style="width:100%">
    </div>
    <div>
      <label style="font-size:11px;font-weight:700">नये प्लान का मासिक रेट (₹):</label>
      <input type="number" id="newRate" value="59" style="width:100%">
    </div>
    <div>
      <label style="font-size:11px;font-weight:700">बचे हुए पूर्ण महीने:</label>
      <input type="number" id="remMonths" value="8" style="width:100%">
    </div>
    <div>
      <label style="font-size:11px;font-weight:700">अतिरिक्त दिन:</label>
      <input type="number" id="extraDays" value="12" style="width:100%">
    </div>
  </div>
  <button class="btn-primary" onclick="calcProrate()" style="margin-top:10px">Calculate Prorated Payable →</button>
  <div id="prorateResult" style="margin-top:12px;font-size:16px;font-weight:700;color:#16a34a"></div>

  <h4 style="margin-top:20px">📦 Bulk .COM Official Tiers (Zero-GST via UPI):</h4>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:12.5px">
    <div style="background:#f1f5f9;padding:10px;border-radius:8px">1–4 Domains: <b>₹749</b> (Regular/Reseller ₹699)</div>
    <div style="background:#f1f5f9;padding:10px;border-radius:8px">5–19 Bulk Domains: <b>₹599 / domain</b></div>
    <div style="background:#f1f5f9;padding:10px;border-radius:8px">20+ Bulk Domains: <b>₹571 / domain</b></div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 18: WEBSITE AUDITED BUG FIXES CHECKLIST
// ==========================================
if ($activeModId === 18):
?>
<div class="box" style="border-left:4px solid #0f172a">
  <h3>🛠️ AmbitionHost 6 Audited Website Bugs (Deepak B.D.E.)</h3>
  <table>
    <thead><tr><th>Bug ID</th><th>Issue Description</th><th>Target Location</th><th>Action</th></tr></thead>
    <tbody>
      <tr><td>#1</td><td><b>SaluteHost.com Public Leak</b> (Without password + "does not provide hosting" text)</td><td>salutehost.com root</td><td><span class="pill pill-green">Locked with htpasswd</span></td></tr>
      <tr><td>#2</td><td><b>Canada VPS Heading Error</b> (Showing "Europe NVMe VPS" under /canada-vps-hosting)</td><td>/canada-vps-hosting</td><td><span class="pill pill-green">Fixed Heading</span></td></tr>
      <tr><td>#3</td><td><b>WhatsApp Footer Inverted Links</b> (Sales links to Support, Support links to Sales)</td><td>Website Footer</td><td><span class="pill pill-green">Verified Separate Queues</span></td></tr>
      <tr><td>#4</td><td><b>VPS Backup Contradiction</b> (Top shows Daily Backup, FAQ shows No Backup)</td><td>/vps-hosting</td><td><span class="pill pill-green">Harmonized Rules</span></td></tr>
      <tr><td>#5</td><td><b>Refund Policy 20-30 Day Clause</b></td><td>/refund</td><td><span class="pill pill-green">Updated Policy</span></td></tr>
      <tr><td>#6</td><td><b>Old Phone Numbers Leak</b> (8690909188, 8540885599)</td><td>/about-us & Directs</td><td><span class="pill pill-green">Cleaned to 7688855600 & 8696518872</span></td></tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 21 & 22: PAYROLL, FIXED BURN & SALARY HIKE MATH
// ==========================================
if (in_array($activeModId, [21, 22], true)):
?>
<div class="box" style="border-left:4px solid #7c3aed">
  <h3>💰 Monthly Fixed Burn Breakdown (₹99,550/mo) & Salary Hike Simulator</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0">
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Monthly Staff Payroll</small>
      <strong style="display:block;font-size:22px;color:#0f172a;margin-top:4px">₹64,000 / mo</strong>
      <small>Deepak ₹25k · Chandrika ₹15k · Priya ₹12k · Sanju ₹12k</small>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Servers & Infra Burn</small>
      <strong style="display:block;font-size:22px;color:#0f172a;margin-top:4px">₹35,550 / mo</strong>
      <small>OVH SGP ₹15.2k · Nahar ₹4.5k · Verpex ₹9.4k · NameCrane ₹6.3k</small>
    </div>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Total Fixed Monthly Burn</small>
      <strong style="display:block;font-size:22px;color:#dc2626;margin-top:4px">₹99,550 / mo</strong>
      <small>Break-even requirement</small>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 23: DAILY 10 AM BRIEFING & HISTORY
// ==========================================
if ($activeModId === 23):
?>
<div class="box" style="border-left:4px solid #2563eb">
  <h3>📜 Permanent Task History & Resolution Proofs</h3>
  <table>
    <thead><tr><th>Task #</th><th>Title & Module</th><th>Owner</th><th>Proof of Resolution</th><th>Resolved At</th></tr></thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM tasks WHERE status='Resolved' ORDER BY resolved_at DESC LIMIT 30") as $rt): ?>
        <tr>
          <td><b>#<?=$rt['id']?></b></td>
          <td><b><?=htmlspecialchars($rt['title'])?></b><br><span class="pill pill-blue">M<?=sprintf('%02d', (int)$rt['module_id'])?></span></td>
          <td><?=htmlspecialchars($rt['owner'])?></td>
          <td><span class="pill pill-green">✅ <?=htmlspecialchars((string)$rt['resolution_evidence'])?></span></td>
          <td><small><?=$rt['resolved_at']?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
