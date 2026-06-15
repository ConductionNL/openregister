<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TimeLink;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimeLink entity.
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */
class TimeLinkTest extends TestCase
{

    public function testJsonSerializeIncludesAllFields(): void
    {
        $link = new TimeLink();
        $link->setObjectUuid('uuid-1');
        $link->setRegisterId(5);
        $link->setBackendEntryId('tm-42');
        $link->setBackendName('timemanager');
        $link->setUserId('alice');
        $link->setDurationMinutes(90);
        $link->setDescription('Code review');
        $link->setTotalMinutes(180);

        $data = $link->jsonSerialize();

        $this->assertSame('uuid-1', $data['objectUuid']);
        $this->assertSame(5, $data['registerId']);
        $this->assertSame('tm-42', $data['backendEntryId']);
        $this->assertSame('timemanager', $data['backendName']);
        $this->assertSame('alice', $data['userId']);
        $this->assertSame(90, $data['durationMinutes']);
        $this->assertSame('Code review', $data['description']);
        $this->assertSame(180, $data['totalMinutes']);
        $this->assertArrayHasKey('entryDate', $data);
        $this->assertArrayHasKey('createdAt', $data);
        $this->assertArrayHasKey('updatedAt', $data);
    }//end testJsonSerializeIncludesAllFields()

    public function testEntryDateFormattedAsAtom(): void
    {
        $link = new TimeLink();
        $date = new DateTime('2026-06-01T10:00:00+00:00');
        $link->setEntryDate($date);

        $data = $link->jsonSerialize();
        $this->assertStringStartsWith('2026-06-01', $data['entryDate']);
    }//end testEntryDateFormattedAsAtom()

    public function testNullableFieldsAcceptNull(): void
    {
        $link = new TimeLink();
        $link->setDescription(null);
        $link->setEntryDate(null);

        $data = $link->jsonSerialize();
        $this->assertNull($data['description']);
        $this->assertNull($data['entryDate']);
    }//end testNullableFieldsAcceptNull()
}//end class
