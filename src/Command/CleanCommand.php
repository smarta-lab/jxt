<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\LeftoverFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt clean`: lists leftovers on the test server that `jxt install` keeps.
 *
 * Leftovers are entries in the exclusive directories of the extension that the manifest in the project does not
 * contain, e.g. from earlier rsync deployments. Deleting needs the web server user and is not supported yet, so the
 * command only runs with `--dry-run`; a later version can add deleting without changing what an existing call does.
 */
#[AsCommand(name: 'clean', description: 'Lists files on the local Joomla test site that no longer belong to the extension.')]
final class CleanCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly PlanExpander $planExpander = new PlanExpander(),
        private readonly LeftoverFinder $leftoverFinder = new LeftoverFinder(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the leftovers (required for now).');
    }

    /**
     * Lists the leftovers; without `--dry-run` it fails, since deleting is not supported yet.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run') !== true) {
            $io->error('Deleting is not supported yet. Run jxt clean --dry-run to list the leftovers.');

            return Command::INVALID;
        }

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $joomlaRoot = $configuration->validJoomlaRoot();
            $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
            $manifest = $this->manifestParser->parseFile($manifestFile);
            $installedManifest = $joomlaRoot . '/' . $manifest->installedManifest();

            if (!is_file($installedManifest)) {
                throw new DeploymentException(sprintf('%s not found. The extension is not installed.', $installedManifest));
            }

            $leftovers = $this->leftoverFinder->find(
                $manifest,
                $this->manifestParser->parseFile($installedManifest),
                $this->planExpander->expand($manifest->plan, dirname($manifestFile)),
                $joomlaRoot,
            );
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('<info>%s</info> → %s (dry run)', $manifest->element, $joomlaRoot));
        $topLevel = $this->topLevel($leftovers);

        foreach ($topLevel as $leftover) {
            $suffix = $leftover->kind === EntryKind::Directory ? '/' : '';
            $io->writeln(sprintf('  <fg=red>- %s%s</>', $leftover->target, $suffix));
        }

        if ($topLevel === []) {
            $io->success('Dry run, nothing changed: no leftovers found.');

            return Command::SUCCESS;
        }

        $io->note('jxt clean cannot delete yet. ' . match ($configuration->webUser) {
            null => 'Remove the leftovers by hand, e.g. with rm -r.',
            default => sprintf('Remove the leftovers as %1$s, e.g. with sudo -u %1$s rm -r.', $configuration->webUser),
        });
        $io->success(sprintf(
            'Dry run, nothing changed: %d %s found.',
            count($topLevel),
            count($topLevel) === 1 ? 'leftover' : 'leftovers',
        ));

        return Command::SUCCESS;
    }

    /**
     * Drops the entries inside leftover directories, so that each directory is listed only once.
     *
     * @param list<Deletion> $leftovers Directory contents before the directory
     *
     * @return list<Deletion>
     */
    private function topLevel(array $leftovers): array
    {
        $directories = array_map(
            static fn (Deletion $leftover): string => $leftover->target . '/',
            array_filter($leftovers, static fn (Deletion $leftover): bool => $leftover->kind === EntryKind::Directory),
        );

        $topLevel = array_filter($leftovers, static function (Deletion $leftover) use ($directories): bool {
            foreach ($directories as $directory) {
                if (str_starts_with($leftover->target, $directory)) {
                    return false;
                }
            }

            return true;
        });

        usort($topLevel, static fn (Deletion $a, Deletion $b): int => strcmp($a->target, $b->target));

        return $topLevel;
    }
}
