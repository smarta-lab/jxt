<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\EditorConfig\EditorConfigResolver;

/**
 * Checks the text files of the project against their `.editorconfig` properties.
 *
 * Files are the tracked and untracked, not ignored files from Git; without Git, the files of the plan
 * and the files in the project directory itself except hidden ones, which are mostly tool files that
 * Git would ignore (`.envrc`, `.phpunit.result.cache`). Binary files (containing NUL bytes) are skipped.
 * Without any `.editorconfig`, only a notice is reported: jxt does not invent a style of its own.
 */
final readonly class EditorConfigChecker implements Checker
{
    public function __construct(private EditorConfigResolver $resolver = new EditorConfigResolver())
    {
    }

    #[\Override]
    public function group(): string
    {
        return 'editorconfig';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        if (!$this->resolver->hasFiles($context->projectDirectory)) {
            $report->add(CheckName::EditorConfigMissing, Severity::Notice, 'no .editorconfig found, whitespace checks skipped');

            return;
        }

        foreach ($this->files($context) as $file) {
            $content = file_get_contents($context->projectDirectory . '/' . $file);

            if ($content !== false && !str_contains($content, "\0")) {
                $properties = $this->resolver->properties($context->projectDirectory . '/' . $file);
                (new EditorConfigFileCheck($file, $content, $properties, $report))->run();
            }
        }
    }

    /**
     * Returns the files to check relative to the project directory.
     *
     * @return list<string>
     */
    private function files(CheckContext $context): array
    {
        if ($context->git !== null) {
            $files = $context->git->files();
        } else {
            $files = array_map(static fn (PlanEntry $entry): string => $context->projectPath($entry->source), $context->files);
            $topLevel = array_filter(scandir($context->projectDirectory) ?: [], static fn (string $file): bool => !str_starts_with($file, '.'));
            $files = [...$files, ...$topLevel];
        }

        $files = array_unique(array_filter(
            $files,
            static fn (string $file): bool => is_file($context->projectDirectory . '/' . $file) && !is_link($context->projectDirectory . '/' . $file),
        ));
        sort($files, SORT_STRING);

        return $files;
    }
}
