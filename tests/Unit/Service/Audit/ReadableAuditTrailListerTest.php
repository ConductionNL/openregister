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
 * The lister answers through the reader path of one object's history, so the
 * read check is ObjectReadAccess (the object GET) and the redaction is the
 * real HistoryEntryRedactor over the real PropertyRbacHandler.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryServices;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\BuildsAuditCursors;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister
 * @covers \OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateFieldRules
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker
 * @uses \OCA\OpenRegister\Service\Audit\HistoryEntryRedactor
 * @uses \OCA\OpenRegister\Service\Audit\PurposeRefusedException
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses \OCA\OpenRegister\Service\PropertyRbacHandler
 * @uses \OCA\OpenRegister\Service\PropertyAccessRequest
 * @uses \OCA\OpenRegister\Service\Audit\ReadableAuditCursor
 * @uses \OCA\OpenRegister\Service\Audit\PurposeGuard
 * @uses \OCA\OpenRegister\Service\Audit\PurposeContext
 */
class ReadableAuditTrailListerTest extends TestCase {

	use BuildsStateFieldRuleResolver;
	use BuildsAuditCursors;

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
	 * The schema id whose objects the doelbinding guard refuses.
	 *
	 * @var int
	 */
	private const PURPOSE_SCHEMA = 8;

	/**
	 * The arguments each read check was asked with.
	 *
	 * @var array<int, array{register: string, schema: string, id: string}>
	 */
	private array $readChecks = [];

	/**
	 * The arguments each candidate query was made with.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

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
		$entry->setChanged([
			'titel' => ['old' => 'Oud', 'new' => 'Nieuw'],
			'interneAantekening' => ['old' => 'klokkenluider', 'new' => 'geheim'],
		]);

		if ($session !== null) {
			$entry->setSession($session);
			$entry->setRequest('req-1');
			$entry->setIpAddress('203.0.113.9');
		}

		return $entry;
	}//end entry()

	/**
	 * An object entity with a uuid, a register and a schema.
	 *
	 * @param string $uuid     The uuid.
	 * @param int    $schemaId The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function object(string $uuid, int $schemaId = 7): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('5');
		$object->setSchema((string)$schemaId);
		$object->setOwner('bob');
		$object->setObject(['titel' => 'Nieuw', 'interneAantekening' => 'geheim']);

		return $object;
	}//end object()

	/**
	 * The schema of every object: `interneAantekening` is readable by `redactie` only.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $schemaId): Schema {
		$schema = new Schema();
		$schema->setId($schemaId);
		$schema->setProperties([
			'titel' => ['type' => 'string'],
			'interneAantekening' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'redactie']]],
			],
		]);

		return $schema;
	}//end schema()

	/**
	 * A lister over the given entries.
	 *
	 * The read check answers like the object GET: an object comes back only
	 * when it is in `$readable` AND is asked for by the register, schema and
	 * uuid the cross-table lookup located it by.
	 *
	 * @param array<AuditTrail>        $entries  The rows the mapper returns for the first query.
	 * @param array<ObjectEntity>      $located  The objects the cross-table lookup finds.
	 * @param array<string, bool>      $readable The read verdicts, by object uuid.
	 * @param array<int, string>       $groups   The caller's groups.
	 * @param ReadEventVisibility|null $reads    The read-event rule, or one that shows reads.
	 *
	 * @return ReadableAuditTrailLister The lister.
	 */
	private function lister(
		array $entries,
		array $located,
		array $readable,
		array $groups = ['staff'],
		?ReadEventVisibility $reads = null,
	): ReadableAuditTrailLister {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function (...$args) use ($entries): array {
				$this->queries[] = $args;
				// One page of candidates, then nothing: the trail is short.
				return (($args[1] ?? 0) > 0) ? [] : $entries;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn($located);

		return new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $this->history(located: $located, readable: $readable, groups: $groups, reads: $reads),
			cursors: $this->auditCursors()
		);
	}//end lister()

	/**
	 * The reader path of one object's history, over a stubbed object read.
	 *
	 * @param array<ObjectEntity> $located  The objects the read check may return.
	 * @param array<string, bool> $readable The read verdicts, by object uuid.
	 * @param array<int, string>  $groups   The caller's groups.
	 * @param ReadEventVisibility|null $reads The read-event rule, or one that shows reads.
	 *
	 * @return ObjectHistoryForReaders The history reader.
	 */
	private function history(array $located, array $readable, array $groups, ?ReadEventVisibility $reads = null): ObjectHistoryForReaders {
		$byUuid = [];
		foreach ($located as $object) {
			$byUuid[$object->getUuid()] = $object;
		}

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			function (string $register, string $schema, string $id) use ($byUuid, $readable): ?ObjectEntity {
				$this->readChecks[] = ['register' => $register, 'schema' => $schema, 'id' => $id];
				$object = ($byUuid[$id] ?? null);
				if ($object === null || ($readable[$id] ?? false) === false) {
					return null;
				}

				if ((string)$object->getRegister() !== $register || (string)$object->getSchema() !== $schema) {
					return null;
				}

				return $object;
			}
		);
		$access->method('schemaOf')->willReturnCallback(
			fn (ObjectEntity $object): Schema => $this->schema(schemaId: (int)$object->getSchema())
		);

		$register = new Register();
		$register->setId(5);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$purposeGuard = $this->createMock(PurposeGuard::class);
		$purposeGuard->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema): ?string {
				if ($schema !== null && $schema->getId() === self::PURPOSE_SCHEMA) {
					throw new PurposeRefusedException(rule: 'purpose-required', reason: 'Name a purpose.');
				}

				return null;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		$properties = new PropertyRbacHandler(
			$session,
			$groupManager,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($session, $groupManager)
		);

		return new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$this->createMock(AuditTrailMapper::class),
				new HistoryEntryRedactor($properties),
				$registerMapper
			),
			$purposeGuard,
			($reads ?? $this->readsShown())
		);
	}//end history()

	/**
	 * THE assertion of this suite: the entry on an object the caller may not
	 * read is absent, while the entry on the readable object is present.
	 *
	 * The read verdict comes from the object read path, which applies the
	 * organisation boundary: an object of another organisation is refused
	 * there, and so its entries are absent here.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectsEntryIsAbsent(): void {
		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::HIDDEN),
				$this->entry(objectUuid: self::READABLE),
			],
			located: [
				$this->object(uuid: self::HIDDEN),
				$this->object(uuid: self::READABLE),
			],
			readable: [self::READABLE => true, self::HIDDEN => false]
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$uuids = array_column($page['results'], 'objectUuid');

		$this->assertNotContains(self::HIDDEN, $uuids, 'An entry on an object the caller may not read was returned.');
		$this->assertContains(self::READABLE, $uuids, 'The entry on the readable object was dropped.');
		$this->assertCount(1, $page['results']);
	}//end testAnUnreadableObjectsEntryIsAbsent()

	/**
	 * Each object is read through the object read path by the register, schema and uuid it was located by, once.
	 *
	 * @return void
	 */
	public function testEachObjectIsReadOnceByWhereItWasLocated(): void {
		$located = $this->object(uuid: self::READABLE);
		$located->setRegister('12');
		$located->setSchema('7');

		$this->lister(
			entries: [
				$this->entry(objectUuid: self::READABLE, action: 'create'),
				$this->entry(objectUuid: self::READABLE, action: 'update'),
			],
			located: [$located],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertSame(
			[['register' => '12', 'schema' => '7', 'id' => self::READABLE]],
			$this->readChecks,
			'The object was not read once, by the place the lookup found it.'
		);
	}//end testEachObjectIsReadOnceByWhereItWasLocated()

	/**
	 * An object the lookup finds twice is read once, and its rows are listed once.
	 *
	 * @return void
	 */
	public function testAnObjectLocatedTwiceIsReadOnce(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertCount(1, $page['results']);
		$this->assertCount(1, $this->readChecks, 'An object found in two tables was read twice.');
	}//end testAnObjectLocatedTwiceIsReadOnce()

	/**
	 * A redaction that does not answer one entry per row hides the rows instead of failing the page.
	 *
	 * @return void
	 */
	public function testAShortRedactionHidesTheRows(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : [
				$this->entry(objectUuid: self::READABLE, action: 'create'),
				$this->entry(objectUuid: self::READABLE, action: 'update'),
			]
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);
		$history->method('readerEntries')->willReturn([['uuid' => 'only-one']]);

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $history,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results']);
	}//end testAShortRedactionHidesTheRows()

	/**
	 * The reader path answers null, without a read, for an object without a uuid, and for one whose schema is gone.
	 *
	 * @return void
	 */
	public function testTheReaderPathAnswersNullForAnUnknown(): void {
		$access = $this->createMock(ObjectReadAccess::class);
		$access->expects($this->once())->method('readable')->willReturn($this->object(uuid: self::READABLE));
		$access->method('schemaOf')->willReturn(null);
		$redactor = $this->createMock(HistoryEntryRedactor::class);
		$redactor->expects($this->never())->method('redact');

		$reader = new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$this->createMock(AuditTrailMapper::class),
				$redactor,
				$this->createMock(RegisterMapper::class)
			),
			$this->createMock(PurposeGuard::class),
			$this->readsShown()
		);

		$this->assertNull($reader->readerEntries(located: new ObjectEntity(), rows: [$this->entry(objectUuid: '')]));
		$this->assertNull($reader->readerEntries(located: $this->object(uuid: self::READABLE), rows: [$this->entry(objectUuid: self::READABLE)]));
	}//end testTheReaderPathAnswersNullForAnUnknown()

	/**
	 * A read check that answers with a different object than the one asked for hides the rows.
	 *
	 * @return void
	 */
	public function testARowIsHiddenWhenTheReadCheckAnswersAnotherObject(): void {
		$other = $this->object(uuid: 'obj-other');
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);

		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : [$this->entry(objectUuid: self::READABLE)]
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturn($other);
		$access->method('schemaOf')->willReturn($this->schema(schemaId: 7));
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$redactor = $this->createMock(HistoryEntryRedactor::class);
		$redactor->method('redact')->willReturnCallback(
			static fn (array $rows): array => array_map(static fn (AuditTrail $row): array => $row->jsonSerialize(), $rows)
		);

		$reader = new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$auditMapper,
				$redactor,
				$registerMapper
			),
			$this->createMock(PurposeGuard::class),
			$this->readsShown()
		);

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $reader,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'Rows were shown on the strength of a read of another object.');
	}//end testARowIsHiddenWhenTheReadCheckAnswersAnotherObject()

	/**
	 * A property the caller may not read is not in the change set of a row they get.
	 *
	 * @return void
	 */
	public function testAPropertyHiddenFromTheCallerIsNotInTheChangeSet(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertCount(1, $page['results']);
		$changed = $page['results'][0]['changed'];
		$this->assertSame(['old' => 'Oud', 'new' => 'Nieuw'], $changed['titel']);
		$this->assertArrayNotHasKey('interneAantekening', $changed, 'A property behind a read rule reached the scoped list.');
		$this->assertStringNotContainsString('klokkenluider', (string)json_encode($page));
	}//end testAPropertyHiddenFromTheCallerIsNotInTheChangeSet()

	/**
	 * A property the object no longer holds stays hidden in the rows of its history.
	 *
	 * The read rule is decided on the stored object, and a cleared property is
	 * not in it any more, but its old values are still in the change set.
	 *
	 * @return void
	 */
	public function testAPropertyTheObjectNoLongerHoldsStaysHidden(): void {
		$object = $this->object(uuid: self::READABLE);
		$object->setObject(['titel' => 'Nieuw']);

		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$object],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertCount(1, $page['results']);
		$this->assertArrayNotHasKey('interneAantekening', $page['results'][0]['changed']);
		$this->assertStringNotContainsString('klokkenluider', (string)json_encode($page));
	}//end testAPropertyTheObjectNoLongerHoldsStaysHidden()

	/**
	 * A caller the property rule names does see it.
	 *
	 * @return void
	 */
	public function testACallerAllowedThePropertySeesItInTheChangeSet(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true],
			groups: ['redactie']
		)->page(userId: 'alice', limit: 20);

		$this->assertSame(['old' => 'klokkenluider', 'new' => 'geheim'], $page['results'][0]['changed']['interneAantekening']);
	}//end testACallerAllowedThePropertySeesItInTheChangeSet()

	/**
	 * A search cannot find a value the caller may not see, and is not handed to the stored-payload query.
	 *
	 * @return void
	 */
	public function testASearchCannotFindAWithheldValue(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		);

		$this->assertSame([], $lister->page(userId: 'alice', limit: 20, search: 'klokkenluider')['results'], 'A search matched a withheld value.');
		$this->assertCount(1, $lister->page(userId: 'alice', limit: 20, search: 'nieuw')['results'], 'A search on a visible value found nothing.');
		foreach ($this->queries as $query) {
			$this->assertNull(($query[4] ?? null), 'The search term reached the query on the stored change set.');
		}
	}//end testASearchCannotFindAWithheldValue()

	/**
	 * Filters on the stored change set and the withheld fields do not reach the candidate query.
	 *
	 * @return void
	 */
	public function testFiltersOnWithheldColumnsAreDropped(): void {
		$this->lister(
			entries: [$this->entry(objectUuid: self::READABLE, session: 'sess-abc')],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		)->page(
			userId: 'alice',
			limit: 20,
			filters: ['changed' => 'x', 'session' => 'sess-abc', 'request' => 'req-1', 'ip_address' => '203.0.113.9', 'action' => 'update']
		);

		$this->assertSame(['action' => 'update'], $this->queries[0][2], 'A filter on a withheld column reached the query.');
	}//end testFiltersOnWithheldColumnsAreDropped()

	/**
	 * The doelbinding refusal of an object's read hides its rows, and only its rows.
	 *
	 * @return void
	 */
	public function testADoelbindingRefusalHidesThatObjectsRows(): void {
		$page = $this->lister(
			entries: [
				$this->entry(objectUuid: 'obj-purpose'),
				$this->entry(objectUuid: self::READABLE),
			],
			located: [
				$this->object(uuid: 'obj-purpose', schemaId: self::PURPOSE_SCHEMA),
				$this->object(uuid: self::READABLE),
			],
			readable: ['obj-purpose' => true, self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertSame([self::READABLE], array_column($page['results'], 'objectUuid'));
	}//end testADoelbindingRefusalHidesThatObjectsRows()

	/**
	 * A caller outside the groups named for read entries gets the feed without them.
	 *
	 * @return void
	 */
	public function testReadEntriesAreLeftOutForACallerWhoMayNotSeeThem(): void {
		$hidden = $this->createMock(ReadEventVisibility::class);
		$hidden->method('maySeeReadEvents')->willReturn(false);
		$hidden->method('isReadAction')->willReturnCallback(static fn ($action): bool => $action === 'read');

		$page = $this->lister(
			entries: [
				$this->entry(objectUuid: self::READABLE, action: 'read'),
				$this->entry(objectUuid: self::READABLE),
			],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true],
			reads: $hidden
		)->page(userId: 'alice', limit: 20);

		$this->assertSame(['update'], array_column($page['results'], 'action'), 'a read entry reached a caller who may not see reads');
	}//end testReadEntriesAreLeftOutForACallerWhoMayNotSeeThem()

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
			history: $this->createMock(ObjectHistoryForReaders::class),
			cursors: $this->auditCursors()
		);

		$page = $lister->page(userId: null, limit: 20);

		$this->assertSame([], $page['results']);
		$this->assertNull($page['nextCursor']);
		$this->assertArrayNotHasKey('scanned', $page);
	}//end testAnonymousCallerGetsNothingAndAsksTheMapperNothing()

	/**
	 * An entry whose object no longer resolves is absent, not present.
	 *
	 * @return void
	 */
	public function testAnEntryWhoseObjectIsGoneIsAbsent(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: 'obj-deleted')],
			located: [],
			readable: ['obj-deleted' => true]
		)->page(userId: 'alice', limit: 20);

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
			fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : [$this->entry(objectUuid: self::READABLE)]
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturn($this->object(uuid: self::READABLE));
		$access->method('schemaOf')->willReturn(null);

		$reader = new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$auditMapper,
				$this->createMock(HistoryEntryRedactor::class),
				$this->createMock(RegisterMapper::class)
			),
			$this->createMock(PurposeGuard::class),
			$this->readsShown()
		);

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $reader,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'An entry whose schema did not resolve was returned anyway.');
	}//end testAnEntryWhoseSchemaIsGoneIsAbsent()

	/**
	 * A read path that throws hides the row rather than showing it.
	 *
	 * @return void
	 */
	public function testAThrowingReadPathHidesTheRow(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : [$this->entry(objectUuid: self::READABLE)]
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->object(uuid: self::READABLE)]);
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);
		$history->method('readerEntries')->willThrowException(new \RuntimeException('rbac unavailable'));

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $history,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'A throwing read path let the row through.');
	}//end testAThrowingReadPathHidesTheRow()

	/**
	 * A cross-table lookup that throws hides the batch rather than showing it.
	 *
	 * @return void
	 */
	public function testAThrowingLookupHidesTheBatch(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : [$this->entry(objectUuid: self::READABLE)]
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willThrowException(new \RuntimeException('db down'));
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);
		$history->method('readerEntries')->willReturnCallback(
			static fn (ObjectEntity $located, array $rows): array => array_map(static fn (AuditTrail $row): array => $row->jsonSerialize(), $rows)
		);

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $history,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results'], 'A failed lookup let the batch through.');
	}//end testAThrowingLookupHidesTheBatch()

	/**
	 * The scoped row carries the change and withholds the instance fields.
	 *
	 * @return void
	 */
	public function testTheScopedRowWithholdsSessionRequestAndIp(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE, action: 'update', session: 'sess-abc')],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

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
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $history,
			cursors: $this->auditCursors()
		);

		$page = $lister->page(userId: 'alice', limit: 20);

		$this->assertSame([], $page['results']);
		$this->assertArrayNotHasKey('scanned', $page, 'The page counts the rows it walked, readable or not.');
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
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false]
		);

		// A page of one: the readable row is the third of four candidates, so
		// filling the page consumes three and leaves the fourth for next time.
		$page = $lister->page(userId: 'alice', limit: 1);

		$this->assertCount(1, $page['results']);
		$this->assertArrayNotHasKey('scanned', $page);

		$this->queries = [];
		$lister->page(userId: 'alice', limit: 1, cursor: $page['nextCursor']);
		$this->assertSame(3, $this->queries[0][1], 'The cursor did not advance past the rows that were filtered out.');
	}//end testTheCursorAdvancesPastFilteredRows()

	/**
	 * Rows keep the order of the trail when several objects interleave.
	 *
	 * @return void
	 */
	public function testRowsKeepTheOrderOfTheTrail(): void {
		$page = $this->lister(
			entries: [
				$this->entry(objectUuid: 'obj-a', action: 'update'),
				$this->entry(objectUuid: 'obj-b', action: 'update'),
				$this->entry(objectUuid: 'obj-a', action: 'create'),
			],
			located: [$this->object(uuid: 'obj-b'), $this->object(uuid: 'obj-a')],
			readable: ['obj-a' => true, 'obj-b' => true]
		)->page(userId: 'alice', limit: 20);

		$this->assertSame(
			['audit-obj-a-update', 'audit-obj-b-update', 'audit-obj-a-create'],
			array_column($page['results'], 'uuid')
		);
	}//end testRowsKeepTheOrderOfTheTrail()

	/**
	 * An exhausted trail reports no next cursor.
	 *
	 * @return void
	 */
	public function testAnExhaustedTrailReportsNoNextCursor(): void {
		$page = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		)->page(userId: 'alice', limit: 20);

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
		$visibility->method('filter')->willReturnArgument(0);
		return $visibility;
	}//end readsShown()

	/**
	 * Entries on one object, each with its own uuid.
	 *
	 * @param int    $count      How many.
	 * @param string $objectUuid The object they belong to.
	 *
	 * @return array<AuditTrail> The entries.
	 */
	private function entriesOf(int $count, string $objectUuid): array {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$entry = $this->entry(objectUuid: $objectUuid);
			$entry->setUuid('audit-' . $objectUuid . '-' . $i);
			$rows[] = $entry;
		}

		return $rows;
	}//end entriesOf()

	/**
	 * The page carries nothing that tells another organisation's object has entries.
	 *
	 * Filtered on an object the caller may not read, a trail that holds its
	 * entries and a trail that holds none must answer alike.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectsEntriesLeaveNoCountInThePage(): void {
		$withEntries = $this->lister(
			entries: $this->entriesOf(count: 7, objectUuid: self::HIDDEN),
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		)->page(userId: 'alice', limit: 20, filters: ['object_uuid' => self::HIDDEN]);
		$withNone = $this->lister(entries: [], located: [], readable: [])
			->page(userId: 'alice', limit: 20, filters: ['object_uuid' => self::HIDDEN]);

		$this->assertSame($withNone, $withEntries);
		$this->assertSame(['results', 'limit', 'cursor', 'nextCursor'], array_keys($withEntries));
	}//end testAnUnreadableObjectsEntriesLeaveNoCountInThePage()

	/**
	 * The cursor of a filled page carries no offset into the raw trail.
	 *
	 * @return void
	 */
	public function testTheNextCursorIsSealed(): void {
		$entries = $this->entriesOf(count: 150, objectUuid: self::HIDDEN);
		$entries[] = $this->entry(objectUuid: self::READABLE);
		$entries = array_merge($entries, $this->entriesOf(count: 10, objectUuid: self::HIDDEN . '-later'));

		$page = $this->lister(
			entries: $entries,
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false]
		)->page(userId: 'alice', limit: 1);

		$this->assertCount(1, $page['results']);
		$this->assertIsString($page['nextCursor']);
		$this->assertStringNotContainsString('151', $page['nextCursor']);
		$this->assertFalse(is_numeric($page['nextCursor']));
	}//end testTheNextCursorIsSealed()

	/**
	 * A cursor the list did not hand out starts at the top of the trail.
	 *
	 * @return void
	 */
	public function testAForgedCursorStartsAtTheTop(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		);

		$lister->page(userId: 'alice', limit: 1, cursor: 'sealed-forged');

		$this->assertSame(0, $this->queries[0][1]);
	}//end testAForgedCursorStartsAtTheTop()

	/**
	 * A plain offset is not a place to resume: every walk the client did not get a cursor for starts at the top.
	 *
	 * @return void
	 */
	public function testAPlainOffsetStartsAtTheTop(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		);

		$lister->page(userId: 'alice', limit: 1, cursor: '40');
		$lister->page(userId: 'alice', limit: 1, cursor: 40);

		$this->assertSame([0, 0], array_column($this->queries, 1));
	}//end testAPlainOffsetStartsAtTheTop()

	/**
	 * A cursor handed to one caller does not resume another caller's walk.
	 *
	 * @return void
	 */
	public function testACursorDoesNotResumeAnotherCallersWalk(): void {
		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::HIDDEN, action: 'create'),
				$this->entry(objectUuid: self::READABLE),
				$this->entry(objectUuid: self::HIDDEN, action: 'delete'),
			],
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false]
		);

		$page = $lister->page(userId: 'alice', limit: 1);
		$this->queries = [];
		$lister->page(userId: 'bob', limit: 1, cursor: $page['nextCursor']);

		$this->assertSame(0, $this->queries[0][1]);
	}//end testACursorDoesNotResumeAnotherCallersWalk()

	/**
	 * A cursor walked without a search does not resume a walk with one.
	 *
	 * @return void
	 */
	public function testACursorDoesNotResumeAnotherSearch(): void {
		$lister = $this->lister(
			entries: [
				$this->entry(objectUuid: self::HIDDEN, action: 'create'),
				$this->entry(objectUuid: self::READABLE),
				$this->entry(objectUuid: self::HIDDEN, action: 'delete'),
			],
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false]
		);

		$page = $lister->page(userId: 'alice', limit: 1);
		$this->queries = [];
		$lister->page(userId: 'alice', limit: 1, cursor: $page['nextCursor'], search: 'Nieuw');

		$this->assertSame(0, $this->queries[0][1]);
	}//end testACursorDoesNotResumeAnotherSearch()

	/**
	 * A list over objects of a purpose-bound schema records one refusal, not one per object.
	 *
	 * @return void
	 */
	public function testAPurposeBoundSchemaIsRefusedOnceAPage(): void {
		$located = [];
		$entries = [];
		$readable = [];
		for ($i = 0; $i < 5; $i++) {
			$uuid = 'obj-bound-' . $i;
			$located[] = $this->object(uuid: $uuid, schemaId: self::PURPOSE_SCHEMA);
			$entries[] = $this->entry(objectUuid: $uuid);
			$readable[$uuid] = true;
		}

		$entries[] = $this->entry(objectUuid: self::READABLE);
		$located[] = $this->object(uuid: self::READABLE);
		$readable[self::READABLE] = true;

		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->expects($this->once())->method('createPurposeRefusalEntry');

		$history = $this->historyWithRealGuard(located: $located, trail: $trail);
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			static fn (...$args): array => (($args[1] ?? 0) > 0) ? [] : $entries
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn($located);

		$page = (new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $history,
			cursors: $this->auditCursors()
		))->page(userId: 'alice', limit: 20);

		$this->assertSame([self::READABLE], array_column($page['results'], 'objectUuid'));
	}//end testAPurposeBoundSchemaIsRefusedOnceAPage()

	/**
	 * The reader path with the real doelbinding guard, refusing the purpose-bound schema.
	 *
	 * @param array<ObjectEntity> $located The objects, all readable.
	 * @param AuditTrailMapper    $trail   Where the guard records a refusal.
	 *
	 * @return ObjectHistoryForReaders The history reader.
	 */
	private function historyWithRealGuard(array $located, AuditTrailMapper $trail): ObjectHistoryForReaders {
		$byUuid = [];
		foreach ($located as $object) {
			$byUuid[$object->getUuid()] = $object;
		}

		$bound = new Schema();
		$bound->setId(self::PURPOSE_SCHEMA);
		$bound->setProperties(['titel' => ['type' => 'string']]);
		$bound->setConfiguration([PurposeGuard::ANNOTATION => true]);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			static fn (string $register, string $schema, string $id): ?ObjectEntity => ($byUuid[$id] ?? null)
		);
		$access->method('schemaOf')->willReturnCallback(
			fn (ObjectEntity $object): Schema => ((int)$object->getSchema() === self::PURPOSE_SCHEMA) ? $bound : $this->schema(schemaId: (int)$object->getSchema())
		);

		$register = new Register();
		$register->setId(5);
		$register->setConfiguration([]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$registry = $this->createMock(PurposeRegistry::class);
		$registry->method('hasAdministeredPurposes')->willReturn(true);
		$registry->method('requirePurpose')->willThrowException(
			new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'no purpose', purpose: null)
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['staff']);

		return new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$this->createMock(AuditTrailMapper::class),
				new HistoryEntryRedactor(
					new PropertyRbacHandler(
						$session,
						$groupManager,
						$this->createMock(ConditionMatcher::class),
						$this->createMock(LoggerInterface::class),
						self::stateFieldRuleResolver($session, $groupManager)
					)
				),
				$registerMapper
			),
			new PurposeGuard($registry, new PurposeContext($request), $trail, new NullLogger()),
			$this->readsShown()
		);
	}//end historyWithRealGuard()

	/**
	 * A lister whose trail honours the object_uuid filter, the offset and the limit.
	 *
	 * @param array<string, array<AuditTrail>> $trails   Entries by object uuid ('' = unfiltered).
	 * @param array<ObjectEntity>              $located  Objects the lookup finds.
	 * @param array<string, bool>              $readable Read verdicts by uuid.
	 *
	 * @return ReadableAuditTrailLister The lister.
	 */
	private function rw126Lister(array $trails, array $located, array $readable): ReadableAuditTrailLister {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('findAll')->willReturnCallback(
			function (...$args) use ($trails): array {
				$this->queries[] = $args;
				$limit = (int)($args[0] ?? 200);
				$offset = (int)($args[1] ?? 0);
				$key = (string)(($args[2] ?? [])['object_uuid'] ?? '');
				return array_slice(($trails[$key] ?? []), $offset, $limit);
			}
		);
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn($located);

		return new ReadableAuditTrailLister(
			auditTrailMapper: $auditMapper,
			objectMapper: $objectMapper,
			history: $this->history(located: $located, readable: $readable, groups: ['staff']),
			cursors: $this->auditCursors()
		);
	}//end rw126Lister()

	/**
	 * Whether a page says there is more, without the sealed token's random bytes.
	 *
	 * @param array $page The page.
	 *
	 * @return array The comparable shape.
	 */
	private function rw126Shape(array $page): array {
		return ['results' => array_column($page['results'], 'uuid'), 'more' => $page['nextCursor'] !== null];
	}//end rw126Shape()

	/**
	 * P1: a hidden object's trail of 2345 rows and one of 2346 rows must answer alike at every plain offset.
	 *
	 * @return void
	 */
	public function testRw126PlainOffsetDoesNotTellTheHiddenTrailsLength(): void {
		$short = $this->rw126Lister(
			trails: [self::HIDDEN => $this->entriesOf(count: 2345, objectUuid: self::HIDDEN)],
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		);
		$long = $this->rw126Lister(
			trails: [self::HIDDEN => $this->entriesOf(count: 2346, objectUuid: self::HIDDEN)],
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		);

		$a = $this->rw126Shape($short->page(userId: 'alice', limit: 20, cursor: '346', filters: ['object_uuid' => self::HIDDEN]));
		$b = $this->rw126Shape($long->page(userId: 'alice', limit: 20, cursor: '346', filters: ['object_uuid' => self::HIDDEN]));
		$this->assertSame($a, $b, 'offset 346: the answer tells a 2345-row hidden trail from a 2346-row one');
	}//end testRw126PlainOffsetDoesNotTellTheHiddenTrailsLength()

	/**
	 * P1b: a binary search over plain offsets must not recover a hidden trail's exact length.
	 *
	 * @return void
	 */
	public function testRw126BinarySearchDoesNotRecoverTheHiddenCount(): void {
		$secret = 5321;
		$lister = $this->rw126Lister(
			trails: [self::HIDDEN => $this->entriesOf(count: $secret, objectUuid: self::HIDDEN)],
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		);
		$lo = 0;
		$hi = 100000;
		$calls = 0;
		while ($lo < $hi) {
			$mid = intdiv($lo + $hi, 2);
			$page = $lister->page(userId: 'alice', limit: 20, cursor: (string)$mid, filters: ['object_uuid' => self::HIDDEN]);
			$calls++;
			if ($page['nextCursor'] === null) {
				$hi = $mid;
			} else {
				$lo = $mid + 1;
			}
		}

		$this->assertNotSame($secret, $lo + ReadableAuditTrailLister::SCAN_BUDGET - 1, 'the exact hidden count was recovered');
	}//end testRw126BinarySearchDoesNotRecoverTheHiddenCount()

	/**
	 * P2: a sealed cursor obtained under one filter must not resume another filter at its offset.
	 *
	 * Under filter A (a mixed user's trail) 37 unreadable rows precede the first readable one;
	 * the cursor after that row is replayed on the caller's own object, whose 100 entries are all readable.
	 *
	 * @return void
	 */
	public function testRw126ACursorDoesNotCarryItsOffsetToAnotherFilter(): void {
		$mixed = array_merge(
			$this->entriesOf(count: 37, objectUuid: self::HIDDEN),
			[$this->entry(objectUuid: self::READABLE)],
			$this->entriesOf(count: 50, objectUuid: self::HIDDEN . '-2')
		);
		$own = $this->entriesOf(count: 100, objectUuid: self::READABLE);
		$lister = $this->rw126Lister(
			trails: ['mixed' => $mixed, self::READABLE => $own],
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::HIDDEN . '-2'), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false, self::HIDDEN . '-2' => false]
		);

		$first = $lister->page(userId: 'alice', limit: 1, filters: ['object_uuid' => 'mixed']);
		$this->assertCount(1, $first['results']);
		$this->assertNotNull($first['nextCursor']);

		$replay = $lister->page(userId: 'alice', limit: 1, cursor: $first['nextCursor'], filters: ['object_uuid' => self::READABLE]);
		$got = ($replay['results'][0]['uuid'] ?? 'none');
		$this->assertSame('audit-' . self::READABLE . '-0', $got, 'the replayed cursor revealed its raw offset');
	}//end testRw126ACursorDoesNotCarryItsOffsetToAnotherFilter()

	/**
	 * Control: a filter with nothing readable and fewer than SCAN_BUDGET rows reports no more (must pass).
	 *
	 * @return void
	 */
	public function testRw126ControlShortHiddenTrailEnds(): void {
		$lister = $this->rw126Lister(
			trails: [self::HIDDEN => $this->entriesOf(count: 30, objectUuid: self::HIDDEN)],
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		);
		$page = $lister->page(userId: 'alice', limit: 20, filters: ['object_uuid' => self::HIDDEN]);
		$this->assertSame([], $page['results']);
		$this->assertNull($page['nextCursor']);
	}//end testRw126ControlShortHiddenTrailEnds()

	/**
	 * S1: what the controller hands the lister on page 2 (the cursor param also sits in filters) must still resume.
	 *
	 * @return void
	 */
	public function testRw139SecondPageResumesWhenTheCursorIsAlsoAFilter(): void {
		$own = $this->entriesOf(count: 100, objectUuid: self::READABLE);
		$lister = $this->rw126Lister(
			trails: [self::READABLE => $own],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		);
		$first = $lister->page(userId: 'alice', limit: 5, filters: ['object_uuid' => self::READABLE]);
		$second = $lister->page(userId: 'alice', limit: 5, cursor: $first['nextCursor'], filters: ['object_uuid' => self::READABLE, 'cursor' => $first['nextCursor']]);
		$this->assertSame('audit-' . self::READABLE . '-5', ($second['results'][0]['uuid'] ?? 'none'), 'page 2 restarted at the top');
	}//end testRw139SecondPageResumesWhenTheCursorIsAlsoAFilter()

	/**
	 * S2: two callers' scopes must differ even when a filter value is not valid UTF-8.
	 *
	 * @return void
	 */
	public function testRw139ScopeKeepsTheCallerWhenAFilterIsNotUtf8(): void {
		$cursors = $this->auditCursors();
		$a = $cursors->scope(userId: 'alice', query: ['filters' => ['x' => "\xff"], 'search' => '']);
		$b = $cursors->scope(userId: 'bob', query: ['filters' => ['x' => "\xff"], 'search' => '']);
		$this->assertFalse($a !== null && $a === $b, 'scope collapsed to one value for every caller');
		$this->assertNull($a, 'an unencodable scope must open nothing rather than fall back to a shared value');
	}//end testRw139ScopeKeepsTheCallerWhenAFilterIsNotUtf8()

	/**
	 * S3: P2 replay, with a column findAll ignores carrying a non-UTF-8 value on both walks.
	 *
	 * @return void
	 */
	public function testRw139ReplayAcrossFiltersWithAnIgnoredNonUtf8Key(): void {
		$mixed = array_merge(
			$this->entriesOf(count: 37, objectUuid: self::HIDDEN),
			[$this->entry(objectUuid: self::READABLE)],
			$this->entriesOf(count: 50, objectUuid: self::HIDDEN . '-2')
		);
		$own = $this->entriesOf(count: 100, objectUuid: self::READABLE);
		$lister = $this->rw126Lister(
			trails: ['mixed' => $mixed, self::READABLE => $own],
			located: [$this->object(uuid: self::HIDDEN), $this->object(uuid: self::HIDDEN . '-2'), $this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true, self::HIDDEN => false, self::HIDDEN . '-2' => false]
		);
		$first = $lister->page(userId: 'alice', limit: 1, filters: ['object_uuid' => 'mixed', 'x' => "\xff"]);
		$replay = $lister->page(userId: 'alice', limit: 1, cursor: $first['nextCursor'], filters: ['object_uuid' => self::READABLE, 'x' => "\xff"]);
		$this->assertSame('audit-' . self::READABLE . '-0', ($replay['results'][0]['uuid'] ?? 'none'), 'the replayed cursor kept its raw offset');
	}//end testRw139ReplayAcrossFiltersWithAnIgnoredNonUtf8Key()

	/**
	 * A search that cannot be encoded gets no cursor, and no cursor resumes it.
	 *
	 * @return void
	 */
	public function testAnUnencodableSearchIsHandedNoCursorAndResumesNothing(): void {
		$entries = $this->entriesOf(count: ReadableAuditTrailLister::SCAN_BUDGET + 10, objectUuid: self::HIDDEN);
		$lister = $this->rw126Lister(
			trails: ['' => $entries],
			located: [$this->object(uuid: self::HIDDEN)],
			readable: [self::HIDDEN => false]
		);

		$page = $lister->page(userId: 'alice', limit: 1, search: "\xff");
		$this->assertNull($page['nextCursor']);

		$sealed = $lister->page(userId: 'alice', limit: 1)['nextCursor'];
		$this->queries = [];
		$lister->page(userId: 'alice', limit: 1, cursor: $sealed, search: "\xff");
		$this->assertSame(0, $this->queries[0][1]);
	}//end testAnUnencodableSearchIsHandedNoCursorAndResumesNothing()

	/**
	 * Only the columns the trail query applies reach it, and only they make up the scope.
	 *
	 * @return void
	 */
	public function testOnlyAppliedColumnsReachTheQuery(): void {
		$lister = $this->lister(
			entries: [$this->entry(objectUuid: self::READABLE)],
			located: [$this->object(uuid: self::READABLE)],
			readable: [self::READABLE => true]
		);

		$lister->page(userId: 'alice', limit: 1, filters: ['object_uuid' => self::READABLE, 'x' => 'y', '_cursor' => 'c', 'cursor' => 'c']);

		$this->assertSame(['object_uuid' => self::READABLE], $this->queries[0][2]);
	}//end testOnlyAppliedColumnsReachTheQuery()

}//end class
