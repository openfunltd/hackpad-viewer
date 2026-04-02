<?php
$this->yield_start('title');
echo '登入';
$this->yield_end();

$this->yield_start('content');
$cont = $this->escape($this->cont ?? '/');
$error = $_GET['error'] ?? '';
$errorMessages = [
    'no_account'    => '您的 Google 帳號未與任何 Hackpad 帳號關聯，無法登入。',
    'auth_failed'   => 'Google 登入失敗，請再試一次。',
    'invalid_state' => '登入請求已過期，請重新嘗試。',
    'cancelled'     => '已取消登入。',
];
?>
<div class="signin-box">
  <h1>登入 Hackpad</h1>
  <?php if ($error && isset($errorMessages[$error])): ?>
    <p class="signin-error"><?= $this->escape($errorMessages[$error]) ?></p>
  <?php endif; ?>
  <a class="btn-google-login" href="/ep/account/google-login?cont=<?= $cont ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
      <path fill="#EA4335" d="M24 9.5c3.14 0 5.95 1.08 8.17 2.86l6.1-6.1C34.46 3.1 29.5 1 24 1 14.82 1 7.07 6.48 3.64 14.22l7.1 5.52C12.5 13.67 17.8 9.5 24 9.5z"/>
      <path fill="#4285F4" d="M46.52 24.5c0-1.64-.15-3.22-.42-4.74H24v8.98h12.67c-.55 2.93-2.2 5.41-4.67 7.08l7.18 5.57C43.24 37.3 46.52 31.36 46.52 24.5z"/>
      <path fill="#FBBC05" d="M10.74 28.26A14.55 14.55 0 0 1 9.5 24c0-1.48.25-2.91.7-4.26l-7.1-5.52A23.94 23.94 0 0 0 0 24c0 3.87.93 7.52 2.56 10.74l8.18-6.48z"/>
      <path fill="#34A853" d="M24 47c5.5 0 10.12-1.82 13.5-4.94l-7.18-5.57c-1.98 1.33-4.5 2.11-6.32 2.11-6.2 0-11.5-4.17-13.26-9.74l-8.18 6.48C7.07 41.52 14.82 47 24 47z"/>
      <path fill="none" d="M0 0h48v48H0z"/>
    </svg>
    使用 Google 帳號登入
  </a>
  <p class="signin-note">僅限原有 Hackpad 帳號，不開放新帳號註冊。</p>
</div>
<?php
$this->yield_end();
echo $this->partial('layout/app');
