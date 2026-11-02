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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\SystemOperationContext;
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
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

	/**
	 * A write runs as the system, so a read inside the save path (a relation, a
	 * listener) is never refused as an anonymous caller, and an update is
	 * marked as a write of declared state while it runs, and only then.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-declared-state-as-the-system
	 */
	public function testAWriteRunsAsTheSystemAndAnUpdateWritesDeclaredState(): void {
		$actions = new LifecycleActionContext();
		$seen    = [];
		$saved   = $this->createMock(ObjectEntity::class);
		$saved->method('jsonSerialize')->willReturn(['@self' => ['id' => 'case-1']]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => SystemOperationContext::run($operation));
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use (&$seen, $actions, $saved): ObjectEntity {
				$seen[] = ['system' => SystemOperationContext::isActive(), 'declared' => $actions->writesDeclaredState(uuid: 'case-1')];
				return $saved;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			ObjectService::class          => $objects,
			LifecycleActionContext::class => $actions,
		});
		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('case-1', $store->create(register: 'dossiq', schema: 'case', data: ['title' => 'Zaak'])['id']);
		$this->assertSame('', $store->update(register: 'dossiq', schema: 'case', id: 'case-1', data: ['status' => 'requested']));

		$this->assertSame([['system' => true, 'declared' => false], ['system' => true, 'declared' => true]], $seen);
		$this->assertFalse($actions->writesDeclaredState(uuid: 'case-1'), 'released after the write');
		$this->assertFalse(SystemOperationContext::isActive());
	}

	/**
	 * The write rules of a schema: append-only, and the fields a rewrite must not send (readOnly, immutable, lenses).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-sends-only-what-the-schema-lets-a-write-change
	 */
	public function testTheWriteRulesOfASchema(): void {
		$schema = new Schema();
		$schema->setProperties([
			'title'    => ['type' => 'string'],
			'since'    => ['type' => 'string', 'readOnly' => true],
			'number'   => ['type' => 'string', Schema::IMMUTABLE_PROPERTY_KEYWORD => true],
		]);
		$schema->setConfiguration([Schema::LENS_ANNOTATION => ['objectTitle' => ['through' => 'object', 'property' => 'title']]]);
		$schema->setAppendOnly(true);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('getCurrentSchemaEntity')->willReturn($schema);

		$rules = $this->storeOver(objects: $objects)->writeRules(register: 'dossiq', schema: 'case');

		$this->assertTrue($rules['appendOnly']);
		$this->assertSame(['since', 'number'], $rules['kept']);
		$this->assertSame(['objectTitle'], $rules['lenses']);
	}

	/**
	 * A declared owner goes to the save as `_owner`; a hand-over runs OpenRegister's ownership change as the system
	 * and names a failure.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function testTheOwnerGoesToTheSaveAndAHandOverRunsAsTheSystem(): void {
		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('jsonSerialize')->willReturn(['@self' => ['id' => 'besluit-1']]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => SystemOperationContext::run($operation));
		$objects->expects($this->once())->method('saveObject')->with(
			$this->anything(), $this->anything(), 'besluiten', 'decision', null, false, false, false, true, null, null, false, false, false, 'pieter'
		)->willReturn($saved);
		$system    = [];
		$ownership = $this->createMock(ObjectOwnershipService::class);
		$ownership->method('reassignMany')->willReturnCallback(
			static function (array $identifiers, string $newOwner) use (&$system): array {
				$system[] = SystemOperationContext::isActive();
				return ['failed' => ($identifiers === ['gone'] ? [['identifier' => 'gone', 'reason' => 'not found']] : [])];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			ObjectService::class          => $objects,
			ObjectOwnershipService::class => $ownership,
		});
		$store = new ExampleSetStore(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('besluit-1', $store->create(register: 'besluiten', schema: 'decision', data: ['title' => 'Besluit'], owner: 'pieter')['id']);
		$this->assertSame('', $store->assignOwner(id: 'besluit-1', owner: 'pieter'));
		$this->assertSame('not found', $store->assignOwner(id: 'gone', owner: 'pieter'));
		$this->assertSame([true, true], $system);
	}
}
