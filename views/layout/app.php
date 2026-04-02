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
      <form class="header-search" method="get" action="/search">
        <input type="text" name="q" placeholder="搜尋…" value="<?= $this->escape($_GET['q'] ?? '') ?>">
        <button type="submit">🔍</button>
      </form>
      <?php if ($this->user): ?>
        <div class="user-menu">
          <span class="user-name"><?= $this->escape($this->user['fullName']) ?> ▾</span>
          <div class="user-dropdown">
            <?php if ($GLOBALS['_isSiteAdmin']): ?>
              <div class="user-dropdown-section">Admin</div>
              <a href="/admin">⚙ Admin Panel</a>
              <div class="user-dropdown-divider"></div>
            <?php endif; ?>
            <?php foreach ($GLOBALS['_userDomains'] as $wd): ?>
              <a href="<?= $this->escape(HackpadHelper::getDomainUrl($wd['subDomain'])) ?>">
                <?= $this->escape($wd['orgName'] ?: $wd['subDomain']) ?>
              </a>
            <?php endforeach; ?>
            <?php if (empty($GLOBALS['_userDomains'])): ?>
              <span class="user-dropdown-empty">無可存取的 workspace</span>
            <?php endif; ?>
            <div class="user-dropdown-divider"></div>
            <a href="/ep/account/sign-out">登出</a>
          </div>
        </div>
      <?php else: ?>
        <a href="/ep/account/sign-in">登入</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container<?= !empty($this->wideContainer) ? ' container--wide' : '' ?>">
  <div class="page-layout">
    <div class="page-content">
    <?= $this->yield('content') ?>
    </div>
    <?php if (!empty($this->padContributors) || !empty($this->padToc)): ?>
    <aside class="sidebar">
      <?php if (!empty($this->padContributors)): ?>
      <section class="sidebar-section">
        <h3 class="sidebar-heading">參與者</h3>
        <ul class="sidebar-list sidebar-contributors">
          <?php foreach ($this->padContributors as $c): ?>
          <li>
            <span class="contributor-dot" style="background:<?= $this->escape($c['color']) ?>"></span>
            <a href="/ep/profile/<?= (int)$c['id'] ?>"><?= $this->escape($c['name']) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
      <?php if (!empty($this->padToc)): ?>
      <section class="sidebar-section">
        <h3 class="sidebar-heading">目錄</h3>
        <ul class="sidebar-toc">
          <?php foreach ($this->padToc as $item): ?>
          <li class="toc-level-<?= (int)$item['level'] ?>">
            <a href="#<?= $this->escape($item['id']) ?>"><?= $this->escape($item['text']) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </aside>
    <?php elseif (!empty($this->members) || !empty($this->collections)): ?>
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
    <p>Hackpad Viewer &mdash; 唯讀模式 &mdash; 由 <a href="https://openfun.tw" target="_blank">歐噴有限公司 openfun.tw</a> 維運 &mdash; 程式碼以 BSD 開源於 <a href="https://github.com/openfunltd/hackpad-viewer" target="_blank">github.com/openfunltd/hackpad-viewer</a></p>
  </div>
</footer>
</body>
</html>
