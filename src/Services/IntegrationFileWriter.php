<?php

namespace DevForge\ApiDev\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use ParseError;

/**
 * Writes approved files from a {@see CodeGenerator} plan.
 *
 * Only files the user approved are written, and only if their contents are
 * exactly what was previewed. Existing files are backed up first.
 */
class IntegrationFileWriter
{
    /**
     * Directories generated files may be written to.
     *
     * @var list<string>
     */
    public const array ALLOWED_DIRECTORIES = [
        'app/Http/Controllers/',
        'app/Http/Requests/',
        'app/Models/',
        'database/migrations/',
        'resources/js/pages/',
        'resources/views/',
    ];

    /**
     * Individual files generated code may be added to.
     *
     * @var list<string>
     */
    public const array ALLOWED_FILES = [CodeGenerator::ROUTES_FILE];

    public function __construct(protected PhpFileMerger $merger) {}

    /**
     * Write the approved files of a plan.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<array{path: string, hash: string}>  $approvals
     * @return list<array{path: string, status: string, written: bool}>
     *
     * @throws ValidationException
     */
    public function write(array $plan, array $approvals): array
    {
        $files = collect($plan)->keyBy('path');
        $approved = collect($approvals)->keyBy('path');

        $errors = $this->verify($files->all(), $approved->all());

        if ($errors !== []) {
            throw ValidationException::withMessages(
                collect($errors)->mapWithKeys(fn (string $error, int $index): array => ["approved.{$index}" => $error])->all(),
            );
        }

        $backupDirectory = rtrim((string) config('integration-builder.backup_path'), '/\\').'/'.now()->format('Ymd_His');
        $results = [];

        foreach ($approved->keys() as $path) {
            $file = $files->get($path);

            if (in_array($file['status'], ['unchanged', 'skip'], true)) {
                $results[] = ['path' => $path, 'status' => $file['status'], 'written' => false];

                continue;
            }

            $absolute = base_path($path);

            if (File::exists($absolute)) {
                File::ensureDirectoryExists(dirname($backupDirectory.'/'.$path));
                File::copy($absolute, $backupDirectory.'/'.$path);
            }

            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, $file['contents']);

            $results[] = ['path' => $path, 'status' => $file['status'], 'written' => true];
        }

        return $results;
    }

    /**
     * Check every approval before anything is written.
     *
     * @param  array<string, array<string, mixed>>  $files
     * @param  array<string, array{path: string, hash: string}>  $approved
     * @return list<string>
     */
    protected function verify(array $files, array $approved): array
    {
        $errors = [];

        foreach ($approved as $path => $approval) {
            $file = $files[$path] ?? null;

            if ($file === null) {
                $errors[] = "{$path} is not part of the generated code.";

                continue;
            }

            if (! hash_equals($file['hash'], $approval['hash'])) {
                $errors[] = "{$path} changed since it was previewed. Preview the code again before approving.";
            }

            if (! $this->isAllowedPath($path)) {
                $errors[] = "{$path} is outside the directories the builder may write to.";
            }

            try {
                if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                    $this->merger->assertValidSyntax($file['contents']);
                }
            } catch (ParseError $exception) {
                $errors[] = "{$path} has a syntax error on line {$exception->getLine()}: {$exception->getMessage()}";
            }

            foreach ($file['requires'] as $required) {
                if (! isset($approved[$required]) && ! File::exists(base_path($required))) {
                    $errors[] = "{$path} depends on {$required}; approve that file as well.";
                }
            }
        }

        return $errors;
    }

    /**
     * Determine whether a relative path is inside an allowed directory.
     */
    protected function isAllowedPath(string $path): bool
    {
        if (str_contains($path, '..') || ! preg_match('#^[A-Za-z0-9_/\-.]+\.(php|vue|tsx)$#', $path)) {
            return false;
        }

        if ((str_ends_with($path, '.vue') || str_ends_with($path, '.tsx')) && ! str_starts_with($path, 'resources/js/pages/')) {
            return false;
        }

        if (str_starts_with($path, 'resources/views/') && ! str_ends_with($path, '.blade.php')) {
            return false;
        }

        if (in_array($path, self::ALLOWED_FILES, true)) {
            return true;
        }

        foreach (self::ALLOWED_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return true;
            }
        }

        return false;
    }
}
