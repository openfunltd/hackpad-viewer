<?php
include(__DIR__ . '/init.inc.php');

MiniEngine::dispatch(function($uri){
    if ($uri == '/robots.txt') {
        return ['index', 'robots'];
    }
    // /collection/{groupId}
    if (preg_match('#^/collection/(\d+)$#', $uri, $m)) {
        return ['collection', 'show', [(int)$m[1]]];
    }
    // /ep/profile/{userId}
    if (preg_match('#^/ep/profile/(\d+)$#', $uri, $m)) {
        return ['profile', 'show', [(int)$m[1]]];
    }
    // /ep/* routes (login, logout, OAuth callback)
    if ($uri === '/ep/account/sign-in')    return ['ep', 'accountSignIn'];
    if ($uri === '/ep/account/sign-out')   return ['ep', 'accountSignOut'];
    if ($uri === '/ep/account/openid')     return ['ep', 'accountOpenid'];
    if ($uri === '/ep/account/google-login') return ['ep', 'accountGoogleLogin'];
    // /{padSlug} — pad view (single path segment, not empty)
    if (preg_match('#^/([^/]+)$#', $uri, $m)) {
        return ['pad', 'show', [urldecode($m[1])]];
    }
    // / — index (pad list)
    if ($uri === '/') {
        return ['index', 'index'];
    }
    return null;
});
