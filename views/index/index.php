<?php
$this->yield_start('title');
echo $this->escape($this->domain['orgName'] ?? 'Hackpad');
$this->yield_end();

$this->yield_start('content');
?>
<h2 class="page-heading">最近的 Pads</h2>
<?php if (empty($this->pads)): ?>
  <p style="color:#aaa;">此 workspace 目前沒有公開的 pad。</p>
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
<?php
$this->yield_end();
echo $this->partial('layout/app');

