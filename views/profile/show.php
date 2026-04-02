<?php
$this->yield_start('title');
echo $this->escape($this->member['fullName'] ?: $this->member['email']);
$this->yield_end();

$this->yield_start('content');
$displayName = $this->member['fullName'] ?: $this->member['email'];
?>
<h2 class="page-heading">
  <a href="/" class="back-link">←</a>
  <?= $this->escape($displayName) ?>
</h2>
<?php if (empty($this->pads)): ?>
  <p style="color:#aaa;">此成員目前沒有公開的 pad。</p>
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
<?php endif; ?>
<?php
$this->yield_end();
echo $this->partial('layout/app');
