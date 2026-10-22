<?php
// cron_engine.php - Run every 5 mins via cPanel Cron: */5 * * * * /usr/local/bin/php /home/USER/public_html/cron_engine.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whm_whmcs_sync.php';

$pdo = db();
$nowTs = time();
$todayStr = date('Y-m-d', $nowTs);
$currentHour = (int)date('G', $nowTs);

// Helper: Create deduplicated Bell Notification
function push_bell_notice(PDO $pdo, string $recipient, int $taskId, string $dedupKey, string $msg): void {
    $stmt = $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$recipient, $taskId, $dedupKey, $msg]);
}

// 1. Check Sales Leads: >30 Working Minutes SLA (9 AM - 6 PM) & +10m / +30m / +2h Price Follow-ups
$openLeads = $pdo->query("SELECT * FROM sales_leads WHERE status = 'Open'")->fetchAll();

foreach ($openLeads as $lead) {
    $leadId = (int)$lead['id'];
    $createdTs = strtotime((string)$lead['first_msg_at']);

    // A. >30 Working Minutes without Human Reply -> Alert Aarav on Telegram
    if (empty($lead['first_human_reply_at']) && (int)$lead['sla_30m_alerted'] === 0) {
        $waitWorkingMins = calc_working_minutes($createdTs, $nowTs);
        if ($waitWorkingMins > 30) {
            $pdo->prepare("UPDATE sales_leads SET sla_30m_alerted = 1 WHERE id = ?")->execute([$leadId]);
            $alertMsg = "Sales Lead #{$leadId} ({$lead['customer_identity']}) को {$waitWorkingMins} working minutes से कोई Human Reply नहीं मिला!\nAssigned: {$lead['assigned_to']}";
            send_telegram_alert($alertMsg);
            push_bell_notice($pdo, 'Aarav', 0, "sla30-lead-{$leadId}", $alertMsg);
        }
    }

    // B. Price Sent Follow-ups (+10 min, +30 min, +120 min from original price_sent_at)
    if (!empty($lead['price_sent_at']) && (int)$lead['customer_replied_after_price'] === 0) {
        $priceTs = strtotime((string)$lead['price_sent_at']);
        $elapsedMins = ($nowTs - $priceTs) / 60;

        $stages = [
            10  => ['col' => 'f10m_created',  'label' => '10 Min Follow-up'],
            30  => ['col' => 'f30m_created',  'label' => '30 Min Follow-up'],
            120 => ['col' => 'f120m_created', 'label' => '2 Hour Follow-up'],
        ];

        foreach ($stages as $mins => $meta) {
            if ($elapsedMins >= $mins && (int)$lead[$meta['col']] === 0) {
                $pdo->prepare("UPDATE sales_leads SET {$meta['col']} = 1 WHERE id = ?")->execute([$leadId]);
                $dueStr = date('Y-m-d H:i:s', $priceTs + ($mins * 60));
                $title = "Price {$meta['label']} · Lead #{$leadId} ({$lead['customer_identity']})";
                $desc  = "Customer को price भेजे {$mins} मिनट हो गए हैं। WhatsApp पर manual follow-up भेजें और प्रमाण (evidence) दर्ज करें।";

                $ins = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, lead_id, due_at) VALUES (13, ?, ?, 'Chandrika', 'followup', ?, ?)");
                $ins->execute([$title, $desc, $leadId, $dueStr]);
                $newTaskId = (int)$pdo->lastInsertId();

                push_bell_notice($pdo, 'Chandrika', $newTaskId, "price-fup-{$leadId}-{$mins}", "🔔 {$title}");
            }
        }
    }
}

// 2. Daily 10:00 AM IST Reminder for All Unresolved Tasks (Current Owner + Transferred Previous Owners)
if ($currentHour >= 10) {
    $pendingTasks = $pdo->query("SELECT * FROM tasks WHERE status = 'Pending'")->fetchAll();
    foreach ($pendingTasks as $t) {
        $tid = (int)$t['id'];
        $recipients = [$t['owner']];
        if (!empty($t['previous_owners'])) {
            foreach (explode(',', (string)$t['previous_owners']) as $prev) {
                $prev = trim($prev);
                if ($prev !== '' && !in_array($prev, $recipients, true)) {
                    $recipients[] = $prev;
                }
            }
        }
        foreach ($recipients as $person) {
            $dedupKey = "daily10am-task-{$tid}-{$person}-{$todayStr}";
            push_bell_notice($pdo, $person, $tid, $dedupKey, "⏰ Daily 10 AM Reminder: Task #{$tid} ({$t['title']}) अभी तक Pending है।");
        }
    }
}

// 3. Daily 10 Random cPanel Accounts Backup Audit Task for Deepak (Once per day after 9 AM)
if ($currentHour >= 9) {
    $auditKey = "deepak-10cpanel-audit-{$todayStr}";
    $exists = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE dedup_key = ?");
    $exists->execute([$auditKey]);
    if ((int)$exists->fetchColumn() === 0) {
        $randAccts = $pdo->query("SELECT cpanel_user, domain, disk_used_mb FROM whm_accounts WHERE is_suspended = 0 AND backup_eligible = 1 ORDER BY RAND() LIMIT 10")->fetchAll();
        if (!empty($randAccts)) {
            $listStr = implode(', ', array_map(fn($a) => "{$a['cpanel_user']} ({$a['domain']} - {$a['disk_used_mb']}MB)", $randAccts));
            $ins = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, due_at) VALUES (11, ?, ?, 'Deepak', 'backup', NOW())");
            $ins->execute([
                "Daily 10 cPanel MySQL Backup Check ({$todayStr})",
                "JetBackup में इन 10 cPanel accounts का MySQL database और files backup बना या नहीं, verify करें: " . $listStr
            ]);
            $tid = (int)$pdo->lastInsertId();
            push_bell_notice($pdo, 'Deepak', $tid, $auditKey, "🛡️ आज के 10 cPanel Accounts का MySQL Backup Check तैयार है।");
        }
    }
}

echo json_encode(['status' => 'cron_completed', 'timestamp' => date('Y-m-d H:i:s')]);