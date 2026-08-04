<?php

/**
 * DocumentProcessingHandlerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\DocumentProcessingHandler}
 * covering the `structurePreservation` accessor's non-PDF branch
 * (REQ-ORTPR-003: no PDF structure tree is involved, so the accessor MUST
 * return null and the HTTP block MUST be omitted).
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\File\OfficeDocumentSanitizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see DocumentProcessingHandler::getLastStructurePreservation()}.
 */
class DocumentProcessingHandlerTest extends TestCase
{

    /**
     * Build a handler with fully-mocked collaborators (no live NC instance).
     *
     * @return DocumentProcessingHandler
     */
    private function makeHandler(): DocumentProcessingHandler
    {
        return new DocumentProcessingHandler(
            rootFolder: $this->createMock(originalClassName: IRootFolder::class),
            userSession: $this->createMock(originalClassName: IUserSession::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
            entityRelationMapper: $this->createMock(originalClassName: EntityRelationMapper::class),
            sanitizer: $this->createMock(originalClassName: OfficeDocumentSanitizer::class),
            anonymisationLogMapper: $this->createMock(originalClassName: AnonymisationLogMapper::class),
            l10n: null
        );
    }//end makeHandler()

    /**
     * Before any redaction has run, the accessor returns null.
     *
     * @return void
     */
    public function testStructurePreservationIsNullBeforeAnyRun(): void
    {
        $handler = $this->makeHandler();

        self::assertNull($handler->getLastStructurePreservation());
    }//end testStructurePreservationIsNullBeforeAnyRun()

    /**
     * A non-PDF (plain-text) redaction never touches
     * `lastStructurePreservation` — the accessor returns null because no PDF
     * structure tree is involved (REQ-ORTPR-003).
     *
     * @return void
     */
    public function testNonPdfHasNoStructureBlock(): void
    {
        $handler = $this->makeHandler();

        $outputFile = $this->createMock(originalClassName: File::class);
        $outputFile->method('getPath')->willReturn('/note_replaced.txt');

        $folder = $this->createMock(originalClassName: Folder::class);
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFile')->willReturn($outputFile);

        $textFile = $this->createMock(originalClassName: File::class);
        $textFile->method('getName')->willReturn('note.txt');
        $textFile->method('getPath')->willReturn('/note.txt');
        $textFile->method('getContent')->willReturn('Aanvraag van Jan Jansen.');
        $textFile->method('getParent')->willReturn($folder);

        $handler->replaceWords(
            node: $textFile,
            replacements: ['Jan Jansen' => '[PERSON: 1]'],
            outputName: 'note_replaced.txt'
        );

        self::assertNull($handler->getLastStructurePreservation());
    }//end testNonPdfHasNoStructureBlock()
}//end class
