<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DestructionList;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DestructionList entity
 */
class DestructionListTest extends TestCase
{
    private DestructionList $entity;

    protected function setUp(): void
    {
        $this->entity = new DestructionList();
    }

    /**
     * Test that constructor registers correct field types.
     */
    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('json', $fieldTypes['objects']);
        $this->assertSame('string', $fieldTypes['approvedBy']);
        $this->assertSame('datetime', $fieldTypes['approvedAt']);
        $this->assertSame('string', $fieldTypes['notes']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    /**
     * Test default values after construction.
     */
    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->entity->getUuid());
        $this->assertNull($this->entity->getName());
        $this->assertNull($this->entity->getStatus());
        $this->assertSame([], $this->entity->getObjects());
        $this->assertNull($this->entity->getApprovedBy());
        $this->assertNull($this->entity->getApprovedAt());
        $this->assertNull($this->entity->getNotes());
    }

    /**
     * Test getters and setters.
     */
    public function testGettersAndSetters(): void
    {
        $now = new DateTime();

        $this->entity->setUuid('dl-uuid');
        $this->entity->setName('Test List');
        $this->entity->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        $this->entity->setObjects(['obj-1', 'obj-2']);
        $this->entity->setApprovedBy('admin');
        $this->entity->setApprovedAt($now);
        $this->entity->setNotes('Test notes');
        $this->entity->setOrganisation('org-1');

        $this->assertSame('dl-uuid', $this->entity->getUuid());
        $this->assertSame('Test List', $this->entity->getName());
        $this->assertSame('pending_review', $this->entity->getStatus());
        $this->assertCount(2, $this->entity->getObjects());
        $this->assertSame('admin', $this->entity->getApprovedBy());
        $this->assertSame($now, $this->entity->getApprovedAt());
    }

    /**
     * Test jsonSerialize output.
     */
    public function testJsonSerialize(): void
    {
        $this->entity->setUuid('dl-1');
        $this->entity->setName('Destruction List 2026');
        $this->entity->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        $this->entity->setObjects(['obj-1', 'obj-2', 'obj-3']);

        $json = $this->entity->jsonSerialize();

        $this->assertSame('dl-1', $json['id']);
        $this->assertSame('dl-1', $json['uuid']);
        $this->assertSame('Destruction List 2026', $json['name']);
        $this->assertSame('pending_review', $json['status']);
        $this->assertSame(3, $json['objectCount']);
        $this->assertCount(3, $json['objects']);
    }

    /**
     * Test hydrate method.
     */
    public function testHydrate(): void
    {
        $this->entity->hydrate([
            'uuid'         => 'h-dl-1',
            'name'         => 'Hydrated List',
            'status'       => 'approved',
            'objects'      => ['a', 'b'],
            'notes'        => 'Some notes',
            'organisation' => 'org-2',
        ]);

        $this->assertSame('h-dl-1', $this->entity->getUuid());
        $this->assertSame('Hydrated List', $this->entity->getName());
        $this->assertSame('approved', $this->entity->getStatus());
        $this->assertCount(2, $this->entity->getObjects());
    }

    /**
     * Test status constants.
     */
    public function testStatusConstants(): void
    {
        $this->assertSame('pending_review', DestructionList::STATUS_PENDING_REVIEW);
        $this->assertSame('approved', DestructionList::STATUS_APPROVED);
        $this->assertSame('completed', DestructionList::STATUS_COMPLETED);
        $this->assertSame('cancelled', DestructionList::STATUS_CANCELLED);
        $this->assertCount(4, DestructionList::VALID_STATUSES);
    }
}
