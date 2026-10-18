<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\LanguageChecker;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for comparing language files and the language constants used in the code.
 */
final class LanguageCheckerTest extends TestCase
{
    use CheckProjectTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject(str_replace(
            '<language tag="de-DE">language/de-DE/mod_example.ini</language>',
            '<language tag="de-DE">language/de-DE/mod_example.ini</language>'
                . '<language tag="en-GB">language/en-GB/mod_example.sys.ini</language>'
                . '<language tag="de-DE">language/de-DE/mod_example.sys.ini</language>',
            self::MANIFEST,
        ));
        $this->writeFile('language/en-GB/mod_example.ini', "MOD_EXAMPLE_TITLE=\"Title\"\nMOD_EXAMPLE_TEXT=\"Text\"\n");
        $this->writeFile('language/de-DE/mod_example.ini', "MOD_EXAMPLE_TITLE=\"Titel\"\nMOD_EXAMPLE_TEXT=\"Text\"\n");
        $this->writeFile('language/en-GB/mod_example.sys.ini', "MOD_EXAMPLE=\"Example\"\nMOD_EXAMPLE_LAYOUT_DEFAULT=\"Default\"\n");
        $this->writeFile('language/de-DE/mod_example.sys.ini', "MOD_EXAMPLE=\"Beispiel\"\nMOD_EXAMPLE_LAYOUT_DEFAULT=\"Standard\"\n");
        $this->writeFile('tmpl/default.php', "<?php\necho Text::_('MOD_EXAMPLE_TITLE'), Text::_('MOD_EXAMPLE_TEXT');\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testConsistentFilesHaveNoFindings(): void
    {
        self::assertSame([], $this->findings(new LanguageChecker()));
    }

    public function testReportsMissingFilesAndKeys(): void
    {
        unlink($this->temporaryDirectory . '/language/de-DE/mod_example.sys.ini');
        $this->writeFile('language/de-DE/mod_example.ini', "MOD_EXAMPLE_TITLE=\"Titel\"\n");

        self::assertSame([
            'ERROR language/keys: de-DE/mod_example.ini lacks MOD_EXAMPLE_TEXT',
            'ERROR language/files: de-DE/mod_example.sys.ini is missing',
        ], $this->findings(new LanguageChecker()));
    }

    public function testReportsUndefinedAndUnusedConstants(): void
    {
        $this->writeFile('media/js/site.js', "Joomla.Text._('MOD_EXAMPLE_MISSING');\n");
        $this->writeFile('tmpl/default.php', "<?php\necho Text::_('MOD_EXAMPLE_TITLE');\n");

        self::assertSame([
            'ERROR language/undefined: MOD_EXAMPLE_MISSING is used in media/js/site.js but not defined',
            'INFO language/unused: MOD_EXAMPLE_TEXT is defined but not found in the code (may be built at runtime)',
        ], $this->findings(new LanguageChecker()));
    }

    public function testReportsUnparsableFiles(): void
    {
        $this->writeFile('language/de-DE/mod_example.sys.ini', "MOD_EXAMPLE=\"open\nMOD_EXAMPLE_LAYOUT_DEFAULT=\"Standard\"\n[broken\n");

        self::assertStringStartsWith(
            'ERROR language/parse: language/de-DE/mod_example.sys.ini: cannot be parsed',
            $this->findings(new LanguageChecker())[0],
        );
    }

    public function testTemplatePrefixAndPositions(): void
    {
        $this->writeFile('templateDetails.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="template" client="site">
                <name>Example</name>
                <version>1.0.0</version>
                <files><filename>index.php</filename></files>
                <languages><language tag="en-GB">language/en-GB/tpl_example.ini</language></languages>
                <positions><position>top</position></positions>
            </extension>
            XML);
        $this->writeFile('index.php', "<?php echo Text::_('TPL_EXAMPLE_COLOR');\n");
        $this->writeFile(
            'language/en-GB/tpl_example.ini',
            "TPL_EXAMPLE=\"Example\"\nTPL_EXAMPLE_COLOR=\"Color\"\nTPL_EXAMPLE_POSITION_TOP=\"Top\"\n",
        );

        self::assertSame([], $this->findings(new LanguageChecker(), $this->context(manifest: 'templateDetails.xml')));
    }
}
