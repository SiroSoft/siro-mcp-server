<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Resource;

use SiroSoft\McpServer\Security\AuditLogger;

/**
 * siro://mcp/runs/* resources — recent MCP execution records.
 */
final class AuditResource implements ResourceInterface
{
    public function __construct(private readonly AuditLogger $logger)
    {
    }

    public function getUriPrefix(): string
    {
        return 'siro://mcp/runs/';
    }

    public function listResources(): array
    {
        $resources = [
            'siro://mcp/runs/latest' => 'Latest MCP tool execution',
        ];

        foreach ($this->logger->recent(20) as $run) {
            $id = $run['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $resources['siro://mcp/runs/' . $id] = 'MCP run ' . $id;
            }
        }

        return $resources;
    }

    public function readResource(string $uri): array
    {
        if ($uri === 'siro://mcp/runs/latest') {
            $run = $this->logger->latest();
        } else {
            $id = substr($uri, strlen('siro://mcp/runs/'));
            $run = null;
            foreach ($this->logger->recent(50) as $candidate) {
                if (($candidate['id'] ?? null) === $id) {
                    $run = $candidate;
                    break;
                }
            }
        }

        if ($run === null) {
            return ['text' => "No MCP run found for {$uri}.\n"];
        }

        $text = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['text' => $text === false ? '{}' : $text];
    }
}
