<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SelectionList;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SelectionList entity
 */
class SelectionListTest extends TestCase
{
    private SelectionList $entity;

    protected function setUp(): void
    {
        $this->entity = new SelectionList();
    }

    /**
     * Test that constructor registers correct field types.
     */
    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['category']);
        $this->assertSame('integer', $fieldTypes['retentionYears']);
        $this->assertSame('string', $fieldTypes['action']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('json', $fieldTypes['schemaOverrides']);
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
        $this->assertNull($this->entity->getCategory());
        $this->assertNull($this->entity->getRetentionYears());
        $this->assertNull($this->entity->getAction());
        $this->assertNull($this->entity->getDescription());
        $this->assertSame([], $this->entity->getSchemaOverrides());
        $this->assertNull($this->entity->getOrganisation());
    }

    /**
     * Test getters and setters.
     */
    public function testGettersAndSetters(): void
    {
        $this->entity->setUuid('test-uuid');
        $this->entity->setCategory('B1');
        $this->entity->setRetentionYears(5);
        $this->entity->setAction('vernietigen');
        $this->entity->setDescription('Short retention');
        $this->entity->setSchemaOverrides(['schema-1' => 10]);
        $this->entity->setOrganisation('gemeente-test');

        $this->assertSame('test-uuid', $this->entity->getUuid());
        $this->assertSame('B1', $this->entity->getCategory());
        $this->assertSame(5, $this->entity->getRetentionYears());
        $this->assertSame('vernietigen', $this->entity->getAction());
        $this->assertSame('Short retention', $this->entity->getDescription());
        $this->assertSame(['schema-1' => 10], $this->entity->getSchemaOverrides());
        $this->assertSame('gemeente-test', $this->entity->getOrganisation());
    }

    /**
     * Test jsonSerialize output.
     */
    public function testJsonSerialize(): void
    {
        $now = new DateTime();

        $this->entity->setUuid('uuid-1');
        $this->entity->setCategory('A1');
        $this->entity->setRetentionYears(10);
        $this->entity->setAction('bewaren');
        $this->entity->setDescription('Long retention');
        $this->entity->setCreated($now);

        $json = $this->entity->jsonSerialize();

        $this->assertSame('uuid-1', $json['id']);
        $this->assertSame('uuid-1', $json['uuid']);
        $this->assertSame('A1', $json['category']);
        $this->assertSame(10, $json['retentionYears']);
        $this->assertSame('bewaren', $json['action']);
        $this->assertSame('Long retention', $json['description']);
        $this->assertSame($now->format('c'), $json['created']);
    }

    /**
     * Test hydrate method.
     */
    public function testHydrate(): void
    {
        $this->entity->hydrate([
            'uuid'            => 'h-uuid',
            'category'        => 'C1',
            'retentionYears'  => 7,
            'action'          => 'vernietigen',
            'description'     => 'Medium retention',
            'schemaOverrides' => ['s1' => 15],
            'organisation'    => 'org-1',
        ]);

        $this->assertSame('h-uuid', $this->entity->getUuid());
        $this->assertSame('C1', $this->entity->getCategory());
        $this->assertSame(7, $this->entity->getRetentionYears());
        $this->assertSame('vernietigen', $this->entity->getAction());
    }

    /**
     * Test VALID_ACTIONS constant.
     */
    public function testValidActionsConstant(): void
    {
        $this->assertContains('vernietigen', SelectionList::VALID_ACTIONS);
        $this->assertContains('bewaren', SelectionList::VALID_ACTIONS);
        $this->assertCount(2, SelectionList::VALID_ACTIONS);
    }
}
