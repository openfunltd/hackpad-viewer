<?php
$this->yield_start('title');
echo $this->escape($this->collection['name']);
$this->yield_end();

$this->yield_start('content');
?>
<h2 class="page-heading">
  <a href="/" class="back-link">←</a>
  <?= $this->escape($this->collection['name']) ?>
</h2>
<?php if (empty($this->pads)): ?>
  <p style="color:#aaa;">此 collection 目前沒有公開的 pad。</p>
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
        </div>
        <?php if ($preview !== ''): ?>
        <div class="pad-list-preview"><?= $this->escape($preview) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
$this->yield_end();
echo $this->partial('layout/app');
