<?php

/**
 * Hotspot captive portal — MAC → customer persistence and auto-pass.
 * Canonical MAC storage: lowercase colon form (aa:bb:cc:dd:ee:ff).
 * Lookup accepts colon / no-colon / mixed-case input.
 */

class PortalMac
{
    /**
     * Normalize to canonical lowercase colon-separated MAC, or '' if invalid.
     */
    public static function normalize($raw)
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$raw));
        if (strlen($hex) !== 12) {
            return '';
        }
        return implode(':', str_split($hex, 2));
    }

    /**
     * Capture nux-mac / nux-ip and common aliases from GET into session.
     */
    public static function captureSession()
    {
        $macKeys = ['nux-mac', 'mac', 'mac-address', 'mac_address', 'macaddress'];
        foreach ($macKeys as $k) {
            if (!empty($_GET[$k])) {
                $norm = self::normalize($_GET[$k]);
                if ($norm !== '') {
                    $_SESSION['nux-mac'] = $norm;
                    break;
                }
                // keep raw if somehow non-standard length; still better than nothing
                $_SESSION['nux-mac'] = trim((string)$_GET[$k]);
                break;
            }
        }
        // Re-normalize whatever is already in session (e.g. from index.php)
        if (!empty($_SESSION['nux-mac'])) {
            $norm = self::normalize($_SESSION['nux-mac']);
            if ($norm !== '') {
                $_SESSION['nux-mac'] = $norm;
            }
        }

        $ipKeys = ['nux-ip', 'ip', 'uamip', 'client-ip', 'client_ip'];
        foreach ($ipKeys as $k) {
            if (!empty($_GET[$k])) {
                $_SESSION['nux-ip'] = trim((string)$_GET[$k]);
                break;
            }
        }
    }

    /**
     * Upsert MAC → customer_id. One MAC maps to one current customer.
     */
    public static function remember($customer_id)
    {
        self::captureSession();
        $mac = self::normalize(isset($_SESSION['nux-mac']) ? $_SESSION['nux-mac'] : '');
        if ($mac === '' || !$customer_id) {
            return false;
        }
        $ip = isset($_SESSION['nux-ip']) ? (string)$_SESSION['nux-ip'] : null;
        $now = date('Y-m-d H:i:s');

        $row = ORM::for_table('tbl_portal_macs')->where('mac', $mac)->find_one();
        if (!$row) {
            // Flexible fallback: match stored form that differs only by separators
            $hex = str_replace(':', '', $mac);
            $row = ORM::for_table('tbl_portal_macs')
                ->where_raw("REPLACE(LOWER(mac), ':', '') = ?", [$hex])
                ->find_one();
        }
        if ($row) {
            $row->mac = $mac;
            $row->customer_id = (int)$customer_id;
            $row->last_ip = $ip;
            $row->last_seen = $now;
            $row->save();
        } else {
            $row = ORM::for_table('tbl_portal_macs')->create();
            $row->mac = $mac;
            $row->customer_id = (int)$customer_id;
            $row->last_ip = $ip;
            $row->last_seen = $now;
            $row->created_at = $now;
            $row->save();
        }
        return true;
    }

    /**
     * Find customer row by session/GET MAC, or null.
     */
    public static function findCustomer()
    {
        self::captureSession();
        $mac = self::normalize(isset($_SESSION['nux-mac']) ? $_SESSION['nux-mac'] : '');
        if ($mac === '') {
            return null;
        }
        $map = ORM::for_table('tbl_portal_macs')->where('mac', $mac)->find_one();
        if (!$map) {
            $hex = str_replace(':', '', $mac);
            $map = ORM::for_table('tbl_portal_macs')
                ->where_raw("REPLACE(LOWER(mac), ':', '') = ?", [$hex])
                ->find_one();
        }
        if (!$map) {
            return null;
        }
        // Touch last-seen
        $map->last_ip = isset($_SESSION['nux-ip']) ? (string)$_SESSION['nux-ip'] : $map['last_ip'];
        $map->last_seen = date('Y-m-d H:i:s');
        $map->mac = $mac;
        $map->save();

        $customer = ORM::for_table('tbl_customers')->find_one($map['customer_id']);
        return $customer ? $customer : null;
    }

    /**
     * If MAC known, customer not Banned, and active package: login, connect, redirect home.
     * @return bool true if redirected (caller should stop)
     */
    public static function tryAutopass()
    {
        $customer = self::findCustomer();
        if (!$customer) {
            return false;
        }
        if ($customer['status'] == 'Banned') {
            return false;
        }
        $tur = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $customer['id'])
            ->where('status', 'on')
            ->find_one();
        if (!$tur) {
            return false;
        }

        // Login
        $_SESSION['uid'] = $customer['id'];
        User::setCookie($customer['id']);
        $customer->last_login = date('Y-m-d H:i:s');
        $customer->save();

        // Refresh MAC mapping last-seen
        self::remember($customer['id']);

        // Grant hotspot if session has MAC+IP (same as portal_try_device_connect)
        global $_app_stage;
        if (!empty($_SESSION['nux-mac']) && !empty($_SESSION['nux-ip'])) {
            $p = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
            if ($p) {
                $dvc = Package::getDevice($p);
                if ($_app_stage != 'demo' && file_exists($dvc)) {
                    try {
                        require_once $dvc;
                        (new $p['device'])->connect_customer(
                            $customer,
                            $_SESSION['nux-ip'],
                            $_SESSION['nux-mac'],
                            $tur['routers']
                        );
                    } catch (Exception $e) {
                        _log('PortalMac autopass connect_customer: ' . $e->getMessage());
                    }
                }
            }
        }

        r2(U . 'home');
        return true;
    }
}
