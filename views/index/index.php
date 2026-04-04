<?php
$this->yield_start('title');
echo $this->escape($this->domain['orgName'] ?? 'Hackpad');
$this->yield_end();

$this->yield_start('content');
?>
<?php if (!empty($this->showWelcome)): ?>
<div class="welcome-box">
  <h1>Hackpad 備份瀏覽器</h1>
  <p>
    此網站是 <strong>hackpad.tw</strong> 的唯讀備份，保存了 2015–2018 年間台灣公民社會在 Hackpad
    上留下的共筆內容，包含 g0v、各社群與個人工作區。
  </p>
  <p>
    各工作區的文章依原始權限設定保存：公開文章可直接瀏覽，私有工作區的文章需以原 Hackpad 帳號登入才能查看。
  </p>
  <a class="btn-primary" href="/ep/account/sign-in">登入查看我的文章</a>
  <p class="welcome-operator">本站由 <a href="https://openfun.tw" target="_blank">歐噴有限公司 openfun.tw</a> 維運</p>
</div>
<?php else: ?>
<?php
$subDomain = HackpadHelper::getSubdomain();
$oldUrl = $subDomain
    ? 'https://' . $subDomain . '.old.hackpad.tw'
    : 'https://old.hackpad.tw';
?>
<div class="migration-notice">
  <span class="migration-notice-icon">ℹ️</span>
  因為原有 hackpad 程式老舊加上 AI 爬蟲量日益增加，導致原有 hackpad.tw 服務不穩，自 2026-04-04 起，採用透過 Claude Sonnet 4.6 重新建置的 <strong>Hackpad 封存器</strong>來取代原有的 hackpad，您仍可至
  <a href="https://old.hackpad.tw" target="_blank">https://old.hackpad.tw</a>
  <?php if ($subDomain): ?>
    或 <a href="<?= $this->escape($oldUrl) ?>" target="_blank"><?= $this->escape($oldUrl) ?></a>
  <?php endif; ?>
  至舊版查看，舊版將於 <strong>2026-05-04</strong> 停用。
</div>
<h2 class="page-heading">
  <?= !empty($this->filterByCreator) ? '我的 Pads' : '最近的 Pads' ?>
</h2>
<?php if (empty($this->pads)): ?>
  <p style="color:#aaa;">
    <?= !empty($this->filterByCreator) ? '你在此 workspace 尚未建立任何 pad。' : '此 workspace 目前沒有公開的 pad。' ?>
  </p>
<?php else: ?>
  <ul class="pad-list">
    <?php foreach ($this->pads as $pad): ?>
      <?php $preview = $this->previews[$pad['localPadId']] ?? ''; ?>
      <li class="pad-list-item">
        <a class="pad-list-title" href="<?= $this->escape(HackpadHelper::padUrl($pad['localPadId'], $pad['title'])) ?>">
          <?= $this->escape($pad['title'] ?: $pad['localPadId']) ?>
        </a>
        <div class="pad-list-meta">
          <?= $this->escape(HackpadHelper::formatDate($pad['lastEditedDate'])) ?>
          <?php $creator = html_entity_decode($pad['creatorName'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
          <?php if ($creator !== ''): ?>
            &nbsp;&bull;&nbsp;<?= $this->escape($creator) ?>
          <?php endif; ?>
          <?php if (in_array($pad['guestPolicy'] ?? '', ['domain', 'deny'])): ?>
            &nbsp;<span class="badge-private">private</span>
          <?php endif; ?>
        </div>
        <?php if ($preview !== ''): ?>
        <div class="pad-list-preview"><?= $this->escape($preview) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($this->totalPages > 1): ?>
  <nav class="pagination">
    <?php if ($this->page > 1): ?>
      <a class="page-btn" href="?page=<?= $this->page - 1 ?>">← 上一頁</a>
    <?php endif; ?>
    <span class="page-info"><?= $this->page ?> / <?= $this->totalPages ?></span>
    <?php if ($this->page < $this->totalPages): ?>
      <a class="page-btn" href="?page=<?= $this->page + 1 ?>">下一頁 →</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php
$this->yield_end();
echo $this->partial('layout/app');
