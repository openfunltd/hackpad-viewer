<?php
$this->yield_start('title'); echo 'Admin – Users'; $this->yield_end();
$this->yield_start('content');
$q = $this->escape($this->search);
?>
<div class="admin-header">
  <h1>Users <span class="admin-count"><?= number_format($this->total) ?></span></h1>
  <a href="/admin" class="admin-back">← Admin</a>
</div>
<form class="admin-search" method="get" action="/admin/users">
  <input type="text" name="q" value="<?= $q ?>" placeholder="搜尋 email / 名稱…">
  <button type="submit">搜尋</button>
  <?php if ($this->search): ?><a href="/admin/users">清除</a><?php endif; ?>
</form>
<table class="admin-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Email</th>
      <th>Name</th>
      <th>Admin</th>
      <th>Workspace</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($this->users as $u): ?>
    <tr>
      <td><?= (int)$u['id'] ?></td>
      <td><?= $this->escape($u['email']) ?></td>
      <td><?= $this->escape(html_entity_decode($u['fullName'], ENT_QUOTES, 'UTF-8')) ?></td>
      <td><?= $u['isAdmin'] ? '✅' : '' ?></td>
      <td><?= $this->escape($u['subDomain']) ?></td>
      <td><?= $this->escape(substr($u['createdDate'] ?? '', 0, 10)) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if ($this->totalPages > 1): ?>
<div class="pagination">
  <?php if ($this->page > 1): ?>
    <a href="?page=<?= $this->page - 1 ?><?= $this->search ? '&q='.urlencode($this->search) : '' ?>">← 上一頁</a>
  <?php endif; ?>
  <span>第 <?= $this->page ?> / <?= $this->totalPages ?> 頁</span>
  <?php if ($this->page < $this->totalPages): ?>
    <a href="?page=<?= $this->page + 1 ?><?= $this->search ? '&q='.urlencode($this->search) : '' ?>">下一頁 →</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php $this->yield_end(); echo $this->partial('layout/app'); ?>
