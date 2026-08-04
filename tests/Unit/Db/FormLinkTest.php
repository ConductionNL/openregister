<?php

/**
 * Unit tests for the FormLink entity.
 *
 * Covers the jsonSerialize contract used by the Tier-2 forms link
 * REST surface (FormLinksController + FormsProvider).
 *
 * @category Tests
 * @package  Unit\Db
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FormLink;
use PHPUnit\Framework\TestCase;

class FormLinkTest extends TestCase
{


    public function testJsonSerializeFormLevelRow(): void
    {
        $link = new FormLink();
        $link->setObjectUuid('obj-1');
        $link->setRegisterId(7);
        $link->setSchemaId(3);
        $link->setFormId(42);
        $link->setFormHash('hash-42');
        $link->setSubmissionId(null);
        $link->setTitle('Budget intake');
        $link->setStatus('open');
        $link->setExpiresAt(new DateTime('2026-12-31T23:59:00+00:00'));
        $link->setLinkedBy('admin');
        $link->setLinkedAt(new DateTime('2026-05-01T10:00:00+00:00'));

        $json = $link->jsonSerialize();

        $this->assertSame('obj-1', $json['objectUuid']);
        $this->assertSame(7, $json['registerId']);
        $this->assertSame(3, $json['schemaId']);
        $this->assertSame(42, $json['formId']);
        $this->assertSame('hash-42', $json['formHash']);
        $this->assertNull($json['submissionId']);
        $this->assertSame('Budget intake', $json['title']);
        $this->assertSame('open', $json['status']);
        $this->assertStringStartsWith('2026-12-31', $json['expiresAt']);
        $this->assertSame('admin', $json['linkedBy']);
        $this->assertStringStartsWith('2026-05-01', $json['linkedAt']);

    }//end testJsonSerializeFormLevelRow()


    public function testJsonSerializeSubmissionLevelRow(): void
    {
        $link = new FormLink();
        $link->setObjectUuid('obj-1');
        $link->setRegisterId(7);
        $link->setFormId(42);
        $link->setSubmissionId(1001);
        $link->setLinkedBy('alice');
        $link->setLinkedAt(new DateTime());

        $json = $link->jsonSerialize();

        $this->assertSame(1001, $json['submissionId']);
        $this->assertSame(42, $json['formId']);

    }//end testJsonSerializeSubmissionLevelRow()


    public function testJsonSerializeHandlesNullableFields(): void
    {
        $link = new FormLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['title']);
        $this->assertNull($json['status']);
        $this->assertNull($json['expiresAt']);
        $this->assertNull($json['formHash']);
        $this->assertNull($json['submissionId']);
        $this->assertNull($json['schemaId']);

    }//end testJsonSerializeHandlesNullableFields()


    public function testSettersAndGetters(): void
    {
        $link = new FormLink();
        $link->setStatus('closed');
        $link->setFormHash('xyz789');

        $this->assertSame('closed', $link->getStatus());
        $this->assertSame('xyz789', $link->getFormHash());

    }//end testSettersAndGetters()


}//end class
