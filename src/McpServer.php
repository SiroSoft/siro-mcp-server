<?php

declare(strict_types=1);

namespace SiroSoft\McpServer;

use SiroSoft\McpServer\Resource\ResourceInterface;
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
    private const PROTOCOL_VERSION = '0.2.0';
    private const SERVER_NAME = 'siro-mcp-server';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, ResourceInterface> */
    private array $resourceHandlers = [];

    private bool $initialized = false;

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
            if (!is_array($request) || !isset($request['jsonrpc'], $request['method'])) {
                continue;
            }

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
        $method = $request['method'];
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

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
        $this->initialized = true;

        return $this->makeResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::PROTOCOL_VERSION,
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
                'inputSchema' => $tool->getInputSchema(),
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

        try {
            $result = $tool->execute($arguments);
            return $this->makeResult($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $result,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->makeResult($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Error: {$e->getMessage()}",
                    ],
                ],
                'isError' => true,
            ]);
        }
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
                                'text' => $result['text'] ?? '',
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
