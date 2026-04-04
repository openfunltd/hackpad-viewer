<?php
$this->yield_start('title'); echo 'Admin'; $this->yield_end();
$this->yield_start('content');
?>
<div class="admin-header">
  <h1>Admin Panel</h1>
</div>
<div class="admin-cards">
  <a class="admin-card" href="/admin/domains">
    <div class="admin-card-number"><?= (int)$this->domainCount ?></div>
    <div class="admin-card-label">Workspaces</div>
  </a>
  <a class="admin-card" href="/admin/users">
    <div class="admin-card-number"><?= number_format((int)$this->userCount) ?></div>
    <div class="admin-card-label">Users</div>
  </a>
  <a class="admin-card" href="/admin/pads">
    <div class="admin-card-number">📋</div>
    <div class="admin-card-label">文章管理</div>
  </a>
</div>
<?php $this->yield_end(); echo $this->partial('layout/app'); ?>
