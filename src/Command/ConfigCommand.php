<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationFile;
use SmartA\Jxt\Configuration\ConfigurationKey;
use SmartA\Jxt\Configuration\ConfigurationLoader;
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
#[AsCommand(name: 'config', description: 'Zeigt und ändert die globale und die Projektkonfiguration.')]
final class ConfigCommand extends Command
{
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
            ->addArgument('action', InputArgument::OPTIONAL, 'show, set oder unset', ConfigAction::Show->value)
            ->addArgument('key', InputArgument::OPTIONAL, 'Schlüssel, z. B. joomla_root')
            ->addArgument('value', InputArgument::OPTIONAL, 'Neuer Wert; „null“ setzt einen globalen Wert im Projekt zurück')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Globale statt Projektkonfiguration ändern');
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
            ?? throw new ConfigurationException(sprintf('Unbekannte Aktion „%s“. Erlaubt: show, set, unset.', $name));
    }

    /**
     * Shows all keys with value and origin as well as the paths of the configuration files.
     */
    private function show(SymfonyStyle $io, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Schlüssel', 'Wert', 'Herkunft']);

        foreach ($this->configurationLoader->describe($this->projectDirectory) as $entry) {
            $table->addRow([$entry->key->value, $entry->value ?? '–', $entry->source->value]);
        }

        $table->render();
        $io->newLine();
        $io->writeln(sprintf('global:  %s', $this->describeFile($this->configurationLoader->globalFile())));
        $io->writeln(sprintf('Projekt: %s', $this->describeFile($this->configurationLoader->projectFile($this->projectDirectory))));
    }

    /**
     * Sets a value in the selected configuration file.
     */
    private function set(SymfonyStyle $io, InputInterface $input): void
    {
        $file = $this->targetFile($input);
        $key = $this->key($input, $file);
        $rawValue = $input->getArgument('value');

        if (!is_string($rawValue)) {
            throw new ConfigurationException('Bitte einen Wert angeben, z. B. jxt config set owner www-data.');
        }

        $value = $key->validate($rawValue === 'null' ? null : $rawValue, $file->path);
        $file->write([...$file->read(), $key->value => $value]);

        $io->success(sprintf('%s = %s in %s gespeichert.', $key->value, $value ?? 'null', $file->path));
    }

    /**
     * Removes a key from the selected configuration file.
     */
    private function unset(SymfonyStyle $io, InputInterface $input): void
    {
        $file = $this->targetFile($input);
        $key = $this->key($input, $file);
        $values = $file->read();

        if (!array_key_exists($key->value, $values)) {
            $io->note(sprintf('%s ist in %s nicht gesetzt.', $key->value, $file->path));

            return;
        }

        unset($values[$key->value]);
        $file->write($values);

        $io->success(sprintf('%s aus %s entfernt.', $key->value, $file->path));
    }

    /**
     * Returns the file to change; nothing is written under sudo so that the file is not owned by root.
     */
    private function targetFile(InputInterface $input): ConfigurationFile
    {
        if ($this->runsViaSudo) {
            throw new ConfigurationException('jxt config bitte ohne sudo ausführen, sonst gehört die Konfigurationsdatei root.');
        }

        if ((bool) $input->getOption('global')) {
            return $this->configurationLoader->globalFile()
                ?? throw new ConfigurationException('Es ist keine globale Konfigurationsdatei festgelegt.');
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
            throw new ConfigurationException('Bitte einen Schlüssel angeben, z. B. jxt config set joomla_root /var/www/html.');
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
            default => $file->path . ' (nicht vorhanden)',
        };
    }
}
