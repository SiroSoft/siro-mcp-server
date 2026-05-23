<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * scaffold_migration — Generate a database migration file.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ScaffoldMigration implements ToolInterface
{
    private PathValidator $pathValidator;
    private string $basePath;

    /** @var array<string, string> Column type definitions */
    private const COLUMN_TYPES = [
        'id'       => "\$table->id();",
        'string'   => "\$table->string('{name}')",
        'text'     => "\$table->text('{name}')",
        'integer'  => "\$table->integer('{name}')",
        'bigint'   => "\$table->bigInteger('{name}')",
        'float'    => "\$table->float('{name}')",
        'boolean'  => "\$table->boolean('{name}')",
        'date'     => "\$table->date('{name}')",
        'datetime' => "\$table->dateTime('{name}')",
        'json'     => "\$table->json('{name}')",
        'decimal'  => "\$table->decimal('{name}', {precision}, {scale})",
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
        $this->pathValidator = new PathValidator($this->basePath);
    }

    public function getName(): string
    {
        return 'scaffold_migration';
    }

    public function getDescription(): string
    {
        return 'Generate a database migration file with schema columns, indexes, and foreign keys.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Migration name (e.g. create_users_table, add_email_to_users)',
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Target table name',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Migration action: create, alter, drop',
                    'enum' => ['create', 'alter', 'drop'],
                    'default' => 'create',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => array_keys(self::COLUMN_TYPES)],
                            'nullable' => ['type' => 'boolean', 'default' => false],
                            'default' => ['type' => 'string'],
                            'unique' => ['type' => 'boolean', 'default' => false],
                            'precision' => ['type' => 'integer'],
                            'scale' => ['type' => 'integer'],
                        ],
                    ],
                    'description' => 'Column definitions',
                ],
                'foreign_keys' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'column' => ['type' => 'string'],
                            'references' => ['type' => 'string'],
                            'on' => ['type' => 'string'],
                            'onDelete' => ['type' => 'string'],
                        ],
                    ],
                ],
                'timestamps' => [
                    'type' => 'boolean',
                    'description' => 'Add created_at / updated_at columns',
                    'default' => true,
                ],
                'soft_deletes' => [
                    'type' => 'boolean',
                    'description' => 'Add deleted_at column',
                    'default' => false,
                ],
            ],
            'required' => ['name', 'table'],
        ];
    }

    public function execute(array $arguments): string
    {
        $name = $arguments['name'] ?? '';
        $table = $arguments['table'] ?? '';
        $action = $arguments['action'] ?? 'create';
        $columns = $arguments['columns'] ?? [];
        $foreignKeys = $arguments['foreign_keys'] ?? [];
        $timestamps = $arguments['timestamps'] ?? true;
        $softDeletes = $arguments['soft_deletes'] ?? false;

        if (!is_string($name) || trim($name) === '') {
            return 'Error: name parameter is required.';
        }
        if (!is_string($table) || trim($table) === '') {
            return 'Error: table parameter is required.';
        }

        $filename = date('Y_m_d_His') . '_' . $this->sanitizeName($name) . '.php';
        $path = $this->basePath . '/database/migrations/' . $filename;

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $content = $this->generateMigration($name, $table, $action, $columns, $foreignKeys, $timestamps, $softDeletes);

        $bytesWritten = @file_put_contents($path, $content);
        if ($bytesWritten === false) {
            return "Error: Failed to write migration file.";
        }

        return "OK: Generated database/migrations/{$filename} ({$bytesWritten} bytes)";
    }

    private function generateMigration(string $name, string $table, string $action, array $columns, array $foreignKeys, bool $timestamps, bool $softDeletes): string
    {
        if ($action === 'create') {
            $schemaCalls = $this->buildCreateSchema($table, $columns, $foreignKeys, $timestamps, $softDeletes);
        } elseif ($action === 'alter') {
            $schemaCalls = $this->buildAlterSchema($table, $columns, $foreignKeys, $timestamps, $softDeletes);
        } else {
            $schemaCalls = "        Schema::dropIfExists('{$table}');";
        }

        return <<<PHP
<?php

declare(strict_types=1);

use Siro\\Core\\Schema;
use Siro\\Core\\DB\\Blueprint;

/**
 * {$name}
 *
 * Auto-generated by Siro MCP Server
 */
return new class
{
    public function up(): void
    {
{$schemaCalls}
    }

    public function down(): void
    {
        // TODO: Define reverse migration
    }
};
PHP;
    }

    private function buildCreateSchema(string $table, array $columns, array $foreignKeys, bool $timestamps, bool $softDeletes): string
    {
        $lines = [];
        $lines[] = "        Schema::create('{$table}', function (Blueprint \$table) {";

        $hasId = false;
        foreach ($columns as $col) {
            $line = '            ' . $this->buildColumnLine($col);
            $lines[] = $line;
            if (($col['name'] ?? '') === 'id') {
                $hasId = true;
            }
        }

        if (!$hasId) {
            $lines[] = "            \$table->id();";
        }

        if ($timestamps) {
            $lines[] = "            \$table->timestamps();";
        }
        if ($softDeletes) {
            $lines[] = "            \$table->softDeletes();";
        }

        foreach ($foreignKeys as $fk) {
            $line = '            ' . $this->buildForeignKeyLine($fk);
            $lines[] = $line;
        }

        $lines[] = "        });";

        return implode("\n", $lines);
    }

    private function buildAlterSchema(string $table, array $columns, array $foreignKeys, bool $timestamps, bool $softDeletes): string
    {
        $lines = [];
        $lines[] = "        Schema::table('{$table}', function (Blueprint \$table) {";

        foreach ($columns as $col) {
            $line = '            ' . $this->buildColumnLine($col);
            $lines[] = $line;
        }

        if ($timestamps) {
            $lines[] = "            \$table->timestamps();";
        }
        if ($softDeletes) {
            $lines[] = "            \$table->softDeletes();";
        }

        foreach ($foreignKeys as $fk) {
            $line = '            ' . $this->buildForeignKeyLine($fk);
            $lines[] = $line;
        }

        $lines[] = "        });";

        return implode("\n", $lines);
    }

    private function buildColumnLine(array $col): string
    {
        $name = $col['name'] ?? '';
        $type = $col['type'] ?? 'string';
        $nullable = $col['nullable'] ?? false;
        $default = $col['default'] ?? null;
        $unique = $col['unique'] ?? false;

        if ($type === 'id' && $name === '') {
            return "\$table->id();";
        }

        if ($type === 'id') {
            return "\$table->id('{$name}');";
        }

        if (!isset(self::COLUMN_TYPES[$type])) {
            $type = 'string';
        }

        $template = self::COLUMN_TYPES[$type];
        $line = str_replace('{name}', $name, $template);
        $line = str_replace('{precision}', (string) ($col['precision'] ?? 10), $line);
        $line = str_replace('{scale}', (string) ($col['scale'] ?? 2), $line);

        if ($nullable) {
            $line .= '->nullable()';
        }
        if ($default !== null) {
            $line .= "->default('{$default}')";
        }
        if ($unique) {
            $line .= '->unique()';
        }

        return $line;
    }

    private function buildForeignKeyLine(array $fk): string
    {
        $column = $fk['column'] ?? '';
        $references = $fk['references'] ?? 'id';
        $on = $fk['on'] ?? '';
        $onDelete = $fk['onDelete'] ?? 'cascade';

        return "\$table->foreign('{$column}')->references('{$references}')->on('{$on}')->onDelete('{$onDelete}');";
    }

    private function sanitizeName(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_]+/', '_', $name));
    }
}
