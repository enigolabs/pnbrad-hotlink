<?php

/**
 * IntaSend Payment Gateway for PHPNuxBill / pnbrad
 * Conventions match Paystack gateway hooks.
 *
 * Portal default: M-Pesa STK (MPESA_STK_PUSH) → waiting page poll → activate on COMPLETE.
 * Fallback: Checkout Link API when STK is not applicable.
 *
 * Configure: Admin → Payment Gateway → IntaSend
 * Webhook: POST /?_route=callback/intasend  (validate payload "challenge")
 *
 * API assumptions (PHP SDK + docs):
 * - Test base:  https://sandbox.intasend.com/api/v1
 * - Live base:  https://payment.intasend.com/api/v1
 * - STK:  POST /payment/mpesa-stk-push/  body includes public_key; Bearer secret when set
 * - Status: POST /payment/status/  { public_key, invoice_id }
 * - Checkout: POST /checkout/  header X-IntaSend-Public-API-Key
 * - States: PENDING | PROCESSING | COMPLETE | FAILED (+ CANCELED etc.)
 *
 * Do NOT hardcode real keys — placeholders until V supplies them.
 */

function intasend_validate_config()
{
    global $config;
    if (empty($config['intasend_publishable_key'])) {
        r2(U . 'portal', 'w', 'Admin has not yet setup IntaSend payment gateway, please tell admin');
    }
    $pk = (string)$config['intasend_publishable_key'];
    if (stripos($pk, 'REPLACE') !== false || stripos($pk, 'xxxxxxxx') !== false || strpos($pk, 'ISPubKey_') !== 0) {
        r2(U . 'portal', 'w', 'IntaSend keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → IntaSend.');
    }
}

function intasend_keys_ready()
{
    global $config;
    $pk = isset($config['intasend_publishable_key']) ? (string)$config['intasend_publishable_key'] : '';
    if ($pk === '' || stripos($pk, 'REPLACE') !== false || stripos($pk, 'xxxxxxxx') !== false) {
        return false;
    }
    return (strpos($pk, 'ISPubKey_') === 0);
}

function intasend_show_config()
{
    global $ui, $config;
    $ui->assign('_title', 'IntaSend - Payment Gateway');
    $ui->assign('_c', $config);
    $ui->display('intasend.tpl');
}

function intasend_save_config()
{
    global $admin;
    $settings = [
        'intasend_publishable_key' => trim(_post('intasend_publishable_key')),
        'intasend_secret_key' => trim(_post('intasend_secret_key')),
        'intasend_webhook_secret' => trim(_post('intasend_webhook_secret')),
        'intasend_mode' => (_post('intasend_mode') === 'live') ? 'live' : 'test',
        'intasend_currency' => strtoupper(trim(_post('intasend_currency', 'KES'))),
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
    _log('[' . $admin['username'] . ']: IntaSend ' . Lang::T('Settings Saved Successfully'), $admin['user_type']);
    r2(U . 'paymentgateway/intasend', 's', Lang::T('Settings Saved Successfully'));
}

function intasend_currency_code()
{
    global $config;
    $c = isset($config['intasend_currency']) ? strtoupper(trim($config['intasend_currency'])) : 'KES';
    return $c !== '' ? $c : 'KES';
}

function intasend_is_test_mode()
{
    global $config;
    $mode = isset($config['intasend_mode']) ? strtolower(trim($config['intasend_mode'])) : '';
    if ($mode === 'live') {
        return false;
    }
    if ($mode === 'test') {
        return true;
    }
    // Infer from key prefix if mode unset
    $pk = isset($config['intasend_publishable_key']) ? (string)$config['intasend_publishable_key'] : '';
    if (strpos($pk, 'ISPubKey_live') === 0) {
        return false;
    }
    return true;
}

function intasend_api_base()
{
    return intasend_is_test_mode()
        ? 'https://sandbox.intasend.com/api/v1'
        : 'https://payment.intasend.com/api/v1';
}

/**
 * Normalize phone to MSISDN digits for M-Pesa STK (Kenya-style 2547…).
 */
function intasend_phone_msisdn($raw)
{
    $d = preg_replace('/\D/', '', (string)$raw);
    if ($d === '') {
        return '';
    }
    // 07XXXXXXXX → 2547XXXXXXXX
    if (strlen($d) === 10 && $d[0] === '0') {
        $d = '254' . substr($d, 1);
    }
    // 7XXXXXXXX (9 digits) → 2547XXXXXXXX
    if (strlen($d) === 9 && $d[0] === '7') {
        $d = '254' . $d;
    }
    return $d;
}

function intasend_auth_headers($with_bearer = true)
{
    global $config;
    $headers = ['Content-Type: application/json'];
    if ($with_bearer && !empty($config['intasend_secret_key'])) {
        $sk = (string)$config['intasend_secret_key'];
        if (stripos($sk, 'REPLACE') === false && stripos($sk, 'xxxxxxxx') === false) {
            $headers[] = 'Authorization: Bearer ' . $sk;
        }
    }
    return $headers;
}

/**
 * Create IntaSend collection (prefer M-Pesa STK). Portal: no blank bounce — caller redirects to waiting.
 * Non-portal checkout fallback may redirect to IntaSend URL.
 *
 * @param bool $silent When true (portal), return bool instead of redirecting on failure/success.
 */
function intasend_create_transaction($trx, $user, $silent = false)
{
    global $config;

    if (!intasend_keys_ready()) {
        if ($silent) {
            return false;
        }
        r2(U . 'portal', 'w', 'IntaSend keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → IntaSend.');
    }

    $is_portal = !empty($_SESSION['portal_checkout']);
    if (!$is_portal && !empty($trx['pg_request'])) {
        $req = json_decode($trx['pg_request'], true);
        if (is_array($req) && !empty($req['portal'])) {
            $is_portal = true;
        }
    }

    $phone_raw = !empty($user['phonenumber']) ? $user['phonenumber'] : $user['username'];
    $msisdn = intasend_phone_msisdn($phone_raw);
    $email = !empty($user['email']) ? $user['email'] : (preg_replace('/\D/', '', (string)$phone_raw) . '@gmail.com');
    $api_ref = 'pnb_' . $trx['id'] . '_' . time();
    $amount = (float)$trx['price'];
    $currency = intasend_currency_code();

    $d = ORM::for_table('tbl_payment_gateway')
        ->where('username', $user['username'])
        ->where('status', 1)
        ->order_by_desc('id')
        ->find_one();
    if (!$d) {
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    }
    if (!$d) {
        if ($silent) {
            return false;
        }
        r2(U . 'portal', 'e', Lang::T('Failed to find payment gateway record for the user.'));
    }

    // Prefer STK when we have a plausible KE mobile MSISDN (2547… / 2541…)
    $use_stk = ($msisdn !== '' && preg_match('/^254[17]\d{8}$/', $msisdn));

    if ($use_stk) {
        $payload = [
            'public_key' => $config['intasend_publishable_key'],
            'currency' => $currency,
            'method' => 'MPESA_STK_PUSH',
            'amount' => $amount,
            'api_ref' => $api_ref,
            'phone_number' => $msisdn,
            'email' => $email,
            'name' => !empty($user['fullname']) ? $user['fullname'] : $user['username'],
        ];
        $response = Http::postJsonData(
            intasend_api_base() . '/payment/mpesa-stk-push/',
            $payload,
            intasend_auth_headers(true)
        );
        $result = json_decode($response, true);
        $invoice_id = '';
        if (is_array($result)) {
            if (!empty($result['invoice']['invoice_id'])) {
                $invoice_id = $result['invoice']['invoice_id'];
            } elseif (!empty($result['invoice']['id'])) {
                $invoice_id = $result['invoice']['id'];
            } elseif (!empty($result['invoice_id'])) {
                $invoice_id = $result['invoice_id'];
            }
        }
        if ($invoice_id === '') {
            _log('IntaSend STK error: ' . substr((string)$response, 0, 500));
            // Fall through to checkout URL
            $use_stk = false;
        } else {
            $d->gateway_trx_id = $invoice_id;
            $d->pg_url_payment = '';
            $d->pg_request = json_encode([
                'portal' => $is_portal ? 1 : 0,
                'method' => 'MPESA_STK_PUSH',
                'api_ref' => $api_ref,
                'phone' => $msisdn,
                'init' => $result,
            ]);
            $d->expired_date = date('Y-m-d H:i:s', strtotime('+6 HOUR'));
            $d->save();
            unset($_SESSION['portal_checkout']);
            if ($silent || $is_portal) {
                return true;
            }
            r2(U . 'order/view/' . $d['id'], 's', Lang::T('Check your phone for M-Pesa prompt'));
        }
    }

    // Checkout link fallback
    $callback = $is_portal
        ? (U . 'portal/waiting/' . $d['id'])
        : (U . 'order/view/' . $d['id'] . '/check');

    $checkout_payload = [
        'public_key' => $config['intasend_publishable_key'],
        'amount' => (string)$amount,
        'currency' => $currency,
        'email' => $email,
        'phone_number' => $msisdn !== '' ? $msisdn : null,
        'api_ref' => $api_ref,
        'redirect_url' => $callback,
        'host' => (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : ''),
        'comment' => $trx['plan_name'],
        'first_name' => $user['username'],
    ];
    $headers = intasend_auth_headers(false);
    $headers[] = 'X-IntaSend-Public-API-Key: ' . $config['intasend_publishable_key'];

    $response = Http::postJsonData(
        intasend_api_base() . '/checkout/',
        $checkout_payload,
        $headers
    );
    $result = json_decode($response, true);
    $url = is_array($result) && !empty($result['url']) ? $result['url'] : '';
    $invoice_id = '';
    if (is_array($result)) {
        if (!empty($result['invoice']['invoice_id'])) {
            $invoice_id = $result['invoice']['invoice_id'];
        } elseif (!empty($result['id'])) {
            $invoice_id = $result['id'];
        }
    }
    if ($url === '') {
        _log('IntaSend checkout error: ' . substr((string)$response, 0, 500));
        if ($silent) {
            return false;
        }
        r2(U . 'portal', 'e', Lang::T('Failed to create transaction.') . ' IntaSend');
    }

    $d->gateway_trx_id = $invoice_id !== '' ? $invoice_id : $api_ref;
    $d->pg_url_payment = $url;
    $d->pg_request = json_encode([
        'portal' => $is_portal ? 1 : 0,
        'method' => 'CHECKOUT',
        'api_ref' => $api_ref,
        'init' => $result,
    ]);
    $d->expired_date = date('Y-m-d H:i:s', strtotime('+6 HOUR'));
    $d->save();
    unset($_SESSION['portal_checkout']);

    if ($is_portal && $silent) {
        // Portal waiting page will send user to checkout URL if set
        return true;
    }
    header('Location: ' . $url);
    exit();
}

/**
 * Webhook — validate challenge, activate only on COMPLETE.
 */
function intasend_payment_notification()
{
    global $config;

    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Bad Request']);
        exit();
    }

    $raw = file_get_contents('php://input');
    $event = json_decode($raw, true);
    if (!is_array($event)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Invalid JSON']);
        exit();
    }

    $challenge = isset($event['challenge']) ? (string)$event['challenge'] : '';
    $expected = isset($config['intasend_webhook_secret']) ? (string)$config['intasend_webhook_secret'] : '';
    if ($expected !== '' && !hash_equals($expected, $challenge)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid challenge']);
        exit();
    }

    header('HTTP/1.1 200 OK');
    header('Content-Type: application/json');

    $state = isset($event['state']) ? strtoupper((string)$event['state']) : '';
    $invoice_id = isset($event['invoice_id']) ? (string)$event['invoice_id'] : '';
    $api_ref = isset($event['api_ref']) ? (string)$event['api_ref'] : '';
    $value = isset($event['value']) ? (float)$event['value'] : (isset($event['net_amount']) ? (float)$event['net_amount'] : 0);
    $provider = isset($event['provider']) ? (string)$event['provider'] : 'intasend';

    if ($invoice_id === '' && $api_ref === '') {
        echo json_encode(['status' => 'no_reference']);
        exit();
    }

    $trx = null;
    if ($invoice_id !== '') {
        $trx = ORM::for_table('tbl_payment_gateway')->where('gateway_trx_id', $invoice_id)->find_one();
    }
    if (!$trx && $api_ref !== '' && preg_match('/pnb_(\d+)_/', $api_ref, $m)) {
        $trx = ORM::for_table('tbl_payment_gateway')->find_one((int)$m[1]);
    }
    if (!$trx) {
        _log("IntaSend webhook: transaction not found invoice=$invoice_id api_ref=$api_ref");
        echo json_encode(['status' => 'not_found']);
        exit();
    }

    if (in_array((string)$trx['status'], ['2', '3', '4'], true)) {
        echo json_encode(['status' => 'already_processed']);
        exit();
    }

    if ($state === 'COMPLETE' && $value + 0.0001 >= (float)$trx['price']) {
        $ok = Package::rechargeUser(
            $trx['user_id'],
            $trx['routers'],
            $trx['plan_id'],
            'IntaSend',
            $provider
        );
        if ($ok) {
            $trx->pg_paid_response = $raw;
            $trx->payment_method = 'IntaSend';
            $trx->payment_channel = $provider;
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->status = 2;
            if ($invoice_id !== '') {
                $trx->gateway_trx_id = $invoice_id;
            }
            $trx->save();
            _log("IntaSend webhook: activated trx {$trx['id']} user {$trx['username']}");
            echo json_encode(['status' => 'ok']);
            exit();
        }
        _log("IntaSend webhook: Package::rechargeUser failed for trx {$trx['id']}");
        echo json_encode(['status' => 'activate_failed']);
        exit();
    }

    if (in_array($state, ['FAILED', 'CANCELED'], true)) {
        $trx->pg_paid_response = $raw;
        $trx->payment_method = 'IntaSend';
        $trx->payment_channel = $provider;
        $trx->status = 4;
        $trx->save();
    }

    echo json_encode(['status' => 'handled', 'payment_status' => $state]);
    exit();
}

/**
 * Poll IntaSend status by invoice_id; activate on COMPLETE.
 * @return bool true if paid/activated
 */
function intasend_get_status($transaction, $user, $silent = false)
{
    global $config;

    $invoice_id = $transaction['gateway_trx_id'];
    if (empty($invoice_id)) {
        if ($silent) {
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Unable to verify the transaction, try again later.'));
    }

    if ((string)$transaction['status'] === '2') {
        if ($silent) {
            return true;
        }
        r2(U . 'order/view/' . $transaction['id'], 's', Lang::T('Transaction has already been paid.'));
    }

    $payload = [
        'public_key' => $config['intasend_publishable_key'],
        'invoice_id' => $invoice_id,
    ];
    // Prefer Bearer when secret available; status also works with public_key in body (SDK)
    $response = Http::postJsonData(
        intasend_api_base() . '/payment/status/',
        $payload,
        intasend_auth_headers(true)
    );
    $result = json_decode($response, true);
    if (!is_array($result)) {
        if ($silent) {
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Unable to verify the transaction, try again later.'));
    }

    $inv = isset($result['invoice']) && is_array($result['invoice']) ? $result['invoice'] : $result;
    $state = isset($inv['state']) ? strtoupper((string)$inv['state']) : '';
    $value = isset($inv['value']) ? (float)$inv['value'] : (isset($inv['net_amount']) ? (float)$inv['net_amount'] : 0);
    $provider = isset($inv['provider']) ? (string)$inv['provider'] : 'M-PESA';

    if ($state === 'COMPLETE' && ($value <= 0 || $value + 0.0001 >= (float)$transaction['price'])) {
        if (!Package::rechargeUser($user['id'], $transaction['routers'], $transaction['plan_id'], $transaction['gateway'], $provider)) {
            if ($silent) {
                return false;
            }
            r2(U . 'order/view/' . $transaction['id'], 'd', Lang::T('Failed to activate your package, try again later.'));
        }
        $transaction->pg_paid_response = json_encode($result);
        $transaction->payment_method = 'IntaSend';
        $transaction->payment_channel = $provider;
        $transaction->paid_date = date('Y-m-d H:i:s');
        $transaction->status = 2;
        $transaction->save();
        if ($silent) {
            return true;
        }
        r2(U . 'order/view/' . $transaction['id'], 's', Lang::T('Transaction successful.'));
    }

    if (in_array($state, ['FAILED', 'CANCELED'], true)) {
        if ($silent) {
            // Mark failed for poller
            if ((string)$transaction['status'] === '1') {
                $transaction->pg_paid_response = json_encode($result);
                $transaction->status = 4;
                $transaction->save();
            }
            return false;
        }
        r2(U . 'order/view/' . $transaction['id'], 'w', Lang::T('Transaction still unpaid.'));
    }

    if ($silent) {
        return false;
    }
    r2(U . 'order/view/' . $transaction['id'], 'w', Lang::T('Transaction still unpaid.'));
}

/**
 * Map IntaSend / DB state to portal poll status: pending|paid|failed
 */
function intasend_portal_poll_state($transaction)
{
    if ((string)$transaction['status'] === '2') {
        return 'paid';
    }
    if (in_array((string)$transaction['status'], ['3', '4'], true)) {
        return 'failed';
    }
    return 'pending';
}
