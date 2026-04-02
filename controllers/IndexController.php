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

        // On the primary domain, show only the logged-in user's own pads.
        // On subdomains, show all pads visible to the user.
        $isPrimaryDomain = (HackpadHelper::getSubdomain() === '');

        // Primary domain + not logged in → show welcome/info page, no pad list
        if ($isPrimaryDomain && !$user) {
            $this->view->showWelcome = true;
            return;
        }

        $filterByCreator = false;
        $creatorId       = null;

        if ($isPrimaryDomain && $user) {
            // Resolve the user's account ID within this domain
            $email   = MiniEngine::getSession('user_email');
            $stmt    = $db->prepare(
                'SELECT id FROM pro_accounts WHERE email = ? AND domainId = ? AND isDeleted = 0 LIMIT 1'
            );
            $stmt->execute([$email, $domainId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $filterByCreator = true;
                $creatorId       = (int) $row['id'];
            }
        }

        $guestPolicies = $user ? "('allow','link','domain')" : "('allow','link')";
        $creatorFilter = $filterByCreator ? " AND pm.creatorId = $creatorId" : '';

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}{$creatorFilter}"
        );
        $stmt->execute([$domainId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT pm.localPadId, pm.title, pm.createdDate, pm.lastEditedDate,
                    ps.guestPolicy, ps.headRev
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             WHERE pm.domainId = ? AND pm.isDeleted = 0 AND pm.isArchived = 0
               AND ps.headRev > 0 AND ps.guestPolicy IN {$guestPolicies}{$creatorFilter}
             ORDER BY pm.lastEditedDate DESC
             LIMIT " . self::PER_PAGE . " OFFSET " . $offset
        );
        $stmt->execute([$domainId]);

        $pads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->view->pads            = $pads;
        $this->view->previews        = PadContentLoader::getPadTextPreviews($pads, $domainId, 5);
        $this->view->page            = $page;
        $this->view->totalPages      = (int) ceil($total / self::PER_PAGE);
        $this->view->filterByCreator = $filterByCreator;
    }

    public function robotsAction()
    {
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /\n";
        return $this->noview();
    }
}
