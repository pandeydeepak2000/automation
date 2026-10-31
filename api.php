<?php
// api.php - Final Production Backend Controller (With Autonomous Brainstorm Offer Engine & Role Controls)
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whm_whmcs_sync.php';
require_once __DIR__ . '/order_bot_engine.php';
require_once __DIR__ . '/ai_brain.php';

$pdo = db();
$staff = current_staff_user();
$currentUser = $staff['name'] ?? 'Aarav';
$currentTitle = $staff['display_title'] ?? 'Aarav (Admin)';
$isAdmin = (($staff['role'] ?? '') === 'Admin' || $currentUser === 'Aarav');
$action = $_REQUEST['action'] ?? '';

function user_can_edit_task(array $task, string $user): bool {
    if ($task['owner'] === $user) return true;
    $grants = array_filter(array_map('trim', explode(',', (string)($task['granted_users'] ?? ''))));
    return in_array($user, $grants, true);
}

// 1-Click CA GST CSV Export
if ($action === 'export_ca_csv' && is_module_allowed(4, $staff)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AmbitionHost_CA_GST_' . date('Y_m_d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Record ID', 'WHMCS Invoice #', 'Customer Name', 'Amount (INR)', 'Source', 'UTR / Ref', 'Sanju Verified', 'Invoice Status', 'Service Delivered', 'Date']);
    $rows = $pdo->query("SELECT id, whmcs_invoice_id, customer_name, amount, payment_source, payment_id_or_utr, sanju_verified, whmcs_invoice_status, service_delivered, created_at FROM payment_records ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {

        // 1. AUTONOMOUS GLOBAL BRAINSTORM & OFFER/URGENT EXECUTION ENGINE (Admin Only)
        case 'ask_global_brainstorm':
            if (!$isAdmin) throw new Exception('Access Denied: सिर्फ Aarav (Admin) के लिए है।');
            $prompt = trim($_POST['prompt'] ?? '');
            if ($prompt === '') throw new Exception('अपना मैसेज लिखें');

            // Rule A: Greeting Check -> Immediately ask what new Offer/Plan Aarav is launching today!
            if (preg_match('/^(hello|hi|hey|good morning|good afternoon|good evening|gm|namaste|नमस्ते|हेलो|हाय)\b/i', $prompt) && mb_strlen($prompt) < 35) {
                $greetingReply = "### Good Day, Aarav (Admin)!\n"
                    . "आज तुम **AmbitionHost** में क्या नया करने वाले हो? या तुम्हारा कोई नया **Offer** या **Planning** है तो बताओ!\n\n"
                    . "*(जैसे ही तुम कोई भी नया Offer या Urgent Action यहाँ लिखोगे, मैं उसकी पूरी Banner, Website, WhatsApp Status और Instagram की प्लानिंग बनाकर बिना तुमसे दोबारा पूछे सीधे **Chandrika (Sales)** और **Deepak (B.D.E.)** के डैशबोर्ड में ऑटोमैटिक भेज दूँगा, और अगर वो 10 मिनट में Read नहीं करेंगे तो उन्हें हर 10 मिनट पर लगातार अलार्म जाएगा!)*";

                $pdo->prepare("INSERT INTO ai_module_chats (module_id, user_name, llm_provider, user_prompt, ai_response) VALUES (0, 'Aarav (Admin)', 'BRAIN', ?, ?)")
                    ->execute([$prompt, $greetingReply]);
                echo json_encode(['status' => 'ok', 'auto_dispatched' => false, 'response' => $greetingReply]);
                break;
            }

            // Rule B: Detect Offer Launch or Urgent Action Keywords (or forced checkbox)
            $isOfferOrUrgent = !empty($_POST['force_offer_launch']) || (bool)preg_match('/(offer|launch|plan|planning|promo|discount|urgent|action|299|599|571|ऑफर|लॉन्च|प्लानिंग|अर्जेंट)/iu', $prompt);

            if ($isOfferOrUrgent) {
                $execPrompt = $prompt . "\n\n"
                    . "[SYSTEM EXECUTION INSTRUCTION]: Aarav (Admin) has commanded an Offer Launch / Urgent Action. Generate a complete, ready-to-execute blueprint divided into 3 clear sections:\n"
                    . "1. Website, Banner & Instagram Execution for Deepak (B.D.E.) (Exact Banner Headline, Website Flash Bar Text, Promo Code/Price check, Instagram Post/Story creative brief).\n"
                    . "2. WhatsApp Status, Broadcast & Sales Closing Script for Chandrika (Sales) (Ready-to-copy WhatsApp Status text, Broadcast message in Hinglish/English, KYC/Terms check).\n"
                    . "3. Small-Budget Paid Ads Proposal for Aarav (Admin) (Ask Aarav if we should run a ₹200-₹330/day Meta Click-to-WhatsApp Ad for this offer, and show the exact targeting & expected leads).";

                $aiRes = AmbitionAIBrain::askGlobalBrainstorm($execPrompt, $staff);
                $fullPlan = $aiRes['response'];

                // Autonomous Dispatch 1 -> Deepak (B.D.E.) without asking Aarav!
                $insTask = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, due_at) VALUES (?, ?, ?, ?, 'urgent', NOW())");
                $deepakTitle = "🚀 URGENT OFFER/ACTION LAUNCH (By Admin): Website, Banner & Insta Setup";
                $insTask->execute([14, $deepakTitle, "Aarav (Admin) ने Brainstorm से यह नया Offer/Urgent Action लॉन्च किया है। अपना हिस्सा तुरंत लाइव करें:\n\n" . $fullPlan, 'Deepak']);
                $deepakTaskId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES ('Deepak', ?, ?, ?)")
                    ->execute([$deepakTaskId, "offer-launch-deepak-{$deepakTaskId}-0", "🚨 [URGENT OFFER LAUNCH BY ADMIN]: {$deepakTitle} (10 मिनट में Read करें वरना हर 10 मिनट पर अलार्म बजेगा!)"]);

                // Autonomous Dispatch 2 -> Chandrika (Sales) without asking Aarav!
                $chandrikaTitle = "🚀 URGENT OFFER/ACTION LAUNCH (By Admin): WhatsApp Status, Broadcast & Sales Pitch";
                $insTask->execute([13, $chandrikaTitle, "Aarav (Admin) ने Brainstorm से यह नया Offer/Urgent Action लॉन्च किया है। तुरंत WhatsApp Status और Sales पर लागू करें:\n\n" . $fullPlan, 'Chandrika']);
                $chandrikaTaskId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES ('Chandrika', ?, ?, ?)")
                    ->execute([$chandrikaTaskId, "offer-launch-chandrika-{$chandrikaTaskId}-0", "🚨 [URGENT OFFER LAUNCH BY ADMIN]: {$chandrikaTitle} (10 मिनट में Read करें वरना हर 10 मिनट पर अलार्म बजेगा!)"]);

                log_system_activity('Aarav (Admin) via Brainstorm AI', 0, "Auto-launched Offer/Urgent Action to Deepak (Task #{$deepakTaskId}) & Chandrika (Task #{$chandrikaTaskId}) with 10-min snooze enforcement.");

                echo json_encode(['status' => 'ok', 'auto_dispatched' => true, 'response' => $fullPlan]);
                break;
            }

            // Standard 360° Brainstorm Consultation
            $aiRes = AmbitionAIBrain::askGlobalBrainstorm($prompt, $staff);
            echo json_encode(['status' => 'ok', 'auto_dispatched' => false, 'response' => $aiRes['response']]);
            break;

        // 2. Per-Module Scoped AI Chat (Inside Modules 1 to 25)
        case 'ask_ai':
            $modId = (int)($_POST['module_id'] ?? 1);
            if (!is_module_allowed($modId, $staff)) throw new Exception('Access Denied for this module');
            $modTitle = trim($_POST['module_title'] ?? 'Module');
            $prompt = trim($_POST['prompt'] ?? '');
            if ($prompt === '') throw new Exception('सवाल लिखें');
            $res = AmbitionAIBrain::askModuleAdvisor($modId, $modTitle, $prompt, $staff);
            echo json_encode(['status' => 'ok', 'data' => $res]);
            break;

        // 3. Module 09: Toggle Which Modules Are Visible to Each Staff Member (Admin Only)
        case 'toggle_module_visibility':
            if (!$isAdmin) throw new Exception('Only Aarav (Admin) can change module visibility');
            $targetUser = trim($_POST['staff_name'] ?? '');
            $modId      = (int)($_POST['module_id'] ?? 0);
            if ($modId === 1) throw new Exception('Module 01 सबके लिए अनिवार्य है।');

            $stmt = $pdo->prepare("SELECT allowed_modules, display_title FROM users WHERE name = ?");
            $stmt->execute([$targetUser]);
            $uRow = $stmt->fetch();
            if (!$uRow) throw new Exception('Staff not found');

            $mods = array_map('intval', array_filter(explode(',', (string)$uRow['allowed_modules'])));
            if (in_array($modId, $mods, true)) {
                $mods = array_values(array_diff($mods, [$modId]));
            } else {
                $mods[] = $modId;
                sort($mods);
            }
            $pdo->prepare("UPDATE users SET allowed_modules = ? WHERE name = ?")->execute([implode(',', $mods), $targetUser]);
            log_system_activity($currentTitle, 9, "Updated Module #{$modId} visibility for {$uRow['display_title']}");
            echo json_encode(['status' => 'ok', 'allowed_modules' => implode(',', $mods)]);
            break;

        // 4. Create / Resolve / Transfer Task
        case 'create_task':
            $modId = (int)($_POST['module_id'] ?? 1);
            $title = trim($_POST['title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $owner = trim($_POST['owner'] ?? $currentUser);
            $kind  = $_POST['task_kind'] ?? 'urgent';
            $link  = trim($_POST['whmcs_link'] ?? '');
            if ($title === '') throw new Exception('Task title required');

            $stmt = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, whmcs_link, due_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$modId, $title, $desc, $owner, $kind, $link]);
            $tid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES (?, ?, ?, ?)")
                ->execute([$owner, $tid, "new-task-{$tid}-" . time(), "🆕 New Task Assigned (#{$tid}): {$title}"]);
            log_system_activity($currentTitle, $modId, "Assigned Task #{$tid} ({$title}) to {$owner}");
            echo json_encode(['status' => 'ok', 'task_id' => $tid]);
            break;

        case 'update_task':
            $tid = (int)($_POST['task_id'] ?? 0);
            $sub = $_POST['sub_action'] ?? '';
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$tid]);
            $task = $stmt->fetch();
            if (!$task) throw new Exception('Task not found');

            if ($sub === 'grant_access') {
                if (!$isAdmin) throw new Exception('सिर्फ Aarav (Admin) ही extra permission दे सकते हैं।');
                $grantTo = trim($_POST['grant_to'] ?? 'Aarav');
                $grants = array_filter(array_map('trim', explode(',', (string)$task['granted_users'])));
                if (!in_array($grantTo, $grants, true)) $grants[] = $grantTo;
                $pdo->prepare("UPDATE tasks SET granted_users = ? WHERE id = ?")->execute([implode(',', $grants), $tid]);
                echo json_encode(['status' => 'ok']);
                break;
            }

            if (!user_can_edit_task($task, $currentUser)) {
                throw new Exception('Read-Only Rule: दूसरे के काम में कोई बदलाव नहीं किया जा सकता।');
            }

            if ($sub === 'resolve') {
                $ev = trim($_POST['evidence'] ?? '');
                if ($ev === '') throw new Exception('Resolution Evidence लिखना अनिवार्य है।');
                $pdo->prepare("UPDATE tasks SET status='Resolved', resolution_evidence=?, resolved_at=NOW() WHERE id=?")->execute([$ev, $tid]);
                log_system_activity($currentTitle, (int)$task['module_id'], "Resolved Task #{$tid} with proof: {$ev}");
            } elseif ($sub === 'transfer') {
                $newOwner = trim($_POST['new_owner'] ?? '');
                if ($newOwner === '' || $newOwner === $task['owner']) throw new Exception('Valid new owner चुनें');
                $prevs = array_filter(array_map('trim', explode(',', (string)$task['previous_owners'])));
                if (!in_array($task['owner'], $prevs, true)) $prevs[] = $task['owner'];
                $pdo->prepare("UPDATE tasks SET owner=?, previous_owners=? WHERE id=?")->execute([$newOwner, implode(',', $prevs), $tid]);
                $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES (?, ?, ?, ?)")
                    ->execute([$newOwner, $tid, "xfer-{$tid}-" . time(), "⇄ Task #{$tid} transferred to you: {$task['title']}"]);
                log_system_activity($currentTitle, (int)$task['module_id'], "Transferred Task #{$tid} to {$newOwner}");
            }
            echo json_encode(['status' => 'ok']);
            break;

        // 5. Mark Notification as Read (Stops 10-Min Repeating Offer Alarm, Does NOT Resolve Task)
        case 'read_notification':
            $nid = (int)($_POST['notification_id'] ?? 0);
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient = ?")->execute([$nid, $currentUser]);
            echo json_encode(['status' => 'ok']);
            break;

        // 6. Module 07: Staff Reply to Website Ticket -> Triggers Strict ZERO-LINK Email!
        case 'reply_local_ticket':
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $replyMsg = trim($_POST['reply_message'] ?? '');
            if ($replyMsg === '') throw new Exception('Reply message required');

            $tStmt = $pdo->prepare("SELECT * FROM support_tickets_local WHERE id = ?");
            $tStmt->execute([$ticketId]);
            $tk = $tStmt->fetch();
            if (!$tk) throw new Exception('Ticket not found');

            $pdo->prepare("INSERT INTO ticket_messages_local (ticket_id, sender_type, sender_name, message) VALUES (?, 'Staff', ?, ?)")
                ->execute([$ticketId, $currentTitle, $replyMsg]);
            $pdo->prepare("UPDATE support_tickets_local SET status = 'Answered' WHERE id = ?")->execute([$ticketId]);

            // STRICT ZERO-LINK PLAIN-TEXT EMAIL NOTIFICATION TO CUSTOMER
            send_zero_link_email(
                (string)$tk['customer_email'],
                "Ticket {$tk['ticket_code']} Update - AmbitionHost",
                "There is a response made on your ticket. Please check."
            );

            log_system_activity($currentTitle, 7, "Replied to Ticket {$tk['ticket_code']} (Zero-link email notification sent to customer).");
            echo json_encode(['status' => 'ok']);
            break;

        // 7. Payment Upload (Protected MIME Validation) & Sanju Verification
        case 'payment_action':
            $pSub = $_POST['pay_sub'] ?? '';
            if ($pSub === 'add_payment') {
                $invId = (int)($_POST['whmcs_invoice_id'] ?? 0);
                $cname = trim($_POST['customer_name'] ?? '');
                $amt   = (float)($_POST['amount'] ?? 0);
                $src   = $_POST['payment_source'] ?? 'WhatsApp_UPI';
                $utr   = trim($_POST['payment_id_or_utr'] ?? '');
                $owner = $_POST['delivery_owner'] ?? 'Chandrika';
                $proofUrl = '';

                if (!empty($_FILES['proof_image']['tmp_name'])) {
                    if ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) throw new Exception('Image must be under 5MB');
                    $imgInfo = @getimagesize($_FILES['proof_image']['tmp_name']);
                    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!$imgInfo || !isset($allowedMimes[$imgInfo['mime']])) {
                        throw new Exception('Only valid JPG, PNG, or WEBP screenshots allowed');
                    }
                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                        file_put_contents($uploadDir . '.htaccess', "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|php5|php7|php8|pl|py|sh|cgi)$\">\nRequire all denied\n</FilesMatch>\n");
                    }
                    $fname = 'proof_inv_' . $invId . '_' . time() . '.' . $allowedMimes[$imgInfo['mime']];
                    if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadDir . $fname)) {
                        $proofUrl = 'uploads/' . $fname;
                    }
                }
                $pdo->prepare("INSERT INTO payment_records (whmcs_invoice_id, customer_name, amount, payment_source, payment_id_or_utr, proof_file_url, delivery_owner) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$invId, $cname, $amt, $src, $utr, $proofUrl, $owner]);
                log_system_activity($currentTitle, 4, "Uploaded ₹{$amt} payment proof for Invoice #{$invId}");
            } elseif ($pSub === 'sanju_verify') {
                $pid = (int)$_POST['payment_row_id'];
                $pdo->prepare("UPDATE payment_records SET sanju_verified=1, whmcs_invoice_status='Paid' WHERE id=?")->execute([$pid]);
                log_system_activity($currentTitle, 4, "Verified bank receipt for Payment Record #{$pid}");
            } elseif ($pSub === 'mark_delivered') {
                $pid = (int)$_POST['payment_row_id'];
                $pdo->prepare("UPDATE payment_records SET service_delivered=1 WHERE id=?")->execute([$pid]);
                log_system_activity($currentTitle, 3, "Marked service delivered for Payment Record #{$pid}");
            }
            echo json_encode(['status' => 'ok']);
            break;

        // 8. Sales CRM Actions (30m SLA & 10m/30m/2h Timers)
        case 'crm_action':
            $crmSub = $_POST['crm_sub'] ?? '';
            if ($crmSub === 'new_lead') {
                $thread = trim($_POST['thread_id'] ?? ('wa-' . time()));
                $ident  = trim($_POST['customer_identity'] ?? 'WhatsApp Lead');
                $msg    = trim($_POST['message'] ?? 'Enquiry');
                $pdo->prepare("INSERT INTO sales_leads (thread_id, customer_identity, first_msg_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE customer_identity=VALUES(customer_identity)")
                    ->execute([$thread, $ident]);
                $lid = (int)$pdo->lastInsertId();
                if ($lid > 0) {
                    $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, lead_id, due_at) VALUES (13, ?, ?, 'Chandrika', 'followup', ?, NOW())")
                        ->execute(["New Sales Enquiry · {$ident}", "Message: {$msg} (Reply within 30 working mins)", $lid]);
                }
            } elseif ($crmSub === 'human_reply') {
                $pdo->prepare("UPDATE sales_leads SET first_human_reply_at = IFNULL(first_human_reply_at, NOW()) WHERE id = ?")->execute([(int)$_POST['lead_id']]);
            } elseif ($crmSub === 'price_sent') {
                $pdo->prepare("UPDATE sales_leads SET first_human_reply_at = IFNULL(first_human_reply_at, NOW()), price_sent_at = NOW(), customer_replied_after_price = 0 WHERE id = ?")->execute([(int)$_POST['lead_id']]);
            } elseif ($crmSub === 'customer_replied') {
                $lid = (int)$_POST['lead_id'];
                $pdo->prepare("UPDATE sales_leads SET customer_replied_after_price = 1 WHERE id = ?")->execute([$lid]);
                $pdo->prepare("UPDATE tasks SET status='Cancelled', resolution_evidence='Customer replied; follow-ups stopped' WHERE lead_id=? AND task_kind='followup' AND status='Pending'")->execute([$lid]);
            }
            echo json_encode(['status' => 'ok']);
            break;

        // 9. Master Domain Vault WhatsApp .txt Importer
        case 'import_domain_vault':
            $rawText = trim($_POST['vault_text'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
            $registrars = ['NameSilo','Cosmotown','Spaceship','Namecheap','Dynadot','Atak','Nominalia','Register.it','Porkbun','one.com','BigRock','Domain.com','Epik','Name.com','Sav','GoDaddy','DreamHost','WHC','Unstoppable','Network Solutions','Wix','Directnic','Hioxindia','Hostinger','Truehost','Gname'];
            $imported = 0;
            $ins = $pdo->prepare("INSERT INTO domain_vault (domain_name, registrar_name, account_email_or_id, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE registrar_name=VALUES(registrar_name), account_email_or_id=VALUES(account_email_or_id), notes=VALUES(notes)");
            foreach ($lines as $line) {
                if (preg_match('/([a-z0-9][a-z0-9\-]{1,62}\.(?:com|in|net|org|xyz|io|co\.in|shop|site|fun|cfd|click|ca|it|info|online))/i', $line, $m)) {
                    $dom = strtolower($m[1]);
                    $em  = preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $line, $emM) ? strtolower($emM[1]) : 'Check Note';
                    $reg = 'Spaceship/Other';
                    foreach ($registrars as $r) { if (stripos($line, $r) !== false) { $reg = $r; break; } }
                    if ($dom !== $em) { $ins->execute([$dom, $reg, $em, mb_substr($line, 0, 240)]); $imported++; }
                }
            }
            echo json_encode(['status' => 'ok', 'imported_count' => $imported]);
            break;

        // 10. WHM Server Add & Sync (14 Ticked Privileges)
        case 'whm_action':
            $wSub = $_POST['whm_sub'] ?? '';
            if ($wSub === 'add_server') {
                $pdo->prepare("INSERT INTO whm_servers (server_name, provider, hostname, whm_username, api_token, monthly_cost_inr, max_backup_gb) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        trim($_POST['server_name'] ?? 'WHM Server'),
                        trim($_POST['provider'] ?? 'Custom'),
                        trim($_POST['hostname'] ?? ''),
                        trim($_POST['whm_username'] ?? 'root'),
                        trim($_POST['api_token'] ?? ''),
                        (float)($_POST['monthly_cost_inr'] ?? 0),
                        (int)($_POST['max_backup_gb'] ?? 20)
                    ]);
                echo json_encode(['status' => 'ok']);
            } elseif ($wSub === 'sync_server') {
                require_once __DIR__ . '/whm_whmcs_sync.php';
                $sid = (int)($_POST['server_id'] ?? 0);
                try {
                    $res = AmbitionInfraSync::syncWhmServer($sid);
                    if (($res['status'] ?? '') === 'error') {
                        echo json_encode(['status' => 'error', 'message' => $res['error'] ?? 'WHM Sync Failed']);
                    } else {
                        echo json_encode([
                            'status' => 'ok',
                            'message' => "✅ Server Synced! Total: {$res['total_accounts']}, Active: {$res['active_accounts']}, Suspended: {$res['suspended_accounts']}, Load: {$res['load_avg']}",
                            'data' => $res
                        ]);
                    }
                } catch (Throwable $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Sync Error: ' . $e->getMessage()]);
                }
            } elseif ($wSub === 'test_server') {
                require_once __DIR__ . '/whm_whmcs_sync.php';
                $host = trim($_POST['hostname'] ?? '');
                $user = trim($_POST['whm_username'] ?? 'root');
                $tok  = trim($_POST['api_token'] ?? '');
                $test = AmbitionInfraSync::testWhmServer($host, $user, $tok);
                echo json_encode($test);
            }
            break;

        // 11. Module 25: Save System & Multi-LLM Settings directly to Database
        case 'save_system_settings':
            if (!$isAdmin) throw new Exception('Admin only');
            $allowedKeys = [
                'active_llm_provider', 'telegram_bot_token', 'aarav_telegram_chat_id',
                'order_bot_telegram_chat_id', 'order_bot_enabled', 'order_bot_pending_reminders',
                'order_bot_reminder_interval_mins', 'telegram_api_gateway',
                'whmcs_url', 'whmcs_api_identifier', 'whmcs_api_secret',
                'razorpay_key_id', 'razorpay_key_secret'
            ];
            foreach ($_POST as $k => $v) {
                if (str_starts_with($k, 'api_key_') || in_array($k, $allowedKeys, true)) {
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                        ->execute([$k, trim((string)$v)]);
                }
            }
            echo json_encode(['status' => 'ok', 'message' => 'Settings saved successfully']);
            break;

        // Telegram 24/7 Order Bot: Test Telegram Connection
        case 'test_telegram_bot':
            if (!$isAdmin) throw new Exception('Admin only');
            $customToken = trim($_POST['telegram_bot_token'] ?? '');
            $customChatId = trim($_POST['telegram_chat_id'] ?? '');
            $testRes = AmbitionOrderBot::testTelegramConnection($customToken, $customChatId);
            if ($testRes['status'] === 'ok') {
                echo json_encode(['status' => 'ok', 'message' => '✅ Telegram Bot Test Message Sent Successfully! Check your Telegram chat.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => $testRes['message'] ?? 'Failed to send Telegram message']);
            }
            break;

        // Telegram 24/7 Order Bot: Run Instant Sync & Escalation Check
        case 'run_order_bot_check':
            if (!$isAdmin) throw new Exception('Admin only');
            $rep = AmbitionOrderBot::syncAndCheckOrders(true);
            if (!empty($rep['api_error'])) {
                $err = $rep['api_error'];
                if (stripos($err, 'IP Banned') !== false) {
                    $serverIp = get_server_public_ip();
                    echo json_encode([
                        'status' => 'error',
                        'message' => "🚨 WHMCS API Error: IP Banned!\n\nWHMCS security firewall ne aapke server ki IP [ {$serverIp} ] ko block/ban kar diya hai.\n\n👉 Turant Unban Karein:\n1. WHMCS Admin me jayein: https://www.ambitionhost.in/user/devokedev/bannedips.php\n2. Wahan '{$serverIp}' ko Delete / Unban karein.\n3. WHMCS Admin ➔ System Settings ➔ General Settings ➔ Security tab me 'API IP Access Restriction' me '{$serverIp}' ko Whitelist (Add) kar dein!",
                        'data' => $rep
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "⚠️ WHMCS API Error: " . $err . "\n\n💡 समाधान: WHMCS Admin ➔ System Settings ➔ API Credentials में जाएं, अपने API Role को Edit करें और 'Get Orders' पर टिक (Check) लगाकर Save कर दें!",
                        'data' => $rep
                    ]);
                }
            } else {
                echo json_encode([
                    'status' => 'ok',
                    'message' => "✅ Order Watchdog Check Complete! Inspected {$rep['orders_inspected']} orders. ({$rep['new_orders_alerted']} new alerted, {$rep['pending_reminders_sent']} pending reminders, {$rep['activated_confirmed']} activated)",
                    'data' => $rep
                ]);
            }
            break;

        case 'diagnose_whmcs_orders':
            if (!$isAdmin) throw new Exception('Admin only');
            $diag = AmbitionOrderBot::diagnoseWhmcsOrders();
            $serverIp = get_server_public_ip();
            $msg = "🔍 WHMCS Orders API Diagnostic:\n"
                 . "• Server Outbound IP: {$serverIp}\n"
                 . "• Target API: {$diag['target_url']}\n"
                 . "• API Configured: " . ($diag['api_configured'] ? 'YES' : 'NO') . "\n"
                 . "• Pending Orders Detected: {$diag['pending_orders_found']}\n"
                 . "• Recent Orders Detected: {$diag['recent_orders_found']}\n"
                 . "• Specific Order #65067 Detected: {$diag['known_order_found']}\n";
            if (!empty($diag['detected_order_ids'])) {
                $msg .= "• Detected IDs: " . json_encode($diag['detected_order_ids'], JSON_UNESCAPED_UNICODE) . "\n";
            }
            if (!empty($diag['api_issues'])) {
                $hasBan = false;
                foreach ($diag['api_issues'] as $iss) {
                    if (stripos($iss, 'IP Banned') !== false) $hasBan = true;
                }
                if ($hasBan) {
                    $msg .= "\n🚨 CRITICAL: WHMCS IP BANNED DETECTED!\n"
                         . "WHMCS ne server IP [ {$serverIp} ] ko ban kar diya hai.\n\n"
                         . "👉 Solution:\n"
                         . "1. Open: https://www.ambitionhost.in/user/devokedev/bannedips.php\n"
                         . "2. '{$serverIp}' ko Unban / Delete karein.\n"
                         . "3. General Settings ➔ Security ➔ 'API IP Access Restriction' me '{$serverIp}' ko add karein!";
                } else {
                    $msg .= "\n⚠️ WHMCS ISSUES FOUND:\n" . implode("\n", $diag['api_issues']) . "\n\n👉 WHMCS Admin ➔ System Settings ➔ API Credentials ➔ Role Permissions me 'Get Orders' ko tick karein!";
                }
            } else if ($diag['pending_orders_found'] > 0 || $diag['recent_orders_found'] > 0) {
                $msg .= "\n✅ WHMCS API is successfully returning orders!";
            }
            echo json_encode([
                'status' => empty($diag['api_issues']) ? 'ok' : 'error',
                'message' => $msg,
                'data' => $diag
            ]);
            break;

        // Telegram 24/7 Order Bot: 1-Click Manual Escalation
        case 'send_manual_order_alert':
            $targetOrderId = (int)($_POST['order_id'] ?? 0);
            if ($targetOrderId <= 0) throw new Exception('Invalid order ID');
            $escRes = AmbitionOrderBot::sendManualEscalation($targetOrderId);
            if ($escRes['status'] === 'ok') {
                echo json_encode(['status' => 'ok', 'message' => "🚨 Urgent SLA Alert for Order #{$targetOrderId} sent to Telegram!"]);
            } else {
                echo json_encode(['status' => 'error', 'message' => $escRes['message'] ?? 'Telegram alert failed']);
            }
            break;

        // 0-Second Instant Webhook Receiver from WHMCS Hook
        case 'whmcs_order_webhook':
            $whOrderId = (int)($_POST['order_id'] ?? ($_GET['order_id'] ?? 0));
            $hookName = (string)($_POST['hook_name'] ?? ($_GET['hook_name'] ?? 'WHMCS_Hook'));
            $res = AmbitionOrderBot::processInstantWebhook($whOrderId, $hookName, $_REQUEST);
            echo json_encode($res);
            exit;

        case 'test_whmcs_connection':
            if (!$isAdmin) throw new Exception('Admin only');
            require_once __DIR__ . '/whm_whmcs_sync.php';
            $res = AmbitionInfraSync::callWhmcs('GetClients', ['limitnum' => 5]);
            if (($res['result'] ?? '') === 'success') {
                $total = (int)($res['totalresults'] ?? 0);
                echo json_encode(['status' => 'ok', 'message' => "✅ WHMCS API Connected Successfully! Found {$total} clients."]);
            } else {
                $errMsg = $res['message'] ?? ($res['raw'] ?? 'Failed to reach WHMCS API endpoint.');
                echo json_encode(['status' => 'error', 'message' => "WHMCS API Error: " . $errMsg]);
            }
            break;

        case 'sync_whmcs_cache':
            require_once __DIR__ . '/whm_whmcs_sync.php';
            $r = AmbitionInfraSync::syncWhmcsItemsCache(500);
            echo json_encode([
                'status' => 'ok',
                'message' => "✅ WHMCS Sync Complete! Total {$r['synced_count']} hosting services & domains cached.",
                'data' => $r
            ]);
            break;

        // 12. Change Email or Password (Owner/Admin can change anyone; Staff can change own)
        case 'change_credentials':
            $targetUserId = (int)($_POST['target_user_id'] ?? $_SESSION['user_id']);
            $newEmail = trim($_POST['new_email'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';

            if ($targetUserId !== (int)$_SESSION['user_id'] && !$isAdmin) {
                throw new Exception('Permission Denied: Only Aarav (Admin) can change other team members.');
            }

            if ($newEmail !== '') {
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check->execute([$newEmail, $targetUserId]);
                if ($check->fetch()) throw new Exception('यह Email पहले से किसी दूसरे स्टाफ द्वारा इस्तेमाल किया जा रहा है।');

                $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$newEmail, $targetUserId]);
            }

            if (!empty($newPassword)) {
                if (strlen($newPassword) < 6) {
                    throw new Exception('Password कम से कम 6 अक्षरों का होना चाहिए।');
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);
            }

            echo json_encode(['status' => 'ok', 'message' => 'Credentials updated successfully']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}