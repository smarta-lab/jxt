<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Configuration\SiteEntry;
use SmartA\Jxt\Configuration\SiteFile;
use SmartA\Jxt\Configuration\SiteSelection;
use SmartA\Jxt\JxtException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt site`: lists, adds, removes and renames the test sites of the global configuration.
 *
 * The keys of a site are changed with `jxt config`, which only changes existing sites, so that a typo in `--site`
 * does not add a site.
 */
#[AsCommand(name: 'site', description: 'Lists, adds, removes and renames the test sites of the global configuration.')]
final class SiteCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory, usually the current directory
     * @param bool   $runsViaSudo      Whether the command runs via sudo; nothing is written then
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly bool $runsViaSudo,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list, add, remove or rename', SiteAction::List->value)
            ->addArgument('id', InputArgument::OPTIONAL, 'Id of the site, e.g. local')
            ->addArgument('new-id', InputArgument::OPTIONAL, 'New id of the site, for rename')
            ->addOption('default', null, InputOption::VALUE_NONE, 'With add: make the site the default site (default_site)');
    }

    /**
     * Runs the selected action and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $value = $input->getArgument('action');
        $action = SiteAction::tryFrom(is_string($value) ? $value : '');

        if ($action === null) {
            $io->error(sprintf('Unknown action "%s". Use list, add, remove or rename.', is_string($value) ? $value : ''));

            return Command::INVALID;
        }

        try {
            match ($action) {
                SiteAction::List => $this->list($io, $input, $output),
                SiteAction::Add => $this->add($io, $input),
                SiteAction::Remove => $this->remove($io, $input),
                SiteAction::Rename => $this->rename($io, $input),
            };
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Lists the sites with their Joomla root, marks `default_site`, the site selected via `JXT_SITE` and incomplete or
     * contradicting sites, and warns if the selected site cannot be used.
     */
    private function list(SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
    {
        UnusedArguments::reject($this, [$input->getArgument('id'), $input->getArgument('new-id')], 'jxt site list');
        $configuration = $this->configurationLoader->load($this->projectDirectory);
        $sites = $this->configurationLoader->sites();
        $globalFile = $this->configurationLoader->globalFile();
        $defaultSite = $globalFile === null ? null : (new SiteFile($globalFile))->defaultSite();

        if ($sites === []) {
            $io->note('No test site is configured. Add one with jxt site add <id> --default.');

            return;
        }

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Id', 'joomla_root', '']);

        foreach ($sites as $site) {
            $notes = array_filter([
                $site->id === $defaultSite ? 'default_site' : null,
                $site->joomlaRoot === null ? 'joomla_root missing' : null,
                $site->conflict() === null ? null : 'contradicting keys',
                $configuration->siteSelection->isExplicit() && $site->id === $configuration->siteSelection->id
                    ? 'selected via ' . $configuration->siteSelection->source->value
                    : null,
            ]);
            $table->addRow([$site->id, $site->joomlaRoot ?? '–', implode(', ', $notes)]);
        }

        $table->render();
        $siteProblem = $configuration->siteProblem();

        if ($siteProblem !== null) {
            $io->warning($siteProblem);
        }
    }

    /**
     * Adds a site that has only its id, and with `--default` makes it the default site.
     */
    private function add(SymfonyStyle $io, InputInterface $input): void
    {
        UnusedArguments::reject($this, [$input->getArgument('new-id')], 'jxt site add <id>');
        $siteFile = $this->siteFile();
        $id = $this->newId($input, 'id', $siteFile);
        $default = (bool) $input->getOption('default');
        $siteFile->write($siteFile->sites()->withSite($id), $default ? $id : $siteFile->defaultSite());

        $io->success(sprintf(
            'Added site "%s"%s to %s. Set its keys with jxt config set <key> <value> --site=%s, e.g. joomla_root.',
            $id,
            $default ? ' as default site' : '',
            $siteFile->file->path,
            $id,
        ));
    }

    /**
     * Removes a site, and `default_site` if it refers to it.
     */
    private function remove(SymfonyStyle $io, InputInterface $input): void
    {
        UnusedArguments::reject($this, [$input->getArgument('new-id')], 'jxt site remove <id>');
        $siteFile = $this->siteFile();
        $id = $this->existingId($input, $siteFile);
        $defaultSite = $siteFile->defaultSite();
        $siteFile->write($siteFile->sites()->withoutSite($id), $defaultSite === $id ? null : $defaultSite);

        $io->success(sprintf(
            'Removed site "%s" from %s%s.',
            $id,
            $siteFile->file->path,
            $defaultSite === $id ? ', and default_site, which referred to it' : '',
        ));
        $this->noteReferencesElsewhere($io, $id);
    }

    /**
     * Renames a site, and changes `default_site` with it.
     */
    private function rename(SymfonyStyle $io, InputInterface $input): void
    {
        $siteFile = $this->siteFile();
        $id = $this->existingId($input, $siteFile);
        $newId = $this->newId($input, 'new-id', $siteFile);
        $defaultSite = $siteFile->defaultSite();
        $siteFile->write($siteFile->sites()->renamed($id, $newId), $defaultSite === $id ? $newId : $defaultSite);

        $io->success(sprintf(
            'Renamed site "%s" to "%s" in %s%s.',
            $id,
            $newId,
            $siteFile->file->path,
            $defaultSite === $id ? sprintf('; default_site now refers to "%s"', $newId) : '',
        ));
        $this->noteReferencesElsewhere($io, $id);
    }

    /**
     * Points out that references to the old id outside of the global configuration stay unchanged.
     */
    private function noteReferencesElsewhere(SymfonyStyle $io, string $id): void
    {
        $io->note(sprintf(
            'References elsewhere, e.g. %s=%s in an .envrc or --site=%s in a script, are not changed.',
            SiteSelection::ENVIRONMENT_VARIABLE,
            $id,
            $id,
        ));
    }

    /**
     * Returns the id of an existing site from the argument.
     *
     * @throws ConfigurationException if the id is missing or no site has it
     */
    private function existingId(InputInterface $input, SiteFile $siteFile): string
    {
        $id = $this->argument($input, 'id');
        $ids = $siteFile->sites()->ids();

        if (!in_array($id, $ids, true)) {
            throw new ConfigurationException(sprintf(
                'There is no site "%s" in %s.%s',
                $id,
                $siteFile->file->path,
                $ids === [] ? '' : sprintf(' Configured sites: %s.', implode(', ', $ids)),
            ));
        }

        return $id;
    }

    /**
     * Returns a valid id from the argument that no site has yet.
     *
     * @throws ConfigurationException if the id is missing, invalid or already used
     */
    private function newId(InputInterface $input, string $argument, SiteFile $siteFile): string
    {
        $id = $this->argument($input, $argument);

        if (!SiteEntry::isValidId($id)) {
            throw new ConfigurationException(
                sprintf('Invalid site id "%s". It may contain letters, digits, "_", "." and "-".', $id),
            );
        }

        if ($siteFile->sites()->has($id)) {
            throw new ConfigurationException(sprintf('There is already a site "%s" in %s.', $id, $siteFile->file->path));
        }

        return $id;
    }

    /**
     * Returns a required argument.
     *
     * @throws ConfigurationException if it is missing
     */
    private function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!is_string($value) || $value === '') {
            throw new ConfigurationException(
                $name === 'new-id'
                    ? 'Please specify the new id, e.g. jxt site rename local default.'
                    : 'Please specify the id of the site, e.g. jxt site add local.',
            );
        }

        return $value;
    }

    /**
     * Returns the sites of the global configuration for a change; nothing is written under sudo so that the file is not owned by
     * root.
     *
     * @throws ConfigurationException under sudo or without a global configuration
     */
    private function siteFile(): SiteFile
    {
        if ($this->runsViaSudo) {
            throw new ConfigurationException('Please run jxt site without sudo, otherwise the configuration file would be owned by root.');
        }

        return new SiteFile(
            $this->configurationLoader->globalFile() ?? throw new ConfigurationException('No global configuration file is defined.'),
        );
    }
}
