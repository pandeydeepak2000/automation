# AmbitionHost Master Control System (control.jixsite.com)

Production Dashboard & Control Center for AmbitionHost Operations, WHM 14-Privileges Live Sync, Per-Module Multi-LLM AI Brain, Sales SLA Tracking, and WHMCS Deep-Links.

---

## 📁 Production Architecture Files

1. `auth.php` - **Mandatory Password Authentication & CSRF Guard** for all pages and APIs (`password_verify`, session security, department restrictions).
2. `install.sql` - Complete MySQL Schema (12 tables including `whmcs_items_cache` & `whmcs_daily_snapshots` + 5 Team user profiles for Aarav, Chandrika, Priya, Sanju, Deepak).
3. `config.php` - Database credentials, WHMCS custom admin deep-link generator (`/user/devokedev/`), API tokens & Telegram alert helper.
4. `whm_whmcs_sync.php` - 14-Privilege WHM API Live Sync with strict SSL verification (`CURLOPT_SSL_VERIFYPEER => true`), result error guard, and WHMCS Read-Only API engine.
5. `analytics_engine.php` - WHMCS Analytics & Normalized MRR Intelligence (Monthly/Quarterly/Annual normalization, 12-month cohort retention, exact 7-day & fair MTD comparisons, POST-only sync).
6. `ai_brain.php` - Multi-LLM provider abstraction (DeepSeek, Qwen, Gemini, Claude, ChatGPT) with sensitive data masking (domain logins, emails protected) and role-filtered context.
7. `cron_engine.php` - SLA & reminder cron (run every 5 mins via cPanel: `*/5 * * * *`).
8. `api.php` - Unified AJAX controller with secure uploads (5MB max, MIME validation, `getimagesize()`), WhatsApp .txt domain vault parser & CA GST CSV export.
9. `rate.php` - **100% Trustpilot Compliant** customer support feedback & escalation form (no review gating, no incentives, direct management escalation + neutral Trustpilot link).
10. `index.php` - 25-Module live dashboard UI with authenticated department access control, top 5 priority queues, WHMCS `/user/devokedev/` 1-click edit buttons, and embedded AI advisors.
11. `uploads/.htaccess` - Blocks script execution (`php_flag engine off`, `Options -ExecCGI -Indexes`, all scripts denied).


---

## 🚀 1-Click Deployment on cPanel Terminal

Run this single command in your cPanel Terminal inside the Document Root folder of `control.jixsite.com`:

```bash
cd /home/YOUR_CPANEL_USER/control.jixsite.com && \
git clone https://github.com/pandeydeepak2000/automation.git temp_repo && \
cp -r temp_repo/* . && \
cp -r temp_repo/.[!.]* . 2>/dev/null || true && \
rm -rf temp_repo && \
mkdir -p uploads && \
chmod 0755 uploads && \
echo "Deployment completed successfully!"
```

---

## ⚙️ Post-Deployment Setup Steps

1. **MySQL Database Import**:
   - In cPanel -> **MySQL Databases**, create a new database & user (grant ALL privileges).
   - In **phpMyAdmin**, select the database and click **Import** -> select `install.sql`.

2. **Update `config.php`**:
   - Set `DB_NAME`, `DB_USER`, `DB_PASS`.
   - Set `WHMCS_API_IDENTIFIER`, `WHMCS_API_SECRET` and whitelist the cPanel server IP in WHMCS Admin (`/user/devokedev/` -> Setup -> Staff Management -> Manage API Credentials).
   - Set `TELEGRAM_BOT_TOKEN`, `AARAV_TELEGRAM_CHAT_ID`.
   - Set LLM API Key (`API_KEY_DEEPSEEK` / `API_KEY_QWEN` / `API_KEY_GEMINI`).

3. **Set cPanel Cron Job**:
   - Interval: `*/5 * * * *` (Every 5 minutes)
   - Command:
     ```bash
     /usr/local/bin/php /home/YOUR_CPANEL_USER/control.jixsite.com/cron_engine.php
     ```

4. **Verify**:
   - Open `https://control.jixsite.com/index.php`.
   - Module 10: Connect & Sync WHM server (Hostname, root, API Token).
   - Test AI Advisor chat in any module.
   - Test WHMCS 1-Click buttons (`/user/devokedev/`).
