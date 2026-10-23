<?php
// ai_brain.php - Dual Multi-LLM Engine: 1) Per-Module Scoped AI  2) 360° Global Brainstorm Advisor (Admin Only) + Customer Chatbot Brain
declare(strict_types=1);
require_once __DIR__ . '/config.php';

class AmbitionAIBrain {

    // Core 12 Operational & Customer Rules Extracted from Aarav's 28+ Real PDFs
    public const AMBITIONHOST_CORE_RULES = <<<RULES
[AMBITIONHOST VERIFIED OPERATIONAL RULES]:
1. Unlimited Shared Hosting Plans (Seed ₹348/yr, Grow ₹528/yr, Connect ₹900/yr, Apex ₹2100/yr): Email service (0/0 limit) and automatic cPanel backups are NOT included. For Email or Auto-Backup, customer must upgrade to Premium Shared (Starter ₹59/mo=1 email, Basic/Plus/Pro=Unlimited emails) via prorated difference, or Cloud Hosting (Power/Advanced/Ultra).
2. ₹299 .IN Promo Offer: Requires fresh Aadhaar details (Full Name, Complete Address with PIN, Aadhaar-linked Mobile Number for DigiLocker OTP) + verification link click. Never register 18+/adult/sex/dating names on .in/.co.in (NIXI blocks them; suggest .com, .net, .live, .online).
3. Bulk .COM Pricing: 1-4 domains = ₹749 (Regular client ₹699); 5-19 bulk .com = ₹599/domain; 20+ bulk .com = ₹571/domain (Zero-GST via direct UPI on Sales WhatsApp +91 7688855600).
4. Domain Unavailable / Redemption: If a paid domain fails registration, offer replacement domain, renewal credit adjustment, or source refund. If an expired domain is in Redemption Period, normal renewal is not possible (takes 75-85 days after expiry to release).
5. Hostinger "Already Registered" Error: Give customer the exact message to ask Hostinger Support for an Ownership Verification TXT/A record, then add it to DNS.
6. SSL: Free Let's Encrypt AutoSSL works via cPanel -> SSL/TLS Status -> Wizard -> Tick Checkbox -> Continue. Wildcard SSL (*.domain.com) is not supported on shared plans.
7. Firewall IP Block & Vi SIM: If cPanel fails on one network, ask for IPv4 from whatismyipaddress.com to whitelist; if blocked on Vi SIM, migrate server IP.
8. Node.js 100 NPROC & Socket.IO: Shared/Cloud plans cap at 100 processes (NPROC) and do NOT support persistent WebSockets (Socket.IO) -> recommend NVMe VPS.
9. SGP Server JetBackup Rule: Daily backup with 30-day retention covers accounts ≤20 GB only. Accounts >18-20 GB must be pitched the ₹199/mo Extended Backup Add-on.
10. Working Hours: 9:00 AM to 6:00 PM IST (All 7 Days). Sales: +91 7688855600, Support: +91 8696518872.
RULES;

    /**
     * 1. Per-Module Scoped AI Advisor (Inside Modules 1 to 25 — Role-Filtered & Privacy-Masked)
     */
    public static function askModuleAdvisor(int $moduleId, string $moduleTitle, string $userPrompt, array $staffUser): array {
        $provider = get_sys_setting('active_llm_provider', 'deepseek');
        $scopedContext = self::buildScopedModuleContext($moduleId, $staffUser);

        $sys = "You are the Scoped AI Advisor for Module #{$moduleId} ({$moduleTitle}) inside AmbitionHost Master OS.\n"
             . "Current Staff User: {$staffUser['display_title']} (Role: {$staffUser['role']}, Scope: {$staffUser['department']}).\n"
             . "Always reply in Hindi Devanagari mixed naturally with English technical/business words. Use English headings only.\n"
             . self::AMBITIONHOST_CORE_RULES . "\n\n"
             . "=== LIVE SCOPED MODULE DATA (PRIVACY MASKED) ===\n" . $scopedContext;

        $reply = self::dispatchToLLM($provider, $sys, $userPrompt);

        db()->prepare("INSERT INTO ai_module_chats (module_id, user_name, llm_provider, user_prompt, ai_response) VALUES (?, ?, ?, ?, ?)")
            ->execute([$moduleId, $staffUser['display_title'], $provider, $userPrompt, $reply]);

        return ['provider' => strtoupper($provider), 'module_id' => $moduleId, 'response' => $reply];
    }

    /**
     * 2. 🧠 360° Global Brainstorm & Business Advisor (EXCLUSIVE TO AARAV ADMIN — Full 25-Module & Activity Log Control)
     */
    public static function askGlobalBrainstorm(string $userPrompt, array $staffUser): array {
        if (($staffUser['role'] ?? '') !== 'Admin' && ($staffUser['name'] ?? '') !== 'Aarav') {
            return ['provider' => 'SECURITY', 'module_id' => 0, 'response' => '🔒 Access Denied: Global Brainstorm AI सिर्फ Aarav (Admin) के लिए है।'];
        }
        $provider = get_sys_setting('active_llm_provider', 'deepseek');
        $globalContext = self::build360GlobalContext();

        $sys = "You are the 360° Master Brainstorm & Executive Business Advisor exclusively for Aarav (Admin) of AmbitionHost.\n"
             . "You have complete visibility across ALL 25 Modules, 7-Day/30-Day Staff Performance (Deepak B.D.E., Chandrika Sales, Priya Domain, Sanju Billing), Full Activity Audit Logs, WHMCS Domain/Hosting Trends, WHM Servers, and Financials (₹64,000 payroll + ₹35,550 server costs).\n"
             . "Always reply in Hindi Devanagari mixed with English words. Use English headings only.\n"
             . self::AMBITIONHOST_CORE_RULES . "\n\n"
             . "=== COMPLETE 360° ENTERPRISE DATA & ACTIVITY LOGS ===\n" . $globalContext;

        $reply = self::dispatchToLLM($provider, $sys, $userPrompt);

        db()->prepare("INSERT INTO ai_module_chats (module_id, user_name, llm_provider, user_prompt, ai_response) VALUES (0, 'Aarav (Admin)', ?, ?, ?)")
            ->execute([$provider, $userPrompt, $reply]);

        return ['provider' => strtoupper($provider), 'module_id' => 0, 'response' => $reply];
    }

    private static function buildScopedModuleContext(int $moduleId, array $staffUser): string {
        $pdo = db();
        $isAdmin = ($staffUser['role'] === 'Admin' || $staffUser['name'] === 'Aarav');

        // Mask PII/Login emails before sending to external LLM
        $tasksStmt = $pdo->prepare("SELECT id, title, owner, status, due_at FROM tasks WHERE module_id = ? ORDER BY id DESC LIMIT 12");
        $tasksStmt->execute([$moduleId]);
        $ctx = "Module #{$moduleId} Tasks: " . json_encode($tasksStmt->fetchAll(), JSON_UNESCAPED_UNICODE) . "\n";

        if (in_array($moduleId, [10, 11])) {
            $accts = $pdo->query("SELECT cpanel_user, domain, disk_used_mb, is_suspended, suspend_reason, backup_eligible FROM whm_accounts WHERE is_suspended=1 OR backup_eligible=0 LIMIT 15")->fetchAll();
            $ctx .= "WHM Flagged Accounts: " . json_encode($accts, JSON_UNESCAPED_UNICODE) . "\n";
        } elseif ($moduleId === 12) {
            // Privacy Rule: Never send account_email_or_id to external LLM!
            $doms = $pdo->query("SELECT domain_name, registrar_name, registrar_expiry_date, whois_status FROM domain_vault ORDER BY registrar_expiry_date ASC LIMIT 15")->fetchAll();
            $ctx .= "Domain Vault Summary (Credentials Masked): " . json_encode($doms, JSON_UNESCAPED_UNICODE) . "\n";
        } elseif (in_array($moduleId, [21, 22]) && $isAdmin) {
            $ctx .= "Monthly Payroll: ₹64,000 (Deepak ₹25k, Chandrika ₹15k, Priya ₹12k, Sanju ₹12k) + Server Costs ~₹35,550/mo.\n";
        }
        return $ctx;
    }

    private static function build360GlobalContext(): string {
        $pdo = db();
        $staffStats = $pdo->query("SELECT owner, status, COUNT(*) as cnt FROM tasks GROUP BY owner, status")->fetchAll();
        $recentLogs = $pdo->query("SELECT actor, module_id, event_summary, created_at FROM activity_logs ORDER BY id DESC LIMIT 25")->fetchAll();
        $whmSummary = $pdo->query("SELECT server_name, total_accounts, active_accounts, suspended_accounts, load_avg FROM whm_servers")->fetchAll();
        $whmcsCounts = $pdo->query("SELECT item_type, status, COUNT(*) as cnt FROM whmcs_items_cache GROUP BY item_type, status")->fetchAll();
        $unverifiedPay = (int)$pdo->query("SELECT COUNT(*) FROM payment_records WHERE sanju_verified=0 OR service_delivered=0")->fetchColumn();
        $openTickets = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets_local WHERE status IN ('Open','Customer-Reply')")->fetchColumn();

        return "1. Staff Task Performance Across All Modules: " . json_encode($staffStats, JSON_UNESCAPED_UNICODE) . "\n"
             . "2. WHM Servers Health: " . json_encode($whmSummary, JSON_UNESCAPED_UNICODE) . "\n"
             . "3. WHMCS Hosting vs Domain Counts: " . json_encode($whmcsCounts, JSON_UNESCAPED_UNICODE) . "\n"
             . "4. Pending Payments/Deliveries: {$unverifiedPay} | Open Local Support Tickets: {$openTickets}\n"
             . "5. Latest 25 System Activity Audit Logs: " . json_encode($recentLogs, JSON_UNESCAPED_UNICODE) . "\n";
    }

    public static function dispatchToLLM(string $provider, string $sys, string $usr): string {
        switch (strtolower($provider)) {
            case 'deepseek':
                return self::callOpenAIStyle('https://api.deepseek.com/chat/completions', get_sys_setting('api_key_deepseek', API_KEY_DEEPSEEK), get_sys_setting('llm_model_deepseek', 'deepseek-chat'), $sys, $usr);
            case 'qwen':
                return self::callOpenAIStyle('https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions', get_sys_setting('api_key_qwen', API_KEY_QWEN), get_sys_setting('llm_model_qwen', 'qwen-plus'), $sys, $usr);
            case 'openai':
                return self::callOpenAIStyle('https://api.openai.com/v1/chat/completions', get_sys_setting('api_key_openai', API_KEY_OPENAI), get_sys_setting('llm_model_openai', 'gpt-4o-mini'), $sys, $usr);
            case 'gemini':
                return self::callGemini(get_sys_setting('api_key_gemini', API_KEY_GEMINI), get_sys_setting('llm_model_gemini', 'gemini-2.5-flash'), $sys, $usr);
            case 'claude':
                return self::callClaude(get_sys_setting('api_key_claude', API_KEY_CLAUDE), get_sys_setting('llm_model_claude', 'claude-3-5-sonnet-latest'), $sys, $usr);
            default:
                return "Unsupported LLM provider.";
        }
    }

    private static function callOpenAIStyle(string $url, string $key, string $model, string $sys, string $usr): string {
        if (empty($key) || strpos($key, 'YOUR_') === 0) {
            return "⚠️ [API Key Missing]: कृपया Module 25 (Integrations & Multi-LLM) में जाकर अपनी {$model} API Key सेव करें।";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => [['role'=>'system','content'=>$sys],['role'=>'user','content'=>$usr]], 'temperature' => 0.3])
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['choices'][0]['message']['content'] ?? ('API Error: ' . json_encode($res));
    }

    private static function callGemini(string $key, string $model, string $sys, string $usr): string {
        if (empty($key) || strpos($key, 'YOUR_') === 0) return "⚠️ [Gemini Key Missing]: कृपया Module 25 में Gemini API Key डालें।";
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['systemInstruction'=>['parts'=>[['text'=>$sys]]], 'contents'=>[['role'=>'user','parts'=>[['text'=>$usr]]]]])
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['candidates'][0]['content']['parts'][0]['text'] ?? ('Gemini Error: ' . json_encode($res));
    }

    private static function callClaude(string $key, string $model, string $sys, string $usr): string {
        if (empty($key) || strpos($key, 'YOUR_') === 0) return "⚠️ [Claude Key Missing]: कृपया Module 25 में Claude API Key डालें।";
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode(['model'=>$model, 'max_tokens'=>1500, 'system'=>$sys, 'messages'=>[['role'=>'user','content'=>$usr]]])
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $res['content'][0]['text'] ?? ('Claude Error: ' . json_encode($res));
    }
}