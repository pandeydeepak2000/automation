<?php
// cron_engine.php - cPanel Cron (*/5 * * * *): 10-Min Repeating Offer Snooze Alarm + 30m SLA + 10m/30m/2h Follow-ups + 10 AM Daily Reminders
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = db();
$nowTs = time();
$todayStr = date('Y-m-d', $nowTs);
$currentHour = (int)date('G', $nowTs);

function push_bell_notice(PDO $pdo, string $recipient, int $taskId, string $dedupKey, string $msg): void {
    $pdo->prepare("INSERT IGNORE INTO notifications (recipient, task_id, dedup_key, message) VALUES (?, ?, ?, ?)")
        ->execute([$recipient, $taskId, $dedupKey, $msg]);
}

// 1. EVERY 10-MINUTE CONTINUOUS SNOOZE ALARM FOR UNREAD OFFER / URGENT TASKS (Chandrika & Deepak)
$urgentOfferTasks = $pdo->query("SELECT * FROM tasks WHERE status = 'Pending' AND task_kind = 'urgent'")->fetchAll();
foreach ($urgentOfferTasks as $ut) {
    $tid = (int)$ut['id'];
    $owner = (string)$ut['owner'];

    // Check if the owner has read ANY notification for this urgent/offer task
    $readCheck = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE task_id = ? AND recipient = ? AND is_read = 1");
    $readCheck->execute([$tid, $owner]);
    $hasRead = ((int)$readCheck->fetchColumn() > 0);

    if (!$hasRead) {
        $elapsedMins = (int)floor(($nowTs - strtotime((string)$ut['created_at'])) / 60);
        if ($elapsedMins >= 10) {
            $tenMinBucket = (int)floor($elapsedMins / 10); // Fires at 10m, 20m, 30m, 40m, 50m... continuously until read!
            $snoozeKey = "snooze10m-task-{$tid}-{$owner}-slot-{$tenMinBucket}";
            $snoozeMins = $tenMinBucket * 10;
            push_bell_notice(
                $pdo,
                $owner,
                $tid,
                $snoozeKey,
                "⏰ [10-MIN SNOOZE ALARM · {$snoozeMins}m Unread]: Admin का Offer / Urgent Action (#{$tid}: {$ut['title']}) आपने अभी तक Read नहीं किया है! तुरंत देखें!"
            );
        }
    }
}

// 2. Sales Leads: >30 Working Minutes SLA Breach (Telegram to Aarav) & +10m / +30m / +2h Price Follow-ups
$openLeads = $pdo->query("SELECT * FROM sales_leads WHERE status = 'Open'")->fetchAll();
foreach ($openLeads as $lead) {
    $leadId = (int)$lead['id'];
    $createdTs = strtotime((string)$lead['first_msg_at']);

    if (empty($lead['first_human_reply_at']) && (int)$lead['sla_30m_alerted'] === 0) {
        $waitWorkingMins = calc_working_minutes($createdTs, $nowTs);
        if ($waitWorkingMins > 30) {
            $pdo->prepare("UPDATE sales_leads SET sla_30m_alerted = 1 WHERE id = ?")->execute([$leadId]);
            $msg = "Sales Lead #{$leadId} ({$lead['customer_identity']}) को {$waitWorkingMins} working mins से कोई Human Reply नहीं मिला!";
            send_telegram_alert($msg);
            push_bell_notice($pdo, 'Aarav', 0, "sla30-lead-{$leadId}", "🚨 " . $msg);
        }
    }

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
                $title = "Price {$meta['label']} · Lead #{$leadId} ({$lead['customer_identity']})";
                $ins = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, lead_id, due_at) VALUES (13, ?, ?, 'Chandrika', 'followup', ?, NOW())");
                $ins->execute([$title, "Price भेजे {$mins} मिनट हो गए हैं। WhatsApp पर manual follow-up भेजें।", $leadId]);
                $newTid = (int)$pdo->lastInsertId();
                push_bell_notice($pdo, 'Chandrika', $newTid, "price-fup-{$leadId}-{$mins}", "🔔 {$title}");
            }
        }
    }
}

// 3. Daily 10:00 AM IST Reminder for All Unresolved Tasks
if ($currentHour >= 10) {
    foreach ($pdo->query("SELECT * FROM tasks WHERE status = 'Pending'") as $t) {
        $tid = (int)$t['id'];
        $recips = array_unique(array_filter(array_merge([$t['owner']], array_map('trim', explode(',', (string)$t['previous_owners'])))));
        foreach ($recips as $p) {
            push_bell_notice($pdo, $p, $tid, "daily10am-{$tid}-{$p}-{$todayStr}", "⏰ Daily 10 AM Reminder: Task #{$tid} ({$t['title']}) अभी भी Pending है।");
        }
    }
}

echo json_encode(['status' => 'cron_ok', 'time' => date('Y-m-d H:i:s')]);