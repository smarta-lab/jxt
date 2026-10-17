<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\TagExporter;
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
use SmartA\Jxt\Git\GitRepository;
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
 * configuration or manifest. With `--tag`, the committed files of a Git tag are checked as the final check of
 * that release, with the configuration from the tag.
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
        private readonly TagExporter $tagExporter = new TagExporter(),
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
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Check the files of a Git tag instead of the working copy, e.g. v1.0.0')
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
        $tag = $input->getOption('tag');

        try {
            [$context, $selection, $report] = is_string($tag)
                ? $this->checkTag($input, $tag)
                : $this->check($input, $this->projectDirectory);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $this->render($io, $context, $selection, $report);

        return $this->conclude($io, $report, (bool) $input->getOption('strict'));
    }

    /**
     * Exports the tag into a temporary directory and checks its files.
     *
     * @return array{CheckContext, CheckSelection, CheckReport}
     *
     * @throws JxtException if there is no Git repository or tag, or the configuration or manifest is invalid
     */
    private function checkTag(InputInterface $input, string $tag): array
    {
        $git = $this->contextFactory->repository($this->projectDirectory) ?? throw new BuildException(
            sprintf('--tag needs Git, but %s is not in a Git repository.', $this->projectDirectory),
        );

        return $this->tagExporter->export(
            $git,
            $tag,
            fn (string $directory): array => $this->check($input, $directory, $git, $tag),
        );
    }

    /**
     * Runs the enabled checkers on the project in the directory.
     *
     * @param GitRepository|null $git Repository of the project directory when the directory holds a tag
     *
     * @return array{CheckContext, CheckSelection, CheckReport}
     *
     * @throws JxtException if the configuration, the selection of checks or the manifest is invalid
     */
    private function check(InputInterface $input, string $directory, ?GitRepository $git = null, ?string $tag = null): array
    {
        $configuration = $this->configurationLoader->load($directory);
        $selection = CheckSelection::create(
            $configuration->checkExclude,
            $configuration->checkInclude,
            $this->names($input, 'exclude'),
            $this->names($input, 'include'),
        );
        $manifestFile = $this->manifestLocator->locate($directory, $configuration->manifestFile);
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $release = (bool) $input->getOption('release');
        $context = $git !== null && $tag !== null
            ? $this->contextFactory->createForTag($directory, $manifestFile, $manifest, $release, $git, $tag)
            : $this->contextFactory->create($directory, $manifestFile, $manifest, $release);
        $report = new CheckReport($selection);

        foreach ($this->checkers() as $checker) {
            if ($selection->isGroupEnabled($checker->group())) {
                $checker->check($context, $report);
            }
        }

        return [$context, $selection, $report];
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
