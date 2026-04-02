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

        // Look up user in hackpad's pro_accounts
        $account = HackpadHelper::findUserByEmail($userInfo['email']);

        if (!$account) {
            // User has a Google account but no hackpad account
            MiniEngine::setSession('auth_error', 'no_account');
            MiniEngine::setSession('auth_email', $userInfo['email']);
            return $this->redirect('/ep/account/sign-in?error=no_account');
        }

        // Store user in session
        MiniEngine::setSession('user_id',    $account['id']);
        MiniEngine::setSession('user_email', $account['email']);
        MiniEngine::setSession('user_name',  $account['fullName']);

        // If returnTo is on a specific subdomain (encoded in state), redirect there
        // Otherwise redirect to current domain
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
}
