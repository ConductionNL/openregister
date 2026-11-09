<?php

/**
 * Golden test for ObjectTreeService::tree() access outcomes.
 *
 * Pins the whole answer, node for node, for a matrix of callers (may read
 * everything, may read part of the tree, may read nothing below the root,
 * whose access check fails, and a read without RBAC) over every direction,
 * depth bound and node cap, together with what the redaction is asked to do.
 * Both what a caller is shown and what it is refused are pinned. Taken ahead
 * of a unit-size refactor of the walk.
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
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Relation\ObjectTreeMappers;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphSources;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden pins for what the tree walk shows and refuses.
 */
class ObjectTreeServiceAccessGoldenTest extends TestCase {

	/**
	 * Uuid => parent uuid: a root `m` with an ancestor chain above and a cycle-free tree below.
	 *
	 * @var array<string, string|null>
	 */
	private const TABLE = [
		'top' => null,
		'up2' => 'top',
		'up1' => 'up2',
		'm'   => 'up1',
		'a'   => 'm',
		'b'   => 'm',
		'c'   => 'm',
		'a1'  => 'a',
		'a2'  => 'a',
		'b1'  => 'b',
		'a11' => 'a1',
		'x'   => 'a11',
		'cyc' => 'cyc2',
		'cyc2' => 'cyc',
	];

	/**
	 * Every call the doubles saw, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the service over TABLE.
	 *
	 * @param array<int, string>|null $readable    The uuids the caller may read; null = all.
	 * @param bool                    $accessFails Whether the access check fails.
	 * @param int                     $cap         The node cap.
	 * @param bool                    $levelFails  Whether reading a level of children throws.
	 *
	 * @return ObjectTreeService The service.
	 */
	private function service(?array $readable, bool $accessFails, int $cap, bool $levelFails): ObjectTreeService {
		$logger    = $this->createMock(LoggerInterface::class);
		$descender = $this->getMockBuilder(HierarchyDescender::class)
			->disableOriginalConstructor()
			->onlyMethods(['childrenOf'])
			->getMock();
		$descender->method('childrenOf')->willReturnCallback(
			function (string $table, string $parentColumn, array $parentUuids, ?int $limit = null) use ($levelFails): array {
				$this->calls[] = 'children '.implode(separator: ',', array: $parentUuids).' limit '.(string)$limit;
				if ($levelFails === true) {
					throw new RuntimeException('level unreadable');
				}

				$children = [];
				foreach (self::TABLE as $child => $parent) {
					if ($parent !== null && in_array(needle: $parent, haystack: $parentUuids, strict: true) === true) {
						$children[$child] = $parent;
					}
				}

				return array_slice(array: $children, offset: 0, length: $limit, preserve_keys: true);
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('readableUuidsOrNull')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids) use ($readable, $accessFails): ?array {
				$this->calls[] = 'access '.implode(separator: ',', array: $uuids);
				if ($accessFails === true) {
					return null;
				}

				return array_values(
					array: array_filter(
						array: $uuids,
						callback: static fn (string $uuid): bool => $readable === null || in_array(needle: $uuid, haystack: $readable, strict: true)
					)
				);
			}
		);
		$objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema, array $uuids): array {
				$this->calls[] = 'load '.implode(separator: ',', array: $uuids);
				$found = [];
				foreach ($uuids as $uuid) {
					if (array_key_exists(key: $uuid, array: self::TABLE) === true) {
						$found[$uuid] = self::entity(uuid: $uuid);
					}
				}

				return $found;
			}
		);

		$renderer = $this->createMock(RenderObject::class);
		$renderer->method('redactWriteOnlyFromRows')->willReturnCallback(
			function (array &$rows, bool $_rbac = true): void {
				$this->calls[] = 'redact '.implode(separator: ',', array: array_keys(array: $rows)).' rbac '.var_export(value: $_rbac, return: true);
				foreach ($rows as $row) {
					$data = $row->getObject();
					unset($data['secret']);
					$row->setObject($data);
				}
			}
		);

		$register = new Register();
		$register->setId(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(self::schema());

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($cap): int {
				if ($key === RelationGraphService::MAX_NODES_KEY) {
					return $cap;
				}

				return $default;
			}
		);
		$graphs = new RelationGraphService(
			objectEntityMapper: $objectMapper,
			sources: new RelationGraphSources(
				relationMapper: $this->createMock(ObjectRelationMapper::class),
				schemaMapper: $schemaMapper,
				relationTypes: $this->createMock(RelationTypeResolver::class)
			),
			appConfig: $appConfig,
			logger: $logger
		);

		return $this->build(
			collaborators: [
				'descender' => $descender,
				'hierarchies' => new HierarchyGrantExpander(descender: $descender, logger: $logger),
				'objectMapper' => $objectMapper,
				'registerMapper' => $registerMapper,
				'schemaMapper' => $schemaMapper,
				'graphs' => $graphs,
				'renderer' => $renderer,
				'logger' => $logger,
			]
		);
	}//end service()

	/**
	 * Construct the service from its collaborators.
	 *
	 * @param array<string, mixed> $collaborators The collaborators, by role.
	 *
	 * @return ObjectTreeService The service.
	 */
	private function build(array $collaborators): ObjectTreeService {
		return new ObjectTreeService(
			descender: $collaborators['descender'],
			hierarchies: $collaborators['hierarchies'],
			mappers: new ObjectTreeMappers(
				objectMapper: $collaborators['objectMapper'],
				registerMapper: $collaborators['registerMapper'],
				schemaMapper: $collaborators['schemaMapper']
			),
			graphs: $collaborators['graphs'],
			renderer: $collaborators['renderer'],
			logger: $collaborators['logger']
		);
	}//end build()

	/**
	 * The organisation schema, its parent property declared by the party annotation.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('organisation');
		$schema->setProperties(
			[
				'tradeName' => ['type' => 'string'],
				'secret' => ['type' => 'string'],
				'parentOrganisation' => ['type' => 'string', 'format' => 'uuid', '$ref' => '#/components/schemas/organisation'],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-party' => [
					'kind' => 'organisation',
					'nameProperty' => 'tradeName',
					'parentProperty' => 'parentOrganisation',
					'maxDepth' => 3,
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * One object of TABLE.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setName('Org '.$uuid);
		$entity->setRegister('1');
		$entity->setSchema('2');
		$parent = self::TABLE[$uuid];
		if ($uuid === 'a1') {
			$parent = ['uuid' => ' a '];
		}

		$entity->setObject(['tradeName' => 'T '.$uuid, 'secret' => 's '.$uuid, 'parentOrganisation' => $parent]);

		return $entity;
	}//end entity()

	/**
	 * The scenario matrix: caller, root, direction, depth, fields and cap, with the pinned md5 of answer and calls.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarioProvider(): array {
		$both = ObjectTreeService::DIRECTION_BOTH;
		$down = ObjectTreeService::DIRECTION_DESCENDANTS;
		$up   = ObjectTreeService::DIRECTION_ANCESTORS;
		$part = ['m', 'a', 'a1', 'a11', 'b1', 'up1', 'top'];
		return [
			'all readable both'           => [null, false, 250, false, 'm', $both, null, [], true],
			'all readable fields'         => [null, false, 250, false, 'm', $both, null, ['tradeName', 'secret'], true],
			'part readable both'          => [$part, false, 250, false, 'm', $both, null, ['secret'], true],
			'part readable deep'          => [$part, false, 250, false, 'm', $down, 9, [], true],
			'nothing readable'            => [['m'], false, 250, false, 'm', $both, null, ['tradeName'], true],
			'access check fails'          => [null, true, 250, false, 'm', $both, null, [], true],
			'access fails no rbac'        => [null, true, 250, false, 'm', $both, null, ['secret'], false],
			'nothing readable no rbac'    => [[], false, 250, false, 'm', $both, null, ['secret'], false],
			'level read fails'            => [null, false, 250, true, 'm', $down, null, [], true],
			'level read fails ancestors'  => [null, false, 250, true, 'm', $up, null, [], true],
			'cap of two'                  => [null, false, 2, false, 'm', $down, null, [], true],
			'cap of four'                 => [null, false, 4, false, 'm', $both, 3, [], true],
			'cap exhausted exactly'       => [null, false, 7, false, 'm', $down, 2, [], true],
			'cap one'                     => [null, false, 1, false, 'm', $down, null, [], true],
			'depth one'                   => [null, false, 250, false, 'm', $both, 1, [], true],
			'depth zero asked'            => [null, false, 250, false, 'm', $down, 0, [], true],
			'depth above declared'        => [null, false, 250, false, 'm', $up, 50, [], true],
			'ancestors stop at hidden'    => [['m', 'up1', 'top'], false, 250, false, 'm', $up, null, [], true],
			'ancestors at depth readable' => [null, false, 250, false, 'a11', $up, 2, [], true],
			'cycle'                       => [null, false, 250, false, 'cyc', $both, null, [], true],
			'leaf'                        => [null, false, 250, false, 'x', $down, null, [], true],
			'object parent reference'     => [null, false, 250, false, 'a11', $up, null, ['tradeName'], true],
		];
	}//end scenarioProvider()

	/**
	 * Every scenario answers, and asks its doubles, exactly what is pinned.
	 *
	 * @param array<int, string>|null $readable    The uuids the caller may read.
	 * @param bool                    $accessFails Whether the access check fails.
	 * @param int                     $cap         The node cap.
	 * @param bool                    $levelFails  Whether reading a level throws.
	 * @param string                  $root        The root uuid.
	 * @param string                  $direction   The direction.
	 * @param int|null                $depth       The depth asked for.
	 * @param array<int, string>      $fields      The fields asked for.
	 * @param bool                    $rbac        Whether RBAC applies.
	 *
	 * @return void
	 */
	#[DataProvider('scenarioProvider')]
	public function testScenarioMatchesThePin(
		?array $readable,
		bool $accessFails,
		int $cap,
		bool $levelFails,
		string $root,
		string $direction,
		?int $depth,
		array $fields,
		bool $rbac
	): void {
		$tree = $this->service(readable: $readable, accessFails: $accessFails, cap: $cap, levelFails: $levelFails)->tree(
			root: self::entity(uuid: $root),
			direction: $direction,
			depth: $depth,
			fields: $fields,
			_rbac: $rbac
		);

		$actual = (string)json_encode(value: ['tree' => $tree, 'calls' => $this->calls], flags: JSON_UNESCAPED_SLASHES);
		$this->assertSame(self::PINNED[$this->dataName()] ?? '', md5(string: $actual), $actual);
	}//end testScenarioMatchesThePin()

	/**
	 * The md5 of the pinned answer-and-calls rendering, per scenario.
	 *
	 * @var array<string, string>
	 */
	private const PINNED = [
		'all readable both'           => 'ac1b1a3ea485e8568e977d7bf7a2852e',
		'all readable fields'         => '6518a4c40604cb58df421b3b82229080',
		'part readable both'          => '1845267c5531339b18a7d27ce6a7a940',
		'part readable deep'          => '0b202818334b6ae8913db934a8a25bb8',
		'nothing readable'            => '1cc88aa299cb588e609a9abc975c37ce',
		'access check fails'          => '13abd61c01f00c239d2e7453e6e27c1d',
		'access fails no rbac'        => 'e577fec48e496f66114607d21d5bf82b',
		'nothing readable no rbac'    => 'e577fec48e496f66114607d21d5bf82b',
		'level read fails'            => '52a7aa7b4b1d98f57e18cb24635f38aa',
		'level read fails ancestors'  => 'beba917e1ef7d93436eb7df4ff34cd20',
		'cap of two'                  => '3103c7ca427769a6a489f9283bc62373',
		'cap of four'                 => 'f61398505e0af1aaba870eb01130b773',
		'cap exhausted exactly'       => 'cf53861c463f5498a9d9934007d17361',
		'cap one'                     => '550aa8b0b3d431b29a333e5b425f1676',
		'depth one'                   => '28efabf3abc8e0c83c691f2e7f4e19e6',
		'depth zero asked'            => 'a73baf140980df30e26168946e4f7410',
		'depth above declared'        => '484d44ae7671949acf1ba28c292a8e9c',
		'ancestors stop at hidden'    => 'ae9560d566bffe395aa066e83eb64180',
		'ancestors at depth readable' => '3fcc4d50528919041b280a5b4c3b93b6',
		'cycle'                       => '3544f9c553b816bae98966c673b1c249',
		'leaf'                        => '2a1dfbcc98f869ea2099834af82e1b9d',
		'object parent reference'     => 'a5d03ab7a77bee496a576caef899024d',
	];
}//end class
