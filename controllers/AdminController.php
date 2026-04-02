<?php
class AdminController extends MiniEngine_Controller
{
    const USERS_PER_PAGE = 100;

    public function init()
    {
        $this->view->domain      = HackpadHelper::getCurrentDomain();
        $this->view->user        = HackpadHelper::getCurrentUser();

        if (!$GLOBALS['_isSiteAdmin']) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body>';
            echo '<h2>Access Denied</h2><p>Site admin only.</p>';
            echo '</body></html>';
            exit;
        }
    }

    /** /admin — dashboard */
    public function indexAction()
    {
        $db = MiniEngine::getDb();
        $this->view->domainCount = (int) $db->query('SELECT COUNT(*) FROM pro_domains WHERE isDeleted=0')->fetchColumn();
        $this->view->userCount   = (int) $db->query('SELECT COUNT(*) FROM pro_accounts WHERE isDeleted=0')->fetchColumn();
    }

    /** /admin/domains — list all domains with their publicDomain setting */
    public function domainsAction()
    {
        $db = MiniEngine::getDb();
        $stmt = $db->query("
            SELECT d.id, d.subDomain, d.orgName, d.createdDate,
                   c.jsonVal AS publicDomainJson,
                   (SELECT COUNT(*) FROM pro_accounts a WHERE a.domainId = d.id AND a.isDeleted = 0) AS memberCount
            FROM pro_domains d
            LEFT JOIN pro_config c ON c.domainId = d.id AND c.name = 'publicDomain'
            WHERE d.isDeleted = 0
            ORDER BY d.subDomain
        ");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($domains as &$row) {
            $val = json_decode($row['publicDomainJson'] ?? '{}', true);
            $row['isPublic'] = !empty($val['x']);
        }
        $this->view->domains = $domains;
    }

    /** /admin/users — paginated list of all users */
    public function usersAction()
    {
        $db      = MiniEngine::getDb();
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $search  = trim($_GET['q'] ?? '');
        $offset  = ($page - 1) * self::USERS_PER_PAGE;

        if ($search !== '') {
            $like = '%' . $search . '%';
            $total = (int) $db->prepare('SELECT COUNT(*) FROM pro_accounts WHERE isDeleted=0 AND (email LIKE ? OR fullName LIKE ?)')
                ->execute([$like, $like]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
            // Re-run properly
            $cStmt = $db->prepare('SELECT COUNT(*) FROM pro_accounts WHERE isDeleted=0 AND (email LIKE ? OR fullName LIKE ?)');
            $cStmt->execute([$like, $like]);
            $total = (int) $cStmt->fetchColumn();
            $limit = self::USERS_PER_PAGE;
            $stmt = $db->prepare("
                SELECT a.id, a.email, a.fullName, a.isAdmin, a.domainId, a.createdDate, d.subDomain
                FROM pro_accounts a JOIN pro_domains d ON d.id = a.domainId
                WHERE a.isDeleted=0 AND (a.email LIKE ? OR a.fullName LIKE ?)
                ORDER BY a.id DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute([$like, $like]);
        } else {
            $total = (int) $db->query('SELECT COUNT(*) FROM pro_accounts WHERE isDeleted=0')->fetchColumn();
            $limit = self::USERS_PER_PAGE;
            $stmt  = $db->prepare("
                SELECT a.id, a.email, a.fullName, a.isAdmin, a.domainId, a.createdDate, d.subDomain
                FROM pro_accounts a JOIN pro_domains d ON d.id = a.domainId
                WHERE a.isDeleted=0
                ORDER BY a.id DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute();
        }

        $this->view->users      = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->view->total      = $total;
        $this->view->page       = $page;
        $this->view->totalPages = (int) ceil($total / self::USERS_PER_PAGE);
        $this->view->search     = $search;
    }
}
