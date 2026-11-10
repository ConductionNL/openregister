<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\VocabularyObjectStore;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Golden test for VocabularyObjectStore: pins the exact findAll configs and
 * saveObject calls of the upsert, save and deprecation-sweep paths.
 *
 * @covers \OCA\OpenRegister\Service\VocabularyObjectStore
 */
final class VocabularyObjectStoreGoldenTest extends TestCase {

	/** @var array<int,array> Every call made on the object service, in order */
	private array $calls = [];

	/** @var array<int,array<int,ObjectEntity>> Queued findAll results, in order */
	private array $pages = [];

	/** @var VocabularyObjectStore The store under test */
	private VocabularyObjectStore $store;

	protected function setUp(): void {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$service->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->calls[] = ['findAll', $config];
				return array_shift($this->pages) ?? [];
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->calls[] = ['saveObject', $object, $register, $schema, $uuid];
				return $this->entity($uuid ?? 'new-uuid', $object);
			}
		);
		$this->store = new VocabularyObjectStore($service, 'concept');
	}

	private function entity(string $uuid, ?array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);
		return $entity;
	}

	private function uriLookup(string $uri): array {
		return ['findAll', ['filters' => ['register' => 'vocabulary', 'schema' => 'concept', 'uri' => $uri], 'limit' => 1]];
	}

	public function testUpsertCreatesWhenAbsent(): void {
		$result = $this->store->upsertByUri(['uri' => 'u:1', 'label' => 'One']);

		$this->assertSame(['status' => 'created', 'uuid' => 'new-uuid'], $result);
		$this->assertSame(
			[$this->uriLookup('u:1'), ['saveObject', ['uri' => 'u:1', 'label' => 'One'], 'vocabulary', 'concept', null]],
			$this->calls
		);
	}

	public function testUpsertLeavesUnchangedObjectAlone(): void {
		$this->pages[] = [$this->entity('e-1', ['uri' => 'u:1', 'label' => 'One', 'extra' => 'x'])];

		$result = $this->store->upsertByUri(['uri' => 'u:1', 'label' => 'One']);

		$this->assertSame(['status' => 'unchanged', 'uuid' => 'e-1'], $result);
		$this->assertSame([$this->uriLookup('u:1')], $this->calls);
	}

	public function testUpsertTreatsMissingKeyAsNullAndStrictlyCompares(): void {
		$this->pages[] = [$this->entity('e-1', ['uri' => 'u:1', 'rank' => '1'])];
		$this->assertSame('unchanged', $this->store->upsertByUri(['uri' => 'u:1', 'note' => null])['status']);

		$this->pages[] = [$this->entity('e-1', ['uri' => 'u:1', 'rank' => '1'])];
		$this->assertSame('updated', $this->store->upsertByUri(['uri' => 'u:1', 'rank' => 1])['status']);
	}

	public function testUpsertMergesExistingFieldsOnUpdate(): void {
		$this->pages[] = [$this->entity('e-1', ['uri' => 'u:1', 'label' => 'Old', 'extra' => 'x'])];

		$result = $this->store->upsertByUri(['uri' => 'u:1', 'label' => 'New']);

		$this->assertSame(['status' => 'updated', 'uuid' => 'e-1'], $result);
		$this->assertSame(
			[
				$this->uriLookup('u:1'),
				['saveObject', ['id' => 'e-1', 'uri' => 'u:1', 'label' => 'New', 'extra' => 'x'], 'vocabulary', 'concept', 'e-1'],
			],
			$this->calls
		);
	}

	public function testUpsertOnObjectWithoutData(): void {
		$this->pages[] = [$this->entity('e-2', null)];

		$this->assertSame(['status' => 'updated', 'uuid' => 'e-2'], $this->store->upsertByUri(['uri' => 'u:2']));
		$this->assertSame(['saveObject', ['id' => 'e-2', 'uri' => 'u:2'], 'vocabulary', 'concept', 'e-2'], $this->calls[1]);
	}

	public function testSave(): void {
		$saved = $this->store->save(['a' => 1], 'id-9');

		$this->assertSame('id-9', $saved->getUuid());
		$this->assertSame([['saveObject', ['a' => 1], 'vocabulary', 'concept', 'id-9']], $this->calls);
	}

	public function testDeprecateMissingSweepsPagesUntilShortPage(): void {
		$full = [];
		for ($i = 0; $i < 200; $i++) {
			$full[] = $this->entity('c-' . $i, ['uri' => 'u:' . $i]);
		}

		$this->pages[] = $full;
		$this->pages[] = [
			$this->entity('x-1', ['uri' => 'gone', 'label' => 'G']),
			$this->entity('x-2', ['uri' => 'old', 'deprecated' => true]),
			$this->entity('x-3', ['label' => 'no uri']),
			$this->entity('x-4', null),
			$this->entity('x-5', ['uri' => 'stale', 'deprecated' => 'yes']),
		];
		$seen = array_map(static fn (int $i): string => 'u:' . $i, range(0, 198));

		$count = $this->store->deprecateMissing('scheme-1', $seen);

		$this->assertSame(3, $count);
		$page = static fn (int $offset): array => [
			'findAll',
			[
				'filters' => ['register' => 'vocabulary', 'schema' => 'concept', 'inScheme' => 'scheme-1'],
				'limit' => 200,
				'offset' => $offset,
			],
		];
		$this->assertSame(
			[
				$page(0),
				['saveObject', ['id' => 'c-199', 'uri' => 'u:199', 'deprecated' => true], 'vocabulary', 'concept', 'c-199'],
				$page(200),
				['saveObject', ['id' => 'x-1', 'uri' => 'gone', 'label' => 'G', 'deprecated' => true], 'vocabulary', 'concept', 'x-1'],
				['saveObject', ['id' => 'x-5', 'uri' => 'stale', 'deprecated' => true], 'vocabulary', 'concept', 'x-5'],
			],
			$this->calls
		);
	}

	public function testDeprecateMissingOnEmptyScheme(): void {
		$this->assertSame(0, $this->store->deprecateMissing('scheme-2', []));
		$this->assertCount(1, $this->calls);
	}
}
