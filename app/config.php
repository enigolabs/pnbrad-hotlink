<?php

$db_user = getenv('DB_USERNAME', true)?: getenv('DB_USERNAME');
$db_pass = getenv('DB_PASSWORD', true)?: getenv('DB_PASSWORD');
$db_name = getenv('DB_DATABASE', true)?: getenv('DB_DATABASE');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('APP_URL', $protocol . $host . $baseDir);

$_app_stage = 'Live';

$db_host = 'localhost';
$db_user = $db_user;
$db_password = $db_pass;
$db_name = $db_name;

$radius_host = 'localhost';
$radius_user = $db_user;
$radius_pass = $db_pass;
$radius_name = $db_name;

if ($_app_stage!= 'Live') {
    error_reporting(E_ERROR);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(E_ERROR);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}