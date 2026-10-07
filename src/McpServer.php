<?php

declare(strict_types=1);

namespace SiroSoft\McpServer;

use SiroSoft\McpServer\Resource\ResourceInterface;
use SiroSoft\McpServer\Security\ApprovalPolicy;
use SiroSoft\McpServer\Security\AuditLogger;
use SiroSoft\McpServer\Tool\ToolInterface;

/**
 * Core MCP Server — JSON-RPC 2.0 over stdio.
 *
 * Implements the Model Context Protocol (MCP) for AI agents.
 * Reads JSON-RPC requests from STDIN, dispatches to tools/resources,
 * writes responses to STDOUT.
 *
 * @package SiroSoft\McpServer
 */
final class McpServer
{
    private const SERVER_VERSION = '0.4.2';
    private const DEFAULT_PROTOCOL_VERSION = '2025-03-26';
    /** @var list<string> */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];
    private const SERVER_NAME = 'siro-mcp-server';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, ResourceInterface> */
    private array $resourceHandlers = [];

    public function __construct(
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?ApprovalPolicy $approvalPolicy = null,
    ) {
        // Keep the no-argument constructor useful for protocol unit tests.
    }

    /**
     * Register a tool that the AI agent can call.
     */
    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Register a resource handler for siro:// URIs.
     */
    public function registerResource(ResourceInterface $resource): void
    {
        $this->resourceHandlers[$resource->getUriPrefix()] = $resource;
    }

    /**
     * Run the MCP server loop — reads from STDIN forever.
     */
    public function run(): void
    {
        while ($line = fgets(STDIN)) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            if (!is_array($request)) {
                $this->writeResponse($this->makeError(null, -32700, 'Parse error'));
                continue;
            }

            /** @var array<string, mixed> $request */
            $response = $this->handleRequest($request);
            if ($response !== null) {
                $this->writeResponse($response);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? null;
        /** @var int|string|null $id */
        $id = array_key_exists('id', $request) && (is_int($request['id']) || is_string($request['id']) || $request['id'] === null)
            ? $request['id']
            : null;

        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return $this->makeError($id, -32600, 'Invalid Request');
        }

        // JSON-RPC 2.0: no "id" field means notification — no response
        if (!array_key_exists('id', $request)) {
            return null;
        }

        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->makeError($id, -32602, 'Params must be an object');
        }
        /** @var array<string, mixed> $params */

        try {
            return match ($method) {
                'initialize' => $this->handleInitialize($params, $id),
                'notifications/initialized' => null, // no response for notifications
                'ping' => $this->makeResult($id, []),
                'tools/list' => $this->handleToolsList($id),
                'tools/call' => $this->handleToolsCall($params, $id),
                'resources/list' => $this->handleResourcesList($id),
                'resources/read' => $this->handleResourcesRead($params, $id),
                default => $this->makeError($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $e) {
            return $this->makeError($id, -32603, "Internal error: {$e->getMessage()}");
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function handleInitialize(array $params, int|string|null $id): array
    {
        $requestedVersion = $params['protocolVersion'] ?? self::DEFAULT_PROTOCOL_VERSION;
        if (!is_string($requestedVersion) || !in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return $this->makeError($id, -32602, 'Unsupported protocol version');
        }

        return $this->makeResult($id, [
            'protocolVersion' => $requestedVersion,
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ]);
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function handleToolsList(int|string|null $id): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $this->approvalPolicy?->augmentSchema($tool->getName(), $tool->getInputSchema()) ?? $tool->getInputSchema(),
            ];
        }

        return $this->makeResult($id, ['tools' => $tools]);
    }

    /**
     * @param array<string, mixed> $params
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params, int|string|null $id): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            return $this->makeError($id, -32602, 'Tool name is required');
        }

        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return $this->makeError($id, -32602, "Unknown tool: {$name}");
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }

        /** @var array<string, mixed> $arguments */
        $approvalPolicy = $this->approvalPolicy;
        $requiresApproval = $approvalPolicy !== null && $approvalPolicy->requiresApproval($name, $arguments);
        $approved = !$requiresApproval || $this->isApproved($approvalPolicy, $arguments);
        $auditRun = $this->auditLogger?->start($name, $id, $arguments);

        if (!$approved) {
            $message = 'Approval required. Set SIRO_MCP_APPROVAL_TOKEN for the operator and pass approval_token.';
            $this->auditLogger?->finish($auditRun ?? [], 'denied', $message, false);
            return $this->makeToolResult($id, $message, true, $auditRun['id'] ?? null);
        }

        $toolArguments = $approvalPolicy !== null ? $approvalPolicy->stripApprovalToken($arguments) : $arguments;
        /** @var array<string, mixed> $toolArguments */
        try {
            $result = $tool->execute($toolArguments);
            $this->auditLogger?->finish($auditRun ?? [], 'completed', $result, true);
            return $this->makeToolResult($id, $result, false, $auditRun['id'] ?? null);
        } catch (\Throwable $e) {
            $message = "Error: {$e->getMessage()}";
            $this->auditLogger?->finish($auditRun ?? [], 'failed', $message, true);
            return $this->makeToolResult($id, $message, true, $auditRun['id'] ?? null);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function isApproved(?ApprovalPolicy $policy, array $arguments): bool
    {
        return $policy !== null && $policy->isApproved($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeToolResult(int|string|null $id, string $text, bool $isError, ?string $runId): array
    {
        $result = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'isError' => $isError,
        ];
        if ($runId !== null) {
            $result['_meta'] = ['runId' => $runId];
        }

        return $this->makeResult($id, $result);
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function handleResourcesList(int|string|null $id): array
    {
        $resources = [];

        foreach ($this->resourceHandlers as $uriPrefix => $handler) {
            $items = $handler->listResources();
            foreach ($items as $uri => $description) {
                $resources[] = [
                    'uri' => $uri,
                    'name' => $description,
                    'mimeType' => 'text/plain',
                ];
            }
        }

        return $this->makeResult($id, ['resources' => $resources]);
    }

    /**
     * @param array<string, mixed> $params
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function handleResourcesRead(array $params, int|string|null $id): array
    {
        $uri = $params['uri'] ?? '';

        if (!is_string($uri) || $uri === '') {
            return $this->makeError($id, -32602, 'URI is required');
        }

        foreach ($this->resourceHandlers as $uriPrefix => $handler) {
            if (str_starts_with($uri, $uriPrefix)) {
                $result = $handler->readResource($uri);
                if (is_array($result)) {
                    return $this->makeResult($id, [
                        'contents' => [
                            [
                                'uri' => $uri,
                                'mimeType' => 'text/plain',
                                'text' => $result['text'],
                            ],
                        ],
                    ]);
                }
            }
        }

        return $this->makeError($id, -32602, "Resource not found: {$uri}");
    }

    /**
     * @param int|string|null $id
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function makeResult(int|string|null $id, mixed $data): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $data,
        ];
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function makeError(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function writeResponse(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            fwrite(STDOUT, $json . "\n");
            fflush(STDOUT);
        }
    }
}
