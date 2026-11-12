<?php

/**
 * A magic table row read back as an object.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicMapperRowToEntityTest extends TestCase {
	private const PROPERTIES = [
		'e-mailadres' => ['type' => 'string'],
		'_kenmerk' => ['type' => 'string'],
		'title' => ['type' => 'string'],
	];

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	private MagicMapper $mapper;

	protected function setUp(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$schema->method('getLinkedTypes')->willReturn([]);

		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willReturnCallback(
			static function (int $id) use ($schema): Schema {
				if ($id === 404) {
					throw new \RuntimeException('no such schema');
				}

				return $schema;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$reflection   = new ReflectionClass(MagicMapper::class);
		$this->mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('schemaMapper')->setValue($this->mapper, $this->schemas);
		$reflection->getProperty('logger')->setValue($this->mapper, $this->logger);
	}//end setUp()

	/**
	 * The entity a row gives.
	 *
	 * @param array $row The row.
	 *
	 * @return ObjectEntity|null The entity.
	 */
	private function entity(array $row): ?ObjectEntity {
		return (new ReflectionMethod(MagicMapper::class, 'rowToObjectEntity'))->invoke($this->mapper, $row);
	}//end entity()

	/**
	 * A row without a uuid gives no object.
	 *
	 * @return void
	 */
	public function testARowWithoutAUuidGivesNoObject(): void {
		$this->assertNull($this->entity(row: ['_name' => 'x']));
	}//end testARowWithoutAUuidGivesNoObject()

	/**
	 * The metadata columns set the entity, as strings for register and schema.
	 *
	 * @return void
	 */
	public function testTheMetadataColumnsSetTheEntity(): void {
		$entity = $this->entity(row: ['_uuid' => 'u-1', '_register' => 3, '_schema' => 7, '_name' => 'Naam']);

		$this->assertSame(['u-1', '3', '7', 'Naam'], [$entity->getUuid(), $entity->getRegister(), $entity->getSchema(), $entity->getName()]);
	}//end testTheMetadataColumnsSetTheEntity()

	/**
	 * Metadata that is absent stays unset.
	 *
	 * @return void
	 */
	public function testAbsentMetadataStaysUnset(): void {
		$entity = $this->entity(row: ['_uuid' => 'u-1']);

		$this->assertSame([null, null, null], [$entity->getRegister(), $entity->getSchema(), $entity->getName()]);
	}//end testAbsentMetadataStaysUnset()

	/**
	 * A column reads back under its property's name, an underscore property included.
	 *
	 * @return void
	 */
	public function testAColumnReadsBackUnderItsPropertyName(): void {
		$object = $this->entity(row: ['_uuid' => 'u-1', '_schema' => 7, 'e_mailadres' => 'a@b', '_kenmerk' => 'k', 'title' => 'T'])->getObject();

		$this->assertSame('a@b', $object['e-mailadres']);
		$this->assertSame('k', $object['_kenmerk']);
		$this->assertArrayNotHasKey('e_mailadres', $object);
	}//end testAColumnReadsBackUnderItsPropertyName()

	/**
	 * No metadata column of the table reads back as a property.
	 *
	 * @return void
	 */
	public function testNoMetadataColumnReadsBackAsAProperty(): void {
		$row = ['_uuid' => 'u-1', '_schema' => 7, 'title' => 'T'];
		foreach ($this->mapper->buildTableColumnsFromSchema(schema: $this->schemas->find(7)) as $name => $column) {
			if ($name !== '_kenmerk' && str_starts_with((string)$name, '_') === true) {
				$row[$column['name']] = 'stored';
			}
		}

		$object = $this->entity(row: $row)->getObject();

		$this->assertGreaterThan(20, count($row));
		$this->assertSame(['title'], array_values(array_filter(array_keys($object), static fn ($key): bool => $key !== 'id' && $object[$key] === 'T')));
		$this->assertNotContains('stored', array_diff_key($object, ['id' => 1]));
	}//end testNoMetadataColumnReadsBackAsAProperty()

	/**
	 * Without a schema a column keeps its own name, and a metadata name is left out.
	 *
	 * @return void
	 */
	public function testWithoutASchemaAColumnKeepsItsName(): void {
		$object = $this->entity(row: ['_uuid' => 'u-1', 'e_mailadres' => 'a@b', '_kenmerk' => 'k', '_name' => 'n'])->getObject();

		$this->assertSame('a@b', $object['e_mailadres']);
		$this->assertArrayNotHasKey('_name', $object);
	}//end testWithoutASchemaAColumnKeepsItsName()

	/**
	 * A schema that cannot be found is logged, and its columns keep their names.
	 *
	 * @return void
	 */
	public function testAMissingSchemaFallsBackToTheColumnNames(): void {
		$this->logger->expects($this->once())->method('debug')->with(
			message: '[MagicMapper] Could not load schema for property mapping',
			context: $this->callback(static fn (array $context): bool => $context['schemaId'] === 404 && $context['error'] === 'no such schema')
		);

		$object = $this->entity(row: ['_uuid' => 'u-1', '_schema' => 404, 'e_mailadres' => 'a@b'])->getObject();

		$this->assertSame('a@b', $object['e_mailadres']);
	}//end testAMissingSchemaFallsBackToTheColumnNames()

	/**
	 * The map of a schema is built once, and a failed one is asked for again.
	 *
	 * @return void
	 */
	public function testTheMapOfASchemaIsBuiltOnce(): void {
		$this->schemas->expects($this->exactly(3))->method('find');

		$this->entity(row: ['_uuid' => 'a', '_schema' => 7]);
		$this->entity(row: ['_uuid' => 'b', '_schema' => 7]);
		$this->entity(row: ['_uuid' => 'c', '_schema' => 404]);
		$this->entity(row: ['_uuid' => 'd', '_schema' => 404]);
	}//end testTheMapOfASchemaIsBuiltOnce()

	/**
	 * A string holding JSON is decoded, any other value stays as it is.
	 *
	 * @return void
	 */
	public function testAJsonStringIsDecoded(): void {
		$object = $this->entity(row: ['_uuid' => 'u-1', 'list' => '["a"]', 'text' => 'open', 'number' => 4, 'none' => null])->getObject();

		$this->assertSame(['a'], $object['list']);
		$this->assertSame('open', $object['text']);
		$this->assertSame(4, $object['number']);
		$this->assertArrayHasKey('none', $object);
	}//end testAJsonStringIsDecoded()
}//end class
