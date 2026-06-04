<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Resource;

/**
 * siro://debug/* resources — debugging and tracing.
 *
 * Reads Siro trace files from storage/framework/traces/.
 *
 * @package SiroSoft\McpServer\Resource
 */
final class DebugResource implements ResourceInterface
{
    private string $basePath;

    /** @var array<string, string> */
    private const RESOURCES = [
        'siro://debug/traces/latest' => 'Latest request trace',
        'siro://debug/errors/latest' => 'Latest application error',
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function getUriPrefix(): string
    {
        return 'siro://debug/';
    }

    public function listResources(): array
    {
        // Check if trace storage exists
        $traceDir = $this->basePath . '/storage/framework/traces';
        $hasTraces = is_dir($traceDir);

        if ($hasTraces) {
            return self::RESOURCES;
        }

        return [
            'siro://debug/errors/latest' => 'Latest application error',
        ];
    }

    public function readResource(string $uri): ?array
    {
        return match ($uri) {
            'siro://debug/traces/latest' => $this->getLatestTrace(),
            'siro://debug/errors/latest' => $this->getLatestError(),
            default => null,
        };
    }

    /**
     * @return array{text: string}
     */
    private function getLatestTrace(): array
    {
        $traceDir = $this->basePath . '/storage/framework/traces';
        $text = "=== Latest Request Trace ===\n\n";

        if (!is_dir($traceDir)) {
            $text .= "No trace directory found. Traces are stored in storage/framework/traces/.\n";
            $text .= "To enable tracing, ensure APP_ENV=local and tracing is configured.\n";
            return ['text' => $text];
        }

        // Find latest trace file
        $files = glob($traceDir . '/*.json');
        if ($files === false || $files === []) {
            $text .= "No trace files found.\n";
            return ['text' => $text];
        }

        // Sort by modification time, newest first
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latest = $files[0];

        $content = file_get_contents($latest);
        if ($content === false) {
            $text .= "Error reading trace file: {$latest}\n";
            return ['text' => $text];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            $text .= "Invalid trace file format.\n";
            return ['text' => $text];
        }

        /** @var array<string, mixed> $data */
        $text .= "Trace File: " . basename($latest) . "\n";

        $mtime = filemtime($latest);
        if ($mtime !== false) {
            $text .= "Time: " . date('Y-m-d H:i:s', $mtime) . "\n\n";
        } else {
            $text .= "Time: unknown\n\n";
        }

        // Format trace data
        $text .= "--- Request ---\n";
        $text .= "Method: " . (is_string($data['method'] ?? null) ? $data['method'] : 'N/A') . "\n";
        $text .= "Path: " . (is_string($data['path'] ?? null) ? $data['path'] : 'N/A') . "\n";
        $text .= "Status: " . (is_string($data['status'] ?? null) ? $data['status'] : 'N/A') . "\n";

        if (isset($data['duration']) && is_numeric($data['duration'])) {
            $text .= "Duration: " . round(floatval($data['duration']) * 1000, 2) . "ms\n";
        }

        if (isset($data['error']) && is_array($data['error'])) {
            /** @var array<string, mixed> $error */
            $error = $data['error'];
            $text .= "\n--- Error ---\n";
            $text .= "Message: " . (is_string($error['message'] ?? null) ? $error['message'] : 'N/A') . "\n";
            if (isset($error['file'])) {
                $text .= "File: " . (is_string($error['file']) ? $error['file'] : '') . "\n";
            }
            if (isset($error['line'])) {
                $text .= "Line: " . (is_string($error['line']) || is_int($error['line']) ? $error['line'] : '') . "\n";
            }
            if (isset($error['trace'])) {
                $text .= "Stack Trace:\n" . (is_string($error['trace']) ? $error['trace'] : '') . "\n";
            }
        }

        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getLatestError(): array
    {
        $logDir = $this->basePath . '/storage/logs';
        $text = "=== Latest Error ===\n\n";

        if (!is_dir($logDir)) {
            $text .= "No log directory found.\n";
            return ['text' => $text];
        }

        // Look for error log files
        $files = glob($logDir . '/*error*');
        if ($files === false || $files === []) {
            $files = glob($logDir . '/*.log');
        }

        if ($files === false || $files === []) {
            $text .= "No log files found.\n";
            return ['text' => $text];
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latest = $files[0];

        $content = file_get_contents($latest);
        if ($content === false) {
            $text .= "Error reading log file.\n";
            return ['text' => $text];
        }

        // Get last 50 lines (most recent errors)
        $lines = explode("\n", $content);
        $lines = array_slice($lines, -50);
        $text .= "Log File: " . basename($latest) . "\n";
        $text .= "Last " . count($lines) . " lines:\n\n";
        $text .= implode("\n", $lines);

        return ['text' => $text];
    }
}
