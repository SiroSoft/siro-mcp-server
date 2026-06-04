<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * scaffold_controller — Generate a controller class.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ScaffoldController implements ToolInterface
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
        return 'scaffold_controller';
    }

    public function getDescription(): string
    {
        return 'Generate a Siro controller class with optional CRUD methods, validation, and resource transformation.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Controller name (e.g. ProductController or Product)',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Associated model name (for CRUD methods)',
                ],
                'resource' => [
                    'type' => 'boolean',
                    'description' => 'Wrap responses in API resource transformer',
                    'default' => false,
                ],
                'crud' => [
                    'type' => 'boolean',
                    'description' => 'Generate full CRUD methods (index, show, store, update, delete)',
                    'default' => true,
                ],
                'service' => [
                    'type' => 'string',
                    'description' => 'Associated service class for business logic',
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
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $modelRaw = $arguments['model'] ?? '';
        $model = is_string($modelRaw) && $modelRaw !== '' ? $this->studly($modelRaw) : '';
        $useResource = is_bool($arguments['resource'] ?? null) ? $arguments['resource'] : false;
        $useCrud = is_bool($arguments['crud'] ?? null) ? $arguments['crud'] : true;
        $service = is_string($arguments['service'] ?? null) ? $arguments['service'] : '';

        $path = $this->basePath . '/app/Controllers/' . $name . '.php';

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $content = $this->generateController($name, $model, $useResource, $useCrud, $service);

        try {
            $this->pathValidator->resolve('app/Controllers/' . $name . '.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        $bytesWritten = @file_put_contents($path, $content);
        if ($bytesWritten === false) {
            return "Error: Failed to write controller file.";
        }

        return "OK: Generated app/Controllers/{$name}.php ({$bytesWritten} bytes)";
    }

    private function generateController(string $name, string $model, bool $useResource, bool $useCrud, string $service): string
    {
        $imports = [
            'Siro\\Core\\Controller',
            'Siro\\Core\\Http\\Request',
        ];
        $modelVar = lcfirst($model);
        $modelLower = $model !== '' ? strtolower($model) : 'item';
        $methods = '';

        if ($model !== '') {
            $imports[] = 'App\\Models\\' . $model;
        }
        if ($useResource) {
            $imports[] = 'App\\Resources\\' . $model . 'Resource';
        }
        if ($service !== '') {
            $imports[] = 'App\\Services\\' . $this->studly($service);
        }

        if ($useCrud && $model !== '') {
            $methods = $this->crudMethods($modelVar, $modelLower, $useResource, $service !== '' ? lcfirst($this->studly($service)) : '');
        }

        $importStr = "use " . implode(";\nuse ", $imports) . ";";

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

{$importStr};

final class {$name} extends Controller
{
{$methods}
}
PHP;
    }

    private function crudMethods(string $modelVar, string $modelLower, bool $useResource, string $serviceVar): string
    {
        $serviceCall = $serviceVar !== '' ? "\$this->{$serviceVar}->" : '';

        return <<<METHODS
    /**
     * List all {$modelLower}s.
     *
     * @return string
     */
    public function index(): string
    {
        \${$modelVar}s = {$modelVar}::all();
{$this->resourceWrap('$' . $modelVar . 's', $useResource, $modelVar)}
    }

    /**
     * Show a single {$modelLower}.
     *
     * @param string \$id
     * @return string
     */
    public function show(string \$id): string
    {
        \${$modelVar} = {$serviceCall}findOrFail(\$id);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelVar)}
    }

    /**
     * Store a new {$modelLower}.
     *
     * @param Request \$request
     * @return string
     */
    public function store(Request \$request): string
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = {$serviceCall}create(\$data);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelVar)}
    }

    /**
     * Update an existing {$modelLower}.
     *
     * @param string \$id
     * @param Request \$request
     * @return string
     */
    public function update(string \$id, Request \$request): string
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = {$serviceCall}findOrFail(\$id);
        \${$modelVar}->update(\$data);
{$this->resourceWrap('$' . $modelVar, $useResource, $modelVar)}
    }

    /**
     * Delete a {$modelLower}.
     *
     * @param string \$id
     * @return string
     */
    public function delete(string \$id): string
    {
        \${$modelVar} = {$serviceCall}findOrFail(\$id);
        \${$modelVar}->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
METHODS;
    }

    private function resourceWrap(string $var, bool $useResource, string $modelVar): string
    {
        if ($useResource) {
            $resourceClass = $this->studly($modelVar) . 'Resource';
            return "        return {$resourceClass}::collection(\${$modelVar})->toJson();";
        }
        return "        return response()->json(\${$modelVar});";
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
