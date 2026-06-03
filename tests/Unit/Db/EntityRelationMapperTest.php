<?php

namespace Unit\Db;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EntityRelationMapper bases-column behaviour.
 *
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md#task-4.1
 */
class EntityRelationMapperTest extends TestCase
{

    private EntityRelationMapper $mapper;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->mapper = new EntityRelationMapper(db: $this->db);
    }

    public function testMapperInstantiates(): void
    {
        $this->assertInstanceOf(EntityRelationMapper::class, $this->mapper);
    }

    public function testEntityRelationHasBasesColumn(): void
    {
        $relation = new EntityRelation();
        $types    = $relation->getFieldTypes();

        $this->assertArrayHasKey('bases', $types);
        $this->assertSame('json', $types['bases']);
    }

    public function testEntityRelationBasesRoundTrip(): void
    {
        $relation = new EntityRelation();
        $bases    = ['uuid-a', 'uuid-b'];

        $relation->setBases($bases);

        $this->assertSame($bases, $relation->getBases());
    }

    public function testEntityRelationNullBasesDistinctFromEmptyArray(): void
    {
        $relation = new EntityRelation();

        $relation->setBases(null);
        $this->assertNull($relation->getBases());

        $relation->setBases([]);
        $this->assertSame([], $relation->getBases());
        $this->assertNotNull($relation->getBases());
    }

    public function testEntityRelationAcceptsNonUuidStrings(): void
    {
        $relation = new EntityRelation();
        $bases    = ['not-a-uuid', '12345', ''];

        $relation->setBases($bases);

        $this->assertSame($bases, $relation->getBases());
    }

    public function testJsonSerializeIncludesBases(): void
    {
        $relation = new EntityRelation();
        $bases    = ['uuid-a'];
        $relation->setBases($bases);

        $serialized = $relation->jsonSerialize();

        $this->assertArrayHasKey('bases', $serialized);
        $this->assertSame($bases, $serialized['bases']);
    }

    public function testJsonSerializeBasesNullByDefault(): void
    {
        $relation   = new EntityRelation();
        $serialized = $relation->jsonSerialize();

        $this->assertArrayHasKey('bases', $serialized);
        $this->assertNull($serialized['bases']);
    }

    public function testJsonSerializeEmptyBases(): void
    {
        $relation = new EntityRelation();
        $relation->setBases([]);

        $serialized = $relation->jsonSerialize();

        $this->assertSame([], $serialized['bases']);
    }
}//end class
