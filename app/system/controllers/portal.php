<?php

/**
 * Hotspot captive portal — packages first, phone-only identity, Paystack checkout.
 * Returning devices: MAC→customer (tbl_portal_macs) with active package auto-pass to home.
 * End-users never see a traditional username/password login form here.
 * Admin/staff login remains at /admin (unchanged).
 *
 * Phone normalization (documented assumption):
 * - Strip spaces, dashes, parentheses, dots
 * - Leading 00 → +
 * - Keep a single leading + then digits (E.164-ish), OR digits-only local form
 * - If Settings → country_code_phone is set AND the number has no + / no 00
 *   AND does not already start with that country code digits, prepend country_code_phone
 * - Username / password / lookup key = normalized phone (as stored)
 * - Email = {digits-only phone}@gmail.com (no +, spaces, or other punctuation)
 */

$action = isset($routes['1']) ? $routes['1'] : 'list';

/**
 * Normalize phone for account username / Paystack customer.
 */
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
    // 00intl → +intl
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
        // local digits → prepend configured country code (without forcing + lock-in)
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
        // Plans assigned to this router name, plus RADIUS plans
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
        // Keep password = phone for reconnect simplicity
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

switch ($action) {
    case 'buy':
        // POST: plan_id, phone, router optional
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

        // Gate Paystack before creating accounts so placeholder keys fail instantly
        global $config, $PAYMENTGATEWAY_PATH;
        $gateway = 'paystack';
        $actives = array_filter(array_map('trim', explode(',', isset($config['payment_gateway']) ? $config['payment_gateway'] : '')));
        if (!in_array($gateway, $actives, true) || !file_exists($PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . $gateway . '.php')) {
            r2(U . 'portal', 'e', 'Paystack is not enabled. Ask admin to configure Payment Gateway → Paystack.');
        }
        $sk = isset($config['paystack_secret_key']) ? (string)$config['paystack_secret_key'] : '';
        $is_placeholder = ($sk === '' || stripos($sk, 'REPLACE') !== false || stripos($sk, 'xxxxxxxx') !== false || substr($sk, 0, 3) !== 'sk_');
        if ($is_placeholder) {
            r2(U . 'portal', 'w', 'Paystack keys are placeholders. Ask admin to set real test/live keys under Payment Gateway → Paystack.');
        }
        include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . $gateway . '.php';
        paystack_validate_config();

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

        // Cancel previous unpaid without payment URL clutter; reuse unpaid same gateway if any
        $existing = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one();
        if ($existing && !empty($existing['pg_url_payment'])) {
            // Mark old unpaid as cancelled so user can buy again
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

        paystack_create_transaction($d, $user);
        break;

    case 'paid':
        // Return from Paystack — verify then redirect to Google
        global $PAYMENTGATEWAY_PATH, $config;
        $trx_id = isset($routes['2']) ? (int)$routes['2'] : 0;
        $ref = _get('reference'); // Paystack appends ?reference=

        $user_id = User::getID();
        if (!$user_id) {
            // Try recover from trx
            if ($trx_id > 0) {
                $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
                if ($trx) {
                    $c = ORM::for_table('tbl_customers')->find_one($trx['user_id']);
                    if ($c) {
                        portal_login_customer($c);
                        $user_id = $c['id'];
                    }
                }
            }
        }
        if (!$user_id) {
            r2(U . 'portal', 'e', Lang::T('Session expired. Use Reconnect with your phone number.'));
        }
        $user = User::_info($user_id);

        $trx = null;
        if ($trx_id > 0) {
            $trx = ORM::for_table('tbl_payment_gateway')
                ->where('user_id', $user_id)
                ->find_one($trx_id);
        }
        if (!$trx && $ref) {
            $trx = ORM::for_table('tbl_payment_gateway')
                ->where('gateway_trx_id', $ref)
                ->find_one();
        }
        if (!$trx) {
            r2(U . 'portal', 'e', Lang::T('Payment not found'));
        }

        include_once $PAYMENTGATEWAY_PATH . DIRECTORY_SEPARATOR . 'paystack.php';
        paystack_validate_config();
        $ok = paystack_get_status($trx, $user, true);

        if ($ok) {
            // refresh trx
            $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
            $customer = ORM::for_table('tbl_customers')->find_one($user_id);
            PortalMac::remember($customer['id']);
            portal_try_device_connect($customer);
            header('Location: https://www.google.com');
            exit();
        }

        // Still unpaid / pending
        r2(U . 'portal', 'w', Lang::T('Transaction still unpaid.'));
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
                // also try phonenumber match
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
        // Returning hotspot client: known MAC + active package → login, connect, status page
        PortalMac::captureSession();
        if (PortalMac::tryAutopass()) {
            break;
        }
        // Unknown MAC or no active package: show packages + Reconnect as before
        $csrf_token = Csrf::generateAndStoreToken();
        $plans = portal_load_hotspot_plans();
        $ui->assign('csrf_token', $csrf_token);
        $ui->assign('plans', $plans);
        $ui->assign('country_code_phone', isset($config['country_code_phone']) ? $config['country_code_phone'] : '');
        $ui->assign('_title', Lang::T('Hotspot Packages'));
        run_hook('customer_view_portal'); #HOOK
        $ui->display('customer/portal.tpl');
        break;
}
