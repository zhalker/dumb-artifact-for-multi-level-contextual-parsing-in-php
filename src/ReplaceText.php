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
     *
     * @param string $text   Input text
     * @param string $open   Opening delimiter (literal or regex)
     * @param string $close  Closing delimiter (literal or regex)
     * @return array<int, array{open: array<string, mixed>, close: array<string, mixed>|null}>
     */
    private function findBlocks(string $text, string $open, string $close): array {
        $positions = [];
        $offset = 0;

        if ($open === '' || $close === '') {
            throw new \InvalidArgumentException("Delimiters cannot be empty.");
        }


        $isOpenRegex = $this->isRegex($open);
        $isCloseRegex = $this->isRegex($close);

        while (true) {
            $startInfo = $this->findPattern($text, $open, $offset, $isOpenRegex);
            if ($startInfo === null) break;

            $openStart = $startInfo['match']['start'];
            $openEnd = $startInfo['match']['end'];
            $openLength = $startInfo['match']['length'];
            $openMatched = $startInfo['match']['value'];
            $openGroups = $startInfo['groups'];

            if ($this->escapePerhaps($text, $openStart)) {
                $offset = $openEnd;
                continue;
            }

            $searchFrom = $openEnd;
            $endInfo = null;

            while (true) {
                $endInfo = $this->findPattern($text, $close, $searchFrom, $isCloseRegex);

                if ($endInfo === null) {

                    $positions[] = [
                        'open' => ['start' => $openStart, 'end' => $openEnd, 'length' => $openLength, 'matched' => $openMatched, 'groups' => $openGroups],
                        'close' => null,
                    ];

                    $offset = $openEnd;

                    break;
                }

                $closeStart = $endInfo['match']['start'];
                $closeEnd = $endInfo['match']['end'];
                $closeLength = $endInfo['match']['length'];
                $closeMatched = $endInfo['match']['value'];
                $closeGroups = $endInfo['groups'];

                if ($this->escapePerhaps($text, $closeStart)) {
                    $searchFrom = $closeEnd;
                    continue;
                }

                $positions[] = [
                    'open' => ['start' => $openStart, 'end' => $openEnd, 'length' => $openLength, 'matched' => $openMatched, 'groups' => $openGroups],
                    'close' => ['start' => $closeStart, 'end' => $closeEnd, 'length' => $closeLength, 'matched' => $closeMatched, 'groups' => $closeGroups],
                ];

                $offset = $closeEnd;

                break;
            }
        }

        return $positions;
    }

    /**
     * Get positions not covered by any block.
     *
     * @param string $text
     * @param array<int, array{open: array<string, mixed>, close: array<string, mixed>|null}> $blocks
     * @return array<int, array{start: int, end: int}>
     */
    private function getNoCoveredBlocks(string $text, array $blocks): array {
        $length = strlen($text);
        $free_ranges = [];

        // Ordenar bloques por inicio
        usort($blocks, fn($a, $b) => $a['open']['start'] <=> $b['open']['start']);

        $current = 0;

        foreach ($blocks as $block) {
            $start = $block['open']['start'];
            $end = $block['close']['end'] ?? $block['open']['end'];

            // Si hay un hueco antes del bloque, añadirlo
            if ($current < $start) {
                $free_ranges[] = [
                    'start' => $current,
                    'end' => $start
                ];
            }

            // Mover current al final del bloque
            $current = $end;
        }

        // Rango libre al final del texto
        if ($current < $length) {
            $free_ranges[] = [
                'start' => $current,
                'end' => $length
            ];
        }

        return $free_ranges;
    }

    /**
     * Replace detected blocks safely, preserving indices by processing from end to start.
     *
     * @param string $text
     * @param array<int, array{open: array<string, mixed>, close: array<string, mixed>|null}> $blocks
     * @param string|callable|null $pattern
     * @return string
     */
    private function replaceBlocks(string $text, array $blocks, string|callable|null $pattern): string {
        // Procesar desde el final hacia el inicio
        usort($blocks, fn($a, $b) => $b['open']['start'] <=> $a['open']['start']);

        foreach ($blocks as $b) {
            $openEnd = $b['open']['end'];
            $closeStart = $b['close']['start'] ?? $openEnd;
            $closeEnd = $b['close']['end'] ?? $openEnd;

            $innerLength = $closeStart - $openEnd;
            $inner = substr($text, $openEnd, $innerLength);

            // Determinar replacement
            if (is_callable($pattern)) {
                $replacement = $pattern($inner, $b);
            } else {
                $replacement = sprintf($pattern ?? '%s', $inner);
            }

            // Reemplazar todo el bloque desde open start hasta close end
            $blockStart = $b['open']['start'];
            $blockLength = $closeEnd - $blockStart;
            $text = substr_replace($text, $replacement, $blockStart, $blockLength);
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

    /* Find first regex or literal pattern match with detailed positions */
    private function findPattern(string $text, string $pattern, int $offset = 0, bool $isRegex = false): ?array {
        if ($isRegex) {
            $found = preg_match(
                $pattern,
                $text,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                $offset
            );

            // Si no hay coincidencias, devolver null (más semántico que [])
            if ($found === 0 || $found === false) {
                return null;
            }

            // Información del match completo
            $fullValue = $matches[0][0];
            $fullStart = $matches[0][1];
            $fullEnd   = $fullStart + strlen($fullValue);
            $fullLen   = strlen($fullValue);

            $entry = [
                'match' => [
                    'value'  => $fullValue,
                    'start'  => $fullStart,
                    'end'    => $fullEnd,
                    'length' => $fullLen
                ],
                'groups' => []
            ];

            // Iterar sobre todos los grupos (saltando el 0 que es el match completo)
            foreach ($matches as $key => $group) {
                if ($key === 0) continue;

                $groupKey = is_int($key) ? "group_{$key}" : $key;
                $value = $group[0];
                $start = $group[1];

                if ($value === null || $start === -1) {
                    $entry['groups'][$groupKey] = [
                        'value'  => null,
                        'start'  => null,
                        'end'    => null,
                        'length' => 0
                    ];
                    continue;
                }

                $length = strlen($value);
                $end    = $start + $length;

                $entry['groups'][$groupKey] = [
                    'value'  => $value,
                    'start'  => $start,
                    'end'    => $end,
                    'length' => $length
                ];
            }

            return $entry;
        }

        // Modo literal (no regex)
        $pos = strpos($text, $pattern, $offset);

        if ($pos === false) {
            return null;
        }

        return [
            'match' => [
                'value'  => $pattern,
                'start'  => $pos,
                'end'    => $pos + strlen($pattern),
                'length' => strlen($pattern)
            ],
            'groups' => []
        ];
    }
}
