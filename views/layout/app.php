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
  <div class="page-layout">
    <div class="page-content">
    <?= $this->yield('content') ?>
    </div>
    <?php if (!empty($this->members) || !empty($this->collections)): ?>
    <aside class="sidebar">
      <?php if (!empty($this->members)): ?>
      <section class="sidebar-section">
        <h3 class="sidebar-heading">Members</h3>
        <ul class="sidebar-list">
          <?php foreach ($this->members as $m): ?>
          <li><a href="/ep/profile/<?= (int)$m['id'] ?>"><?= $this->escape($m['fullName'] ?: $m['email']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
      <?php if (!empty($this->collections)): ?>
      <section class="sidebar-section">
        <h3 class="sidebar-heading">Collections</h3>
        <ul class="sidebar-list">
          <?php foreach ($this->collections as $c): ?>
          <li>
            <a href="/collection/<?= (int)$c['groupId'] ?>"><?= $this->escape($c['name']) ?></a>
            <span class="sidebar-count"><?= (int)$c['padCount'] ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>
</main>
<footer class="site-footer">
  <div class="container">
    <p>Hackpad Viewer &mdash; 唯讀模式</p>
  </div>
</footer>
</body>
</html>
