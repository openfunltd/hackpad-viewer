<?php
class PadController extends MiniEngine_Controller
{
    public function init()
    {
        $this->view->domain = HackpadHelper::getCurrentDomain();
        $this->view->user   = HackpadHelper::getCurrentUser();
    }

    /**
     * Show a pad.
     * $padSlug can be "localPadId" or "Title-With-Dashes-localPadId"
     */
    public function showAction(string $padSlug)
    {
        $domain = $this->view->domain;
        if (!$domain) {
            return $this->notfound('Workspace not found');
        }
        $domainId   = (int) $domain['id'];
        $localPadId = HackpadHelper::extractPadId($padSlug);

        $globalPadId = $domainId . '$' . $localPadId;

        // Check access
        if (!HackpadHelper::canReadPad($globalPadId, $domainId)) {
            if (!MiniEngine::getSession('user_id')) {
                return $this->redirect('/ep/account/sign-in?cont=' . urlencode($_SERVER['REQUEST_URI']));
            }
            return $this->notfound('You do not have access to this pad.');
        }

        // Load pad metadata
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT pm.localPadId, pm.title, pm.createdDate, pm.lastEditedDate,
                    pm.creatorId, ps.guestPolicy
             FROM pro_padmeta pm
             JOIN PAD_SQLMETA ps ON ps.id = CONCAT(pm.domainId, \'$\', pm.localPadId)
             WHERE pm.domainId = ? AND pm.localPadId = ? AND pm.isDeleted = 0'
        );
        $stmt->execute([$domainId, $localPadId]);
        $padMeta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$padMeta) {
            return $this->notfound('Pad not found.');
        }

        // Render pad content
        $content = PadContentLoader::renderPad($domainId, $localPadId);

        $this->view->padMeta  = $padMeta;
        $this->view->content  = $content;
        $this->view->padTitle = $padMeta['title'] ?: $localPadId;
    }
}
