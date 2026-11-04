<?php

namespace DevForge\ApiDev\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

#[Signature('apidev:uninstall
    {--force : Do not ask for confirmation}
    {--backups : Also delete the backups the builder made}')]
#[Description('Remove the files the API Integration Builder added, ready for composer remove')]
class UninstallCommand extends Command
{
    /**
     * Execute the console command.
     *
     * Generated controllers, routes, models and pages are left alone: they
     * belong to the project and do not depend on this package.
     */
    public function handle(): int
    {
        intro('Remove the API Integration Builder');

        if (! $this->option('force') && ! confirm('Delete config/integration-builder.php and clear the cached config and routes?', default: true)) {
            outro('Nothing was changed.');

            return self::SUCCESS;
        }

        $backups = (string) config('integration-builder.backup_path');

        if (File::exists(config_path('integration-builder.php'))) {
            File::delete(config_path('integration-builder.php'));
            info('Deleted config/integration-builder.php.');
        }

        if (File::isDirectory($backups) && $this->shouldDeleteBackups($backups)) {
            File::deleteDirectory($backups);
            info('Deleted the builder backups.');
        }

        $this->callSilently('config:clear');
        $this->callSilently('route:clear');

        note('Generated controllers, routes, models and pages stay, and keep working without this package.');
        outro('Finish with: composer remove devforge/apidev');

        return self::SUCCESS;
    }

    /**
     * Decide whether to delete the backups, which may be the only copy of older code.
     */
    protected function shouldDeleteBackups(string $path): bool
    {
        if ($this->option('backups')) {
            return true;
        }

        return ! $this->option('force') && confirm("Also delete the backups in {$path}?", default: false);
    }
}
