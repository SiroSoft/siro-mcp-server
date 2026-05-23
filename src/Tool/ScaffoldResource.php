<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

/**
 * scaffold_resource — Full CRUD scaffolding: model + migration + controller + routes + resource.
 *
 * Orchestrates scaffold_model, scaffold_migration, scaffold_controller,
 * and generates route registration. Equivalent to `php siro make:crud` via MCP.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ScaffoldResource implements ToolInterface
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function getName(): string
    {
        return 'scaffold_resource';
    }

    public function getDescription(): string
    {
        return 'Full CRUD scaffolding: generates model, migration, controller, API resource, and route registration.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Resource name (e.g. Product, Order)',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['string', 'text', 'integer', 'bigint', 'float', 'boolean', 'date', 'datetime', 'json', 'decimal']],
                            'nullable' => ['type' => 'boolean', 'default' => false],
                            'unique' => ['type' => 'boolean', 'default' => false],
                        ],
                    ],
                    'description' => 'Column definitions for migration',
                ],
                'relations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['hasMany', 'belongsTo', 'belongsToMany', 'hasOne']],
                            'target' => ['type' => 'string'],
                        ],
                    ],
                    'description' => 'Model relations',
                ],
                'timestamps' => [
                    'type' => 'boolean',
                    'description' => 'Add timestamps to migration',
                    'default' => true,
                ],
                'soft_deletes' => [
                    'type' => 'boolean',
                    'description' => 'Enable soft deletes',
                    'default' => false,
                ],
                'resource' => [
                    'type' => 'boolean',
                    'description' => 'Generate API resource transformer',
                    'default' => false,
                ],
                'controller' => [
                    'type' => 'string',
                    'description' => 'Controller name (default: {name}Controller)',
                ],
                'route_prefix' => [
                    'type' => 'string',
                    'description' => 'Route prefix (default: lowercase plural of name)',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments): string
    {
        $name = $arguments['name'] ?? '';
        if (!is_string($name) || trim($name) === '') {
            return 'Error: name parameter is required.';
        }

        $name = $this->studly(trim($name));
        $columns = $arguments['columns'] ?? [];
        $relations = $arguments['relations'] ?? [];
        $timestamps = $arguments['timestamps'] ?? true;
        $softDeletes = $arguments['soft_deletes'] ?? false;
        $useResource = $arguments['resource'] ?? false;
        $table = $this->tableize($name);
        $controllerName = $arguments['controller'] ?? $name . 'Controller';

        $results = [];

        // 1. Generate migration
        $results[] = $this->generateMigration($name, $table, $columns, $timestamps, $softDeletes);

        // 2. Generate model
        $results[] = $this->generateModel($name, $table, $columns, $relations, $softDeletes);

        // 3. Generate controller
        $results[] = $this->generateController($controllerName, $name, $useResource);

        // 4. Generate resource if requested
        if ($useResource) {
            $results[] = $this->generateResource($name, $columns);
        }

        // 5. Update routes file
        $routePrefix = $arguments['route_prefix'] ?? $table;
        $results[] = $this->updateRoutes($routePrefix, $controllerName);

        return implode("\n", $results);
    }

    private function generateMigration(string $name, string $table, array $columns, bool $timestamps, bool $softDeletes): string
    {
        $filename = date('Y_m_d_His') . '_create_' . $table . '_table.php';
        $path = $this->basePath . '/database/migrations/' . $filename;

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $blueprintLines = [];
        $blueprintLines[] = "            \$table->id();";

        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            $colType = $col['type'] ?? 'string';
            $nullable = $col['nullable'] ?? false;
            $unique = $col['unique'] ?? false;

            $line = "            \$table->{$colType}('{$colName}')";
            if ($nullable) {
                $line .= "->nullable()";
            }
            if ($unique) {
                $line .= "->unique()";
            }
            $line .= ';';
            $blueprintLines[] = $line;
        }

        if ($timestamps) {
            $blueprintLines[] = "            \$table->timestamps();";
        }
        if ($softDeletes) {
            $blueprintLines[] = "            \$table->softDeletes();";
        }

        $blueprintStr = implode("\n", $blueprintLines);

        $content = <<<PHP
<?php

declare(strict_types=1);

use Siro\\Core\\Schema;
use Siro\\Core\\DB\\Blueprint;

/**
 * Create {$table} table
 *
 * Auto-generated by Siro MCP Server
 */
return new class
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
{$blueprintStr}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;

        file_put_contents($path, $content);
        return "OK: Generated database/migrations/{$filename}";
    }

    private function generateModel(string $name, string $table, array $columns, array $relations, bool $softDeletes): string
    {
        $path = $this->basePath . '/app/Models/' . $name . '.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $fillable = [];
        $casts = ['id' => 'int'];

        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            if ($colName === '' || $colName === 'id') {
                continue;
            }
            $fillable[] = $colName;
            $colType = $col['type'] ?? 'string';

            $castMap = [
                'integer' => 'int',
                'bigint' => 'int',
                'float' => 'float',
                'decimal' => 'float',
                'boolean' => 'bool',
                'datetime' => 'datetime',
                'date' => 'date',
                'json' => 'array',
            ];

            if (isset($castMap[$colType])) {
                $casts[$colName] = $castMap[$colType];
            }
        }

        $fillableStr = $fillable !== [] ? "\n        '" . implode("',\n        '", $fillable) . "',\n    " : '';
        $castsStr = '';
        foreach ($casts as $key => $type) {
            $castsStr .= "\n        '{$key}' => '{$type}',";
        }

        $uses = ['Siro\\Core\\Model'];
        $traits = '';

        if ($softDeletes) {
            $uses[] = 'Siro\\Core\\Model\\SoftDeletes';
            $traits = "\n    use SoftDeletes;\n";
        }

        $relationsStr = '';
        foreach ($relations as $rel) {
            $relType = $rel['type'] ?? 'hasMany';
            $relTarget = $rel['target'] ?? 'RelatedModel';
            $relMethod = lcfirst(basename(str_replace('\\', '/', $relTarget)));

            $relationsStr .= "\n\n    public function {$relMethod}(): \\Siro\\Core\\ModelRelations\\{$this->studly($relType)}\n    {\n        return \$this->{$relType}({$relTarget}::class);\n    }";
        }

        $usesStr = "use " . implode(";\nuse ", $uses) . ";";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

{$usesStr};

final class {$name} extends Model
{{$traits}
    protected string \$table = '{$table}';

    /** @var array<int, string> */
    protected array \$fillable = [{$fillableStr}];

    /** @var array<string, string> */
    protected array \$casts = [{$castsStr}
    ];

    /** @var array<int, string> */
    protected array \$hidden = [];
}
{$relationsStr}
PHP;

        file_put_contents($path, $content);
        return "OK: Generated app/Models/{$name}.php";
    }

    private function generateController(string $controllerName, string $modelName, bool $useResource): string
    {
        $path = $this->basePath . '/app/Controllers/' . $controllerName . '.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $modelVar = lcfirst($modelName);
        $modelLower = strtolower($modelName);

        $imports = [
            'Siro\\Core\\Controller',
            'Siro\\Core\\Http\\Request',
            'App\\Models\\' . $modelName,
        ];

        if ($useResource) {
            $imports[] = 'App\\Resources\\' . $modelName . 'Resource';
        }

        $importStr = "use " . implode(";\nuse ", $imports) . ";";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

{$importStr};

final class {$controllerName} extends Controller
{
    public function index(): string
    {
        \${$modelVar}s = {$modelName}::all();
{$this->resourceWrap('$' . $modelVar . 's', $useResource, $modelName)}
    }

    public function show(string \$id): string
    {
        \${$modelVar} = {$modelName}::findOrFail(\$id);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelName)}
    }

    public function store(Request \$request): string
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = {$modelName}::create(\$data);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelName)}
    }

    public function update(string \$id, Request \$request): string
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = {$modelName}::findOrFail(\$id);
        \${$modelVar}->update(\$data);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelName)}
    }

    public function delete(string \$id): string
    {
        \${$modelVar} = {$modelName}::findOrFail(\$id);
        \${$modelVar}->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
PHP;

        file_put_contents($path, $content);
        return "OK: Generated app/Controllers/{$controllerName}.php";
    }

    private function generateResource(string $name, array $columns): string
    {
        $path = $this->basePath . '/app/Resources/' . $name . 'Resource.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $fields = "'id' => \$this->id,";
        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            if ($colName === '' || $colName === 'id') {
                continue;
            }
            $fields .= "\n            '{$colName}' => \$this->{$colName},";
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\\Core\\Resource;

final class {$name}Resource extends Resource
{
    public function toArray(object \$model): array
    {
        return [
            {$fields}
        ];
    }
}
PHP;

        file_put_contents($path, $content);
        return "OK: Generated app/Resources/{$name}Resource.php";
    }

    private function updateRoutes(string $prefix, string $controllerName): string
    {
        $routeFile = $this->basePath . '/routes/api.php';
        if (!file_exists($routeFile)) {
            return "Warning: routes/api.php not found. Add route manually:\n\$router->resource('{$prefix}', \\App\\Controllers\\{$controllerName}::class);";
        }

        $content = file_get_contents($routeFile);
        if ($content === false) {
            return "Warning: Could not read routes/api.php. Add route manually.";
        }

        $useImport = "use App\\Controllers\\{$controllerName};";
        $routeLine = "\$router->resource('{$prefix}', {$controllerName}::class);";

        if (str_contains($content, $routeLine)) {
            return "OK: Route already registered: {$routeLine}";
        }

        if (!str_contains($content, $useImport)) {
            if (preg_match('/^(use\s[^;]+;\s*)+/m', $content, $matches)) {
                $lastUsePos = strrpos($content, 'use ');
                $semicolonPos = strpos($content, ';', $lastUsePos);
                $content = substr_replace($content, "\n{$useImport};", $semicolonPos + 1, 0);
            } else {
                $content = "<?php\n\n{$useImport};\n\n" . ltrim($content, "<?php\n ");
            }
        }

        $content = rtrim($content) . "\n{$routeLine}\n";

        file_put_contents($routeFile, $content);
        return "OK: Added route to routes/api.php: {$routeLine}";
    }

    private function resourceWrap(string $var, bool $useResource, string $modelName): string
    {
        if ($useResource) {
            return "        return {$modelName}Resource::collection({$var})->toJson();";
        }
        return "        return response()->json({$var});";
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function tableize(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value)) . 's';
    }
}
