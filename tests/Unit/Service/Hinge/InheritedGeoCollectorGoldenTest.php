<?php

/**
 * Characterisation tests for InheritedGeoCollector.
 *
 * Pins the exact FeatureCollection for every geo shape and reference shape the
 * collector accepts, the order referenced records are read in, and the access
 * flags each read carries — allowed and refused — so restructuring the
 * collection cannot change what a caller is shown.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class InheritedGeoCollectorGoldenTest extends TestCase {

	private const POINT = ['type' => 'Point', 'coordinates' => [1, 2]];
	private const POLYGON = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]];

	/**
	 * Every read: identifier, _rbac, _multitenancy.
	 *
	 * @var array<int, array>
	 */
	private array $reads = [];

	private function object(string $uuid, array $data = [], mixed $geo = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		if (is_array($geo) === true) {
			$object->setGeo($geo);
		}

		return $object;
	}

	private function schema(array $sources): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getGeoInheritance')->willReturn($sources);
		return $schema;
	}

	/**
	 * @param array<string, ObjectEntity> $byId       Readable records by identifier.
	 * @param array<int, string>          $secretIds  Records only readable with `_rbac` off.
	 *
	 * @return InheritedGeoCollector The collector under test.
	 */
	private function collector(array $byId, array $secretIds = []): InheritedGeoCollector {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('find')->willReturnCallback(
			function (
				string|int $identifier,
				mixed $register = null,
				mixed $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use (
				$byId,
				$secretIds
			): ObjectEntity {
				$this->reads[] = [(string)$identifier, $_rbac, $_multitenancy];
				if (isset($byId[(string)$identifier]) === false) {
					throw new RuntimeException('not found');
				}

				if ($_rbac === true && in_array((string)$identifier, $secretIds, true) === true) {
					throw new RuntimeException('not readable');
				}

				return $byId[(string)$identifier];
			}
		);

		return new InheritedGeoCollector(magicMapper: $magicMapper, logger: new NullLogger());
	}

	public function testEveryGeoShapeIsFlattenedWithPurposeAndProvenance(): void {
		$referenced = [
			'bare' => $this->object('u-bare', [], self::POINT),
			'feat' => $this->object(
				'u-feat',
				[],
				['type' => 'Feature', 'geometry' => self::POLYGON, 'properties' => ['purpose' => 'perceel', 'x' => 1]]
			),
			'feat-noprops' => $this->object('u-np', [], ['type' => 'Feature', 'geometry' => self::POINT, 'properties' => 'bad']),
			'feat-nogeo' => $this->object('u-ng', [], ['type' => 'Feature', 'properties' => ['a' => 1]]),
			'coll' => $this->object(
				'u-coll',
				[],
				[
					'type' => 'FeatureCollection',
					'features' => [
						self::POINT,
						['type' => 'Feature', 'geometry' => self::POINT, 'properties' => ['purpose' => '']],
						'junk',
						[],
					],
				]
			),
			'list' => $this->object('u-list', [], [self::POLYGON, [self::POINT]]),
			'assoc' => $this->object('u-assoc', [], ['foo' => self::POINT]),
			'oddtype' => $this->object('u-odd', [], ['type' => 'Circle', 'radius' => 3]),
			'nogeo' => $this->object('u-nogeo'),
		];
		$data = [
			'a' => 'https://example.test/objects/bare/',
			'b' => ['id' => 'feat'],
			'c' => ['uuid' => 'feat-noprops'],
			'd' => [['id' => 'feat-nogeo'], 'coll', ['nested' => ['list']], 5, ''],
			'e' => ['assoc', 'oddtype', 'nogeo', 'missing'],
			'f' => ['id' => '', 'uuid' => 'list'],
		];
		$object = $this->object('u-self', $data, ['type' => 'Feature', 'geometry' => self::POINT, 'properties' => ['purpose' => 'perceel']]);
		$schema = $this->schema(
			[
				['through' => 'a'],
				['through' => 'b', 'label' => 'Besluit'],
				['through' => 'c'],
				['through' => 'd', 'label' => 'Lijst'],
				['through' => 'e'],
				['through' => 'f'],
				['through' => 'absent'],
			]
		);

		$result = $this->collector($referenced)->collect($object, $schema, true);

		$inherited = static fn (array $geometry, array $properties): array => [
			'type' => 'Feature',
			'geometry' => $geometry,
			'properties' => $properties,
		];
		$from = static fn (string $purpose, string $through, string $uuid, ?string $label = null, bool $superseded = false): array => array_merge(
			['_purpose' => $purpose, '_source' => 'inherited', '_through' => $through, '_fromObject' => $uuid],
			array_filter(['_relationLabel' => $label], static fn (?string $value): bool => $value !== null),
			['_superseded' => $superseded]
		);
		$this->assertSame(
			[
				'type' => 'FeatureCollection',
				'features' => [
					$inherited(self::POINT, ['purpose' => 'perceel', '_purpose' => 'perceel', '_source' => 'own', '_superseded' => false]),
					$inherited(self::POINT, $from('Point', 'a', 'u-bare')),
					$inherited(self::POLYGON, array_merge(['purpose' => 'perceel', 'x' => 1], $from('perceel', 'b', 'u-feat', 'Besluit', true))),
					$inherited(self::POINT, $from('Point', 'c', 'u-np')),
					$inherited(self::POINT, $from('Point', 'd', 'u-coll', 'Lijst')),
					$inherited(self::POINT, array_merge(['purpose' => ''], $from('Point', 'd', 'u-coll', 'Lijst'))),
					$inherited(self::POLYGON, $from('Polygon', 'd', 'u-list', 'Lijst')),
					$inherited(self::POINT, $from('Point', 'd', 'u-list', 'Lijst')),
					$inherited(self::POLYGON, $from('Polygon', 'f', 'u-list')),
					$inherited(self::POINT, $from('Point', 'f', 'u-list')),
				],
			],
			$result
		);
		$this->assertSame(
			['bare', 'feat', 'feat-noprops', 'feat-nogeo', 'coll', 'list', 'assoc', 'oddtype', 'nogeo', 'missing', 'list'],
			array_column($this->reads, 0)
		);
	}

	public function testAReferenceIsReadWithTheCallersAccessAndRefusedWhenUnreadable(): void {
		$referenced = ['secret' => $this->object('u-secret', [], self::POINT)];
		$object = $this->object('u-self', ['adres' => 'secret']);
		$schema = $this->schema([['through' => 'adres']]);

		$refused = $this->collector($referenced, ['secret'])->collect($object, $schema);
		$this->assertSame(['type' => 'FeatureCollection', 'features' => []], $refused);
		$this->assertSame([['secret', true, true]], $this->reads);

		$this->reads = [];
		$allowed = $this->collector($referenced, ['secret'])->collect($object, $schema, false);
		$this->assertCount(1, $allowed['features']);
		$this->assertSame('u-secret', $allowed['features'][0]['properties']['_fromObject']);
		$this->assertSame([['secret', false, false]], $this->reads);
	}

	public function testNoSchemaOrNoSourcesReadsNothing(): void {
		$object = $this->object('u-self', ['adres' => 'x'], self::POINT);

		$this->assertSame(
			[
				'type' => 'FeatureCollection',
				'features' => [
					[
						'type' => 'Feature',
						'geometry' => self::POINT,
						'properties' => ['_purpose' => 'Point', '_source' => 'own', '_superseded' => false],
					],
				],
			],
			$this->collector([])->collect($object, null)
		);
		$this->collector([])->collect($object, $this->schema([]));
		$this->assertSame([], $this->reads);
	}
}
