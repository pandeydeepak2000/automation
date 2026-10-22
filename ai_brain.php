<?php
// ai_brain.php - Pluggable Multi-LLM Abstraction Layer with Per-Module Live Context
declare(strict_types=1);
require_once __DIR__ . '/config.php';

class AmbitionAIBrain {

    public static function askModuleAdvisor(int $moduleId, string $moduleTitle, string $userPrompt, string $userName = 'Aarav'): array {
        $provider = self::getSetting('active_llm_provider', 'deepseek');
        $isOwner = ($userName === 'Aarav' || ($_SESSION['user_role'] ?? '') === 'Owner');
        $liveContext = self::buildLiveBusinessContext($moduleId, $moduleTitle, $userName, $isOwner);

        $teamDesc = $isOwner
            ? "Owner: Aarav. Team: Chandrika (Sales ₹15k), Priya (Domains ₹12k), Sanju (Billing/Domains ₹12k), Deepak (Support/Servers ₹25k), Nahar (L2 Server Maintenance ₹3k)."
            : "Owner: Aarav. Team: Chandrika (Sales), Priya (Domains), Sanju (Billing & Domains), Deepak (Support & Servers), Nahar (L2 Server Maintenance).";

        $systemPrompt = "You are the embedded Senior Business Strategy & Technical Operations AI Advisor inside AmbitionHost's Master Control Dashboard (Module #{$moduleId}: {$moduleTitle}).\n"
            . "Active Staff: {$userName}. {$teamDesc}\n"
            . "Always reply in Hindi Devanagari mixed naturally with English technical/business words. Use English headings only.\n"
            . "WHMCS Custom Admin Base URL for 1-click actions: " . WHMCS_ADMIN_URL . "/\n\n"
            . "=== LIVE CONTEXT FOR THIS MODULE ===\n" . $liveContext;

        $responseText = self::dispatchToLLM($provider, $systemPrompt, $userPrompt);

        // Save chat in module history
        db()->prepare("INSERT INTO ai_module_chats (module_id, user_name, llm_provider, user_prompt, ai_response) VALUES (?, ?, ?, ?, ?)")
            ->execute([$moduleId, $userName, $provider, $userPrompt, $responseText]);

        return [
            'provider' => strtoupper($provider),
            'module_id' => $moduleId,
            'response' => $responseText
        ];
    }

    private static function buildLiveBusinessContext(int $moduleId, string $moduleTitle, string $userName, bool $isOwner): string {
        $pdo = db();
        $pendingTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending'")->fetchColumn();
        $openLeads    = (int)$pdo->query("SELECT COUNT(*) FROM sales_leads WHERE status='Open'")->fetchColumn();

        if ($isOwner) {
            $unpaidPaidMismatch = (int)$pdo->query("SELECT COUNT(*) FROM payment_records WHERE whmcs_invoice_status='Unpaid'")->fetchColumn();
            $suspAccts    = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE is_suspended=1")->fetchColumn();
            $over20Gb     = (int)$pdo->query("SELECT COUNT(*) FROM whm_accounts WHERE backup_eligible=0")->fetchColumn();
            $ctx = "Global Snapshot (Owner View): Pending Tasks={$pendingTasks}, Open Sales Leads={$openLeads}, Unpaid Payment Mismatches={$unpaidPaidMismatch}, WHM Suspended Accounts={$suspAccts}, Accounts >20GB (Backup Excluded)={$over20Gb}.\n";
        } else {
            $userPending = (int)$pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND owner = ?")->execute([$userName]) ? $pdo->query("SELECT COUNT(*) FROM tasks WHERE status='Pending' AND owner = " . $pdo->quote($userName))->fetchColumn() : 0;
            $ctx = "Staff Snapshot: Assigned Pending Tasks for {$userName}={$userPending}, Open Sales Leads={$openLeads}.\n";
        }

        // Inject Module-Specific Deep Data with Sensitive Info Masking
        if (in_array($moduleId, [10, 11])) {
            $rows = $pdo->query("SELECT cpanel_user, domain, disk_used_mb, is_suspended, suspend_reason FROM whm_accounts WHERE is_suspended=1 OR backup_eligible=0 LIMIT 15")->fetchAll();
            $ctx .= "WHM Flagged Accounts (Suspended or >20GB): " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
        } elseif (in_array($moduleId, [3, 4])) {
            if ($isOwner) {
                $rows = $pdo->query("SELECT whmcs_invoice_id, amount, payment_source, sanju_verified, whmcs_invoice_status, service_delivered FROM payment_records ORDER BY id DESC LIMIT 12")->fetchAll();
                $ctx .= "Recent Payment & Delivery Verification Records: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $rows = $pdo->query("SELECT whmcs_invoice_id, payment_source, service_delivered FROM payment_records WHERE delivery_owner = " . $pdo->quote($userName) . " ORDER BY id DESC LIMIT 10")->fetchAll();
                $ctx .= "Assigned Delivery Records: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
            }
        } elseif ($moduleId === 12) {
            // Strict Privacy: Never expose registrar account logins or customer emails to LLMs
            $rows = $pdo->query("SELECT domain_name, registrar_name, registrar_expiry_date FROM domain_vault ORDER BY registrar_expiry_date ASC LIMIT 15")->fetchAll();
            $ctx .= "Domain Vault (Registrar & Expiry Only, Credentials Masked): " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            if ($isOwner) {
                $rows = $pdo->query("SELECT id, title, owner, status, due_at FROM tasks WHERE module_id=" . (int)$moduleId . " OR status='Pending' LIMIT 12")->fetchAll();
            } else {
                $tStmt = $pdo->prepare("SELECT id, title, owner, status, due_at FROM tasks WHERE (owner = ? OR module_id = ?) AND status='Pending' LIMIT 10");
                $tStmt->execute([$userName, $moduleId]);
                $rows = $tStmt->fetchAll();
            }
            $ctx .= "Relevant Tasks: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $ctx;
    }

    private static function dispatchToLLM(string $provider, string $systemPrompt, string $userPrompt): string {
        switch (strtolower($provider)) {
            case 'deepseek':
                return self::callOpenAICompatible(
                    'https://api.deepseek.com/chat/completions',
                    API_KEY_DEEPSEEK,
                    self::getSetting('llm_model_deepseek', 'deepseek-chat'),
                    $systemPrompt, $userPrompt
                );
            case 'qwen':
                return self::callOpenAICompatible(
                    'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
                    API_KEY_QWEN,
                    self::getSetting('llm_model_qwen', 'qwen-plus'),
                    $systemPrompt, $userPrompt
                );
            case 'openai':
                return self::callOpenAICompatible(
                    'https://api.openai.com/v1/chat/completions',
                    API_KEY_OPENAI,
                    self::getSetting('llm_model_openai', 'gpt-4o-mini'),
                    $systemPrompt, $userPrompt
                );
            case 'gemini':
                return self::callGemini(API_KEY_GEMINI, self::getSetting('llm_model_gemini', 'gemini-2.5-flash'), $systemPrompt, $userPrompt);
            case 'claude':
                return self::callClaude(API_KEY_CLAUDE, self::getSetting('llm_model_claude', 'claude-3-5-sonnet-latest'), $systemPrompt, $userPrompt);
            default:
                return "Active LLM provider not recognized.";
        }
    }

    private static function callOpenAICompatible(string $url, string $apiKey, string $model, string $sys, string $usr): string {
        if (empty($apiKey) || strpos($apiKey, 'YOUR_') === 0) {
            return "⚠️ [LLM Key Not Set]: कृपया Settings (Module 25) या config.php में अपनी {$model} API Key डालें।";
        }
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $usr]
            ],
            'temperature' => 0.4
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['choices'][0]['message']['content'] ?? ('API Error: ' . json_encode($res));
    }

    private static function callGemini(string $apiKey, string $model, string $sys, string $usr): string {
        if (empty($apiKey) || strpos($apiKey, 'YOUR_') === 0) {
            return "⚠️ [Gemini Key Not Set]: कृपया config.php में अपनी Google Gemini API Key डालें।";
        }
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $sys]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $usr]]]]
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['candidates'][0]['content']['parts'][0]['text'] ?? ('Gemini API Error: ' . json_encode($res));
    }

    private static function callClaude(string $apiKey, string $model, string $sys, string $usr): string {
        if (empty($apiKey) || strpos($apiKey, 'YOUR_') === 0) {
            return "⚠️ [Claude Key Not Set]: कृपया config.php में अपनी Anthropic Claude API Key डालें।";
        }
        $payload = [
            'model' => $model,
            'max_tokens' => 1500,
            'system' => $sys,
            'messages' => [['role' => 'user', 'content' => $usr]]
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['content'][0]['text'] ?? ('Claude API Error: ' . json_encode($res));
    }

    private static function getSetting(string $key, string $default = ''): string {
        $stmt = db()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : $default;
    }
}