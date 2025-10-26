<?php

namespace DumbContextualParser;

class ScopedReplaceText {

    private function __construct() {
    }

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
        string|callable|null $pattern = '%s'
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

            if (strpos($text, $endMarker, $sectionStart) === false) {
                $text .=  ' ' . $endMarker;
            }

            $end = strpos($text, $endMarker, $sectionStart);

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

    private static function replaceOutsideComments(string $text, string|array $open, string|array $close, string|callable|null $pattern = '%s'): string {
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
