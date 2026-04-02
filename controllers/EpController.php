<?php
/**
 * EpController handles /ep/* routes:
 *   /ep/account/sign-in   - show sign-in page
 *   /ep/account/sign-out  - clear session
 *   /ep/account/openid    - Google OAuth2 callback
 */
class EpController extends MiniEngine_Controller
{
    public function init()
    {
        $this->view->domain = HackpadHelper::getCurrentDomain();
        $this->view->user   = HackpadHelper::getCurrentUser();
    }

    /**
     * /ep/account/sign-in
     */
    public function accountSignInAction()
    {
        if (MiniEngine::getSession('user_id')) {
            return $this->redirect($_GET['cont'] ?? '/');
        }
        $this->view->cont = $_GET['cont'] ?? '/';
    }

    /**
     * /ep/account/sign-out
     */
    public function accountSignOutAction()
    {
        MiniEngine::deleteSession('user_id');
        MiniEngine::deleteSession('user_email');
        MiniEngine::deleteSession('user_name');
        return $this->redirect('/');
    }

    /**
     * /ep/account/openid  - Google OAuth2 callback
     */
    public function accountOpenidAction()
    {
        // Handle the Google OAuth2 redirect
        $code  = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error || !$code) {
            return $this->redirect('/ep/account/sign-in?error=cancelled');
        }

        // Validate state/CSRF
        $returnTo = GoogleOAuth::validateState($state);
        if ($returnTo === false) {
            return $this->redirect('/ep/account/sign-in?error=invalid_state');
        }

        // Exchange code for user info
        $callbackUrl = GoogleOAuth::getCallbackUrl();
        $userInfo    = GoogleOAuth::exchangeCode($code, $callbackUrl);

        if (!$userInfo) {
            return $this->redirect('/ep/account/sign-in?error=auth_failed');
        }

        // Apply EMAIL_ALIASES mapping (format: "google@a.com:hackpad@b.com,...")
        $lookupEmail = self::resolveEmailAlias($userInfo['email']);

        // Look up user in hackpad's pro_accounts
        $account = HackpadHelper::findUserByEmail($lookupEmail);

        if (!$account) {
            // User has a Google account but no hackpad account
            MiniEngine::setSession('auth_error', 'no_account');
            MiniEngine::setSession('auth_email', $userInfo['email']);
            return $this->redirect('/ep/account/sign-in?error=no_account');
        }

        // Store user in session
        MiniEngine::setSession('user_id',    $account['id']);
        MiniEngine::setSession('user_email', $account['email']);
        MiniEngine::setSession('user_name',  html_entity_decode($account['fullName'], ENT_QUOTES, 'UTF-8'));

        // Validate returnTo to prevent open redirect.
        // Accept absolute URLs only if they belong to our session domain.
        $sessionDomain = ltrim(getenv('SESSION_DOMAIN') ?: '', '.');
        if ($sessionDomain && strpos($returnTo, '://') !== false) {
            $host = parse_url($returnTo, PHP_URL_HOST);
            if (!$host || !str_ends_with($host, $sessionDomain)) {
                $returnTo = '/'; // Reject external URLs
            }
        }

        return $this->redirect($returnTo);
    }

    /**
     * /ep/account/google-login  - initiate Google login
     */
    public function accountGoogleLoginAction()
    {
        $cont     = $_GET['cont'] ?? '/';
        $state    = GoogleOAuth::generateState($cont);
        $authUrl  = GoogleOAuth::buildAuthUrl(GoogleOAuth::getCallbackUrl(), $state);
        return $this->redirect($authUrl);
    }

    /**
     * Resolve a Google login email to a hackpad email via EMAIL_ALIASES.
     * Config format (in config.inc.php):
     *   putenv('EMAIL_ALIASES=google@example.com:hackpad@example.com,...');
     */
    private static function resolveEmailAlias(string $email): string
    {
        $raw = getenv('EMAIL_ALIASES');
        if (!$raw) return $email;
        foreach (explode(',', $raw) as $pair) {
            [$from, $to] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($to && strtolower($from) === strtolower($email)) {
                return $to;
            }
        }
        return $email;
    }
}
