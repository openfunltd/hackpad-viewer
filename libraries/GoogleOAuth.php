<?php
/**
 * Google OAuth2 helper for hackpad-viewer.
 *
 * Uses Google OAuth2 with scopes: openid, email, profile.
 * After callback, looks up or creates a session record keyed by email.
 */
class GoogleOAuth
{
    const AUTH_URL     = 'https://accounts.google.com/o/oauth2/auth';
    const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * Build the redirect URL to Google's OAuth2 consent screen.
     */
    public static function buildAuthUrl(string $redirectUri, string $state = ''): string
    {
        $params = [
            'client_id'     => getenv('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for user info.
     * Returns ['email', 'name', 'picture'] or false on failure.
     */
    public static function exchangeCode(string $code, string $redirectUri): array|false
    {
        $tokenData = self::fetchToken($code, $redirectUri);
        if (!$tokenData || empty($tokenData['access_token'])) {
            return false;
        }

        $userInfo = self::fetchUserInfo($tokenData['access_token']);
        if (!$userInfo || empty($userInfo['email'])) {
            return false;
        }

        return [
            'email'   => $userInfo['email'],
            'name'    => $userInfo['name'] ?? $userInfo['email'],
            'picture' => $userInfo['picture'] ?? '',
        ];
    }

    private static function fetchToken(string $code, string $redirectUri): array|false
    {
        $postData = http_build_query([
            'code'          => $code,
            'client_id'     => getenv('GOOGLE_CLIENT_ID'),
            'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 15,
        ]]);

        $result = @file_get_contents(self::TOKEN_URL, false, $ctx);
        if ($result === false) return false;

        return json_decode($result, true) ?: false;
    }

    private static function fetchUserInfo(string $accessToken): array|false
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer {$accessToken}\r\n",
            'timeout' => 15,
        ]]);

        $result = @file_get_contents(self::USERINFO_URL, false, $ctx);
        if ($result === false) return false;

        return json_decode($result, true) ?: false;
    }

    /**
     * Generate the OAuth2 callback URL.
     * Always points to hackpad.tw (primary domain) regardless of subdomain.
     */
    public static function getCallbackUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // Strip leading separator (. or -) to get the bare domain
        $base   = ltrim(getenv('HACKPAD_PRIMARY_DOMAIN') ?: '.hackpad.tw', '.-');
        return $scheme . '://' . $base . '/ep/account/openid';
    }

    /**
     * Generate a CSRF state token and store it in the session.
     */
    public static function generateState(string $returnTo = '/'): string
    {
        $nonce = bin2hex(random_bytes(16));
        $state = base64_encode(json_encode(['nonce' => $nonce, 'return_to' => $returnTo]));
        MiniEngine::setSession('oauth_nonce', $nonce);
        return $state;
    }

    /**
     * Validate state and return the return_to URL, or false if invalid.
     */
    public static function validateState(string $state): string|false
    {
        $decoded = json_decode(base64_decode($state), true);
        if (!$decoded || empty($decoded['nonce'])) return false;

        $expected = MiniEngine::getSession('oauth_nonce');
        if ($decoded['nonce'] !== $expected) return false;

        MiniEngine::deleteSession('oauth_nonce');
        return $decoded['return_to'] ?? '/';
    }
}
