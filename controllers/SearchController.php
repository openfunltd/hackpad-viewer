<?php
class SearchController extends MiniEngine_Controller
{
    const PER_PAGE = 20;

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

    public function indexAction()
    {
        $domain = $this->view->domain;
        if (!$domain) return $this->notfound('Workspace not found');

        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $from   = ($page - 1) * self::PER_PAGE;

        $this->view->q    = $q;
        $this->view->page = $page;
        $this->view->hits = [];
        $this->view->total = 0;
        $this->view->totalPages = 0;

        if ($q === '') return;

        $domainId    = (int) $domain['id'];
        $isLoggedIn  = (bool) MiniEngine::getSession('user_id');

        // Allowed guestpolicies based on login state
        $policies = $isLoggedIn ? ['allow', 'link', 'domain'] : ['allow', 'link'];

        // creatorId is an integer field in the ES mapping (unlike `id`, which is
        // analyzed text and can't be exact-matched), so this filter works reliably
        // and keeps result counts/pagination correct.
        $spamAccountIds = HackpadHelper::getSpamAccountIds($domain['subDomain']);

        $esQuery = [
            'from' => $from,
            'size' => self::PER_PAGE,
            'query' => [
                'bool' => [
                    'must' => [
                        'multi_match' => [
                            'query'  => $q,
                            'fields' => ['title^3', 'contents'],
                            'type'   => 'best_fields',
                        ],
                    ],
                    'filter' => [
                        ['term'  => ['domainId' => $domainId]],
                        ['terms' => ['guestpolicy' => $policies]],
                        ['term'  => ['deleted' => false]],
                    ],
                    'must_not' => $spamAccountIds
                        ? [['terms' => ['creatorId' => $spamAccountIds]]]
                        : [],
                ],
            ],
            'highlight' => [
                'fields' => [
                    'title'    => ['number_of_fragments' => 0],
                    'contents' => ['fragment_size' => 150, 'number_of_fragments' => 2],
                ],
                'pre_tags'  => ['<mark>'],
                'post_tags' => ['</mark>'],
            ],
            '_source' => ['id', 'title', 'lastedit'],
        ];

        try {
            $prefix = getenv('ELASTIC_PREFIX');
            $result = Elastic::dbQuery(
                '/{prefix}etherpad/_search',
                'POST',
                json_encode($esQuery, JSON_UNESCAPED_UNICODE)
            );
        } catch (Exception $e) {
            $this->view->error = '搜尋服務暫時無法使用。';
            return;
        }

        $total = $result->hits->total->value ?? $result->hits->total ?? 0;
        $hits  = [];

        // ES doesn't index createdDate, so cutoff-based masking needs one small
        // lookup for just this page's results (at most PER_PAGE rows).
        $pageLocalPadIds = [];
        foreach ($result->hits->hits as $h) {
            $pageLocalPadIds[] = substr($h->_source->id, strpos($h->_source->id, '$') + 1);
        }
        $createdDates = [];
        $cutoff = HackpadHelper::getWorkspaceCutoff($domain['subDomain']);
        if ($cutoff !== null && $pageLocalPadIds) {
            $db  = MiniEngine::getDb();
            $ph  = implode(',', array_fill(0, count($pageLocalPadIds), '?'));
            $stmt = $db->prepare("SELECT localPadId, createdDate FROM pro_padmeta WHERE domainId = ? AND localPadId IN ($ph)");
            $stmt->execute(array_merge([$domainId], $pageLocalPadIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $createdDates[$r['localPadId']] = $r['createdDate'];
            }
        }

        foreach ($result->hits->hits as $h) {
            $src        = $h->_source;
            $globalId   = $src->id;
            $localPadId = substr($globalId, strpos($globalId, '$') + 1);
            $title      = $src->title ?: $localPadId;

            // Highlighted snippets
            $hl      = $h->highlight ?? null;
            $hlTitle = $hl->title[0]   ?? null;
            $hlBody  = isset($hl->contents) ? implode(' … ', (array)$hl->contents) : null;

            // The ES index can't be updated (read-only backing DB), so mask
            // takendown spam pads here instead of trying to filter them out
            // of the search query (their `id` field isn't a keyword field,
            // so exact-match filtering on it doesn't work reliably).
            $isPastCutoff = HackpadHelper::isPastCutoff($domain['subDomain'], $createdDates[$localPadId] ?? null);
            if (HackpadHelper::isTakendown($domain['subDomain'], $localPadId) || $isPastCutoff) {
                $title   = '[deleted]';
                $hlTitle = null;
                $hlBody  = '[deleted]';
            }

            $hits[] = [
                'localPadId' => $localPadId,
                'title'      => $title,
                'hlTitle'    => $hlTitle,
                'hlBody'     => $hlBody,
                'lastedit'   => $src->lastedit ?? null,
                'url'        => HackpadHelper::padUrl($localPadId, $title),
            ];
        }

        $this->view->hits       = $hits;
        $this->view->total      = $total;
        $this->view->totalPages = (int) ceil($total / self::PER_PAGE);
    }
}
