<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use SmartA\Jxt\Check\Checker\Checker;
use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckContextFactory;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckSelection;
use SmartA\Jxt\Check\Finding;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Install\ProcessRunner;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Creates a module project in a temporary directory and runs checkers on it.
 *
 * Without `initGit()`, Git is reported as missing, since `tests/tmp/` lies in the jxt repository itself.
 */
trait CheckProjectTrait
{
    use GitTrait;
    use TemporaryDirectoryTrait;

    private const string MANIFEST = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <extension type="module" client="site" method="upgrade">
            <name>mod_example</name>
            <version>1.1.0</version>
            <files>
                <folder module="mod_example">tmpl</folder>
            </files>
            <languages>
                <language tag="en-GB">language/en-GB/mod_example.ini</language>
                <language tag="de-DE">language/de-DE/mod_example.ini</language>
            </languages>
            <media destination="mod_example" folder="media">
                <filename>joomla.asset.json</filename>
                <folder>css</folder>
                <folder>js</folder>
            </media>
        </extension>
        XML;

    /**
     * Creates the project directory with a root `.editorconfig` and the given manifest.
     */
    private function createProject(string $manifest = self::MANIFEST): string
    {
        $directory = $this->createTemporaryDirectory();
        $this->writeFile('mod_example.xml', $manifest . "\n");

        return $directory;
    }

    /**
     * Creates the context of the project.
     *
     * @param string $manifest File name of the manifest in the project directory
     */
    private function context(bool $release = false, string $manifest = 'mod_example.xml'): CheckContext
    {
        $manifestFile = $this->temporaryDirectory . '/' . $manifest;

        return (new CheckContextFactory($this->processRunner()))->create(
            $this->temporaryDirectory,
            $manifestFile,
            (new ManifestParser())->parseFile($manifestFile),
            $release,
        );
    }

    /**
     * Returns a real process runner if the project has its own Git repository, otherwise one that reports
     * that there is none.
     */
    private function processRunner(): ProcessRunner
    {
        return is_dir($this->temporaryDirectory . '/.git') ? new SystemProcessRunner() : new class implements ProcessRunner {
            #[\Override]
            public function run(array $command): ProcessResult
            {
                return new ProcessResult(128, 'fatal: not a git repository');
            }
        };
    }

    /**
     * Runs a checker and returns its findings as `LABEL check: message`.
     *
     * @return list<string>
     */
    private function findings(Checker $checker, ?CheckContext $context = null): array
    {
        $report = new CheckReport(CheckSelection::create());
        $checker->check($context ?? $this->context(), $report);
        $findings = array_merge(...array_map($report->findings(...), CheckName::groups()));

        return array_map(
            static fn (Finding $finding): string => sprintf('%s %s: %s', $finding->severity->label(), $finding->check->value, $finding->text()),
            $findings,
        );
    }
}
