<?php
class AdminPadController extends MiniEngine_Controller
{
    public function init()
    {
        $this->view->domain = HackpadHelper::getCurrentDomain();
        $this->view->user   = HackpadHelper::getCurrentUser();
    }

    public function showAction()
    {
        $domain = $this->view->domain;
        if (!$domain) return $this->notfound('Workspace not found');

        $domainId = (int) $domain['id'];
        $email    = MiniEngine::getSession('user_email') ?? '';

        // Gate: domain admin or site admin only
        if (!HackpadHelper::isDomainAdmin($email, $domainId) && !HackpadHelper::isSiteAdmin($email)) {
            return $this->notfound('Access denied.');
        }

        $db = MiniEngine::getDb();

        $stmt = $db->prepare(
            "SELECT pm.localPadId, pm.title, pm.createdDate, pm.lastEditedDate,
                    pm.creatorId, ps.guestPolicy,
                    pa.fullName AS creatorName, pa.email AS creatorEmail,
                    pa.createdDate AS acctCreated, pa.lastLoginDate
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, '\$', pm.localPadId)
             LEFT JOIN pro_accounts pa ON pa.id = pm.creatorId AND pa.isDeleted = 0
             WHERE pm.domainId = ? AND pm.isDeleted = 0
             ORDER BY pm.createdDate DESC"
        );
        $stmt->execute([$domainId]);
        $pads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pads as &$pad) {
            $pad['creatorName'] = html_entity_decode($pad['creatorName'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Spam signals
            $acctCreated  = $pad['acctCreated'];
            $padCreated   = $pad['createdDate'];
            $lastLogin    = $pad['lastLoginDate'];

            // Seconds between account creation and pad creation
            $ageSecs = ($acctCreated && $padCreated)
                ? (strtotime($padCreated) - strtotime($acctCreated))
                : null;

            // One-time account: only logged in once (lastLogin == acctCreated or null after)
            $isOneTime = $acctCreated && ($lastLogin === null || $lastLogin === $acctCreated);

            // Spam risk: 🔴 both signals, 🟡 one signal, '' normal
            $isNewAcct = ($ageSecs !== null && $ageSecs < 300); // < 5 minutes
            if ($isNewAcct && $isOneTime)      $pad['spamRisk'] = 'high';
            elseif ($isNewAcct || $isOneTime)  $pad['spamRisk'] = 'medium';
            else                               $pad['spamRisk'] = '';

            $pad['acctAgeSecs'] = $ageSecs;
            $pad['isOneTime']   = $isOneTime;
        }
        unset($pad);

        $this->view->pads   = $pads;
        $this->view->domain = $domain;
    }
}
