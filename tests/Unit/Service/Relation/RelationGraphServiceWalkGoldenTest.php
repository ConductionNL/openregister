<?php

/**
 * Golden test for RelationGraphService::graph() and export().
 *
 * Pins the whole graph and its export rows for walks over a cyclic, branching
 * object set with stored incoming and external links, across depths and node
 * caps: which nodes appear at which distance, which edges are listed once,
 * and which bound truncated the walk. Taken ahead of a unit-size refactor of
 * the walk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphSources;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\OpenRegister\Service\Relation\GraphExportOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden pins for the relation graph walk.
 */
class RelationGraphServiceWalkGoldenTest extends TestCase {

	/**
	 * Uuid => property => referenced uuid(s). `gone` is referenced but does not load.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const OBJECTS = [
		'r' => ['next' => ['a', 'b', '', 'r'], 'self' => 'a', 'num' => 5],
		'a' => ['next' => ['b', 'c'], 'back' => 'r'],
		'b' => ['next' => 'c', 'list' => [3, 'd']],
		'c' => ['next' => ['r', 'gone']],
		'd' => ['next' => []],
	];

	/**
	 * Build the service.
	 *
	 * @param int $maxDepth The depth ceiling.
	 * @param int $maxNodes The node cap.
	 *
	 * @return RelationGraphService The service.
	 */
	private function service(int $maxDepth, int $maxNodes): RelationGraphService {
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturnCallback(
			static function (mixed $identifier): ObjectEntity {
				$uuid = (string)$identifier;
				if (isset(self::OBJECTS[$uuid]) === false) {
					throw new RuntimeException('no such object');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setName('Object '.$uuid);
				$entity->setRegister(1);
				$entity->setSchema(2);
				$entity->setRelations(self::OBJECTS[$uuid]);

				return $entity;
			}
		);

		$relationMapper = $this->createMock(ObjectRelationMapper::class);
		$relationMapper->method('findTouching')->willReturnCallback(
			static fn (array $uuids): array => array_values(
				array: array_filter(
					array: self::stored(),
					callback: static fn (ObjectRelation $row): bool => in_array(needle: (string)$row->getSourceUuid(), haystack: $uuids, strict: true)
						|| in_array(needle: (string)$row->getTargetUuid(), haystack: $uuids, strict: true)
				)
			)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => match ($key) {
				RelationGraphService::MAX_DEPTH_KEY => $maxDepth,
				RelationGraphService::MAX_NODES_KEY => $maxNodes,
				default => $default,
			}
		);

		return new RelationGraphService(
			objectEntityMapper: $objectMapper,
			sources: new RelationGraphSources(
				relationMapper: $relationMapper,
				schemaMapper: $this->createMock(SchemaMapper::class),
				relationTypes: new RelationTypeResolver()
			),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * The stored relation rows: an incoming link into `a`, and external addresses off `b` and `r`.
	 *
	 * @return array<int, ObjectRelation> The rows.
	 */
	private static function stored(): array {
		$incoming = new ObjectRelation();
		$incoming->setSourceUuid('z');
		$incoming->setTargetUuid('a');
		$incoming->setRelationType('cites');
		$incoming->setOrigin('prose');
		$incoming->setKind(ObjectRelation::KIND_OBJECT);

		$external = new ObjectRelation();
		$external->setSourceUuid('b');
		$external->setTargetUrl('https://example.org/x');
		$external->setTargetTitle('Example');
		$external->setLabel('see also');
		$external->setOrigin('external');
		$external->setKind(ObjectRelation::KIND_EXTERNAL);

		$untitled = new ObjectRelation();
		$untitled->setSourceUuid('r');
		$untitled->setTargetUrl('https://example.org/y');
		$untitled->setRelationType('links');
		$untitled->setOrigin('external');
		$untitled->setKind(ObjectRelation::KIND_EXTERNAL);

		return [$incoming, $external, $untitled];
	}//end stored()

	/**
	 * Walks with their pinned md5 of graph and export.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
	 */
	public static function walkProvider(): array {
		return [
			'root depth one'      => ['r', 1, 3, 250],
			'root depth two'      => ['r', 2, 3, 250],
			'root depth three'    => ['r', 3, 3, 250],
			'root above maximum'  => ['r', 9, 2, 250],
			'root depth zero'     => ['r', 0, 3, 250],
			'cap of two'          => ['r', 3, 3, 2],
			'cap of four'         => ['r', 3, 3, 4],
			'cap of one'          => ['r', 3, 3, 1],
			'from b'              => ['b', 3, 5, 250],
			'from d'              => ['d', 2, 3, 250],
			'from missing'        => ['gone', 2, 3, 250],
			'from incoming end'   => ['z', 2, 3, 250],
		];
	}//end walkProvider()

	/**
	 * Every walk produces the pinned graph and export.
	 *
	 * @param string $root     The root uuid.
	 * @param int    $depth    The depth asked for.
	 * @param int    $maxDepth The depth ceiling.
	 * @param int    $maxNodes The node cap.
	 *
	 * @return void
	 */
	#[DataProvider('walkProvider')]
	public function testWalkMatchesThePin(string $root, int $depth, int $maxDepth, int $maxNodes): void {
		$export = $this->service(maxDepth: $maxDepth, maxNodes: $maxNodes)->export(rootUuid: $root, options: new GraphExportOptions(depth: $depth, language: 'en'));

		$actual = (string)json_encode(value: $export, flags: JSON_UNESCAPED_SLASHES);
		$this->assertSame(self::PINNED[$this->dataName()] ?? '', md5(string: $actual), $actual);
	}//end testWalkMatchesThePin()

	/**
	 * The md5 of the pinned export, per walk.
	 *
	 * @var array<string, string>
	 */
	private const PINNED = [
		'root depth one'     => '80133c9d4bcf805239cca5cd414f5d0a',
		'root depth two'     => '1ac19e9eed422b2de5be806f8c12458c',
		'root depth three'   => 'b48bc8fc405f1f2acacafa5e56bd9d4f',
		'root above maximum' => '16e472355a924e7e9641a453df105a34',
		'root depth zero'    => '80133c9d4bcf805239cca5cd414f5d0a',
		'cap of two'         => '70527e3bfaa37256b64c08f78ac2b62d',
		'cap of four'        => '25d00cb250f888a56b7a326e9c52c636',
		'cap of one'         => '8d8470b1b9ad8224bf6921ce22361e98',
		'from b'             => 'eaf575de5eeb72b9717f2e7568ab1d16',
		'from d'             => '3f62f6ac3b1a2f94be67bfb4d809fca3',
		'from missing'       => 'cb581588a80a9b63ef29edbf13c6f982',
		'from incoming end'  => 'efc26e1bde6c22a0604cd188b413af86',
	];
}//end class
