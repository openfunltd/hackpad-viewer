<?php
$this->yield_start('title');
echo '管理員 — 文章列表 — ' . $this->escape($this->domain['orgName'] ?? '');
$this->yield_end();

$this->yield_start('content');
?>
<div class="admin-pad-header">
  <h2 class="page-heading">📋 文章管理列表</h2>
  <p class="admin-pad-hint">
    共 <?= count($this->pads) ?> 篇 &nbsp;·&nbsp; 僅 domain 管理員可見
    &nbsp;·&nbsp; <a href="/admin/">← 管理首頁</a>
  </p>
  <p class="admin-pad-hint">
    🔴 高風險：帳號建立後 5 分鐘內創文章 + 只登入一次 &nbsp;|&nbsp;
    🟡 中風險：符合其中一項
  </p>
</div>

<div class="admin-pad-table-wrap">
<table class="admin-pad-table">
  <thead>
    <tr>
      <th>風險</th>
      <th>文章代碼</th>
      <th>標題</th>
      <th>建立時間</th>
      <th>最後編輯</th>
      <th>作者</th>
      <th>帳號年齡</th>
      <th>一次性帳號</th>
      <th>權限</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($this->pads as $pad): ?>
    <tr class="<?= $pad['spamRisk'] === 'high' ? 'spam-high' : ($pad['spamRisk'] === 'medium' ? 'spam-medium' : '') ?>">
      <td class="spam-risk-col">
        <?php if ($pad['spamRisk'] === 'high'): ?>🔴
        <?php elseif ($pad['spamRisk'] === 'medium'): ?>🟡
        <?php else: ?><span class="no-data">—</span>
        <?php endif; ?>
      </td>
      <td class="pad-code">
        <a href="/<?= $this->escape($pad['localPadId']) ?>" target="_blank">
          <?= $this->escape($pad['localPadId']) ?>
        </a>
      </td>
      <td class="pad-title-col">
        <a href="<?= $this->escape(HackpadHelper::padUrl($pad['localPadId'], $pad['title'])) ?>" target="_blank">
          <?= $this->escape($pad['title'] ?: '（無標題）') ?>
        </a>
      </td>
      <td class="pad-date-col"><?= $this->escape(HackpadHelper::formatDate($pad['createdDate'])) ?></td>
      <td class="pad-date-col"><?= $this->escape(HackpadHelper::formatDate($pad['lastEditedDate'])) ?></td>
      <td class="pad-author-col">
        <?php if ($pad['creatorName']): ?>
          <a href="/ep/profile/<?= (int)$pad['creatorId'] ?>"><?= $this->escape($pad['creatorName']) ?></a>
        <?php elseif ($pad['creatorEmail']): ?>
          <?= $this->escape($pad['creatorEmail']) ?>
        <?php else: ?>
          <span class="no-data">—</span>
        <?php endif; ?>
      </td>
      <td class="pad-age-col <?= ($pad['acctAgeSecs'] !== null && $pad['acctAgeSecs'] < 300) ? 'age-warn' : '' ?>">
        <?php if ($pad['acctAgeSecs'] === null): ?>
          <span class="no-data">—</span>
        <?php elseif ($pad['acctAgeSecs'] < 60): ?>
          <?= $pad['acctAgeSecs'] ?>秒
        <?php elseif ($pad['acctAgeSecs'] < 3600): ?>
          <?= round($pad['acctAgeSecs'] / 60) ?>分鐘
        <?php else: ?>
          <?= round($pad['acctAgeSecs'] / 86400) ?>天
        <?php endif; ?>
      </td>
      <td class="pad-onetime-col">
        <?= $pad['isOneTime'] ? '<span class="onetime-yes">✓</span>' : '<span class="no-data">—</span>' ?>
      </td>
      <td>
        <span class="policy-badge policy-<?= $this->escape($pad['guestPolicy'] ?? '') ?>">
          <?= $this->escape($pad['guestPolicy'] ?? '—') ?>
        </span>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
$this->yield_end();
echo $this->partial('layout/app');
