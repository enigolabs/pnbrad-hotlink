<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

session_start();

// MikroTik / captive portal MAC + IP (canonical aliases accepted)
$__nux_mac_keys = ['nux-mac', 'mac', 'mac-address', 'mac_address', 'macaddress'];
foreach ($__nux_mac_keys as $__k) {
    if (isset($_GET[$__k]) && $_GET[$__k] !== '') {
        $__raw = trim((string)$_GET[$__k]);
        $__hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $__raw));
        $_SESSION['nux-mac'] = (strlen($__hex) === 12)
            ? implode(':', str_split($__hex, 2))
            : $__raw;
        break;
    }
}
unset($__nux_mac_keys, $__k, $__raw, $__hex);

$__nux_ip_keys = ['nux-ip', 'ip', 'uamip', 'client-ip', 'client_ip'];
foreach ($__nux_ip_keys as $__k) {
    if (isset($_GET[$__k]) && $_GET[$__k] !== '') {
        $_SESSION['nux-ip'] = trim((string)$_GET[$__k]);
        break;
    }
}
unset($__nux_ip_keys, $__k);

if(isset($_GET['nux-router']) && !empty($_GET['nux-router'])){
    $_SESSION['nux-router'] = $_GET['nux-router'];
}

//get chap id and chap challenge
if(isset($_GET['nux-key']) && !empty($_GET['nux-key'])){
    $_SESSION['nux-key'] = $_GET['nux-key'];
}
//get mikrotik hostname
if(isset($_GET['nux-hostname']) && !empty($_GET['nux-hostname'])){
    $_SESSION['nux-hostname'] = $_GET['nux-hostname'];
}
require_once 'system/vendor/autoload.php';
require_once 'system/boot.php';
App::_run();
