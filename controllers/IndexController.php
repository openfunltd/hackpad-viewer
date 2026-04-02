<?php
class IndexController extends MiniEngine_Controller
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

    const PER_PAGE = 50;

    public function indexAction()
    {
        $domain = $this->view->domain;
        if (!$domain) {
            return $this->notfound('Workspace not found');
        }
        $domainId = $domain['id'];

        $db   = MiniEngine::getDb();
        $user = $this->view->user;

        $guestPolicies = $user ? "('allow','link','domain')" : "('allow','link')";

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}"
        );
        $stmt->execute([$domainId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT pm.localPadId, pm.title, pm.createdDate, pm.lastEditedDate,
                    ps.guestPolicy
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}
             ORDER BY pm.lastEditedDate DESC
             LIMIT " . self::PER_PAGE . " OFFSET " . $offset
        );
        $stmt->execute([$domainId]);

        $this->view->pads       = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->view->page       = $page;
        $this->view->totalPages = (int) ceil($total / self::PER_PAGE);
    }

    public function robotsAction()
    {
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /\n";
        return $this->noview();
    }
}
