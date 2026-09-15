<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Context\ModelGraph;

final class ModelGraphTest extends TestCase
{
    private string $basePath;
    private ModelGraph $graph;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-mg-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        $this->graph = new ModelGraph($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->basePath);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_returns_empty_when_no_models_dir(): void
    {
        $graph = new ModelGraph(sys_get_temp_dir() . '/nonexistent-' . uniqid());
        $this->assertEquals([], $graph->parseRelations());
    }

    public function test_returns_empty_for_empty_models_dir(): void
    {
        $this->assertEquals([], $this->graph->parseRelations());
    }

    public function test_parses_model_relations(): void
    {
        $this->createModel('User', [
            'function posts(): HasMany',
            'return $this->hasMany(Post::class);',
        ]);
        $this->createModel('Post', [
            'function user(): BelongsTo',
            'return $this->belongsTo(User::class);',
        ]);

        $graph = $this->graph->parseRelations();

        $this->assertArrayHasKey('User', $graph);
        $this->assertArrayHasKey('Post', $graph);
        $this->assertCount(1, $graph['User']);
        $this->assertEquals('hasMany', $graph['User'][0]['type']);
        $this->assertEquals('Post', $graph['User'][0]['target']);
    }

    public function test_parses_belongs_to_many(): void
    {
        $this->createModel('Role', [
            'function users(): BelongsToMany',
            'return $this->belongsToMany(User::class);',
        ]);

        $graph = $this->graph->parseRelations();
        $this->assertArrayHasKey('Role', $graph);
        $this->assertEquals('belongsToMany', $graph['Role'][0]['type']);
    }

    public function test_skips_models_without_relations(): void
    {
        $this->createModel('Simple', []);
        $graph = $this->graph->parseRelations();
        $this->assertArrayNotHasKey('Simple', $graph);
    }

    private function createModel(string $name, array $relationBlocks): void
    {
        $methods = '';
        foreach ($relationBlocks as $block) {
            if (str_starts_with($block, 'function')) {
                $methods .= "\n    public $block\n    {\n";
            } else {
                $methods .= "        $block\n    }\n";
            }
        }

        $content = <<<PHP
<?php

namespace App\Models;

use Siro\\Core\\Model;

final class {$name} extends Model
{
    protected string \$table = '{$name}s';{$methods}
}
PHP;
        file_put_contents($this->basePath . '/app/Models/' . $name . '.php', $content);
    }
}
