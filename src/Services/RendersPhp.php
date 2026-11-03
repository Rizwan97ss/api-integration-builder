<?php

namespace DevForge\ApiKit\Services;

/**
 * Helpers for rendering PHP source code.
 */
trait RendersPhp
{
    /**
     * Indent a single line by the given depth.
     */
    protected function indent(int $depth): string
    {
        return str_repeat('    ', $depth);
    }

    /**
     * Render a PHPDoc block.
     *
     * @param  list<string>  $lines
     */
    protected function docBlock(array $lines, int $depth): string
    {
        $indent = $this->indent($depth);

        return $indent."/**\n"
            .implode("\n", array_map(fn (string $line): string => rtrim($indent.' * '.$line), $lines))
            ."\n{$indent} */";
    }

    /**
     * Export a value as PHP code using short array syntax.
     */
    protected function export(mixed $value, int $depth = 0): string
    {
        if (! is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => var_export($value, true),
            };
        }

        if ($value === []) {
            return '[]';
        }

        $indent = $this->indent($depth + 1);
        $isList = array_is_list($value);

        $lines = array_map(
            fn (mixed $item, int|string $key): string => $indent.($isList ? '' : var_export($key, true).' => ').$this->export($item, $depth + 1).',',
            $value,
            array_keys($value),
        );

        return "[\n".implode("\n", $lines)."\n".$this->indent($depth).']';
    }

    /**
     * Export a flat array on a single line.
     *
     * @param  array<array-key, scalar|null>  $value
     */
    protected function exportInline(array $value): string
    {
        $isList = array_is_list($value);

        $items = array_map(
            fn (mixed $item, int|string $key): string => ($isList ? '' : var_export($key, true).' => ').$this->export($item),
            $value,
            array_keys($value),
        );

        return '['.implode(', ', $items).']';
    }

    /**
     * Render a nested array whose leaves are already PHP expressions.
     *
     * @param  array<array-key, mixed>  $tree
     */
    protected function exportExpressions(array $tree, int $depth): string
    {
        $indent = $this->indent($depth + 1);
        $lines = [];

        foreach ($tree as $key => $value) {
            $lines[] = $indent.var_export($key, true).' => '.(is_array($value) ? $this->exportExpressions($value, $depth + 1) : $value).',';
        }

        return "[\n".implode("\n", $lines)."\n".$this->indent($depth).']';
    }

    /**
     * Render a method call, breaking arguments over lines when there are many.
     *
     * @param  list<string>  $arguments
     */
    protected function call(string $callee, array $arguments, int $depth): string
    {
        if (count($arguments) <= 3) {
            return $callee.'('.implode(', ', $arguments).')';
        }

        return $callee."(\n"
            .implode("\n", array_map(fn (string $argument): string => $this->indent($depth + 1).$argument.',', $arguments))
            ."\n".$this->indent($depth).')';
    }

    /**
     * Make user-provided text safe to place inside a PHP comment.
     */
    protected function sanitizeComment(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['*/', '/*'], ['* /', '/ *'], $text)));
    }
}
