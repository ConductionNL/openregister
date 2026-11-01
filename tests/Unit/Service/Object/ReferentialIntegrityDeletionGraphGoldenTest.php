<?php

/**
 * The deletion graph walk of ReferentialIntegrityService gives the recorded analysis for a fixed set of graphs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of canDelete() over graphs that use every onDelete action, a chain, a cycle,
 * a soft-deleted dependent, a lookup that fails and a chain deeper than the depth limit and an unknown onDelete value. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses   \OCA\OpenRegister\Service\Object\ReferenceKey
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphNode
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphStep
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphWalk
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphNode
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphStep
 * @uses   \OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphWalk
 */
class ReferentialIntegrityDeletionGraphGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/referential-integrity-deletion-graph.json';

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $objectMapper;

	private LoggerInterface&MockObject $logger;

	private ReferentialIntegrityService $service;

	/**
	 * Build the service over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
		$cacheFactory       = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$this->service = new ReferentialIntegrityService(
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->objectMapper,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			logger: $this->logger,
			db: $this->createMock(IDBConnection::class),
			cacheFactory: $cacheFactory,
			archivalGuard: $this->createMock(ArchivalRetentionGuard::class)
		);
	}//end setUp()

	/**
	 * Every scenario's analysis matches the recorded golden.
	 *
	 * @return void
	 */
	public function testAnalysesMatchTheGolden(): void {
		$actual = [
			'mixed'      => $this->analyse(start: 'person-uuid'),
			'contact'    => $this->analyse(start: 'contact-uuid'),
			'cycle'      => $this->analyse(start: 'a-uuid'),
			'unverified' => $this->analyse(start: 'broken-uuid'),
			'deep'       => $this->analyse(start: 'chain-0'),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);

		// An unknown onDelete value adds no target and no blocker.
		$this->assertStringNotContainsString(needle: 'odd-uuid', haystack: (string) json_encode($actual['mixed']));
	}//end testAnalysesMatchTheGolden()

	/**
	 * The analysis of deleting one object of the fixed graph, as an array.
	 *
	 * @param string $start The uuid of the object to delete.
	 *
	 * @return array<string, mixed>
	 */
	private function analyse(string $start): array {
		$this->schemaMapper->method('findAll')->willReturn($this->schemas());
		$objects = $this->objects();
		$this->objectMapper->method('findByRelation')->willReturnCallback(
			function (string $uuid) use ($objects) {
				if ($uuid === 'broken-uuid') {
					throw new RuntimeException('lookup failed');
				}

				return array_values($objects);
			}
		);

		return $this->service->canDelete($objects[$start])->toArray();
	}//end analyse()

	/**
	 * The schemas: person (1) is cited by every action, contact (2) by a chain, a and b cite each other.
	 *
	 * @return Schema[]
	 */
	private function schemas(): array {
		return [
			$this->schema(id: 1, properties: []),
			$this->schema(id: 2, properties: ['person' => ['$ref' => '1', 'onDelete' => 'CASCADE']]),
			$this->schema(id: 3, properties: ['assignee' => ['$ref' => '1', 'onDelete' => 'SET_NULL'], 'owner' => ['$ref' => '1', 'onDelete' => 'SET_NULL']], required: ['owner']),
			$this->schema(id: 4, properties: ['manager' => ['$ref' => '1', 'onDelete' => 'RESTRICT']]),
			$this->schema(
				id: 5,
				properties: [
					'fallback' => ['$ref' => '1', 'onDelete' => 'SET_DEFAULT', 'default' => 'fallback-uuid'],
					'loose'    => ['$ref' => '1', 'onDelete' => 'SET_DEFAULT'],
					'strict'   => ['$ref' => '1', 'onDelete' => 'SET_DEFAULT'],
				],
				required: ['strict']
			),
			$this->schema(id: 6, properties: ['contact' => ['$ref' => '2', 'onDelete' => 'CASCADE'], 'tags' => ['type' => 'array', 'items' => ['$ref' => '1'], 'onDelete' => 'SET_NULL']]),
			$this->schema(id: 7, properties: ['contact' => ['$ref' => '2', 'onDelete' => 'RESTRICT']]),
			$this->schema(id: 8, properties: ['peer' => ['$ref' => '9', 'onDelete' => 'CASCADE']]),
			$this->schema(id: 9, properties: ['peer' => ['$ref' => '8', 'onDelete' => 'CASCADE']]),
			$this->schema(id: 10, properties: ['parent' => ['$ref' => '11', 'onDelete' => 'RESTRICT']]),
			$this->schema(id: 11, properties: []),
			$this->schema(id: 12, properties: ['parent' => ['$ref' => '12', 'onDelete' => 'CASCADE']]),
			$this->schema(id: 13, properties: ['odd' => ['$ref' => '1', 'onDelete' => 'BOGUS']]),
		];
	}//end schemas()

	/**
	 * The objects of the graph, by uuid.
	 *
	 * @return array<string, ObjectEntity>
	 */
	private function objects(): array {
		$list = [
			$this->object(uuid: 'person-uuid', schema: '1', data: []),
			$this->object(uuid: 'contact-uuid', schema: '2', data: ['person' => 'person-uuid']),
			$this->object(uuid: 'contact2-uuid', schema: '2', data: ['person' => 'person-uuid']),
			$this->object(uuid: 'order-uuid', schema: '3', data: ['assignee' => 'person-uuid', 'owner' => 'person-uuid']),
			$this->object(uuid: 'service-uuid', schema: '4', data: ['manager' => 'person-uuid']),
			$this->object(uuid: 'gone-uuid', schema: '4', data: ['manager' => 'person-uuid'], deleted: ['deletedBy' => 'admin']),
			$this->object(uuid: 'item-uuid', schema: '5', data: ['fallback' => 'person-uuid', 'loose' => 'person-uuid', 'strict' => 'person-uuid']),
			$this->object(uuid: 'phone-uuid', schema: '6', data: ['contact' => 'contact-uuid', 'tags' => ['person-uuid', 'x']]),
			$this->object(uuid: 'audit-uuid', schema: '7', data: ['contact' => 'contact2-uuid']),
			$this->object(uuid: 'a-uuid', schema: '8', data: ['peer' => 'b-uuid']),
			$this->object(uuid: 'b-uuid', schema: '9', data: ['peer' => 'a-uuid']),
			$this->object(uuid: 'broken-uuid', schema: '11', data: []),
			$this->object(uuid: 'odd-uuid', schema: '13', data: ['odd' => 'person-uuid']),
		];
		for ($i = 0; $i <= 12; $i++) {
			$list[] = $this->object(uuid: 'chain-' . $i, schema: '12', data: ['parent' => 'chain-' . ($i - 1)]);
		}

		$byUuid = [];
		foreach ($list as $object) {
			$byUuid[$object->getUuid()] = $object;
		}

		return $byUuid;
	}//end objects()

	/**
	 * A schema with the given properties.
	 *
	 * @param int                  $id         The schema id.
	 * @param array<string, mixed> $properties The properties.
	 * @param string[]             $required   The required property names.
	 *
	 * @return Schema
	 */
	private function schema(int $id, array $properties, array $required = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema-' . $id);
		$schema->setUuid('uuid-schema-' . $id);
		$schema->setProperties($properties);
		$schema->setRequired($required);
		return $schema;
	}//end schema()

	/**
	 * An object of a schema.
	 *
	 * @param string                    $uuid    The uuid.
	 * @param string                    $schema  The schema id.
	 * @param array<string, mixed>      $data    The object data.
	 * @param array<string, mixed>|null $deleted The deletion marker, if soft-deleted.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $schema, array $data, ?array $deleted = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setRegister('1');
		$entity->setObject($data);
		if ($deleted !== null) {
			$entity->setDeleted($deleted);
		}

		return $entity;
	}//end object()
}//end class
