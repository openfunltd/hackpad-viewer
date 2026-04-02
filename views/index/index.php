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
</div>
<?php else: ?>
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
      <li class="pad-list-item">
        <a class="pad-list-title" href="<?= $this->escape(HackpadHelper::padUrl($pad['localPadId'], $pad['title'])) ?>">
          <?= $this->escape($pad['title'] ?: $pad['localPadId']) ?>
        </a>
        <div class="pad-list-meta">
          <?= $this->escape(HackpadHelper::formatDate($pad['lastEditedDate'])) ?>
        </div>
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
