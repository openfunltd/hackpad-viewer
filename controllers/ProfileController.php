<?php
class ProfileController extends MiniEngine_Controller
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

    public function showAction(int $userId)
    {
        $domain = $this->view->domain;
        if (!$domain) {
            return $this->notfound('Workspace not found');
        }
        $domainId = (int) $domain['id'];

        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, fullName, email FROM pro_accounts
             WHERE id = ? AND domainId = ? AND isDeleted = 0'
        );
        $stmt->execute([$userId, $domainId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return $this->notfound('Member not found');
        }

        $currentUser   = $this->view->user;
        $guestPolicies = $currentUser ? "('allow','link','domain')" : "('allow','link')";

        $stmt = $db->prepare(
            "SELECT pm.localPadId, pm.title, pm.lastEditedDate, ps.guestPolicy
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.creatorId = ?
               AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}
             ORDER BY pm.lastEditedDate DESC
             LIMIT 100"
        );
        $stmt->execute([$domainId, $userId]);

        $this->view->member = $member;
        $this->view->pads   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
