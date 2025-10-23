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

    /**
     * Find all blocks delimited by open/close markers.
     */
    private function findBlocks(string $text, string $open, string $close): array {
        $positions = [];
        $offset = 0;

        while (($start = strpos($text, $open, $offset)) !== false) {
            if ($this->escapeperhaps($text, $start)) {
                $offset = $start + strlen($open);
                continue;
            }

            $end = strpos($text, $close, $start + strlen($open));
            if ($end === false) break;

            if ($this->escapeperhaps($text, $end)) {
                $offset = $end + strlen($close);
                continue;
            }

            $positions[] = [
                'start' => $start,
                'end' => $end + strlen($close) - 1,
                'open' => $open,
                'close' => $close
            ];

            $offset = $end + strlen($close);
        }

        return $positions;
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
}
