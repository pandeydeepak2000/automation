<?php
// rate.php - 100% Trustpilot Compliant Customer Feedback & Direct Escalation Form
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$client = htmlspecialchars($_GET['client'] ?? 'Valued Customer');
$submitted = false;
$submittedStars = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stars = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $name  = trim($_POST['client_name'] ?? $client);
    $issue = trim($_POST['feedback'] ?? '');
    $submittedStars = $stars;

    // Log all feedback (1 to 5 stars) directly into dashboard tasks
    $pdo = db();
    if ($stars <= 3) {
        $stmt = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, due_at) VALUES (20, ?, ?, 'Aarav', 'support', NOW())");
        $stmt->execute([
            "⚠️ Customer Escalation ({$stars}★) · {$name}",
            "Customer Feedback: {$issue}"
        ]);
        send_telegram_alert("🛡️ *Support Feedback Alert ({$stars}★)*\nCustomer: {$name}\nFeedback: {$issue}");
    } else {
        $stmt = $pdo->prepare("INSERT INTO tasks (module_id, title, description, owner, task_kind, due_at) VALUES (20, ?, ?, 'Chandrika', 'support', NOW())");
        $stmt->execute([
            "🌟 Positive Feedback ({$stars}★) · {$name}",
            "Customer Feedback: {$issue}"
        ]);
    }
    $submitted = true;
}
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Customer Feedback</title>
<style>
  body{font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;background:#f1f5f9;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px;color:#0f172a}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;max-width:500px;width:100%;padding:32px 28px;box-shadow:0 12px 32px rgba(15,23,42,.08);text-align:center;box-sizing:border-box}
  .stars{display:flex;justify-content:center;gap:10px;margin:20px 0}
  .stars button{font-size:26px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:10px 14px;cursor:pointer;transition:all .15s}
  .stars button.active{background:#2563eb;color:#fff;border-color:#2563eb}
  input,textarea{width:100%;padding:11px 12px;margin:8px 0;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font:inherit}
  button.sub{width:100%;padding:13px;background:#2563eb;color:#fff;border:0;border-radius:8px;font-weight:700;font-size:15px;cursor:pointer;margin-top:12px}
  button.sub:hover{background:#1d4ed8}
  .trustpilot-box{margin-top:24px;padding:16px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;font-size:13px;line-height:1.6}
  .trustpilot-link{display:inline-block;margin-top:8px;padding:8px 16px;background:#00b67a;color:#fff;text-decoration:none;border-radius:6px;font-weight:700}
</style>
</head>
<body>
<div class="card">
<?php if ($submitted): ?>
  <div style="font-size:48px;margin-bottom:12px">✅</div>
  <h2 style="margin:0 0 8px">धन्यवाद, आपकी प्रतिक्रिया दर्ज कर ली गई है!</h2>
  <p style="color:#64748b;font-size:14px;line-height:1.6">
    आपकी रेटिंग (<?=$submittedStars?>★) और सुझाव हमारी टीम और मैनेजमेंट तक सीधे पहुँच गए हैं।
    <?php if ($submittedStars <= 3): ?>
      हमारी सीनियर सपोर्ट टीम जल्द से जल्द आपसे संपर्क कर समस्या का समाधान करेगी।
    <?php endif; ?>
  </p>

  <div class="trustpilot-box">
    <strong>सार्वजनिक समीक्षा (Public Review):</strong><br>
    यदि आप चाहें, तो Trustpilot पर भी निष्पक्ष रूप से अपना सार्वजनिक अनुभव साझा कर सकते हैं:
    <div>
      <a href="https://www.trustpilot.com/evaluate/ambitionhost.com" target="_blank" rel="noopener noreferrer" class="trustpilot-link">
        Review on Trustpilot ★ ↗
      </a>
    </div>
  </div>
<?php else: ?>
  <h2 style="margin:0 0 6px">AmbitionHost Support & Service Feedback</h2>
  <p style="color:#64748b;font-size:13px;margin:0 0 16px">आपकी राय हमारे लिए अत्यंत महत्वपूर्ण है। कृपया अपना सच्चा अनुभव साझा करें:</p>
  
  <form method="post">
    <input type="hidden" name="stars" id="starsInput" value="5">
    <div class="stars" id="starBox">
      <?php for($i=1;$i<=5;$i++): ?>
        <button type="button" data-v="<?=$i?>" class="<?=$i===5?'active':''?>"><?=$i?>★</button>
      <?php endfor; ?>
    </div>
    <input name="client_name" value="<?=$client?>" placeholder="आपका नाम / Domain Name" required>
    <textarea name="feedback" rows="4" placeholder="अपना सुझाव या समस्या यहाँ लिखें..." required></textarea>
    <button type="submit" class="sub" id="subBtn">Submit Feedback →</button>
  </form>

  <div class="trustpilot-box" style="margin-top:20px">
    <small style="color:#64748b">
      Trustpilot पर समीक्षा देने के लिए आप किसी भी समय 
      <a href="https://www.trustpilot.com/evaluate/ambitionhost.com" target="_blank" rel="noopener noreferrer" style="color:#00b67a;font-weight:700">यहाँ क्लिक कर सकते हैं ↗</a>
    </small>
  </div>

  <script>
    document.querySelectorAll('#starBox button').forEach(b => {
      b.onclick = () => {
        const v = Number(b.dataset.v);
        document.getElementById('starsInput').value = v;
        document.querySelectorAll('#starBox button').forEach(x => {
          x.classList.toggle('active', Number(x.dataset.v) <= v);
        });
      };
    });
  </script>
<?php endif; ?>
</div>
</body>
</html>