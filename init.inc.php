<?php

define('MINI_ENGINE_LIBRARY', true);
define('MINI_ENGINE_ROOT', __DIR__);
require_once(__DIR__ . '/mini-engine.php');
if (file_exists(__DIR__ . '/config.inc.php')) {
    include(__DIR__ . '/config.inc.php');
}
set_include_path(
    __DIR__ . '/libraries'
    . PATH_SEPARATOR . __DIR__ . '/models'
);
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}
MiniEngine::initEnv();
// Force UTF-8 on the MySQL connection (mini-engine doesn't set charset in DSN)
if (getenv('DATABASE_URL')) {
    MiniEngine::getDb()->exec('SET NAMES utf8mb4');
}

// Private domains require authentication.
// Allow /ep/* (sign-in, OAuth callback) and /robots.txt through without login.
$_reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (strpos($_reqUri, '/ep/') !== 0 && $_reqUri !== '/robots.txt') {
    $_domain = HackpadHelper::getCurrentDomain();
    if ($_domain && !HackpadHelper::isDomainPublic((int)$_domain['id'])
        && !MiniEngine::getSession('user_id')
    ) {
        header('Location: /ep/account/sign-in?cont=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}
