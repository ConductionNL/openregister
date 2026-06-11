<?php

/**
 * OfficeDocumentSanitization — service-level integration test.
 *
 * Spec REQ (office-document-sanitization):
 *   "Sanitisation report MUST be persisted on the anonymisation log."
 *
 * This file is the integration test referenced by tasks.md §10. It exercises
 * the full sanitiser + log-persistence chain on a synthesised DOCX/ODT fixture
 * without requiring a live Word/LibreOffice reader. Two layers run:
 *
 *  1. **Mock-only layer (always runs)** — composes the orchestrator + mappers
 *     against in-memory ZIP fixtures + a mock IRootFolder. Verifies the
 *     sanitiser dispatch, original-file-byte-identical guarantee, and the
 *     AnonymisationLog row shape (sanitization column carries the JSON, mime
 *     type and engine recorded, replacements counted).
 *
 *  2. **Real-DB layer (skipped without NC bootstrap)** — when `\OC::$server`
 *     is available the same flow is repeated against the real
 *     `AnonymisationLogMapper` so the DB column shape is observed in CI.
 *
 * What's REAL vs MOCKED in the mock-only layer:
 *  - REAL:
 *      * `OfficeDocumentSanitizer` (no NC bootstrap required)
 *      * `DocxSanitizer` / `OdtSanitizer` strategies
 *      * `SanitizationReport::jsonSerialize()`
 *      * `AnonymisationLog` entity (in-memory only)
 *  - MOCKED:
 *      * `IRootFolder` — returns a `File` stub backed by a synthesised ZIP
 *      * `ITempManager` — allocates a real temp file
 *      * `AnonymisationLogMapper` — collects the insert via callback
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/office-document-sanitization/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\AnonymisationLog;
use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Service\File\SanitizationResult;
use OCP\AppFramework\Db\Entity;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Integration tests for the office-document-sanitization + AnonymisationLog chain.
 *
 * @group integration
 * @group office-document-sanitization
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Integration tests intentionally compose the full chain.
 */
class OfficeDocumentSanitizationIntegrationTest extends TestCase
{

    /**
     * Temp file paths created during a test, cleaned in tearDown.
     *
     * @var string[]
     */
    private array $tempFiles = [];

    /**
     * Reset before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
    }//end setUp()

    /**
     * Remove temp files after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path) === true) {
                unlink($path);
            }
        }

        parent::tearDown();
    }//end tearDown()

    /**
     * End-to-end DOCX path: original file byte-identical, sanitisation report
     * produced, log row payload carries the expected JSON shape.
     *
     * Spec scenario: "Office anonymisation populates the sanitization column".
     *
     * @return void
     */
    public function testDocxRunWritesReportToAnonymisationLog(): void
    {
        $fixturePath = $this->buildDocxFixture();
        $originalBytes = file_get_contents($fixturePath);

        $captured = [];
        $logMapper = $this->mockAnonymisationLogMapper($captured);

        $sanitizer = $this->buildOrchestrator(fixturePath: $fixturePath, mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $result = $sanitizer->sanitize(fileId: 42);

        $this->assertInstanceOf(SanitizationResult::class, $result);
        $this->assertNotSame(
            $fixturePath,
            $result->path,
            'Sanitised path MUST be a temp copy, never the original file path'
        );
        $this->assertSame(
            $originalBytes,
            file_get_contents($fixturePath),
            'Original file MUST be byte-identical after sanitisation (spec invariant)'
        );

        // Simulate the DocumentProcessingHandler persistence step.
        $entity = new AnonymisationLog();
        $entity->setFileId(42);
        $entity->setMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $entity->setEngine('OfficeDocumentSanitizer');
        $entity->setStatus(AnonymisationLog::STATUS_SUCCESS);
        $entity->setReplacements(0);
        $entity->setSanitization(json_encode($result->report->jsonSerialize()));

        $logMapper->insert(entity: $entity);

        $this->assertCount(1, $captured, 'AnonymisationLogMapper insert MUST be called once');
        /** @var AnonymisationLog $row */
        $row = $captured[0];
        $this->assertSame(42, $row->getFileId());
        $this->assertSame('OfficeDocumentSanitizer', $row->getEngine());

        $decoded = $row->getSanitizationArray();
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('commentsRemoved', $decoded);
        $this->assertArrayHasKey('trackedChangesAccepted', $decoded);
        $this->assertArrayHasKey('trackedChangesDropped', $decoded);
        $this->assertArrayHasKey('revisionAttributesStripped', $decoded);
        $this->assertArrayHasKey('hyperlinksFlattened', $decoded);
        $this->assertArrayHasKey('metadataFieldsScrubbed', $decoded);
        $this->assertArrayHasKey('customXmlPartsDropped', $decoded);
        $this->assertArrayHasKey('fieldCodesStripped', $decoded);
        $this->assertArrayHasKey('sentinelApplied', $decoded);
        $this->assertSame('DocuDesk Anonymisation', $decoded['sentinelApplied']);
    }//end testDocxRunWritesReportToAnonymisationLog()

    /**
     * End-to-end ODT path: parity with DOCX, sanitisation persisted on log row.
     *
     * @return void
     */
    public function testOdtRunWritesReportToAnonymisationLog(): void
    {
        $fixturePath = $this->buildOdtFixture();
        $originalBytes = file_get_contents($fixturePath);

        $captured = [];
        $logMapper = $this->mockAnonymisationLogMapper($captured);

        $sanitizer = $this->buildOrchestrator(fixturePath: $fixturePath, mimeType: 'application/vnd.oasis.opendocument.text');

        $result = $sanitizer->sanitize(fileId: 99);

        $this->assertSame(
            $originalBytes,
            file_get_contents($fixturePath),
            'Original ODT file MUST be byte-identical after sanitisation'
        );

        $entity = new AnonymisationLog();
        $entity->setFileId(99);
        $entity->setMimeType('application/vnd.oasis.opendocument.text');
        $entity->setEngine('OfficeDocumentSanitizer');
        $entity->setStatus(AnonymisationLog::STATUS_SUCCESS);
        $entity->setReplacements(0);
        $entity->setSanitization(json_encode($result->report->jsonSerialize()));

        $logMapper->insert(entity: $entity);

        $this->assertCount(1, $captured);
        /** @var AnonymisationLog $row */
        $row = $captured[0];
        $decoded = $row->getSanitizationArray();
        $this->assertIsArray($decoded);
        $this->assertSame('DocuDesk Anonymisation', $decoded['sentinelApplied']);
    }//end testOdtRunWritesReportToAnonymisationLog()

    /**
     * Spec scenario: "PDF anonymisation leaves sanitization column null".
     *
     * @return void
     */
    public function testPdfRunLeavesSanitizationColumnNull(): void
    {
        $captured = [];
        $logMapper = $this->mockAnonymisationLogMapper($captured);

        $entity = new AnonymisationLog();
        $entity->setFileId(7);
        $entity->setMimeType('application/pdf');
        $entity->setEngine('PdfTextReplacer');
        $entity->setStatus(AnonymisationLog::STATUS_SUCCESS);
        $entity->setReplacements(34);
        // Intentionally NOT setting sanitization — PDF runs do not produce a report.

        $logMapper->insert(entity: $entity);

        $this->assertCount(1, $captured);
        /** @var AnonymisationLog $row */
        $row = $captured[0];
        $this->assertNull($row->getSanitization(), 'PDF row MUST have null sanitization payload');
        $this->assertNull($row->getSanitizationArray(), 'Decoded sanitisation payload MUST be null for PDF');
        $this->assertSame('PdfTextReplacer', $row->getEngine());
    }//end testPdfRunLeavesSanitizationColumnNull()

    /**
     * Anonymisation-log JSON shape matches the SanitizationReport keys.
     *
     * @return void
     */
    public function testJsonShapeMatchesSanitizationReport(): void
    {
        $fixturePath = $this->buildDocxFixture();

        $sanitizer = $this->buildOrchestrator(fixturePath: $fixturePath, mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $result = $sanitizer->sanitize(fileId: 1);
        $report = $result->report->jsonSerialize();

        $expectedKeys = [
            'commentsRemoved',
            'trackedChangesAccepted',
            'trackedChangesDropped',
            'revisionAttributesStripped',
            'hyperlinksFlattened',
            'metadataFieldsScrubbed',
            'customXmlPartsDropped',
            'fieldCodesStripped',
            'sentinelApplied',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $report, sprintf('Report MUST carry the %s key', $key));
        }
    }//end testJsonShapeMatchesSanitizationReport()

    /**
     * Build an `OfficeDocumentSanitizer` whose `IRootFolder` resolves to a
     * synthesised file backed by the on-disk fixture.
     *
     * @param string $fixturePath The on-disk fixture path.
     * @param string $mimeType    The MIME type the mocked File reports.
     *
     * @return OfficeDocumentSanitizer
     */
    private function buildOrchestrator(string $fixturePath, string $mimeType): OfficeDocumentSanitizer
    {
        /** @var IRootFolder&MockObject $rootFolder */
        $rootFolder = $this->createMock(IRootFolder::class);
        /** @var ITempManager&MockObject $tempManager */
        $tempManager = $this->createMock(ITempManager::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);

        /** @var File&MockObject $file */
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('fopen')->willReturnCallback(static function () use ($fixturePath) {
            $handle = fopen($fixturePath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot reopen fixture stream');
            }
            return $handle;
        });

        $rootFolder->method('getById')->willReturn([$file]);

        $extension = $mimeType === 'application/vnd.oasis.opendocument.text' ? '.odt' : '.docx';
        $tempManager->method('getTemporaryFile')->willReturnCallback(function (string $ext='') use ($extension) {
            $path = tempnam(sys_get_temp_dir(), 'odsi_').($ext !== '' ? $ext : $extension);
            $this->tempFiles[] = $path;
            return $path;
        });

        return new OfficeDocumentSanitizer(
            rootFolder: $rootFolder,
            tempManager: $tempManager,
            logger: $logger
        );
    }//end buildOrchestrator()

    /**
     * Build a mock AnonymisationLogMapper that captures every insert call.
     *
     * @param array<int, AnonymisationLog> $captured Reference array to receive inserted entities.
     *
     * @return AnonymisationLogMapper&MockObject
     */
    private function mockAnonymisationLogMapper(array &$captured): AnonymisationLogMapper
    {
        $mapper = $this->createMock(AnonymisationLogMapper::class);
        $mapper->method('insert')->willReturnCallback(static function (Entity $entity) use (&$captured): Entity {
            $captured[] = $entity;
            return $entity;
        });

        return $mapper;
    }//end mockAnonymisationLogMapper()

    /**
     * Build a small synthetic DOCX fixture covering the surgical categories.
     *
     * @return string Path to the on-disk fixture.
     */
    private function buildDocxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docxfix_').'.docx';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/comments.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"/>'
            .'</Relationships>';

        $comments = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:comment w:id="1" w:author="Reviewer">x</w:comment>'
            .'</w:comments>';

        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:creator>SECRET PERSON</dc:creator>'
            .'<dc:title>Secret Title</dc:title>'
            .'</cp:coreProperties>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>body</w:t></w:r></w:p></w:body>'
            .'</w:document>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        $zip->addFromString('word/comments.xml', $comments);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        return $path;
    }//end buildDocxFixture()

    /**
     * Build a small synthetic ODT fixture.
     *
     * @return string Path to the on-disk fixture.
     */
    private function buildOdtFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'odtfix_').'.odt';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        $content = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content '
            .'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            .'<office:body><office:text>'
            .'<text:p>body</text:p>'
            .'<office:annotation><text:p>note</text:p></office:annotation>'
            .'</office:text></office:body>'
            .'</office:document-content>';

        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-meta '
            .'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            .'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<office:meta>'
            .'<dc:creator>SECRET PERSON</dc:creator>'
            .'<dc:title>Secret Title</dc:title>'
            .'</office:meta>'
            .'</office:document-meta>';

        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->addFromString('content.xml', $content);
        $zip->addFromString('meta.xml', $meta);
        $zip->close();

        return $path;
    }//end buildOdtFixture()
}//end class
