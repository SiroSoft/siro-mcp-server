<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * patch_file — Apply surgical diffs to existing files with safe mode.
 *
 * Supports unified diff format. In diff mode (default), returns a preview
 * of changes. In direct mode, applies changes immediately.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class PatchFile implements ToolInterface
{
    private PathValidator $pathValidator;

    public function __construct(string $basePath)
    {
        $this->pathValidator = new PathValidator(rtrim($basePath, '\\/'));
    }

    public function getName(): string
    {
        return 'patch_file';
    }

    public function getDescription(): string
    {
        return 'Apply surgical diffs to existing files. In "diff" mode (safe/default), returns a preview of changes. Use "direct" mode for automated patching. Supports unified diff format with +/- lines.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path relative to project root (e.g. app/Controllers/ProductController.php)',
                ],
                'diff' => [
                    'type' => 'string',
                    'description' => 'Unified diff content. Lines starting with + are added, - are removed.',
                ],
                'mode' => [
                    'type' => 'string',
                    'description' => 'Patch mode: "diff" (safe, preview only) or "direct" (apply immediately)',
                    'enum' => ['diff', 'direct'],
                    'default' => 'diff',
                ],
            ],
            'required' => ['path', 'diff'],
        ];
    }

    public function execute(array $arguments): string
    {
        $userPath = $arguments['path'] ?? '';
        $diff = $arguments['diff'] ?? '';
        $mode = $arguments['mode'] ?? 'diff';

        if (!in_array($mode, ['diff', 'direct'], true)) {
            return 'Error: Invalid mode. Must be "diff" or "direct".';
        }

        if (!is_string($userPath) || trim($userPath) === '') {
            return 'Error: path parameter is required.';
        }
        if (!is_string($diff) || trim($diff) === '') {
            return 'Error: diff parameter is required.';
        }

        try {
            $resolvedPath = $this->pathValidator->resolve($userPath);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        if (!is_file($resolvedPath)) {
            return "Error: File does not exist: {$userPath}. Use write_file to create new files.";
        }

        $original = file_get_contents($resolvedPath);
        if ($original === false) {
            return "Error: Could not read file: {$userPath}";
        }

        // Parse and apply the diff
        $result = $this->applyDiff($original, $diff);

        if (!$result['success']) {
            return "Error applying diff:\n{$result['error']}";
        }

        if ($mode === 'diff') {
            // Safe mode — return preview only
            return "=== DIFF PREVIEW ===\n"
                . "File: {$userPath}\n"
                . "Mode: diff (safe — changes NOT applied)\n"
                . "Set mode=direct to apply these changes.\n\n"
                . "--- Changes ---\n"
                . "Lines removed: {$result['removed']}\n"
                . "Lines added: {$result['added']}\n\n"
                . $this->formatSideBySide($original, $result['content'], $userPath);
        }

        // Direct mode — apply changes
        $bytesWritten = @file_put_contents($resolvedPath, $result['content']);
        if ($bytesWritten === false) {
            return "Error: Failed to write patched file: {$userPath}";
        }

        return "OK: Patch applied to {$userPath} ({$result['removed']} lines removed, {$result['added']} lines added)";
    }

    /**
     * Apply a unified-diff-like patch to the original content.
     *
     * Parses lines starting with - (remove), + (add), and context (unchanged).
     *
     * @return array{success: bool, content: string, removed: int, added: int, error: string}
     */
    private function applyDiff(string $original, string $diff): array
    {
        $originalLines = explode("\n", $original);
        $diffLines = explode("\n", $diff);

        $resultLines = [];
        $originalIndex = 0;

        $removed = 0;
        $added = 0;

        foreach ($diffLines as $diffLine) {
            // Skip unified diff metadata and the "no newline" marker.
            if (str_starts_with($diffLine, '---') || str_starts_with($diffLine, '+++') || str_starts_with($diffLine, '@@') || $diffLine === '\\ No newline at end of file') {
                continue;
            }

            if (str_starts_with($diffLine, '-')) {
                // Remove line — expect match with original
                $expectedOriginal = substr($diffLine, 1);
                $actualOriginal = $originalLines[$originalIndex] ?? null;

                if ($actualOriginal !== null && $expectedOriginal === $actualOriginal) {
                    // Remove this line
                    $removed++;
                    $originalIndex++;
                } else {
                    return [
                        'success' => false,
                        'content' => '',
                        'removed' => 0,
                        'added' => 0,
                        'error' => "Line mismatch at original line " . ($originalIndex + 1)
                            . "\n  Expected (in file): " . ($actualOriginal ?? '<end of file>')
                            . "\n  Diff says (-): " . $expectedOriginal,
                    ];
                }
            } elseif (str_starts_with($diffLine, '+')) {
                // Add line
                $added++;
                $resultLines[] = substr($diffLine, 1);
            } else {
                // Context lines may be prefixed with a space in unified diff format.
                $expectedOriginal = str_starts_with($diffLine, ' ') ? substr($diffLine, 1) : $diffLine;
                $actualOriginal = $originalLines[$originalIndex] ?? null;
                if ($actualOriginal !== $expectedOriginal) {
                    return [
                        'success' => false,
                        'content' => '',
                        'removed' => 0,
                        'added' => 0,
                        'error' => "Context mismatch at original line " . ($originalIndex + 1),
                    ];
                }
                $resultLines[] = $actualOriginal;
                $originalIndex++;
            }
        }

        // Copy remaining original lines
        while ($originalIndex < count($originalLines)) {
            $resultLines[] = $originalLines[$originalIndex];
            $originalIndex++;
        }

        return [
            'success' => true,
            'content' => implode("\n", $resultLines),
            'removed' => $removed,
            'added' => $added,
            'error' => '',
        ];
    }

    /**
     * Format a side-by-side comparison for preview.
     */
    private function formatSideBySide(string $original, string $patched, string $filename): string
    {
        $origLines = explode("\n", $original);
        $newLines = explode("\n", $patched);

        $maxOrig = count($origLines);
        $maxNew = count($newLines);
        $maxLines = max($maxOrig, $maxNew);

        $output = "--- a/{$filename}\n+++ b/{$filename}\n\n";

        for ($i = 0; $i < $maxLines; $i++) {
            $origLine = $i < $maxOrig ? $origLines[$i] : '';
            $newLine = $i < $maxNew ? $newLines[$i] : '';

            if ($origLine !== $newLine) {
                if ($i < $maxOrig && ($i >= $maxNew || !isset($newLines[$i]) || $newLines[$i] !== $origLines[$i])) {
                    $output .= "-{$origLine}\n";
                }
                if ($i < $maxNew && ($i >= $maxOrig || !isset($origLines[$i]) || $origLines[$i] !== $newLines[$i])) {
                    $output .= "+{$newLine}\n";
                }
            }
        }

        return $output;
    }
}
