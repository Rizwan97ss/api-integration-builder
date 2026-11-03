<?php

namespace Rizwan\IntegrationBuilder\Services;

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Safely adds generated code to existing PHP files.
 *
 * Existing methods, imports and routes are never modified or removed; only
 * missing ones are appended.
 */
class PhpFileMerger
{
    /**
     * List the names of functions and methods declared in PHP code.
     *
     * @return list<string>
     */
    public function methodNames(string $code): array
    {
        $tokens = PhpToken::tokenize($code);
        $names = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_FUNCTION)) {
                continue;
            }

            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                if ($tokens[$next]->isIgnorable() || $tokens[$next]->text === '&') {
                    continue;
                }

                if ($tokens[$next]->is(T_STRING)) {
                    $names[] = $tokens[$next]->text;
                }

                break;
            }
        }

        return $names;
    }

    /**
     * Add methods missing from a class.
     *
     * @param  array<string, string>  $methods  Method code keyed by method name.
     * @return array{contents: string, added: list<string>, existing: list<string>}
     */
    public function addMethods(string $code, array $methods): array
    {
        $declared = array_map(strtolower(...), $this->methodNames($code));

        $added = [];
        $existing = [];

        foreach ($methods as $name => $method) {
            if (in_array(strtolower($name), $declared, true)) {
                $existing[] = $name;
            } else {
                $added[$name] = $method;
            }
        }

        if ($added === []) {
            return ['contents' => $code, 'added' => [], 'existing' => $existing];
        }

        $closingBrace = strrpos($code, '}');

        if ($closingBrace === false) {
            throw new RuntimeException('Could not find the end of the class.');
        }

        $body = rtrim(substr($code, 0, $closingBrace));
        $body = preg_replace('/\{\s*\/\/\s*$/', '{', $body) ?? $body;
        $separator = str_ends_with($body, '{') ? "\n" : "\n\n";

        $contents = $body.$separator.implode("\n\n", $added)."\n}".substr($code, $closingBrace + 1);

        return ['contents' => $contents, 'added' => array_keys($added), 'existing' => $existing];
    }

    /**
     * Add `use` imports missing from the top of a file, keeping them sorted.
     *
     * @param  list<string>  $imports  Fully qualified class names.
     */
    public function addImports(string $code, array $imports): string
    {
        $headerEnd = preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s|^Route::|^return\s/m', $code, $match, PREG_OFFSET_CAPTURE)
            ? $match[0][1]
            : strlen($code);

        $header = substr($code, 0, $headerEnd);

        preg_match_all('/^use\s+([^;]+);\R/m', $header, $existing, PREG_OFFSET_CAPTURE);

        $current = array_map(fn (array $import): string => trim($import[0]), $existing[1]);
        $missing = array_values(array_diff($imports, $current));

        if ($missing === []) {
            return $code;
        }

        $all = array_values(array_unique([...$current, ...$missing]));
        usort($all, fn (string $a, string $b): int => strcasecmp($a, $b));
        $block = implode('', array_map(fn (string $import): string => "use {$import};\n", $all));

        if ($existing[0] !== []) {
            $start = $existing[0][0][1];
            $last = end($existing[0]);
            $end = $last[1] + strlen($last[0]);

            if ($this->onlyImportsBetween(substr($code, $start, $end - $start))) {
                return substr($code, 0, $start).$block.substr($code, $end);
            }

            return substr($code, 0, $end).implode('', array_map(fn (string $import): string => "use {$import};\n", $missing)).substr($code, $end);
        }

        if (preg_match('/^namespace\s+[^;]+;\R/m', $header, $namespace, PREG_OFFSET_CAPTURE)) {
            $position = $namespace[0][1] + strlen($namespace[0][0]);

            return substr($code, 0, $position)."\n".$block.substr($code, $position);
        }

        $position = str_starts_with($code, '<?php') ? strlen("<?php\n") : 0;

        return substr($code, 0, $position)."\n".$block.substr($code, $position);
    }

    /**
     * Add routes to a controller's route group, creating the group when missing.
     *
     * A route is treated as existing when its controller method, or its HTTP
     * method and URI, is already declared in the group.
     *
     * @param  string  $group  The complete group to append when the controller has none yet.
     * @param  array<string, array{verb: string, uri: string, line: string}>  $routes  Keyed by controller method.
     * @return array{contents: string, added: list<string>, existing: list<string>}
     */
    public function addControllerRoutes(string $code, string $controller, string $group, array $routes): array
    {
        $marker = "->controller({$controller}::class)";
        $position = strpos($code, $marker);

        if ($position === false) {
            return ['contents' => rtrim($code)."\n\n".$group."\n", 'added' => array_keys($routes), 'existing' => []];
        }

        $groupEnd = strpos($code, '});', $position);

        if ($groupEnd === false) {
            throw new RuntimeException("Could not find the end of the {$controller} route group.");
        }

        preg_match_all("/Route::(\w+)\(\s*'([^']*)'\s*,\s*'([A-Za-z0-9_\/\-.]+)'/", substr($code, $position, $groupEnd - $position), $matches, PREG_SET_ORDER);

        $declaredMethods = array_map(fn (array $match): string => strtolower($match[3]), $matches);
        $declaredUris = array_map(fn (array $match): string => strtolower($match[1].' '.trim($match[2], '/')), $matches);

        $added = [];
        $existing = [];

        foreach ($routes as $method => $route) {
            $signature = strtolower($route['verb'].' '.trim($route['uri'], '/'));

            if (in_array(strtolower($method), $declaredMethods, true) || in_array($signature, $declaredUris, true)) {
                $existing[] = $method;
            } else {
                $added[$method] = $route['line'];
            }
        }

        if ($added === []) {
            return ['contents' => $code, 'added' => [], 'existing' => $existing];
        }

        $lineStart = (int) strrpos(substr($code, 0, $groupEnd), "\n") + 1;
        $contents = rtrim(substr($code, 0, $lineStart))."\n".implode("\n", $added)."\n".substr($code, $lineStart);

        return ['contents' => $contents, 'added' => array_keys($added), 'existing' => $existing];
    }

    /**
     * Ensure PHP code has valid syntax.
     *
     * @throws ParseError
     */
    public function assertValidSyntax(string $code): void
    {
        PhpToken::tokenize($code, TOKEN_PARSE);
    }

    /**
     * Determine whether a block of lines only contains `use` imports.
     */
    protected function onlyImportsBetween(string $block): bool
    {
        foreach (preg_split('/\R/', trim($block)) ?: [] as $line) {
            if (! preg_match('/^use\s+[^;]+;$/', trim($line))) {
                return false;
            }
        }

        return true;
    }
}
