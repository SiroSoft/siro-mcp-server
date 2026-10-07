<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * scaffold_resource — Full CRUD scaffolding: model + migration + repository + service + controller + resource + routes + feature test.
 *
 * Orchestrates scaffold_model, scaffold_migration, scaffold_controller,
 * and generates route registration. Equivalent to `php siro make:crud` via MCP.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ScaffoldResource implements ToolInterface
{
    private PathValidator $pathValidator;
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
        $this->pathValidator = new PathValidator($this->basePath);
    }

    public function getName(): string
    {
        return 'scaffold_resource';
    }

    public function getDescription(): string
    {
        return 'Full CRUD scaffolding: generates model, migration, repository, service, controller, API resource, routes, and a feature test.';
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
                    'default' => true,
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
        if (!$this->isClassName($name)) {
            return 'Error: name must be a valid resource class name.';
        }
        $rawColumns = is_array($arguments['columns'] ?? null) ? $arguments['columns'] : [];
        /** @var array<int, array{name: string, type: string, nullable: bool, unique: bool}> $columns */
        $columns = [];
        $allowedColumnTypes = ['string', 'text', 'integer', 'bigint', 'float', 'boolean', 'date', 'datetime', 'json', 'decimal'];
        foreach ($rawColumns as $column) {
            if (!is_array($column) || !is_string($column['name'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column['name'])) {
                return 'Error: each column name must be a valid database identifier.';
            }
            $columnType = is_string($column['type'] ?? null) ? $column['type'] : 'string';
            if (!in_array($columnType, $allowedColumnTypes, true)) {
                return 'Error: each column type must be supported by the schema builder.';
            }
            $columns[] = [
                'name' => $column['name'],
                'type' => $columnType,
                'nullable' => is_bool($column['nullable'] ?? null) ? $column['nullable'] : false,
                'unique' => is_bool($column['unique'] ?? null) ? $column['unique'] : false,
            ];
        }

        $rawRelations = is_array($arguments['relations'] ?? null) ? $arguments['relations'] : [];
        /** @var array<int, array{type: string, target: string}> $relations */
        $relations = [];
        $allowedRelationTypes = ['hasMany', 'belongsTo', 'belongsToMany', 'hasOne'];
        foreach ($rawRelations as $relation) {
            if (!is_array($relation) || !is_string($relation['target'] ?? null) || !$this->isClassName($this->studly($relation['target']))) {
                return 'Error: each relation target must be a valid class name.';
            }
            $relationType = is_string($relation['type'] ?? null) ? $relation['type'] : 'hasMany';
            if (!in_array($relationType, $allowedRelationTypes, true)) {
                return 'Error: each relation type must be supported.';
            }
            $relations[] = ['type' => $relationType, 'target' => $this->studly($relation['target'])];
        }
        $timestamps = is_bool($arguments['timestamps'] ?? null) ? $arguments['timestamps'] : true;
        $softDeletes = is_bool($arguments['soft_deletes'] ?? null) ? $arguments['soft_deletes'] : false;
        $useResource = is_bool($arguments['resource'] ?? null) ? $arguments['resource'] : true;
        $table = $this->tableize($name);
        $serviceName = $name . 'Service';
        $repositoryName = $name . 'Repository';
        $controllerRaw = $arguments['controller'] ?? null;
        $controllerName = is_string($controllerRaw) && trim($controllerRaw) !== ''
            ? $this->studly(trim($controllerRaw))
            : $name . 'Controller';
        if (!$this->isClassName($controllerName)) {
            return 'Error: controller must be a valid class name.';
        }
        $routePrefixRaw = $arguments['route_prefix'] ?? null;
        $routePrefix = is_string($routePrefixRaw) && trim($routePrefixRaw) !== '' ? trim($routePrefixRaw) : $table;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\/-]*$/', $routePrefix) || str_contains($routePrefix, '..')) {
            return 'Error: route_prefix must be a safe route path.';
        }
        $results = [];

        // 1. Generate migration
        $results[] = $this->generateMigration($name, $table, $columns, $timestamps, $softDeletes);

        // 2. Generate model
        $results[] = $this->generateModel($name, $table, $columns, $relations, $softDeletes);

        // 3. Generate repository and service layers
        $results[] = $this->generateRepository($repositoryName, $name, $table);
        $results[] = $this->generateService($serviceName, $name, $repositoryName);

        // 4. Generate controller
        $results[] = $this->generateController($controllerName, $name, $useResource, $serviceName);

        // 5. Generate resource if requested
        if ($useResource) {
            $results[] = $this->generateResource($name, $columns);
        }

        // 6. Generate feature test
        $results[] = $this->generateTest($name, $table);

        // 7. Update routes file
        $results[] = $this->updateRoutes($routePrefix, $controllerName);

        return implode("\n", $results);
    }

    private function generateRepository(string $repositoryName, string $modelName, string $table): string
    {
        $path = $this->basePath . '/app/Repositories/' . $repositoryName . '.php';
        $relativePath = 'app/Repositories/' . $repositoryName . '.php';
        if (str_contains(str_replace('\\', '/', $relativePath), '..')) {
            return 'Error: Path traversal detected: ".." is not allowed';
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        try {
            $this->pathValidator->resolve($relativePath);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\{$modelName};

final class {$repositoryName}
{
    public function findAll(int \$page = 1, int \$perPage = 20): array
    {
        return {$modelName}::query()->orderBy('id', 'DESC')->paginate(\$perPage, \$page);
    }

    public function findById(int \$id): mixed
    {
        return {$modelName}::find(\$id);
    }

    public function store(array \$data): mixed
    {
        return {$modelName}::create(\$data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function update(int \$id, array \$data): mixed
    {
        \$item = \$this->findById(\$id);
        if (\$item === null) return null;
        \$item->update(\$data);
        return \$item;
    }

    public function destroy(int \$id): bool
    {
        \$item = \$this->findById(\$id);
        return \$item !== null && (bool) \$item->delete();
    }
}
PHP;

        @file_put_contents($path, $content);
        return "OK: Generated app/Repositories/{$repositoryName}.php for {$table}";
    }

    private function generateService(string $serviceName, string $modelName, string $repositoryName): string
    {
        $path = $this->basePath . '/app/Services/' . $serviceName . '.php';
        $relativePath = 'app/Services/' . $serviceName . '.php';
        if (str_contains(str_replace('\\', '/', $relativePath), '..')) {
            return 'Error: Path traversal detected: ".." is not allowed';
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        try {
            $this->pathValidator->resolve($relativePath);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\{$repositoryName};

final class {$serviceName}
{
    public function __construct(private readonly {$repositoryName} \$repository)
    {
    }

    public function getAll(int \$page = 1, int \$perPage = 20): array
    {
        return \$this->repository->findAll(\$page, \$perPage);
    }

    public function getById(int \$id): mixed
    {
        return \$this->repository->findById(\$id);
    }

    public function create(array \$data): mixed
    {
        return \$this->repository->store(\$data);
    }

    public function update(int \$id, array \$data): mixed
    {
        return \$this->repository->update(\$id, \$data);
    }

    public function delete(int \$id): bool
    {
        return \$this->repository->destroy(\$id);
    }
}
PHP;

        @file_put_contents($path, $content);
        return "OK: Generated app/Services/{$serviceName}.php for {$modelName}";
    }

    /**
     * @param array<int, array{name?: string, type?: string, nullable?: bool, unique?: bool}> $columns
     */
    private function generateMigration(string $name, string $table, array $columns, bool $timestamps, bool $softDeletes): string
    {
        $filename = date('Y_m_d_His') . '_create_' . $table . '_table.php';
        $path = $this->basePath . '/database/migrations/' . $filename;

        try {
            $this->pathValidator->resolve('database/migrations/' . $filename);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

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

    /**
     * @param array<int, array{name?: string, type?: string, nullable?: bool, unique?: bool}> $columns
     * @param array<int, array{type?: string, target?: string}> $relations
     */
    private function generateModel(string $name, string $table, array $columns, array $relations, bool $softDeletes): string
    {
        $path = $this->basePath . '/app/Models/' . $name . '.php';

        try {
            $this->pathValidator->resolve('app/Models/' . $name . '.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

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
            $uses[] = 'Siro\\Core\\DB\\SoftDeletes';
            $traits = "\n    use SoftDeletes;\n";
        }

        $relationsStr = '';
        foreach ($relations as $rel) {
            $relType = $rel['type'] ?? 'hasMany';
            $relTarget = $rel['target'] ?? 'RelatedModel';
            $relMethod = lcfirst(basename(str_replace('\\', '/', $relTarget)));

            $relationsStr .= "\n\n    public function {$relMethod}(): \\Siro\\Core\\DB\\Relations\\{$this->studly($relType)}\n    {\n        return \$this->{$relType}({$relTarget}::class);\n    }";
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
    protected array \$hidden = [];{$relationsStr}
}
PHP;

        file_put_contents($path, $content);
        return "OK: Generated app/Models/{$name}.php";
    }

    /**
     * @param bool $useResource
     */
    private function generateController(string $controllerName, string $modelName, bool $useResource, string $serviceName): string
    {
        $path = $this->basePath . '/app/Controllers/' . $controllerName . '.php';

        try {
            $this->pathValidator->resolve('app/Controllers/' . $controllerName . '.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $modelVar = lcfirst($modelName);

        $imports = [
            'Siro\\Core\\Controller',
            'Siro\\Core\\Request',
            'Siro\\Core\\Response',
            'App\\Services\\' . $serviceName,
        ];

        if ($useResource) {
            $imports[] = 'App\\Resources\\' . $modelName . 'Resource';
        }

        $importStr = "use " . implode(";\nuse ", $imports) . ";";
        $indexData = $useResource
            ? "{$modelName}Resource::collection(\$result['data'])"
            : "\$result['data']";
        $showData = $useResource ? "{$modelName}Resource::make(\${$modelVar})" : "\${$modelVar}";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

{$importStr};

final class {$controllerName} extends Controller
{
    public function __construct(private readonly {$serviceName} \$service)
    {
    }

    public function index(Request \$request): Response
    {
        \$result = \$this->service->getAll(\$request->queryInt('page', 1), \$request->queryInt('per_page', 20));
        return Response::paginated({$indexData}, \$result['meta'], '{$modelName} list');
    }

    public function show(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \${$modelVar} = \$this->service->getById(\$id);
        if (\${$modelVar} === null) return Response::error('{$modelName} not found', 404);
        return Response::success({$showData}, '{$modelName} detail');
    }

    public function store(Request \$request): Response
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = \$this->service->create(\$data);
        return Response::created({$showData}, '{$modelName} created');
    }

    public function update(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = \$this->service->update(\$id, \$data);
        if (\${$modelVar} === null) return Response::error('{$modelName} not found', 404);
        return Response::success({$showData}, '{$modelName} updated');
    }

    public function delete(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        if (!\$this->service->delete(\$id)) {
            return Response::error('{$modelName} not found', 404);
        }

        return Response::success(null, '{$modelName} deleted');
    }
}
PHP;

        file_put_contents($path, $content);
        return "OK: Generated app/Controllers/{$controllerName}.php";
    }

    /**
     * @param array<int, array{name?: string}> $columns
     */
    private function generateResource(string $name, array $columns): string
    {
        $path = $this->basePath . '/app/Resources/' . $name . 'Resource.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        try {
            $this->pathValidator->resolve('app/Resources/' . $name . 'Resource.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        $fields = "'id' => \$this->data['id'] ?? null,";
        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            if ($colName === '' || $colName === 'id') {
                continue;
            }
            $fields .= "\n            '{$colName}' => \$this->data['{$colName}'] ?? null,";
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\\Core\\Resource;

final class {$name}Resource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
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

    private function generateTest(string $name, string $routePrefix): string
    {
        $className = $name . 'Test';
        $path = $this->basePath . '/tests/Feature/' . $className . '.php';
        $relativePath = 'tests/Feature/' . $className . '.php';
        if (str_contains(str_replace('\\', '/', $relativePath), '..')) {
            return 'Error: Path traversal detected: ".." is not allowed';
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        try {
            $this->pathValidator->resolve($relativePath);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        $endpoint = '/api/' . $routePrefix;
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;

final class {$className} extends TestCase
{
    public function testIndexReturns200(): void
    {
        \$this->get('{$endpoint}')->assertOk();
    }

    public function testShowReturns404ForUnknownId(): void
    {
        \$this->get('{$endpoint}/999999')->assertNotFound();
    }

    public function testStoreRejectsMissingRequiredData(): void
    {
        \$this->post('{$endpoint}', [])->assertValidationError();
    }

    public function testUpdateReturns404ForUnknownId(): void
    {
        \$this->put('{$endpoint}/999999', ['name' => 'Updated'])->assertNotFound();
    }

    public function testDeleteReturns404ForUnknownId(): void
    {
        \$this->delete('{$endpoint}/999999')->assertNotFound();
    }
}
PHP;

        @file_put_contents($path, $content);
        return "OK: Generated tests/Feature/{$className}.php";
    }

    private function updateRoutes(string $prefix, string $controllerName): string
    {
        $routeFile = $this->basePath . '/routes/api.php';

        try {
            $this->pathValidator->resolve('routes/api.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

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
                if ($lastUsePos !== false) {
                    $semicolonPos = strpos($content, ';', $lastUsePos);
                    if ($semicolonPos !== false) {
                        $content = substr_replace($content, "\n{$useImport};", $semicolonPos + 1, 0);
                    }
                }
            } else {
                $content = "<?php\n\n{$useImport};\n\n" . ltrim($content, "<?php\n ");
            }
        }

        $content = rtrim($content) . "\n{$routeLine}\n";

        file_put_contents($routeFile, $content);
        return "OK: Added route to routes/api.php: {$routeLine}";
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function isClassName(string $value): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $value) === 1;
    }

    private function tableize(string $value): string
    {
        $replaced = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);
        return strtolower($replaced ?? $value) . 's';
    }
}
