<?php
// api.php - Unified AJAX & Form Backend Controller for AmbitionHost Master OS
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whm_whmcs_sync.php';
require_once __DIR__ . '/ai_brain.php';

$pdo = db();
$action = $_REQUEST['action'] ?? '';
$currentUser = $_SESSION['user_name'] ?? 'Aarav';

// Helper: Verify if current user can edit/resolve a specific task
function user_can_edit_task(array $task, string $user): bool {
    if ($task['owner'] === $user) return true;
    $grants = array_filter(array_map('trim', explode(',', (string)($task['granted_users'] ?? ''))));
    return in_array($user, $grants, true);
}

// 1. Export CA GST / Payment Reconciliation CSV (1-Click Download)
if ($action === 'export_ca_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AmbitionHost_CA_Payments_' . date('Y_m_d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Record ID', 'WHMCS Invoice #', 'Customer Name', 'Email', 'Amount (INR)', 'Source', 'Payment ID / UTR', 'Sanju Verified', 'Invoice Status', 'Service Delivered', 'Date']);
    $rows = $pdo->query("SELECT id, whmcs_invoice_id, customer_name, customer_email, amount, payment_source, payment_id_or_utr, sanju_verified, whmcs_invoice_status, service_delivered, created_at FROM payment_records ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {

        // Change Email or Password (Owner can change anyone; Staff can change own)
        case 'change_credentials':
            $targetUserId = (int)($_POST['target_user_id'] ?? $_SESSION['user_id']);
            $newEmail = trim($_POST['new_email'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';

            if ($targetUserId !== (int)$_SESSION['user_id'] && ($_SESSION['user_role'] ?? '') !== 'Owner' && $currentUser !== 'Aarav') {
                throw new Exception('Permission Denied: Only Aarav can change other team members.');
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

            echo json_encode(['status' => 'ok', 'message' => 'Credentials updated successfully!']);
            break;

        // Create New Task in Any Module
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

            $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, ?, 'system', ?)")
                ->execute([$tid, $currentUser, "Task created and assigned to {$owner}"]);
            $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES (?, ?, ?, ?)")
                ->execute([$owner, $tid, "new-task-{$tid}-" . time(), "🆕 New Task Assigned: {$title}"]);

            echo json_encode(['status' => 'ok', 'task_id' => $tid]);
            break;

        // Add Own Note / Resolve / Mark Not Resolved / Transfer / Aarav Grant Access
        case 'update_task':
            $tid = (int)($_POST['task_id'] ?? 0);
            $sub = $_POST['sub_action'] ?? '';
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$tid]);
            $task = $stmt->fetch();
            if (!$task) throw new Exception('Task not found');

            if ($sub === 'grant_access') {
                if ($currentUser !== 'Aarav') throw new Exception('सिर्फ Aarav ही extra task permission दे सकते हैं।');
                $grantTo = trim($_POST['grant_to'] ?? '');
                $grants = array_filter(array_map('trim', explode(',', (string)$task['granted_users'])));
                if ($grantTo !== '' && !in_array($grantTo, $grants, true)) {
                    $grants[] = $grantTo;
                    $pdo->prepare("UPDATE tasks SET granted_users = ? WHERE id = ?")->execute([implode(',', $grants), $tid]);
                    $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, 'Aarav', 'grant', ?)")
                        ->execute([$tid, "Aarav granted extra edit permission to {$grantTo}"]);
                }
                echo json_encode(['status' => 'ok']);
                break;
            }

            // Enforce Strict Read-Only Rule for Other Users' Tasks
            if (!user_can_edit_task($task, $currentUser)) {
                throw new Exception('Read-Only Rule: दूसरे के काम पर कोई comment, note या बदलाव नहीं किया जा सकता।');
            }

            if ($sub === 'add_note') {
                $note = trim($_POST['note'] ?? '');
                if ($note === '') throw new Exception('Note खाली नहीं हो सकता');
                $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, ?, 'note', ?)")
                    ->execute([$tid, $currentUser, $note]);
            } elseif ($sub === 'resolve') {
                $evidence = trim($_POST['evidence'] ?? '');
                if ($evidence === '') throw new Exception('काम पूरा करने का प्रमाण (Resolution Evidence) लिखना अनिवार्य है।');
                $pdo->prepare("UPDATE tasks SET status = 'Resolved', resolution_evidence = ?, resolved_at = NOW() WHERE id = ?")
                    ->execute([$evidence, $tid]);
                $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, ?, 'status', ?)")
                    ->execute([$tid, $currentUser, "Resolved with evidence: {$evidence} (All reminders stopped)"]);
            } elseif ($sub === 'not_resolved') {
                $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, ?, 'status', 'Checked: Not resolved yet; daily 10 AM reminders remain active.')")
                    ->execute([$tid, $currentUser]);
            } elseif ($sub === 'transfer') {
                $newOwner = trim($_POST['new_owner'] ?? '');
                if ($newOwner === '' || $newOwner === $task['owner']) throw new Exception('Valid new owner चुनें');
                $prevs = array_filter(array_map('trim', explode(',', (string)$task['previous_owners'])));
                if (!in_array($task['owner'], $prevs, true)) $prevs[] = $task['owner'];
                $pdo->prepare("UPDATE tasks SET owner = ?, previous_owners = ? WHERE id = ?")
                    ->execute([$newOwner, implode(',', $prevs), $tid]);
                $pdo->prepare("INSERT INTO task_events (task_id, actor, event_type, message) VALUES (?, ?, 'transfer', ?)")
                    ->execute([$tid, $currentUser, "Transferred from {$task['owner']} to {$newOwner} (Reminders active for both until resolved)"]);
            }
            echo json_encode(['status' => 'ok']);
            break;

        // Mark Notification as Read (Does NOT Resolve Task)
        case 'read_notification':
            $nid = (int)($_POST['notification_id'] ?? 0);
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient = ?")->execute([$nid, $currentUser]);
            echo json_encode(['status' => 'ok']);
            break;

        // Sales CRM: Capture Lead / Record Human Reply / Price Sent / Customer Reply
        case 'crm_action':
            $crmSub = $_POST['crm_sub'] ?? '';
            if ($crmSub === 'new_lead') {
                $thread = trim($_POST['thread_id'] ?? ('wa-' . time()));
                $ident  = trim($_POST['customer_identity'] ?? 'Unconfirmed WhatsApp Lead');
                $msg    = trim($_POST['message'] ?? 'New Hosting/Domain Enquiry');
                $stmt = $pdo->prepare("INSERT INTO sales_leads (thread_id, customer_identity, first_msg_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE customer_identity=VALUES(customer_identity)");
                $stmt->execute([$thread, $ident]);
                $lid = (int)$pdo->lastInsertId();
                if ($lid > 0) {
                    $tStmt = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, lead_id, due_at) VALUES (13, ?, ?, 'Chandrika', 'followup', ?, NOW())");
                    $tStmt->execute(["New Sales Enquiry · {$ident}", "Incoming message: {$msg} (Reply within 30 working mins)", $lid]);
                }
            } elseif ($crmSub === 'human_reply') {
                $lid = (int)$_POST['lead_id'];
                $pdo->prepare("UPDATE sales_leads SET first_human_reply_at = IFNULL(first_human_reply_at, NOW()) WHERE id = ?")->execute([$lid]);
            } elseif ($crmSub === 'price_sent') {
                $lid = (int)$_POST['lead_id'];
                $pdo->prepare("UPDATE sales_leads SET first_human_reply_at = IFNULL(first_human_reply_at, NOW()), price_sent_at = NOW(), customer_replied_after_price = 0 WHERE id = ?")->execute([$lid]);
            } elseif ($crmSub === 'customer_replied') {
                $lid = (int)$_POST['lead_id'];
                $pdo->prepare("UPDATE sales_leads SET customer_replied_after_price = 1 WHERE id = ?")->execute([$lid]);
                // Cancel remaining automated price follow-up tasks
                $pdo->prepare("UPDATE tasks SET status = 'Cancelled', resolution_evidence = 'Customer replied; follow-up auto-cancelled' WHERE lead_id = ? AND task_kind = 'followup' AND status = 'Pending'")->execute([$lid]);
            }
            echo json_encode(['status' => 'ok']);
            break;

        // Upgrade A: WhatsApp Group .txt Export Importer -> Master Domain Vault
        case 'import_domain_vault':
            $rawText = trim($_POST['vault_text'] ?? '');
            if ($rawText === '') throw new Exception('Text empty');
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
            $registrars = ['NameSilo','Cosmotown','Spaceship','Namecheap','Dynadot','Atak','Nominalia','Register.it','Porkbun','one.com','BigRock','Domain.com','Epik','Name.com','Sav','GoDaddy','DreamHost','WHC','Unstoppable','Network Solutions','Wix','Directnic','Hioxindia','Hostinger','Truehost','Gname'];
            $imported = 0;

            $ins = $pdo->prepare("
                INSERT INTO domain_vault (domain_name, registrar_name, account_email_or_id, notes)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE registrar_name=VALUES(registrar_name), account_email_or_id=VALUES(account_email_or_id), notes=VALUES(notes)
            ");

            foreach ($lines as $line) {
                if (preg_match('/([a-z0-9][a-z0-9\-]{1,62}\.(?:com|in|net|org|xyz|io|co\.in|shop|site|fun|cfd|click|ca|it|info|online))/i', $line, $domMatch)) {
                    $domain = strtolower($domMatch[1]);
                    $email = 'Check Group Note';
                    if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $line, $emMatch)) {
                        $email = strtolower($emMatch[1]);
                    }
                    $foundReg = 'Unknown Registrar';
                    foreach ($registrars as $r) {
                        if (stripos($line, $r) !== false) { $foundReg = $r; break; }
                    }
                    if ($domain !== $email) {
                        $ins->execute([$domain, $foundReg, $email, mb_substr($line, 0, 240)]);
                        $imported++;
                    }
                }
            }
            echo json_encode(['status' => 'ok', 'imported_count' => $imported]);
            break;

        // Upgrade B & C: Add Payment Proof / Sanju Verification / Mark Delivered
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
                    $tmpFile = $_FILES['proof_image']['tmp_name'];
                    if (filesize($tmpFile) > 5 * 1024 * 1024) {
                        throw new Exception('Image upload failed: Size exceeds 5MB limit.');
                    }
                    $imgMeta = @getimagesize($tmpFile);
                    $mime = function_exists('mime_content_type') ? @mime_content_type($tmpFile) : ($imgMeta['mime'] ?? '');
                    $allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];
                    if ($imgMeta === false || !isset($allowedMimes[$mime])) {
                        throw new Exception('Security Error: Only genuine image/jpeg, image/png, or image/webp files are allowed.');
                    }

                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = $allowedMimes[$mime];
                    $fname = 'proof_inv_' . $invId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($tmpFile, $uploadDir . $fname)) {
                        $proofUrl = 'uploads/' . $fname;
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO payment_records (whmcs_invoice_id, customer_name, amount, payment_source, payment_id_or_utr, proof_file_url, delivery_owner) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$invId, $cname, $amt, $src, $utr, $proofUrl, $owner]);
            } elseif ($pSub === 'sanju_verify') {
                $pid = (int)$_POST['payment_row_id'];
                $pdo->prepare("UPDATE payment_records SET sanju_verified = 1, whmcs_invoice_status = 'Paid' WHERE id = ?")->execute([$pid]);
            } elseif ($pSub === 'mark_delivered') {
                $pid = (int)$_POST['payment_row_id'];
                $pdo->prepare("UPDATE payment_records SET service_delivered = 1 WHERE id = ?")->execute([$pid]);
            }
            echo json_encode(['status' => 'ok']);
            break;

        // WHM Server Add & 14-Privilege Live Sync
        case 'whm_action':
            $wSub = $_POST['whm_sub'] ?? '';
            if ($wSub === 'add_server') {
                $stmt = $pdo->prepare("INSERT INTO whm_servers (server_name, provider, hostname, whm_username, api_token, monthly_cost_inr, max_backup_gb) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    trim($_POST['server_name'] ?? 'SGP Dedicated Server'),
                    trim($_POST['provider'] ?? 'OVHcloud'),
                    trim($_POST['hostname'] ?? ''),
                    trim($_POST['whm_username'] ?? 'root'),
                    trim($_POST['api_token'] ?? ''),
                    (float)($_POST['monthly_cost_inr'] ?? 15250),
                    (int)($_POST['max_backup_gb'] ?? 20)
                ]);
                $sid = (int)$pdo->lastInsertId();
                $syncRes = AmbitionInfraSync::syncWhmServer($sid);
                echo json_encode(['status' => 'ok', 'sync' => $syncRes]);
            } elseif ($wSub === 'sync_server') {
                $sid = (int)$_POST['server_id'];
                echo json_encode(AmbitionInfraSync::syncWhmServer($sid));
            } elseif ($wSub === 'diagnostic') {
                $sid = (int)$_POST['server_id'];
                $type = $_POST['diag_type'] ?? 'packages';
                $target = trim($_POST['target'] ?? '');
                echo json_encode(AmbitionInfraSync::fetchWhmDiagnostic($sid, $type, $target));
            }
            break;

        // Multi-LLM Advisor Chat (Embedded inside all 25 Modules)
        case 'ask_ai':
            $modId = (int)($_POST['module_id'] ?? 1);
            $modTitle = trim($_POST['module_title'] ?? 'Overview');
            $prompt = trim($_POST['prompt'] ?? '');
            if ($prompt === '') throw new Exception('अपना सवाल लिखें');
            $res = AmbitionAIBrain::askModuleAdvisor($modId, $modTitle, $prompt, $currentUser);
            echo json_encode(['status' => 'ok', 'data' => $res]);
            break;

        // Switch Active LLM Provider (DeepSeek, Qwen, Gemini, Claude, OpenAI)
        case 'set_llm_provider':
            $prov = strtolower(trim($_POST['provider'] ?? 'deepseek'));
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('active_llm_provider', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$prov]);
            echo json_encode(['status' => 'ok', 'active_llm_provider' => $prov]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown API action']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}