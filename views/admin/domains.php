<?php
$this->yield_start('title'); echo 'Admin – Workspaces'; $this->yield_end();
$this->yield_start('content');
?>
<div class="admin-header">
  <h1>Workspaces <span class="admin-count"><?= count($this->domains) ?></span></h1>
  <a href="/admin" class="admin-back">← Admin</a>
</div>
<table class="admin-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Subdomain</th>
      <th>Name</th>
      <th>Public</th>
      <th>Members</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($this->domains as $d): ?>
    <tr>
      <td><?= (int)$d['id'] ?></td>
      <td>
        <?php if ($d['subDomain'] !== '<<private-network>>'): ?>
          <a href="<?= $this->escape(HackpadHelper::getDomainUrl($d['subDomain'])) ?>" target="_blank">
            <?= $this->escape($d['subDomain']) ?>
          </a>
        <?php else: ?>
          <em><?= $this->escape($d['subDomain']) ?></em>
        <?php endif; ?>
      </td>
      <td><?= $this->escape($d['orgName']) ?></td>
      <td><?= $d['isPublic'] ? '✅ 公開' : '🔒 私有' ?></td>
      <td><?= (int)$d['memberCount'] ?></td>
      <td><?= $this->escape(substr($d['createdDate'] ?? '', 0, 10)) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php $this->yield_end(); echo $this->partial('layout/app'); ?>
