<?php
$this->yield_start('title');
$is404 = ($this->error instanceof MiniEngine_Controller_NotFound);
echo $is404 ? '404 找不到頁面' : '500 伺服器錯誤';
$this->yield_end();

$this->yield_start('content');
?>
<div class="error-box">
  <?php if ($is404): ?>
    <h1>404</h1>
    <h2>找不到頁面</h2>
    <p><?= $this->escape($this->error->getMessage() ?: '您要求的頁面不存在。') ?></p>
  <?php else: ?>
    <h1>500</h1>
    <h2>伺服器發生錯誤</h2>
    <p>很抱歉，發生了未預期的錯誤，請稍後再試。</p>
    <?php if (getenv('ENV') !== 'production'): ?>
      <pre style="text-align:left;background:#f4f4f2;padding:1em;border-radius:4px;font-size:0.85em;overflow:auto">
<?= $this->escape($this->error->getMessage()) . "\n" . $this->escape($this->error->getTraceAsString()) ?>
      </pre>
    <?php endif; ?>
  <?php endif; ?>
  <p><a href="/">← 回首頁</a></p>
</div>
<?php
$this->yield_end();
echo $this->partial('layout/app');
