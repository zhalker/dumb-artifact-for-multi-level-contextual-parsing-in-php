<?php

namespace DumbContextualParser;

/**
 * A simple, context-aware text replacement utility.
 *
 * Features:
 * - Replace text between specified delimiters (e.g., quotes, brackets).
 * - Handle nested contexts with scoped replacements.
 * - Ignore replacements inside comments (single-line and block).
 * - Support for escaped delimiters.
 *
 * Usage:
 * 1. Use `ReplaceText::replace` for basic replacements.
 * 2. Use `ScopedReplaceText::scopedReplaceAll` to limit replacements to specific sections.
 * 3. Use `ContextualReplaceText::applyContexts` for complex nested rules.
 *
 */

class ContextualReplaceText {
    /**
     * Applies nested contextual replacement rules to the text
     *
     * @param string $text The text to process
     * @param array $rules Array of replacement rules with nested contexts
     * @return string The processed text
     */
    public static function applyContexts(string $text, array $rules): string {
        foreach ($rules as $rule) {
            $text = self::processRule($text, $rule);
        }
        return $text;
    }

    /**
     * Processes a single replacement rule
     *
     * @param string $text The text to process
     * @param array $rule The rule configuration
     * @return string The processed text
     */
    private static function processRule(string $text, array $rule): string {
        // Process outer scope first

        $text = ScopedReplaceText::scopedReplaceAll(
            $text,
            $rule['scope_start'],
            $rule['scope_end'],
            $rule['self_replace']['open'],
            $rule['self_replace']['close'],
            function ($inner, $b) use ($rule) {
                if (!empty($rule['inner_scopes'])) {
                    foreach ($rule['inner_scopes'] as $innerRule) {
                        $inner = ReplaceText::replace(
                            $inner,
                            $innerRule['self_replace']['open'],
                            $innerRule['self_replace']['close'],
                            $innerRule['self_replace']['pattern']
                        );
                    }
                }

                return sprintf($rule['self_replace']['pattern'], $inner);
            }
        );

        return $text;
    }
}

class ScopedReplaceText {

    /**
     * Applies ReplaceText::replace inside all delimited sections of the text.
     *
     * @param string $text Full text
     * @param string $startMarker Start of section
     * @param string $endMarker End of section
     * @param string|array $open Open delimiter for ReplaceText
     * @param string|array $close Close delimiter for ReplaceText
     * @param string|callable|null $pattern Replacement pattern or callback
     * @return string Modified text
     */
    public static function scopedReplaceAll(
        string $text,
        string $startMarker,
        string $endMarker,
        string|array $open,
        string|array $close,
        string|callable|null $pattern = '".(%s)."'
    ): string {
        $result = '';
        $offset = 0;

        while (true) {
            $start = strpos($text, $startMarker, $offset);
            if ($start === false) {
                // Append remaining part of text (no more sections)
                $result .= substr($text, $offset);
                break;
            }

            // Append part before section
            $result .= substr($text, $offset, $start - $offset);

            $sectionStart = $start + strlen($startMarker);

            $end = strpos($text, $endMarker, $sectionStart) ?: strlen($text) - strlen($endMarker);

            if ($end === false) {
                // If no closing marker, append the rest and stop
                $result .= substr($text, $start);
                break;
            }

            $section = substr($text, $sectionStart, $end - $sectionStart);

            // Apply ReplaceText only inside this section
            $replacedSection = self::replaceOutsideComments($section, $open, $close, $pattern);

            // Append rebuilt section
            $result .= $startMarker . $replacedSection . $endMarker;

            // Move offset past the current section
            $offset = $end + strlen($endMarker);
        }

        return $result;
    }

    private static function replaceOutsideComments(string $text, string|array $open, string|array $close, string|callable|null $pattern = '".(%s)."'): string {
        // Expresiones que detectan comentarios simples y de bloque
        $regex = '/(\/\*[\s\S]*?\*\/|\/\/[^\n]*)/';
        $parts = preg_split($regex, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = '';
        $isComment = false;

        foreach ($parts as $part) {
            if ($isComment) {
                $result .= $part;
            } else {
                $result .= ReplaceText::replace($part, $open, $close, $pattern);
            }
            $isComment = !$isComment;
        }

        return $result;
    }
}

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
    public static function replace(string $text, string|array $open, string|array $close, string|callable|null $pattern = '".(%s)."'): string {
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
