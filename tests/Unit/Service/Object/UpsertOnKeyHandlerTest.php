<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Service\Import\MatchResolver;
use OCA\OpenRegister\Service\Object\UpsertOnKeyException;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The upsert on a declared key: create, update or refuse, under one lock per key.
 *
 * Runs the real UniqueConstraintEvaluator and the real MatchResolver; only the
 * object store's findAll() and Nextcloud's lock provider are doubles.
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */
class UpsertOnKeyHandlerTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locks;

	/** @var array<int, array{string, string}> Lock calls in order: [acquire|release, path]. */
	private array $lockCalls = [];

	/** @var array<int, array<string, mixed>> The findAll() configs the lookup sent. */
	private array $lookups = [];

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->locks->method('acquireLock')->willReturnCallback(
			function (string $path): void {
				$this->lockCalls[] = ['acquire', $path];
			}
		);
		$this->locks->method('releaseLock')->willReturnCallback(
			function (string $path): void {
				$this->lockCalls[] = ['release', $path];
			}
		);

		$this->register = new Register();
		$this->register->setId(3);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setConfiguration(
			[
				'uniqueConstraints' => [
					'zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'],
					'titel' => ['properties' => ['titel'], 'action' => 'report'],
				],
			]
		);
	}//end setUp()

	private function handler(): UpsertOnKeyHandler {
		return new UpsertOnKeyHandler(
			evaluator: new UniqueConstraintEvaluator(),
			matchResolver: new MatchResolver(objectService: $this->objectService, logger: new NullLogger()),
			lockingProvider: $this->locks
		);
	}//end handler()

	/**
	 * The store finds these uuids for any lookup.
	 *
	 * @param array<int, string> $uuids The uuids.
	 */
	private function storeFinds(array $uuids): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($uuids): array {
				$this->lookups[] = $config;
				return array_map(static fn (string $uuid): array => ['@self' => ['id' => $uuid]], $uuids);
			}
		);
	}//end storeFinds()

	/** @return array<string, mixed> */
	private function body(): array {
		return ['gemeentecode' => '0363', 'zaaknummer' => 'Z-1', 'titel' => 'Een zaak'];
	}//end body()

	/**
	 * A save double that records the uuid it was asked to write.
	 *
	 * @param array<int, string|null> $calls Filled with each uuid passed.
	 */
	private function saver(array &$calls): callable {
		return static function (?string $uuid) use (&$calls): ObjectEntity {
			$calls[] = $uuid;
			$entity = new ObjectEntity();
			$entity->setUuid($uuid ?? 'new-uuid');
			return $entity;
		};
	}//end saver()

	public function testNoMatchCreates(): void {
		$this->storeFinds([]);
		$calls = [];

		$outcome = $this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls));

		$this->assertTrue($outcome['created']);
		$this->assertSame([null], $calls);
		$this->assertSame(
			['register' => 3, 'schema' => 7, 'gemeentecode' => '0363', 'zaaknummer' => 'Z-1'],
			$this->lookups[0]['filters']
		);
	}//end testNoMatchCreates()

	public function testOneMatchUpdatesThatRecord(): void {
		$this->storeFinds(['u-1']);
		$calls = [];

		$outcome = $this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls));

		$this->assertFalse($outcome['created']);
		$this->assertSame(['u-1'], $calls);
		$this->assertSame('u-1', $outcome['object']->getUuid());
	}//end testOneMatchUpdatesThatRecord()

	public function testTwoMatchesAreRefusedWithTheMatches(): void {
		$this->storeFinds(['u-1', 'u-2']);
		$calls = [];

		$refusal = $this->refusal(fn () => $this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls)));

		$this->assertSame(409, $refusal->getStatusCode());
		$this->assertSame(['u-1', 'u-2'], $refusal->getBody()['matches']);
		$this->assertSame([], $calls);
	}//end testTwoMatchesAreRefusedWithTheMatches()

	public function testAReportConstraintIsNotAKey(): void {
		$this->storeFinds([]);
		$calls = [];

		$refusal = $this->refusal(fn () => $this->handler()->upsert('titel', $this->body(), $this->register, $this->schema, $this->saver($calls)));

		$this->assertSame(400, $refusal->getStatusCode());
		$this->assertSame(['zaaksleutel'], $refusal->getBody()['refuseConstraints']);
		$this->assertSame([], $calls);
		$this->assertSame([], $this->lockCalls);
	}//end testAReportConstraintIsNotAKey()

	public function testAnUnknownNameIsRefused(): void {
		$this->storeFinds([]);
		$calls = [];

		$refusal = $this->refusal(fn () => $this->handler()->upsert('status', $this->body(), $this->register, $this->schema, $this->saver($calls)));

		$this->assertSame(400, $refusal->getStatusCode());
		$this->assertSame(['zaaksleutel'], $refusal->getBody()['refuseConstraints']);
	}//end testAnUnknownNameIsRefused()

	public function testTheLegacyUniqueKeyIsNamedByItsProperties(): void {
		$this->schema->setConfiguration(['unique' => ['gemeentecode', 'zaaknummer']]);
		$this->storeFinds(['u-1']);
		$calls = [];

		$outcome = $this->handler()->upsert('gemeentecode+zaaknummer', $this->body(), $this->register, $this->schema, $this->saver($calls));

		$this->assertFalse($outcome['created']);
		$this->assertSame(['u-1'], $calls);
	}//end testTheLegacyUniqueKeyIsNamedByItsProperties()

	public function testAMissingKeyValueIsRefusedNamingTheProperty(): void {
		$this->storeFinds([]);
		$calls = [];
		$body = $this->body();
		$body['zaaknummer'] = '';

		$refusal = $this->refusal(fn () => $this->handler()->upsert('zaaksleutel', $body, $this->register, $this->schema, $this->saver($calls)));

		$this->assertSame(400, $refusal->getStatusCode());
		$this->assertSame('zaaknummer', $refusal->getBody()['property']);
		$this->assertSame([], $this->lookups);
	}//end testAMissingKeyValueIsRefusedNamingTheProperty()

	public function testTheLockPathIsAHashAndIsReleased(): void {
		$this->storeFinds([]);
		$calls = [];

		$this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls));

		$this->assertCount(2, $this->lockCalls);
		[$acquire, $release] = $this->lockCalls;
		$this->assertSame('acquire', $acquire[0]);
		$this->assertSame(['release', $acquire[1]], $release);
		$this->assertMatchesRegularExpression('#^openregister/upsert/[0-9a-f]{40}$#', $acquire[1]);
		$this->assertStringNotContainsString('Z-1', $acquire[1]);
		$this->assertStringNotContainsString('0363', $acquire[1]);
	}//end testTheLockPathIsAHashAndIsReleased()

	/**
	 * The lock path fits the column Nextcloud's database locking provider stores it in.
	 *
	 * `oc_file_locks.key` is a varchar(64). A longer path made pgsql refuse the
	 * INSERT, and the controller's generic catch answered every upsert with 403
	 * (CI Newman, run 37230164290). A stub provider cannot see that, so the
	 * length is asserted here against the column, with the largest ids a
	 * register and a schema can carry.
	 */
	public function testTheLockPathFitsNextcloudsLockColumn(): void {
		$this->storeFinds([]);
		$calls = [];
		$this->register->setId(2147483647);
		$this->schema->setId(2147483647);

		$this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls));

		$this->assertLessThanOrEqual(64, strlen($this->lockCalls[0][1]));
	}//end testTheLockPathFitsNextcloudsLockColumn()

	/**
	 * One key in one schema is one lock; the same values elsewhere are another.
	 */
	public function testTheLockPathSeparatesRegistersSchemasAndKeys(): void {
		$this->storeFinds([]);
		$calls = [];
		$saver = $this->saver($calls);

		$this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $saver);
		$this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $saver);
		$otherRegister = new Register();
		$otherRegister->setId(4);
		$this->handler()->upsert('zaaksleutel', $this->body(), $otherRegister, $this->schema, $saver);
		$this->handler()->upsert('zaaksleutel', (['zaaknummer' => 'Z-2'] + $this->body()), $this->register, $this->schema, $saver);

		$paths = array_column(array_filter($this->lockCalls, static fn (array $call): bool => $call[0] === 'acquire'), 1);
		$this->assertSame($paths[0], $paths[1]);
		$this->assertNotSame($paths[0], $paths[2]);
		$this->assertNotSame($paths[0], $paths[3]);
	}//end testTheLockPathSeparatesRegistersSchemasAndKeys()

	public function testTheLockIsReleasedWhenTheSaveThrows(): void {
		$this->storeFinds([]);

		try {
			$this->handler()->upsert(
				'zaaksleutel',
				$this->body(),
				$this->register,
				$this->schema,
				static function (): ObjectEntity {
					throw new RuntimeException('save failed');
				}
			);
			$this->fail('the save error must reach the caller');
		} catch (RuntimeException $exception) {
			$this->assertSame('save failed', $exception->getMessage());
		}

		$this->assertSame(['acquire', 'release'], array_column($this->lockCalls, 0));
	}//end testTheLockIsReleasedWhenTheSaveThrows()

	public function testAFailedLookupWritesNothing(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$calls = [];

		$refusal = $this->refusal(fn () => $this->handler()->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver($calls)));

		$this->assertSame(503, $refusal->getStatusCode());
		$this->assertSame(['Retry-After' => '5'], $refusal->getHeaders());
		$this->assertSame([], $calls);
		$this->assertSame(['acquire', 'release'], array_column($this->lockCalls, 0));
	}//end testAFailedLookupWritesNothing()

	public function testAnUnseenHolderIsA409WithoutItsUuid(): void {
		$this->storeFinds([]);

		$refusal = $this->refusal(
			fn () => $this->handler()->upsert(
				'zaaksleutel',
				$this->body(),
				$this->register,
				$this->schema,
				static function (): ObjectEntity {
					throw new HookStoppedException(
						'breach',
						[
							'code' => UniqueConstraintListener::ERROR_CODE,
							'message' => 'breach',
							'constraint' => 'zaaksleutel',
							'conflictingObject' => 'secret-uuid',
						]
					);
				}
			)
		);

		$this->assertSame(409, $refusal->getStatusCode());
		$this->assertSame('zaaksleutel', $refusal->getBody()['constraint']);
		$this->assertStringNotContainsString('secret-uuid', (string)json_encode($refusal->getBody()));
	}//end testAnUnseenHolderIsA409WithoutItsUuid()

	public function testAnotherHookRefusalPassesThrough(): void {
		$this->storeFinds([]);
		$this->expectException(HookStoppedException::class);

		$this->handler()->upsert(
			'zaaksleutel',
			$this->body(),
			$this->register,
			$this->schema,
			static function (): ObjectEntity {
				throw new HookStoppedException('a workflow said no', ['code' => 'workflow']);
			}
		);
	}//end testAnotherHookRefusalPassesThrough()

	public function testAKeyLockHeldTooLongIsA503(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('openregister/upsert/x'));
		$locks->expects($this->never())->method('releaseLock');
		$this->objectService->expects($this->never())->method('findAll');

		$handler = new class(new UniqueConstraintEvaluator(), new MatchResolver($this->objectService, new NullLogger()), $locks) extends UpsertOnKeyHandler {
		};

		$refusal = $this->refusal(fn () => $handler->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, static fn (): ObjectEntity => new ObjectEntity()));

		$this->assertSame(503, $refusal->getStatusCode());
	}//end testAKeyLockHeldTooLongIsA503()

	private function refusal(callable $call): UpsertOnKeyException {
		try {
			$call();
		} catch (UpsertOnKeyException $exception) {
			return $exception;
		}

		$this->fail('expected the upsert to be refused');
	}//end refusal()
}//end class
