<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

/**
 * execute_cli — Run safe Siro CLI commands via sandbox whitelist.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ExecuteCli implements ToolInterface
{
    private string $basePath;

    /** @var array<string, string> Command whitelist — name => description */
    private const WHITELIST = [
        // Read-only — safe to run anytime
        'route:list'    => 'List all registered routes',
        'route:search'  => 'Search routes by keyword',
        'route:rules'   => 'Show validation rules for routes',
        'migrate:status' => 'Show migration status',
        'env:check'     => 'Check environment configuration',
        'doctor'        => 'System health check',
        'db:show'       => 'Show table data or schema',
        'rate:status'   => 'Rate limit status',
        'trace:list'    => 'List recent traces',
        'config:clear'  => 'Clear cached config',
        'env:cache'     => 'Cache environment variables',

        // Scaffold — generate files (safe, no destructive actions)
        'make:model'     => 'Generate a model class',
        'make:controller' => 'Generate a controller class',
        'make:migration'  => 'Generate a migration file',
        'make:crud'      => 'Full CRUD scaffolding',
        'make:service'   => 'Generate a service class',
        'make:repository' => 'Generate a repository class',
        'make:middleware' => 'Generate a middleware class',
        'make:request'   => 'Generate a FormRequest class',
        'make:rule'      => 'Generate a validation rule class',
        'make:seeder'    => 'Generate a seeder class',
        'make:factory'   => 'Generate a factory class',
        'make:test'      => 'Generate a test class',
        'make:job'       => 'Generate a job class',
        'make:mail'      => 'Generate a mail class',
        'make:event'     => 'Generate an event class',
        'make:listener'  => 'Generate an event listener',
        'make:observer'  => 'Generate a model observer',
        'make:auth'      => 'Generate authentication system',
        'make:resource'  => 'Generate API resource transformer',
        'make:openapi'   => 'Generate OpenAPI specification',

        // Destructive — only allowed with --force flag
        'migrate'       => 'Run pending migrations [requires --force]',
        'migrate:fresh' => 'Drop all tables and re-migrate [requires --force]',
        'migrate:rollback' => 'Rollback migrations [requires --force]',
        'db:seed'       => 'Run database seeders [requires --force]',
    ];

    /** @var array<string, true> Commands that are NEVER allowed */
    private const BLOCKLIST = [
        'tinker' => true,
        'shell' => true,
        'exec' => true,
        'eval' => true,
        'system' => true,
        'passthru' => true,
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function getName(): string
    {
        return 'execute_cli';
    }

    public function getDescription(): string
    {
        $commands = implode(', ', array_keys(self::WHITELIST));
        return "Run safe Siro CLI commands via sandbox whitelist. Allowed: {$commands}. Destructive commands require --force. Blocked: tinker, shell.";
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Siro CLI command to execute (e.g. route:list, make:model Product)',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Allow destructive commands (migrate, migrate:fresh, db:seed)',
                    'default' => false,
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $arguments): string
    {
        $commandStr = $arguments['command'] ?? '';
        $force = $arguments['force'] ?? false;

        if (!is_string($commandStr) || trim($commandStr) === '') {
            return 'Error: command parameter is required.';
        }

        $commandStr = trim($commandStr);

        // Parse the command name (first token)
        $parts = preg_split('/\s+/', $commandStr);
        if ($parts === false) {
            return 'Error: Failed to parse command.';
        }
        $cmdName = $parts[0] ?? '';

        // Check blocklist first
        if (isset(self::BLOCKLIST[$cmdName])) {
            return "Error: '{$cmdName}' is blocked for security reasons and cannot be executed via MCP.";
        }

        // Check whitelist
        if (!isset(self::WHITELIST[$cmdName])) {
            $allowed = implode(', ', array_keys(self::WHITELIST));
            return "Error: '{$cmdName}' is not in the allowed command whitelist.\nAllowed commands: {$allowed}";
        }

        // Check if command is destructive and requires --force
        $destructiveCommands = ['migrate', 'migrate:fresh', 'migrate:rollback', 'db:seed'];
        if (in_array($cmdName, $destructiveCommands, true) && !$force) {
            return "Error: '{$cmdName}' is a destructive command. Pass force=true to execute.\n"
                . "Always verify the database state before allowing destructive operations.";
        }

        // Build the siro command
        $siroScript = $this->basePath . '/siro';
        if (!file_exists($siroScript)) {
            return "Error: siro CLI not found at {$siroScript}";
        }

        $escapedArgs = $this->escapeShellArgs(array_slice($parts, 1));
        $fullCommand = PHP_BINARY . ' ' . escapeshellarg($siroScript) . ' ' . escapeshellarg($cmdName) . ($escapedArgs !== '' ? ' ' . $escapedArgs : '');

        return $this->execCommand($fullCommand);
    }

    private function execCommand(string $command, int $timeout = 300): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->basePath);

        if (!is_resource($process)) {
            return 'Error: Failed to execute command.';
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            if ((time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return "Error: Command timed out after {$timeout} seconds.";
            }

            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out !== false) {
                $stdout .= $out;
            }
            if ($err !== false) {
                $stderr .= $err;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            usleep(100000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $result = '';
        if ($stdout !== '') {
            $result .= $stdout;
        }
        if ($stderr !== '') {
            $result .= "\n--- STDERR ---\n" . $stderr;
        }

        $result .= "\nExit code: {$exitCode}";

        return $result;
    }

    /**
     * Escape shell arguments safely.
     *
     * @param array<int, string> $args
     * @return string
     */
    private function escapeShellArgs(array $args): string
    {
        if ($args === []) {
            return '';
        }

        $escaped = [];
        foreach ($args as $arg) {
            $escaped[] = escapeshellarg($arg);
        }

        return implode(' ', $escaped);
    }
}
