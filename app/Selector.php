<?php
declare(strict_types=1);

final class Selector
{
    public static function query(DOMXPath $xp, DOMNode $context, string $selector): DOMNodeList|false
    {
        $selector = trim($selector);
        if ($selector === '') {
            return false;
        }
        $expr = self::isXpath($selector) ? $selector : self::cssToXpath($selector);
        return @$xp->query($expr, $context);
    }

    public static function first(DOMXPath $xp, DOMNode $context, string $selector): ?DOMNode
    {
        $nodes = self::query($xp, $context, $selector);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        return $nodes->item(0);
    }

    public static function count(DOMXPath $xp, DOMNode $context, string $selector): int
    {
        $nodes = self::query($xp, $context, $selector);
        return $nodes ? $nodes->length : 0;
    }

    public static function cssToXpath(string $css): string
    {
        $groups = self::splitGroups($css);
        $paths = [];
        foreach ($groups as $group) {
            $tokens = self::tokenize($group);
            $path = '';
            $first = true;
            foreach ($tokens as $token) {
                [$combinator, $simple] = $token;
                $simple = trim($simple);
                if ($simple === '') {
                    continue;
                }
                $axis = $first ? './/' : ($combinator === '>' ? '/' : '//');
                $path .= $axis . self::simpleToXpath($simple);
                $first = false;
            }
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return $paths ? implode(' | ', $paths) : './/*';
    }

    private static function isXpath(string $selector): bool
    {
        return str_starts_with($selector, '/') || str_starts_with($selector, './') || str_starts_with($selector, '(') || str_starts_with($selector, './/');
    }

    private static function splitGroups(string $css): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($quote !== '') {
                if ($c === $quote) {
                    $quote = '';
                }
                $buf .= $c;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '[' || $c === '(') {
                $depth++;
            } elseif ($c === ']' || $c === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($c === ',' && $depth === 0) {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    private static function tokenize(string $css): array
    {
        $tokens = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $combinator = ' ';
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($quote !== '') {
                if ($c === $quote) {
                    $quote = '';
                }
                $buf .= $c;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '[' || $c === '(') {
                $depth++;
            } elseif ($c === ']' || $c === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($depth === 0 && ($c === '>' || ctype_space($c))) {
                if (trim($buf) !== '') {
                    $tokens[] = [$combinator, trim($buf)];
                    $buf = '';
                }
                if ($c === '>') {
                    $combinator = '>';
                } elseif ($combinator !== '>') {
                    $combinator = ' ';
                }
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $tokens[] = [$combinator, trim($buf)];
        }
        return $tokens;
    }

    private static function simpleToXpath(string $simple): string
    {
        $simple = preg_replace('/:(first-child|last-child|hover|active|focus|visited|link)/', '', $simple) ?? $simple;
        $simple = preg_replace('/:not\([^)]*\)/', '', $simple) ?? $simple;
        $tag = '*';
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*|^\*/', $simple, $m)) {
            $tag = $m[0] === '*' ? '*' : strtolower($m[0]);
            $simple = substr($simple, strlen($m[0]));
        }
        $conds = [];
        if (preg_match('/#([a-zA-Z0-9_\-:.]+)/', $simple, $m)) {
            $conds[] = '@id=' . self::literal($m[1]);
        }
        if (preg_match_all('/\.([a-zA-Z0-9_\-]+)/', $simple, $matches)) {
            foreach ($matches[1] as $class) {
                $conds[] = "contains(concat(' ', normalize-space(@class), ' '), " . self::literal(' ' . $class . ' ') . ')';
            }
        }
        if (preg_match_all('/\[\s*([a-zA-Z0-9_\-:]+)\s*(?:([*^$|~]?=)\s*["\']?([^"\'\]]+)["\']?)?\s*\]/', $simple, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attr = $m[1];
                $op = $m[2] ?? '';
                $val = $m[3] ?? '';
                if ($op === '') {
                    $conds[] = '@' . $attr;
                } elseif ($op === '=') {
                    $conds[] = '@' . $attr . '=' . self::literal($val);
                } elseif ($op === '*=') {
                    $conds[] = 'contains(@' . $attr . ', ' . self::literal($val) . ')';
                } elseif ($op === '^=') {
                    $conds[] = 'starts-with(@' . $attr . ', ' . self::literal($val) . ')';
                } elseif ($op === '$=') {
                    $conds[] = 'substring(@' . $attr . ', string-length(@' . $attr . ') - string-length(' . self::literal($val) . ') + 1) = ' . self::literal($val);
                }
            }
        }
        if (preg_match('/:nth-of-type\((\d+)\)/', $simple, $m)) {
            $conds[] = 'count(preceding-sibling::' . $tag . ') + 1 = ' . (int)$m[1];
        }
        return $tag . ($conds ? '[' . implode(' and ', $conds) . ']' : '');
    }

    private static function literal(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = explode("'", $value);
        return "concat('" . implode("', \"'\", '", $parts) . "')";
    }
}
