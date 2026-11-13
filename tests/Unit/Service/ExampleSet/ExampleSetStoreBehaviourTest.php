<?php

/**
 * ExampleSetStore over a mocked ObjectService: reads, matching, writes,
 * deletes, ownership, schema rules, and every failure turned into a refusal
 * or a reason.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use OCA\OpenRegister\Service\ExampleSet\ObjectCheck;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCP\AppFramework\Db\DoesNotExistException;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDraft
 * @uses \OCA\OpenRegister\Service\ExampleSet\ObjectCheck
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Service\Object\PlannedAccountsCheck
 * @uses \OCA\OpenRegister\Service\Rbac\OwnershipReassignment
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\PresentationAnnotations
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\LockTerms
 * @uses \OCA\OpenRegister\Db\SoftDeletion
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 */
class ExampleSetStoreBehaviourTest extends TestCase {
	/**
	 * The object service the container hands out.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A store whose container hands out the given services; a Throwable is thrown instead.
	 *
	 * @param array<string, mixed> $services By id; ObjectOperationsInterface defaults to $this->objects.
	 *
	 * @return ExampleSetStore
	 */
	private function store(array $services = []): ExampleSetStore {
		$services += [ObjectOperationsInterface::class => $this->objects];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($services): mixed {
			$service = ($services[$id] ?? new RuntimeException('no service '.$id));
			if ($service instanceof Throwable) {
				throw $service;
			}

			return $service;
		});

		return new ExampleSetStore(container: $container, logger: $this->logger);
	}

	/**
	 * A store whose container cannot build the object service.
	 *
	 * @return ExampleSetStore
	 */
	private function storeWithoutService(): ExampleSetStore {
		return $this->store(services: [ObjectOperationsInterface::class => new RuntimeException('not built')]);
	}

	/**
	 * A saved/found entity that serialises to $data.
	 *
	 * @param array<string, mixed> $data The serialised row.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}

	public function testWithoutAnObjectServiceEveryCallAnswersSafely(): void {
		$na    = 'OpenRegister\'s object service is not available';
		$ref   = new ObjectRef(register: 'r', schema: 's', id: 'x');
		$table = new RegisterSchemaRef(register: 'r', schema: 's');

		foreach ([$this->storeWithoutService(), $this->store(services: [ObjectOperationsInterface::class => new \stdClass()])] as $store) {
			$this->assertFalse($store->offers(register: 'r', schema: 's'));
			$this->assertSame([], $store->find(table: $table, filters: []));
			$this->assertSame(['row' => null, 'error' => $na], $store->readRow(ref: $ref));
			$this->assertSame(['id' => '', 'error' => $na], $store->create(register: 'r', schema: 's', data: ['a' => 1]));
			$this->assertSame($na, $store->update(ref: $ref, data: []));
			$this->assertNull($store->ownerOf(ref: $ref));
			$this->assertSame($na, $store->assignOwner(id: 'x', owner: 'u'));
			$this->assertSame([], $store->textProperties(register: 'r', schema: 's'));
			$this->assertSame(['appendOnly' => false, 'kept' => [], 'lenses' => []], $store->writeRules(register: 'r', schema: 's'));
			$this->assertSame($na, $store->deleteRecorded(ref: $ref));
			$this->assertNull($store->registerId(register: 'r'));
			$this->assertNull($store->schemaId(register: 'r', schema: 's'));
			$this->assertSame(
				['this instance has no schema "s" in a register "r"'],
				$store->refusalsFor(table: $table, check: new ObjectCheck(object: ['a' => 1]))
			);
		}
	}

	public function testOffersIsWhetherTheSchemaResolves(): void {
		$this->objects->method('getSchema')->willReturn(7);

		$this->assertTrue($this->store()->offers(register: 'crm', schema: 'ticket'));
	}

	public function testAnInvalidObjectIsRefusedWithTheValidatorsMessage(): void {
		$this->objects->method('getSchema')->willReturn(7);
		$result = $this->createMock(ValidationResult::class);
		$result->method('isValid')->willReturn(false);
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateWithPlannedAccounts')->willReturn($result);
		$validator->expects($this->once())->method('generateErrorMessage')->with($result)->willReturn('title is required');

		$refusals = $this->store(services: [ValidateObject::class => $validator])
			->refusalsFor(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), check: new ObjectCheck(object: ['a' => 1]));

		$this->assertSame(['title is required'], $refusals);
	}

	public function testAValidatorThatThrowsIsARefusal(): void {
		$this->objects->method('getSchema')->willReturn(7);
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateWithPlannedAccounts')->willThrowException(new RuntimeException('schema is broken'));

		$refusals = $this->store(services: [ValidateObject::class => $validator])
			->refusalsFor(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), check: new ObjectCheck(object: ['a' => 1]));

		$this->assertSame(['the validator could not run: schema is broken'], $refusals);
	}

	public function testFindReadsPagesUntilAShortOneAndKeepsOnlyRows(): void {
		$full    = array_fill(0, 500, ['id' => 'x']);
		$configs = [];
		$this->objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy) use (&$configs, $full): array {
				$configs[] = [$config, $_rbac, $_multitenancy];
				if ($config['offset'] === 0) {
					return $full;
				}

				return [$this->entity(data: ['id' => 'last']), 'not a row', 5];
			}
		);

		$rows = $this->store()->find(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), filters: ['status' => 'open']);

		$this->assertCount(501, $rows);
		$this->assertSame(['id' => 'last'], $rows[500]);
		$this->assertSame(
			[
				[['filters' => ['status' => 'open'], 'limit' => 500, 'offset' => 0], false, false],
				[['filters' => ['status' => 'open'], 'limit' => 500, 'offset' => 500], false, false],
			],
			$configs
		);
	}

	public function testFindStopsAfterTwentyFullPages(): void {
		$this->objects->expects($this->exactly(20))->method('findAll')->willReturn(array_fill(0, 500, ['id' => 'x']));

		$this->assertCount(10000, $this->store()->find(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), filters: []));
	}

	public function testAFailedReadIsLoggedAndKeepsWhatWasRead(): void {
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(
			array_fill(0, 500, ['id' => 'x']),
			$this->throwException(new RuntimeException('db down'))
		);
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegister: example set read failed', ['schema' => 'ticket', 'reason' => 'db down']);

		$this->assertCount(500, $this->store()->find(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), filters: []));
	}

	public function testRowsMatchingFiltersFirstThenReadsAllAndChecksEveryRow(): void {
		$filters = [];
		$this->objects->method('findAll')->willReturnCallback(static function (array $config) use (&$filters): array {
			$filters[] = $config['filters'];
			return [
				['@self' => ['id' => 'a'], 'code' => 5],
				['code' => '5'],
				['code' => '6', 'id' => 'b'],
				['@self' => ['id' => 'a'], 'code' => '5', 'dup' => true],
				['code' => [5]],
			];
		});

		$rows = $this->store()->rowsMatching(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), where: ['code' => '5']);

		$this->assertSame([['@self' => ['id' => 'a'], 'code' => '5', 'dup' => true], ['code' => '5']], $rows);
		$this->assertSame([['code' => '5']], $filters, 'the filtered read already matched');
	}

	public function testRowsMatchingFallsBackToAnUnfilteredReadAndAnswersEmptyWhenNothingMatches(): void {
		$filters = [];
		$this->objects->method('findAll')->willReturnCallback(static function (array $config) use (&$filters): array {
			$filters[] = $config['filters'];
			return [['code' => 'z']];
		});

		$this->assertSame([], $this->store()->rowsMatching(table: new RegisterSchemaRef(register: 'crm', schema: 'ticket'), where: ['code' => 'y']));
		$this->assertSame([['code' => 'y'], []], $filters);
	}

	public function testRowMatchesComparesScalarsAsText(): void {
		$store = $this->store();

		$this->assertTrue($store->rowMatches(row: ['a' => 1, 'b' => ['x']], where: ['a' => '1', 'b' => ['x']]));
		$this->assertTrue($store->rowMatches(row: [], where: []));
		$this->assertFalse($store->rowMatches(row: ['a' => 1], where: ['a' => 2]));
		$this->assertFalse($store->rowMatches(row: ['a' => ['x']], where: ['a' => 'x']));
		$this->assertFalse($store->rowMatches(row: ['a' => 'x'], where: ['a' => ['x']]));
		$this->assertFalse($store->rowMatches(row: [], where: ['a' => 'x']));
	}

	public function testReadRowAnswersTheRowWithItsIdOrAbsent(): void {
		$this->objects->method('find')->willReturnCallback(function (string|int $id, ObjectFindOptions $options, bool $_rbac, bool $_multitenancy): ?ObjectEntity {
			$this->assertSame('crm', $options->register);
			$this->assertSame('ticket', $options->schema);
			$this->assertFalse($_rbac);
			$this->assertFalse($_multitenancy);
			return match ($id) {
				't-1' => $this->entity(data: ['@self' => ['uuid' => 't-1'], 'title' => 'T']),
				default => $this->entity(data: ['id' => 'someone-else']),
			};
		});
		$store = $this->store();

		$this->assertSame(
			['row' => ['id' => 't-1', '@self' => ['uuid' => 't-1'], 'title' => 'T'], 'error' => ''],
			$store->readRow(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: 't-1'))
		);
		$this->assertSame(['id' => 't-1', '@self' => ['uuid' => 't-1'], 'title' => 'T'], $store->row(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: 't-1')));
		$this->assertSame(['row' => null, 'error' => ''], $store->readRow(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: 't-2')));
	}

	public function testReadRowWithoutAnIdAsksNothing(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertSame(['row' => null, 'error' => ''], $this->store()->readRow(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: '')));
	}

	public function testAFailedOrIdlessWriteNamesWhy(): void {
		$this->objects->method('saveObject')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('validation failed: title')),
			$this->entity(data: ['title' => 'T'])
		);
		$store = $this->store();

		$this->assertSame(['id' => '', 'error' => 'validation failed: title'], $store->create(register: 'crm', schema: 'ticket', data: ['title' => 'T']));
		$this->assertSame(['id' => '', 'error' => 'OpenRegister answered the write without an id'], $store->create(register: 'crm', schema: 'ticket', data: ['title' => 'T']));
	}

	public function testAnUpdateDropsSelfAndIdAndNamesAFailureWithoutLifecycleContext(): void {
		$this->objects->expects($this->exactly(2))->method('saveObject')->with(
			['title' => 'New'],
			$this->callback(static fn (ObjectSaveOptions $options): bool => $options->uuid === 't-1' && $options->target->register === 'crm' && $options->target->schema === 'ticket'),
			false,
			false
		)->willReturnOnConsecutiveCalls(
			$this->entity(data: []),
			$this->throwException(new RuntimeException('locked'))
		);
		$ref = new ObjectRef(register: 'crm', schema: 'ticket', id: 't-1');

		$this->assertSame('', $this->store()->update(ref: $ref, data: ['title' => 'New', 'id' => 't-1', '@self' => ['x' => 1]]));
		$this->assertSame('locked', $this->store(services: [LifecycleActionContext::class => new \stdClass()])->update(ref: $ref, data: ['title' => 'New']));
	}

	public function testAFailedUpdateStillLeavesTheDeclaredState(): void {
		$actions = new LifecycleActionContext();
		$this->objects->method('saveObject')->willReturnCallback(function () use ($actions): ObjectEntity {
			$this->assertTrue($actions->writesDeclaredState(uuid: 't-1'));
			throw new RuntimeException('locked');
		});

		$this->assertSame('locked', $this->store(services: [LifecycleActionContext::class => $actions])->update(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: 't-1'), data: []));
		$this->assertFalse($actions->writesDeclaredState(uuid: 't-1'));
	}

	public function testOwnerOfReadsTheStoredOwnerOrNull(): void {
		$owned = $this->createMock(ObjectEntity::class);
		$owned->method('getOwner')->willReturn('esra');
		$this->objects->method('find')->willReturnCallback(static fn (string|int $id): ?ObjectEntity => match ($id) {
			'owned' => $owned,
			'gone' => null,
			default => throw new RuntimeException('db down'),
		});
		$store = $this->store();

		$this->assertSame('esra', $store->ownerOf(ref: new ObjectRef(register: 'r', schema: 's', id: 'owned')));
		$this->assertNull($store->ownerOf(ref: new ObjectRef(register: 'r', schema: 's', id: 'gone')));
		$this->assertNull($store->ownerOf(ref: new ObjectRef(register: 'r', schema: 's', id: 'broken')));
	}

	public function testAHandOverThatThrowsOrFailsSeveralTimesNamesEveryReason(): void {
		$ownership = $this->createMock(ObjectOwnershipService::class);
		$ownership->method('reassign')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('not an admin')),
			['failed' => [['reason' => 'a'], ['reason' => 'b']]],
			[]
		);
		$store = $this->store(services: [ObjectOwnershipService::class => $ownership]);

		$this->assertSame('not an admin', $store->assignOwner(id: 'x', owner: 'u'));
		$this->assertSame('a; b', $store->assignOwner(id: 'x', owner: 'u'));
		$this->assertSame('', $store->assignOwner(id: 'x', owner: 'u'));
	}

	public function testTextPropertiesAreThePropertiesTypedAsTextOnly(): void {
		$schema = new Schema();
		$schema->setProperties([
			'title'    => ['type' => 'string'],
			'note'     => ['type' => ['string', 'null']],
			'count'    => ['type' => 'integer'],
			'mixed'    => ['type' => ['string', 'integer']],
			'onlyNull' => ['type' => ['null']],
			'untyped'  => [],
			'broken'   => 'not a definition',
		]);
		$this->objects->method('getCurrentSchemaEntity')->willReturnOnConsecutiveCalls($schema, null, $this->throwException(new RuntimeException('no schema')));
		$store = $this->store();

		$this->assertSame(['title', 'note'], $store->textProperties(register: 'r', schema: 's'));
		$this->assertSame([], $store->textProperties(register: 'r', schema: 's'));
		$this->assertSame([], $store->textProperties(register: 'r', schema: 's'));
	}

	public function testWriteRulesOfAnUnreadableOrAbsentSchemaChangeNothing(): void {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string', 'readOnly' => false], 'raw' => 'x']);
		$this->objects->method('getCurrentSchemaEntity')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('no schema')),
			null,
			$schema
		);
		$store = $this->store();
		$none  = ['appendOnly' => false, 'kept' => [], 'lenses' => []];

		$this->assertSame($none, $store->writeRules(register: 'r', schema: 's'));
		$this->assertSame($none, $store->writeRules(register: 'r', schema: 's'));
		$this->assertSame($none, $store->writeRules(register: 'r', schema: 's'));
	}

	public function testDeletingARowThatIsGoneOrThatOpenRegisterKeeps(): void {
		$this->objects->method('find')->willReturnCallback(fn (string|int $id): ?ObjectEntity => match ($id) {
			'gone' => throw new DoesNotExistException('gone'),
			'null' => null,
			default => $this->createMock(ObjectEntity::class),
		});
		$this->objects->expects($this->once())->method('deleteObject')->willReturn(false);
		$store = $this->store();

		$this->assertSame('', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'gone')));
		$this->assertSame('', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'null')));
		$this->assertSame('OpenRegister did not delete it', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'kept')));
	}

	public function testAFailedReadOrDeleteIsLogged(): void {
		$this->objects->method('find')->willReturnCallback(fn (string|int $id): ?ObjectEntity => match ($id) {
			'unreadable' => throw new RuntimeException('db down'),
			default => $this->createMock(ObjectEntity::class),
		});
		$this->objects->method('deleteObject')->willThrowException(new RuntimeException('relation'));
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context];
		});
		$store = $this->store();

		$this->assertSame('it could not be read: db down', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'unreadable')));
		$this->assertSame('relation', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'held-not')));
		$this->assertSame(
			[
				['OpenRegister: example set read before delete failed', ['schema' => 's', 'id' => 'unreadable', 'reason' => 'db down']],
				['OpenRegister: example set delete failed', ['schema' => 's', 'id' => 'held-not', 'reason' => 'relation']],
			],
			$logged
		);
	}

	public function testIdOfLooksInSelfFirstThenTheRow(): void {
		$store = $this->store();

		$this->assertSame('a', $store->idOf(row: ['@self' => ['id' => 'a', 'uuid' => 'b'], 'id' => 'c']));
		$this->assertSame('b', $store->idOf(row: ['@self' => ['id' => '', 'uuid' => 'b'], 'id' => 'c']));
		$this->assertSame('c', $store->idOf(row: ['@self' => 'flat', 'id' => 'c', 'uuid' => 'd']));
		$this->assertSame('d', $store->idOf(row: ['id' => 5, 'uuid' => 'd']));
		$this->assertSame('', $store->idOf(row: ['title' => 'T']));
	}

	public function testAValidObjectHasNoRefusalAndAFaultyDraftIsRefusedBeforeValidating(): void {
		$this->objects->method('getSchema')->willReturn(7);
		$validator = $this->createMock(ValidateObject::class);
		$validator->expects($this->once())->method('validateWithPlannedAccounts')->with(['title' => 'T'])->willReturn(new ValidationResult(null));
		$store = $this->store(services: [ValidateObject::class => $validator]);
		$table = new RegisterSchemaRef(register: 'crm', schema: 'ticket');

		$this->assertSame([], $store->refusalsFor(table: $table, check: new ObjectCheck(object: ['title' => 'T'])));
		$refusal = $store->refusalsFor(table: $table, check: new ObjectCheck(object: ['title' => 'T', '@self' => ['status' => 'published']]));
		$this->assertCount(1, $refusal);
		$this->assertStringContainsString('@self status may only be "draft"', $refusal[0]);
	}

	public function testReadRowOfARowOpenRegisterDoesNotHaveIsAbsent(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('not found'));

		$this->assertSame(['row' => null, 'error' => ''], $this->store()->readRow(ref: new ObjectRef(register: 'crm', schema: 'ticket', id: 't-1')));
	}

	public function testCreateWritesForTheCastOwnerOrADraftForItsScopedOwner(): void {
		$sent = [];
		$this->objects->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy) use (&$sent): ObjectEntity {
				$sent[] = [$object, $options->target->register, $options->target->schema, $options->actor->owner, $_rbac, $_multitenancy];
				return $this->entity(data: ['@self' => ['id' => 'new-'.count($sent)]]);
			}
		);
		$store = $this->store();

		$this->assertSame(['id' => 'new-1', 'error' => ''], $store->create(register: 'crm', schema: 'ticket', data: ['title' => 'T'], owner: 'esra'));
		$this->assertSame(
			['id' => 'new-2', 'error' => ''],
			$store->create(register: 'crm', schema: 'ticket', data: ['title' => 'D', '@self' => ['status' => 'draft', 'owner' => 'portal:sanne']])
		);
		$this->assertSame(
			['id' => '', 'error' => 'a draft names its own owner; it may not also name an owner from the cast'],
			$store->create(register: 'crm', schema: 'ticket', data: ['title' => 'D', '@self' => ['status' => 'draft', 'owner' => 'portal:sanne']], owner: 'esra')
		);
		$this->assertSame(
			[
				[['title' => 'T'], 'crm', 'ticket', 'esra', false, false],
				[['title' => 'D', '@self' => ['status' => 'draft']], 'crm', 'ticket', 'portal:sanne', false, false],
			],
			$sent
		);
	}

	public function testWriteRulesKeepReadOnlyAndImmutableFieldsAndNameTheLenses(): void {
		$schema = new Schema();
		$schema->setProperties([
			'title'  => ['type' => 'string'],
			'since'  => ['type' => 'string', 'readOnly' => true],
			'number' => ['type' => 'string', Schema::IMMUTABLE_PROPERTY_KEYWORD => true],
			'flat'   => 'x',
		]);
		$schema->setConfiguration([Schema::LENS_ANNOTATION => ['objectTitle' => ['through' => 'object', 'property' => 'title']]]);
		$schema->setAppendOnly(true);
		$this->objects->method('getCurrentSchemaEntity')->willReturn($schema);

		$this->assertSame(
			['appendOnly' => true, 'kept' => ['since', 'number'], 'lenses' => ['objectTitle']],
			$this->store()->writeRules(register: 'r', schema: 's')
		);
	}

	public function testAHeldRowStaysAndAnotherIsDeletedThroughTheRetentionPath(): void {
		$held = $this->createMock(ObjectEntity::class);
		$held->method('hasActiveLegalHold')->willReturn(true);
		$free = $this->createMock(ObjectEntity::class);
		$free->method('hasActiveLegalHold')->willReturn(false);
		$this->objects->method('find')->willReturnCallback(static fn (string|int $id): ObjectEntity => ($id === 'held' ? $held : $free));
		$this->objects->expects($this->once())->method('deleteObject')->with(
			$this->callback(static fn (ObjectDeletion $deletion): bool => $deletion->uuid === 'free' && $deletion->target->register === 'r' && $deletion->target->schema === 's'),
			false,
			false,
			true
		)->willReturn(true);
		$store = $this->store();

		$this->assertSame('it is under an active legal hold', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'held')));
		$this->assertSame('', $store->deleteRecorded(ref: new ObjectRef(register: 'r', schema: 's', id: 'free')));
	}

	public function testRegisterIdIsTheResolvedRegister(): void {
		$this->objects->expects($this->once())->method('setRegister')->with('crm');
		$this->objects->method('getRegister')->willReturn(12);

		$this->assertSame(12, $this->store()->registerId(register: 'crm'));
	}

	public function testAFailedLookupIsAnErrorOrNullNeverAnAbsentRow(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willThrowException(new RuntimeException('no such register'));
		$objects->method('find')->willThrowException(new RuntimeException('db down'));
		$objects->expects($this->never())->method('saveObject');
		$store = $this->store(services: [ObjectOperationsInterface::class => $objects]);

		$this->assertSame(['row' => null, 'error' => 'db down'], $store->readRow(ref: new ObjectRef(register: 'r', schema: 's', id: 'x')));
		$this->assertNull($store->registerId(register: 'r'));
		$this->assertNull($store->schemaId(register: 'r', schema: 's'));
		$created = $store->create(register: 'r', schema: 's', data: ['title' => 'T', '@self' => ['status' => 'published']]);
		$this->assertSame('', $created['id']);
		$this->assertStringContainsString('@self status may only be "draft"', $created['error']);
	}
}
