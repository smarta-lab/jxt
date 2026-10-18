<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use UnexpectedValueException;

/**
 * Enables the plugin after installing or updating it: the Joomla installer leaves new plugins disabled, and the
 * Joomla CLI only loads enabled console plugins.
 *
 * The installer caches the list of plugins before this script runs, so with caching enabled the plugin would stay
 * invisible until the cache expires; the script therefore clears the cache of the plugin list.
 */
return new class () implements InstallerScriptInterface, DatabaseAwareInterface {
    use DatabaseAwareTrait;

    private ?CMSApplicationInterface $application = null;

    /**
     * Receives the application from the Joomla installer, for the cache path of the site.
     */
    public function setApplication(CMSApplicationInterface $application): void
    {
        $this->application = $application;
    }

    #[\Override]
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    #[\Override]
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    #[\Override]
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    #[\Override]
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    #[\Override]
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $db = $this->getDatabase();
        $type = 'plugin';
        $folder = 'console';
        $element = 'jxt';
        $query = $db->createQuery()
            ->update($this->quoteName('#__extensions'))
            ->set($this->quoteName('enabled') . ' = 1')
            ->where($this->quoteName('type') . ' = :type')
            ->where($this->quoteName('folder') . ' = :folder')
            ->where($this->quoteName('element') . ' = :element')
            ->bind(':type', $type)
            ->bind(':folder', $folder)
            ->bind(':element', $element);
        $db->setQuery($query)->execute();
        $this->clearPluginCache();

        return true;
    }

    /**
     * Clears the cached list of plugins.
     */
    private function clearPluginCache(): void
    {
        $options = [
            'defaultgroup' => 'com_plugins',
            'cachebase'    => $this->application?->get('cache_path', JPATH_CACHE) ?? JPATH_CACHE,
        ];
        $factory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);

        if ($factory instanceof CacheControllerFactoryInterface) {
            $factory->createCacheController('callback', $options)->clean();
        }
    }

    /**
     * Returns the quoted name of a table or column.
     */
    private function quoteName(string $name): string
    {
        $quoted = $this->getDatabase()->quoteName($name);

        return is_string($quoted) ? $quoted : throw new UnexpectedValueException('Cannot quote ' . $name);
    }
};
