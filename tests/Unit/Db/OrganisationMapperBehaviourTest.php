<?php

/**
 * Behaviour test for OrganisationMapper: what each lookup, write and guard decides.
 *
 * Covers save (uuid validation, slug conflicts, insert vs update, events),
 * hierarchy guards (self parent, missing parent, cycles, depth), the active
 * organisation preference (cache, fallback to the default, insert vs update),
 * config reads and merge-chain resolution (end, cycle, hop limit).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Support\ConfigServices;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OrganisationMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const UUID = '0b9c2a64-3c1e-4c6f-9d6a-1f2e3d4c5b6a';

	/** @var list<list<string>|Exception> */
	private array $preparedQueue = [];

	/** @var list<string> */
	private array $preparedSql = [];

	/** @var list<Event> */
	private array $events = [];

	/** @var array<string, string> */
	private array $appValues = [];

	/** @var LoggerInterface The logger handed to the mapper. */
	private LoggerInterface $logger;

	private function mapper(array $rows = [], ?IDBConnection $db = null): OrganisationMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		if ($db === null) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
			$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->prepared($sql));
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->events[] = $event;
		});
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appValues[$key] ?? $default
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnMap([['dbtableprefix', 'oc_', 'nc_']]);
		$this->logger ??= $this->createMock(LoggerInterface::class);

		return new OrganisationMapper($db, $this->logger, new EntityEventPublisher(dispatcher: $dispatcher), new ConfigServices($appConfig, $config));
	}

	private function prepared(string $sql): IPreparedStatement {
		$this->preparedSql[] = $sql;
		$next = array_shift($this->preparedQueue) ?? [];
		$stmt = $this->createMock(IPreparedStatement::class);
		if ($next instanceof Exception) {
			$stmt->method('execute')->willThrowException($next);
			return $stmt;
		}

		$rows = array_map(static fn (string $uuid): array => ['uuid' => $uuid], $next);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
			return array_shift($rows) ?? false;
		});
		$stmt->method('execute')->willReturn($result);
		return $stmt;
	}

	private static function org(string $uuid, ?string $parent = null, ?string $mergedInto = null): array {
		return ['id' => 1, 'uuid' => $uuid, 'name' => 'Org ' . $uuid, 'parent' => $parent, 'merged_into' => $mergedInto, 'users' => '["alice","bob"]'];
	}

	public function testInsertGivesANewOrganisationAUuidAndAnnouncesIt(): void {
		$organisation = new Organisation();
		$organisation->setName('New');
		$saved = $this->mapper()->insert($organisation);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $saved->getUuid());
		$this->assertNotNull($saved->getCreated());
		$this->assertContains('#1 insert(openregister_organisations)', $this->queryLog);
		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(OrganisationCreatedEvent::class, $this->events[0]);
	}

	public function testInsertKeepsAnExistingUuid(): void {
		$organisation = new Organisation();
		$organisation->setUuid(self::UUID);
		$this->assertSame(self::UUID, $this->mapper()->insert($organisation)->getUuid());
	}

	public function testUpdateAndDeleteAnnounceTheirEvent(): void {
		$organisation = new Organisation();
		$organisation->setId(4);
		$organisation->setName('Renamed');
		$mapper = $this->mapper();
		$mapper->update($organisation);
		$mapper->delete($organisation);

		$this->assertInstanceOf(OrganisationUpdatedEvent::class, $this->events[0]);
		$this->assertInstanceOf(OrganisationDeletedEvent::class, $this->events[1]);
		$this->assertContains('#1 expr.eq(id, :p1, NULL)', $this->queryLog, 'the previous state is loaded for the event');
		$this->assertContains('#2 update(openregister_organisations, NULL)', $this->queryLog);
		$this->assertContains('#2 set(name, :p2)', $this->queryLog);
		$this->assertContains('#3 delete(openregister_organisations, NULL)', $this->queryLog);
	}

	public function testSaveInsertsANewOrganisationWithAGeneratedUuid(): void {
		$organisation = new Organisation();
		$organisation->setName('Fresh');
		$saved = $this->mapper()->save($organisation);

		$this->assertNotEmpty($saved->getUuid());
		$this->assertNotNull($saved->getCreated());
		$this->assertInstanceOf(OrganisationCreatedEvent::class, $this->events[0]);
	}

	public function testSaveRefusesAMalformedUuid(): void {
		$organisation = new Organisation();
		$organisation->setUuid('not-a-uuid');
		$this->expectExceptionMessage('Invalid UUID format');
		$this->mapper()->save($organisation);
	}

	public function testSaveRefusesAUuidAnotherOrganisationHolds(): void {
		$organisation = new Organisation();
		$organisation->setId(3);
		$organisation->setUuid(self::UUID);
		$mapper = $this->mapper([[['id' => 9]]]);
		try {
			$mapper->save($organisation);
			$this->fail('A taken uuid must be refused');
		} catch (Exception $e) {
			$this->assertSame('UUID already exists. Please use a different UUID.', $e->getMessage());
		}

		$this->assertContains('#1 param :p2 = 3, 1', $this->queryLog);
		$this->assertContains('#1 andWhere(neq(id, :p2, NULL))', $this->queryLog);
		$this->assertSame([], $this->events);
	}

	public function testSaveUpdatesAnExistingOrganisationWithAFreeUuid(): void {
		$organisation = new Organisation();
		$organisation->setId(3);
		$organisation->setUuid(self::UUID);
		$organisation->resetUpdatedFields();
		$saved = $this->mapper([[]])->save($organisation);

		$this->assertNull($saved->getCreated());
		$this->assertNotNull($saved->getUpdated());
		$this->assertInstanceOf(OrganisationUpdatedEvent::class, $this->events[0]);
	}

	public function testSaveRefusesASlugThatIsTakenBeforeInserting(): void {
		$organisation = new Organisation();
		$organisation->setSlug('acme');
		$mapper = $this->mapper([[self::org('other')]]);
		try {
			$mapper->save($organisation);
			$this->fail('A taken slug must be refused');
		} catch (OrganisationSlugConflictException $e) {
			$this->assertSame('acme', $e->getSlug());
		}

		$this->assertNotContains('#2 insert(openregister_organisations)', $this->queryLog);
		$this->assertSame([], $this->events);
	}

	public function testSaveTurnsAnInsertRaceOnTheSlugIntoAConflict(): void {
		$organisation = new Organisation();
		$organisation->setSlug('acme');
		$db = $this->createMock(IDBConnection::class);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls): IQueryBuilder {
			$calls++;
			$qb = $this->recordingBuilder();
			if ($calls === 2) {
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('insert')->willThrowException(new Exception('duplicate key'));
			}

			return $qb;
		});
		$mapper = $this->mapper([[], [self::org('racer')]], $db);

		try {
			$mapper->save($organisation);
			$this->fail('The race must surface as a slug conflict');
		} catch (OrganisationSlugConflictException $e) {
			$this->assertSame('duplicate key', $e->getPrevious()?->getMessage());
		}
	}

	public function testSaveRethrowsAnInsertFailureThatIsNotASlugConflict(): void {
		$organisation = new Organisation();
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willThrowException(new Exception('disk full'));
		$db->method('getQueryBuilder')->willReturn($qb);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('error')->with('[OrganisationMapper] insert() failed: disk full');

		$this->expectExceptionMessage('disk full');
		$this->mapper([], $db)->save($organisation);
	}

	public function testAddAndRemoveUserUpdateTheMembership(): void {
		$mapper = $this->mapper([[self::org('o1')], [self::org('o1')], [self::org('o1')], [self::org('o1')]]);
		$this->assertSame(['alice', 'bob', 'carol'], array_values($mapper->addUserToOrganisation('o1', 'carol')->getUserIds()));
		$this->assertSame(['bob'], array_values($mapper->removeUserFromOrganisation('o1', 'alice')->getUserIds()));
		$this->assertCount(2, $this->events);
	}

	public function testFindByUuidAndBySlugBindTheirKey(): void {
		$mapper = $this->mapper([[self::org('o1')], [self::org('o2')]]);
		$this->assertSame('o1', $mapper->findByUuid('o1')->getUuid());
		$this->assertSame('o2', $mapper->findBySlug('two')->getUuid());
		$this->assertContains('#2 param :p2 = two, 2', $this->queryLog);
		$this->assertContains('#2 setMaxResults(1)', $this->queryLog);
	}

	public function testFindMultipleByUuidKeysByUuidAndSkipsAnEmptyList(): void {
		$this->assertSame([], $this->mapper()->findMultipleByUuid([]));
		$this->assertSame(0, $this->builderCount);

		$found = $this->mapper([[self::org('a'), self::org('b')]])->findMultipleByUuid(['a', 'b']);
		$this->assertSame(['a', 'b'], array_keys($found));
		$this->assertContains('#1 param :p1 = ["a","b"], 102', $this->queryLog);
	}

	public function testFindByUserIdMatchesTheQuotedUserInTheMemberList(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($db): IQueryBuilder {
			$qb = $this->recordingBuilder();
			$qb->method('getConnection')->willReturn($db);
			return $qb;
		});
		$found = $this->mapper([[self::org('o1')]], $db)->findByUserId('alice');
		$this->assertCount(1, $found);
		$this->assertContains('#1 param :p1 = %"alice"%, 2', $this->queryLog);
		$this->assertContains('#1 expr.like(users, :p1, NULL)', $this->queryLog);
	}

	public function testFindAllWithUserCountCountsMembers(): void {
		$found = $this->mapper([[self::org('o1')]])->findAllWithUserCount();
		$this->assertSame(2, $found[0]->userCount);
		$this->assertContains('#1 orderBy(name, ASC)', $this->queryLog);
	}

	public function testUuidExistsWithoutExclusion(): void {
		$this->assertFalse($this->mapper([[]])->uuidExists('x'));
		$this->assertFalse(in_array('#1 expr.neq(id, :p2, NULL)', $this->queryLog, true));
		$this->assertTrue($this->mapper([[['id' => 1]]])->uuidExists('x'));
	}

	public function testFindLocalTenantsKeepsLocalAndUnflaggedOrganisations(): void {
		$this->mapper([[self::org('o1')]])->findLocalTenants(5, 10);
		$this->assertContains('#1 expr.isNull(is_local_tenant)', $this->queryLog);
		$this->assertContains('#1 param :p1 = true, boolean', $this->queryLog);
		$this->assertContains('#1 setMaxResults(5)', $this->queryLog);
		$this->assertContains('#1 setFirstResult(10)', $this->queryLog);
	}

	public function testFindAllAndFindByNamePage(): void {
		$mapper = $this->mapper([[self::org('o1')], [self::org('o2')]]);
		$this->assertCount(1, $mapper->findAll(7, 14));
		$this->assertContains('#1 setFirstResult(14)', $this->queryLog);
		$this->assertFalse(in_array('#1 expr.isNull(is_local_tenant)', $this->queryLog, true));
		$this->assertCount(1, $mapper->findListByName('ac', new ListQuery(3, 6)));
		$this->assertContains('#2 param :p1 = %ac%, 2', $this->queryLog);
		$this->assertContains('#2 setMaxResults(3)', $this->queryLog);
	}

	public function testGetStatisticsCountsAll(): void {
		$this->assertSame(['total' => 12], $this->mapper([[['total' => '12']]])->getStatistics());
	}

	public function testParentChainReadsTheRecursiveQueryWithTheTablePrefix(): void {
		$this->preparedQueue = [['p1', 'p2']];
		$this->assertSame(['p1', 'p2'], $this->mapper()->findParentChain('o1'));
		$this->assertStringContainsString('FROM nc_openregister_organisations', $this->preparedSql[0]);
		$this->assertStringContainsString('WHERE uuid = :org_uuid', $this->preparedSql[0]);
	}

	public function testChainsAreEmptyWhenTheQueryFails(): void {
		$this->preparedQueue = [new Exception('boom'), new Exception('boom')];
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->exactly(2))->method('error');
		$mapper = $this->mapper();
		$this->assertSame([], $mapper->findParentChain('o1'));
		$this->assertSame([], $mapper->findChildrenChain('o1'));
	}

	public function testChildrenChainReadsDirectAndDeeperChildren(): void {
		$this->preparedQueue = [['c1', 'c2']];
		$this->assertSame(['c1', 'c2'], $this->mapper()->findChildrenChain('o1'));
		$this->assertStringContainsString('WHERE parent = :org_uuid', $this->preparedSql[0]);
	}

	public function testHierarchyIsTheOrganisationFollowedByItsParents(): void {
		$this->preparedQueue = [['p1'], []];
		$mapper = $this->mapper();
		$this->assertSame(['o1', 'p1'], $mapper->getOrganisationHierarchy('o1'));
		$this->assertSame(['o2'], $mapper->getOrganisationHierarchy('o2'));
	}

	public function testANullParentNeedsNoLookups(): void {
		$this->mapper()->validateParentAssignment('o1', null);
		$this->assertSame(0, $this->builderCount);
		$this->assertSame([], $this->preparedSql);
	}

	public function testAnOrganisationCannotBeItsOwnParent(): void {
		$this->expectExceptionMessage('Organisation cannot be its own parent.');
		$this->mapper()->validateParentAssignment('o1', 'o1');
	}

	public function testAMissingParentIsRefused(): void {
		$this->expectExceptionMessage('Parent organisation not found.');
		$this->mapper([[]])->validateParentAssignment('o1', 'ghost');
	}

	public function testADescendantCannotBecomeTheParent(): void {
		$this->preparedQueue = [['x', 'o1']];
		$this->expectExceptionMessage('Circular reference detected');
		$this->mapper([[self::org('p')]])->validateParentAssignment('o1', 'p');
	}

	public function testAShallowParentAssignmentIsAccepted(): void {
		$this->preparedQueue = [['gp'], ['c1', 'c2']];
		$rows = [[self::org('p')], [self::org('c1', 'o1')], [self::org('c2', 'c1')]];
		$this->mapper($rows)->validateParentAssignment('o1', 'p');
		$this->assertCount(2, $this->preparedSql);
		$this->assertSame(3, $this->builderCount, 'the parent and each child are looked up');
	}

	public function testAChildThatCannotBeLoadedDoesNotCountTowardsDepth(): void {
		$this->preparedQueue = [[], ['gone']];
		$this->mapper([[self::org('p')], []])->validateParentAssignment('o1', 'p');
		$this->assertSame(2, $this->builderCount);
	}

	public function testATooDeepHierarchyIsRefused(): void {
		$parents = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'];
		$this->preparedQueue = [$parents, ['c1', 'c2']];
		$rows = [[self::org('p')], [self::org('c1', 'o1')], [self::org('c2', 'c1')]];
		$this->expectExceptionMessage('Total depth would be 11 levels');
		$this->mapper($rows)->validateParentAssignment('o1', 'p');
	}

	public function testExactlyTenLevelsIsAllowed(): void {
		$parents = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7'];
		$this->preparedQueue = [$parents, ['c1', 'c2']];
		$rows = [[self::org('p')], [self::org('c1', 'o1')], [self::org('c2', 'c1')]];
		$this->mapper($rows)->validateParentAssignment('o1', 'p');
		$this->assertSame(3, $this->builderCount);
	}

	public function testOnPostgresTheMemberListIsCastToTextBeforeMatching(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class));
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($db): IQueryBuilder {
			$qb = $this->recordingBuilder();
			$qb->method('getConnection')->willReturn($db);
			return $qb;
		});
		$this->mapper([[]], $db)->findByUserId('alice');

		$this->assertContains('#1 expr.like(CAST(users AS TEXT), :p2, NULL)', $this->queryLog);
		$this->assertContains('#1 where(like(CAST(users AS TEXT), :p2, NULL))', $this->queryLog);
	}

	public function testAParentForALeafNeedsNoChildLookups(): void {
		$this->preparedQueue = [[], []];
		$this->mapper([[self::org('p')]])->validateParentAssignment('o1', 'p');
		$this->assertSame(1, $this->builderCount);
		$this->assertCount(2, $this->preparedSql);
	}

	public function testActiveOrganisationIsReadOnceAndCached(): void {
		$mapper = $this->mapper([[['configvalue' => 'o7']]]);
		$this->assertSame('o7', $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame('o7', $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(1, $this->builderCount);
		$this->assertContains('#1 param :p3 = active_organisation, 2', $this->queryLog);
	}

	public function testNoPreferenceRowReadsAsNull(): void {
		$this->assertNull($this->mapper([[]])->getActiveOrganisationUuidForUser('alice'));
	}

	public function testAFailingPreferenceReadIsLoggedAndReadsAsNull(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning');
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $chained) {
			$qb->method($chained)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willThrowException(new Exception('db down'));
		$db->method('getQueryBuilder')->willReturn($qb);
		$this->assertNull($this->mapper([], $db)->getActiveOrganisationUuidForUser('alice'));
	}

	public function testFallbackReturnsTheStoredPreference(): void {
		$this->appValues = ['defaultOrganisation' => 'dflt'];
		$this->assertSame('o7', $this->mapper([[['configvalue' => 'o7']]])->getActiveOrganisationWithFallback('alice'));
		$this->assertSame(1, $this->builderCount);
	}

	public function testFallbackStoresTheDefaultWhenNothingIsActive(): void {
		$this->appValues = ['defaultOrganisation' => 'dflt'];
		$mapper = $this->mapper([[], []]);
		$this->assertSame('dflt', $mapper->getActiveOrganisationWithFallback('alice'));
		$this->assertContains('#3 insert(preferences)', $this->queryLog);
		$this->assertSame('dflt', $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(3, $this->builderCount, 'the stored default is cached');
	}

	public function testFallbackIsNullWithoutADefault(): void {
		$this->assertNull($this->mapper([[]])->getActiveOrganisationWithFallback('alice'));
		$this->assertSame(1, $this->builderCount);
	}

	public function testFallbackStillReturnsTheDefaultWhenStoringItFails(): void {
		$this->appValues = ['defaultOrganisation' => 'dflt'];
		$db = $this->createMock(IDBConnection::class);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls): IQueryBuilder {
			$calls++;
			if ($calls === 2) {
				throw new Exception('read only');
			}

			return $this->recordingBuilder();
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('read only'));
		$this->assertSame('dflt', $this->mapper([[]], $db)->getActiveOrganisationWithFallback('alice'));
	}

	public function testSetActiveOrganisationUpdatesAnExistingPreference(): void {
		$mapper = $this->mapper([[['userid' => 'alice']]]);
		$mapper->setActiveOrganisationForUser('alice', 'o9');
		$this->assertContains('#2 update(preferences, NULL)', $this->queryLog);
		$this->assertContains('#2 param :p4 = o9, 2', $this->queryLog);
		$this->assertNotContains('#3 insert(preferences)', $this->queryLog);
		$this->assertSame('o9', $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(2, $this->builderCount);
	}

	public function testEntityRbacEnforcementOnlyWhenEnabled(): void {
		$this->assertFalse($this->mapper()->isEntityRbacEnforcementEnabled());
		$this->appValues = ['rbac_entity_enforcement' => 'enabled'];
		$this->assertTrue($this->mapper()->isEntityRbacEnforcementEnabled());
		$this->appValues = ['rbac_entity_enforcement' => 'yes'];
		$this->assertFalse($this->mapper()->isEntityRbacEnforcementEnabled());
	}

	public function testDefaultOrganisationPrefersTheDedicatedKey(): void {
		$this->appValues = ['defaultOrganisation' => 'd1', 'organisation' => '{"default_organisation":"d2"}'];
		$this->assertSame('d1', $this->mapper()->getDefaultOrganisationFromConfig());
	}

	public function testDefaultOrganisationFallsBackToTheOrganisationBlob(): void {
		$this->appValues = ['organisation' => '{"default_organisation":"d2"}'];
		$this->assertSame('d2', $this->mapper()->getDefaultOrganisationFromConfig());
	}

	public function testDefaultOrganisationIsNullWhenTheBlobLacksIt(): void {
		$this->appValues = ['organisation' => '{"other":1}'];
		$this->assertNull($this->mapper()->getDefaultOrganisationFromConfig());
		$this->appValues = [];
		$this->assertNull($this->mapper()->getDefaultOrganisationFromConfig());
	}

	public function testMergeTargetFollowsTheChainToItsEnd(): void {
		$mapper = $this->mapper([[self::org('a', null, 'b')], [self::org('b', null, 'c')], [self::org('c')]]);
		$this->assertSame('c', $mapper->resolveMergeTarget('a'));
	}

	public function testMergeTargetStopsAtAMissingOrganisationOrSelfReference(): void {
		$this->assertSame('a', $this->mapper([[]])->resolveMergeTarget('a'));
		$this->assertSame('a', $this->mapper([[self::org('a', null, 'a')]])->resolveMergeTarget('a'));
	}

	public function testMergeTargetStopsAtACycleAndWarns(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning')->with('Organisation merge chain contains a cycle; stopping resolution');
		$mapper = $this->mapper([[self::org('a', null, 'b')], [self::org('b', null, 'a')]]);
		$this->assertSame('b', $mapper->resolveMergeTarget('a'));
	}

	public function testMergeTargetStopsAtTheHopLimitAndWarns(): void {
		$rows = [];
		for ($i = 0; $i < 20; $i++) {
			$rows[] = [self::org('n' . $i, null, 'n' . ($i + 1))];
		}

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning')->with('Organisation merge chain exceeded the hop limit; stopping resolution');
		$this->assertSame('n16', $this->mapper($rows)->resolveMergeTarget('n0'));
	}

	public function testFindByUuidFollowingMergeLoadsTheSuccessor(): void {
		$mapper = $this->mapper([[self::org('a', null, 'b')], [self::org('b')], [self::org('b')]]);
		$this->assertSame('b', $mapper->findByUuidFollowingMerge('a')->getUuid());
	}

	public function testFindByUuidThrowsWhenAbsent(): void {
		$this->expectException(DoesNotExistException::class);
		$this->mapper([[]])->findByUuid('ghost');
	}
}
