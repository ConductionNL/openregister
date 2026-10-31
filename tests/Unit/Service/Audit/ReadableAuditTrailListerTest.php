<?php

/**
 * The scoped audit list shows what the caller may read, and nothing else.
 *
 * The failure this suite exists to catch is the one that looks like success:
 * a scope filter that is accidentally a no-op returns exactly the rows an
 * administrator sees, and the page renders perfectly. Nobody notices until two
 * accounts are compared. So the central assertion here is not "rows come back"
 * but "the unreadable row does NOT come back", and it is mutation-checked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class ReadableAuditTrailListerTest extends TestCase {

	/**
	 * The uuid of the object the caller may read.
	 *
	 * @var string
	 */
	private const READABLE = 'obj-readable';

	/**
	 * The uuid of the object the caller may not read.
	 *
	 * @var string
	 */
	private const HIDDEN = 'obj-hidden';

	/**
	 * An audit entry pointing at one object.
	 *
	 * @param string      $objectUuid The object the entry belongs to.
	 * @param string      $action     The action recorded.
	 * @param string|null $session    A session id, to prove it is withheld.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(string $objectUuid, string $action = 'update', ?string $session = null): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid('audit-' . $objectUuid . '-' . $action);
		$entry->setObjectUuid($objectUuid);
		$entry->setAction($action);
		$entry->setUser('alice');
		$entry->setSchema(7);

		if ($session !== null) {
			$entry->setSession($session);
			$entry->setRequest('req-1');
			$entry->setIpAddress('203.0.113.9');
		}

		return $entry;
	}//end entry()

	/**
	 * An object entity with a uuid and a schema.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('7');
		$object->setOwner('bob');

		return $object;
	}//end object()

	/**
	 * A lister over the given entries, where only READABLE is readable.
	 *
	 * @param array<AuditTrail>   $entries     The rows the mapper returns for the first query.
	 * @param array<ObjectEntity> $objects     The objects that resolve.
	 * @param array<string, bool> $permissions Object owner-independent verdicts, by object uuid.
	 *
	 * @return ReadableAuditTrailLister The lister.
	 */
	private function lister(array $entries, array $objects, array $permissions, ?ReadEventVisibility $readEvents=null): ReadableAuditTrailLister {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null) use ($entries): array {
				// One page of candidates, then nothing: the trail is short.
				if ($offset !== null && $offset > 0) {
					return [];
				}

				return $entries;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn($objects);

		$schema = $this->createMock(Schema::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturnCallback(
			function (
				Schema $_schema,
				string $action,
				?string $userId = null,
				?string $objectOwner = null,
				bool $rbac = true,
				?ObjectEntity $object = null,
			) use ($permissions): bool {
				if ($object === null || $action !== 'read') {
					return false;
				}

				return ($permissions[$object->getUuid()] ?? false);
			}
		);

		return new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			schemaMapper: $schemaMapper,
			permissionHandler: $permissionHandler,
			readEvents: ($readEvents ?? $this->readsShown())
		);
	}//end lister()

	/**
	 * A caller outside the groups named for read entries gets the feed without them.
	 *
	 * @return void
	 */
	public function testReadEntriesAreLeftOutForACallerWhoMayNotSeeThem(): void {
		$hidden = $this->createMock(ReadEventVisibility::class);
		$hidden->method('maySeeReadEvents')->willReturn(false);
		$hidden->method('isReadAction')->willReturnCallback(static fn ($action): bool => $action === 'read');

		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::READABLE, action: 'read'),
				$this->entry(objectUuid: self::READABLE),
			],
			objects: [$this->object(uuid: self::READABLE)],
			permissions: [self::READABLE => true],
			readEvents: $hidden
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame(['update'], array_column($page['results'], 'action'), 'a read entry reached a caller who may not see reads');
	}//end testReadEntriesAreLeftOutForACallerWhoMayNotSeeThem()

	/**
	 * THE assertion of this suite: the entry on an object the caller may not
	 * read is absent, while the entry on the readable object is present.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectsEntryIsAbsent(): void {
		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::HIDDEN),
				$this->entry(objectUuid: self::READABLE),
			],
			objects: [
				$this->object(uuid: self::HIDDEN),
				$this->object(uuid: self::READABLE),
			],
			permissions: [self::READABLE => true, self::HIDDEN => false]
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$uuids = array_column($page['results'], 'objectUuid');

		$this->assertNotContains(self::HIDDEN, $uuids, 'An entry on an object the caller may not read was returned.');
		$this->assertContains(self::READABLE, $uuids, 'The entry on the readable object was dropped.');
		$this->assertCount(1, $page['results']);
	}//end testAnUnreadableObjectsEntryIsAbsent()

	/**
	 * A lister over the REAL permission handler, for one caller and one draft owned by somebody else.
	 *
	 * @param string $caller The signed-in user.
	 *
	 * @return array<int, string> The uuids of the entries the caller gets back.
	 */
	private function draftEntriesFor(string $caller): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not available'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default=false): bool => $default
		);

		// The schema lets every signed-in user read: only the draft rule can refuse.
		$schema = new Schema();
		$schema->setId(7);
		$schema->setAuthorization(['read' => ['authenticated']]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$draft = $this->object(uuid: 'obj-draft');
		$draft->setStatus('draft');
		$draft->setOwner('alice');

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$draft]);
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			fn (?int $limit = null, ?int $offset = null): array => (($offset !== null && $offset > 0) ? [] : [$this->entry(objectUuid: 'obj-draft', action: 'create')])
		);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			schemaMapper: $schemaMapper,
			permissionHandler: new PermissionHandler(
				userSession: $session,
				userManager: $userManager,
				groupManager: $groupManager,
				schemaMapper: $schemaMapper,
				objectEntityMapper: $objectMapper,
				conditionMatcher: $this->createMock(ConditionMatcher::class),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
				container: $container,
			),
			readEvents: $this->readsShown()
		);

		return array_column($lister->page(userId: $caller, limit: 20)['results'], 'objectUuid');
	}//end draftEntriesFor()

	/**
	 * What a user typed into a draft is not in the readable trail of anybody else (the draft rule of hasPermission()).
	 *
	 * Goes red without the draft rule: the schema lets bob read, and the entry
	 * holds the draft's values in its `changed` column.
	 *
	 * @return void
	 */
	public function testSomebodyElsesDraftHasNoEntriesInTheTrail(): void {
		$this->assertSame([], $this->draftEntriesFor(caller: 'bob'), "bob got the audit entries of alice's draft.");
	}//end testSomebodyElsesDraftHasNoEntriesInTheTrail()

	/**
	 * The owner still reads the entries of their own draft.
	 *
	 * @return void
	 */
	public function testTheOwnerStillGetsTheEntriesOfTheirDraft(): void {
		$this->assertSame(['obj-draft'], $this->draftEntriesFor(caller: 'alice'));
	}//end testTheOwnerStillGetsTheEntriesOfTheirDraft()

	/**
	 * An anonymous caller gets nothing, and the mapper is never asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallerGetsNothingAndAsksTheMapperNothing(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->expects($this->never())->method('findAll');

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $this->createMock(MagicMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			readEvents: $this->readsShown()
		);

		$page = $lister->page(userId: null, limit: 20);

		$this->assertSame([], $page['results']);
		$this->assertNull($page['nextCursor']);
		$this->assertSame(0, $page['scanned']);
	}//end testAnonymousCallerGetsNothingAndAsksTheMapperNothing()

	/**
	 * An entry whose object no longer resolves is absent, not present.
	 *
	 * @return void
	 */
	public function testAnEntryWhoseObjectIsGoneIsAbsent(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: 'obj-deleted')],
			objects: [],
			permissions: ['obj-deleted' => true]
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'An entry whose object did not resolve was returned anyway.');
	}//end testAnEntryWhoseObjectIsGoneIsAbsent()

	/**
	 * An entry whose schema does not resolve is absent.
	 *
	 * @return void
	 */
	public function testAnEntryWhoseSchemaIsGoneIsAbsent(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null): array {
				if ($offset !== null && $offset > 0) {
					return [];
				}

				return [$this->entry(objectUuid: self::READABLE)];
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('no such schema'));

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			schemaMapper: $schemaMapper,
			permissionHandler: $permissionHandler,
			readEvents: $this->readsShown()
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'An entry whose schema did not resolve was returned anyway.');
	}//end testAnEntryWhoseSchemaIsGoneIsAbsent()

	/**
	 * A permission check that throws hides the row rather than showing it.
	 *
	 * @return void
	 */
	public function testAThrowingPermissionCheckHidesTheRow(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null): array {
				if ($offset !== null && $offset > 0) {
					return [];
				}

				return [$this->entry(objectUuid: self::READABLE)];
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->createMock(Schema::class));

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willThrowException(new \RuntimeException('rbac unavailable'));

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			schemaMapper: $schemaMapper,
			permissionHandler: $permissionHandler,
			readEvents: $this->readsShown()
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'A throwing permission check let the row through.');
	}//end testAThrowingPermissionCheckHidesTheRow()

	/**
	 * The scoped row carries the change and withholds the instance fields.
	 *
	 * @return void
	 */
	public function testTheScopedRowWithholdsSessionRequestAndIp(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE, action: 'update', session: 'sess-abc')],
			objects: [$this->object(uuid: self::READABLE)],
			permissions: [self::READABLE => true]
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertCount(1, $page['results']);
		$row = $page['results'][0];

		$this->assertSame('update', $row['action']);
		$this->assertSame('alice', $row['user']);
		$this->assertArrayNotHasKey('session', $row, 'The scoped row carried the session id.');
		$this->assertArrayNotHasKey('request', $row, 'The scoped row carried the request id.');
		$this->assertArrayNotHasKey('ipAddress', $row, 'The scoped row carried the IP address.');
	}//end testTheScopedRowWithholdsSessionRequestAndIp()

	/**
	 * The scan is bounded: a caller who may read nothing does not walk the
	 * whole table, and is handed a cursor to continue from.
	 *
	 * @return void
	 */
	public function testTheScanIsBoundedWhenNothingIsReadable(): void {
		$full = [];
		for ($i = 0; $i < ReadableAuditTrailLister::BATCH_SIZE; $i++) {
			$full[] = $this->entry(objectUuid: self::HIDDEN . '-' . $i);
		}

		$calls = 0;
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function () use ($full, &$calls): array {
				$calls++;

				// An endless trail: every query answers a full batch.
				return $full;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([]);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			readEvents: $this->readsShown()
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results']);
		$this->assertSame(ReadableAuditTrailLister::SCAN_BUDGET, $page['scanned'], 'The scan did not stop at its budget.');
		$this->assertNotNull($page['nextCursor'], 'A budget-bounded short page must hand back a cursor to continue from.');
		$this->assertSame(
			intdiv(ReadableAuditTrailLister::SCAN_BUDGET, ReadableAuditTrailLister::BATCH_SIZE),
			$calls,
			'The scan queried more batches than its budget allows.'
		);
	}//end testTheScanIsBoundedWhenNothingIsReadable()

	/**
	 * The cursor advances past the rows that were filtered out, so the next
	 * page does not replay them.
	 *
	 * @return void
	 */
	public function testTheCursorAdvancesPastFilteredRows(): void {
		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::HIDDEN, action: 'create'),
				$this->entry(objectUuid: self::HIDDEN, action: 'update'),
				$this->entry(objectUuid: self::READABLE),
				$this->entry(objectUuid: self::HIDDEN, action: 'delete'),
			],
			objects: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::READABLE)],
			permissions: [self::READABLE => true, self::HIDDEN => false]
		);

		// A page of one: the readable row is the third of four candidates, so
		// filling the page consumes three and leaves the fourth for next time.
		$page = $lister->page(userId: 'alice', limit: 1);

		$this->assertCount(1, $page['results']);
		$this->assertSame(3, $page['scanned']);
		$this->assertSame(3, $page['nextCursor'], 'The cursor did not advance past the rows that were filtered out.');
	}//end testTheCursorAdvancesPastFilteredRows()

	/**
	 * An exhausted trail reports no next cursor.
	 *
	 * @return void
	 */
	public function testAnExhaustedTrailReportsNoNextCursor(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			objects: [$this->object(uuid: self::READABLE)],
			permissions: [self::READABLE => true]
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertCount(1, $page['results']);
		$this->assertNull($page['nextCursor'], 'A trail shorter than one batch reported more pages.');
	}//end testAnExhaustedTrailReportsNoNextCursor()

	/**
	 * A read-event rule that shows reads, for the cases that are not about it.
	 *
	 * @return ReadEventVisibility
	 */
	private function readsShown(): ReadEventVisibility {
		$visibility = $this->createMock(ReadEventVisibility::class);
		$visibility->method('maySeeReadEvents')->willReturn(true);
		$visibility->method('isReadAction')->willReturnCallback(static fn ($action): bool => $action === 'read');
		return $visibility;
	}//end readsShown()
}//end class
