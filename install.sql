-- AmbitionHost Master OS - Production Database Schema
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- 1. Team Users & Department Access
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_title` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Owner','Sales','Domain','Billing','Billing_Domain','Support_Tech','B.D.E.') NOT NULL,
  `department` varchar(50) NOT NULL,
  `allowed_modules` varchar(255) DEFAULT '1',
  `can_graphs` tinyint(1) DEFAULT 0,
  `monthly_salary` decimal(10,2) DEFAULT 0.00,
  `can_refund` tinyint(1) DEFAULT 0,
  `can_discount` tinyint(1) DEFAULT 0,
  `max_discount_pct` decimal(5,2) DEFAULT 0.00,
  `telegram_chat_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Your Exact Team & Payroll Rules (Default Password for all: Ambition@2026 - Change after login)
INSERT INTO `users` (`name`, `display_title`, `email`, `password_hash`, `role`, `department`, `allowed_modules`, `can_graphs`, `monthly_salary`, `can_refund`, `can_discount`, `max_discount_pct`) VALUES
('Aarav', 'Aarav (Admin)', 'aarav@ambitionhost.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'All', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25', 1, 0.00, 1, 1, 100.00),
('Deepak', 'Deepak (B.D.E.)', 'deepak@ambitionhost.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'B.D.E.', 'Support & Servers', '1,2,7,8,10,11,18,19,23,24', 1, 25000.00, 0, 0, 0.00),
('Chandrika', 'Chandrika (Sales)', 'chandrika@ambitionhost.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sales', 'Sales', '1,2,3,5,6,7,8,13,14,20,23', 0, 15000.00, 0, 1, 19.00),
('Priya', 'Priya (Domain)', 'priya@ambitionhost.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Domain', 'Domains', '1,3,5,7,12,23', 0, 12000.00, 0, 0, 0.00),
('Sanju', 'Sanju (Billing)', 'sanju@ambitionhost.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Billing', 'Billing & Domains', '1,3,4,5,12,13,23', 0, 12000.00, 0, 1, 10.00)
ON DUPLICATE KEY UPDATE `display_title`=VALUES(`display_title`), `role`=VALUES(`role`), `allowed_modules`=VALUES(`allowed_modules`), `can_graphs`=VALUES(`can_graphs`), `monthly_salary`=VALUES(`monthly_salary`);

-- 1B. Global Activity Audit Logs (For 360° Brainstorm AI & Audit)
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor` varchar(100) NOT NULL,
  `module_id` int(11) NOT NULL DEFAULT 0,
  `event_summary` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `module_actor` (`module_id`, `actor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1C. Website Chat Widget & Local Support Tickets
CREATE TABLE IF NOT EXISTS `chat_customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `reset_otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_live_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_email` varchar(120) NOT NULL,
  `sender` enum('Customer','AI','Staff') NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_email` (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `support_tickets_local` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(50) NOT NULL,
  `customer_email` varchar(120) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('Open','Answered','Customer-Reply','Closed') DEFAULT 'Open',
  `assigned_to` varchar(50) DEFAULT 'Support',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_code` (`ticket_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_messages_local` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `sender_type` enum('Customer','Staff') NOT NULL,
  `sender_name` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tasks & Accountability Queue (With Read-Only for Others & Transfer History)
CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` int(11) NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `description` text,
  `owner` varchar(50) NOT NULL,
  `previous_owners` text DEFAULT NULL,
  `granted_users` text DEFAULT NULL,
  `task_kind` enum('urgent','followup','support','renewal','backup','domain','billing') DEFAULT 'urgent',
  `lead_id` int(11) DEFAULT NULL,
  `whmcs_link` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Resolved','Cancelled') DEFAULT 'Pending',
  `resolution_evidence` text DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_owner` (`status`, `owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Task Notes & Audit Timeline
CREATE TABLE IF NOT EXISTS `task_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `actor` varchar(50) NOT NULL,
  `event_type` enum('note','status','transfer','grant','system') DEFAULT 'system',
  `message` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `task_id` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Sales CRM Leads (30-Min SLA & 10m/30m/2h Follow-ups)
CREATE TABLE IF NOT EXISTS `sales_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_id` varchar(100) NOT NULL,
  `customer_identity` varchar(190) DEFAULT 'Unconfirmed Lead',
  `phone_or_email` varchar(150) DEFAULT NULL,
  `channel` enum('Sales_WhatsApp','Support_WhatsApp','Website','Ticket') DEFAULT 'Sales_WhatsApp',
  `assigned_to` varchar(50) DEFAULT 'Chandrika',
  `first_msg_at` datetime NOT NULL,
  `first_human_reply_at` datetime DEFAULT NULL,
  `price_sent_at` datetime DEFAULT NULL,
  `customer_replied_after_price` tinyint(1) DEFAULT 0,
  `sla_30m_alerted` tinyint(1) DEFAULT 0,
  `f10m_created` tinyint(1) DEFAULT 0,
  `f30m_created` tinyint(1) DEFAULT 0,
  `f120m_created` tinyint(1) DEFAULT 0,
  `status` enum('Open','Won','Lost') DEFAULT 'Open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `thread_id` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Bell Notifications & Daily 10 AM Reminder Deduplication
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(50) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `dedup_key` varchar(150) NOT NULL,
  `message` varchar(500) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dedup_key` (`dedup_key`),
  KEY `recipient_read` (`recipient`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. WHM Servers & Live Account Cache (Strictly for Your 14 Ticked Privileges)
CREATE TABLE IF NOT EXISTS `whm_servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_name` varchar(100) NOT NULL,
  `provider` varchar(100) NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `whm_username` varchar(100) DEFAULT 'root',
  `api_token` text NOT NULL,
  `monthly_cost_inr` decimal(10,2) DEFAULT 0.00,
  `max_backup_gb` int(11) DEFAULT 20,
  `total_accounts` int(11) DEFAULT 0,
  `active_accounts` int(11) DEFAULT 0,
  `suspended_accounts` int(11) DEFAULT 0,
  `load_avg` varchar(50) DEFAULT 'N/A',
  `disk_used_pct` varchar(20) DEFAULT 'N/A',
  `service_status_json` text DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. WHM cPanel Accounts Cache (Suspended Reasons, 20GB Backup Alert, SSL & Bandwidth)
CREATE TABLE IF NOT EXISTS `whm_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `cpanel_user` varchar(64) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `owner_email` varchar(150) DEFAULT NULL,
  `plan_name` varchar(100) DEFAULT NULL,
  `disk_used_mb` decimal(10,2) DEFAULT 0.00,
  `disk_limit_mb` varchar(50) DEFAULT NULL,
  `bw_used_mb` decimal(10,2) DEFAULT 0.00,
  `is_suspended` tinyint(1) DEFAULT 0,
  `suspend_reason` varchar(255) DEFAULT NULL,
  `suspend_time` datetime DEFAULT NULL,
  `backup_eligible` tinyint(1) DEFAULT 1,
  `last_backup_check_date` date DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_user` (`server_id`, `cpanel_user`),
  KEY `is_suspended` (`is_suspended`),
  KEY `disk_used_mb` (`disk_used_mb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Master Domain Vault (For 26 Registrars & ~2,000 Accounts + WhatsApp .txt Import)
CREATE TABLE IF NOT EXISTS `domain_vault` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_name` varchar(255) NOT NULL,
  `registrar_name` varchar(100) NOT NULL,
  `account_email_or_id` varchar(190) NOT NULL,
  `account_holder_name` varchar(100) DEFAULT NULL,
  `whmcs_domain_id` int(11) DEFAULT NULL,
  `customer_due_date` date DEFAULT NULL,
  `registrar_expiry_date` date DEFAULT NULL,
  `current_nameservers` varchar(500) DEFAULT NULL,
  `whois_status` varchar(100) DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_name` (`domain_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Payment Reconciliation & Direct UPI Screenshot Proofs
CREATE TABLE IF NOT EXISTS `payment_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `whmcs_invoice_id` int(11) NOT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_email` varchar(150) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_source` enum('Razorpay','WhatsApp_UPI','Bank_Transfer') DEFAULT 'Razorpay',
  `payment_id_or_utr` varchar(120) DEFAULT NULL,
  `proof_file_url` varchar(500) DEFAULT NULL,
  `sanju_verified` tinyint(1) DEFAULT 0,
  `whmcs_invoice_status` enum('Paid','Unpaid','Refund_Requested','Refunded') DEFAULT 'Unpaid',
  `service_delivered` tinyint(1) DEFAULT 0,
  `delivery_owner` varchar(50) DEFAULT 'Chandrika',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `whmcs_invoice_id` (`whmcs_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Per-Module AI Advisor Chat History & System Settings
CREATE TABLE IF NOT EXISTS `ai_module_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` int(11) NOT NULL,
  `user_name` varchar(50) NOT NULL,
  `llm_provider` varchar(50) NOT NULL,
  `user_prompt` text NOT NULL,
  `ai_response` mediumtext NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `module_id` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('active_llm_provider', 'deepseek'),
('llm_model_deepseek', 'deepseek-chat'),
('llm_model_qwen', 'qwen-plus'),
('llm_model_gemini', 'gemini-2.5-flash'),
('llm_model_openai', 'gpt-4o-mini'),
('llm_model_claude', 'claude-3-5-sonnet-latest'),
('whmcs_base_url', 'https://www.ambitionhost.in/user'),
('whmcs_admin_folder', 'devokedev')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- 11. WHMCS Services & Domains Live Cache for Analytics Engine
CREATE TABLE IF NOT EXISTS `whmcs_items_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_type` enum('hosting','domain') NOT NULL DEFAULT 'hosting',
  `whmcs_item_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL DEFAULT 0,
  `domain` varchar(255) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `client_email` varchar(150) NOT NULL DEFAULT '',
  `client_name` varchar(150) NOT NULL DEFAULT '',
  `billing_cycle` varchar(50) DEFAULT 'Monthly',
  `recurring_amount` decimal(10,2) DEFAULT 0.00,
  `status` varchar(50) DEFAULT 'Active',
  `reg_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_unique` (`item_type`, `whmcs_item_id`),
  KEY `status` (`status`),
  KEY `reg_date` (`reg_date`),
  KEY `billing_cycle` (`billing_cycle`),
  KEY `email_idx` (`client_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. WHMCS Daily Historical Snapshots
CREATE TABLE IF NOT EXISTS `whmcs_daily_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `total_clients` int(11) DEFAULT 0,
  `active_services` int(11) DEFAULT 0,
  `active_domains` int(11) DEFAULT 0,
  `mrr_inr` decimal(12,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `snapshot_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;