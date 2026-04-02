<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->yield('title') ?> - <?= $this->escape($this->domain['orgName'] ?? 'Hackpad') ?></title>
<link rel="stylesheet" href="/static/style.css">
<?= $this->yield('head') ?>
</head>
<body>
<header class="site-header">
  <div class="container">
    <a class="site-name" href="/"><?= $this->escape($this->domain['orgName'] ?? 'Hackpad') ?></a>
    <nav class="header-nav">
      <?php if ($this->user): ?>
        <span class="user-name"><?= $this->escape($this->user['fullName']) ?></span>
        <a href="/ep/account/sign-out">登出</a>
      <?php else: ?>
        <a href="/ep/account/sign-in">登入</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container">
<?= $this->yield('content') ?>
</main>
<footer class="site-footer">
  <div class="container">
    <p>Hackpad Viewer &mdash; 唯讀模式</p>
  </div>
</footer>
</body>
</html>
