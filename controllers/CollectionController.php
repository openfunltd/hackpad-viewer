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

        $stmt = $db->prepare(
            "SELECT DISTINCT pm.localPadId, pm.title, pm.lastEditedDate, ps.guestPolicy
             FROM pad_access pa
             JOIN pro_padmeta pm
               ON pa.globalPadId = CONCAT(pm.domainId, '\$', pm.localPadId)
              AND pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
             JOIN PAD_SQLMETA ps ON ps.id = pa.globalPadId
             WHERE pa.groupId = ? AND pa.isRevoked = 0
               AND ps.guestPolicy IN {$guestPolicies}
             ORDER BY pm.lastEditedDate DESC"
        );
        $stmt->execute([$domainId, $groupId]);

        $this->view->collection = $collection;
        $this->view->pads       = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
