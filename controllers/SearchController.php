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

        foreach ($result->hits->hits as $h) {
            $src        = $h->_source;
            $globalId   = $src->id;
            $localPadId = substr($globalId, strpos($globalId, '$') + 1);
            $title      = $src->title ?: $localPadId;

            // Highlighted snippets
            $hl      = $h->highlight ?? null;
            $hlTitle = $hl->title[0]   ?? null;
            $hlBody  = isset($hl->contents) ? implode(' … ', (array)$hl->contents) : null;

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
