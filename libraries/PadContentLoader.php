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

        // 7. Render to HTML
        $html = Easysync::runsToHtml($runs, $numToAttrib);

        return $html;
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
        $offset  = 0;
        for ($i = 0; $i < $indexInPage && $i < count($offsets); $i++) {
            $offset += $offsets[$i];
        }
        $len = $offsets[$indexInPage] ?? 0;
        if ($len === 0) return null;

        $json = substr($row['DATA'], $offset, $len);
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
            $bytePos   = 0;

            for ($i = 0; $i < count($offsets); $i++) {
                $rev = $pageStart + $i;
                $len = $offsets[$i];
                if ($len === 0) { $bytePos += $len; continue; }
                if ($rev >= $from && $rev <= $to) {
                    $changesets[$rev] = substr($data, $bytePos, $len);
                }
                $bytePos += $len;
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
}
