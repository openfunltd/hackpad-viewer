<?php
$this->yield_start('title');
echo $this->escape($this->padTitle);
$this->yield_end();

$this->yield_start('content');
?>
<article class="pad-view">
  <header class="pad-header">
    <h1 class="pad-title"><?= $this->escape($this->padTitle) ?></h1>
    <div class="pad-meta">
      <?php if ($this->padMeta['lastEditedDate']): ?>
        <span class="pad-date">最後編輯：<?= $this->escape(HackpadHelper::formatDate($this->padMeta['lastEditedDate'])) ?></span>
      <?php endif; ?>
      <?php if ($this->padMeta['createdDate']): ?>
        <span class="pad-date">建立：<?= $this->escape(HackpadHelper::formatDate($this->padMeta['createdDate'])) ?></span>
      <?php endif; ?>
      <a class="pad-history-link" href="/<?= $this->escape($this->padMeta['localPadId']) ?>/history">歷史紀錄</a>
      <?php if (!empty($this->padCollections)): ?>
        <div class="pad-collections">
          <?php foreach ($this->padCollections as $c): ?>
            <a class="pad-collection-tag" href="/collection/<?= (int)$c['groupId'] ?>"><?= $this->escape($c['name']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <div class="pad-content">
    <?php if ($this->content !== false && $this->content !== ''): ?>
      <?= $this->content ?>
    <?php else: ?>
      <p class="pad-empty">（此文件沒有內容）</p>
    <?php endif; ?>
  </div>
</article>
<?php
$this->yield_end();
echo $this->partial('layout/app');
