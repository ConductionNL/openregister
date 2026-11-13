<?php

/**
 * Golden for the edges RelationGraphService reads for one level
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Relation
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphSources;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The property edges and stored edges of one level, in order, for two languages.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Relation\RelationGraphService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\ObjectRelation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @uses   \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\DescribeRun
 * @uses \OCA\OpenRegister\Service\Relation\RelationGraphShapes
 * @uses \OCA\OpenRegister\Service\Relation\RelationGraphSources
 * @uses \OCA\OpenRegister\Service\Relation\RelationValueReader
 */
class RelationGraphEdgesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/relation-graph-edges-golden.json';

	/**
	 * The service over objects a (typed schema 2), b (schema 3, unreadable), c (no schema) and the stored rows.
	 *
	 * @return RelationGraphService
	 */
	private function service(): RelationGraphService {
		$relations = [
			'a' => ['parent' => 'b', 'tags.0' => 'c', 'tags.1' => 'a', 'many' => ['b', '', 7, 'c'], 0 => 'b', 'empty' => ''],
			'b' => ['parent' => ['a'], 'loose' => 'x'],
			'c' => ['parent' => 'a'],
		];
		$schemas = ['a' => 2, 'b' => 3, 'c' => 'none'];

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturnCallback(
			static function (mixed $identifier) use ($relations, $schemas): ObjectEntity {
				if (isset($relations[$identifier]) === false) {
					throw new \RuntimeException('no such object');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$identifier);
				$entity->setSchema((string)$schemas[$identifier]);
				$entity->setRelations($relations[$identifier]);
				return $entity;
			}
		);

		$typed = new Schema();
		$typed->setId(2);
		$typed->setProperties(
			[
				'parent' => [
					'type' => 'object',
					'$ref' => '#/components/schemas/x',
					RelationAnnotationValidator::PROPERTY_ANNOTATION => ['label' => ['nl' => 'ouder', 'en' => 'parent'], 'inverseLabel' => ['nl' => 'kind', 'en' => 'child']],
				],
				'tags'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/t']],
			]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (int $id) use ($typed): Schema {
				if ($id === 2) {
					return $typed;
				}

				throw new \RuntimeException('no such schema');
			}
		);

		$rows = $this->createMock(ObjectRelationMapper::class);
		$rows->method('findTouching')->willReturnCallback(
			static fn (array $uuids): array => array_values(array_filter(self::stored(), static fn (ObjectRelation $r): bool => in_array((string)$r->getSourceUuid(), $uuids, true) || in_array((string)$r->getTargetUuid(), $uuids, true)))
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		return new RelationGraphService(
			objectEntityMapper: $objects,
			sources: new RelationGraphSources(
				relationMapper: $rows,
				schemaMapper: $schemaMapper,
				relationTypes: new RelationTypeResolver()
			),
			appConfig: $appConfig,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * Stored rows: outgoing, incoming, external without a target uuid, and one with no label.
	 *
	 * @return array<int, ObjectRelation>
	 */
	private static function stored(): array {
		$rows = [];
		foreach (
			[
				['sourceUuid' => 'a', 'targetUuid' => 'z', 'relationType' => 'refers', 'label' => 'verwijst', 'inverseLabel' => 'verwezen', 'origin' => ObjectRelation::ORIGIN_MANUAL, 'kind' => ObjectRelation::KIND_OBJECT],
				['sourceUuid' => 'q', 'targetUuid' => 'b', 'relationType' => 'cites', 'origin' => ObjectRelation::ORIGIN_MANUAL, 'kind' => ObjectRelation::KIND_OBJECT],
				['sourceUuid' => 'c', 'targetUrl' => 'https://example.org/x', 'targetTitle' => 'X', 'label' => 'published', 'origin' => ObjectRelation::ORIGIN_MANUAL, 'kind' => ObjectRelation::KIND_EXTERNAL],
			] as $data
		) {
			$row = new ObjectRelation();
			$row->hydrate($data);
			$rows[] = $row;
		}

		return $rows;
	}//end stored()

	public function testTheEdgesOfALevelMatchTheGolden(): void {
		$service = $this->service();
		$edgesFrom = new ReflectionMethod(RelationGraphService::class, 'edgesFrom');
		$actual = [];
		foreach ([['a', 'b', 'c', 'missing'], ['b'], ['c', 'a']] as $i => $uuids) {
			foreach (['nl', 'en'] as $language) {
				$actual["$i-$language"] = $edgesFrom->invoke($service, $uuids, $language);
			}
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string) file_get_contents(self::FIXTURE), true), actual: $actual);
	}//end testTheEdgesOfALevelMatchTheGolden()
}//end class

