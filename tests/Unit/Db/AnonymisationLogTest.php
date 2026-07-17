<?php

/**
 * AnonymisationLog entity unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AnonymisationLog;
use PHPUnit\Framework\TestCase;

class AnonymisationLogTest extends TestCase
{
    private AnonymisationLog $log;

    protected function setUp(): void
    {
        $this->log = new AnonymisationLog();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->log->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['fileId']);
        $this->assertSame('string', $fieldTypes['objectUuid']);
        $this->assertSame('integer', $fieldTypes['registerId']);
        $this->assertSame('integer', $fieldTypes['schemaId']);
        $this->assertSame('string', $fieldTypes['mimeType']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('string', $fieldTypes['reason']);
        $this->assertSame('string', $fieldTypes['sanitization']);
        $this->assertSame('integer', $fieldTypes['replacements']);
        $this->assertSame('integer', $fieldTypes['durationMs']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame(0, $this->log->getFileId());
        $this->assertNull($this->log->getObjectUuid());
        $this->assertNull($this->log->getRegisterId());
        $this->assertNull($this->log->getSchemaId());
        $this->assertSame('', $this->log->getMimeType());
        $this->assertSame('', $this->log->getEngine());
        $this->assertSame(AnonymisationLog::STATUS_SUCCESS, $this->log->getStatus());
        $this->assertNull($this->log->getReason());
        $this->assertNull($this->log->getSanitization());
        $this->assertSame(0, $this->log->getReplacements());
        $this->assertNull($this->log->getDurationMs());
        $this->assertInstanceOf(DateTime::class, $this->log->getCreated());
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('success', AnonymisationLog::STATUS_SUCCESS);
        $this->assertSame('failure', AnonymisationLog::STATUS_FAILURE);
    }

    public function testGetSanitizationArrayNullByDefault(): void
    {
        $this->assertNull($this->log->getSanitizationArray());
    }

    public function testGetSanitizationArrayReturnsDecoded(): void
    {
        $this->log->setSanitization(json_encode([
            'commentsRemoved' => 2,
            'trackedChangesAccepted' => 1,
            'sentinelApplied' => 'DocuDesk Anonymisation',
        ]));

        $decoded = $this->log->getSanitizationArray();
        $this->assertIsArray($decoded);
        $this->assertSame(2, $decoded['commentsRemoved']);
        $this->assertSame(1, $decoded['trackedChangesAccepted']);
        $this->assertSame('DocuDesk Anonymisation', $decoded['sentinelApplied']);
    }

    public function testGetSanitizationArrayReturnsNullOnInvalidJson(): void
    {
        $this->log->setSanitization('not-a-json');
        $this->assertNull($this->log->getSanitizationArray());
    }

    public function testJsonSerializeProducesExpectedShape(): void
    {
        $this->log->setFileId(42);
        $this->log->setObjectUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $this->log->setRegisterId(7);
        $this->log->setSchemaId(11);
        $this->log->setMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->log->setEngine('OfficeDocumentSanitizer');
        $this->log->setStatus(AnonymisationLog::STATUS_SUCCESS);
        $this->log->setReplacements(3);
        $this->log->setDurationMs(125);
        $this->log->setSanitization(json_encode(['commentsRemoved' => 4]));

        $json = $this->log->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertSame(42, $json['fileId']);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $json['objectUuid']);
        $this->assertSame(7, $json['registerId']);
        $this->assertSame(11, $json['schemaId']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $json['mimeType']);
        $this->assertSame('OfficeDocumentSanitizer', $json['engine']);
        $this->assertSame('success', $json['status']);
        $this->assertNull($json['reason']);
        $this->assertSame(['commentsRemoved' => 4], $json['sanitization']);
        $this->assertSame(3, $json['replacements']);
        $this->assertSame(125, $json['durationMs']);
        $this->assertIsString($json['created']);
    }

    public function testJsonSerializeNullSanitizationForNonOffice(): void
    {
        $this->log->setFileId(99);
        $this->log->setMimeType('application/pdf');
        $this->log->setEngine('PdfTextReplacer');

        $json = $this->log->jsonSerialize();
        $this->assertNull($json['sanitization']);
    }

    public function testFailureRowWithReason(): void
    {
        $this->log->setStatus(AnonymisationLog::STATUS_FAILURE);
        $this->log->setReason('encrypted');

        $this->assertSame('failure', $this->log->getStatus());
        $this->assertSame('encrypted', $this->log->getReason());
    }
}
