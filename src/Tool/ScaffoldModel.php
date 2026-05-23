<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * scaffold_model — Generate a model class.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ScaffoldModel implements ToolInterface
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
        return 'scaffold_model';
    }

    public function getDescription(): string
    {
        return 'Generate a Siro model class with table, fillable, casts, and optional relations.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Model name (e.g. Product, User)',
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name (auto-derived from name if not provided)',
                ],
                'fillable' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Fillable columns',
                ],
                'casts' => [
                    'type' => 'object',
                    'description' => 'Cast definitions (e.g. {"price": "float", "is_active": "bool"})',
                ],
                'relations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['hasMany', 'belongsTo', 'belongsToMany', 'hasOne']],
                            'target' => ['type' => 'string', 'description' => 'Related model class'],
                            'method' => ['type' => 'string', 'description' => 'Relation method name (auto from target if not set)'],
                        ],
                    ],
                    'description' => 'Model relations',
                ],
                'soft_deletes' => [
                    'type' => 'boolean',
                    'description' => 'Enable soft deletes',
                    'default' => false,
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
        $table = $arguments['table'] ?? $this->tableize($name);
        $fillable = $arguments['fillable'] ?? [];
        $casts = $arguments['casts'] ?? [];
        $relations = $arguments['relations'] ?? [];
        $softDeletes = $arguments['soft_deletes'] ?? false;

        $path = $this->basePath . '/app/Models/' . $name . '.php';

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $content = $this->generateModel($name, $table, $fillable, $casts, $relations, $softDeletes);

        $bytesWritten = @file_put_contents($path, $content);
        if ($bytesWritten === false) {
            return "Error: Failed to write model file.";
        }

        return "OK: Generated app/Models/{$name}.php ({$bytesWritten} bytes)";
    }

    private function generateModel(string $name, string $table, array $fillable, array $casts, array $relations, bool $softDeletes): string
    {
        $fillableStr = $fillable !== [] ? "\n        '" . implode("',\n        '", $fillable) . "',\n    " : '';
        $castsStr = '';
        $uses = ['Siro\\Core\\Model'];
        $traits = '';

        if ($softDeletes) {
            $uses[] = 'Siro\\Core\\Model\\SoftDeletes';
            $traits = "\n    use SoftDeletes;\n";
        }

        foreach ($casts as $key => $type) {
            $castsStr .= "\n        '{$key}' => '{$type}',";
        }

        $relationsStr = '';
        foreach ($relations as $rel) {
            $relType = $rel['type'] ?? 'hasMany';
            $relTarget = $rel['target'] ?? 'RelatedModel';
            $relMethod = $rel['method'] ?? lcfirst(basename(str_replace('\\', '/', $relTarget)));

            $relationsStr .= <<<PHP


    public function {$relMethod}(): \\Siro\\Core\\ModelRelations\\{$this->studly($relType)}
    {
        return \$this->{$relType}({$relTarget}::class);
    }
PHP;
        }

        $usesStr = implode(";\nuse ", $uses);

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use {$usesStr};

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
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function tableize(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value)) . 's';
    }
}
