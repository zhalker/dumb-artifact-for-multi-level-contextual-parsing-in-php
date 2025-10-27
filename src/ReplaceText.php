<?php

namespace DumbContextualParser;

class ReplaceText {

    private static ?ReplaceText $instance = null;

    private function __construct() {
    }

    /**
     * Replaces blocks delimited by open/close markers using a custom pattern or callback.
     *
     * @param string $text Original text
     * @param string|array $open Opening marker(s)
     * @param string|array $close Closing marker(s)
     * @param string|callable|null $pattern Replacement pattern or callback
     *   - If string: uses sprintf-like replacement, where `%s` = inner text
     *   - If callable: function(string $inner, array $block): string
     * @return string Modified text
     */
    public static function replace(string $text, string|array $open, string|array $close, string|callable|null $pattern = null): string {
        if (Self::$instance === null) {
            Self::$instance = new Self();
        }

        if (is_string($open)) $open = [$open];
        if (is_string($close)) $close = [$close];

        if (count($open) != count($close)) {
            throw new \Exception("Error: open and close arrays must match in length");
        }

        $blocks = [];

        foreach ($open as $i => $openChar) {
            $closeChar = $close[$i];
            $blocks = array_merge($blocks, Self::$instance->findBlocks($text, $openChar, $closeChar));
        }

        return Self::$instance->replaceBlocks($text, $blocks, $pattern);
    }

    public static function customReplace(string $text, string|array $open, string|array $close, callable $pattern): string {
        if (Self::$instance === null) {
            Self::$instance = new Self();
        }

        if (is_string($open)) $open = [$open];
        if (is_string($close)) $close = [$close];

        if (count($open) != count($close)) {
            throw new \Exception("Error: open and close arrays must match in length");
        }

        $strings = [];
        $blocks = [];

        foreach ($open as $i => $openChar) {
            $closeChar = $close[$i];
            $blocks = array_merge($blocks, Self::$instance->findBlocks($text, $openChar, $closeChar));
        }

        $strings = Self::$instance->getNoCoveredBlocks($text, $blocks);

        return $pattern($text, $strings, $blocks);
    }

    /**
     * Find all blocks delimited by open/close markers.
     */
    private function findBlocks(string $text, string $open, string $close): array {
        $positions = [];
        $offset = 0;

        $isOpenRegex = $this->isRegex($open);
        $isCloseRegex = $this->isRegex($close);

        while (true) {
            $startInfo = $this->findPattern($text, $open, $offset, $isOpenRegex);
            if ($startInfo === null) break;

            $start = $startInfo['pos'];
            $openLength = $startInfo['length'];
            $openMatched = $startInfo['matched'] ?? $open; // <-- NUEVO

            if ($this->escapePerhaps($text, $start)) {
                $offset = $start + $openLength;
                continue;
            }

            $searchFrom = $start + $openLength;
            $endInfo = null;

            while (true) {
                $endInfo = $this->findPattern($text, $close, $searchFrom, $isCloseRegex);
                if ($endInfo === null) break;

                $end = $endInfo['pos'];

                if (!$this->escapePerhaps($text, $end)) {
                    break;
                }

                $searchFrom = $end + $endInfo['length'];
            }

            if ($endInfo === null) break;

            $closeMatched = $endInfo['matched'] ?? $close; // <-- NUEVO

            $positions[] = [
                'start' => $start,
                'end' => $endInfo['pos'] + $endInfo['length'] - 1,
                'open' => $openMatched,
                'close' => $closeMatched,
            ];

            $offset = $endInfo['pos'] + $endInfo['length'];
        }

        return $positions;
    }

    /*private function findBlocks(string $text, string $open, string $close): array {
        $positions = [];
        $offset = 0;

        while (($start = strpos($text, $open, $offset)) !== false) {
            if ($this->escapePerhaps($text, $start)) {
                $offset = $start + strlen($open);
                continue;
            }

            $searchFrom = $start + strlen($open);
            while (($end = strpos($text, $close, $searchFrom)) !== false) {
                if (!$this->escapePerhaps($text, $end)) {
                    break;
                }
                $searchFrom = $end + strlen($close);
            }

            if ($end === false) break;

            $positions[] = [
                'start' => $start,
                'end' => $end + strlen($close) - 1,
                'open' => $open,
                'close' => $close
            ];

            $offset = $end + strlen($close);
        }

        return $positions;
    }*/

    /* Get the positions not covered by any block */
    private function getNoCoveredBlocks(string $text, array $blocks): array {
        $length = strlen($text);
        $free_ranges = [];

        // Sort blocks by start position
        usort($blocks, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $current = 0;

        foreach ($blocks as $block) {
            $start = (int) $block['start'];
            $end = (int) $block['end'];

            // If there's a gap before the block, add it as free range
            if ($current < $start) {
                $free_ranges[] = [
                    'start' => $current,
                    'end' => $start - 1
                ];
            }

            // Move current to after the block
            $current = $end + 1;
        }

        // Add remaining free range if any
        if ($current <= $length) {
            $free_ranges[] = [
                'start' => $current,
                'end' => $length
            ];
        }

        return $free_ranges;
    }

    /**
     * Replace detected blocks safely, preserving indices by processing from end to start.
     */
    private function replaceBlocks(string $text, array $blocks, string|callable|null $pattern): string {
        usort($blocks, fn($a, $b) => $b['start'] <=> $a['start']);

        foreach ($blocks as $b) {
            $innerStart = $b['start'] + strlen($b['open']);
            $innerEnd = $b['end'] - strlen($b['close']) + 1;
            $inner = substr($text, $innerStart, $innerEnd - $innerStart);

            // Determine replacement
            if (is_callable($pattern)) {
                $replacement = $pattern($inner, $b);
            } else {
                $replacement = sprintf($pattern ?? '%s', $inner);
            }

            $text = substr_replace($text, $replacement, $b['start'], $b['end'] - $b['start'] + 1);
        }

        return $text;
    }

    /**
     * Checks if the character at a given position is escaped by backslashes.
     */
    private function escapePerhaps(string $str, int $pos): bool {
        if ($pos === 0) return false;
        $backslashCount = 0;
        $i = $pos - 1;
        while ($i >= 0 && $str[$i] === '\\') {
            $backslashCount++;
            $i--;
        }
        return ($backslashCount % 2) === 1;
    }

    private function isRegex(string $pattern): bool {
        // Verify typical regex delimiters
        if (strlen($pattern) < 3) return false;

        $delimiters = ['/', '#', '~', '@', ';', '%', '`'];
        $firstChar = $pattern[0];

        if (!in_array($firstChar, $delimiters)) return false;

        // Search for the last occurrence of the delimiter
        $lastDelimiterPos = strrpos($pattern, $firstChar);
        if ($lastDelimiterPos === 0) return false;

        // Verify modifiers
        $modifiers = substr($pattern, $lastDelimiterPos + 1);
        if (!preg_match('/^[imsxADSUXJu]*$/', $modifiers)) return false;

        // Try to compile the regex to ensure it's valid
        return @preg_match($pattern, '') !== false;
    }

    private function findPattern(string $text, string $pattern, int $offset, bool $isRegex): ?array {
        if ($isRegex) {
            $result = preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset);

            if ($result === 1) {
                $matchIndex = isset($matches[1]) ? 1 : 0;
                return [
                    'pos' => $matches[$matchIndex][1],
                    'length' => strlen($matches[$matchIndex][0]),
                    'matched' => $matches[$matchIndex][0] // <-- NUEVO
                ];
            }

            return null;
        } else {
            $pos = strpos($text, $pattern, $offset);

            if ($pos !== false) {
                return [
                    'pos' => $pos,
                    'length' => strlen($pattern),
                    'matched' => $pattern // <-- NUEVO
                ];
            }

            return null;
        }
    }
}
