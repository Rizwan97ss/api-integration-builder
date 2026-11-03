<?php

namespace DevForge\ApiKit\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Formats generated Vue pages with the project's own formatter (`vp fmt`).
 *
 * Formatting happens while the plan is built, so the previewed code is
 * exactly what gets written. When the formatter is unavailable the code is
 * returned unchanged.
 */
class FrontendFormatter
{
    /**
     * Format source code as if it lived at the given relative path.
     */
    public function format(string $code, string $path): string
    {
        $binary = base_path('node_modules/.bin/'.(PHP_OS_FAMILY === 'Windows' ? 'vp.cmd' : 'vp'));

        if (! File::exists($binary)) {
            return $code;
        }

        try {
            $result = Process::path(base_path())
                ->input($code)
                ->timeout(60)
                ->run([$binary, 'fmt', '--stdin-filepath='.$path]);
        } catch (Throwable) {
            return $code;
        }

        return $result->successful() && trim($result->output()) !== '' ? $result->output() : $code;
    }
}
