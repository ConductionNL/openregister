<?php

/**
 * OpenRegister - the owner and organisation a bulk row is stamped with, and the audit and events it emits.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Pins which owner and organisation a bulk row keeps or is given, for every kind of
 * caller (allowed: the session user, an administrator's or member's own organisation;
 * refused: a client-supplied owner, a foreign organisation), and whether the audit trail
 * is written and the lifecycle events dispatched for each switch. Recorded on the code
 * before these methods were split up; the expected values live in
 * `fixtures/save-objects-ownership-side-effects.php`.
 */
class SaveObjectsOwnershipAndSideEffectsGoldenTest extends TestCase {

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every ownership case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function ownershipCases(): array {
		return [
			'session user overrides a client owner'      => [['user' => 'bob', 'self' => ['owner' => 'mallory']]],
			'no session user keeps a client owner'       => [['user' => null, 'self' => ['owner' => 'importer']]],
			'no session user and an empty owner'         => [['user' => null, 'self' => ['owner' => '']]],
			'no session user and no owner'               => [['user' => null, 'self' => []]],
			'no organisation asked'                      => [['user' => 'bob', 'self' => []]],
			'empty organisation asked'                   => [['user' => 'bob', 'self' => ['organisation' => '']]],
			'foreign organisation for a non-member'      => [['user' => 'bob', 'self' => ['organisation' => 'theirs']]],
			'own organisation for a member'              => [['user' => 'bob', 'self' => ['organisation' => 'mine']]],
			'foreign organisation for an administrator'  => [['user' => 'admin', 'self' => ['organisation' => 'theirs']]],
			'foreign organisation without group manager' => [['user' => 'admin', 'groups' => false, 'self' => ['organisation' => 'theirs']]],
			'foreign organisation without session user'  => [['user' => null, 'self' => ['organisation' => 'theirs']]],
			'own organisation without session user'      => [['user' => null, 'self' => ['organisation' => 'mine', 'owner' => 'x']]],
		];
	}//end ownershipCases()

	/**
	 * Every side-effect case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function sideEffectCases(): array {
		return [
			'audit and events'          => [['events' => true, 'audit' => true]],
			'audit only'                => [['events' => false, 'audit' => true]],
			'events only'               => [['events' => true, 'audit' => false]],
			'neither'                   => [['events' => false, 'audit' => false]],
			'no audit mapper'           => [['events' => true, 'audit' => true, 'auditMapper' => false]],
			'no dispatcher'             => [['events' => true, 'audit' => true, 'dispatcher' => false]],
			'system operation'          => [['events' => true, 'audit' => true, 'system' => true]],
			'failing audit and events'  => [['events' => true, 'audit' => true, 'fail' => true]],
			'nothing to emit'           => [['events' => true, 'audit' => true, 'empty' => true]],
		];
	}//end sideEffectCases()

	/**
	 * The owner and organisation a row ends with are the recorded ones.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return void
	 */
	#[DataProvider('ownershipCases')]
	public function testTheRowIsStampedAsRecorded(array $case): void {
		$this->calls = [];
		$handler     = $this->handler(case: $case);
		$selfData    = (new ReflectionMethod(SaveObjects::class, 'hydrateObjectMetadataFields'))->invoke($handler, $case['self']);

		$this->assertSame($this->expected(group: 'ownership', name: $this->dataName()), ['self' => $selfData, 'calls' => $this->calls]);
	}//end testTheRowIsStampedAsRecorded()

	/**
	 * The audit rows written and events dispatched are the recorded ones.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return void
	 */
	#[DataProvider('sideEffectCases')]
	public function testTheSideEffectsAreAsRecorded(array $case): void {
		$this->calls = [];
		$handler     = $this->handler(case: $case);
		$created     = new ObjectEntity();
		$created->setUuid('c1');
		$old = new ObjectEntity();
		$old->setUuid('u1');
		$new = new ObjectEntity();
		$new->setUuid('u1');
		$new->setObject(['changed' => true]);
		$sideEffects = ['created' => [$created], 'updated' => [['old' => $old, 'new' => $new], ['old' => null, 'new' => $new]]];
		if (($case['empty'] ?? false) === true) {
			$sideEffects = [];
		}

		$emit = new ReflectionMethod(SaveObjects::class, 'emitChunkSideEffects');
		$run  = static fn () => $emit->invoke($handler, $sideEffects, $case['events'], $case['audit']);
		if (($case['system'] ?? false) === true) {
			SystemOperationContext::run($run);
		} else {
			$run();
		}

		$this->assertSame($this->expected(group: 'sideEffects', name: $this->dataName()), $this->calls);
	}//end testTheSideEffectsAreAsRecorded()

	/**
	 * The recorded outcome of a case.
	 *
	 * @param string     $group The group
	 * @param int|string $name  The case
	 *
	 * @return mixed
	 */
	private function expected(string $group, int|string $name): mixed {
		$expected = require __DIR__.'/fixtures/save-objects-ownership-side-effects.php';

		return ($expected[$group][$name] ?? 'missing expectation');
	}//end expected()

	/**
	 * The handler, wired for the case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return SaveObjects
	 */
	private function handler(array $case): SaveObjects {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		if (($case['user'] ?? 'bob') !== null) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn(($case['user'] ?? 'bob'));
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($account);
		}

		$groups = null;
		if (($case['groups'] ?? true) === true) {
			$groups = $this->createMock(IGroupManager::class);
			$groups->method('isAdmin')->willReturnCallback(
				function (string $uid): bool {
					$this->calls[] = 'isAdmin '.$uid;
					return $uid === 'admin';
				}
			);
		}

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturnCallback(
			function (): string {
				$this->calls[] = 'getOrganisationForNewEntity';
				return 'active-org';
			}
		);
		$organisations->method('hasAccessToOrganisation')->willReturnCallback(
			function (string $organisation): bool {
				$this->calls[] = 'hasAccessToOrganisation '.$organisation;
				return $organisation === 'mine';
			}
		);

		return new SaveObjects(
			stores: new BulkSaveStores(
				objectEntityMapper: $this->createMock(MagicMapper::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				registerMapper: $this->createMock(RegisterMapper::class),
				saveHandler: $this->createMock(SaveObject::class)
			),
			session: new BulkSaveSession(userSession: $session, organisationService: $organisations, groupManager: $groups),
			reporting: new BulkSaveReporting(
				logger: $this->logger(),
				eventDispatcher: $this->dispatcher(case: $case),
				auditTrailMapper: $this->auditMapper(case: $case)
			),
			checks: new BulkRowChecks()
		);
	}//end handler()

	/**
	 * A logger that records into the calls.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$calls = &$this->calls;

		return new class($calls) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $calls The calls, by reference
			 */
			public function __construct(private array &$calls) {
			}//end __construct()

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->calls[] = 'log '.$level.': '.$message.' '.json_encode($context);
			}//end log()
		};
	}//end logger()

	/**
	 * An event dispatcher that records the events, or none.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IEventDispatcher|null
	 */
	private function dispatcher(array $case): ?IEventDispatcher {
		if (($case['dispatcher'] ?? true) === false) {
			return null;
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($case): void {
				$this->calls[] = 'dispatch '.(new \ReflectionClass($event))->getShortName();
				if (($case['fail'] ?? false) === true) {
					throw new RuntimeException('listener broke');
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * An audit trail mapper that records the entries, or none.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return AuditTrailMapper|null
	 */
	private function auditMapper(array $case): ?AuditTrailMapper {
		if (($case['auditMapper'] ?? true) === false) {
			return null;
		}

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($case): array {
				$this->calls[] = 'audit '.json_encode(
					array_map(static fn (array $e): array => [$e['action'], $e['old']?->getUuid(), $e['new']->getUuid()], $entries)
				);
				if (($case['fail'] ?? false) === true) {
					throw new RuntimeException('audit broke');
				}

				return [];
			}
		);

		return $mapper;
	}//end auditMapper()
}//end class
