<?php
include(__DIR__ . '/init.inc.php');

MiniEngine::dispatch(function($uri){
    if ($uri == '/robots.txt') {
        return ['index', 'robots'];
    }
    // /ep/* routes (login, logout, OAuth callback)
    if (strpos($uri, '/ep/') === 0) {
        return null; // let default dispatcher handle ep/account/...
    }
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
