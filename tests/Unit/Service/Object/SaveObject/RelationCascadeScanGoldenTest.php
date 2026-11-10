<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScan;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pins RelationCascadeHandler's relation scanning, reference resolution in
 * object data, cascading of inversedBy properties and its isReference rule
 * against fixtures/relation-cascade-scan-golden.json. UPDATE_GOLDEN=1 rewrites it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class RelationCascadeScanGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/relation-cascade-scan-golden.json';

	private const UUID = '21aab6e0-2177-4920-beb0-391492fed04b';

	private const UUID2 = '6f1c2a3b-4d5e-4f60-8a71-92b3c4d5e6f7';

	public function testScanResolveAndCascadeMatchGolden(): void {
		$handler = $this->handler();
		$actual = [
			'scan' => $this->scans(handler: $handler),
			'update' => $this->updates(handler: $handler),
			'cascade' => $this->cascades(handler: $handler),
			'isReference' => $this->references(handler: $handler),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testScanResolveAndCascadeMatchGolden()

	private function scans(RelationCascadeHandler $handler): array {
		$schema = $this->schema(properties: [
			'owner' => ['$ref' => '#/components/schemas/Person'],
			'code' => ['type' => 'string'],
			'nested' => ['inner' => ['$ref' => 'x']],
			'flat' => 'scalar-definition',
		]);
		$data = [
			'_self' => ['id' => self::UUID],
			'_schema' => '5',
			'_register' => '7',
			'owner' => '12',
			'code' => 'https://h.example/api/things',
			'title' => 'Plain title',
			'date' => '2026-01-01',
			'list' => ['a', self::UUID, 'b'],
			'scalars' => ['a', 'b'],
			'nested' => ['inner' => 'https://h.example/api/x', 'deeper' => ['ref' => 'abc/objects/' . self::UUID]],
			'numbers' => '0',
			'negative' => '-4',
			'number' => 42,
			'empty' => [],
			'prefixed' => 'id-' . self::UUID,
		];

		$out = [
			'noSchema' => $handler->scanForRelations($data),
			'withSchema' => $handler->scanForRelations($data, '', $schema),
			'prefixed' => $handler->scanForRelations($data, 'root'),
			'intKeys' => $handler->scanForRelations([0 => self::UUID, 1 => 'plain', 2 => '15']),
			'intKeysPrefixed' => $handler->scanForRelations([0 => self::UUID], 'list'),
			'intKeyArrayWithSchema' => $this->attempt(fn () => $handler->scanForRelations([0 => [self::UUID]], '', $schema)),
			'intKeyNestedNoSchema' => $this->attempt(fn () => $handler->scanForRelations([0 => ['x' => ['y' => self::UUID]]])),
			'flatDefinition' => $this->attempt(fn () => $handler->scanForRelations(['flat' => ['sub' => self::UUID]], '', $schema)),
		];

		return $out;
	}//end scans()

	private function updates(RelationCascadeHandler $handler): array {
		$cases = [
			'mixed' => [
				'data' => ['a' => '12', 'b' => [self::UUID, '404', 'x/objects/' . self::UUID2, 'x/objects/nope'], 'c' => ['d' => '12']],
				'object' => ['a' => '12', 'b' => [self::UUID, '404', 'x/objects/' . self::UUID2, 'x/objects/nope', 5], 'c' => ['d' => '12']],
			],
			'missing' => [
				'data' => ['a' => '12', 'c' => ['d' => '12']],
				'object' => ['z' => 1],
			],
			'noRelations' => [
				'data' => ['a' => 'plain'],
				'object' => ['a' => 'plain'],
			],
			'unresolvable' => [
				'data' => ['a' => '404', 'b' => 'x/objects/a/objects/' . self::UUID],
				'object' => ['a' => '404', 'b' => 'x/objects/a/objects/' . self::UUID],
			],
			'nonStringTarget' => [
				'data' => ['a' => '12'],
				'object' => ['a' => 12],
			],
			'nullIntermediate' => [
				'data' => ['c' => ['d' => '12']],
				'object' => ['c' => null],
			],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$entity = new ObjectEntity();
			$entity->setObject($case['object']);
			$out[$name] = $this->attempt(fn () => $handler->updateObjectRelations($entity, new RelationScan(data: $case['data']))->getObject());
		}

		return $out;
	}//end updates()

	private function cascades(RelationCascadeHandler $handler): array {
		$schema = $this->schema(properties: [
			'children' => ['type' => 'array', 'inversedBy' => 'parent'],
			'child' => ['type' => 'object', 'inversedBy' => 'parent'],
			'scalarChild' => ['inversedBy' => 'parent'],
			'stringChild' => ['inversedBy' => 'parent'],
			'emptyChild' => ['inversedBy' => 'parent'],
			'plain' => ['type' => 'string'],
			'absent' => ['inversedBy' => 'parent'],
		]);
		$data = [
			'children' => [['name' => 'a'], self::UUID, 'not-a-uuid', ['x', 'y'], [['deep' => 1]], 5],
			'child' => ['name' => 'b'],
			'scalarChild' => ['x', 'y'],
			'stringChild' => 'text',
			'emptyChild' => [],
			'plain' => 'keep',
		];


		$arraySchema = $this->schema(properties: ['l' => ['type' => 'array', 'inversedBy' => 'p']]);

		return [
			'cascade' => $this->attempt(fn () => $handler->cascadeObjects($schema, $data)),
			'arrayString' => $this->attempt(fn () => $handler->cascadeObjects($arraySchema, ['l' => 'text'])),
			'multiple' => $handler->cascadeMultipleObjects([self::UUID, ['a' => 1], ['a', 'b'], 'x', null, self::UUID2]),
		];
	}//end cascades()

	private function references(RelationCascadeHandler $handler): array {
		$out = [];
		$values = [self::UUID, 'id-' . self::UUID, str_replace('-', '', self::UUID), 'a/objects/b', 'a/api/b', '12', '0', '-3', '1.5', '1e3', 'x', ''];
		foreach ($values as $value) {
			$out[$value] = $handler->isReference($value);
		}

		return $out;
	}//end references()

	private function attempt(callable $call): mixed {
		try {
			return $call();
		} catch (Throwable $e) {
			return ['threw' => get_class($e)];
		}
	}//end attempt()

	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	private function handler(): RelationCascadeHandler {
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('find')->willReturnCallback(function ($id) {
			if ($id === 12) {
				$entity = new ObjectEntity();
				$entity->setUuid(self::UUID2);
				return $entity;
			}

			throw new DoesNotExistException('no');
		});

		return new RelationCascadeHandler(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->createMock(SchemaMapperInterface::class), tables: $objects), logger: new NullLogger());
	}//end handler()
}//end class
