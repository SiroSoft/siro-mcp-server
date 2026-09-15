<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Security;

/**
 * Append-only JSONL audit log for MCP tool executions.
 */
final class AuditLogger
{
    private const MAX_TEXT_LENGTH = 16000;
    private const MAX_RECENT_RUNS = 50;

    private string $logPath;

    public function __construct(string $basePath)
    {
        $this->logPath = rtrim($basePath, '\\/') . '/storage/framework/mcp/runs.jsonl';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{id: string, started_at: string, started_ns: int, tool: string, request_id: int|string|null, arguments: array<string, mixed>}
     */
    public function start(string $tool, int|string|null $requestId, array $arguments): array
    {
        $redactedArguments = $this->redactArguments($arguments);
        return [
            'id' => bin2hex(random_bytes(8)),
            'started_at' => gmdate('c'),
            'started_ns' => (int) hrtime(true),
            'tool' => $tool,
            'request_id' => $requestId,
            'arguments' => $redactedArguments,
        ];
    }

    /**
     * @param array<string, mixed> $run
     */
    public function finish(array $run, string $status, string $result, bool $approved): bool
    {
        $startedNs = is_int($run['started_ns'] ?? null) ? $run['started_ns'] : hrtime(true);
        unset($run['started_ns']);

        $run['finished_at'] = gmdate('c');
        $run['duration_ms'] = round((hrtime(true) - $startedNs) / 1_000_000, 3);
        $run['status'] = $status;
        $run['approved'] = $approved;
        $run['result'] = $this->redact($result);

        $directory = dirname($this->logPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $encoded = json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return @file_put_contents($this->logPath, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $lines = @file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $runs = [];
        foreach (array_slice($lines, -min(max($limit, 1), self::MAX_RECENT_RUNS)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $runs[] = $decoded;
            }
        }

        return $runs;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $runs = $this->recent(1);
        return $runs[0] ?? null;
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : '';
                $redacted[$key] = $this->isSensitiveKey($keyString) ? '[REDACTED]' : $this->redact($item);
            }
            return $redacted;
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace('/(?i)(password|token|secret|api[_-]?key)\s*([:=])\s*[^\s,;]+/', '$1$2[REDACTED]', $value) ?? $value;
        return $this->truncate($value);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function redactArguments(array $arguments): array
    {
        $redacted = array_fill_keys(array_keys($arguments), null);
        foreach ($arguments as $key => $value) {
            $keyString = strtolower($key);
            $redacted[$key] = $this->isSensitiveKey($keyString) ? '[REDACTED]' : $this->redact($value);
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        return $key !== '' && (str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'api_key')
            || str_contains($key, 'authorization'));
    }

    private function truncate(string $value): string
    {
        return strlen($value) > self::MAX_TEXT_LENGTH
            ? substr($value, 0, self::MAX_TEXT_LENGTH) . '\n...[truncated]'
            : $value;
    }
}
