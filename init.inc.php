<?php

define('MINI_ENGINE_LIBRARY', true);
define('MINI_ENGINE_ROOT', __DIR__);
require_once(__DIR__ . '/mini-engine.php');
if (file_exists(__DIR__ . '/config.inc.php')) {
    include(__DIR__ . '/config.inc.php');
} elseif (file_exists("/srv/config/hackpad-viewer.inc.php")) {
    include("/srv/config/hackpad-viewer.inc.php");
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

// Private domains require authentication and domain membership.
// Exception: a pad with guestPolicy=allow/link is publicly accessible
// even within a private domain (matching original hackpad behaviour).
// Allow /ep/* (sign-in, OAuth callback) and /robots.txt through without login.
$_reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (strpos($_reqUri, '/ep/') !== 0 && $_reqUri !== '/robots.txt') {
    $_domain = HackpadHelper::getCurrentDomain();
    if ($_domain && !HackpadHelper::isDomainPublic((int)$_domain['id'])) {
        // Check if this is a pad URL with public guestPolicy → bypass gate
        $_padPublic = false;
        if (preg_match('#^/([^/]+)$#', $_reqUri, $_pm)) {
            $_localPadId = HackpadHelper::extractPadId(urldecode($_pm[1]));
            $_globalPadId = (int)$_domain['id'] . '$' . $_localPadId;
            $_gpStmt = MiniEngine::getDb()->prepare('SELECT guestPolicy FROM PAD_SQLMETA WHERE id=? LIMIT 1');
            $_gpStmt->execute([$_globalPadId]);
            $_gp = $_gpStmt->fetchColumn();
            $_padPublic = in_array($_gp, ['allow', 'link'], true);
        }

        if (!$_padPublic) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $_userEmail = MiniEngine::getSession('user_email');
            if (!$_userEmail) {
                // Not logged in → redirect to sign-in
                $fullUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/');
                header('Location: /ep/account/sign-in?cont=' . urlencode($fullUrl));
                exit;
            }
            if (!HackpadHelper::isEmailDomainMember($_userEmail, (int)$_domain['id'])) {
                // Logged in but not a member of this workspace
                http_response_code(403);
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access Denied</title></head><body>';
                echo '<h2>Access Denied</h2>';
                echo '<p>Your account (<strong>' . htmlspecialchars($_userEmail) . '</strong>) is not a member of this workspace.</p>';
                echo '<p><a href="/ep/account/sign-out">Sign out</a></p>';
                echo '</body></html>';
                exit;
            }
        }
    }
}

// Pre-load user's accessible domains for the nav dropdown (available as $GLOBALS['_userDomains'])
$_sessionEmail = MiniEngine::getSession('user_email');
$GLOBALS['_userDomains']   = $_sessionEmail ? HackpadHelper::getUserDomains($_sessionEmail) : [];
$GLOBALS['_isSiteAdmin']   = $_sessionEmail ? HackpadHelper::isSiteAdmin($_sessionEmail) : false;
$_currentDomainId = HackpadHelper::getCurrentDomain()['id'] ?? 0;
$GLOBALS['_isDomainAdmin'] = ($_sessionEmail && $_currentDomainId)
    ? HackpadHelper::isDomainAdmin($_sessionEmail, (int)$_currentDomainId)
    : false;
