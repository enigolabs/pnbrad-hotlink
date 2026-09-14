<?php

/**
 * Paystack Payment Gateway for PHPNuxBill / pnbrad
 * Conventions match existing gateways (Flutterwave-style hooks).
 *
 * Configure keys in Admin → Payment Gateway → Paystack.
 * Do NOT hardcode live keys. Placeholders are fine until V supplies test/live keys.
 *
 * Webhook: POST /?_route=callback/paystack
 * Signature: x-paystack-signature = HMAC SHA512(raw body, webhook_secret ?: secret_key)
 */

function paystack_validate_config()
{
    global $config;
    if (empty($config['paystack_secret_key'])) {
        // Plain string: Lang::T() auto-translates unknown keys via external HTTP and can hang
        r2(U . 'portal', 'w', 'Admin has not yet setup Paystack payment gateway, please tell admin');
    }
    // Fail fast on placeholders so portal UX does not hang on Paystack HTTP timeout
    $sk = $config['paystack_secret_key'];
    if (stripos($sk, 'REPLACE') !== false || $sk === 'sk_test_xxxxxxxx') {
        r2(U . 'portal', 'w', 'Paystack keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → Paystack.');
    }
}

function paystack_show_config()
{
    global $ui, $config;
    $ui->assign('_title', 'Paystack - Payment Gateway');
    $ui->assign('_c', $config);
    $ui->display('paystack.tpl');
}

function paystack_save_config()
{
    global $admin;
    $settings = [
        'paystack_public_key' => _post('paystack_public_key'),
        'paystack_secret_key' => _post('paystack_secret_key'),
        'paystack_webhook_secret' => _post('paystack_webhook_secret'),
        'paystack_currency' => strtoupper(trim(_post('paystack_currency', 'NGN'))),
    ];
    foreach ($settings as $key => $value) {
        $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
        if ($d) {
            $d->value = $value;
            $d->save();
        } else {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = $key;
            $d->value = $value;
            $d->save();
        }
    }
    _log('[' . $admin['username'] . ']: Paystack ' . Lang::T('Settings Saved Successfully'), $admin['user_type']);
    r2(U . 'paymentgateway/paystack', 's', Lang::T('Settings Saved Successfully'));
}

/**
 * HMAC key for webhook verification: prefer dedicated webhook secret, else secret key (Paystack default).
 */
function paystack_webhook_hmac_key()
{
    global $config;
    if (!empty($config['paystack_webhook_secret'])) {
        return $config['paystack_webhook_secret'];
    }
    return isset($config['paystack_secret_key']) ? $config['paystack_secret_key'] : '';
}

function paystack_currency_code()
{
    global $config;
    $c = isset($config['paystack_currency']) ? strtoupper(trim($config['paystack_currency'])) : 'NGN';
    return $c !== '' ? $c : 'NGN';
}

/**
 * Create Paystack transaction and redirect customer to authorization_url.
 * Amount is sent in the smallest currency unit (kobo/pesewas/cents): price * 100.
 */
function paystack_create_transaction($trx, $user)
{
    global $config;
    // Defense in depth: never call Paystack API with placeholders
    $sk = isset($config['paystack_secret_key']) ? (string)$config['paystack_secret_key'] : '';
    if ($sk === '' || stripos($sk, 'REPLACE') !== false || substr($sk, 0, 3) !== 'sk_') {
        r2(U . 'portal', 'w', 'Paystack keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → Paystack.');
    }

    $reference = 'pnb_' . $trx['id'] . '_' . time();
    $amount_minor = (int) round(((float) $trx['price']) * 100);
    if ($amount_minor < 100) {
        r2(U . 'portal', 'e', Lang::T('Invalid amount'));
    }

    $email = !empty($user['email']) ? $user['email'] : ($user['username'] . '@gmail.com');
    $is_portal = !empty($_SESSION['portal_checkout']);
    if (!$is_portal && !empty($trx['pg_request'])) {
        $req = json_decode($trx['pg_request'], true);
        if (is_array($req) && !empty($req['portal'])) {
            $is_portal = true;
        } elseif (strpos((string)$trx['pg_request'], '"portal":1') !== false) {
            $is_portal = true;
        }
    }

    $callback = $is_portal
        ? (U . 'portal/waiting/' . $trx['id'])
        : (U . 'order/view/' . $trx['id'] . '/check');

    $payload = [
        'reference' => $reference,
        'amount' => $amount_minor,
        'email' => $email,
        'currency' => paystack_currency_code(),
        'callback_url' => $callback,
        'metadata' => [
            'custom_fields' => [
                [
                    'display_name' => 'Username',
                    'variable_name' => 'username',
                    'value' => $user['username'],
                ],
                [
                    'display_name' => 'Plan',
                    'variable_name' => 'plan_name',
                    'value' => $trx['plan_name'],
                ],
            ],
            'price' => $trx['price'],
            'userid' => $user['id'],
            'planid' => $trx['plan_id'],
            'router' => $trx['routers'],
            'trxid' => $trx['id'],
            'portal' => $is_portal ? 1 : 0,
        ],
    ];

    $response = Http::postJsonData(
        'https://api.paystack.co/transaction/initialize',
        $payload,
        [
            'Authorization: Bearer ' . $config['paystack_secret_key'],
            'Cache-Control: no-cache',
        ]
    );

    $result = json_decode($response, true);
    if (!$result || !isset($result['status'])) {
        _log('Paystack initialize API error: ' . substr((string)$response, 0, 500));
        r2(U . 'portal', 'e', Lang::T('Failed to create transaction. Paystack API error.'));
    }
    if (empty($result['status'])) {
        $msg = isset($result['message']) ? $result['message'] : 'unknown';
        _log('Paystack initialize failed: ' . $msg);
        r2(U . 'portal', 'e', Lang::T('Failed to create transaction.') . ' ' . $msg);
    }

    $d = ORM::for_table('tbl_payment_gateway')
        ->where('username', $user['username'])
        ->where('status', 1)
        ->order_by_desc('id')
        ->find_one();
    if (!$d) {
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    }
    if (!$d) {
        r2(U . 'portal', 'e', Lang::T('Failed to find payment gateway record for the user.'));
    }

    $d->gateway_trx_id = isset($result['data']['reference']) ? $result['data']['reference'] : $reference;
    $d->pg_url_payment = $result['data']['authorization_url'];
    $d->pg_request = json_encode(['portal' => $is_portal ? 1 : 0, 'init' => $result]);
    $d->expired_date = date('Y-m-d H:i:s', strtotime('+6 HOUR'));
    $d->save();

    unset($_SESSION['portal_checkout']);

    header('Location: ' . $result['data']['authorization_url']);
    exit();
}

/**
 * Webhook handler — verify signature, activate only on successful charge.
 */
function paystack_payment_notification()
{
    global $config;

    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Bad Request']);
        exit();
    }

    $raw = file_get_contents('php://input');
    $sig = isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']) ? $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] : '';
    $hmac_key = paystack_webhook_hmac_key();
    $expected = hash_hmac('sha512', $raw, $hmac_key);

    if ($sig === '' || !hash_equals($expected, $sig)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid signature']);
        exit();
    }

    // ACK quickly; process after
    header('HTTP/1.1 200 OK');
    header('Content-Type: application/json');

    $event = json_decode($raw);
    if (!$event || empty($event->event)) {
        echo json_encode(['status' => 'ignored']);
        exit();
    }

    // Only process successful charge events
    if ($event->event !== 'charge.success') {
        echo json_encode(['status' => 'ignored', 'event' => $event->event]);
        exit();
    }

    $data = $event->data;
    $reference = isset($data->reference) ? $data->reference : '';
    $status = isset($data->status) ? $data->status : '';
    $amount = isset($data->amount) ? ((float)$data->amount / 100) : 0;
    $channel = isset($data->channel) ? $data->channel : 'paystack';

    if ($reference === '') {
        echo json_encode(['status' => 'no_reference']);
        exit();
    }

    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway_trx_id', $reference)
        ->find_one();
    if (!$trx) {
        _log("Paystack webhook: transaction not found for reference $reference");
        echo json_encode(['status' => 'not_found']);
        exit();
    }

    // Already paid / cancelled / expired
    if (in_array((string)$trx['status'], ['2', '3', '4'], true)) {
        echo json_encode(['status' => 'already_processed']);
        exit();
    }

    if ($status === 'success' && $amount + 0.0001 >= (float)$trx['price']) {
        $ok = Package::rechargeUser(
            $trx['user_id'],
            $trx['routers'],
            $trx['plan_id'],
            'Paystack',
            $channel
        );
        if ($ok) {
            $trx->pg_paid_response = $raw;
            $trx->payment_method = 'Paystack';
            $trx->payment_channel = $channel;
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->status = 2;
            $trx->save();
            _log("Paystack webhook: activated trx {$trx['id']} user {$trx['username']}");
            echo json_encode(['status' => 'ok']);
            exit();
        }
        _log("Paystack webhook: Package::rechargeUser failed for trx {$trx['id']}");
        echo json_encode(['status' => 'activate_failed']);
        exit();
    }

    if (in_array($status, ['failed', 'abandoned'], true)) {
        $trx->pg_paid_response = $raw;
        $trx->payment_method = 'Paystack';
        $trx->payment_channel = $channel;
        $trx->status = 4;
        $trx->save();
    }

    echo json_encode(['status' => 'handled', 'payment_status' => $status]);
    exit();
}

/**
 * Verify transaction by reference (customer return / check).
 * Returns true if newly activated; redirects for normal order flow.
 * When $silent is true (portal paid), returns bool instead of redirecting.
 */
function paystack_get_status($transaction, $user, $silent = false)
{
    global $config;

    $ref = $transaction['gateway_trx_id'];
    if (empty($ref)) {
        if ($silent) {
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Unable to verify the transaction, try again later.'));
    }

    $response = Http::getData(
        'https://api.paystack.co/transaction/verify/' . rawurlencode($ref),
        [
            'Authorization: Bearer ' . $config['paystack_secret_key'],
            'Cache-Control: no-cache',
        ]
    );
    $result = json_decode($response, true);

    if (!$result || empty($result['status'])) {
        if ($silent) {
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Unable to verify the transaction, try again later.'));
    }

    $data = isset($result['data']) ? $result['data'] : [];
    $pay_status = isset($data['status']) ? $data['status'] : '';
    $amount_paid = isset($data['amount']) ? ((float)$data['amount'] / 100) : 0;
    $channel = isset($data['channel']) ? $data['channel'] : 'paystack';

    if ((string)$transaction['status'] === '2') {
        if ($silent) {
            return true;
        }
        r2(U . 'order/view/' . $transaction['id'], 's', Lang::T('Transaction has already been paid.'));
    }

    if ($pay_status === 'success' && $amount_paid + 0.0001 >= (float)$transaction['price']) {
        if (!Package::rechargeUser($user['id'], $transaction['routers'], $transaction['plan_id'], $transaction['gateway'], $channel)) {
            if ($silent) {
                return false;
            }
            r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Failed to activate your package, try again later.'));
        }
        $transaction->pg_paid_response = json_encode($result);
        $transaction->payment_method = 'Paystack';
        $transaction->payment_channel = $channel;
        $transaction->paid_date = date('Y-m-d H:i:s');
        $transaction->status = 2;
        $transaction->save();
        if ($silent) {
            return true;
        }
        r2(U . 'order/view/' . $transaction['id'], 's', Lang::T('Transaction successful.'));
    }

    if (in_array($pay_status, ['failed', 'abandoned'], true)) {
        if ($silent) {
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'w', Lang::T('Transaction still unpaid.'));
    }

    if ($silent) {
        return false;
    }
    r2(U . 'order/view/' . $transaction['id'], 'w', Lang::T('Transaction still unpaid.'));
}
