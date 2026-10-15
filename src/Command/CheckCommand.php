<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckContextFactory;
use SmartA\Jxt\Check\Checker\AssetChecker;
use SmartA\Jxt\Check\Checker\ChangelogChecker;
use SmartA\Jxt\Check\Checker\Checker;
use SmartA\Jxt\Check\Checker\ComposerChecker;
use SmartA\Jxt\Check\Checker\EditorConfigChecker;
use SmartA\Jxt\Check\Checker\LanguageChecker;
use SmartA\Jxt\Check\Checker\ManifestChecker;
use SmartA\Jxt\Check\CheckMode;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckSelection;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt check`: mechanical checks of the extension before a release.
 *
 * Exit code 0 without errors, 1 with errors (or warnings with `--strict`), 2 for an invalid call,
 * configuration or manifest.
 */
#[AsCommand(name: 'check', description: 'Checks the extension before a release.')]
final class CheckCommand extends Command
{
    /**
     * @param string        $projectDirectory Project directory of the extension, usually the current directory
     * @param list<Checker> $checkers         Checkers in output order; empty for the default checkers
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly CheckContextFactory $contextFactory = new CheckContextFactory(),
        private readonly array $checkers = [],
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Skip a group or check, e.g. manifest or manifest/stray-files (several or comma-separated)',
            )
            ->addOption(
                'include',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run a group or check despite an exclusion (several or comma-separated)',
            )
            ->addOption('release', null, InputOption::VALUE_NONE, 'Treat release-only problems as errors (missing CHANGELOG heading, asset versions)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as errors');
    }

    /**
     * Runs the checks and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $selection = CheckSelection::create(
                $configuration->checkExclude,
                $configuration->checkInclude,
                $this->names($input, 'exclude'),
                $this->names($input, 'include'),
            );
            $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
            $manifest = $this->manifestParser->parseFile($manifestFile);
            $context = $this->contextFactory->create($this->projectDirectory, $manifestFile, $manifest, (bool) $input->getOption('release'));
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $report = new CheckReport($selection);

        foreach ($this->checkers() as $checker) {
            if ($selection->isGroupEnabled($checker->group())) {
                $checker->check($context, $report);
            }
        }

        $this->render($io, $context, $selection, $report);

        return $this->conclude($io, $report, (bool) $input->getOption('strict'));
    }

    /**
     * Returns the check names of an option, split at commas.
     *
     * @return list<string>
     */
    private function names(InputInterface $input, string $option): array
    {
        $values = array_filter((array) $input->getOption($option), is_string(...));
        $names = array_map(trim(...), explode(',', implode(',', $values)));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    /**
     * Returns the checkers in output order.
     *
     * @return list<Checker>
     */
    private function checkers(): array
    {
        return $this->checkers !== [] ? $this->checkers : [
            new ManifestChecker(),
            new EditorConfigChecker(),
            new ChangelogChecker(),
            new AssetChecker(),
            new LanguageChecker(),
            new ComposerChecker(),
        ];
    }

    /**
     * Prints the mode and the findings grouped by section.
     */
    private function render(SymfonyStyle $io, CheckContext $context, CheckSelection $selection, CheckReport $report): void
    {
        $io->writeln(sprintf(
            'Checking %s %s: %s, %s.',
            $context->manifest->element,
            $context->manifest->version ?? '(no version)',
            $context->state->mode->value,
            $context->state->reason,
        ));

        if ($context->release && $context->state->mode === CheckMode::PreCheck) {
            $io->writeln(sprintf('<comment>--release has no effect in a pre-release check (%s).</comment>', $context->state->reason));
        }

        foreach (CheckName::groups() as $group) {
            $io->section($group);

            if (!$selection->isGroupEnabled($group)) {
                $io->writeln('<comment>excluded</comment>');

                continue;
            }

            $findings = $report->findings($group);

            if ($findings === []) {
                $io->writeln('<info>OK</info>');
            }

            foreach ($findings as $finding) {
                $io->writeln(sprintf(
                    '<%s>%-5s</> %s <fg=gray>[%s]</>',
                    match ($finding->severity) {
                        Severity::Error => 'fg=red;options=bold',
                        Severity::Warning => 'fg=yellow',
                        Severity::Notice => 'fg=default',
                    },
                    $finding->severity->label(),
                    $finding->message,
                    $finding->check->value,
                ));
            }
        }
    }

    /**
     * Prints the summary and returns the exit code.
     */
    private function conclude(SymfonyStyle $io, CheckReport $report, bool $strict): int
    {
        $summary = sprintf(
            '%d error(s), %d warning(s), %d notice(s)',
            $report->count(Severity::Error),
            $report->count(Severity::Warning),
            $report->count(Severity::Notice),
        );

        if ($report->failed($strict)) {
            $io->error($summary . ($report->count(Severity::Error) === 0 ? ' (warnings count as errors with --strict)' : '') . '.');

            return Command::FAILURE;
        }

        $io->success($summary . '.');

        return Command::SUCCESS;
    }
}
