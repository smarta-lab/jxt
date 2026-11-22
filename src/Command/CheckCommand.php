<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\Preparation;
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
use SmartA\Jxt\Check\Checker\PackageChecker;
use SmartA\Jxt\Check\CheckMode;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckScope;
use SmartA\Jxt\Check\CheckSelection;
use SmartA\Jxt\Check\GithubAnnotation;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt check`: mechanical checks of the extension before a release.
 *
 * Exit code 0 without errors, 1 with errors (or warnings with `--strict`), 2 for an invalid call,
 * configuration or manifest, or a failing prepare step. The prepare steps run before the checks, since the checks
 * concern the files that would be shipped. With `--tag`, the committed files of a Git tag are checked as the final
 * check of that release, with the configuration from the tag and its prepare steps run in the exported files.
 * `--format=github` prints the findings as GitHub Actions annotations instead of sections. For a package, the checks
 * of the project run once, those of an extension for the package and each of its extensions (after their own prepare
 * steps), and the package entries are checked against the extensions.
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
        private readonly Preparation $preparation = new Preparation(),
        private readonly PackageResolver $packageResolver = new PackageResolver(),
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
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as errors')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Output format: %s', implode(', ', CheckFormat::values())),
                CheckFormat::Text->value,
            );
    }

    /**
     * Runs the checks and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $format = CheckFormat::tryFrom(is_string($format) ? $format : '');

        if ($format === null) {
            $io->error(sprintf('Unknown format, expected one of: %s.', implode(', ', CheckFormat::values())));

            return Command::INVALID;
        }

        $tag = $input->getOption('tag');

        try {
            [$context, $selection, $report] = is_string($tag)
                ? $this->checkTag($input, $output, $tag)
                : $this->check($input, $output, $this->projectDirectory);
        } catch (JxtException $exception) {
            match ($format) {
                CheckFormat::Text => $io->error($exception->getMessage()),
                CheckFormat::Github => $this->writeRaw($output, GithubAnnotation::command(Severity::Error, $exception->getMessage())),
            };

            return Command::INVALID;
        }

        $strict = (bool) $input->getOption('strict');

        if ($format === CheckFormat::Github) {
            $this->renderAnnotations($output, $context, $report);
            $this->writeRaw($output, $this->summary($report, $strict) . '.');
        } else {
            $this->render($io, $context, $selection, $report);
            $this->conclude($io, $report, $strict);
        }

        return $report->failed($strict) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Exports the tag into a temporary directory and checks its files.
     *
     * @return array{CheckContext, CheckSelection, CheckReport}
     *
     * @throws JxtException if there is no Git repository or tag, or the configuration or manifest is invalid
     */
    private function checkTag(InputInterface $input, OutputInterface $output, string $tag): array
    {
        $git = $this->contextFactory->repository($this->projectDirectory) ?? throw new BuildException(
            sprintf('--tag needs Git, but %s is not in a Git repository.', $this->projectDirectory),
        );

        return $this->tagExporter->export(
            $git,
            $tag,
            fn (string $directory): array => $this->check($input, $output, $directory, $git, $tag),
        );
    }

    /**
     * Runs the prepare steps and the enabled checkers on the project in the directory.
     *
     * @param OutputInterface    $output Output for the prepare steps
     * @param GitRepository|null $git    Repository of the project directory when the directory holds a tag
     *
     * @return array{CheckContext, CheckSelection, CheckReport}
     *
     * @throws JxtException if the configuration, the selection of checks or the manifest is invalid, or a prepare
     *                      step fails
     */
    private function check(
        InputInterface $input,
        OutputInterface $output,
        string $directory,
        ?GitRepository $git = null,
        ?string $tag = null,
    ): array {
        $configuration = $this->configurationLoader->load($directory);
        $selection = CheckSelection::create(
            $configuration->checkExclude,
            $configuration->checkInclude,
            $this->names($input, 'exclude'),
            $this->names($input, 'include'),
        );
        $this->preparation->run($configuration->prepare, $directory, $output);
        $manifestFile = $this->manifestLocator->locate($directory, $configuration->manifestFile);
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $resolution = $manifest->type === ExtensionType::Package
            ? $this->packageResolver->resolve($manifest, dirname($manifestFile), [$configuration->distDirectory])
            : null;

        foreach ($resolution?->children() ?? [] as $child) {
            $this->preparation->run($this->configurationLoader->load($child->directory())->prepare, $child->directory(), $output);
        }

        $release = (bool) $input->getOption('release');
        $context = $git !== null && $tag !== null
            ? $this->contextFactory->createForTag($directory, $manifestFile, $manifest, $release, $git, $tag)
            : $this->contextFactory->create($directory, $manifestFile, $manifest, $release);
        $context = $resolution === null ? $context : $this->contextFactory->withPackage($context, $resolution);
        $report = new CheckReport($selection);

        foreach ($this->checkers() as $checker) {
            if ($selection->isGroupEnabled($checker->group())) {
                foreach ($this->contexts($checker, $context) as $checked) {
                    $checker->check($checked, $report);
                }
            }
        }

        return [$context, $selection, $report];
    }

    /**
     * Returns the contexts the checker runs on: the project once, each extension of a package, or the package.
     *
     * @return list<CheckContext>
     */
    private function contexts(Checker $checker, CheckContext $context): array
    {
        return match ($checker->scope()) {
            CheckScope::Project => [$context],
            CheckScope::Extension => [$context, ...$context->children],
            CheckScope::Package => $context->package === null ? [] : [$context],
        };
    }

    /**
     * Returns the groups shown in the output: all except those that only concern packages, unless the project is
     * one.
     *
     * @return list<string>
     */
    private function groups(CheckContext $context): array
    {
        $packageGroups = array_map(
            static fn (Checker $checker): string => $checker->group(),
            array_filter($this->checkers(), static fn (Checker $checker): bool => $checker->scope() === CheckScope::Package),
        );

        return $context->package === null ? array_values(array_diff(CheckName::groups(), $packageGroups)) : CheckName::groups();
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
            new PackageChecker(),
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
        $io->writeln($this->heading($context), OutputInterface::OUTPUT_RAW);
        $hint = $this->releaseHint($context);

        if ($hint !== null) {
            $io->writeln('<comment>' . OutputFormatter::escape($hint) . '</comment>');
        }

        foreach ($this->extensionLines($context) as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }

        foreach ($this->groups($context) as $group) {
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
                    OutputFormatter::escape($finding->text()),
                    $finding->check->value,
                ));
            }
        }
    }

    /**
     * Prints the heading, the release hint and the findings as GitHub Actions workflow commands.
     *
     * The paths of the findings are relative to the project directory, GitHub expects them relative to the
     * repository root.
     */
    private function renderAnnotations(OutputInterface $output, CheckContext $context, CheckReport $report): void
    {
        $this->writeRaw($output, $this->heading($context));
        $hint = $this->releaseHint($context);

        if ($hint !== null) {
            $this->writeRaw($output, GithubAnnotation::command(Severity::Notice, $hint));
        }

        foreach ($this->extensionLines($context) as $line) {
            $this->writeRaw($output, $line);
        }

        $pathPrefix = $context->git?->prefix() ?? '';

        foreach (CheckName::groups() as $group) {
            foreach ($report->findings($group) as $finding) {
                $this->writeRaw($output, GithubAnnotation::forFinding($finding, $pathPrefix));
            }
        }
    }

    /**
     * Returns the first line of the output: extension, version and mode.
     */
    private function heading(CheckContext $context): string
    {
        return sprintf(
            'Checking %s %s: %s, %s.',
            $context->manifest->name(),
            $context->manifest->version ?? '(no version)',
            $context->state->mode->value,
            $context->state->reason,
        );
    }

    /**
     * Returns the lines listing the extensions of a package with their directories, empty for other types.
     *
     * @return list<string>
     */
    private function extensionLines(CheckContext $context): array
    {
        return array_map(
            static fn (CheckContext $child): string => sprintf(
                '  with %s %s in %s/',
                $child->manifest->name(),
                $child->manifest->version ?? '(no version)',
                substr($child->extensionDirectory, strlen($context->projectDirectory) + 1),
            ),
            $context->children,
        );
    }

    /**
     * Returns the hint that `--release` has no effect in a pre-release check, or null.
     */
    private function releaseHint(CheckContext $context): ?string
    {
        return $context->release && $context->state->mode === CheckMode::PreCheck
            ? sprintf('--release has no effect in a pre-release check (%s).', $context->state->reason)
            : null;
    }

    /**
     * Prints the summary as a success or error block.
     */
    private function conclude(SymfonyStyle $io, CheckReport $report, bool $strict): void
    {
        if ($report->failed($strict)) {
            $io->error($this->summary($report, $strict) . '.');
        } else {
            $io->success($this->summary($report, $strict) . '.');
        }
    }

    /**
     * Returns the numbers of findings, with a note if only warnings fail the check.
     */
    private function summary(CheckReport $report, bool $strict): string
    {
        $summary = sprintf(
            '%d error(s), %d warning(s), %d notice(s)',
            $report->count(Severity::Error),
            $report->count(Severity::Warning),
            $report->count(Severity::Notice),
        );

        return $report->failed($strict) && $report->count(Severity::Error) === 0
            ? $summary . ' (warnings count as errors with --strict)'
            : $summary;
    }

    /**
     * Prints a line without interpreting console tags such as `<version>`.
     */
    private function writeRaw(OutputInterface $output, string $line): void
    {
        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
