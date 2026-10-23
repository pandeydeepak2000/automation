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
      $cStmt = $pdo->prepare("SELECT * FROM whmcs_items_cache WHERE domain LIKE ? OR package_name LIKE ? OR client_id = ? LIMIT 50");
      $cStmt->execute(["%$qClient%", "%$qClient%", (int)$qClient]);
      $clientRows = $cStmt->fetchAll();
  } else {
      $clientRows = $pdo->query("SELECT * FROM whmcs_items_cache ORDER BY id DESC LIMIT 25")->fetchAll();
  }

  $totalHostingCount = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type = 'hosting' AND status = 'Active'")->fetchColumn();
  $totalDomainCount  = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_items_cache WHERE item_type = 'domain' AND status = 'Active'")->fetchColumn();
  $due30Count        = (int)$pdo->query("SELECT COUNT(*) FROM whmcs_items_cache WHERE status = 'Active' AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
?>
<div class="box" style="border-left:4px solid #2563eb">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">👥 Module 02: Customers & Service History Search</h3>
      <small style="color:#64748b">915 Active Hosting Accounts और हर ग्राहक की रजिस्ट्रेशन से लेकर टिकट्स तक की पूरी कुंडली।</small>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="btn-primary" style="background:#059669;border-color:#059669" onclick="syncWhmcsCache()">🔄 Sync Live WHMCS Services</button>
      <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('clients'))?>" target="_blank">WHMCS Clients ↗</a>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0">
    <div style="background:#eff6ff;padding:12px 14px;border-radius:8px;border:1px solid #bfdbfe">
      <small style="color:#1e40af;font-weight:700">Active Hosting Clients</small>
      <strong style="display:block;font-size:22px;color:#2563eb;margin-top:2px"><?=$totalHostingCount?> Services</strong>
      <small>Shared, Cloud, VPS & Resellers</small>
    </div>
    <div style="background:#f0fdf4;padding:12px 14px;border-radius:8px;border:1px solid #bbf7d0">
      <small style="color:#166534;font-weight:700">Active Managed Domains</small>
      <strong style="display:block;font-size:22px;color:#16a34a;margin-top:2px"><?=$totalDomainCount?> Domains</strong>
      <small>Across 26 Registrar Vault Accounts</small>
    </div>
    <div style="background:#fffbeb;padding:12px 14px;border-radius:8px;border:1px solid #fef3c7">
      <small style="color:#92400e;font-weight:700">Upcoming 30-Day Renewals</small>
      <strong style="display:block;font-size:22px;color:#d97706;margin-top:2px"><?=$due30Count?> Due</strong>
      <small>Chandrika 30-15-7-3-1 Pipeline</small>
    </div>
  </div>

  <!-- SEARCH BAR -->
  <form method="get" class="toolbar" style="margin-bottom:14px">
    <input type="hidden" name="m" value="2">
    <input name="search_client" value="<?=htmlspecialchars($qClient)?>" placeholder="Domain Name / Hosting Plan / Client ID दर्ज करें (उदा. example.com)..." style="flex:1">
    <button type="submit" class="btn-primary">खोजें 🔍</button>
    <?php if ($qClient): ?><a href="index.php?m=2" class="btn-dark">Reset</a><?php endif; ?>
  </form>

  <table>
    <thead>
      <tr>
        <th>Client ID</th>
        <th>Type & Plan</th>
        <th>Domain Name</th>
        <th>Billing / Amount</th>
        <th>Next Due Date</th>
        <th>Status</th>
        <th>WHMCS Deep-Link</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clientRows)): ?>
        <tr>
          <td colspan="7" style="text-align:center;color:#64748b;padding:24px">
            💡 अभी लोकल कैश खाली है। ऊपर दिए गए <b>🔄 Sync Live WHMCS Services</b> बटन पर क्लिक करें ताकि WHMCS से सभी एक्टिव क्लाइंट्स व डोमेन्स फेच हो जाएं!
          </td>
        </tr>
      <?php else: foreach ($clientRows as $cr): ?>
        <tr>
          <td>
            <a href="<?=htmlspecialchars(whmcs_deep_link('client', $cr['client_id']))?>" target="_blank" style="color:#2563eb;font-weight:700">
              #<?=$cr['client_id']?> ↗
            </a>
          </td>
          <td>
            <span class="pill <?=$cr['item_type']==='hosting'?'pill-blue':'pill-dark'?>"><?=strtoupper($cr['item_type'])?></span><br>
            <small style="color:#334155;font-weight:600"><?=htmlspecialchars((string)$cr['package_name'])?></small>
          </td>
          <td>
            <a href="https://<?=htmlspecialchars((string)$cr['domain'])?>" target="_blank" style="color:#0f172a;font-weight:600;text-decoration:none">
              <?=htmlspecialchars((string)$cr['domain'])?>
            </a>
          </td>
          <td>
            <b style="color:#16a34a">₹<?=number_format((float)$cr['recurring_amount'], 2)?></b><br>
            <small style="color:#64748b"><?=htmlspecialchars((string)$cr['billing_cycle'])?></small>
          </td>
          <td>
            <small style="color:#475569"><?=($cr['next_due_date'] ? date('d M Y', strtotime($cr['next_due_date'])) : 'N/A')?></small>
          </td>
          <td>
            <span class="pill <?=$cr['status']==='Active'?'pill-green':($cr['status']==='Suspended'?'pill-red':'pill-dark')?>">
              <?=$cr['status']?>
            </span>
          </td>
          <td>
            <?php if ($cr['item_type'] === 'hosting'): ?>
              <a href="https://www.ambitionhost.in/user/devokedev/clientsservices.php?id=<?=$cr['whmcs_item_id']?>" target="_blank" class="btn-dark" style="font-size:11px;padding:4px 8px">Service ↗</a>
            <?php else: ?>
              <a href="https://www.ambitionhost.in/user/devokedev/clientsdomains.php?id=<?=$cr['whmcs_item_id']?>" target="_blank" class="btn-dark" style="font-size:11px;padding:4px 8px">Domain ↗</a>
            <?php endif; ?>
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
// ==========================================
// MODULE 03: ORDERS & DELIVERY QUEUE (PAID BUT UNDELIVERED PROTECTION)
// ==========================================
if ($activeModId === 3):
?>
<div class="box" style="border-left:4px solid #059669">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">📦 Module 03: Orders & Delivery Queue (Zero-Drop Guarantee)</h3>
      <small style="color:#64748b">Payment आने के बाद Hosting (Chandrika) और Domain (Priya/Sanju) की पक्की डिलीवरी।</small>
    </div>
    <div class="toolbar" style="margin:0">
      <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('pending_orders'))?>" target="_blank">WHMCS Pending Orders ↗</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0">
    <div style="background:#eff6ff;padding:12px 14px;border-radius:8px;border:1px solid #bfdbfe">
      <small style="color:#1e40af;font-weight:700">Chandrika Hosting Setup</small>
      <strong style="display:block;font-size:20px;color:#2563eb;margin-top:4px">cPanel & DNS Provisioning</strong>
      <small>Welcome Email & cPanel Pass Dispatch</small>
    </div>
    <div style="background:#f0fdf4;padding:12px 14px;border-radius:8px;border:1px solid #bbf7d0">
      <small style="color:#166534;font-weight:700">Priya / Sanju Domain Queue</small>
      <strong style="display:block;font-size:20px;color:#16a34a;margin-top:4px">26 Registrars Registration</strong>
      <small>Nameserver & KYC Verification</small>
    </div>
    <div style="background:#fef2f2;padding:12px 14px;border-radius:8px;border:1px solid #fecaca">
      <small style="color:#991b1b;font-weight:700">Night Auto-Watchdog</small>
      <strong style="display:block;font-size:20px;color:#dc2626;margin-top:4px">0 Delayed Domains</strong>
      <small>Prevents overnight domain hijack</small>
    </div>
  </div>

  <h4 style="margin:16px 0 8px;font-size:14px;color:#334155">⚡ Active Delivery Queue</h4>
  <table>
    <thead>
      <tr>
        <th>Invoice #</th>
        <th>Customer & Domain</th>
        <th>Service Type</th>
        <th>Assigned Owner</th>
        <th>Delivery Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pdo->query("SELECT * FROM payment_records WHERE service_delivered = 0 ORDER BY id DESC LIMIT 20") as $pr): ?>
        <tr>
          <td><b>#<?=$pr['whmcs_invoice_id']?></b></td>
          <td><b><?=htmlspecialchars((string)$pr['customer_name'])?></b><br><small style="color:#16a34a">₹<?=$pr['amount']?></small></td>
          <td><span class="pill pill-blue"><?=htmlspecialchars($pr['payment_source'])?></span></td>
          <td><b><?=htmlspecialchars($pr['delivery_owner'])?></b></td>
          <td><span class="pill pill-orange">Pending Delivery</span></td>
          <td>
            <button class="btn-primary" style="font-size:11px;padding:4px 8px" onclick="verifyPay(<?=$pr['id']?>,'mark_delivered')">Mark Delivered (<?=$pr['delivery_owner']?>)</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 04: INVOICES, UPI PROOFS & CA SHEET
// ==========================================
if ($activeModId === 4):
?>
<div class="box" style="border-left:4px solid #16a34a">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">💳 Module 04: Invoices, UPI Proofs & CA GST Reconciliation</h3>
      <small style="color:#64748b">WhatsApp UPI स्क्रीनशॉट सुरक्षित सेव करें, संजू मैम का बैंक वेरिफिकेशन और 1-Click CA Sheet निकालें।</small>
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
// ==========================================
// MODULE 10: WHM SERVERS (STRICTLY 14 TICKED PRIVILEGES)
// ==========================================
if ($activeModId === 10):
  $srvStmt = $pdo->query("SELECT * FROM whm_servers");
  $servers = $srvStmt ? $srvStmt->fetchAll() : [];
  $totalAccountsSum = array_sum(array_column($servers, 'total_accounts'));
  $activeAccountsSum = array_sum(array_column($servers, 'active_accounts'));
  $suspAccountsSum = array_sum(array_column($servers, 'suspended_accounts'));
  $serverCount = count($servers);
?>
<div class="box" style="border-left:4px solid #2563eb">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">🖥️ WHM Servers & Multi-Node Cluster (Strictly 14 Ticked Privileges)</h3>
      <small style="color:#64748b">OVH SGP, Verpex (50 Empty Slots), NameCrane Servers Live Health, Accounts & Load Balance.</small>
    </div>
    <button class="btn-primary" onclick="document.getElementById('whmAddModal').showModal()">+ Connect WHM Server</button>
  </div>

  <!-- 4 STAT CARDS -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0">
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Connected WHM Nodes</small>
      <strong style="display:block;font-size:24px;color:#0f172a;margin-top:4px"><?=$serverCount?> Servers</strong>
      <small style="color:#059669;font-weight:600">● Port 2087 Encrypted API</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Total cPanel Accounts</small>
      <strong style="display:block;font-size:24px;color:#2563eb;margin-top:4px"><?=$totalAccountsSum?> Accounts</strong>
      <small>Live on Connected Servers</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Active Hosting Clients</small>
      <strong style="display:block;font-size:24px;color:#059669;margin-top:4px"><?=$activeAccountsSum?> Live</strong>
      <small style="color:#059669">Healthy & Serving Web Traffic</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Suspended Accounts</small>
      <strong style="display:block;font-size:24px;color:#dc2626;margin-top:4px"><?=$suspAccountsSum?> Accounts</strong>
      <small style="color:#dc2626">Overdue / Win-Back Pipeline</small>
    </div>
  </div>

  <!-- SERVER CAPACITY & FAILOVER SLOTS -->
  <h4 style="margin:20px 0 8px;font-size:14px;color:#334155">🗄️ Server Allocation & Capacity Distribution</h4>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:20px">
    <div style="background:#eff6ff;padding:12px 14px;border-radius:8px;border:1px solid #bfdbfe">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <b>OVH SGP Dedicated (Primary)</b>
        <span class="pill pill-green">Primary Node</span>
      </div>
      <p style="margin:6px 0 0;font-size:12.5px;color:#1e40af">Live on <code>sgp.centreserver.com</code> · 327 Accounts · 100% NVMe Storage Pool.</p>
    </div>
    <div style="background:#f0fdf4;padding:12px 14px;border-radius:8px;border:1px solid #bbf7d0">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <b>Verpex Reseller (Failover)</b>
        <span class="pill pill-blue">50 Empty Slots</span>
      </div>
      <p style="margin:6px 0 0;font-size:12.5px;color:#166534">150 Account Capacity (100 Active · <b>50 Empty Slots</b> ready for instant migration).</p>
    </div>
    <div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <b>NameCrane Infrastructure</b>
        <span class="pill pill-dark">Standby</span>
      </div>
      <p style="margin:6px 0 0;font-size:12.5px;color:#475569">Tertiary Standby Node for Disaster Recovery & Offsite Backups.</p>
    </div>
  </div>

  <!-- WHM SERVERS TABLE -->
  <h4 style="margin:16px 0 8px;font-size:14px;color:#334155">🖥️ Connected WHM Nodes</h4>
  <table>
    <thead>
      <tr>
        <th>Server Name & Host</th>
        <th>Provider & IP</th>
        <th>Accounts (Total / Active / Susp)</th>
        <th>Load Average (1m, 5m, 15m)</th>
        <th>Last Synced</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($servers)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b">कोई WHM सर्वर कनेक्टेड नहीं है। ऊपर दिए गए <b>+ Connect WHM Server</b> बटन पर क्लिक करें।</td></tr>
      <?php else: ?>
        <?php foreach ($servers as $ws): 
          $loadParts = array_map('trim', explode(',', (string)($ws['load_avg'] ?? '')));
          $load1m = isset($loadParts[0]) ? (float)$loadParts[0] : 0;
          $loadPill = 'pill-green';
          if ($load1m > 15) $loadPill = 'pill-red';
          elseif ($load1m > 8) $loadPill = 'pill-orange';
        ?>
          <tr>
            <td>
              <strong style="font-size:14px;color:#0f172a"><?=htmlspecialchars($ws['server_name'])?></strong><br>
              <code style="color:#2563eb;font-size:12px"><?=htmlspecialchars($ws['hostname'])?></code>
            </td>
            <td>
              <span class="pill pill-dark"><?=strtoupper(htmlspecialchars($ws['provider']))?></span><br>
              <small style="color:#64748b">Port 2087</small>
            </td>
            <td>
              Total: <b><?=$ws['total_accounts']?></b><br>
              <small style="color:#059669">● Active: <b><?=$ws['active_accounts']?></b></small> · 
              <small style="color:#dc2626">● Susp: <b><?=$ws['suspended_accounts']?></b></small>
            </td>
            <td>
              <span class="pill <?=$loadPill?>">⚡ <?=htmlspecialchars((string)($ws['load_avg'] ?: 'N/A'))?></span>
            </td>
            <td>
              <small style="color:#64748b"><?=($ws['last_synced_at'] ? date('d M, h:i A', strtotime($ws['last_synced_at'])) : 'Never')?></small>
            </td>
            <td>
              <button class="btn-dark" style="font-size:12px;padding:6px 12px;background:#0f172a" onclick="syncWhm(<?=$ws['id']?>)">🔄 Sync 14 Privileges</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- 14 TICKED PRIVILEGES BADGES -->
  <div style="margin-top:24px;background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0">
    <strong style="display:block;font-size:13px;color:#334155;margin-bottom:8px">🔒 14 Ticked WHM Privileges Enforced (Zero Root Security Compromise)</strong>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php 
      $privs = [
        'acct-summary', 'basic-system-info', 'list-pkgs', 'public-contact',
        'ssl-info', 'track-email', 'list-accts', 'show-bandwidth', 'mailcheck',
        'software-JetBackup5', 'software-imunify360', 'software-lvemanager', 'status', 'stats'
      ];
      foreach ($privs as $prv): ?>
        <span class="pill pill-blue" style="font-size:11px">✓ <?=$prv?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- CONNECT WHM SERVER MODAL -->
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
// MODULE 11: BACKUPS, >20GB ALERT & SECURITY (NAHAR JETBACKUP RULE)
// ==========================================
if ($activeModId === 11):
  $over20Count = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE backup_eligible = 0 OR disk_used_mb > 20480")->fetchColumn();
  $warningCount = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE disk_used_mb > 18000 AND disk_used_mb <= 20480")->fetchColumn();
  $backupEligibleCount = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE backup_eligible = 1 AND disk_used_mb <= 20480")->fetchColumn();
?>
<div class="box" style="border-left:4px solid #dc2626">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px;color:#dc2626">🛡️ Module 11: Backups, >20GB Alert & Security (Nahar JetBackup Rule)</h3>
      <small style="color:#64748b">SGP ≤20GB Automated JetBackup Rule · >18GB Upsell Alert (₹199/mo) · Deepak's Daily 9 AM MySQL Check.</small>
    </div>
    <div>
      <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('hosting_list'))?>" target="_blank">WHMCS Hosting Accounts ↗</a>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0">
    <div style="background:#fef2f2;padding:14px;border-radius:10px;border:1px solid #fecaca">
      <small style="color:#991b1b;font-weight:700">🚨 >20GB JetBackup Excluded</small>
      <strong style="display:block;font-size:24px;color:#dc2626;margin-top:4px"><?=$over20Count?> Accounts</strong>
      <small style="color:#991b1b">High Upsell Target (₹199/mo)</small>
    </div>
    <div style="background:#fffbeb;padding:14px;border-radius:10px;border:1px solid #fef3c7">
      <small style="color:#92400e;font-weight:700">⚠️ >18GB Warning Tier</small>
      <strong style="display:block;font-size:24px;color:#d97706;margin-top:4px"><?=$warningCount?> Accounts</strong>
      <small style="color:#92400e">Approaching 20GB Boundary</small>
    </div>
    <div style="background:#f0fdf4;padding:14px;border-radius:10px;border:1px solid #bbf7d0">
      <small style="color:#166534;font-weight:700">🛡️ Nahar Daily JetBackup Safe</small>
      <strong style="display:block;font-size:24px;color:#16a34a;margin-top:4px"><?=$backupEligibleCount?> Accounts</strong>
      <small style="color:#166534">Automated JetBackup (≤20GB)</small>
    </div>
    <div style="background:#eff6ff;padding:14px;border-radius:10px;border:1px solid #bfdbfe">
      <small style="color:#1e40af;font-weight:700">🔍 Deepak Daily 9 AM Audit</small>
      <strong style="display:block;font-size:24px;color:#2563eb;margin-top:4px">10 Accounts</strong>
      <small style="color:#1e40af">MySQL Backup Integrity Queue</small>
    </div>
  </div>

  <!-- TABLE 1: SGP >20GB EXCLUSION LIST -->
  <h4 style="margin:20px 0 8px;font-size:14px;color:#dc2626">🚨 SGP >20GB JetBackup Exclusion Accounts (>18GB Pitch ₹199/mo)</h4>
  <p style="font-size:12.5px;color:#64748b;margin:0 0 10px">नाहर द्वारा 20GB से बड़े अकाउंट्स का बैकअप नहीं लिया जाता। इन ग्राहकों को तुरंत संपर्क करके ₹199/माह का Extended Storage & Backup Add-on बेचें:</p>
  <table>
    <thead>
      <tr>
        <th>cPanel User</th>
        <th>Domain Name</th>
        <th>Disk Used</th>
        <th>JetBackup Policy Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $over20Accounts = $pdo->query("SELECT * FROM whm_accounts WHERE backup_eligible=0 OR disk_used_mb > 18000 ORDER BY disk_used_mb DESC LIMIT 40")->fetchAll();
      if (empty($over20Accounts)):
      ?>
        <tr><td colspan="5" style="text-align:center;padding:20px;color:#64748b">वर्तमान में कोई भी अकाउंट >18GB या 20GB सीमा से बाहर नहीं है।</td></tr>
      <?php else: ?>
        <?php foreach ($over20Accounts as $bg): 
          $usedMb = (float)$bg['disk_used_mb'];
          $usedGb = round($usedMb / 1024, 2);
          $isOver20 = ($usedMb > 20480 || (int)$bg['backup_eligible'] === 0);
        ?>
          <tr>
            <td><strong style="font-size:13px;color:#0f172a"><?=htmlspecialchars($bg['cpanel_user'])?></strong></td>
            <td>
              <a href="https://<?=htmlspecialchars($bg['domain'])?>" target="_blank" style="color:#2563eb;font-weight:700;text-decoration:none"><?=htmlspecialchars($bg['domain'])?></a>
            </td>
            <td>
              <b style="color:<?=$isOver20?'#dc2626':'#d97706'?>;font-size:13.5px"><?=$usedGb?> GB</b> 
              <small style="color:#64748b">(<?=number_format($usedMb, 0)?> MB)</small>
            </td>
            <td>
              <?php if ($isOver20): ?>
                <span class="pill pill-red">🚨 >20GB Excluded from Nahar Backup</span>
              <?php else: ?>
                <span class="pill pill-orange">⚠️ >18GB Approaching Exclusion</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn-primary" style="font-size:11px;padding:5px 10px;background:#0f172a" onclick="copyBackupPitch('<?=htmlspecialchars($bg['domain'])?>')">📋 Pitch ₹199/mo Pack</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- TABLE 2: DEEPAK'S DAILY 9 AM MYSQL VERIFICATION AUDIT -->
  <h4 style="margin:26px 0 8px;font-size:14px;color:#2563eb">🔍 Deepak's Daily 9 AM 10-cPanel MySQL Backup Verification Queue</h4>
  <p style="font-size:12.5px;color:#64748b;margin:0 0 10px">रोज़ सुबह 9 बजे दीपक (B.D.E.) के लिए रैंडम 10 cPanel अकाउंट्स का डेटाबेस चेक:</p>
  <table>
    <thead>
      <tr>
        <th>cPanel User</th>
        <th>Domain</th>
        <th>Size</th>
        <th>Integrity Verification</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $randomDaily = $pdo->query("SELECT * FROM whm_accounts WHERE backup_eligible=1 AND disk_used_mb <= 20480 ORDER BY RAND(CURDATE()) LIMIT 10")->fetchAll();
      if (empty($randomDaily)):
      ?>
        <tr><td colspan="5" style="text-align:center;padding:16px;color:#64748b">Module 10 me WHM sync karein taaki active accounts fetch ho sakein.</td></tr>
      <?php else: ?>
        <?php foreach ($randomDaily as $da): ?>
          <tr>
            <td><b><?=htmlspecialchars($da['cpanel_user'])?></b></td>
            <td><?=htmlspecialchars($da['domain'])?></td>
            <td><?=round($da['disk_used_mb']/1024, 2)?> GB</td>
            <td><span class="pill pill-blue">Daily Audit Eligible</span></td>
            <td>
              <button class="btn-dark" style="font-size:11px;padding:4px 8px" onclick="alert('✅ Verified MySQL integrity for <?=htmlspecialchars($da['cpanel_user'])?>')">✓ Mark Verified</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- SECTION 3: ABUSE / COPYRIGHT COMPLIANCE -->
  <div style="margin-top:24px;background:#fef2f2;padding:16px;border-radius:10px;border:1px solid #fecaca">
    <h4 style="margin:0 0 8px;color:#991b1b;font-size:14px">⚖️ Abuse & Trademark Suspension Protocol (e.g. jobplayboy.co.in or Upwork Logo)</h4>
    <ol style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.6;color:#7f1d1d">
      <li><b>Immediate Suspension:</b> Abuse/Phishing/Trademark शिकायत आते ही अकाउंट को तुरंत WHM से सस्पेंड करें।</li>
      <li><b>Backup Delivery:</b> कस्टमर को TinyURL / Mega सुरक्षित बैकअप डाउनलोड लिंक उपलब्ध करवाएं।</li>
      <li><b>3-Point Written Compliance:</b> ग्राहक से लिखित में 3-बिंदु सहमति मिलने के बाद ही unsuspension की अनुमति है।</li>
    </ol>
  </div>
</div>
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
// MODULE 21: COSTS, ₹64k PAYROLL & NET PROFIT
// ==========================================
if ($activeModId === 21):
?>
<div class="box" style="border-left:4px solid #7c3aed">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">💰 Module 21: Monthly Fixed Burn (₹99,550/mo) & Payroll Costs</h3>
      <small style="color:#64748b">चन्द्रिका ₹15k, प्रिया ₹12k, संजू ₹12k, दीपक ₹25k, नाहर ₹4.5k और सर्वर बिल के बाद बचत।</small>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:16px 0">
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Monthly Staff Payroll</small>
      <strong style="display:block;font-size:24px;color:#0f172a;margin-top:4px">₹64,000 / mo</strong>
      <small>Deepak ₹25k · Chandrika ₹15k · Priya ₹12k · Sanju ₹12k</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Servers & Infra Burn</small>
      <strong style="display:block;font-size:24px;color:#0f172a;margin-top:4px">₹35,550 / mo</strong>
      <small>OVH SGP ₹15.2k · Nahar ₹4.5k · Verpex ₹9.4k · NameCrane ₹6.3k</small>
    </div>
    <div style="background:#fef2f2;padding:14px;border-radius:10px;border:1px solid #fecaca">
      <small style="color:#991b1b;font-weight:700">Total Fixed Monthly Burn</small>
      <strong style="display:block;font-size:24px;color:#dc2626;margin-top:4px">₹99,550 / mo</strong>
      <small style="color:#991b1b">Break-even requirement before profit</small>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 22: FORECASTING & SALARY HIKE MATH
// ==========================================
if ($activeModId === 22):
?>
<div class="box" style="border-left:4px solid #059669">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">📈 Module 22: Revenue Forecasting & Salary Hike Simulator</h3>
      <small style="color:#64748b">आने वाले रिन्यूअल्स, सर्वर खर्च और रेवेन्यू टारगेट के साथ सैलरी बढ़ोतरी का गणित।</small>
    </div>
  </div>

  <div style="background:#f0fdf4;padding:16px;border-radius:10px;border:1px solid #bbf7d0;margin:16px 0">
    <h4 style="margin:0 0 10px;color:#166534">🎯 Target Monthly Revenue Requirement for Team Salary Hikes</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #dcfce7">
        <small style="color:#64748b;font-weight:700">Current Base Target</small>
        <strong style="display:block;font-size:18px;color:#0f172a">₹1,50,000 / mo</strong>
        <small style="color:#16a34a">Covers ₹99,550 Burn + ₹50k Profit</small>
      </div>
      <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #dcfce7">
        <small style="color:#64748b;font-weight:700">Hike Milestone 1</small>
        <strong style="display:block;font-size:18px;color:#2563eb">₹2,00,000 / mo</strong>
        <small>Deepak ₹30k · Chandrika ₹18k · Priya/Sanju ₹15k</small>
      </div>
      <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #dcfce7">
        <small style="color:#64748b;font-weight:700">Hike Milestone 2</small>
        <strong style="display:block;font-size:18px;color:#7c3aed">₹2,75,000 / mo</strong>
        <small>Deepak ₹35k · Chandrika ₹22k · Priya/Sanju ₹18k</small>
      </div>
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
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">📜 Module 23: Daily 10 AM Briefing & Permanent Resolved History</h3>
      <small style="color:#64748b">सुबह 10 बजे के रिमाइंडर्स, पेंडिंग काम और नीचे सुरक्षित Resolved History (No Auto-Deletion)।</small>
    </div>
  </div>
  <table style="margin-top:14px">
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

<?php
// ==========================================
// MODULE 15: ADS CENTER (META CTWA & GOOGLE ADS)
// ==========================================
if ($activeModId === 15):
?>
<div class="box" style="border-left:4px solid #2563eb">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">📢 Module 15: Ads Center (Meta CTWA & Google Search Ads)</h3>
      <small style="color:#64748b">₹10,000/माह Meta Click-to-WhatsApp (CTWA) कैंपेन और High-Intent Google Search Ads ट्रैकिंग।</small>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0">
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Monthly Ad Budget</small>
      <strong style="display:block;font-size:24px;color:#2563eb;margin-top:4px">₹10,000 / mo</strong>
      <small>Meta CTWA ₹6,000 · Google ₹4,000</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Target Cost Per Lead (CPL)</small>
      <strong style="display:block;font-size:24px;color:#059669;margin-top:4px">₹12 - ₹20</strong>
      <small>WhatsApp Direct Inquiries</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">WhatsApp Destination</small>
      <strong style="display:block;font-size:20px;color:#0f172a;margin-top:4px">+91 7688855600</strong>
      <small>Assigned to Chandrika (Sales)</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Lead Conversion Target</small>
      <strong style="display:block;font-size:24px;color:#7c3aed;margin-top:4px">18% - 25%</strong>
      <small>Via 10m/30m/2h CRM SLA</small>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 16: MARKETING STRATEGY & 60-DAY PLAN
// ==========================================
if ($activeModId === 16):
?>
<div class="box" style="border-left:4px solid #7c3aed">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">🎯 Module 16: AmbitionHost 60-Day Scaling Blueprint</h3>
      <small style="color:#64748b">2 महीने में रेवेन्यू ₹2.5 लाख/माह तक पहुंचाने और टीम की सैलरी बढ़ाने का एक्शन प्लान।</small>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0">
    <div style="background:#eff6ff;padding:16px;border-radius:10px;border:1px solid #bfdbfe">
      <h4 style="margin:0 0 8px;color:#1e40af">📅 Month 1 (Days 1–30): Foundation & Churn Defense</h4>
      <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#1e3a8a">
        <li><b>120 Suspended Accounts Win-Back:</b> Zero Late Fee अभियान से ₹45,000 रिकवर करना।</li>
        <li><b>26 Registrar Vault Search:</b> 2,000 अकाउंट्स का लॉगिन सेंट्रलाइज करना ताकि NS चेंज 10 मिनट में हो।</li>
        <li><b>SGP >20GB Backup Upsell:</b> 171GB+ वाले अकाउंट्स को ₹199/mo का एक्सटेंडेड बैकअप देना।</li>
        <li><b>Website 6 Bug Fixes:</b> SaluteHost पासवर्ड लॉक और कनाडा VPS हेडिंग ठीक करना।</li>
      </ul>
    </div>
    <div style="background:#f0fdf4;padding:16px;border-radius:10px;border:1px solid #bbf7d0">
      <h4 style="margin:0 0 8px;color:#166534">🚀 Month 2 (Days 31–60): Growth Engine & Salary Hikes</h4>
      <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#14532d">
        <li><b>Bulk .COM Push:</b> 5-19 Domains ₹599, 20+ Domains ₹571 (Zero-GST UPI) से एजेंसी क्लाइंट्स लाना।</li>
        <li><b>YouTube Creator Affiliates 2.0:</b> बिना झूठे वादे के 25-30% कमीशन पर टॉप टेक यूट्यूबर्स को जोड़ना।</li>
        <li><b>₹10k Meta CTWA Ads:</b> डायरेक्ट व्हाट्सएप सेल्स से रोज़ 15-20 फ्रेश लीड्स जनरेट करना।</li>
        <li><b>Team Salary Hikes:</b> दीपक ₹30k, चन्द्रिका ₹18k, प्रिया ₹15k, संजू ₹15k का माइलस्टोन हासिल करना।</li>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 17: ATTRIBUTION & CHANNEL ROI
// ==========================================
if ($activeModId === 17):
?>
<div class="box" style="border-left:4px solid #059669">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">📊 Module 17: Attribution & Channel ROI Matrix</h3>
      <small style="color:#64748b">किस चैनल (Meta, Google, YouTube, WhatsApp Direct) से कितना खर्च हुआ और कितना शुद्ध ROI आया।</small>
    </div>
  </div>

  <table style="margin-top:16px">
    <thead><tr><th>Acquisition Channel</th><th>Monthly Spend</th><th>Leads / Visits</th><th>Conversions</th><th>Revenue Generated</th><th>Net Channel ROI</th></tr></thead>
    <tbody>
      <tr>
        <td><b>Meta Click-to-WhatsApp (CTWA)</b></td>
        <td>₹6,000</td>
        <td>320 WhatsApp Chats</td>
        <td>68 Clients (21.2%)</td>
        <td><b style="color:#16a34a">₹24,500</b></td>
        <td><span class="pill pill-green">+308% ROI</span></td>
      </tr>
      <tr>
        <td><b>Google High-Intent Search Ads</b></td>
        <td>₹4,000</td>
        <td>180 Clicks</td>
        <td>32 Clients (17.7%)</td>
        <td><b style="color:#16a34a">₹16,200</b></td>
        <td><span class="pill pill-green">+305% ROI</span></td>
      </tr>
      <tr>
        <td><b>YouTube Creator Affiliates (25-30%)</b></td>
        <td>₹3,500 (Commission)</td>
        <td>410 Clicks</td>
        <td>45 Clients</td>
        <td><b style="color:#16a34a">₹18,000</b></td>
        <td><span class="pill pill-green">+414% ROI</span></td>
      </tr>
      <tr>
        <td><b>Organic WhatsApp Direct & Word of Mouth</b></td>
        <td>₹0</td>
        <td>520 Inquiries</td>
        <td>140 Clients</td>
        <td><b style="color:#16a34a">₹52,000</b></td>
        <td><span class="pill pill-blue">Pure Organic</span></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 19: YOUTUBE CREATORS & AFFILIATES 2.0
// ==========================================
if ($activeModId === 19):
?>
<div class="box" style="border-left:4px solid #dc2626">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">🎥 Module 19: YouTube Creators & Affiliates 2.0</h3>
      <small style="color:#64748b">ambitionhost.in/user/aff.php के ज़रिए 25-30% कमीशन ट्रैकिंग (बिना झूठे वादे के)।</small>
    </div>
    <a class="btn-dark" href="<?=htmlspecialchars(whmcs_deep_link('affiliates'))?>" target="_blank">WHMCS Affiliates ↗</a>
  </div>

  <div style="background:#fef2f2;padding:12px 14px;border-radius:8px;border:1px solid #fecaca;margin:14px 0;font-size:13px;line-height:1.5;color:#991b1b">
    ⚠️ <b>AmbitionHost Affiliate Policy Rule:</b> किसी भी यूट्यूबर को "Lifetime Free Domain" का झूठा दावा करने की अनुमति नहीं है। केवल 1st Year Free Domain या 25-30% रेवेन्यू शेयरिंग की पक्की गारंटी दी जाएगी।
  </div>

  <table>
    <thead><tr><th>Creator / Channel</th><th>WHMCS Aff ID</th><th>Commission %</th><th>Referrals Converted</th><th>Revenue</th><th>Payout Status</th></tr></thead>
    <tbody>
      <tr>
        <td><b>Tech Hindi Master</b></td>
        <td><code>aff=102</code></td>
        <td><b>30%</b></td>
        <td>42 Clients</td>
        <td>₹18,500</td>
        <td><span class="pill pill-green">Paid</span></td>
      </tr>
      <tr>
        <td><b>Web Dev Gyan</b></td>
        <td><code>aff=145</code></td>
        <td><b>25%</b></td>
        <td>28 Clients</td>
        <td>₹11,200</td>
        <td><span class="pill pill-orange">₹2,800 Pending</span></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 20: REVIEWS & CSAT ESCALATION DESK
// ==========================================
if ($activeModId === 20):
?>
<div class="box" style="border-left:4px solid #ea580c">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">⭐ Module 20: Reviews & CSAT Escalation Desk (rate.php)</h3>
      <small style="color:#64748b">Internal CSAT feedback (rate.php), शिकायतों का त्वरित निवारण और ≤3★ सबमिशन पर Aarav Telegram Alert।</small>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0">
    <div style="background:#f0fdf4;padding:14px;border-radius:10px;border:1px solid #bbf7d0">
      <small style="color:#166534;font-weight:700">4★ & 5★ Ratings (Happy Clients)</small>
      <strong style="display:block;font-size:24px;color:#16a34a;margin-top:4px">88%</strong>
      <small>Invited to Public Trustpilot / Google</small>
    </div>
    <div style="background:#fef2f2;padding:14px;border-radius:10px;border:1px solid #fecaca">
      <small style="color:#991b1b;font-weight:700">≤3★ Escalation Submissions</small>
      <strong style="display:block;font-size:24px;color:#dc2626;margin-top:4px">12%</strong>
      <small>Escalated directly to Aarav Telegram</small>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0">
      <small style="color:#64748b;font-weight:700">Trustpilot Guidelines</small>
      <strong style="display:block;font-size:20px;color:#0f172a;margin-top:4px">100% Compliant</strong>
      <small>No Review Bribes / Zero Review Gating</small>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ==========================================
// MODULE 24: AUTOMATION & 10M SNOOZE CONTROL
// ==========================================
if ($activeModId === 24):
?>
<div class="box" style="border-left:4px solid #0f172a">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0;font-size:18px">⚙️ Module 24: Automation & 10m Snooze Watchdog</h3>
      <small style="color:#64748b">10-Min Repeating Snooze Alarm, 30-Min SLA Telegram Alert, और Auto-Followups की निगरानी।</small>
    </div>
  </div>

  <table style="margin-top:16px">
    <thead><tr><th>Automation Watchdog</th><th>Trigger Condition</th><th>Target Owner</th><th>Current Engine Status</th></tr></thead>
    <tbody>
      <tr>
        <td><b>10-Min Offer/Urgent Snooze Alarm</b></td>
        <td>If Offer notification unread after 10m</td>
        <td>Chandrika & Deepak</td>
        <td><span class="pill pill-green">Active (Repeating every 10m)</span></td>
      </tr>
      <tr>
        <td><b>30-Min Human Reply SLA Escalation</b></td>
        <td>If working minutes >30 without human reply</td>
        <td>Aarav (Admin Telegram)</td>
        <td><span class="pill pill-green">Active (09:00 - 18:00 IST)</span></td>
      </tr>
      <tr>
        <td><b>Lead CRM Price Follow-ups</b></td>
        <td>+10m, +30m, +120m after quote sent</td>
        <td>Chandrika (Sales)</td>
        <td><span class="pill pill-green">Active (Auto-cancels on reply)</span></td>
      </tr>
      <tr>
        <td><b>Daily 9 AM MySQL Backup Integrity Check</b></td>
        <td>Every morning at 09:00 AM IST</td>
        <td>Deepak (B.D.E.)</td>
        <td><span class="pill pill-blue">Scheduled (10 Random Accounts)</span></td>
      </tr>
      <tr>
        <td><b>Daily 10 AM Briefing Generator</b></td>
        <td>Every morning at 10:00 AM IST</td>
        <td>All 5 Staff Members</td>
        <td><span class="pill pill-blue">Scheduled (Pending Tasks)</span></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>
