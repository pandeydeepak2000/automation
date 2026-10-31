<?php
/**
 * AmbitionHost - 0-Second Instant Telegram Order Alert Hook for WHMCS
 * ---------------------------------------------------------------------
 * INSTALLATION:
 * 1. Upload this file to your WHMCS server at:
 *    /home/oxydomain/public_html/user/includes/hooks/ambition_order_telegram_hook.php
 *    (or your WHMCS path: /includes/hooks/ambition_order_telegram_hook.php)
 * 
 * 2. Whenever any client orders or pays, WHMCS executes this hook instantly (0-second delay),
 *    triggering real-time Telegram alerts to Aarav and the delivery team!
 * ---------------------------------------------------------------------
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function ambition_send_order_to_master_os(array $vars, string $hookName): void {
    try {
        $orderId = 0;
        if (!empty($vars['orderid'])) {
            $orderId = (int)$vars['orderid'];
        } elseif (!empty($vars['orderId'])) {
            $orderId = (int)$vars['orderId'];
        } elseif (!empty($vars['invoiceid'])) {
            // Find order by invoice ID if possible
            if (class_exists('\WHMCS\Database\Capsule')) {
                $ord = \WHMCS\Database\Capsule::table('tblorders')->where('invoiceid', (int)$vars['invoiceid'])->value('id');
                if ($ord) $orderId = (int)$ord;
            }
        }

        if ($orderId <= 0) return;

        // URL to Master OS API Webhook
        $webhookUrl = 'https://control.jixsite.com/api.php?action=whmcs_order_webhook';

        $postData = [
            'order_id'   => $orderId,
            'hook_name'  => $hookName,
            'vars_json'  => json_encode($vars),
            'secret_key' => 'ambition_instant_2026'
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4, // Fast non-blocking timeout
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // Silently prevent any impact on customer checkout
    }
}

// 1. Hook: Immediately when customer clicks Checkout
add_hook('AfterShoppingCartCheckout', 1, function($vars) {
    ambition_send_order_to_master_os($vars, 'AfterShoppingCartCheckout');
});

// 2. Hook: Immediately when Payment is completed (Razorpay/UPI/Bank)
add_hook('OrderPaid', 1, function($vars) {
    ambition_send_order_to_master_os($vars, 'OrderPaid');
});

// 3. Hook: When order is Accepted/Activated
add_hook('AcceptOrder', 1, function($vars) {
    ambition_send_order_to_master_os($vars, 'AcceptOrder');
});
