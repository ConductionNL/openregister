<?php

/**
 * DocxHyperlinkRedactionTest
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use ZipArchive;

/**
 * Covers redaction of entity text carried by docx hyperlinks.
 *
 * Found in live testing: an email address in a Word hyperlink survived
 * anonymisation. It lives in two places, neither reachable by a text walk:
 *
 * - `word/_rels/document.xml.rels` holds the `mailto:` target. When the link's
 *   display text is a NAME, the address is ONLY there — invisible in the
 *   document and absent from `document.xml` entirely.
 * - the display text belongs to `PhpWord\Element\Link`, which has `getText()`
 *   but no `setText()` and no `setSource()`, so PhpWord cannot rewrite either
 *   half.
 *
 * Pre-existing rather than a planner regression: the previous per-element loop
 * used the same `getText` + `setText` test and skipped links identically.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class DocxHyperlinkRedactionTest extends TestCase
{

    /**
     * Temp files to clean up.
     *
     * @var array<int, string>
     */
    private array $temporary = [];


    /**
     * Remove generated files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path) === true) {
                unlink($path);
            }
        }

        $this->temporary = [];
        parent::tearDown();
    }//end tearDown()


    /**
     * Build a docx containing hyperlinks and run hyperlink redaction over it.
     *
     * @param array<int, array<int, string>> $links        Pairs of [target, displayText].
     * @param array<string, string>          $replacements Needle => placeholder.
     * @param array<string, string>          $types        Needle => raw entity type.
     *
     * @return string Path to the redacted docx.
     */
    private function redactLinks(array $links, array $replacements, array $types): string
    {
        $word    = new PhpWord();
        $section = $word->addSection();
        $section->addText('Contactpersoon hieronder.');
        foreach ($links as [$target, $display]) {
            $section->addLink($target, $display);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'or_linktest_');
        $this->temporary[] = $path;
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $handler = new DocumentProcessingHandler(
            rootFolder: $this->createMock(IRootFolder::class),
            userSession: $this->createMock(IUserSession::class),
            logger: $this->createMock(LoggerInterface::class),
            entityRelationMapper: $this->createMock(EntityRelationMapper::class),
            l10n: $this->createMock(IL10N::class)
        );

        $typeMap = new ReflectionProperty(DocumentProcessingHandler::class, 'entityTypesByNeedle');
        $typeMap->setAccessible(true);
        $typeMap->setValue($handler, $types);

        $method = new ReflectionMethod(DocumentProcessingHandler::class, 'redactHyperlinksInDocx');
        $method->setAccessible(true);
        $method->invoke($handler, $path, $replacements);

        return $path;
    }//end redactLinks()


    /**
     * Every XML/rels part of a docx, concatenated.
     *
     * @param string $path Path to the docx.
     *
     * @return string
     */
    private function allParts(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'output docx must be readable');

        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '.xml') === true || str_ends_with($name, '.rels') === true) {
                $all .= (string) $zip->getFromName($name);
            }
        }

        $zip->close();

        return $all;
    }//end allParts()


    /**
     * One named part of a docx.
     *
     * @param string $path Path to the docx.
     * @param string $part Part name.
     *
     * @return string
     */
    private function part(string $path, string $part): string
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName($part);
        $zip->close();

        return $xml;
    }//end part()


    /**
     * THE REPORTED BUG: the address is only in the relationship target, invisible
     * in the document, and must not survive anywhere.
     *
     * @return void
     */
    public function testAddressHiddenInTheLinkTargetIsRemoved(): void
    {
        $path = $this->redactLinks(
            links: [['mailto:jan.jansen@example.nl', 'Jan Jansen']],
            replacements: ['jan.jansen@example.nl' => '[EMAIL: 1]', 'Jan Jansen' => '[PERSOON: 3]'],
            types: ['jan.jansen@example.nl' => 'EMAIL', 'Jan Jansen' => 'PERSON']
        );

        $this->assertStringNotContainsString('jan.jansen@example.nl', $this->allParts($path));
    }//end testAddressHiddenInTheLinkTargetIsRemoved()


    /**
     * The link's DISPLAY text is redacted too — it was equally unreachable,
     * because Element\Link has no setText().
     *
     * @return void
     */
    public function testLinkDisplayTextIsRedacted(): void
    {
        $path = $this->redactLinks(
            links: [['mailto:jan.jansen@example.nl', 'Jan Jansen']],
            replacements: ['jan.jansen@example.nl' => '[EMAIL: 1]', 'Jan Jansen' => '[PERSOON: 3]'],
            types: ['jan.jansen@example.nl' => 'EMAIL', 'Jan Jansen' => 'PERSON']
        );

        $document = $this->part($path, 'word/document.xml');

        $this->assertStringContainsString('[PERSOON: 3]', $document);
        $this->assertStringNotContainsString('Jan Jansen', $document);
    }//end testLinkDisplayTextIsRedacted()


    /**
     * A compromised hyperlink is UNWRAPPED — the clickable link is gone, its text
     * remains. Rewriting the target to `mailto:[PERSOON: 1]` would leave a
     * malformed address masquerading as real.
     *
     * @return void
     */
    public function testCompromisedHyperlinkIsUnwrappedAndTargetNeutralised(): void
    {
        $path = $this->redactLinks(
            links: [['mailto:sanne.smit@example.org', 'sanne.smit@example.org']],
            replacements: ['sanne.smit@example.org' => '[EMAIL: 2]'],
            types: ['sanne.smit@example.org' => 'EMAIL']
        );

        $this->assertStringNotContainsString('<w:hyperlink', $this->part($path, 'word/document.xml'));
        $this->assertStringContainsString('[EMAIL: 2]', $this->part($path, 'word/document.xml'));
        $this->assertStringContainsString('about:blank', $this->part($path, 'word/_rels/document.xml.rels'));
        $this->assertStringNotContainsString('sanne.smit@example.org', $this->allParts($path));
    }//end testCompromisedHyperlinkIsUnwrappedAndTargetNeutralised()


    /**
     * A hyperlink carrying NO entity text keeps its link intact. Anonymisation
     * must not strip unrelated links from the document.
     *
     * @return void
     */
    public function testCleanHyperlinkIsLeftIntact(): void
    {
        $path = $this->redactLinks(
            links: [['https://www.nextcloud.com/', 'Nextcloud']],
            replacements: ['jan.jansen@example.nl' => '[EMAIL: 1]'],
            types: ['jan.jansen@example.nl' => 'EMAIL']
        );

        $document = $this->part($path, 'word/document.xml');

        $this->assertStringContainsString('<w:hyperlink', $document, 'an unrelated link must survive');
        $this->assertStringContainsString('Nextcloud', $document);
        $this->assertStringContainsString(
            'https://www.nextcloud.com/',
            $this->part($path, 'word/_rels/document.xml.rels')
        );
    }//end testCleanHyperlinkIsLeftIntact()


    /**
     * A clean link's display text is still redacted when it happens to contain an
     * entity, without the link being stripped.
     *
     * @return void
     */
    public function testCleanLinkKeepsItsTargetButRedactsDisplayText(): void
    {
        $path = $this->redactLinks(
            links: [['https://www.example.org/dossier', 'Dossier van Jan Jansen']],
            replacements: ['Jan Jansen' => '[PERSOON: 3]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $document = $this->part($path, 'word/document.xml');

        $this->assertStringContainsString('<w:hyperlink', $document, 'target is clean, so the link stays');
        $this->assertStringContainsString('[PERSOON: 3]', $document);
        $this->assertStringNotContainsString('Jan Jansen', $document);
    }//end testCleanLinkKeepsItsTargetButRedactsDisplayText()


    /**
     * A docx with no hyperlinks at all is untouched, and an empty replacement map
     * is a no-op.
     *
     * @return void
     */
    public function testDegenerateInputsAreNoOps(): void
    {
        $before = $this->redactLinks(
            links: [],
            replacements: ['Jan Jansen' => '[PERSOON: 3]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertStringContainsString('Contactpersoon hieronder.', $this->part($before, 'word/document.xml'));

        $empty = $this->redactLinks(
            links: [['mailto:jan.jansen@example.nl', 'Jan Jansen']],
            replacements: [],
            types: []
        );

        $this->assertStringContainsString(
            'jan.jansen@example.nl',
            $this->allParts($empty),
            'no map means nothing to redact'
        );
    }//end testDegenerateInputsAreNoOps()


    /**
     * The output remains a loadable docx after the XML surgery.
     *
     * @return void
     */
    public function testOutputIsStillAValidDocx(): void
    {
        $path = $this->redactLinks(
            links: [
                ['mailto:jan.jansen@example.nl', 'Jan Jansen'],
                ['https://www.nextcloud.com/', 'Nextcloud'],
            ],
            replacements: ['jan.jansen@example.nl' => '[EMAIL: 1]', 'Jan Jansen' => '[PERSOON: 3]'],
            types: ['jan.jansen@example.nl' => 'EMAIL', 'Jan Jansen' => 'PERSON']
        );

        $reloaded = IOFactory::load($path);

        $this->assertNotEmpty($reloaded->getSections(), 'PhpWord must still be able to read the result');
    }//end testOutputIsStillAValidDocx()
}//end class
