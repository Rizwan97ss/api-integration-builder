<?php

namespace Rizwan\IntegrationBuilder\Credentials;

use Illuminate\Support\Facades\File;
use PhpToken;
use Rizwan\IntegrationBuilder\Services\RendersPhp;
use RuntimeException;

/**
 * Rewrites the `credentials` section of config/integration-builder.php.
 *
 * Only that section changes; every other setting and comment in the file
 * is kept, and the previous file is copied to the builder's backups first.
 */
class CredentialConfigWriter
{
    use RendersPhp;

    /**
     * Replace the credentials section and return the backup path.
     *
     * @param  array<string, mixed>  $database  The database driver settings.
     * @param  array<string, mixed>  $config  The config driver settings.
     */
    public function write(string $driver, array $database, array $config, ?string $path = null): string
    {
        $path ??= config_path('integration-builder.php');

        if (! File::exists($path)) {
            throw new RuntimeException("{$path} does not exist. Publish the integration builder config first.");
        }

        $original = File::get($path);
        [$start, $end] = $this->locate($original);
        $contents = substr($original, 0, $start).$this->render($driver, $database, $config).substr($original, $end);

        PhpToken::tokenize($contents, TOKEN_PARSE);

        $backup = rtrim((string) config('integration-builder.backup_path'), '/\\').'/'.now()->format('Ymd_His').'/config/'.basename($path);
        File::ensureDirectoryExists(dirname($backup));
        File::copy($path, $backup);
        File::put($path, $contents);

        return $backup;
    }

    /**
     * Render the credentials section.
     *
     * @param  array<string, mixed>  $database
     * @param  array<string, mixed>  $config
     */
    public function render(string $driver, array $database, array $config): string
    {
        $model = $database['model'] ?? null;
        unset($database['model'], $database['connection']);

        $databaseLines = explode("\n", $this->export($database, 2));
        array_splice($databaseLines, 1, 0, [
            $this->indent(3)."'connection' => env('INTEGRATION_BUILDER_CREDENTIALS_CONNECTION'),",
            $this->indent(3)."'model' => ".(is_string($model) && $model !== '' ? '\\'.ltrim($model, '\\').'::class' : 'null').',',
        ]);

        return implode("\n", [
            "'credentials' => [",
            '',
            $this->indent(2)."'driver' => env('INTEGRATION_BUILDER_CREDENTIALS', ".$this->export($driver).'),',
            '',
            $this->indent(2)."'database' => ".implode("\n", $databaseLines).',',
            '',
            $this->indent(2)."'config' => ".$this->export($config === [] ? ['providers' => []] : $config, 2).',',
            '',
            $this->indent(2)."'custom' => env('INTEGRATION_BUILDER_CREDENTIALS_RESOLVER'),",
            '',
            $this->indent(1).'],',
        ]);
    }

    /**
     * Find the byte range of `'credentials' => [ ... ],` in the config file.
     *
     * @return array{0: int, 1: int}
     */
    protected function locate(string $code): array
    {
        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING) || trim($tokens[$i]->text, '\'"') !== 'credentials') {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $i);

            if ($next === null || ! $tokens[$next]->is(T_DOUBLE_ARROW)) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $next);

            if ($open === null || $tokens[$open]->text !== '[') {
                continue;
            }

            $depth = 0;

            for ($j = $open; $j < $count; $j++) {
                $depth += match ($tokens[$j]->text) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $comma = $this->nextMeaningful($tokens, $j);
                    $last = $comma !== null && $tokens[$comma]->text === ',' ? $tokens[$comma] : $tokens[$j];

                    return [$tokens[$i]->pos, $last->pos + strlen($last->text)];
                }
            }
        }

        throw new RuntimeException("Could not find the 'credentials' section in the integration builder config.");
    }

    /**
     * Get the index of the next token that is not whitespace or a comment.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    protected function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
