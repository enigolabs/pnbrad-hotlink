<?php

/**
 * Hotspot captive portal — eNiGoLabs branding.
 * Flow: packages → phone → IntaSend STK (prefer) / Paystack → waiting poll → activate + connect → Google.
 * Returning devices: MAC→customer (tbl_portal_macs) with active package auto-pass to home.
 *
 * Phone normalization (documented assumption):
 * - Strip spaces, dashes, parentheses, dots
 * - Leading 00 → +
 * - Keep a single leading + then digits (E.164-ish), OR digits-only local form
 * - If Settings → country_code_phone is set AND the number has no + / no 00
 *   AND does not already start with that country code digits, prepend country_code_phone
 * - Username / password / lookup key = normalized phone (as stored)
 * - Email = {digits-only phone}@gmail.com
 */

$action = isset($routes['1']) ? $routes['1'] : 'list';

function portal_email_from_phone($phone)
{
    $digits = preg_replace('/\D/', '', (string)$phone);
    return $digits . '@gmail.com';
}

function portal_normalize_phone($raw)
{
    global $config;
    $phone = trim((string)$raw);
    $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);
    if ($phone === '' || $phone === null) {
        return '';
    }
    if (strpos($phone, '00') === 0) {
        $phone = '+' . substr($phone, 2);
    }
    if (strpos($phone, '+') === 0) {
        $digits = preg_replace('/\D/', '', substr($phone, 1));
        return $digits !== '' ? ('+' . $digits) : '';
    }
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') {
        return '';
    }
    $cc = isset($config['country_code_phone']) ? preg_replace('/\D/', '', $config['country_code_phone']) : '';
    if ($cc !== '' && strpos($digits, $cc) !== 0) {
        $digits = $cc . ltrim($digits, '0');
    }
    return $digits;
}

function portal_resolve_router_name()
{
    $ref = isset($_SESSION['nux-router']) ? trim((string)$_SESSION['nux-router']) : '';
    if ($ref === '') {
        return '';
    }
    if ($ref === 'radius') {
        return 'radius';
    }
    if (ctype_digit($ref)) {
        $r = ORM::for_table('tbl_routers')->where('id', $ref)->find_one();
        return $r ? $r['name'] : $ref;
    }
    $r = ORM::for_table('tbl_routers')->where('name', $ref)->find_one();
    return $r ? $r['name'] : $ref;
}

function portal_load_hotspot_plans()
{
    $q = ORM::for_table('tbl_plans')
        ->where('enabled', '1')
        ->where('type', 'Hotspot')
        ->where('prepaid', 'yes')
        ->where('allow_purchase', 'yes');
    $name = portal_resolve_router_name();
    if ($name === 'radius') {
        $q->where('is_radius', '1');
    } elseif ($name !== '') {
        $q->where_raw('(routers = ? OR is_radius = 1)', [$name]);
    }
    return $q->order_by_asc('price')->find_many();
}

function portal_find_or_create_customer($phone)
{
    $existing = ORM::for_table('tbl_customers')->where('username', $phone)->find_one();
    if ($existing) {
        if ($existing['status'] == 'Banned') {
            return [null, Lang::T('This account status') . ' : ' . Lang::T($existing['status'])];
        }
        $want_email = portal_email_from_phone($phone);
        $dirty = false;
        if ($existing['password'] !== $phone) {
            $existing->password = $phone;
            $dirty = true;
        }
        if ($existing['email'] !== $want_email) {
            $existing->email = $want_email;
            $dirty = true;
        }
        if ($dirty) {
            $existing->save();
        }
        return [$existing, null];
    }

    $d = ORM::for_table('tbl_customers')->create();
    $d->username = $phone;
    $d->password = $phone;
    $d->fullname = $phone;
    $d->email = portal_email_from_phone($phone);
    $d->phonenumber = $phone;
    $d->service_type = 'Hotspot';
    $d->account_type = 'Personal';
    $d->status = 'Active';
    $d->address = '';
    $d->save();
    return [$d, null];
}

function portal_login_customer($customer)
{
    $_SESSION['uid'] = $customer['id'];
    User::setCookie($customer['id']);
    $customer->last_login = date('Y-m-d H:i:s');
    $customer->save();
}

function portal_try_device_connect($customer)
{
    global $_app_stage;
    if (empty($_SESSION['nux-mac']) || empty($_SESSION['nux-ip'])) {
        return false;
    }
    $tur = ORM::for_table('tbl_user_recharges')
        ->where('customer_id', $customer['id'])
        ->where('status', 'on')
        ->order_by_desc('id')
        ->find_one();
    if (!$tur) {
        return false;
    }
    $p = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
    if (!$p) {
        return false;
    }
    $dvc = Package::getDevice($p);
    if ($_app_stage == 'demo' || !file_exists($dvc)) {
        return false;
    }
    try {
        require_once $dvc;
        (new $p['device'])->connect_customer($customer, $_SESSION['nux-ip'], $_SESSION['nux-mac'], $tur['routers']);
        return true;
    } catch (Exception $e) {
        _log('portal connect_customer: ' . $e->getMessage());
        return false;
    }
}

function portal_has_active_package($customer_id)
{
    $tur = ORM::for_table('tbl_user_recharges')
        ->where('customer_id', $customer_id)
        ->where('status', 'on')
        ->find_one();
    return (bool)$tur;
}

/**
 * Prefer IntaSend when enabled+keys ready; else Paystack; else null + error message.
 */
function portal_select_gateway()
{
    global $config, $PAYMENTGATEWAY_PATH;
    $actives = array_filter(array_map('trim', explode(',', isset($config['payment_gateway']) ? $config['payment_gateway'] : '')));

    $intasend_file = $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'intasend.php';
    $paystack_file = $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'paystack.php';
    $intasend_err = null;
    $paystack_err = null;

    if (in_array('intasend', $actives, true) && file_exists($intasend_file)) {
        include_once $intasend_file;
        if (function_exists('intasend_keys_ready') && intasend_keys_ready()) {
            return ['intasend', null];
        }
        $intasend_err = 'IntaSend keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → IntaSend.';
    }

    if (in_array('paystack', $actives, true) && file_exists($paystack_file)) {
        include_once $paystack_file;
        $sk = isset($config['paystack_secret_key']) ? (string)$config['paystack_secret_key'] : '';
        $ok = ($sk !== '' && stripos($sk, 'REPLACE') === false && stripos($sk, 'xxxxxxxx') === false && substr($sk, 0, 3) === 'sk_');
        if ($ok) {
            return ['paystack', null];
        }
        $paystack_err = 'Paystack keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → Paystack.';
    }

    if ($intasend_err) {
        return [null, $intasend_err];
    }
    if ($paystack_err) {
        return [null, $paystack_err];
    }
    return [null, 'No payment gateway enabled. Ask admin to enable IntaSend (preferred) or Paystack under Payment Gateway.'];
}

function portal_after_paid_redirect($customer)
{
    PortalMac::remember($customer['id']);
    portal_try_device_connect($customer);
    header('Location: https://www.google.com');
    exit();
}

function portal_json_exit($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit();
}

switch ($action) {
    case 'buy':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            r2(U . 'portal', 'w', Lang::T('Invalid request'));
        }
        $csrf_token = _post('csrf_token');
        if (!Csrf::check($csrf_token)) {
            r2(U . 'portal', 'e', Lang::T('Invalid or Expired CSRF Token'));
        }

        $phone = portal_normalize_phone(_post('phone'));
        $plan_id = (int)_post('plan_id');
        if ($phone === '' || strlen(preg_replace('/\D/', '', $phone)) < 7) {
            r2(U . 'portal', 'e', Lang::T('Please enter a valid phone number'));
        }
        if ($plan_id <= 0) {
            r2(U . 'portal', 'e', Lang::T('Plan Not found'));
        }

        $plan = ORM::for_table('tbl_plans')
            ->where('enabled', '1')
            ->where('type', 'Hotspot')
            ->find_one($plan_id);
        if (!$plan) {
            r2(U . 'portal', 'e', Lang::T('Plan Not found'));
        }

        global $config, $PAYMENTGATEWAY_PATH;
        list($gateway, $gw_err) = portal_select_gateway();
        if (!$gateway) {
            r2(U . 'portal', 'w', $gw_err ? $gw_err : 'Payment gateway not ready');
        }

        if ($gateway === 'intasend') {
            include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'intasend.php';
            intasend_validate_config();
        } else {
            include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'paystack.php';
            paystack_validate_config();
        }

        list($customer, $err) = portal_find_or_create_customer($phone);
        if ($err) {
            r2(U . 'portal', 'e', $err);
        }
        portal_login_customer($customer);
        $user = User::_info($customer['id']);

        if ($plan['is_radius'] == '1') {
            $router_id = 0;
            $router_name = 'radius';
        } else {
            $router_name = $plan['routers'];
            $router = ORM::for_table('tbl_routers')->where('name', $router_name)->find_one();
            $router_id = $router ? $router['id'] : 0;
        }

        $existing = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one();
        if ($existing && (!empty($existing['pg_url_payment']) || !empty($existing['gateway_trx_id']))) {
            $existing->status = 4;
            $existing->save();
        }

        $tax = 0;
        $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
        if ($tax_enable === 'yes') {
            $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
            $custom_tax_rate = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;
            $tax_rate = ($tax_rate_setting === 'custom') ? $custom_tax_rate : $tax_rate_setting;
            $tax = Package::tax($plan['price'], $tax_rate);
        }

        $d = ORM::for_table('tbl_payment_gateway')->create();
        $d->username = $user['username'];
        $d->user_id = $user['id'];
        $d->gateway = $gateway;
        $d->plan_id = $plan['id'];
        $d->plan_name = $plan['name_plan'];
        $d->routers_id = $router_id;
        $d->routers = $router_name;
        $d->price = ((float)$plan['price']) + $tax;
        $d->created_date = date('Y-m-d H:i:s');
        $d->status = 1;
        $d->pg_request = json_encode(['portal' => 1]);
        $d->save();

        $_SESSION['portal_checkout'] = 1;
        $_SESSION['portal_trx_id'] = $d->id();

        if ($gateway === 'intasend') {
            // STK in "background" — do not bounce user to a blank Paystack-style redirect
            $ok = intasend_create_transaction($d, $user, true);
            if (!$ok) {
                r2(U . 'portal', 'e', Lang::T('Failed to create transaction.') . ' IntaSend');
            }
            // If checkout fallback stored a URL, waiting page can offer/open it
            r2(U . 'portal/waiting/' . $d->id());
        }

        // Paystack: create checkout then send user to Paystack; return URL → waiting
        // Temporarily override callback by setting portal flag; paystack uses portal/paid —
        // we route paid → waiting. Also set session so waiting works after return.
        paystack_create_transaction($d, $user);
        // paystack_create_transaction redirects to authorization_url and exits
        break;

    case 'waiting':
        PortalMac::captureSession();
        $trx_id = isset($routes['2']) ? (int)$routes['2'] : (int)_get('trx');
        if ($trx_id <= 0 && !empty($_SESSION['portal_trx_id'])) {
            $trx_id = (int)$_SESSION['portal_trx_id'];
        }
        if ($trx_id <= 0) {
            r2(U . 'portal', 'w', Lang::T('Payment not found'));
        }
        $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
        if (!$trx) {
            r2(U . 'portal', 'e', Lang::T('Payment not found'));
        }
        // Recover login from trx if session lost (e.g. Paystack return)
        $user_id = User::getID();
        if (!$user_id) {
            $c = ORM::for_table('tbl_customers')->find_one($trx['user_id']);
            if ($c) {
                portal_login_customer($c);
            }
        }
        $_SESSION['portal_trx_id'] = $trx_id;

        // Paystack return may include ?reference= — stash on trx if missing
        $ref = _get('reference');
        if ($ref && empty($trx['gateway_trx_id'])) {
            $trx->gateway_trx_id = $ref;
            $trx->save();
        } elseif ($ref && $trx['gateway_trx_id'] !== $ref) {
            // Keep existing gateway_trx_id (Paystack sets it at init)
        }

        $checkout_url = !empty($trx['pg_url_payment']) ? $trx['pg_url_payment'] : '';
        $need_checkout = false;
        if ($checkout_url !== '' && (string)$trx['status'] === '1') {
            $req = json_decode($trx['pg_request'], true);
            $method = is_array($req) && !empty($req['method']) ? $req['method'] : '';
            // Auto-open IntaSend checkout fallback (not STK). Paystack users already visited checkout.
            if ($method === 'CHECKOUT' && strtolower((string)$trx['gateway']) === 'intasend') {
                $need_checkout = true;
            }
        }

        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('trx_id', $trx_id);
        $ui->assign('plan_name', $trx['plan_name']);
        $ui->assign('gateway', $trx['gateway']);
        $ui->assign('checkout_url', $checkout_url);
        $ui->assign('need_checkout', $need_checkout);
        $ui->assign('pay_status_url', '?_route=portal/pay-status&trx=' . $trx_id);
        $ui->assign('_title', 'Please wait');
        $ui->display('customer/portal-waiting.tpl');
        break;

    case 'pay-status':
        // JSON poll endpoint
        global $PAYMENTGATEWAY_PATH, $config;
        $trx_id = (int)_get('trx');
        if ($trx_id <= 0) {
            $trx_id = isset($routes['2']) ? (int)$routes['2'] : 0;
        }
        if ($trx_id <= 0) {
            portal_json_exit(['status' => 'failed', 'message' => 'missing trx'], 400);
        }
        $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
        if (!$trx) {
            portal_json_exit(['status' => 'failed', 'message' => 'not found'], 404);
        }

        // Timeout: older than 15 minutes unpaid → failed
        $created = strtotime($trx['created_date']);
        if ((string)$trx['status'] === '1' && $created && (time() - $created) > 900) {
            portal_json_exit([
                'status' => 'failed',
                'message' => 'timeout',
                'redirect' => U . 'portal',
            ]);
        }

        if ((string)$trx['status'] === '2') {
            $customer = ORM::for_table('tbl_customers')->find_one($trx['user_id']);
            if ($customer) {
                portal_login_customer($customer);
                PortalMac::remember($customer['id']);
                portal_try_device_connect($customer);
            }
            portal_json_exit([
                'status' => 'paid',
                'redirect' => 'https://www.google.com',
            ]);
        }
        if (in_array((string)$trx['status'], ['3', '4'], true)) {
            portal_json_exit([
                'status' => 'failed',
                'redirect' => U . 'portal',
            ]);
        }

        // Still pending — try live verify
        $user = null;
        $c = ORM::for_table('tbl_customers')->find_one($trx['user_id']);
        if ($c) {
            portal_login_customer($c);
            $user = User::_info($c['id']);
        }
        if (!$user) {
            portal_json_exit(['status' => 'pending', 'message' => 'Checking…']);
        }

        $gw = strtolower((string)$trx['gateway']);
        $ok = false;
        if ($gw === 'intasend' && file_exists($PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'intasend.php')) {
            include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'intasend.php';
            $ok = intasend_get_status($trx, $user, true);
        } elseif ($gw === 'paystack' && file_exists($PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'paystack.php')) {
            include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'paystack.php';
            $ok = paystack_get_status($trx, $user, true);
        }

        // Reload trx after verify
        $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
        if ($ok || (string)$trx['status'] === '2') {
            PortalMac::remember($c['id']);
            portal_try_device_connect($c);
            portal_json_exit([
                'status' => 'paid',
                'redirect' => 'https://www.google.com',
            ]);
        }
        if (in_array((string)$trx['status'], ['3', '4'], true)) {
            portal_json_exit([
                'status' => 'failed',
                'redirect' => U . 'portal',
            ]);
        }
        portal_json_exit(['status' => 'pending', 'message' => 'Checking…']);
        break;

    case 'paid':
        // Legacy / Paystack return — send to waiting page (poll verifies)
        $trx_id = isset($routes['2']) ? (int)$routes['2'] : 0;
        $ref = _get('reference');
        if ($trx_id <= 0 && $ref) {
            $t = ORM::for_table('tbl_payment_gateway')->where('gateway_trx_id', $ref)->find_one();
            if ($t) {
                $trx_id = (int)$t['id'];
            }
        }
        if ($trx_id <= 0 && !empty($_SESSION['portal_trx_id'])) {
            $trx_id = (int)$_SESSION['portal_trx_id'];
        }
        if ($trx_id <= 0) {
            r2(U . 'portal', 'e', Lang::T('Payment not found'));
        }
        // Preserve Paystack reference query for waiting handler
        $q = $ref ? ('?reference=' . urlencode($ref)) : '';
        // r2 builds route — append reference via session
        if ($ref) {
            $_SESSION['portal_pay_ref'] = $ref;
        }
        r2(U . 'portal/waiting/' . $trx_id);
        break;

    case 'reconnect':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf_token = _post('csrf_token');
            if (!Csrf::check($csrf_token)) {
                r2(U . 'portal/reconnect', 'e', Lang::T('Invalid or Expired CSRF Token'));
            }
            $phone = portal_normalize_phone(_post('phone'));
            if ($phone === '') {
                r2(U . 'portal/reconnect', 'e', Lang::T('Please enter a valid phone number'));
            }
            $customer = ORM::for_table('tbl_customers')->where('username', $phone)->find_one();
            if (!$customer) {
                $customer = ORM::for_table('tbl_customers')->where('phonenumber', $phone)->find_one();
            }
            if (!$customer) {
                r2(U . 'portal/reconnect', 'e', Lang::T('Account not found. Buy a package first.'));
            }
            if ($customer['status'] == 'Banned') {
                r2(U . 'portal/reconnect', 'e', Lang::T('This account status') . ' : ' . Lang::T($customer['status']));
            }
            if (!portal_has_active_package($customer['id'])) {
                r2(U . 'portal', 'w', Lang::T('No active package. Please buy a package.'));
            }
            portal_login_customer($customer);
            PortalMac::remember($customer['id']);
            portal_try_device_connect($customer);
            header('Location: https://www.google.com');
            exit();
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('_title', Lang::T('Reconnect'));
        $ui->assign('country_code_phone', isset($config['country_code_phone']) ? $config['country_code_phone'] : '');
        $ui->display('customer/portal-reconnect.tpl');
        break;

    case 'list':
    default:
        PortalMac::captureSession();
        if (PortalMac::tryAutopass()) {
            break;
        }
        $csrf_token = Csrf::generateAndStoreToken();
        $plans = portal_load_hotspot_plans();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('plans', $plans);
        $ui->assign('country_code_phone', isset($config['country_code_phone']) ? $config['country_code_phone'] : '');
        $ui->assign('_title', 'eNiGoLabs');
        run_hook('customer_view_portal'); #HOOK
        $ui->display('customer/portal.tpl');
        break;
}
