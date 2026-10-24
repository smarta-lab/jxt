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
                . 'comma-separated; cli_command takes its arguments as they are, after "--" if one starts with "-"',
            )
            ->addOption('global', null, InputOption::VALUE_NONE, 'Change the global instead of the project configuration');
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
                ConfigAction::Show => $this->show($io, $output),
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
     * Shows all keys with value and origin as well as the paths of the configuration files.
     */
    private function show(SymfonyStyle $io, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Key', 'Value', 'Origin']);

        $entries = $this->configurationLoader->describe($this->projectDirectory);

        foreach ($entries as $entry) {
            $value = $entry->value === null || $entry->value === [] ? '–' : $this->format($entry->key, $entry->value);
            $table->addRow([$entry->key->value, $value, $entry->source->value]);
        }

        $table->render();
        $io->newLine();
        $siteConflict = $this->siteConflict();
        $io->writeln(sprintf('global:  %s', $this->describeFile($this->configurationLoader->globalFile())));
        $io->writeln(sprintf('project: %s', $this->describeFile($this->configurationLoader->projectFile($this->projectDirectory))));

        if ($siteConflict !== null) {
            $io->warning($siteConflict . self::SITE_CONFLICT_CONSEQUENCE);
        }
    }

    /**
     * Sets a value in the selected configuration file.
     */
    private function set(SymfonyStyle $io, InputInterface $input): void
    {
        $file = $this->targetFile($input);
        $key = $this->key($input, $file);
        $arguments = $this->valueArguments($input);
        $value = $key->validate(match (true) {
            $key->isCommand() => $arguments === ['null'] ? null : $arguments,
            $key->isList() => $this->listValue($arguments),
            default => $this->singleValue($key, $arguments),
        }, $file->path);
        $file->write([...$file->read(), $key->value => $value]);

        $io->success(sprintf('Saved %s = %s in %s.', $key->value, $this->format($key, $value), $file->path));
        $this->warnAboutSiteConflict($io);
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
     * Formats a value for the output; commands as command line, other lists comma-separated.
     *
     * @param string|list<string>|null $value
     */
    private function format(ConfigurationKey $key, string|array|null $value): string
    {
        return match (true) {
            !is_array($value) => $value ?? 'null',
            $key->isCommand() => CommandLine::format($value),
            default => implode(', ', $value),
        };
    }

    /**
     * Removes a key from the selected configuration file, and the file if no key is left.
     */
    private function unset(SymfonyStyle $io, InputInterface $input): void
    {
        $file = $this->targetFile($input);
        $key = $this->key($input, $file);
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
        $this->warnAboutSiteConflict($io);
    }

    /**
     * Warns if the keys of the test site contradict each other after a change.
     *
     * Only a warning, so that a configuration can be changed in several calls, e.g. setting cli_command before
     * removing web_user.
     */
    private function warnAboutSiteConflict(SymfonyStyle $io): void
    {
        $siteConflict = $this->siteConflict();

        if ($siteConflict !== null) {
            $io->warning($siteConflict . self::SITE_CONFLICT_CONSEQUENCE);
        }
    }

    /**
     * Returns why the keys of the test site in the merged configuration contradict each other, or null.
     */
    private function siteConflict(): ?string
    {
        return $this->configurationLoader->load($this->projectDirectory)->siteConflict;
    }

    /**
     * Returns the file to change; nothing is written under sudo so that the file is not owned by root.
     */
    private function targetFile(InputInterface $input): ConfigurationFile
    {
        if ($this->runsViaSudo) {
            throw new ConfigurationException('Please run jxt config without sudo, otherwise the configuration file would be owned by root.');
        }

        if ((bool) $input->getOption('global')) {
            return $this->configurationLoader->globalFile()
                ?? throw new ConfigurationException('No global configuration file is defined.');
        }

        return $this->configurationLoader->projectFile($this->projectDirectory);
    }

    /**
     * Returns the validated key from the arguments.
     */
    private function key(InputInterface $input, ConfigurationFile $file): ConfigurationKey
    {
        $name = $input->getArgument('key');

        if (!is_string($name)) {
            throw new ConfigurationException('Please specify a key, e.g. jxt config set joomla_root /var/www/html.');
        }

        return ConfigurationKey::fromName($name, $file->path);
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
