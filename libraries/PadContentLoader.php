<?php
/**
 * Loads pad content from MySQL and renders it to HTML.
 *
 * Strategy:
 *   1. Find the last key revision (multiple of keyRevInterval) in PAD_REVMETA_TEXT
 *   2. Parse the stored atext JSON from that key revision
 *   3. Apply remaining individual changesets from PAD_REVS_TEXT up to headRev
 *   4. Convert runs to HTML using the attribute pool (PAD_APOOL)
 *
 * Results are cached in /cache/{domainId}-{localPadId}.html to avoid re-computation.
 */
class PadContentLoader
{
    /** Key revision interval (matches hackpad's keyRevInterval=100) */
    const KEY_REV_INTERVAL = 100;

    /** Rows per page in PAD_REVMETA/PAD_REVS storage */
    const PAGE_SIZE = 20;

    /**
     * Render a pad to HTML.  Returns false on failure.
     */
    public static function renderPad(int $domainId, string $localPadId): string|false
    {
        $globalPadId = $domainId . '$' . $localPadId;

        // 1. Get headRev and keyRevInterval from PAD_META
        $meta = self::getPadMeta($globalPadId);
        if (!$meta) return false;
        $headRev        = $meta['head'];
        $keyRevInterval = $meta['keyRevInterval'] ?? self::KEY_REV_INTERVAL;

        // 2. Find and load the last key revision atext
        $lastKeyRev = (int) floor($headRev / $keyRevInterval) * $keyRevInterval;
        $keyAtext   = self::getKeyRevAtext($globalPadId, $lastKeyRev);
        if ($keyAtext === null) {
            // Fall back to key rev 0
            $lastKeyRev = 0;
            $keyAtext   = self::getKeyRevAtext($globalPadId, 0);
        }
        if ($keyAtext === null) return false;

        // 3. Convert key atext to runs
        $runs = Easysync::atextToRuns($keyAtext);

        // 4. Get attribute pool
        $numToAttrib = self::getApool($globalPadId);

        // 5. Apply remaining changesets from (lastKeyRev+1) to headRev
        if ($headRev > $lastKeyRev) {
            $changesets = self::getChangesets($globalPadId, $lastKeyRev + 1, $headRev);
            foreach ($changesets as $cs) {
                if ($cs !== '') {
                    $runs = Easysync::applyToRuns($runs, $cs, $numToAttrib);
                }
            }
        }

        // 6. Remove title line (first line) since we display it separately
        $runs = self::skipTitleLine($runs);

        // 7. Build author info map from apool (author: p.{id} -> {name, color})
        $authorInfo = self::buildAuthorInfo($numToAttrib);

        // 8. Render to HTML
        $html = Easysync::runsToHtml($runs, $numToAttrib, $authorInfo);

        return $html;
    }

    /**
     * Get the revision history of a pad as a list of edit sessions.
     * Consecutive revisions by the same author within SESSION_GAP_MS are merged.
     *
     * Returns array of sessions: [
     *   'fromRev'    => int,
     *   'toRev'      => int,
     *   'startTime'  => int (ms timestamp),
     *   'endTime'    => int (ms timestamp),
     *   'authorKey'  => string (e.g. "p.275"),
     *   'authorName' => string,
     *   'authorColor'=> string,
     * ]
     */
    public static function getRevisionHistory(string $globalPadId): array
    {
        $db = MiniEngine::getDb();

        // Get NUMID
        $stmt = $db->prepare('SELECT NUMID FROM PAD_REVMETA_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $numid = $row['NUMID'];

        // Load all REVMETA pages ordered by PAGESTART
        $stmt = $db->prepare(
            'SELECT PAGESTART, DATA, OFFSETS FROM PAD_REVMETA_TEXT WHERE NUMID = ? ORDER BY PAGESTART'
        );
        $stmt->execute([$numid]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build apool: author index → author key ("p.NNN")
        $numToAttrib = self::getApool($globalPadId);
        $indexToKey  = [];
        foreach ($numToAttrib as $num => $pair) {
            if ($pair[0] === 'author') {
                $indexToKey[$num] = $pair[1]; // e.g. "p.275"
            }
        }

        // Parse every revmeta entry → [{rev, t, authorKey}]
        $revs = [];
        foreach ($pages as $page) {
            $pageStart = (int) $page['PAGESTART'];
            $offsets   = array_map('intval', explode(',', $page['OFFSETS']));
            $charPos   = 0;
            for ($i = 0; $i < count($offsets); $i++) {
                $len = $offsets[$i];
                if ($len === 0) continue;
                $json = mb_substr($page['DATA'], $charPos, $len, 'UTF-8');
                $charPos += $len;
                $entry = json_decode($json, true);
                if (!$entry) continue;
                $rev       = $pageStart + $i;
                $authorIdx = $entry['a'] ?? 0;
                $revs[]    = [
                    'rev'       => $rev,
                    't'         => (int)($entry['t'] ?? 0),
                    'authorKey' => $indexToKey[$authorIdx] ?? '',
                ];
            }
        }

        if (empty($revs)) return [];

        // Resolve author names + colors
        $authorInfo = self::buildAuthorInfo($numToAttrib);

        // Group into edit sessions (same author, gap < 5 min)
        $sessionGapMs = 60 * 60 * 1000;
        $sessions = [];
        $cur = null;
        foreach ($revs as $r) {
            if ($cur === null
                || $r['authorKey'] !== $cur['authorKey']
                || ($r['t'] - $cur['endTime']) > $sessionGapMs
            ) {
                if ($cur) $sessions[] = $cur;
                $info = $authorInfo[$r['authorKey']] ?? null;
                $cur  = [
                    'fromRev'     => $r['rev'],
                    'toRev'       => $r['rev'],
                    'startTime'   => $r['t'],
                    'endTime'     => $r['t'],
                    'authorKey'   => $r['authorKey'],
                    'authorName'  => $info['name'] ?? ($r['authorKey'] ?: '(unknown)'),
                    'authorColor' => $info['color'] ?? '#888',
                ];
            } else {
                $cur['toRev']   = $r['rev'];
                $cur['endTime'] = $r['t'];
            }
        }
        if ($cur) $sessions[] = $cur;

        return array_reverse($sessions); // newest first
    }

    /**

     * Chosen to be legible on white/light backgrounds.
     */
    private static array $AUTHOR_COLORS = [
        '#b5500a', // burnt orange
        '#1a7a3a', // forest green
        '#1455a4', // cobalt blue
        '#7b2d8b', // purple
        '#b8860b', // dark goldenrod
        '#c0392b', // crimson
        '#0e7d7d', // teal
        '#2e4799', // indigo
        '#8b4513', // saddle brown
        '#4a7c20', // olive green
        '#a63278', // magenta-rose
        '#2c6e8a', // steel blue
    ];

    /**
     * Build a map from author attribute value (e.g. "p.27229") to
     * ['name' => string, 'color' => hex color string].
     * Color is assigned deterministically from the numeric user ID.
     */
    private static function buildAuthorInfo(array $numToAttrib): array
    {
        $ids = [];
        foreach ($numToAttrib as $pair) {
            if ($pair[0] === 'author' && str_starts_with($pair[1], 'p.')) {
                $ids[] = (int) substr($pair[1], 2);
            }
        }
        if (empty($ids)) return [];

        $db   = MiniEngine::getDb();
        $in   = implode(',', $ids);
        $stmt = $db->query(
            "SELECT id, fullName, email FROM pro_accounts WHERE id IN ($in)"
        );
        $map  = [];
        $n    = count(self::$AUTHOR_COLORS);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name  = html_entity_decode($row['fullName'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $color = self::$AUTHOR_COLORS[$row['id'] % $n];
            $map['p.' . $row['id']] = [
                'name'  => $name ?: $row['email'],
                'color' => $color,
            ];
        }
        return $map;
    }

    /**
     * Get the full text (title = first line) of a pad from its key revision.
     * Used to confirm/supplement pro_padmeta.title.
     */
    public static function getPadTitle(int $domainId, string $localPadId): string
    {
        $globalPadId = $domainId . '$' . $localPadId;
        $meta        = self::getPadMeta($globalPadId);
        if (!$meta) return '';

        $headRev        = $meta['head'];
        $keyRevInterval = $meta['keyRevInterval'] ?? self::KEY_REV_INTERVAL;
        $lastKeyRev     = (int) floor($headRev / $keyRevInterval) * $keyRevInterval;
        $keyAtext       = self::getKeyRevAtext($globalPadId, $lastKeyRev);
        if (!$keyAtext) {
            $keyAtext = self::getKeyRevAtext($globalPadId, 0);
        }
        if (!$keyAtext) return '';

        $text  = $keyAtext['text'] ?? '';
        $lines = explode("\n", $text, 2);
        return trim($lines[0]);
    }

    /**
     * Read PAD_META JSON for a global pad ID.
     * Returns ['head', 'keyRevInterval', ...] or null.
     */
    public static function getPadMeta(string $globalPadId): ?array
    {
        $db  = MiniEngine::getDb();
        $stmt = $db->prepare('SELECT JSON FROM PAD_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $data = json_decode($row['JSON'], true);
        $x    = $data['x'] ?? [];
        return [
            'head'           => $x['head'] ?? 0,
            'keyRevInterval' => $x['keyRevInterval'] ?? self::KEY_REV_INTERVAL,
        ];
    }

    /**
     * Get the stored key revision atext from PAD_REVMETA_TEXT.
     * Returns {text, attribs} array, or null if not found.
     */
    private static function getKeyRevAtext(string $globalPadId, int $keyRev): ?array
    {
        $db = MiniEngine::getDb();

        // Get NUMID for this pad
        $stmt = $db->prepare('SELECT NUMID FROM PAD_REVMETA_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $numid = $row['NUMID'];

        // Key revisions are at PAGESTART = floor(keyRev / PAGE_SIZE) * PAGE_SIZE
        // and the key revision is at index (keyRev % PAGE_SIZE) within that page.
        // However, only multiples of keyRevInterval have full atext stored.
        // We look at PAGESTART = keyRev when keyRev is a multiple of PAGE_SIZE,
        // otherwise the first key rev might be at PAGESTART=0.
        $pageStart = (int) floor($keyRev / self::PAGE_SIZE) * self::PAGE_SIZE;
        $indexInPage = $keyRev - $pageStart;

        $stmt = $db->prepare(
            'SELECT DATA, OFFSETS FROM PAD_REVMETA_TEXT WHERE NUMID = ? AND PAGESTART = ?'
        );
        $stmt->execute([$numid, $pageStart]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Try PAGESTART=0 as fallback for keyRev=0
            if ($keyRev !== 0) return null;
            $stmt = $db->prepare(
                'SELECT DATA, OFFSETS FROM PAD_REVMETA_TEXT WHERE NUMID = ? AND PAGESTART = 0'
            );
            $stmt->execute([$numid]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $indexInPage = 0;
        }

        $offsets = array_map('intval', explode(',', $row['OFFSETS']));
        $charPos = 0;  // OFFSETS stores char counts, not byte lengths
        for ($i = 0; $i < $indexInPage && $i < count($offsets); $i++) {
            $charPos += $offsets[$i];
        }
        $len = $offsets[$indexInPage] ?? 0;
        if ($len === 0) return null;

        $json = mb_substr($row['DATA'], $charPos, $len, 'UTF-8');
        $data = json_decode($json, true);
        return $data['atext'] ?? null;
    }

    /**
     * Get the attribute pool for a pad.
     * Returns numToAttrib: array [attrNum => [key, value]]
     */
    public static function getApool(string $globalPadId): array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare('SELECT JSON FROM PAD_APOOL WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];

        $data        = json_decode($row['JSON'], true);
        $numToAttrib = [];
        foreach (($data['x']['numToAttrib'] ?? []) as $numStr => $pair) {
            $numToAttrib[intval($numStr)] = $pair;
        }
        return $numToAttrib;
    }

    /**
     * Get individual changeset strings for revisions $from to $to (inclusive).
     * Returns associative array [rev => changeset_string], ksorted.
     */
    private static function getChangesetsIndexed(string $globalPadId, int $from, int $to): array
    {
        $db = MiniEngine::getDb();

        $stmt = $db->prepare('SELECT NUMID FROM PAD_REVS_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $numid = $row['NUMID'];

        $firstPage = (int) floor($from / self::PAGE_SIZE) * self::PAGE_SIZE;
        $lastPage  = (int) floor($to / self::PAGE_SIZE) * self::PAGE_SIZE;

        $changesets = [];

        $stmt = $db->prepare(
            'SELECT PAGESTART, DATA, OFFSETS FROM PAD_REVS_TEXT
             WHERE NUMID = ? AND PAGESTART >= ? AND PAGESTART <= ?
             ORDER BY PAGESTART'
        );
        $stmt->execute([$numid, $firstPage, $lastPage]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pages as $page) {
            $pageStart = (int) $page['PAGESTART'];
            $offsets   = array_map('intval', explode(',', $page['OFFSETS']));
            $data      = $page['DATA'];
            $charPos   = 0;

            for ($i = 0; $i < count($offsets); $i++) {
                $rev = $pageStart + $i;
                $len = $offsets[$i];
                if ($len === 0) { $charPos += $len; continue; }
                if ($rev >= $from && $rev <= $to) {
                    $changesets[$rev] = mb_substr($data, $charPos, $len, 'UTF-8');
                }
                $charPos += $len;
            }
        }

        ksort($changesets);
        return $changesets;
    }

    /**
     * Get plain text at each revision boundary in $revs.
     * $revs is a sorted array of revision numbers.
     * Returns [rev => plaintext].
     */
    private static function getTextsAtRevBoundaries(string $globalPadId, array $revs): array
    {
        if (empty($revs)) return [];

        $meta = self::getPadMeta($globalPadId);
        if (!$meta) return [];
        $keyRevInterval = $meta['keyRevInterval'] ?? self::KEY_REV_INTERVAL;

        $minRev = min($revs);
        $maxRev = max($revs);

        // Find the largest key rev <= minRev
        $keyRev = (int) floor($minRev / $keyRevInterval) * $keyRevInterval;
        $keyAtext = self::getKeyRevAtext($globalPadId, $keyRev);
        if ($keyAtext === null) {
            $keyRev   = 0;
            $keyAtext = self::getKeyRevAtext($globalPadId, 0);
        }
        if ($keyAtext === null) return [];

        $runs = Easysync::atextToRuns($keyAtext);

        $revsSet = array_flip($revs);
        $texts   = [];

        // If keyRev itself is in revs, capture it
        if (isset($revsSet[$keyRev])) {
            $texts[$keyRev] = implode('', array_column($runs, 't'));
        }

        if ($maxRev > $keyRev) {
            $indexed = self::getChangesetsIndexed($globalPadId, $keyRev + 1, $maxRev);
            for ($rev = $keyRev + 1; $rev <= $maxRev; $rev++) {
                if (isset($indexed[$rev]) && $indexed[$rev] !== '') {
                    $runs = Easysync::applyToRuns($runs, $indexed[$rev]);
                }
                if (isset($revsSet[$rev])) {
                    $texts[$rev] = implode('', array_column($runs, 't'));
                }
            }
        }

        return $texts;
    }

    /**
     * LCS-based line diff.
     * Returns array of ['op' => ' '|'+'|'-', 'line' => string].
     */
    private static function computeLineDiff(string $before, string $after): array
    {
        $aLines = explode("\n", rtrim($before, "\n"));
        $bLines = explode("\n", rtrim($after,  "\n"));

        // Guard against huge diffs
        $m = count($aLines);
        $n = count($bLines);
        if ($m * $n > 400000) {
            return [['op' => '~', 'line' => '（diff 過大，略過）']];
        }

        // Build LCS table
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($aLines[$i - 1] === $bLines[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        // Backtrack to produce diff
        $diff = [];
        $i = $m; $j = $n;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $aLines[$i - 1] === $bLines[$j - 1]) {
                array_unshift($diff, ['op' => ' ', 'line' => $aLines[$i - 1]]);
                $i--; $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                array_unshift($diff, ['op' => '+', 'line' => $bLines[$j - 1]]);
                $j--;
            } else {
                array_unshift($diff, ['op' => '-', 'line' => $aLines[$i - 1]]);
                $i--;
            }
        }

        return $diff;
    }

    /**
     * Collapse unchanged context lines, keeping $ctx lines around changes.
     * Groups collapsed lines into ['op' => '~', 'line' => "（N 行未修改）"].
     */
    private static function collapseContext(array $diff, int $ctx = 2): array
    {
        $n = count($diff);
        if ($n === 0) return [];

        // Mark which indices are "near a change"
        $keep = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            if ($diff[$i]['op'] !== ' ') {
                for ($k = max(0, $i - $ctx); $k <= min($n - 1, $i + $ctx); $k++) {
                    $keep[$k] = true;
                }
            }
        }

        $result = [];
        $i = 0;
        while ($i < $n) {
            if ($keep[$i]) {
                $result[] = $diff[$i];
                $i++;
            } else {
                // Count consecutive skipped lines
                $start = $i;
                while ($i < $n && !$keep[$i]) $i++;
                $count = $i - $start;
                $result[] = ['op' => '~', 'line' => "（{$count} 行未修改）"];
            }
        }

        return $result;
    }

    /**
     * Get the revision history with per-session diffs.
     * Same structure as getRevisionHistory() but each session has an optional 'diff' key.
     */
    public static function getRevisionHistoryWithDiff(string $globalPadId): array
    {
        $db = MiniEngine::getDb();

        $stmt = $db->prepare('SELECT NUMID FROM PAD_REVMETA_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $numid = $row['NUMID'];

        $stmt = $db->prepare(
            'SELECT PAGESTART, DATA, OFFSETS FROM PAD_REVMETA_TEXT WHERE NUMID = ? ORDER BY PAGESTART'
        );
        $stmt->execute([$numid]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $numToAttrib = self::getApool($globalPadId);
        $indexToKey  = [];
        foreach ($numToAttrib as $num => $pair) {
            if ($pair[0] === 'author') {
                $indexToKey[$num] = $pair[1];
            }
        }

        $revs = [];
        foreach ($pages as $page) {
            $pageStart = (int) $page['PAGESTART'];
            $offsets   = array_map('intval', explode(',', $page['OFFSETS']));
            $charPos   = 0;
            for ($i = 0; $i < count($offsets); $i++) {
                $len = $offsets[$i];
                if ($len === 0) continue;
                $json = mb_substr($page['DATA'], $charPos, $len, 'UTF-8');
                $charPos += $len;
                $entry = json_decode($json, true);
                if (!$entry) continue;
                $rev       = $pageStart + $i;
                $authorIdx = $entry['a'] ?? 0;
                $revs[]    = [
                    'rev'       => $rev,
                    't'         => (int)($entry['t'] ?? 0),
                    'authorKey' => $indexToKey[$authorIdx] ?? '',
                ];
            }
        }

        if (empty($revs)) return [];

        $authorInfo   = self::buildAuthorInfo($numToAttrib);
        $sessionGapMs = 60 * 60 * 1000;
        $sessions     = [];
        $cur          = null;
        foreach ($revs as $r) {
            if ($cur === null
                || $r['authorKey'] !== $cur['authorKey']
                || ($r['t'] - $cur['endTime']) > $sessionGapMs
            ) {
                if ($cur) $sessions[] = $cur;
                $info = $authorInfo[$r['authorKey']] ?? null;
                $cur  = [
                    'fromRev'     => $r['rev'],
                    'toRev'       => $r['rev'],
                    'startTime'   => $r['t'],
                    'endTime'     => $r['t'],
                    'authorKey'   => $r['authorKey'],
                    'authorName'  => $info['name'] ?? ($r['authorKey'] ?: '(unknown)'),
                    'authorColor' => $info['color'] ?? '#888',
                ];
            } else {
                $cur['toRev']   = $r['rev'];
                $cur['endTime'] = $r['t'];
            }
        }
        if ($cur) $sessions[] = $cur;

        // sessions is oldest-first here; collect all toRev boundaries
        $toRevs = array_unique(array_column($sessions, 'toRev'));
        sort($toRevs);
        $texts = self::getTextsAtRevBoundaries($globalPadId, $toRevs);

        $prevText = '';
        foreach ($sessions as &$s) {
            $afterText = $texts[$s['toRev']] ?? '';
            $fullDiff  = self::computeLineDiff($prevText, $afterText);
            $collapsed = self::collapseContext($fullDiff, 2);
            // Only attach diff if there are actual changes
            $hasChange = false;
            foreach ($collapsed as $entry) {
                if ($entry['op'] !== ' ') { $hasChange = true; break; }
            }
            if ($hasChange) {
                $s['diff'] = $collapsed;
            }
            $prevText = $afterText;
        }
        unset($s);

        return array_reverse($sessions); // newest first
    }

    /**
     * Get individual changeset strings for revisions $from to $to (inclusive).
     * Returns array of changeset strings in order.
     */
    private static function getChangesets(string $globalPadId, int $from, int $to): array
    {
        $db = MiniEngine::getDb();

        $stmt = $db->prepare('SELECT NUMID FROM PAD_REVS_META WHERE ID = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $numid = $row['NUMID'];

        // Determine which pages we need
        $firstPage = (int) floor($from / self::PAGE_SIZE) * self::PAGE_SIZE;
        $lastPage  = (int) floor($to / self::PAGE_SIZE) * self::PAGE_SIZE;

        $changesets = [];

        $stmt = $db->prepare(
            'SELECT PAGESTART, DATA, OFFSETS FROM PAD_REVS_TEXT
             WHERE NUMID = ? AND PAGESTART >= ? AND PAGESTART <= ?
             ORDER BY PAGESTART'
        );
        $stmt->execute([$numid, $firstPage, $lastPage]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pages as $page) {
            $pageStart = (int) $page['PAGESTART'];
            $offsets   = array_map('intval', explode(',', $page['OFFSETS']));
            $data      = $page['DATA'];
            $charPos   = 0;  // OFFSETS stores char counts, not byte lengths

            for ($i = 0; $i < count($offsets); $i++) {
                $rev = $pageStart + $i;
                $len = $offsets[$i];
                if ($len === 0) { $charPos += $len; continue; }
                if ($rev >= $from && $rev <= $to) {
                    $changesets[$rev] = mb_substr($data, $charPos, $len, 'UTF-8');
                }
                $charPos += $len;
            }
        }

        ksort($changesets);
        return array_values($changesets);
    }

    /**
     * Remove the first line (title line) from runs.
     * The first newline character marks the end of the title.
     */
    private static function skipTitleLine(array $runs): array
    {
        $result   = [];
        $skipping = true;

        foreach ($runs as $run) {
            if (!$skipping) {
                $result[] = $run;
                continue;
            }
            $pos = mb_strpos($run['t'], "\n", 0, 'UTF-8');
            if ($pos !== false) {
                $after = mb_substr($run['t'], $pos + 1, null, 'UTF-8');
                if ($after !== '') {
                    $result[] = ['t' => $after, 'a' => $run['a']];
                }
                $skipping = false;
            }
            // else: still on title line, skip entire run
        }

        return $result;
    }

    /**
     * Invalidate cached HTML for a pad.
     */
    public static function clearCache(int $domainId, string $localPadId): void
    {
        // No-op: caching is disabled
    }

    /**
     * Batch-fetch plain-text previews for multiple pads.
     * Uses the key revision snapshot to avoid running the full changeset engine.
     *
     * @param array  $pads      Each element must have 'localPadId' and 'headRev'.
     * @param int    $domainId  Domain that all pads belong to.
     * @param int    $maxLines  Maximum number of non-empty lines to return per pad.
     * @return array  Map of localPadId => preview string.
     */
    public static function getPadTextPreviews(array $pads, int $domainId, int $maxLines = 5): array
    {
        if (empty($pads)) return [];

        $db = MiniEngine::getDb();

        // Build globalPadId → [localPadId, keyRev, pageStart] map
        $padInfo = [];
        foreach ($pads as $pad) {
            $local       = $pad['localPadId'];
            $headRev     = (int) $pad['headRev'];
            $keyRev      = (int) floor($headRev / self::KEY_REV_INTERVAL) * self::KEY_REV_INTERVAL;
            $pageStart   = (int) floor($keyRev / self::PAGE_SIZE) * self::PAGE_SIZE;
            $globalPadId = $domainId . '$' . $local;
            $padInfo[$globalPadId] = [
                'localPadId' => $local,
                'keyRev'     => $keyRev,
                'pageStart'  => $pageStart,
                'indexInPage'=> $keyRev - $pageStart,
            ];
        }

        // Batch 1: resolve NUMIDs
        $globalIds    = array_keys($padInfo);
        $placeholders = implode(',', array_fill(0, count($globalIds), '?'));
        $stmt = $db->prepare("SELECT ID, NUMID FROM PAD_REVMETA_META WHERE ID IN ($placeholders)");
        $stmt->execute($globalIds);
        $numidMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $numidMap[$row['ID']] = (int) $row['NUMID'];
        }

        // Batch 2: fetch REVMETA rows  (OR-joined conditions)
        $conditions = [];
        $params     = [];
        foreach ($padInfo as $globalPadId => $info) {
            if (!isset($numidMap[$globalPadId])) continue;
            $numid = $numidMap[$globalPadId];
            $padInfo[$globalPadId]['numid'] = $numid;
            $conditions[] = '(NUMID = ? AND PAGESTART = ?)';
            $params[]     = $numid;
            $params[]     = $info['pageStart'];
        }
        if (empty($conditions)) return [];

        $stmt = $db->prepare(
            'SELECT NUMID, PAGESTART, DATA, OFFSETS FROM PAD_REVMETA_TEXT WHERE ' .
            implode(' OR ', $conditions)
        );
        $stmt->execute($params);

        $revmetaRows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['NUMID'] . '_' . $row['PAGESTART'];
            $revmetaRows[$key] = $row;
        }

        // Extract preview text
        $previews = [];
        foreach ($padInfo as $globalPadId => $info) {
            if (!isset($info['numid'])) continue;
            $key = $info['numid'] . '_' . $info['pageStart'];
            if (!isset($revmetaRows[$key])) continue;

            $row         = $revmetaRows[$key];
            $indexInPage = $info['indexInPage'];
            $offsets     = array_map('intval', explode(',', $row['OFFSETS']));
            $data        = $row['DATA'];

            // OFFSETS stores character counts (not byte lengths) — use mb_substr
            $charPos = 0;
            for ($i = 0; $i < $indexInPage; $i++) {
                $charPos += $offsets[$i] ?? 0;
            }
            $length = $offsets[$indexInPage] ?? 0;
            if ($length === 0) continue;

            $json   = mb_substr($data, $charPos, $length, 'UTF-8');
            $parsed = json_decode($json, true);
            if (!$parsed) continue;

            // atext may be nested under 'atext' key or at top level
            $text = $parsed['atext']['text'] ?? $parsed['text'] ?? null;
            if ($text === null) continue;

            // If the snapshot still contains the default hackpad template text
            // (happens when keyRev=0 and the pad was edited with headRev<100),
            // apply changesets up to headRev to get the actual current text.
            $headRev = array_reduce($pads, function ($carry, $p) use ($info) {
                return $p['localPadId'] === $info['localPadId'] ? (int) $p['headRev'] : $carry;
            }, 0);
            if (self::isDefaultTemplate($text) && $headRev > $info['keyRev']) {
                $actual = self::getPlainTextAtHead($globalPadId, $headRev, $info['keyRev']);
                if ($actual !== null) {
                    $text = $actual;
                }
            }

            // Skip the first line (usually the title, same as pad title shown above)
            $lines   = explode("\n", rtrim($text, "\n"));
            $nonEmpty = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
            // Drop the first non-empty line (title duplicate), show next $maxLines
            $preview = implode("\n", array_slice($nonEmpty, 1, $maxLines));
            if ($preview !== '') {
                $previews[$info['localPadId']] = $preview;
            }
        }

        return $previews;
    }

    /** Detect hackpad's default "new pad" template text. */
    private static function isDefaultTemplate(string $text): bool
    {
        return strpos($text, 'This pad text is synchronized as you type') !== false;
    }

    /**
     * Apply changesets from keyRev+1 to headRev and return plain text.
     * Used when a key-revision snapshot still contains the default template.
     */
    private static function getPlainTextAtHead(string $globalPadId, int $headRev, int $keyRev): ?string
    {
        $keyAtext = self::getKeyRevAtext($globalPadId, $keyRev);
        if ($keyAtext === null) return null;

        $runs        = Easysync::atextToRuns($keyAtext);
        $numToAttrib = self::getApool($globalPadId);

        if ($headRev > $keyRev) {
            $changesets = self::getChangesets($globalPadId, $keyRev + 1, $headRev);
            foreach ($changesets as $cs) {
                if ($cs !== '') {
                    $runs = Easysync::applyToRuns($runs, $cs, $numToAttrib);
                }
            }
        }

        return implode('', array_column($runs, 't'));
    }
}
