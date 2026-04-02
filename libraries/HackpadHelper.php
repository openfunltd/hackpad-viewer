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
            // Check user is a member of this domain
            $stmt2 = $db->prepare(
                'SELECT id FROM pro_accounts WHERE id = ? AND domainId = ? AND isDeleted = 0'
            );
            $stmt2->execute([$userId, $domainId]);
            return (bool) $stmt2->fetch(PDO::FETCH_ASSOC);
        }

        if ($policy === 'deny') {
            // Check explicit pad_access grant
            $stmt2 = $db->prepare(
                'SELECT 1 FROM pad_access WHERE globalPadId = ? AND userId = ? AND isRevoked = 0'
            );
            $stmt2->execute([$globalPadId, $userId]);
            return (bool) $stmt2->fetch(PDO::FETCH_ASSOC);
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
}
