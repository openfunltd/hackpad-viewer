<?php
$this->yield_start('title');
echo $this->escape($this->padTitle) . ' — History';
$this->yield_end();

$this->yield_start('content');
?>
<div class="pad-view">
  <div class="pad-header">
    <h1 class="pad-title"><?= $this->escape($this->padTitle) ?></h1>
    <div class="pad-meta">
      <a href="/<?= $this->escape($this->padSlug) ?>">← 回到文章</a>
    </div>
  </div>

  <h2 style="font-size:1.1rem;margin:0 0 1rem;color:#555;">編輯歷史</h2>

  <?php if (empty($this->sessions)): ?>
    <p style="color:#aaa;">無歷史紀錄。</p>
  <?php else: ?>
  <table class="history-table">
    <thead>
      <tr>
        <th>時間</th>
        <th>作者</th>
        <th>版本</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($this->sessions as $s): ?>
      <tr>
        <td class="history-time">
          <?php
            $start = date('Y-m-d H:i', $s['startTime'] / 1000);
            $end   = date('H:i', $s['endTime'] / 1000);
            echo $this->escape($start);
            if ($s['fromRev'] !== $s['toRev']) echo ' – ' . $this->escape($end);
          ?>
        </td>
        <td>
          <span class="history-author" style="color:<?= $this->escape($s['authorColor']) ?>">
            <?= $this->escape($s['authorName']) ?>
          </span>
        </td>
        <td class="history-rev">
          <?php if ($s['fromRev'] === $s['toRev']): ?>
            r<?= $s['fromRev'] ?>
          <?php else: ?>
            r<?= $s['fromRev'] ?> – r<?= $s['toRev'] ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php
$this->yield_end();
echo $this->partial('layout/app');
