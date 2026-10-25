<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationFile;
use SmartA\Jxt\Configuration\ConfigurationKey;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Configuration\KeyScope;
use SmartA\Jxt\Configuration\PrepareStep;
use SmartA\Jxt\Configuration\SiteEntry;
use SmartA\Jxt\Configuration\SiteFile;
use SmartA\Jxt\Configuration\SiteSelection;
use SmartA\Jxt\Install\CommandLine;
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
 * Command `jxt config`: shows the configuration and changes the global or the project configuration.
 *
 * The keys of a test site always change the selected site in the global configuration (`--site`, `JXT_SITE` or
 * `default_site`); the site must exist, since sites are only added, removed and renamed with `jxt site`.
 *
 * @phpstan-import-type ConfigurationValue from ConfigurationKey
 */
#[AsCommand(name: 'config', description: 'Shows and changes the global and the project configuration.')]
final class ConfigCommand extends Command
{
    private const string SITE_CONFLICT_CONSEQUENCE = ' jxt install, jxt bridge and jxt sync via the bridge fail until '
        . 'this is resolved.';

    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
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
            ->addArgument('action', InputArgument::OPTIONAL, 'show, set or unset', ConfigAction::Show->value)
            ->addArgument('key', InputArgument::OPTIONAL, 'Key, e.g. joomla_root')
            ->addArgument(
                'value',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'New value; "null" overrides a global value with null in the project; lists take several values, also '
                . 'comma-separated; cli_command and prepare (one step) take their arguments as they are, after "--" if '
                . 'one starts with "-"',
            )
            ->addOption('global', null, InputOption::VALUE_NONE, 'Change the global instead of the project configuration');
        SiteOption::add($this);
    }

    /**
     * Runs the selected action and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            match ($this->action($input)) {
                ConfigAction::Show => $this->show($io, $input, $output),
                ConfigAction::Set => $this->set($io, $input),
                ConfigAction::Unset => $this->unset($io, $input),
            };
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Returns the validated action from the arguments.
     */
    private function action(InputInterface $input): ConfigAction
    {
        $name = $input->getArgument('action');
        $name = is_string($name) ? $name : '';

        return ConfigAction::tryFrom($name)
            ?? throw new ConfigurationException(sprintf('Unknown action "%s". Allowed: show, set, unset.', $name));
    }

    /**
     * Shows all keys with value and origin, the selected test site, the configured sites and the paths of the
     * configuration files.
     */
    private function show(SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Key', 'Value', 'Origin']);

        foreach ($this->configurationLoader->describe($this->projectDirectory) as $entry) {
            $table->addRow([$entry->key->value, $this->formatOrDash($entry->key, $entry->value), $entry->source->value]);
        }

        $table->render();
        $configuration = $this->configurationLoader->load($this->projectDirectory, SiteOption::value($input));
        $site = $configuration->site;
        $io->newLine();
        $io->writeln(sprintf('Test site: %s', $configuration->siteSelection->describe()));

        if ($site !== null) {
            $siteTable = new Table($output);
            $siteTable->setStyle('compact');

            foreach (SiteEntry::keys() as $key) {
                $siteTable->addRow([$key->value, $this->formatOrDash($key, $this->siteValue($site, $key))]);
            }

            $siteTable->render();
        }

        $sites = $this->configurationLoader->sites();
        $io->newLine();
        $io->writeln(sprintf('sites:   %s', $sites === [] ? '–' : implode(', ', array_map(
            static fn (SiteEntry $entry): string => $entry->id,
            $sites,
        ))));
        $io->writeln(sprintf('global:  %s', $this->describeFile($this->configurationLoader->globalFile())));
        $io->writeln(sprintf('project: %s', $this->describeFile($this->configurationLoader->projectFile($this->projectDirectory))));
        $siteProblem = $configuration->siteProblem();

        if ($siteProblem !== null) {
            $io->warning($siteProblem . self::SITE_CONFLICT_CONSEQUENCE);
        }
    }

    /**
     * Returns the value of a key of the site.
     *
     * @return ConfigurationValue
     */
    private function siteValue(SiteEntry $site, ConfigurationKey $key): string|array|null
    {
        return match ($key) {
            ConfigurationKey::JoomlaRoot => $site->joomlaRoot,
            ConfigurationKey::WebUser => $site->webUser,
            ConfigurationKey::CliCommand => $site->cliCommand,
            ConfigurationKey::CliRoot => $site->cliRoot,
            default => null,
        };
    }

    /**
     * Sets a value in the selected configuration file.
     */
    private function set(SymfonyStyle $io, InputInterface $input): void
    {
        $key = $this->key($input);
        $file = $this->targetFile($input, $key);
        $arguments = $this->valueArguments($input);
        $value = $key->validate(match (true) {
            $key === ConfigurationKey::Prepare => $arguments === ['null'] ? null : [['run' => $arguments]],
            $key->isCommand() => $arguments === ['null'] ? null : $arguments,
            $key->isList() => $this->listValue($arguments),
            default => $this->singleValue($key, $arguments),
        }, $file->path);

        if ($key->scope() === KeyScope::Site) {
            $siteFile = new SiteFile($file);
            [$id, $selection] = $this->existingSite($input, $siteFile);
            $siteFile->write($siteFile->sites()->with($id, $key, $value), $siteFile->defaultSite());
            $io->success(sprintf(
                'Saved %s = %s in %s of %s.',
                $key->value,
                $this->format($key, $value),
                $selection->describe(),
                $file->path,
            ));
        } else {
            $this->assertSiteExists($key, $value, $file);
            $file->write([...$file->read(), $key->value => $value]);
            $io->success(sprintf('Saved %s = %s in %s.', $key->value, $this->format($key, $value), $file->path));
        }

        $this->warnAboutSiteConflict($io, $input);
    }

    /**
     * Returns the id of the selected site and the selection, after checking that the site exists.
     *
     * @return array{string, SiteSelection}
     *
     * @throws ConfigurationException if no site is selected or the selected one does not exist
     */
    private function existingSite(InputInterface $input, SiteFile $siteFile): array
    {
        $selection = $this->configurationLoader->selection(SiteOption::value($input));
        $sites = $siteFile->sites();

        if ($selection->id === null || !$sites->has($selection->id)) {
            throw new ConfigurationException($selection->missingMessage($sites->ids(), $siteFile->file->path));
        }

        return [$selection->id, $selection];
    }

    /**
     * Rejects a `default_site` that names no configured site, so that a typo does not go unnoticed.
     *
     * @param ConfigurationValue $value
     *
     * @throws ConfigurationException if the site does not exist
     */
    private function assertSiteExists(ConfigurationKey $key, string|array|null $value, ConfigurationFile $file): void
    {
        $ids = (new SiteFile($file))->sites()->ids();

        if ($key === ConfigurationKey::DefaultSite && is_string($value) && !in_array($value, $ids, true)) {
            throw new ConfigurationException(sprintf(
                'There is no site "%s" in %s.%s Add it with jxt site add %s.',
                $value,
                $file->path,
                $ids === [] ? '' : sprintf(' Configured sites: %s.', implode(', ', $ids)),
                $value,
            ));
        }
    }

    /**
     * Returns the value arguments; at least one is required.
     *
     * @return non-empty-list<string>
     */
    private function valueArguments(InputInterface $input): array
    {
        $arguments = array_values(array_filter((array) $input->getArgument('value'), is_string(...)));

        if ($arguments === []) {
            throw new ConfigurationException('Please specify a value, e.g. jxt config set joomla_root /var/www/html.');
        }

        return $arguments;
    }

    /**
     * Returns the value of a single-value key; "null" stands for null.
     *
     * @param non-empty-list<string> $arguments
     */
    private function singleValue(ConfigurationKey $key, array $arguments): ?string
    {
        if (count($arguments) > 1) {
            throw new ConfigurationException(sprintf('%s takes exactly one value.', $key->value));
        }

        return $arguments[0] === 'null' ? null : $arguments[0];
    }

    /**
     * Returns the entries of a list key from all arguments, split at commas.
     *
     * @param non-empty-list<string> $arguments
     *
     * @return list<string>
     */
    private function listValue(array $arguments): array
    {
        $entries = array_map(trim(...), explode(',', implode(',', $arguments)));
        $entries = array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));

        if ($entries === []) {
            throw new ConfigurationException('Please specify a value, e.g. jxt config set check_exclude manifest.');
        }

        return $entries;
    }

    /**
     * Formats a value for the output, or `–` if it is not set.
     *
     * @param ConfigurationValue $value
     */
    private function formatOrDash(ConfigurationKey $key, string|array|null $value): string
    {
        return $value === null || $value === [] ? '–' : $this->format($key, $value);
    }

    /**
     * Formats a value for the output; commands as command line, steps of `prepare` as command lines separated by
     * `; `, other lists comma-separated.
     *
     * @param ConfigurationValue $value
     */
    private function format(ConfigurationKey $key, string|array|null $value): string
    {
        if (!is_array($value)) {
            return $value ?? 'null';
        }

        if ($key === ConfigurationKey::Prepare) {
            return implode('; ', array_map($this->formatStep(...), array_keys($value), $value));
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $key->isCommand() ? CommandLine::format($strings) : implode(', ', $strings);
    }

    /**
     * Formats a step of `prepare` as command line, with its replacement for `jxt sync` if it has one.
     *
     * @param int $index Position of the step, starting at 0
     */
    private function formatStep(int $index, mixed $value): string
    {
        $step = PrepareStep::fromValue($value, ConfigurationKey::Prepare->stepLabel($index, ConfigurationLoader::PROJECT_FILE));

        return CommandLine::format($step->run) . match ($step->sync) {
            null => '',
            false => ' (sync: skipped)',
            default => sprintf(' (sync: %s)', CommandLine::format($step->sync)),
        };
    }

    /**
     * Removes a key from the selected configuration file, and the file if no key is left.
     */
    private function unset(SymfonyStyle $io, InputInterface $input): void
    {
        $key = $this->key($input);
        $file = $this->targetFile($input, $key);

        if ($key->scope() === KeyScope::Site) {
            $this->unsetSiteKey($io, $input, $file, $key);

            return;
        }

        $values = $file->read();

        if (!array_key_exists($key->value, $values)) {
            $io->note(sprintf('%s is not set in %s.', $key->value, $file->path));

            return;
        }

        unset($values[$key->value]);
        $file->write($values);

        $io->success(sprintf(
            $values === [] ? 'Removed %s and the now empty file %s.' : 'Removed %s from %s.',
            $key->value,
            $file->path,
        ));
        $this->warnAboutSiteConflict($io, $input);
    }

    /**
     * Removes a key from the selected site; the site itself stays, even without keys.
     */
    private function unsetSiteKey(SymfonyStyle $io, InputInterface $input, ConfigurationFile $file, ConfigurationKey $key): void
    {
        $siteFile = new SiteFile($file);
        [$id, $selection] = $this->existingSite($input, $siteFile);
        $sites = $siteFile->sites();

        if (!$sites->hasKey($id, $key)) {
            $io->note(sprintf('%s is not set in %s of %s.', $key->value, $selection->describe(), $file->path));

            return;
        }

        $siteFile->write($sites->without($id, $key), $siteFile->defaultSite());
        $io->success(sprintf('Removed %s from %s of %s.', $key->value, $selection->describe(), $file->path));
        $this->warnAboutSiteConflict($io, $input);
    }

    /**
     * Warns if the keys of the selected test site contradict each other after a change.
     *
     * Only a warning, so that a site can be changed in several calls, e.g. setting cli_command before removing
     * web_user.
     */
    private function warnAboutSiteConflict(SymfonyStyle $io, InputInterface $input): void
    {
        $siteConflict = $this->configurationLoader->load($this->projectDirectory, SiteOption::value($input))->site?->conflict();

        if ($siteConflict !== null) {
            $io->warning($siteConflict . self::SITE_CONFLICT_CONSEQUENCE);
        }
    }

    /**
     * Returns the file to change: the global configuration for `--global`, the keys of a site and `default_site`,
     * otherwise the project configuration; nothing is written under sudo so that the file is not owned by root.
     *
     * @throws ConfigurationException under sudo, for `sites` itself, or for `--site` with a key outside of a site
     */
    private function targetFile(InputInterface $input, ConfigurationKey $key): ConfigurationFile
    {
        if ($this->runsViaSudo) {
            throw new ConfigurationException('Please run jxt config without sudo, otherwise the configuration file would be owned by root.');
        }

        if ($key === ConfigurationKey::Sites) {
            throw new ConfigurationException(
                'sites is changed with jxt site and the keys of a site, e.g. jxt config set joomla_root /var/www/html --site=<id>.',
            );
        }

        if ($key->scope() !== KeyScope::Site && SiteOption::value($input) !== null) {
            throw new ConfigurationException(sprintf('--site only applies to the keys of a site, not to %s.', $key->value));
        }

        if ((bool) $input->getOption('global') || in_array($key->scope(), [KeyScope::Site, KeyScope::Global], true)) {
            return $this->configurationLoader->globalFile()
                ?? throw new ConfigurationException('No global configuration file is defined.');
        }

        return $this->configurationLoader->projectFile($this->projectDirectory);
    }

    /**
     * Returns the validated key from the arguments.
     */
    private function key(InputInterface $input): ConfigurationKey
    {
        $name = $input->getArgument('key');

        if (!is_string($name)) {
            throw new ConfigurationException('Please specify a key, e.g. jxt config set joomla_root /var/www/html.');
        }

        if ($name === SiteEntry::ID_KEY) {
            throw new ConfigurationException('The id of a site is changed with jxt site rename <id> <new id>.');
        }

        return ConfigurationKey::fromName($name, 'the arguments');
    }

    /**
     * Returns the path of a configuration file with a note on whether it exists.
     */
    private function describeFile(?ConfigurationFile $file): string
    {
        return match (true) {
            $file === null => '–',
            is_file($file->path) => $file->path,
            default => $file->path . ' (does not exist)',
        };
    }
}
