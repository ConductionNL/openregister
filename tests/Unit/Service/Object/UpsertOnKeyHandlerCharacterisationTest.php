<?php

/**
 * Golden answers of the upsert on a declared key.
 *
 * Pins the full status, body and headers of every refusal, the lock path and
 * the order of lock, lookup, save and release, so a refactor of the handler's
 * internals cannot change what a caller receives.
 *
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Characterisation of UpsertOnKeyHandler::upsert().
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */
class UpsertOnKeyHandlerCharacterisationTest extends TestCase {

	private const LOCK = 'openregister/upsert/134247367255d44da42e2fb95b5302b377808b93';

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var list<string> Every side effect, in order. */
	private array $events = [];

	private Register $register;

	private Schema $schema;

	private UpsertOnKeyHandler $handler;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $path, int $type, ?string $readablePath=null): void {
				$this->events[] = 'acquire:' . $path . ':' . $type . ':' . $readablePath;
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			function (string $path, int $type): void {
				$this->events[] = 'release:' . $path . ':' . $type;
			}
		);

		$this->register = new Register();
		$this->register->setId(3);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setConfiguration(
			[
				'uniqueConstraints' => [
					'titel' => ['properties' => ['titel'], 'action' => 'report'],
					'zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'],
					'nummer' => ['properties' => ['zaaknummer'], 'action' => 'refuse'],
				],
			]
		);

		$this->handler = new UpsertOnKeyHandler(
			evaluator: new UniqueConstraintEvaluator(),
			matchResolver: new MatchResolver(objectService: $this->objectService, logger: new NullLogger()),
			lockingProvider: $locks
		);
	}//end setUp()

	/**
	 * The store finds these uuids.
	 *
	 * @param list<string> $uuids The uuids.
	 *
	 * @return void
	 */
	private function storeFinds(array $uuids): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function () use ($uuids): array {
				$this->events[] = 'lookup';
				return array_map(static fn (string $uuid): array => ['@self' => ['id' => $uuid]], $uuids);
			}
		);
	}//end storeFinds()

	/**
	 * A save that records the uuid it got.
	 *
	 * @return callable The save.
	 */
	private function saver(): callable {
		return function (?string $uuid): ObjectEntity {
			$this->events[] = 'save:' . ($uuid ?? 'null');
			$entity = new ObjectEntity();
			$entity->setUuid($uuid ?? 'new-uuid');
			return $entity;
		};
	}//end saver()

	/**
	 * The refusal a call ends in, as [status, body, headers].
	 *
	 * @param string   $name The constraint name.
	 * @param array    $body The body.
	 * @param callable $save The save.
	 *
	 * @return array The answer.
	 */
	private function answer(string $name, array $body, callable $save): array {
		try {
			$this->handler->upsert($name, $body, $this->register, $this->schema, $save);
		} catch (UpsertOnKeyException $e) {
			return [$e->getStatusCode(), $e->getBody(), $e->getHeaders(), $e->getPrevious() === null ? null : get_class($e->getPrevious())];
		}

		$this->fail('expected a refusal');
	}//end answer()

	/** @return array<string, string> */
	private function body(): array {
		return ['gemeentecode' => '0363', 'zaaknummer' => 'Z-1', 'titel' => 'Een zaak'];
	}//end body()

	public function testCreateAndUpdatePinLockLookupSaveAndRelease(): void {
		$this->storeFinds([]);
		$created = $this->handler->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver());
		$this->assertSame(true, $created['created']);
		$this->assertSame('new-uuid', $created['object']->getUuid());
		$this->assertSame(
			['acquire:' . self::LOCK . ':2:an upsert key', 'lookup', 'save:null', 'release:' . self::LOCK . ':2'],
			$this->events
		);
	}

	public function testOneMatchUpdatesIt(): void {
		$this->storeFinds(['u-1']);
		$updated = $this->handler->upsert('zaaksleutel', $this->body(), $this->register, $this->schema, $this->saver());
		$this->assertSame(['created' => false], ['created' => $updated['created']]);
		$this->assertSame('u-1', $updated['object']->getUuid());
		$this->assertSame(['acquire:' . self::LOCK . ':2:an upsert key', 'lookup', 'save:u-1', 'release:' . self::LOCK . ':2'], $this->events);
	}

	public function testAnUnknownNameIsA400ListingTheRefuseConstraints(): void {
		$this->assertSame(
			[
				400,
				[
					'error' => "'titel' is not a uniqueness constraint of this schema that refuses duplicates, so it cannot be used as an upsert key.",
					'refuseConstraints' => ['zaaksleutel', 'nummer'],
				],
				[],
				null,
			],
			$this->answer('titel', $this->body(), $this->saver())
		);
		$this->assertSame([], $this->events);
	}

	public function testAMissingValueIsA400NamingTheFirstMissingProperty(): void {
		$this->assertSame(
			[
				400,
				[
					'error' => "The upsert key 'zaaksleutel' needs a value for 'gemeentecode'.",
					'constraint' => 'zaaksleutel',
					'property' => 'gemeentecode',
				],
				[],
				null,
			],
			$this->answer('zaaksleutel', ['gemeentecode' => '', 'zaaknummer' => null], $this->saver())
		);
		$this->assertSame([], $this->events);
	}

	public function testTwoMatchesAreA409WithTheMatches(): void {
		$this->storeFinds(['u-1', 'u-2']);
		$this->assertSame(
			[
				409,
				['error' => 'More than one record holds this key, so none was changed.', 'constraint' => 'zaaksleutel', 'matches' => ['u-1', 'u-2']],
				[],
				null,
			],
			$this->answer('zaaksleutel', $this->body(), $this->saver())
		);
		$this->assertSame(['acquire:' . self::LOCK . ':2:an upsert key', 'lookup', 'release:' . self::LOCK . ':2'], $this->events);
	}

	public function testAFailedLookupIsA503WithRetryAfter(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$answer = $this->answer('zaaksleutel', $this->body(), $this->saver());
		$this->assertSame(503, $answer[0]);
		$this->assertSame(['error' => 'The key could not be looked up, so nothing was written. Try again.'], $answer[1]);
		$this->assertSame(['Retry-After' => '5'], $answer[2]);
		$this->assertNotNull($answer[3]);
		$this->assertSame(['acquire:' . self::LOCK . ':2:an upsert key', 'release:' . self::LOCK . ':2'], $this->events);
	}

	public function testAnUnseenHolderIsA409NamingTheConstraintFromTheRefusal(): void {
		$this->storeFinds([]);
		$save = static function (): ObjectEntity {
			throw new HookStoppedException('breach', ['code' => UniqueConstraintListener::ERROR_CODE, 'constraint' => 'other-name', 'conflictingObject' => 'secret']);
		};
		$this->assertSame(
			[409, ['error' => 'A record with this key exists that you cannot change.', 'constraint' => 'other-name'], [], HookStoppedException::class],
			$this->answer('zaaksleutel', $this->body(), $save)
		);

		$this->events = [];
		$save = static function (): ObjectEntity {
			throw new HookStoppedException('breach', ['code' => UniqueConstraintListener::ERROR_CODE]);
		};
		$this->assertSame(
			[409, ['error' => 'A record with this key exists that you cannot change.', 'constraint' => 'zaaksleutel'], [], HookStoppedException::class],
			$this->answer('zaaksleutel', $this->body(), $save)
		);
		$this->assertSame(['acquire:' . self::LOCK . ':2:an upsert key', 'lookup', 'release:' . self::LOCK . ':2'], $this->events);
	}
}//end class
