<?php

/**
 * OdtPlannerWirebackTest
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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Covers what the ODT paragraph redactor gained by moving onto the planner.
 *
 * The superseded implementation claimed ranges longest-first over BYTE offsets
 * using `stripos`, whose case-insensitivity is ASCII-only. An accented Dutch or
 * Turkish name written in a different case therefore never matched and was left
 * in the document — silently, because the needle looked "processed". It also had
 * no word-boundary notion and no way to resolve two entities competing for the
 * same characters.
 *
 * The existing DocumentProcessingHandlerOdtWritebackTest covers the behaviour
 * that had to be PRESERVED; this file covers the behaviour that CHANGED.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class OdtPlannerWirebackTest extends TestCase
{


    /**
     * Build a handler with all collaborators mocked.
     *
     * @return DocumentProcessingHandler
     */
    private function makeHandler(): DocumentProcessingHandler
    {
        return new DocumentProcessingHandler(
            rootFolder: $this->createMock(IRootFolder::class),
            userSession: $this->createMock(IUserSession::class),
            logger: $this->createMock(LoggerInterface::class),
            entityRelationMapper: $this->createMock(EntityRelationMapper::class),
            l10n: $this->createMock(IL10N::class)
        );
    }//end makeHandler()


    /**
     * Wrap paragraph markup in a minimal ODF content document.
     *
     * @param string $paragraphs Inner paragraph markup.
     *
     * @return string
     */
    private function odfContent(string $paragraphs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            .'<office:body><office:text>'.$paragraphs.'</office:text></office:body>'
            .'</office:document-content>';
    }//end odfContent()


    /**
     * Redact ODF markup and return the concatenated visible text.
     *
     * @param array<string, string> $replacements Needle => placeholder.
     * @param array<string, string> $types        Needle => raw entity type.
     * @param string                $paragraphs   Inner paragraph markup.
     *
     * @return string
     */
    private function redactedText(string $paragraphs, array $replacements, array $types=[]): string
    {
        $handler = $this->makeHandler();

        $typeMap = new ReflectionProperty(DocumentProcessingHandler::class, 'entityTypesByNeedle');
        $typeMap->setAccessible(true);
        $typeMap->setValue($handler, $types);

        $replace = new ReflectionMethod(DocumentProcessingHandler::class, 'replaceTextInOdfXml');
        $replace->setAccessible(true);
        $out = (string) $replace->invoke($handler, $this->odfContent($paragraphs), $replacements);

        $extract = new ReflectionMethod(DocumentProcessingHandler::class, 'extractOdfConcatenatedText');
        $extract->setAccessible(true);

        return (string) $extract->invoke($handler, $out);
    }//end redactedText()


    /**
     * An accented name in a different case IS now matched.
     *
     * `stripos` folds only ASCII, so `ANIÉLA` against the needle `Aniéla` was a
     * miss and the name stayed in the published document.
     *
     * @return void
     */
    public function testAccentedNameInDifferentCaseIsNowMatched(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Betreft ANIÉLA ÖTZÜ vandaag.</text:p>',
            replacements: ['Aniéla Ötzü' => '[PERSOON: 1]'],
            types: ['Aniéla Ötzü' => 'PERSON']
        );

        $this->assertStringContainsString('[PERSOON: 1]', $text);
        $this->assertStringNotContainsString('ANIÉLA', $text);
        $this->assertStringNotContainsString('ÖTZÜ', $text);
    }//end testAccentedNameInDifferentCaseIsNowMatched()


    /**
     * A multibyte entity split across spans lands correctly, which requires the
     * offsets to be codepoints rather than bytes.
     *
     * @return void
     */
    public function testMultibyteEntitySplitAcrossSpansIsRedacted(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Naam <text:span>Anié</text:span><text:span>la Ötzü</text:span> hier</text:p>',
            replacements: ['Aniéla Ötzü' => '[PERSOON: 1]'],
            types: ['Aniéla Ötzü' => 'PERSON']
        );

        $this->assertSame('Naam [PERSOON: 1] hier', $text);
    }//end testMultibyteEntitySplitAcrossSpansIsRedacted()


    /**
     * Word boundaries now apply, so a short name stops rewriting ordinary words.
     *
     * @return void
     */
    public function testShortNameNoLongerRewritesAnOrdinaryWord(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>In Januari sprak Jan met de raad.</text:p>',
            replacements: ['Jan' => '[PERSOON: 1]'],
            types: ['Jan' => 'PERSON']
        );

        $this->assertSame('In Januari sprak [PERSOON: 1] met de raad.', $text);
    }//end testShortNameNoLongerRewritesAnOrdinaryWord()


    /**
     * A date is not matched inside a case number.
     *
     * @return void
     */
    public function testDateIsNotMatchedInsideACaseNumber(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Kenmerk 2026-0012, vastgesteld 2026.</text:p>',
            replacements: ['2026' => '[DATUM: 4]'],
            types: ['2026' => 'DATE']
        );

        $this->assertSame('Kenmerk 2026-0012, vastgesteld [DATUM: 4].', $text);
    }//end testDateIsNotMatchedInsideACaseNumber()


    /**
     * A containing entity beats the entity nested inside it, so no fragment of
     * the longer value survives.
     *
     * @return void
     */
    public function testContainingEntityBeatsTheNestedOne(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Mail robert@rjzondervan.nl voor vragen.</text:p>',
            replacements: [
                'rjzondervan'           => '[PERSOON: 1]',
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
            ],
            types: [
                'rjzondervan'           => 'PERSON',
                'robert@rjzondervan.nl' => 'EMAIL',
            ]
        );

        $this->assertSame('Mail [EMAIL: 2] voor vragen.', $text);
    }//end testContainingEntityBeatsTheNestedOne()


    /**
     * Two entities competing for the same characters leave no residue.
     *
     * @return void
     */
    public function testCompetingEntitiesLeaveNoResidue(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Betreft Jan de Vries-Bakker vandaag.</text:p>',
            replacements: [
                'Jan de Vries' => '[PERSOON: 1]',
                'Vries-Bakker' => '[PERSOON: 2]',
            ],
            types: ['Jan de Vries' => 'PERSON', 'Vries-Bakker' => 'PERSON']
        );

        $this->assertStringNotContainsString('Vries', $text);
        $this->assertStringNotContainsString('Bakker', $text);
    }//end testCompetingEntitiesLeaveNoResidue()


    /**
     * A match must not span two separate paragraphs — they are different text
     * flows, and joining them would invent a redaction.
     *
     * @return void
     */
    public function testMatchDoesNotSpanTwoParagraphs(): void
    {
        $text = $this->redactedText(
            paragraphs: '<text:p>Slot is Jan</text:p><text:p>Jansen begint</text:p>',
            replacements: ['JanJansen' => '[PERSOON: 1]'],
            types: ['JanJansen' => 'PERSON']
        );

        $this->assertStringNotContainsString('[PERSOON: 1]', $text, 'paragraphs are separate flows');
        $this->assertStringContainsString('Jan', $text);
        $this->assertStringContainsString('Jansen', $text);
    }//end testMatchDoesNotSpanTwoParagraphs()
}//end class
