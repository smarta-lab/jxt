<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * The selected test site: the option `--site` before the environment variable `JXT_SITE` before `default_site` of
 * the global configuration.
 */
final readonly class SiteSelection
{
    public const string ENVIRONMENT_VARIABLE = 'JXT_SITE';

    /**
     * @param string|null $id Id of the selected site; null if none is selected
     */
    public function __construct(
        public ?string $id = null,
        public SiteSelectionSource $source = SiteSelectionSource::None,
    ) {
    }

    /**
     * Selects the site; an empty environment variable counts as unset.
     *
     * @param string|null $option      Value of `--site`; null if it is not given
     * @param string|null $environment Value of `JXT_SITE`; null if it is not set
     * @param string|null $defaultSite Value of `default_site`; null if it is not set
     *
     * @throws ConfigurationException for an empty option
     */
    public static function choose(?string $option, ?string $environment, ?string $defaultSite): self
    {
        return match (true) {
            $option === '' => throw new ConfigurationException('--site needs the id of a site.'),
            $option !== null => new self($option, SiteSelectionSource::Option),
            $environment !== null && $environment !== '' => new self($environment, SiteSelectionSource::Environment),
            $defaultSite !== null => new self($defaultSite, SiteSelectionSource::DefaultSite),
            default => new self(),
        };
    }

    /**
     * Returns the selected site among the configured ones, or null if none is selected or it does not exist.
     *
     * @param list<SiteEntry> $sites
     */
    public function find(array $sites): ?SiteEntry
    {
        foreach ($sites as $site) {
            if ($site->id === $this->id) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Checks whether the site was selected for this call, by `--site` or `JXT_SITE`, not by `default_site`.
     */
    public function isExplicit(): bool
    {
        return in_array($this->source, [SiteSelectionSource::Option, SiteSelectionSource::Environment], true);
    }

    /**
     * Describes the selection for the output, e.g. `site "leaflet" via JXT_SITE` or `site "local" (default_site)`.
     */
    public function describe(): string
    {
        return match ($this->source) {
            SiteSelectionSource::None => 'no site selected',
            SiteSelectionSource::DefaultSite => sprintf('site "%s" (%s)', $this->id, $this->source->value),
            default => sprintf('site "%s" via %s', $this->id, $this->source->value),
        };
    }

    /**
     * Summarizes the selection for `jxt config`, e.g. `leaflet via JXT_SITE` or `local (default_site)`; `–` if no
     * site is selected.
     */
    public function summary(): string
    {
        return match ($this->source) {
            SiteSelectionSource::None => '–',
            SiteSelectionSource::DefaultSite => sprintf('%s (%s)', $this->id, $this->source->value),
            default => sprintf('%s via %s', $this->id, $this->source->value),
        };
    }

    /**
     * Returns why no site can be used: none is selected, or the selected one does not exist.
     *
     * @param list<string> $ids    Ids of the configured sites
     * @param string       $source Path of the global configuration
     */
    public function missingMessage(array $ids, string $source): string
    {
        $available = $ids === [] ? '' : sprintf(' Configured sites: %s.', implode(', ', $ids));

        return match (true) {
            $ids === [] && $this->id === null => sprintf(
                'No test site is configured in %s. Add one with jxt site add <id> --default and set its joomla_root '
                . 'with jxt config set joomla_root <path>.',
                $source,
            ),
            $this->id === null => sprintf(
                'No test site is selected: select one with --site or %s, or set default_site in %s.%s',
                self::ENVIRONMENT_VARIABLE,
                $source,
                $available,
            ),
            default => sprintf(
                'There is no site "%s" (selected via %s) in %s.%s Add it with jxt site add %s.',
                $this->id,
                $this->source->value,
                $source,
                $available,
                $this->id,
            ),
        };
    }
}
