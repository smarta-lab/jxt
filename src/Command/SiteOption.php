<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Configuration\SiteSelection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The option `--site` of the commands that use the test site.
 */
final class SiteOption
{
    public const string NAME = 'site';

    /**
     * Adds the option to the command.
     */
    public static function add(Command $command): void
    {
        $command->addOption(self::NAME, null, InputOption::VALUE_REQUIRED, sprintf(
            'Id of the test site in the global configuration, instead of %s or default_site',
            SiteSelection::ENVIRONMENT_VARIABLE,
        ));
    }

    /**
     * Returns the value of the option, or null if it is not given.
     */
    public static function value(InputInterface $input): ?string
    {
        $value = $input->getOption(self::NAME);

        return is_string($value) ? $value : null;
    }

    /**
     * Returns how the site was selected for the output, e.g. ` (site "leaflet" via JXT_SITE)`; empty for
     * `default_site`, where the path says enough.
     */
    public static function describe(Configuration $configuration): string
    {
        $selection = $configuration->siteSelection;

        return $selection->isExplicit() ? sprintf(' (%s)', $selection->describe()) : '';
    }
}
