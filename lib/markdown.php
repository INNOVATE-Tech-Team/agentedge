<?php
// Small, dependency-free Markdown renderer scoped to what the LAUNCH
// Curriculum content actually uses: headers (#/##/###), bold/italic/inline
// code, fenced code blocks (worksheets), bullet + numbered lists, and GFM-
// style pipe tables. Not a general-purpose Markdown implementation — no
// links, images, blockquotes, or nested lists, because the source content
// doesn't use them. Output is escaped before any markup is applied, so raw
// HTML in the source text is never rendered.
if (defined('AGENTEDGE_MARKDOWN_LOADED')) return;
define('AGENTEDGE_MARKDOWN_LOADED', true);

function _launch_md_inline(string $text): string {
    $t = htmlspecialchars($text, ENT_QUOTES);
    $t = preg_replace('/`([^`]+?)`/', '<code>$1</code>', $t);
    $t = preg_replace('/\*\*([^\*]+?)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<!\*)\*(?!\*)([^\*]+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $t);
    return $t;
}

function _launch_md_table_row(string $line): array {
    $line = trim($line);
    $line = preg_replace('/^\||\|$/', '', $line);
    return array_map('trim', explode('|', $line));
}

function render_launch_markdown(string $md): string {
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $n = count($lines);
    $out = '';
    $i = 0;

    $paraBuf = [];
    $flushPara = function () use (&$paraBuf, &$out) {
        if (!$paraBuf) return;
        $out .= '<p>' . _launch_md_inline(implode(' ', $paraBuf)) . '</p>';
        $paraBuf = [];
    };

    while ($i < $n) {
        $line = $lines[$i];
        $trimmed = trim($line);

        // Fenced code block
        if (preg_match('/^```/', $trimmed)) {
            $flushPara();
            $i++;
            $buf = [];
            while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) { $buf[] = $lines[$i]; $i++; }
            $i++; // skip closing fence
            $out .= '<pre class="lc-code">' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES) . '</pre>';
            continue;
        }

        // Headers
        if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m)) {
            $flushPara();
            $level = strlen($m[1]) + 1; // ## -> h3, ### -> h4 (h1/h2 reserved for page chrome)
            $level = min($level, 6);
            $out .= "<h{$level}>" . _launch_md_inline($m[2]) . "</h{$level}>";
            $i++;
            continue;
        }

        // Table (header row + separator row of dashes/pipes)
        if (strpos($trimmed, '|') !== false && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim($lines[$i + 1]))) {
            $flushPara();
            $header = _launch_md_table_row($trimmed);
            $i += 2;
            $rows = [];
            while ($i < $n && strpos(trim($lines[$i]), '|') !== false && trim($lines[$i]) !== '') {
                $rows[] = _launch_md_table_row($lines[$i]);
                $i++;
            }
            $out .= '<div class="lc-table-wrap"><table class="lc-table"><thead><tr>';
            foreach ($header as $h) $out .= '<th>' . _launch_md_inline($h) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $out .= '<tr>';
                foreach ($r as $cell) $out .= '<td>' . _launch_md_inline($cell) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table></div>';
            continue;
        }

        // Bullet list
        if (preg_match('/^-\s+(.*)$/', $trimmed, $m)) {
            $flushPara();
            $out .= '<ul>';
            while ($i < $n && preg_match('/^-\s+(.*)$/', trim($lines[$i]), $m2)) {
                $out .= '<li>' . _launch_md_inline($m2[1]) . '</li>';
                $i++;
            }
            $out .= '</ul>';
            continue;
        }

        // Numbered list
        if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
            $flushPara();
            $out .= '<ol>';
            while ($i < $n && preg_match('/^\d+\.\s+(.*)$/', trim($lines[$i]), $m2)) {
                $out .= '<li>' . _launch_md_inline($m2[1]) . '</li>';
                $i++;
            }
            $out .= '</ol>';
            continue;
        }

        // Blank line ends a paragraph
        if ($trimmed === '') {
            $flushPara();
            $i++;
            continue;
        }

        // Plain paragraph text — accumulate until a blank line or a block-level line
        $paraBuf[] = $trimmed;
        $i++;
    }
    $flushPara();
    return $out;
}
