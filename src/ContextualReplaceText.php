<?php

namespace DumbContextualParser;

use DumbContextualParser\ReplaceText;
use DumbContextualParser\ScopedReplaceText;

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
            $rule['self_replace']['block']['open'],
            $rule['self_replace']['block']['close'],
            function ($inner) use ($rule) {
                if (!empty($rule['inner_scopes'])) {
                    foreach ($rule['inner_scopes'] as $innerRule) {

                        if (!empty($innerRule['self_replace']['block'])) {
                            $inner = ReplaceText::replace(
                                $inner,
                                $innerRule['self_replace']['block']['open'],
                                $innerRule['self_replace']['block']['close'],
                                $innerRule['self_replace']['block']['pattern']
                            );
                        }

                        if (!empty($innerRule['self_replace']['token'])) {
                            $inner = self::processTokenReplacement($inner, $innerRule['self_replace']['token']);
                        }
                    }
                }

                return is_callable($rule['self_replace']['block']['pattern']) ? $rule['self_replace']['block']['pattern']($inner) : sprintf($rule['self_replace']['block']['pattern'], $inner);
            }
        );

        return $text;
    }

    private static function processTokenReplacement(string $inner, array $tokenRule): string {
        $search  = $tokenRule['search'] ?? '';
        $subject = $tokenRule['subject'] ?? '';

        $inner = preg_match('/^([#~%\/]).+\1[imsxuADSUXJ]*$/', $search)
            ? preg_replace($search ?? '', $subject ?? '', $inner)
            : str_replace($search ?? '', $subject ?? '', $inner);

        return $inner;
    }
}
