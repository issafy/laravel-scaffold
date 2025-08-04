<?php

namespace Acme\Scaffold\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Path;

class SyncModelsFromMigrationsCommand extends Command
{
    protected $signature = 'scaffold:sync 
        {--dry-run : Show what would be created without making changes}
        {--soft-deletes : Detect and support soft deletes in models}
        {--watch : Watch for new migrations and scaffold automatically}
        {--poll=1 : Polling interval in seconds (default: 1)}';

    protected $description = 'Scan migrations and generate missing models, controllers, and routes';

    protected Filesystem $files;
    protected bool $hasChanges = false;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        if ($this->option('watch')) {
            $this->watch();
            return;
        }

        return $this->sync();
    }

    protected function sync(): int
    {
        $migrationsPath = database_path('migrations');
        $migrations = $this->getCreateTableMigrations($migrationsPath);

        if (empty($migrations)) {
            $this->comment("No table-creating migrations found.");
            return Command::SUCCESS;
        }

        $this->info("Found " . count($migrations) . " table-creating migrations.");

        $this->hasChanges = false;

        foreach ($migrations as $migration) {
            $tableName = $this->extractTableName($migration['up']);
            if (! $tableName) continue;

            $modelName = Str::studly(Str::singular($tableName));

            if (! $this->shouldScaffold($modelName)) {
                continue;
            }

            $fields = $this->parseMigrationFields($migration['file'], $tableName, $this->option('soft-deletes'));
            $fieldString = $this->formatFieldsForCommand($fields);

            if ($this->option('dry-run')) {
                $this->line("🔹 Would scaffold: <info>{$modelName}</info>");
                $this->line("   Fields: {$fieldString}");
                $this->hasChanges = true;
            } else {
                $this->call('make:model-with-fields', [
                    'model' => $modelName,
                    '--fields' => $fieldString,
                    '--force' => false,
                ]);
                $this->info("✅ Scaffolded: {$modelName}");
                $this->hasChanges = true;
            }
        }

        if ($this->option('dry-run') && ! $this->hasChanges) {
            $this->comment("No new models to scaffold.");
        } elseif ($this->option('dry-run')) {
            $this->info("Dry run complete. No changes made.");
        }

        return Command::SUCCESS;
    }

    protected function watch()
    {
        $this->info("Watching for new migrations... (Ctrl+C to stop)");

        $migrationsPath = database_path('migrations');
        $knownMigrations = $this->getMigrationBasenames($migrationsPath);

        while (true) {
            $current = $this->getMigrationBasenames($migrationsPath);
            $new = array_diff($current, $knownMigrations);

            if (!empty($new)) {
                $this->info("New migration(s) detected: " . implode(', ', $new));
                $this->sync(); // Run sync (without --watch)
                $knownMigrations = $current; // Update list
            }

            usleep($this->option('poll') * 1000000); // seconds → microseconds
        }
    }

    protected function getMigrationBasenames(string $path): array
    {
        if (! $this->files->exists($path)) return [];

        $files = Finder::create()->in($path)->name('/\.php$/');
        return array_map('basename', iterator_to_array($files));
    }

    protected function getCreateTableMigrations(string $path): array
    {
        if (! $this->files->exists($path)) {
            $this->error("Migrations directory not found: {$path}");
            return [];
        }

        $files = Finder::create()
            ->in($path)
            ->name('/\.php$/')
            ->sortByName();

        $migrations = [];

        foreach ($files as $file) {
            $content = $this->files->get($file->getRealPath());
            if (Str::contains($content, 'Schema::create') && Str::contains($content, 'up()')) {
                $migrations[] = [
                    'file' => $file->getRealPath(),
                    'up' => $content,
                ];
            }
        }

        return $migrations;
    }

    protected function extractTableName(string $content): ?string
    {
        preg_match("/Schema::create\('([^']+)'/", $content, $matches);
        return $matches[1] ?? null;
    }

    protected function shouldScaffold(string $modelName): bool
    {
        $modelPath = app_path("Models/{$modelName}.php");
        return ! $this->files->exists($modelPath);
    }

    protected function parseMigrationFields(string $migrationFile, string $table, bool $detectSoftDeletes = false): array
    {
        $content = $this->files->get($migrationFile);

        preg_match("/Schema::create\('[^']+',\s*function\s*\(.*?\}\s*(?=\);)/s", $content, $schemaMatch);
        if (!isset($schemaMatch[0])) {
            return [];
        }

        $schema = $schemaMatch[0];
        $fields = [];
        $fieldTypes = ['string', 'text', 'integer', 'boolean', 'json', 'uuid', 'dateTime', 'timestamp', 'decimal', 'bigInteger', 'float', 'double'];

        foreach ($fieldTypes as $type) {
            preg_match_all("/->{$type}\s*\(\s*'([^']+)'\s*\)/", $schema, $matches);
            foreach ($matches[1] as $colName) {
                $modifiers = $this->extractModifiers($schema, $colName);
                $fields[] = compact('colName', 'type', 'modifiers');
            }
        }

        // Handle foreignId
        preg_match_all("/->foreignId\('([^']+)'\)/", $schema, $foreignMatches);
        foreach ($foreignMatches[1] as $colName) {
            $modifiers = $this->extractModifiers($schema, $colName);
            $fields[] = [
                'colName' => $colName,
                'type' => 'foreign',
                'modifiers' => $modifiers
            ];
        }

        // Detect softDeletes
        if ($detectSoftDeletes && Str::contains($schema, '->softDeletes')) {
            $fields[] = [
                'colName' => 'deleted_at',
                'type' => 'dateTime',
                'modifiers' => ['nullable' => true]
            ];
        }

        return $fields;
    }

    protected function extractModifiers(string $schema, string $colName): array
    {
        $modifiers = [];
        $lines = explode("\n", $schema);

        foreach ($lines as $line) {
            if (Str::contains($line, "'{$colName}'")) {
                if (Str::contains($line, '->nullable')) {
                    $modifiers['nullable'] = true;
                }
                if (Str::contains($line, '->default')) {
                    preg_match("/->default\(([^)]+)\)/", $line, $m);
                    $modifiers['default'] = $m[1] ?? 'null';
                }
                if (Str::contains($line, '->index')) {
                    $modifiers['index'] = true;
                }
                if (Str::contains($line, '->unique')) {
                    $modifiers['unique'] = true;
                }
                if (Str::contains($line, '->constrained')) {
                    $modifiers['constrained'] = true;
                }
                if (Str::contains($line, '->onDelete')) {
                    preg_match("/->onDelete\('([^']+)'\)/", $line, $m);
                    $modifiers['onDelete'] = $m[1] ?? 'cascade';
                }
                if (Str::contains($line, '->onUpdate')) {
                    preg_match("/->onUpdate\('([^']+)'\)/", $line, $m);
                    $modifiers['onUpdate'] = $m[1] ?? 'cascade';
                }
            }
        }

        return $modifiers;
    }

    protected function formatFieldsForCommand(array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $colName = $field['colName'];
            $type = $field['type'];
            $modifiers = [];

            if (isset($field['modifiers']['nullable'])) {
                $modifiers[] = 'nullable';
            }
            if (isset($field['modifiers']['default'])) {
                $default = trim($field['modifiers']['default'], "'");
                $modifiers[] = "default({$default})";
            }
            if (isset($field['modifiers']['index'])) {
                $modifiers[] = 'index';
            }
            if (isset($field['modifiers']['unique'])) {
                $modifiers[] = 'unique';
            }
            if (isset($field['modifiers']['onDelete'])) {
                $modifiers[] = "onDelete({$field['modifiers']['onDelete']})";
            }
            if (isset($field['modifiers']['onUpdate'])) {
                $modifiers[] = "onUpdate({$field['modifiers']['onUpdate']})";
            }

            $parts[] = "{$colName}:{$type}" . (empty($modifiers) ? '' : ':' . implode(',', $modifiers));
        }

        return implode(',', $parts);
    }
}