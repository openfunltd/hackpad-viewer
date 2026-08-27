<?php
/**
 * HackpadHelper - utility functions for the viewer.
 */
class HackpadHelper
{
    /**
     * Get the workspace subdomain from HTTP_HOST.
     * e.g. "g0v.hackpad.tw" → "g0v"
     *      "hackpad.tw" → "" (primary domain)
     */
    public static function getSubdomain(): string
    {
        $host       = $_SERVER['HTTP_HOST'] ?? '';
        $suffix     = getenv('HACKPAD_PRIMARY_DOMAIN') ?: '.hackpad.tw';
        if (str_ends_with($host, $suffix)) {
            return substr($host, 0, strlen($host) - strlen($suffix));
        }
        return '';
    }

    /**
     * Get the domain record (from pro_domains) for the current request.
     * Returns array ['id', 'subDomain', 'orgName'] or null.
     */
    public static function getCurrentDomain(): ?array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $subdomain = self::getSubdomain();
        if ($subdomain === '') {
            // Primary domain - return the special domain id=1
            $cache = ['id' => 1, 'subDomain' => '', 'orgName' => 'Hackpad'];
            return $cache;
        }

        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, subDomain, orgName FROM pro_domains WHERE subDomain = ? AND isDeleted = 0 LIMIT 1'
        );
        $stmt->execute([$subdomain]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache = $row ?: null;
        return $cache;
    }

    /**
     * Get domain info by ID.
     */
    public static function getDomainById(int $id): ?array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, subDomain, orgName FROM pro_domains WHERE id = ? AND isDeleted = 0'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Check whether a domain is publicly accessible (no login required).
     * Reads the 'publicDomain' key from pro_config; defaults to true if absent.
     */
    public static function isDomainPublic(int $domainId): bool
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            "SELECT jsonVal FROM pro_config WHERE domainId = ? AND name = 'publicDomain' LIMIT 1"
        );
        $stmt->execute([$domainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        $val = json_decode($row['jsonVal'], true);
        return !empty($val['x']);
    }

    /**
     * Check if the given email is a site-wide admin
     * (has isAdmin=1 in the main <<private-network>> domain, id=1).
     */
    public static function isSiteAdmin(string $email): bool
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id FROM pro_accounts WHERE email = ? AND domainId = 1 AND isAdmin = 1 AND isDeleted = 0 LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        return (bool) $stmt->fetch();
    }

    /**
     * Check if the given email is an admin of a specific domain
     * (has isAdmin=1 in pro_accounts for that domainId).
     */
    public static function isDomainAdmin(string $email, int $domainId): bool
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id FROM pro_accounts WHERE email = ? AND domainId = ? AND isAdmin = 1 AND isDeleted = 0 LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email)), $domainId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Build the full URL for a subdomain workspace.
     * e.g. "g0v" → "https://g0v.hackpad.tw" (prod) or "https://g0v-hackpad.ronny-test.openfun.dev" (test)
     */
    public static function getDomainUrl(string $subDomain): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $suffix = getenv('HACKPAD_PRIMARY_DOMAIN') ?: '.hackpad.tw';
        return $scheme . '://' . $subDomain . $suffix;
    }

    /**
     * Get all workspace domains the given email has an account in,
     * excluding the main private-network domain.
     */
    public static function getUserDomains(string $email): array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare('
            SELECT d.id, d.subDomain, d.orgName
            FROM pro_accounts a
            JOIN pro_domains d ON d.id = a.domainId
            WHERE a.email = ? AND a.isDeleted = 0 AND d.isDeleted = 0
              AND d.subDomain != \'<<private-network>>\'
            ORDER BY d.subDomain
        ');
        $stmt->execute([strtolower(trim($email))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Extract local pad ID from a URL path.
     * Handles both "/LocalPadId" and "/Title-With-Dashes-LocalPadId" formats.
     * In hackpad, pad IDs are 11-char alphanumeric strings.
     */
    public static function extractPadId(string $pathSegment): string
    {
        // Handle "Title-here-AbCdE12345f" format: last segment after final dash if 11 chars
        if (preg_match('/^.*-([a-zA-Z0-9]{11})$/', $pathSegment, $m)) {
            return $m[1];
        }
        return $pathSegment;
    }

    /**
     * Build URL for a pad (pretty format with title).
     */
    public static function padUrl(string $localPadId, string $title = ''): string
    {
        if ($title === '') return '/' . $localPadId;
        $slug = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]+/u', '-', $title);
        $slug = trim($slug, '-');
        return '/' . $slug . '-' . $localPadId;
    }

    /**
     * Check if the current user can read a given pad.
     *
     * Rules:
     *   guestPolicy = 'allow' or 'link' → anyone can read
     *   guestPolicy = 'domain'           → must be logged-in domain member
     *   guestPolicy = 'deny'             → must have explicit pad_access grant
     *
     * Returns true/false.
     */
    public static function canReadPad(string $globalPadId, int $domainId): bool
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare('SELECT guestPolicy FROM PAD_SQLMETA WHERE id = ?');
        $stmt->execute([$globalPadId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $policy = $row['guestPolicy'];

        if (in_array($policy, ['allow', 'link'])) {
            return true;
        }

        // Need to be logged in
        $userId = MiniEngine::getSession('user_id');
        if (!$userId) return false;

        if ($policy === 'domain') {
            // Check user is a member of this domain by email
            // (the same person has different account IDs in different domains)
            $email = MiniEngine::getSession('user_email');
            if (!$email) return false;
            return self::isEmailDomainMember($email, $domainId);
        }

        if ($policy === 'deny') {
            // Check explicit pad_access grant using the domain-specific userId
            $email = MiniEngine::getSession('user_email');
            if (!$email) return false;
            $stmt2 = $db->prepare(
                'SELECT a.id FROM pro_accounts a WHERE a.email = ? AND a.domainId = ? AND a.isDeleted = 0 LIMIT 1'
            );
            $stmt2->execute([$email, $domainId]);
            $acc = $stmt2->fetch(PDO::FETCH_ASSOC);
            if (!$acc) return false;
            $stmt3 = $db->prepare(
                'SELECT 1 FROM pad_access WHERE globalPadId = ? AND userId = ? AND isRevoked = 0'
            );
            $stmt3->execute([$globalPadId, $acc['id']]);
            return (bool) $stmt3->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    /**
     * Get the pro_accounts row for the currently logged-in user.
     */
    public static function getCurrentUser(): ?array
    {
        $userId = MiniEngine::getSession('user_id');
        if (!$userId) return null;

        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, domainId, fullName, email, isAdmin FROM pro_accounts WHERE id = ? AND isDeleted = 0'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Check if an email address belongs to a member of the given domain.
     * Used for access control on private domains.
     */
    public static function isEmailDomainMember(string $email, int $domainId): bool
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id FROM pro_accounts WHERE email = ? AND domainId = ? AND isDeleted = 0 LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email)), $domainId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Look up a user by email across all domains.
     * Returns the first matching account or null.
     */
    public static function findUserByEmail(string $email): ?array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, domainId, fullName, email, isAdmin FROM pro_accounts
             WHERE email = ? AND isDeleted = 0 LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Format a datetime string for display.
     */
    public static function formatDate(?string $datetime): string
    {
        if (!$datetime) return '';
        return date('Y-m-d', strtotime($datetime));
    }

    /**
     * Truncate text with ellipsis.
     */
    public static function truncate(string $text, int $len = 100): string
    {
        $text = strip_tags($text);
        if (mb_strlen($text, 'UTF-8') <= $len) return $text;
        return mb_substr($text, 0, $len, 'UTF-8') . '…';
    }

    /**
     * Get members (pro_accounts) for a domain, ordered by name.
     * Limited to $limit to avoid sidebar overflow on large workspaces.
     */
    public static function getDomainMembers(int $domainId, int $limit = 50): array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT id, fullName, email FROM pro_accounts
             WHERE domainId = ? AND isDeleted = 0
             ORDER BY fullName LIMIT ' . (int)$limit
        );
        $stmt->execute([$domainId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            // fullName may be stored as HTML entities in the original hackpad DB
            $row['fullName'] = html_entity_decode($row['fullName'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $rows;
    }

    /**
     * Get public collections (pro_groups) for a domain, ordered by pad count desc.
     */
    public static function getDomainCollections(int $domainId): array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT g.groupId, g.name, COUNT(pa.globalPadId) AS padCount
             FROM pro_groups g
             LEFT JOIN pad_access pa ON pa.groupId = g.groupId AND pa.isRevoked = 0
             WHERE g.domainId = ? AND g.isDeleted = 0 AND g.isPublic = 1
             GROUP BY g.groupId
             HAVING padCount > 0
             ORDER BY padCount DESC'
        );
        $stmt->execute([$domainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get public collections that a pad belongs to.
     */
    public static function getPadCollections(string $globalPadId): array
    {
        $db   = MiniEngine::getDb();
        $stmt = $db->prepare(
            'SELECT g.groupId, g.name FROM pad_access pa
             JOIN pro_groups g ON g.groupId = pa.groupId AND g.isDeleted = 0
             WHERE pa.globalPadId = ? AND pa.isRevoked = 0 AND g.isPublic = 1'
        );
        $stmt->execute([$globalPadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load /takedown.csv (subdomain,localPadId,note) into memory.
     * This lets us hide/404 individual spam pads without writing to the
     * read-only hackpad MySQL database.
     * Returns [subdomain => [localPadId => true, ...]] (hash set, O(1) lookup).
     */
    private static function loadTakedownList(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $path  = __DIR__ . '/../takedown.csv';
        if (is_readable($path)) {
            $fh = fopen($path, 'r');
            fgetcsv($fh); // header row
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2 || $row[0] === '') continue;
                $subdomain  = trim($row[0]);
                $localPadId = trim($row[1]);
                if ($localPadId === '') continue;
                $cache[$subdomain][$localPadId] = true;
            }
            fclose($fh);
        }
        return $cache;
    }

    /**
     * Get the list of takendown localPadIds for a given subdomain (workspace).
     */
    public static function getTakedownPadIds(string $subdomain): array
    {
        return array_keys(self::loadTakedownList()[$subdomain] ?? []);
    }

    /**
     * Check whether a given pad has been listed in takedown.csv.
     */
    public static function isTakendown(string $subdomain, string $localPadId): bool
    {
        return isset(self::loadTakedownList()[$subdomain][$localPadId]);
    }

    /**
     * Load /spam_accounts.csv (subdomain,creatorId,email,note) into memory.
     * Bulk spam accounts post dozens to thousands of spam pads each; blocking
     * by creatorId keeps the blocklist (and the SQL NOT IN filters built from
     * it) small even when the number of spam *pads* is huge.
     * Returns [subdomain => [creatorId => true, ...]] (hash set, O(1) lookup).
     */
    private static function loadSpamAccountList(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $path  = __DIR__ . '/../spam_accounts.csv';
        if (is_readable($path)) {
            $fh = fopen($path, 'r');
            fgetcsv($fh); // header row
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2 || $row[0] === '') continue;
                $subdomain = trim($row[0]);
                $creatorId = (int) trim($row[1]);
                if ($creatorId <= 0) continue;
                $cache[$subdomain][$creatorId] = true;
            }
            fclose($fh);
        }
        return $cache;
    }

    /**
     * Get the list of spam account creatorIds for a given subdomain (workspace).
     */
    public static function getSpamAccountIds(string $subdomain): array
    {
        return array_keys(self::loadSpamAccountList()[$subdomain] ?? []);
    }

    /**
     * Check whether a given creatorId is a known bulk-spam account.
     */
    public static function isSpamAccount(string $subdomain, int $creatorId): bool
    {
        return isset(self::loadSpamAccountList()[$subdomain][$creatorId]);
    }

    /**
     * Load /workspace_cutoff.csv (subdomain,cutoffDate,note) into memory.
     * Some workspaces went completely silent for real users and were later
     * flooded by spam bots with no legitimate activity mixed back in; for
     * those, everything created after the last real post can be treated as
     * spam by default instead of chasing every account individually. Since
     * the source DB is read-only, no genuine new content can ever appear
     * after a cutoff, so this is a one-time, low-risk decision per domain.
     * Returns [subdomain => cutoffDateString].
     */
    private static function loadWorkspaceCutoffs(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $path  = __DIR__ . '/../workspace_cutoff.csv';
        if (is_readable($path)) {
            $fh = fopen($path, 'r');
            fgetcsv($fh); // header row
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2 || $row[0] === '') continue;
                $subdomain = trim($row[0]);
                $cutoff    = trim($row[1]);
                if ($cutoff === '') continue;
                $cache[$subdomain] = $cutoff;
            }
            fclose($fh);
        }
        return $cache;
    }

    /**
     * Get the configured cutoff datetime string for a subdomain, or null if none.
     */
    public static function getWorkspaceCutoff(string $subdomain): ?string
    {
        return self::loadWorkspaceCutoffs()[$subdomain] ?? null;
    }

    /**
     * Check whether a pad's createdDate is after its workspace's configured cutoff.
     */
    public static function isPastCutoff(string $subdomain, ?string $createdDate): bool
    {
        $cutoff = self::getWorkspaceCutoff($subdomain);
        if ($cutoff === null || !$createdDate) return false;
        return strtotime($createdDate) > strtotime($cutoff);
    }
}
