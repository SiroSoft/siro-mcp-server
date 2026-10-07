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
        if (!$this->isClassName($name)) {
            return 'Error: name must be a valid controller class name.';
        }

        $modelRaw = $arguments['model'] ?? '';
        $model = is_string($modelRaw) && $modelRaw !== '' ? $this->studly($modelRaw) : '';
        if ($model !== '' && !$this->isClassName($model)) {
            return 'Error: model must be a valid class name.';
        }
        $useResource = is_bool($arguments['resource'] ?? null) ? $arguments['resource'] : false;
        $useCrud = is_bool($arguments['crud'] ?? null) ? $arguments['crud'] : true;
        $service = is_string($arguments['service'] ?? null) ? $arguments['service'] : '';
        if ($service !== '') {
            $service = $this->studly($service);
            if (!$this->isClassName($service)) {
                return 'Error: service must be a valid class name.';
            }
        }

        $path = $this->basePath . '/app/Controllers/' . $name . '.php';

        try {
            $this->pathValidator->resolve('app/Controllers/' . $name . '.php');
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $content = $this->generateController($name, $model, $useResource, $useCrud, $service);

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
            'Siro\\Core\\Request',
            'Siro\\Core\\Response',
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
            $methods = $this->crudMethods($model, $modelVar, $modelLower, $useResource, $service !== '' ? lcfirst($service) : '');
        }

        $importStr = "use " . implode(";\nuse ", $imports);

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

    private function crudMethods(string $model, string $modelVar, string $modelLower, bool $useResource, string $serviceVar): string
    {
        $indexQuery = $serviceVar !== ''
            ? "\$result = \$this->{$serviceVar}->getAll(\$request->queryInt('page', 1), \$request->queryInt('per_page', 20));"
            : "\$result = {$model}::query()->orderBy('id', 'DESC')->paginate(\$request->queryInt('per_page', 20), \$request->queryInt('page', 1));";
        $findById = $serviceVar !== '' ? "\$this->{$serviceVar}->getById(\$id)" : "{$model}::find(\$id)";
        $create = $serviceVar !== '' ? "\$this->{$serviceVar}->create(\$data)" : "{$model}::create(\$data)";
        $update = $serviceVar !== '' ? "\$this->{$serviceVar}->update(\$id, \$data)" : "{$model}::find(\$id)";
        $indexData = $useResource ? "{$model}Resource::collection(\$result['data'])" : "\$result['data']";
        $itemData = $useResource ? "{$model}Resource::make(\${$modelVar})" : "\${$modelVar}";
        $updateBlock = $serviceVar !== ''
            ? "\${$modelVar} = {$update};\n        if (\${$modelVar} === null) return Response::error('{$model} not found', 404);"
            : "\${$modelVar} = {$update};\n        if (\${$modelVar} === null) return Response::error('{$model} not found', 404);\n        \${$modelVar}->update(\$data);";
        $deleteBlock = $serviceVar !== ''
            ? "if (!\$this->{$serviceVar}->delete(\$id)) return Response::error('{$model} not found', 404);"
            : "\${$modelVar} = {$findById};\n        if (\${$modelVar} === null) return Response::error('{$model} not found', 404);\n        \${$modelVar}->delete();";

        return <<<METHODS
    public function index(Request \$request): Response
    {
        {$indexQuery}
        return Response::paginated({$indexData}, \$result['meta'], '{$model} list');
    }

    public function show(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \${$modelVar} = {$findById};
        if (\${$modelVar} === null) return Response::error('{$model} not found', 404);
        return Response::success({$itemData}, '{$model} detail');
    }

    public function store(Request \$request): Response
    {
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        \${$modelVar} = {$create};
        return Response::created({$itemData}, '{$model} created');
    }

    public function update(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$data = \$request->validate([
            // TODO: Add validation rules
        ]);

        {$updateBlock}
        return Response::success({$itemData}, '{$model} updated');
    }

    public function delete(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        {$deleteBlock}

        return Response::success(null, '{$model} deleted');
    }
METHODS;
    }

    private function isClassName(string $value): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $value) === 1;
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
