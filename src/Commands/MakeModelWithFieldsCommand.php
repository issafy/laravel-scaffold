<?php

namespace Acme\Scaffold\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModelWithFieldsCommand extends Command
{
    protected $signature = 'make:model-with-fields {model} {--fields=} {--force}';
    protected $description = 'Create a model, migration, controller, and REST routes with field definitions';

    protected Filesystem $files;

    protected $fieldTypes = [
        'string', 'text', 'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
        'float', 'double', 'decimal', 'boolean', 'dateTime', 'date', 'time',
        'timestamp', 'enum', 'json', 'binary', 'uuid', 'ipAddress', 'macAddress', 'foreign'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    protected function getStub(string $name): string
    {
        $stubPath = dirname(__DIR__) . '/Stubs/' . $name . '.stub';

        if (!$this->files->exists($stubPath)) {
            throw new \RuntimeException("Stub not found at: {$stubPath}");
        }

        return $this->files->get($stubPath);
    }

    public function handle()
    {

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if (!in_array($connection, ['mysql'])) {
            $this->warn("⚠️  Warning: This package is optimized for MySQL. Some syntax (e.g. boolean defaults) may not work on {$connection}.");
        }

        $model = $this->argument('model');
        $modelName = Str::studly($model);

        $fields = $this->getParsedFields();

        $this->makeModel($modelName, $this->getModelPath($modelName), $this->getModelNamespace());
        $this->makeMigration($modelName, $fields);
        $this->makeController($modelName, $fields);
        $this->registerRoute($modelName);
        $this->suggestInverseRelationships($modelName, $fields);

        $this->info("✅ Full REST resource created for {$modelName}.");
        return Command::SUCCESS;
    }

    protected function getModelNamespace(): string
    {
        return config('scaffold.namespaces.model', 'App\\Models');
    }

    protected function getModelPath(string $modelName): string
    {
        $modelDir = config('scaffold.directories.model', app_path('Models'));
        return $modelDir . '/' . $modelName . '.php';
    }

    protected function makeModel(string $name, string $path, string $namespace)
    {
        $this->files->ensureDirectoryExists(dirname($path));

        $stub = $this->getStub('model');

        $fields = $this->getParsedFields();
        $fillable = collect($fields)
            ->map(fn($f) => $f['colName'])
            ->map(fn($name) => "'{$name}'")
            ->implode(', ');

        $foreignFields = array_filter($fields, fn($f) => $f['type'] === 'foreign');
        $relationships = [];
        foreach ($foreignFields as $field) {
            $colName = $field['colName'];
            $onTable = $field['modifiers']['on'] ?? Str::plural(Str::beforeLast($colName, '_id'));
            $relatedModel = Str::studly(Str::singular($onTable));
            $methodName = Str::camel($relatedModel);

            $relationships[] = "    public function {$methodName}()\n    {\n        return \$this->belongsTo({$relatedModel}::class);\n    }";
        }

        $relationshipsStr = $relationships ? implode("\n\n", $relationships) . "\n" : "";

        $stub = str_replace(
            ['{{namespace}}', '{{class}}', '{{fillable}}', '{{relationships}}'],
            [$namespace, $name, $fillable, $relationshipsStr],
            $stub
        );

        $this->files->put($path, $stub);
        $this->info("Model created: {$path}");
    }

    protected function makeMigration(string $name, array $fields)
    {
        $tableName = Str::snake(Str::plural($name));
        $migrationName = "create_{$tableName}_table";
        $migrationFile = date('Y_m_d_His') . "_{$migrationName}.php";

        $migrationPath = database_path("migrations/{$migrationFile}");
        $stub = $this->getStub('migration');


        $upSchema = $this->buildUpSchema($tableName, $fields);
        $downSchema = $this->buildDownSchema($tableName);

        $stub = str_replace(
            ['{{class}}', '{{table}}', '{{up}}', '{{down}}'],
            [$migrationName, $tableName, $upSchema, $downSchema],
            $stub
        );

        $this->files->put($migrationPath, $stub);
        $this->info("Migration created: {$migrationPath}");
    }

    protected function buildUpSchema(string $table, array $fields): string
    {
        $lines = ["Schema::create('{$table}', function (Blueprint \$table) {"];
        $lines[] = "    \$table->id();";

        foreach ($fields as $field) {
            $line = $this->buildFieldLine($field);
            if ($line) {
                $lines[] = "    " . $line;
            }
        }

        $lines[] = "";
        $lines[] = "    \$table->timestamps();";
        $lines[] = "});";

        return implode("\n", $lines);
    }

    protected function buildFieldLine(array $field): ?string
    {
        $colName = $field['colName'];
        $type = $field['type'];
        $modifiers = $field['modifiers'];

        if ($type === 'foreign') {
            $on = $modifiers['on'] ?? Str::plural(Str::beforeLast($colName, '_id'));
            $onDelete = $modifiers['onDelete'] ?? 'cascade';
            $onUpdate = $modifiers['onUpdate'] ?? 'cascade';

            $line = "\$table->foreignId('{$colName}')->constrained('{$on}')";

            if ($modifiers['nullable'] ?? false) {
                $line .= '->nullable()';
            }

            $line .= "->onDelete('{$onDelete}')->onUpdate('{$onUpdate}')";

            return $line . ';';
        }

        $line = "\$table->{$type}('{$colName}')";

        if (isset($modifiers['nullable'])) {
            $line .= '->nullable()';
        }

        if (isset($modifiers['default'])) {
            $default = $modifiers['default'];

            // 🔽 Handle booleans for MySQL: true → 1, false → 0
            if ($type === 'boolean') {
                $default = filter_var($default, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            $line .= "->default(" . (is_numeric($default) ? $default : "'{$default}'") . ")";
        }

        if (isset($modifiers['index'])) {
            $line .= '->index()';
        }

        if (isset($modifiers['unique'])) {
            $line .= '->unique()';
        }

        return $line . ';';
    }

    protected function buildDownSchema(string $table): string
    {
        return "Schema::dropIfExists('{$table}');";
    }

    protected function makeController(string $modelName, array $fields): void
    {
        $controllerName = "{$modelName}Controller";
        $controllerPath = app_path("Http/Controllers/{$controllerName}.php");
        $modelVar = Str::camel($modelName);
        $routeName = Str::plural(Str::snake($modelName));

        if ($this->files->exists($controllerPath)) {
            $this->warn("Controller {$controllerName} already exists. Skipping.");
            return;
        }

        $this->files->ensureDirectoryExists(dirname($controllerPath));

        $stub = $this->getStub('controller');


        $rules = [];
        foreach ($fields as $field) {
            $colName = $field['colName'];
            $type = $field['type'];
            $modifiers = $field['modifiers'];

            $ruleParts = [];

            if (!isset($modifiers['nullable'])) {
                $ruleParts[] = 'required';
            } else {
                $ruleParts[] = 'nullable';
            }

            match ($type) {
                'email' => $ruleParts[] = 'email',
                'url' => $ruleParts[] = 'url',
                'integer', 'bigInteger', 'smallInteger' => $ruleParts[] = 'integer',
                'float', 'double', 'decimal' => $ruleParts[] = 'numeric',
                'date' => $ruleParts[] = 'date',
                'boolean' => $ruleParts[] = 'boolean',
                default => null
            };

            if (isset($modifiers['min'])) {
                $ruleParts[] = "min:{$modifiers['min']}";
            }
            if (isset($modifiers['max'])) {
                $ruleParts[] = "max:{$modifiers['max']}";
            }
            if ($type === 'enum' && isset($modifiers['values'])) {
                $values = str_replace(['[', ']', '"', "'"], '', $modifiers['values']);
                $ruleParts[] = "in:{$values}";
            }

            $rules[] = "            '{$colName}' => '" . implode('|', $ruleParts) . "',";
        }

        $validationRules = $rules ? implode("\n", $rules) : "            // Add validation rules";

        $namespace = config('scaffold.namespaces.controller', 'App\\Http\\Controllers');
        $modelNamespace = config('scaffold.namespaces.model', 'App\\Models');

        $stub = str_replace(
            [
                '{{namespace}}',
                '{{controller}}',
                '{{modelNamespace}}',
                '{{model}}',
                '{{modelVar}}',
                '{{route}}',
                '{{validation_rules}}'
            ],
            [
                $namespace,
                $controllerName,
                $modelNamespace,
                $modelName,
                $modelVar,
                $routeName,
                $validationRules
            ],
            $stub
        );

        $this->files->put($controllerPath, $stub);
        $this->info("Controller created: {$controllerPath}");
    }

    protected function registerRoute(string $modelName): void
    {
        $controller = "{$modelName}Controller";
        $routeName = Str::plural(Str::snake($modelName));
        $routeFile = base_path('routes/web.php');

        $routeLine = PHP_EOL . "Route::resource('{$routeName}', {$controller}::class)->except(['create', 'edit']);";

        $content = $this->files->get($routeFile);

        if (Str::contains($content, $routeLine)) {
            $this->warn("Route for {$routeName} already exists in web.php.");
            return;
        }

        $this->files->put($routeFile, $content . $routeLine);
        $this->info("Route registered: Route::resource('{$routeName}', {$controller}::class)->except(['create', 'edit'])");
    }

    protected function getParsedFields(): array
    {
        $fields = $this->option('fields');

        if (!$fields) {
            if ($this->input->isInteractive()) {
                return $this->promptForFields();
            } else {
                $this->error("No fields provided and running in non-interactive mode.");
                return Command::FAILURE;
            }
        }

        $parsed = [];
        $rawFields = explode(',', $fields);

        foreach ($rawFields as $field) {
            $field = trim($field);
            if (empty($field)) continue;

            preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*([a-zA-Z_][a-zA-Z0-9_]*)/', $field, $matches);
            if (!$matches) {
                $this->warn("Invalid field format: {$field}");
                continue;
            }

            $colName = $matches[1];
            $type = strtolower($matches[2]);

            $modifiers = $this->parseModifiers($field);

            $parsed[] = compact('colName', 'type', 'modifiers');
        }

        return $parsed;
    }

    protected function parseModifiers(string $field): array
    {
        $modifiers = [];
        preg_match_all('/:([a-zA-Z]+)(?:\(([^)]+)\))?/', $field, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $value = $match[2] ?? true;
            $modifiers[$key] = $value;
        }

        return $modifiers;
    }

    protected function promptForFields(): array
    {
        $fields = [];

        $this->info("Define fields (e.g. name:string:required, age:integer:default(18))");
        $this->info("Available types: " . implode(', ', $this->fieldTypes));
        $this->info("Type 'done' when finished.");

        while (true) {
            $field = $this->ask("Field (name:type:modifiers) or 'done'");
            if (!$field || strtolower($field) === 'done') break;

            $parsed = $this->parseSingleField($field);
            if ($parsed) {
                $fields[] = $parsed;
            }
        }

        return $fields;
    }

    protected function parseSingleField(string $field): ?array
    {
        preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*([a-zA-Z_][a-zA-Z0-9_]*)/', $field, $matches);
        if (!$matches) {
            $this->error("Invalid field: {$field}");
            return null;
        }

        $colName = $matches[1];
        $type = strtolower($matches[2]);
        if (!in_array($type, $this->fieldTypes)) {
            $this->error("Unsupported type: {$type}");
            return null;
        }

        $modifiers = $this->parseModifiers($field);

        return compact('colName', 'type', 'modifiers');
    }

    protected function suggestInverseRelationships(string $modelName, array $fields): void
    {
        $inverseSuggestions = [];

        foreach ($fields as $field) {
            if ($field['type'] === 'foreign') {
                $colName = $field['colName'];
                $onTable = $field['modifiers']['on'] ?? Str::plural(Str::beforeLast($colName, '_id'));
                $relatedModel = Str::studly(Str::singular($onTable));
                $methodName = Str::camel($modelName);

                $inverseSuggestions[] = [
                    'model' => $relatedModel,
                    'method' => "    public function {$methodName}s()\n    {\n        return \$this->hasMany({$modelName}::class);\n    }",
                    'file' => "app/Models/{$relatedModel}.php"
                ];
            }
        }

        if (!empty($inverseSuggestions)) {
            $this->line("\n💡 <fg=yellow>SUGGESTION: Consider adding these inverse relationships:</>");
            foreach ($inverseSuggestions as $suggestion) {
                $this->line("\nAdd to <fg=cyan>{$suggestion['file']}></>:");
                $this->line("<fg=yellow>{$suggestion['method']}</>");
            }
            $this->line('');
        }
    }
}