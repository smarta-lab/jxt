<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use SimpleXMLElement;
use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;

/**
 * Reads a Joomla manifest and derives the deployment plan from it.
 *
 * Takes `<files>`, `<scriptfile>`, `<languages>`, `<media>` and the manifest file itself
 * into account. All paths are relative to the extension directory, i.e. the directory of the manifest.
 */
final class ManifestParser
{
    private const string ELEMENT_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/';
    private const string LANGUAGE_TAG_PATTERN = '/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/';
    private const string CMD_FILTER_PATTERN = '/[^A-Z0-9_.-]/i';
    private const array INSTALLER_SECTIONS = ['scriptfile', 'install', 'uninstall', 'update', 'updateservers'];

    /**
     * Reads and parses the manifest file.
     *
     * @throws ManifestException if the file is unreadable or the manifest is invalid
     */
    public function parseFile(string $path): Manifest
    {
        $xml = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($xml === false) {
            throw new ManifestException(sprintf('Manifest %s is not readable.', $path));
        }

        try {
            return $this->parse($xml, basename($path));
        } catch (ManifestException $exception) {
            throw new ManifestException($path . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Parses the content of a manifest.
     *
     * @param string $fileName File name of the manifest
     *
     * @throws ManifestException if the manifest is invalid or has an unsupported type
     */
    public function parse(string $xml, string $fileName): Manifest
    {
        $root = $this->loadXml($xml);
        $type = $this->extensionType($root);
        $client = $this->clientType($root, $type);
        $element = $this->element($root, $type);
        $group = $this->group($root, $type);
        $installDirectory = $type->installDirectory($client, $element, $group);
        $manifestFile = basename(RelativePath::normalize($fileName));

        $entries = [
            new PlanEntry($manifestFile, $installDirectory . '/' . $manifestFile, EntryKind::File),
            ...$this->fileEntries($root, $installDirectory),
            ...$this->scriptfileEntries($root, $installDirectory),
            ...$this->languageEntries($root, $type->languageClient($client)),
            ...$this->mediaEntries($root),
        ];

        return new Manifest(
            $type,
            $client,
            $element,
            $this->version($root),
            $manifestFile,
            new DeploymentPlan(
                $this->withoutDuplicateTargets($entries),
                [$installDirectory, ...array_map($this->mediaDirectory(...), $this->children($root, 'media'))],
                $this->sourceDirectories($root),
            ),
            $this->installerMetadata($root, $installDirectory),
            $group,
        );
    }

    /**
     * Determines the source directories whose content should be fully listed in the manifest.
     *
     * These are the `folder` attributes of `<files>`, `<languages>` and `<media>`, and for language files
     * without `folder` the first path segment (e.g. `language`). Without `folder` on `<files>` or `<media>`
     * this would be the extension directory itself, which may also contain dev files; it is therefore not checked.
     *
     * @return list<string>
     */
    private function sourceDirectories(SimpleXMLElement $root): array
    {
        $directories = [];

        foreach (['files', 'languages', 'media'] as $sectionName) {
            foreach ($this->children($root, $sectionName) as $section) {
                $prefix = $this->sourcePrefix($section);

                if ($prefix !== '') {
                    $directories[] = rtrim($prefix, '/');
                } elseif ($sectionName === 'languages') {
                    $directories = [...$directories, ...$this->languageRootDirectories($section)];
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * Returns the first path segment of all language files of a `<languages>` section without `folder`.
     *
     * @return list<string>
     */
    private function languageRootDirectories(SimpleXMLElement $languages): array
    {
        $directories = [];

        foreach ($this->children($languages, 'language') as $language) {
            $path = RelativePath::normalize((string) $language);

            if (str_contains($path, '/')) {
                $directories[] = explode('/', $path, 2)[0];
            }
        }

        return $directories;
    }

    /**
     * Collects the data that only takes effect when installing via the Joomla installer.
     */
    private function installerMetadata(SimpleXMLElement $root, string $installDirectory): InstallerMetadata
    {
        $namespace = $this->children($root, 'namespace')[0] ?? null;
        $sections = [];

        foreach (self::INSTALLER_SECTIONS as $name) {
            foreach ($this->children($root, $name) as $section) {
                $sections[$name] = ($sections[$name] ?? '') . $this->normalizedXml($section);
            }
        }

        $paths = $root->xpath('scriptfile | install/sql/file | uninstall/sql/file | update/schemas/schemapath') ?: [];
        $targets = array_map(
            static fn (SimpleXMLElement $path): string => $installDirectory . '/' . RelativePath::normalize((string) $path),
            array_values(array_filter($paths, static fn (SimpleXMLElement $path): bool => trim((string) $path) !== '')),
        );

        return new InstallerMetadata(
            $namespace === null ? null : trim((string) $namespace),
            $namespace === null || trim((string) $namespace['path']) === '' ? null : trim((string) $namespace['path']),
            $sections,
            $targets,
            strtolower(trim((string) $root['method'])) === 'upgrade',
        );
    }

    /**
     * Returns the XML of an element without insignificant whitespace, so formatting changes go unnoticed.
     */
    private function normalizedXml(SimpleXMLElement $element): string
    {
        $xml = (string) $element->asXML();

        return trim((string) preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $xml));
    }

    /**
     * Loads the XML and checks the root element.
     */
    private function loadXml(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            $reason = $error === false ? '' : ': ' . trim($error->message);

            throw new ManifestException('The manifest is not valid XML' . $reason);
        }

        if ($root->getName() !== 'extension') {
            throw new ManifestException('The manifest must have the root element <extension>.');
        }

        return $root;
    }

    /**
     * Determines the extension type from the attribute `type`.
     */
    private function extensionType(SimpleXMLElement $root): ExtensionType
    {
        $type = trim((string) $root['type']);

        return ExtensionType::tryFrom($type)
            ?? throw new ManifestException(sprintf('Extension type "%s" is not supported.', $type));
    }

    /**
     * Determines the application area from the attribute `client`; defaults to `site`.
     *
     * The Joomla installer ignores the attribute for plugins, which always belong to the site.
     */
    private function clientType(SimpleXMLElement $root, ExtensionType $type): ClientType
    {
        $client = trim((string) $root['client']);

        if ($client === '' || $type === ExtensionType::Plugin) {
            return ClientType::Site;
        }

        return ClientType::tryFrom($client)
            ?? throw new ManifestException(sprintf('Unknown client "%s" in the manifest.', $client));
    }

    /**
     * Determines the technical name of the extension according to the rules of the Joomla installer.
     *
     * Modules: `<element>` as it is, otherwise the attribute `module` of an entry in the first `<files>`
     * in lower case, without a fallback.
     * Templates: `<element>`, otherwise `<name>`, reduced to the characters of the filter `cmd` and in lower case.
     * Plugins: attribute `plugin` of an entry in the first `<files>` as it is, without a fallback.
     */
    private function element(SimpleXMLElement $root, ExtensionType $type): string
    {
        $name = trim((string) $root->name);

        if ($name === '') {
            throw new ManifestException('The extension name <name> is missing in the manifest.');
        }

        $elementTag = trim((string) $root->element);
        $element = match ($type) {
            ExtensionType::Module => $elementTag !== '' ? $elementTag : strtolower(
                $this->filesAttribute($root, 'module') ?? throw new ManifestException(
                    'The module element (<element> or attribute module of an entry in the first <files>) '
                    . 'is missing in the manifest.',
                ),
            ),
            ExtensionType::Template => $this->templateElement($elementTag !== '' ? $elementTag : $name),
            ExtensionType::Plugin => $this->filesAttribute($root, 'plugin') ?? throw new ManifestException(
                'The plugin element (attribute plugin of an entry in the first <files>) is missing in the manifest.',
            ),
        };

        return $this->validName($element, 'element name');
    }

    /**
     * Applies the filter `cmd` of the Joomla installer and converts the result to lower case.
     */
    private function templateElement(string $name): string
    {
        return strtolower(ltrim((string) preg_replace(self::CMD_FILTER_PATTERN, '', $name), '.'));
    }

    /**
     * Returns the validated plugin group from the attribute `group`, or null for other extension types.
     */
    private function group(SimpleXMLElement $root, ExtensionType $type): ?string
    {
        if ($type !== ExtensionType::Plugin) {
            return null;
        }

        $group = trim((string) $root['group']);

        if ($group === '') {
            throw new ManifestException('The attribute group of <extension> is missing in the plugin manifest.');
        }

        return $this->validName($group, 'plugin group');
    }

    /**
     * Returns the name if it is safe as a directory name.
     *
     * @param string $description Kind of name for the error message
     */
    private function validName(string $name, string $description): string
    {
        if (preg_match(self::ELEMENT_PATTERN, $name) !== 1) {
            throw new ManifestException(sprintf('Invalid %s "%s".', $description, $name));
        }

        return $name;
    }

    /**
     * Returns the value of the first attribute with the given name on an entry inside the first `<files>`.
     *
     * Like the Joomla installer, further `<files>` sections are not considered.
     */
    private function filesAttribute(SimpleXMLElement $root, string $attribute): ?string
    {
        foreach (($this->children($root, 'files')[0] ?? null)?->children() ?? [] as $child) {
            $value = trim((string) $child[$attribute]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the content of `<version>` or null if it is missing.
     */
    private function version(SimpleXMLElement $root): ?string
    {
        $version = trim((string) $root->version);

        return $version === '' ? null : $version;
    }

    /**
     * Creates the entries for all `<files>` sections.
     *
     * @return list<PlanEntry>
     */
    private function fileEntries(SimpleXMLElement $root, string $installDirectory): array
    {
        $entries = [];

        foreach ($this->children($root, 'files') as $files) {
            $entries = [...$entries, ...$this->sectionEntries($files, $installDirectory)];
        }

        return $entries;
    }

    /**
     * Creates the entry for `<scriptfile>`, which Joomla copies into the install directory.
     *
     * @return list<PlanEntry>
     */
    private function scriptfileEntries(SimpleXMLElement $root, string $installDirectory): array
    {
        if (trim((string) $root->scriptfile) === '') {
            return [];
        }

        $path = RelativePath::normalize((string) $root->scriptfile);

        return [new PlanEntry($path, $installDirectory . '/' . $path, EntryKind::File)];
    }

    /**
     * Creates the entries for the language files in `<languages>`.
     *
     * The target is the shared language directory of the application area, e.g. `language/de-DE/`; the
     * attribute `client` of a `<language>` entry overrides the default area of the extension.
     *
     * @return list<PlanEntry>
     */
    private function languageEntries(SimpleXMLElement $root, ClientType $defaultClient): array
    {
        $entries = [];

        foreach ($this->children($root, 'languages') as $languages) {
            $sourcePrefix = $this->sourcePrefix($languages);

            foreach ($this->children($languages, 'language') as $language) {
                $path = RelativePath::normalize((string) $language);
                $client = $this->languageClient($language) ?? $defaultClient;
                $target = $client->pathPrefix() . 'language/' . $this->languageTag($language) . '/' . basename($path);
                $entries[] = new PlanEntry($sourcePrefix . $path, $target, EntryKind::File);
            }
        }

        return $entries;
    }

    /**
     * Returns the application area from the attribute `client` of a `<language>` entry, or null if it is missing.
     */
    private function languageClient(SimpleXMLElement $language): ?ClientType
    {
        $client = trim((string) $language['client']);

        if ($client === '') {
            return null;
        }

        return ClientType::tryFrom($client)
            ?? throw new ManifestException(sprintf('Unknown client "%s" of a language file in the manifest.', $client));
    }

    /**
     * Returns the validated attribute `tag` of a `<language>` entry.
     *
     * The Joomla installer copies entries without `tag` to the path given in the entry below `language/`;
     * jxt does not support this, since the jxt bridge only writes to `language/<tag>/`.
     */
    private function languageTag(SimpleXMLElement $language): string
    {
        $tag = trim((string) $language['tag']);

        if ($tag === '') {
            throw new ManifestException(sprintf(
                'Language file %s without attribute tag is not supported; please add the language tag, '
                . 'e.g. <language tag="en-GB">.',
                trim((string) $language),
            ));
        }

        if (preg_match(self::LANGUAGE_TAG_PATTERN, $tag) !== 1) {
            throw new ManifestException(sprintf('Invalid language tag "%s" in the manifest.', $tag));
        }

        return $tag;
    }

    /**
     * Creates the entries for all `<media>` sections.
     *
     * @return list<PlanEntry>
     */
    private function mediaEntries(SimpleXMLElement $root): array
    {
        $entries = [];

        foreach ($this->children($root, 'media') as $media) {
            $entries = [...$entries, ...$this->sectionEntries($media, $this->mediaDirectory($media))];
        }

        return $entries;
    }

    /**
     * Returns the target directory of a `<media>` section from the attribute `destination`.
     */
    private function mediaDirectory(SimpleXMLElement $media): string
    {
        if (trim((string) $media['destination']) === '') {
            throw new ManifestException('The attribute destination of <media> is missing in the manifest.');
        }

        return 'media/' . RelativePath::normalize((string) $media['destination']);
    }

    /**
     * Creates the entries for the children of a section.
     *
     * Like the Joomla installer, every child except `<folder>` counts as a file, usually `<filename>`, but
     * also e.g. `<file>`.
     *
     * @return list<PlanEntry>
     */
    private function sectionEntries(SimpleXMLElement $section, string $targetDirectory): array
    {
        $sourcePrefix = $this->sourcePrefix($section);
        $entries = [];

        foreach ($section->children() as $child) {
            $kind = $child->getName() === 'folder' ? EntryKind::Directory : EntryKind::File;
            $path = RelativePath::normalize((string) $child);
            $entries[] = new PlanEntry($sourcePrefix . $path, $targetDirectory . '/' . $path, $kind);
        }

        return $entries;
    }

    /**
     * Returns the source directory from the attribute `folder` of a section, with a trailing `/`.
     */
    private function sourcePrefix(SimpleXMLElement $section): string
    {
        $folder = (string) $section['folder'];

        return trim($folder) === '' ? '' : RelativePath::normalize($folder) . '/';
    }

    /**
     * Returns all direct child elements with the given name.
     *
     * @return list<SimpleXMLElement>
     */
    private function children(SimpleXMLElement $parent, string $name): array
    {
        $children = [];

        foreach ($parent->children() as $child) {
            if ($child->getName() === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Removes entries whose target already exists, e.g. a manifest listed again in `<files>`.
     *
     * @param list<PlanEntry> $entries
     *
     * @return list<PlanEntry>
     */
    private function withoutDuplicateTargets(array $entries): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            $unique[$entry->target] ??= $entry;
        }

        return array_values($unique);
    }
}
