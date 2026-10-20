<?php

/**
 * The store hands the set's planned accounts to OpenRegister's validator.
 *
 * The installer tests run over an in-memory store, so this is the test that
 * the real store passes the planned account ids on to ValidateObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 */
class ExampleSetStoreTest extends TestCase {
	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function testValidatePassesThePlannedAccountsToTheValidator(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getSchema')->willReturn(42);

		$validator = $this->createMock(ValidateObject::class);
		$validator->expects($this->once())
			->method('validateObjectWithPlannedUsers')
			->with(['assignee' => 'daan.verbeek'], 42, $this->anything(), ['daan.verbeek'])
			->willReturn(new ValidationResult(null));
		$validator->expects($this->never())->method('validateObject');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			ObjectService::class  => $objects,
			ValidateObject::class => $validator,
		});

		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $store->validate(register: 'crm', schema: 'ticket', data: ['assignee' => 'daan.verbeek'], plannedUsers: ['daan.verbeek']));
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function testARegisterAndASchemaAreFoundByTheirSlugsAndAMissOrAFailureIsNull(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnCallback(function (mixed $register) use ($objects): ObjectService {
			if ($register !== 'publication') {
				throw new \RuntimeException('no such register');
			}

			return $objects;
		});
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getRegister')->willReturn(31);
		$objects->method('getSchema')->willReturn(1113);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(31, $store->registerId(register: 'publication'));
		$this->assertSame(1113, $store->schemaId(register: 'publication', schema: 'publication'));
		$this->assertNull($store->registerId(register: 'nope'));
		$this->assertNull($store->schemaId(register: 'nope', schema: 'publication'));
	}

	/**
	 * A store over an ObjectService whose find hands back $row.
	 *
	 * @param ObjectService $objects The object service.
	 *
	 * @return ExampleSetStore
	 */
	private function storeOver(ObjectService $objects): ExampleSetStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));
	}

	/**
	 * A row of the set is deleted through the system path, which an archival schema lets through.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testARecordedRowIsDeletedThroughTheSystemPath(): void {
		$row = $this->createMock(ObjectEntity::class);
		$row->method('hasActiveLegalHold')->willReturn(false);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($row);
		$objects->expects($this->once())
			->method('deleteObject')
			->with('case-1', 'dossiq', 'case', false, false, true)
			->willReturn(true);

		$this->assertSame('', $this->storeOver(objects: $objects)->deleteRecorded(register: 'dossiq', schema: 'case', id: 'case-1'));
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testAHeldRowIsNeverDeleted(): void {
		$row = $this->createMock(ObjectEntity::class);
		$row->method('hasActiveLegalHold')->willReturn(true);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($row);
		$objects->expects($this->never())->method('deleteObject');

		$this->assertSame('it is under an active legal hold', $this->storeOver(objects: $objects)->deleteRecorded(register: 'dossiq', schema: 'case', id: 'case-1'));
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testARefusalIsReturnedAsTheReasonAndAGoneRowIsGone(): void {
		$row = $this->createMock(ObjectEntity::class);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(static function (mixed $id) use ($row): ObjectEntity {
			if ($id === 'gone') {
				throw new DoesNotExistException('Object gone not found');
			}

			return $row;
		});
		$objects->method('deleteObject')->willThrowException(new \RuntimeException('a relation still points here'));
		$store = $this->storeOver(objects: $objects);

		$this->assertSame('a relation still points here', $store->deleteRecorded(register: 'pipelinq', schema: 'ticket', id: 'ticket-1'));
		$this->assertSame('', $store->deleteRecorded(register: 'pipelinq', schema: 'ticket', id: 'gone'));
	}

	/**
	 * A read that fails says nothing about the row: remove must not drop it from the record as gone.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function testAReadThatFailsLeavesTheRowInTheRecordAndNamesWhy(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new \RuntimeException('db down'));
		$objects->expects($this->never())->method('deleteObject');

		$why = $this->storeOver(objects: $objects)->deleteRecorded(register: 'pipelinq', schema: 'ticket', id: 'ticket-1');

		$this->assertNotSame('', $why);
		$this->assertStringContainsString('db down', $why);
	}

	/**
	 * A row that is not there reads as absent without an error; one that cannot be read comes back with its error.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function testReadTellsAbsentFromFailed(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(static function (mixed $id): ?ObjectEntity {
			return match ($id) {
				'absent' => throw new DoesNotExistException('not found'),
				'null' => null,
				default => throw new \RuntimeException('db down'),
			};
		});
		$store = $this->storeOver(objects: $objects);

		$this->assertSame(['row' => null, 'error' => ''], $store->read(register: 'r', schema: 's', id: 'absent'));
		$this->assertSame(['row' => null, 'error' => ''], $store->read(register: 'r', schema: 's', id: 'null'));
		$this->assertSame(['row' => null, 'error' => 'db down'], $store->read(register: 'r', schema: 's', id: 'broken'));
		$this->assertNull($store->get(register: 'r', schema: 's', id: 'broken'));
	}
}
