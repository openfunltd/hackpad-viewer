<?php
class IndexController extends MiniEngine_Controller
{
    public function init()
    {
        $this->view->domain = HackpadHelper::getCurrentDomain();
        $this->view->user   = HackpadHelper::getCurrentUser();
    }

    public function indexAction()
    {
        $domain = $this->view->domain;
        if (!$domain) {
            return $this->notfound('Workspace not found');
        }
        $domainId = $domain['id'];

        $db = MiniEngine::getDb();

        $user = $this->view->user;

        if ($user) {
            // Logged-in: show allow/link/domain pads
            $guestPolicies = "('allow','link','domain')";
        } else {
            // Public: only publicly accessible pads
            $guestPolicies = "('allow','link')";
        }

        $stmt = $db->prepare(
            "SELECT pm.localPadId, pm.title, pm.createdDate, pm.lastEditedDate,
                    ps.guestPolicy
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}
             ORDER BY pm.lastEditedDate DESC
             LIMIT 100"
        );
        $stmt->execute([$domainId]);
        $this->view->pads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function robotsAction()
    {
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /\n";
        return $this->noview();
    }
}
