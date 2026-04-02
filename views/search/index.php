<?php
$this->yield_start('title'); echo '搜尋'; $this->yield_end();
$this->yield_start('content');
$q = $this->escape($this->q ?? '');
?>
<div class="search-header">
  <form class="search-form" method="get" action="/search">
    <input class="search-input" type="text" name="q" value="<?= $q ?>" placeholder="搜尋文章…" autofocus>
    <button type="submit">搜尋</button>
  </form>
</div>

<?php if (!empty($this->error)): ?>
  <p class="search-error"><?= $this->escape($this->error) ?></p>
<?php elseif ($this->q !== ''): ?>
  <p class="search-summary">
    <?php if ($this->total === 0): ?>
      找不到「<strong><?= $q ?></strong>」的相關文章。
    <?php else: ?>
      找到 <strong><?= number_format($this->total) ?></strong> 篇文章
    <?php endif; ?>
  </p>

  <?php if (!empty($this->hits)): ?>
  <ul class="search-results">
    <?php foreach ($this->hits as $h): ?>
    <li class="search-result">
      <a class="search-result-title" href="<?= $this->escape($h['url']) ?>">
        <?php if ($h['hlTitle']): ?>
          <?= $h['hlTitle'] ?>
        <?php else: ?>
          <?= $this->escape($h['title']) ?>
        <?php endif; ?>
      </a>
      <?php if ($h['hlBody']): ?>
        <div class="search-result-snippet"><?= $h['hlBody'] ?></div>
      <?php endif; ?>
      <?php if ($h['lastedit']): ?>
        <div class="search-result-meta"><?= $this->escape(substr($h['lastedit'], 0, 10)) ?></div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($this->totalPages > 1): ?>
  <nav class="pagination">
    <?php if ($this->page > 1): ?>
      <a class="page-btn" href="?q=<?= urlencode($this->q) ?>&page=<?= $this->page - 1 ?>">← 上一頁</a>
    <?php endif; ?>
    <span class="page-info"><?= $this->page ?> / <?= $this->totalPages ?></span>
    <?php if ($this->page < $this->totalPages): ?>
      <a class="page-btn" href="?q=<?= urlencode($this->q) ?>&page=<?= $this->page + 1 ?>">下一頁 →</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php $this->yield_end(); echo $this->partial('layout/app'); ?>
