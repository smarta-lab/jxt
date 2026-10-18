<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\ProjectBuilder;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\JxtException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt build`: creates an installable ZIP file from the project directory or from a Git tag.
 */
#[AsCommand(name: 'build', description: 'Builds an installable ZIP file of the extension.')]
final class BuildCommand extends Command
{
    private readonly ProjectBuilder $projectBuilder;

    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        ?ProjectBuilder $projectBuilder = null,
    ) {
        $this->projectBuilder = $projectBuilder ?? new ProjectBuilder($configurationLoader);
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'tag',
            null,
            InputOption::VALUE_REQUIRED,
            'Build from a Git tag instead of the working copy, e.g. v1.0.0',
        );
    }

    /**
     * Creates the ZIP file and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tag = $input->getOption('tag');

        try {
            $distDirectory = $this->configurationLoader->load($this->projectDirectory)->distDirectory;
            $build = is_string($tag)
                ? $this->projectBuilder->buildTag($this->projectDirectory, $tag, $distDirectory)
                : $this->projectBuilder->build($this->projectDirectory, $distDirectory);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($build->unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not in the ZIP:', ...$build->unlisted]));
        }

        TagWarning::show($io, $build);

        $io->success(sprintf(
            'Created %s%s (%d files).',
            $this->displayPath($build->package->path),
            $build->tag === null ? '' : ' from tag ' . $build->tag,
            $build->package->fileCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Shortens paths inside the project directory to the relative path.
     */
    private function displayPath(string $path): string
    {
        $prefix = $this->projectDirectory . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
