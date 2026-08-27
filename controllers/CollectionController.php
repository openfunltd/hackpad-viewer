<?php
class CollectionController extends MiniEngine_Controller
{
    public function init()
    {
        $this->view->domain      = HackpadHelper::getCurrentDomain();
        $this->view->user        = HackpadHelper::getCurrentUser();
        $domainId = $this->view->domain['id'] ?? null;
        if ($domainId) {
            $this->view->members     = HackpadHelper::getDomainMembers($domainId);
            $this->view->collections = HackpadHelper::getDomainCollections($domainId);
        }
    }

    public function showAction(int $groupId)
    {
        $domain = $this->view->domain;
        if (!$domain) {
            return $this->notfound('Workspace not found');
        }
        $domainId = (int) $domain['id'];

        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT groupId, name FROM pro_groups
             WHERE groupId = ? AND domainId = ? AND isDeleted = 0'
        );
        $stmt->execute([$groupId, $domainId]);
        $collection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$collection) {
            return $this->notfound('Collection not found');
        }

        $user = $this->view->user;
        $guestPolicies = $user ? "('allow','link','domain')" : "('allow','link')";

        $takedownIds    = HackpadHelper::getTakedownPadIds($domain['subDomain']);
        $spamAccountIds = HackpadHelper::getSpamAccountIds($domain['subDomain']);
        $excludeFilter  = '';
        $excludeParams  = [];
        if ($takedownIds) {
            $excludeFilter .= ' AND pm.localPadId NOT IN (' . implode(',', array_fill(0, count($takedownIds), '?')) . ')';
            $excludeParams = array_merge($excludeParams, $takedownIds);
        }
        if ($spamAccountIds) {
            $excludeFilter .= ' AND pm.creatorId NOT IN (' . implode(',', array_fill(0, count($spamAccountIds), '?')) . ')';
            $excludeParams = array_merge($excludeParams, $spamAccountIds);
        }

        $stmt = $db->prepare(
            "SELECT DISTINCT pm.localPadId, pm.title, pm.lastEditedDate, ps.guestPolicy, ps.headRev,
                    pa.fullName AS creatorName
             FROM pad_access pac
             JOIN pro_padmeta pm
               ON pac.globalPadId = CONCAT(pm.domainId, '\$', pm.localPadId)
              AND pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
             JOIN PAD_SQLMETA ps ON ps.id = pac.globalPadId
             LEFT JOIN pro_accounts pa ON pa.id = pm.creatorId AND pa.isDeleted = 0
             WHERE pac.groupId = ? AND pac.isRevoked = 0
               AND ps.guestPolicy IN {$guestPolicies}{$excludeFilter}
             ORDER BY pm.lastEditedDate DESC"
        );
        $stmt->execute(array_merge([$domainId, $groupId], $excludeParams));

        $pads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->view->collection = $collection;
        $this->view->pads       = $pads;
        $this->view->previews   = PadContentLoader::getPadTextPreviews($pads, $domainId, 5);
    }
}
