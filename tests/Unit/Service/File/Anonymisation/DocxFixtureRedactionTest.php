<?php

/**
 * DocxFixtureRedactionTest
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Drives the real docx traversal over the repository's own docx fixture.
 *
 * `tests/Data/anonimisering_testdocument.docx` shipped in this repo but was
 * referenced by NO test. That is not incidental: it is a realistic Dutch
 * fictional-PII document, and having it unexercised is a plausible reason the
 * cross-`<w:r>`-run defect survived as long as it did.
 *
 * The fixture also happens to demonstrate why run grouping must be scoped:
 * its `<w:t>` values concatenate to `Kerkstraat 123512 GK Utrecht` across two
 * SEPARATE paragraphs. Flattening a whole section would let a needle match
 * across text no reader sees as adjacent.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class DocxFixtureRedactionTest extends TestCase
{

    /**
     * Path to the repository's docx fixture.
     *
     * @var string
     */
    private const FIXTURE = __DIR__.'/../../../../Data/anonimisering_testdocument.docx';


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
     * Run the handler's real element traversal over the loaded fixture and
     * return the resulting visible text.
     *
     * Mirrors replaceWordsInWordDocument's own walk (headers, body, footers)
     * without the file IO, which needs a Nextcloud container.
     *
     * @param array<string, string> $replacements Needle => placeholder.
     * @param array<string, string> $types        Needle => raw entity type.
     *
     * @return string
     */
    private function redactFixture(array $replacements, array $types): string
    {
        $handler = $this->makeHandler();

        $typeMap = new ReflectionProperty(DocumentProcessingHandler::class, 'entityTypesByNeedle');
        $typeMap->setAccessible(true);
        $typeMap->setValue($handler, $types);

        $apply = new ReflectionMethod(DocumentProcessingHandler::class, 'applyToRunGroup');
        $apply->setAccessible(true);

        $phpWord = IOFactory::load(self::FIXTURE);

        // Recurse exactly as the production walk does: group consecutive text
        // leaves inside a container, never across a section's paragraph list.
        $walk = function (array $elements, bool $groupLeaves) use (&$walk, $apply, $handler, $replacements): void {
            $group = [];
            foreach ($elements as $element) {
                if (method_exists($element, 'getText') === true && method_exists($element, 'setText') === true) {
                    $group[] = $element;
                    if ($groupLeaves === false) {
                        $apply->invoke($handler, $group, $replacements);
                        $group = [];
                    }

                    continue;
                }

                $apply->invoke($handler, $group, $replacements);
                $group = [];
            }

            $apply->invoke($handler, $group, $replacements);

            foreach ($elements as $element) {
                if (method_exists($element, 'getRows') === true) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            $walk($cell->getElements(), true);
                        }
                    }
                }

                if (method_exists($element, 'getItems') === true) {
                    foreach ($element->getItems() as $item) {
                        $walk($item->getElements(), true);
                    }
                }

                if (method_exists($element, 'getElements') === true) {
                    $walk($element->getElements(), true);
                }
            }
        };

        foreach ($phpWord->getSections() as $section) {
            $walk($section->getElements(), false);
        }

        return $this->visibleText($phpWord);
    }//end redactFixture()


    /**
     * Concatenate every text leaf's value, for assertion purposes.
     *
     * @param mixed $phpWord The loaded document.
     *
     * @return string
     */
    private function visibleText($phpWord): string
    {
        $text  = '';
        $walk  = function (array $elements) use (&$walk, &$text): void {
            foreach ($elements as $element) {
                if (method_exists($element, 'getText') === true) {
                    $value = $element->getText();
                    if (is_string($value) === true) {
                        $text .= $value.' ';
                    }
                }

                if (method_exists($element, 'getRows') === true) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            $walk($cell->getElements());
                        }
                    }
                }

                if (method_exists($element, 'getItems') === true) {
                    foreach ($element->getItems() as $item) {
                        $walk($item->getElements());
                    }
                }

                if (method_exists($element, 'getElements') === true) {
                    $walk($element->getElements());
                }
            }
        };

        foreach ($phpWord->getSections() as $section) {
            $walk($section->getElements());
        }

        return $text;
    }//end visibleText()


    /**
     * The fixture exists and is loadable — guards against it being moved or
     * deleted, which would silently reduce this file to a no-op.
     *
     * @return void
     */
    public function testFixtureIsPresent(): void
    {
        $this->assertFileExists(self::FIXTURE);
    }//end testFixtureIsPresent()


    /**
     * Real entities from the fixture are redacted across its actual structure.
     *
     * @return void
     */
    public function testFixtureEntitiesAreRedacted(): void
    {
        $text = $this->redactFixture(
            replacements: [
                'Jan de Vries'              => '[PERSOON: 1]',
                'jan.devries@example.org'   => '[EMAIL: 2]',
                '123456782'                 => '[BSN: 3]',
            ],
            types: [
                'Jan de Vries'            => 'PERSON',
                'jan.devries@example.org' => 'EMAIL',
                '123456782'               => 'SSN',
            ]
        );

        $this->assertStringContainsString('[PERSOON: 1]', $text);
        $this->assertStringContainsString('[EMAIL: 2]', $text);
        $this->assertStringContainsString('[BSN: 3]', $text);

        $this->assertStringNotContainsString('Jan de Vries', $text);
        $this->assertStringNotContainsString('jan.devries@example.org', $text);
        $this->assertStringNotContainsString('123456782', $text);
    }//end testFixtureEntitiesAreRedacted()


    /**
     * A needle that only exists by concatenating two separate paragraphs must
     * NOT match. `Kerkstraat 12` and `3512 GK Utrecht` are different
     * paragraphs; joined they read `Kerkstraat 123512 GK Utrecht`.
     *
     * @return void
     */
    public function testNeedleSpanningTwoParagraphsDoesNotMatch(): void
    {
        $text = $this->redactFixture(
            replacements: ['123512' => '[NUMMER: 9]'],
            types: ['123512' => 'SSN']
        );

        $this->assertStringNotContainsString(
            '[NUMMER: 9]',
            $text,
            'a match may not span two paragraphs'
        );
    }//end testNeedleSpanningTwoParagraphsDoesNotMatch()


    /**
     * Non-entity text in the fixture survives untouched, including the address
     * and place names that are not in the substitution map.
     *
     * @return void
     */
    public function testNonEntityTextSurvives(): void
    {
        $text = $this->redactFixture(
            replacements: ['Jan de Vries' => '[PERSOON: 1]'],
            types: ['Jan de Vries' => 'PERSON']
        );

        $this->assertStringContainsString('Kerkstraat', $text);
        $this->assertStringContainsString('Utrecht', $text);
        $this->assertStringContainsString('Testdocument', $text);
    }//end testNonEntityTextSurvives()
}//end class
