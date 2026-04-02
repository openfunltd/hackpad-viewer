<?php
/**
 * Easysync2 Changeset Engine (PHP port)
 *
 * Handles parsing and applying Easysync2 changesets (as used by Hackpad/EtherPad)
 * to reconstruct the final attributed text (atext) from stored revisions.
 *
 * Changeset format:  Z:{oldLen}(>|<){delta} {ops} ${bank}
 * Attribution string: sequence of (*X)* (|L)? (+|-|=) N
 * Counts are base-36. Characters are counted as Unicode code points (mb_* functions).
 */
class Easysync
{
    /**
     * Parse a changeset string into its components.
     * Returns ['oldLen', 'delta', 'ops' => [...], 'bank']
     * Each op: ['type' => +/-/=, 'count', 'lines', 'attribs' => [nums]]
     */
    public static function parseChangeset(string $cs): array
    {
        $dollarPos = strpos($cs, '$');
        $bank      = ($dollarPos !== false) ? substr($cs, $dollarPos + 1) : '';
        $header    = ($dollarPos !== false) ? substr($cs, 0, $dollarPos) : $cs;

        // Parse "Z:oldLen(>|<)delta" prefix
        if (!preg_match('/^Z:([0-9a-z]+)([><])([0-9a-z]+)(.*)$/s', $header, $m)) {
            return ['oldLen' => 0, 'delta' => 0, 'ops' => [], 'bank' => $bank];
        }
        $oldLen   = intval($m[1], 36);
        $delta    = intval($m[3], 36) * ($m[2] === '<' ? -1 : 1);
        $opsStr   = $m[4];

        $ops = [];
        $i   = 0;
        $len = strlen($opsStr);
        while ($i < $len) {
            $attribs = [];
            while ($i < $len && $opsStr[$i] === '*') {
                $i++;
                $num = '';
                while ($i < $len && ctype_alnum($opsStr[$i])) {
                    $num .= $opsStr[$i++];
                }
                $attribs[] = intval($num, 36);
            }
            $lines = 0;
            if ($i < $len && $opsStr[$i] === '|') {
                $i++;
                $num = '';
                while ($i < $len && ctype_alnum($opsStr[$i])) {
                    $num .= $opsStr[$i++];
                }
                $lines = intval($num, 36);
            }
            if ($i >= $len) break;
            $type = $opsStr[$i++];
            if (!in_array($type, ['+', '-', '='])) continue;
            $num = '';
            while ($i < $len && ctype_alnum($opsStr[$i])) {
                $num .= $opsStr[$i++];
            }
            $count   = intval($num, 36);
            $ops[]   = ['type' => $type, 'count' => $count, 'lines' => $lines, 'attribs' => $attribs];
        }

        return ['oldLen' => $oldLen, 'delta' => $delta, 'ops' => $ops, 'bank' => $bank];
    }

    /**
     * Parse an attribution string (from atext.attribs) into an array of runs.
     * Returns array of ['count', 'attribs' => [nums]]
     * Text positions are in Unicode code points (characters).
     */
    public static function parseAttribString(string $attribs): array
    {
        $runs = [];
        $i    = 0;
        $len  = strlen($attribs);
        while ($i < $len) {
            $curAttribs = [];
            while ($i < $len && $attribs[$i] === '*') {
                $i++;
                $num = '';
                while ($i < $len && ctype_alnum($attribs[$i])) {
                    $num .= $attribs[$i++];
                }
                $curAttribs[] = intval($num, 36);
            }
            $lines = 0;
            if ($i < $len && $attribs[$i] === '|') {
                $i++;
                $num = '';
                while ($i < $len && ctype_alnum($attribs[$i])) {
                    $num .= $attribs[$i++];
                }
                $lines = intval($num, 36);
            }
            if ($i >= $len) break;
            $type = $attribs[$i++];
            if ($type !== '+') { $i++; continue; } // only + expected in atext attribs
            $num = '';
            while ($i < $len && ctype_alnum($attribs[$i])) {
                $num .= $attribs[$i++];
            }
            $count   = intval($num, 36);
            $runs[]  = ['count' => $count, 'attribs' => $curAttribs];
        }
        return $runs;
    }

    /**
     * Convert an atext JSON object {text, attribs} into an array of character runs.
     * Run: ['t' => string, 'a' => [attrib_nums]]
     */
    public static function atextToRuns(array $atext): array
    {
        $text      = $atext['text'] ?? '';
        $attribStr = $atext['attribs'] ?? '';

        $attrRuns  = self::parseAttribString($attribStr);
        $runs      = [];
        $textPos   = 0;

        foreach ($attrRuns as $ar) {
            $chars   = mb_substr($text, $textPos, $ar['count'], 'UTF-8');
            $textPos += $ar['count'];
            if ($chars !== '' && $chars !== false) {
                $runs[] = ['t' => $chars, 'a' => $ar['attribs']];
            }
        }

        // Append any remaining text with no attribs
        if ($textPos < mb_strlen($text, 'UTF-8')) {
            $remaining = mb_substr($text, $textPos, null, 'UTF-8');
            if ($remaining !== '') {
                $runs[] = ['t' => $remaining, 'a' => []];
            }
        }

        return $runs;
    }

    /**
     * Convert runs back to atext format {text, attribs}.
     */
    public static function runsToAtext(array $runs): array
    {
        $text    = '';
        $attribs = '';
        foreach ($runs as $run) {
            $t     = $run['t'];
            $a     = $run['a'];
            $count = mb_strlen($t, 'UTF-8');
            if ($count === 0) continue;
            $text .= $t;
            // Count newlines for |L prefix
            $lines = substr_count($t, "\n");
            foreach ($a as $an) {
                $attribs .= '*' . base_convert($an, 10, 36);
            }
            if ($lines > 0) {
                $attribs .= '|' . base_convert($lines, 10, 36);
            }
            $attribs .= '+' . base_convert($count, 10, 36);
        }
        return ['text' => $text, 'attribs' => $attribs];
    }

    /**
     * Apply a changeset to an array of runs.
     * Returns new array of runs.
     */
    public static function applyToRuns(array $runs, string $cs, array $numToAttrib = []): array
    {
        $parsed = self::parseChangeset($cs);
        if (empty($parsed['ops'])) {
            return $runs;
        }

        $bank    = $parsed['bank'];
        $bankPos = 0;
        $newRuns = [];

        // Flatten runs into a character-level cursor for easy traversal
        $cursor  = new EasysyncRunCursor($runs);

        foreach ($parsed['ops'] as $op) {
            $count = $op['count'];
            $type  = $op['type'];
            $opAttribs = $op['attribs'];

            if ($type === '+') {
                // Insert from bank
                $chars = mb_substr($bank, $bankPos, $count, 'UTF-8');
                $bankPos += $count;
                if ($chars !== false && $chars !== '') {
                    $newRuns[] = ['t' => $chars, 'a' => $opAttribs];
                }
            } elseif ($type === '-') {
                // Delete from source
                $cursor->skip($count);
            } elseif ($type === '=') {
                // Keep from source (possibly change attribs)
                $kept = $cursor->take($count);
                if (!empty($opAttribs) && !empty($numToAttrib)) {
                    // Update attributes on kept text
                    $kept = self::updateRunAttribs($kept, $opAttribs, $numToAttrib);
                }
                foreach ($kept as $r) {
                    $newRuns[] = $r;
                }
            }
        }

        // Any remaining source content (shouldn't happen in valid changesets)
        $remaining = $cursor->takeAll();
        foreach ($remaining as $r) {
            $newRuns[] = $r;
        }

        return self::mergeRuns($newRuns);
    }

    /**
     * Update attributes on kept runs when = op has *X prefix.
     * For each attribute in $opAttribs, replace any existing attrib with same key.
     */
    private static function updateRunAttribs(array $runs, array $opAttribs, array $numToAttrib): array
    {
        if (empty($opAttribs)) return $runs;

        // Build map of key => newAttrNum for the op attribs
        $keyToNew = [];
        foreach ($opAttribs as $an) {
            $pair = $numToAttrib[$an] ?? null;
            if ($pair) {
                $keyToNew[$pair[0]] = ['num' => $an, 'val' => $pair[1]];
            }
        }

        $result = [];
        foreach ($runs as $run) {
            $newA = $run['a'];
            // Remove any existing attrib with same key as op attribs
            $newA = array_filter($newA, function($existingAn) use ($keyToNew, $numToAttrib) {
                $existing = $numToAttrib[$existingAn] ?? null;
                if ($existing && isset($keyToNew[$existing[0]])) {
                    return false; // remove, will be replaced
                }
                return true;
            });
            $newA = array_values($newA);
            // Add new attribs (only if value is non-empty)
            foreach ($keyToNew as $key => $info) {
                if ($info['val'] !== '') {
                    $newA[] = $info['num'];
                }
            }
            $result[] = ['t' => $run['t'], 'a' => $newA];
        }
        return $result;
    }

    /**
     * Merge adjacent runs with identical attribute sets.
     */
    public static function mergeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            if (empty($run['t'])) continue;
            if (!empty($merged)) {
                $last = &$merged[count($merged) - 1];
                $sa   = $run['a'];
                $la   = $last['a'];
                sort($sa);
                sort($la);
                if ($sa === $la) {
                    $last['t'] .= $run['t'];
                    continue;
                }
            }
            $merged[] = $run;
        }
        return $merged;
    }

    /**
     * Convert runs to HTML, using the attribute pool.
     * $numToAttrib: array mapping attrNum => [key, value]
     */
    public static function runsToHtml(array $runs, array $numToAttrib, array $authorNames = []): string
    {
        // Split runs into lines
        $lines = self::splitRunsIntoLines($runs);

        $html    = '';
        $listStack = []; // stack of ['type' => string, 'level' => int]

        foreach ($lines as $lineRuns) {
            // Get line-level attributes from first char's attributes
            $lineType  = '';
            $lineLevel = 0;
            $lineLink  = '';

            if (!empty($lineRuns)) {
                $firstAttribs = $lineRuns[0]['a'];
                foreach ($firstAttribs as $an) {
                    $pair = $numToAttrib[$an] ?? null;
                    if (!$pair) continue;
                    if ($pair[0] === 'list' && $pair[1] !== '') {
                        // e.g. "bullet1", "number2", "hone1", "htwo1", "task1", "code1", "indent1", "comment1"
                        if (preg_match('/^(.*?)(\d+)$/', $pair[1], $lm)) {
                            $lineType  = $lm[1];
                            $lineLevel = intval($lm[2]);
                        }
                    }
                }
            }

            // For comment lines, find the author of the first text run
            $lineAuthor = '';
            if ($lineType === 'comment' && !empty($authorNames)) {
                foreach ($lineRuns as $run) {
                    foreach ($run['a'] as $an) {
                        $pair = $numToAttrib[$an] ?? null;
                        if ($pair && $pair[0] === 'author' && isset($authorNames[$pair[1]])) {
                            $lineAuthor = $authorNames[$pair[1]];
                            break 2;
                        }
                    }
                }
            }

            // Close list tags if needed
            $html .= self::adjustLists($listStack, $lineType, $lineLevel);

            // Build inline HTML for this line
            $lineHtml = self::lineRunsToHtml($lineRuns, $numToAttrib, $lineType);

            if ($lineType === 'hone') {
                $html .= '<h2>' . $lineHtml . '</h2>' . "\n";
            } elseif ($lineType === 'htwo') {
                $html .= '<h3>' . $lineHtml . '</h3>' . "\n";
            } elseif ($lineType === 'comment') {
                $indent = max(0, $lineLevel - 1);
                $style  = $indent > 0 ? ' style="margin-left:' . ($indent * 1.5) . 'rem"' : '';
                $authorHtml = $lineAuthor
                    ? '<span class="comment-author">' . htmlspecialchars($lineAuthor, ENT_QUOTES, 'UTF-8') . '</span>'
                    : '';
                $html .= '<div class="pad-comment"' . $style . '>' . $authorHtml . $lineHtml . '</div>' . "\n";
            } elseif (in_array($lineType, ['bullet', 'number', 'task', 'taskdone', 'code', 'indent'])) {
                $tag = ($lineType === 'number') ? 'ol' : 'ul';
                if (empty($listStack) || $listStack[count($listStack) - 1]['type'] !== $lineType
                    || $listStack[count($listStack) - 1]['level'] !== $lineLevel) {
                    if (!empty($listStack) && $listStack[count($listStack) - 1]['level'] < $lineLevel) {
                        $class = '';
                        if ($lineType === 'task') $class = ' class="task"';
                        elseif ($lineType === 'taskdone') $class = ' class="taskdone"';
                        elseif ($lineType === 'code') $class = ' class="code"';
                        elseif ($lineType === 'indent') $class = ' style="list-style:none"';
                        $html .= "<{$tag}{$class}>\n";
                        $listStack[] = ['type' => $lineType, 'level' => $lineLevel];
                    }
                }
                $html .= '<li>' . $lineHtml . '</li>' . "\n";
            } else {
                // Regular paragraph
                if (trim($lineHtml) !== '') {
                    $html .= '<p>' . $lineHtml . '</p>' . "\n";
                } else {
                    $html .= '<p class="blank-line">&nbsp;</p>' . "\n";
                }
            }
        }

        // Close any remaining list tags
        while (!empty($listStack)) {
            $item  = array_pop($listStack);
            $tag   = ($item['type'] === 'number') ? 'ol' : 'ul';
            $html .= "</{$tag}>\n";
        }

        return $html;
    }

    /**
     * Split runs into lines (split on newline characters).
     * Each line's runs do NOT include the trailing newline run.
     */
    private static function splitRunsIntoLines(array $runs): array
    {
        $lines      = [];
        $currentLine = [];

        foreach ($runs as $run) {
            $text  = $run['t'];
            $attribs = $run['a'];

            // Split text on newlines
            $parts = preg_split('/(\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $part) {
                if ($part === "\n") {
                    $lines[]     = $currentLine;
                    $currentLine = [];
                } elseif ($part !== '') {
                    $currentLine[] = ['t' => $part, 'a' => $attribs];
                }
            }
        }
        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    /**
     * Adjust list stack HTML, closing/opening list tags as needed.
     * Returns HTML string for list tag changes.
     */
    private static function adjustLists(array &$listStack, string $newType, int $newLevel): string
    {
        $html = '';

        if (empty($newType)) {
            // Not a list item - close all open lists
            while (!empty($listStack)) {
                $item  = array_pop($listStack);
                $tag   = ($item['type'] === 'number') ? 'ol' : 'ul';
                $html .= "</{$tag}>\n";
            }
            return $html;
        }

        // Close deeper levels or mismatched types
        while (!empty($listStack)) {
            $top = end($listStack);
            if ($top['level'] > $newLevel || ($top['level'] === $newLevel && $top['type'] !== $newType)) {
                array_pop($listStack);
                $tag   = ($top['type'] === 'number') ? 'ol' : 'ul';
                $html .= "</{$tag}>\n";
            } else {
                break;
            }
        }

        // Open new list if needed
        if (empty($listStack) || end($listStack)['level'] < $newLevel) {
            $tag   = ($newType === 'number') ? 'ol' : 'ul';
            $class = '';
            if ($newType === 'task') $class = ' class="task"';
            elseif ($newType === 'taskdone') $class = ' class="taskdone"';
            elseif ($newType === 'code') $class = ' class="code"';
            elseif ($newType === 'indent') $class = ' style="list-style:none"';
            $html .= "<{$tag}{$class}>\n";
            $listStack[] = ['type' => $newType, 'level' => $newLevel];
        }

        return $html;
    }

    /**
     * Convert a single line's runs to inline HTML.
     * Skips the first run if it's the list marker character (*).
     */
    private static function lineRunsToHtml(array $lineRuns, array $numToAttrib, string $lineType): string
    {
        $html        = '';
        $openTags    = []; // stack of open inline tags
        $skipFirst   = !empty($lineType); // skip the list-marker char

        foreach ($lineRuns as $idx => $run) {
            $text    = $run['t'];
            $attribs = $run['a'];

            // Skip the list marker character (* at start of list/heading lines)
            if ($skipFirst) {
                $skipFirst = false;
                // The first char is the list marker; skip it
                if (mb_strlen($text, 'UTF-8') === 1 && $text !== "\n") {
                    continue;
                }
                // If the run has more than just the marker, trim first char
                if (mb_strlen($text, 'UTF-8') > 1) {
                    $text = mb_substr($text, 1, null, 'UTF-8');
                }
            }

            if ($text === '') continue;

            // Determine inline attributes for this run
            $bold        = false;
            $italic      = false;
            $underline   = false;
            $strikethrough = false;
            $superscript = false;
            $subscript   = false;
            $link        = null;
            $img         = null;
            $isCode      = ($lineType === 'code');

            foreach ($attribs as $an) {
                $pair = $numToAttrib[$an] ?? null;
                if (!$pair) continue;
                switch ($pair[0]) {
                    case 'bold':        $bold = $pair[1] === 'true'; break;
                    case 'italic':      $italic = $pair[1] === 'true'; break;
                    case 'underline':   $underline = $pair[1] === 'true'; break;
                    case 'strikethrough': $strikethrough = $pair[1] === 'true'; break;
                    case 'superscript': $superscript = $pair[1] === 'true'; break;
                    case 'subscript':   $subscript = $pair[1] === 'true'; break;
                    case 'link':        $link = $pair[1]; break;
                    case 'img':         $img = $pair[1]; break;
                }
            }

            // Handle image
            if ($text === '*' && $img) {
                $html .= '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" style="max-width:100%">';
                continue;
            }

            $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

            // Wrap in inline tags
            $prefix = '';
            $suffix = '';
            if ($bold)          { $prefix .= '<b>';     $suffix = '</b>' . $suffix; }
            if ($italic)        { $prefix .= '<i>';     $suffix = '</i>' . $suffix; }
            if ($underline)     { $prefix .= '<u>';     $suffix = '</u>' . $suffix; }
            if ($strikethrough) { $prefix .= '<s>';     $suffix = '</s>' . $suffix; }
            if ($superscript)   { $prefix .= '<sup>';   $suffix = '</sup>' . $suffix; }
            if ($subscript)     { $prefix .= '<sub>';   $suffix = '</sub>' . $suffix; }

            $content = $prefix . $escaped . $suffix;

            if ($link) {
                $safeLink = htmlspecialchars($link, ENT_QUOTES);
                $content = '<a href="' . $safeLink . '" target="_blank" rel="noopener">' . $content . '</a>';
            }

            $html .= $content;
        }

        return $html;
    }
}

/**
 * Helper cursor for traversing and splitting runs during changeset application.
 */
class EasysyncRunCursor
{
    private array $runs;
    private int   $runIdx = 0;
    private int   $charOffset = 0; // char offset within current run

    public function __construct(array $runs)
    {
        $this->runs = $runs;
    }

    /**
     * Skip (delete) N characters.
     */
    public function skip(int $n): void
    {
        while ($n > 0 && $this->runIdx < count($this->runs)) {
            $run       = $this->runs[$this->runIdx];
            $remaining = mb_strlen($run['t'], 'UTF-8') - $this->charOffset;
            if ($n >= $remaining) {
                $n -= $remaining;
                $this->runIdx++;
                $this->charOffset = 0;
            } else {
                $this->charOffset += $n;
                $n = 0;
            }
        }
    }

    /**
     * Take N characters and return as array of runs.
     */
    public function take(int $n): array
    {
        $result = [];
        while ($n > 0 && $this->runIdx < count($this->runs)) {
            $run       = $this->runs[$this->runIdx];
            $runText   = $run['t'];
            $runLen    = mb_strlen($runText, 'UTF-8');
            $available = $runLen - $this->charOffset;

            if ($n >= $available) {
                $chunk    = mb_substr($runText, $this->charOffset, null, 'UTF-8');
                $result[] = ['t' => $chunk, 'a' => $run['a']];
                $n       -= $available;
                $this->runIdx++;
                $this->charOffset = 0;
            } else {
                $chunk    = mb_substr($runText, $this->charOffset, $n, 'UTF-8');
                $result[] = ['t' => $chunk, 'a' => $run['a']];
                $this->charOffset += $n;
                $n = 0;
            }
        }
        return $result;
    }

    /**
     * Take all remaining characters.
     */
    public function takeAll(): array
    {
        return $this->take(PHP_INT_MAX);
    }
}
