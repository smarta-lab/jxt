<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Console;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use SmartA\Plugin\Console\Jxt\Plan\ExtensionIdentity;
use UnexpectedValueException;

/**
 * Looks up the ID of an extension in `#__extensions`.
 */
final readonly class ExtensionFinder
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Returns the extension ID, or null if the extension is not registered.
     */
    public function find(ExtensionIdentity $extension): ?int
    {
        $db = $this->db;
        $type = $extension->type;
        $element = $extension->element;
        $folder = $extension->folder;
        $clientId = $extension->clientId;
        $query = $db->createQuery()
            ->select($this->quoteName('extension_id'))
            ->from($this->quoteName('#__extensions'))
            ->where($this->quoteName('type') . ' = :type')
            ->where($this->quoteName('element') . ' = :element')
            ->where($this->quoteName('folder') . ' = :folder')
            ->where($this->quoteName('client_id') . ' = :client_id')
            ->bind(':type', $type)
            ->bind(':element', $element)
            ->bind(':folder', $folder)
            ->bind(':client_id', $clientId, ParameterType::INTEGER);
        $result = $db->setQuery($query)->loadResult();
        $id = is_numeric($result) ? (int) $result : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Returns the quoted name of a table or column.
     */
    private function quoteName(string $name): string
    {
        $quoted = $this->db->quoteName($name);

        return is_string($quoted) ? $quoted : throw new UnexpectedValueException('Cannot quote ' . $name);
    }
}
