<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Security;

/**
 * Explicit operator approval for tools that can change project state.
 */
final class ApprovalPolicy
{
    private const TOKEN_ENV = 'SIRO_MCP_APPROVAL_TOKEN';

    /** @var array<string, true> */
    private const MUTATING_TOOLS = [
        'write_file' => true,
        'patch_file' => true,
        'scaffold_model' => true,
        'scaffold_controller' => true,
        'scaffold_migration' => true,
        'scaffold_resource' => true,
    ];

    /** @param array<string, mixed> $arguments */
    public function requiresApproval(string $toolName, array $arguments = []): bool
    {
        if (isset(self::MUTATING_TOOLS[$toolName])) {
            return true;
        }

        if ($toolName !== 'execute_cli') {
            return false;
        }

        $command = $arguments['command'] ?? '';
        if (!is_string($command)) {
            return false;
        }

        $name = preg_split('/\s+/', trim($command))[0] ?? '';
        return in_array($name, ['migrate', 'migrate:fresh', 'migrate:rollback', 'db:seed'], true);
    }

    /** @param array<string, mixed> $arguments */
    public function isApproved(array $arguments): bool
    {
        $configured = getenv(self::TOKEN_ENV);
        $provided = $arguments['approval_token'] ?? null;

        return is_string($configured)
            && $configured !== ''
            && is_string($provided)
            && $provided !== ''
            && hash_equals($configured, $provided);
    }

    /**
     * Remove the approval credential before handing arguments to a tool.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function stripApprovalToken(array $arguments): array
    {
        unset($arguments['approval_token']);
        /** @var array<string, mixed> $arguments */
        return $arguments;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function augmentSchema(string $toolName, array $schema): array
    {
        if (!isset(self::MUTATING_TOOLS[$toolName]) && $toolName !== 'execute_cli') {
            return $schema;
        }

        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $properties['approval_token'] = [
            'type' => 'string',
            'description' => 'Operator approval token. Required for file changes, scaffolding, and destructive CLI commands.',
        ];
        $schema['properties'] = $properties;

        return $schema;
    }
}
