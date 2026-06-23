<?php

/**
 * DocumentProcessingHandlerLocalizationTest
 *
 * Unit tests for the placeholder TYPE-label localisation in
 * {@see \OCA\OpenRegister\Service\File\DocumentProcessingHandler}
 * (anonymisation-placeholder-id-scope): known entity types are translated to
 * the acting user's language, unknown types fall back to the raw label, and a
 * null IL10N leaves the English label untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for {@see DocumentProcessingHandler::localizeEntityType()}.
 */
class DocumentProcessingHandlerLocalizationTest extends TestCase
{
    /**
     * Build a handler with an optional IL10N. All other collaborators are
     * mocked (the localisation helper touches none of them).
     *
     * @param IL10N|null $l10n The localisation to inject (null to omit).
     *
     * @return DocumentProcessingHandler
     */
    private function makeHandler(?IL10N $l10n): DocumentProcessingHandler
    {
        return new DocumentProcessingHandler(
            rootFolder: $this->createMock(originalClassName: IRootFolder::class),
            userSession: $this->createMock(originalClassName: IUserSession::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            entityRelationMapper: $this->createMock(originalClassName: EntityRelationMapper::class),
            l10n: $l10n
        );

    }//end makeHandler()

    /**
     * Invoke the private localizeEntityType() via reflection.
     *
     * @param DocumentProcessingHandler $handler    The handler under test.
     * @param string                    $entityType The raw entity type.
     *
     * @return string The localised (or raw) label.
     */
    private function callLocalize(DocumentProcessingHandler $handler, string $entityType): string
    {
        $method = new ReflectionMethod(objectOrMethod: DocumentProcessingHandler::class, method: 'localizeEntityType');
        $method->setAccessible(accessible: true);

        return (string) $method->invoke($handler, $entityType);

    }//end callLocalize()

    /**
     * A Dutch IL10N translates a known type: PERSON → PERSOON.
     *
     * @return void
     */
    public function testLocalizesKnownTypeWithDutchL10n(): void
    {
        $l10n = $this->createMock(originalClassName: IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text): string {
                $map = [
                    'PERSON'       => 'PERSOON',
                    'ORGANIZATION' => 'ORGANISATIE',
                ];

                return ($map[$text] ?? $text);
            }
        );

        $handler = $this->makeHandler(l10n: $l10n);

        $this->assertSame(expected: 'PERSOON', actual: $this->callLocalize(handler: $handler, entityType: 'PERSON'));
        $this->assertSame(
            expected: 'ORGANISATIE',
            actual: $this->callLocalize(handler: $handler, entityType: 'ORGANIZATION')
        );

    }//end testLocalizesKnownTypeWithDutchL10n()

    /**
     * An unknown / free-form type is returned unchanged (never sent to t()).
     *
     * @return void
     */
    public function testUnknownTypeFallsBackToRawLabel(): void
    {
        $l10n = $this->createMock(originalClassName: IL10N::class);
        // The t() method must NOT be called for a non-enumerated type.
        $l10n->expects($this->never())->method('t');

        $handler = $this->makeHandler(l10n: $l10n);

        $this->assertSame(
            expected: 'CUSTOM_THING',
            actual: $this->callLocalize(handler: $handler, entityType: 'CUSTOM_THING')
        );

    }//end testUnknownTypeFallsBackToRawLabel()

    /**
     * With no IL10N injected the raw English label is emitted (the en /
     * untranslated behaviour).
     *
     * @return void
     */
    public function testNullL10nReturnsRawLabel(): void
    {
        $handler = $this->makeHandler(l10n: null);

        $this->assertSame(
            expected: 'PERSON',
            actual: $this->callLocalize(handler: $handler, entityType: 'PERSON')
        );

    }//end testNullL10nReturnsRawLabel()
}//end class
