<?php

/**
 * Unit tests for AgentMapper's single-row reads and its write path.
 *
 * Records every query-builder call and asserts the lookups by id and uuid,
 * the insert that stamps a uuid and timestamps, the update that reads the old
 * row first, the delete, and the events each write dispatches. A write on an
 * agent of another organisation is refused before any SQL runs or any event
 * fires.
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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapperPlatform;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The agent lookups and writes, as an admin with no active organisation.
 *
 * @covers \OCA\OpenRegister\Mcp\Db\AgentMapper
 * @uses   \OCA\OpenRegister\Mcp\Db\Agent
 * @uses   \OCA\OpenRegister\Mcp\Db\AgentColumns
 * @uses   \OCA\OpenRegister\Mcp\Db\AgentMapperPlatform
 * @uses   \OCA\OpenRegister\Event\AbstractAgentEvent
 * @uses   \OCA\OpenRegister\Event\AgentUpdatedEvent
 * @uses   \OCA\OpenRegister\Db\Tenancy\EntityRbacTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class AgentMapperWritePathTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The events the mapper dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * The mapper over a recording connection that serves the given result sets in order.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows One row list per executeQuery().
	 *
	 * @return AgentMapper
	 */
	private function mapper(array $rows = []): AgentMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$this->dispatched = [];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		return new AgentMapper(
			new AgentMapperPlatform($db, $events),
			$this->createMock(OrganisationMapperInterface::class),
			$session,
			$groups
		);
	}//end mapper()

	public function testFindLooksUpByIdAndKeepsGlobalAgentsVisible(): void {
		$agent = $this->mapper(rows: [[['id' => 7, 'uuid' => 'u-7', 'name' => 'Triage']]])->find(id: 7);

		$this->assertSame('u-7', $agent->getUuid());
		$this->assertSame('Triage', $agent->getName());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_agents, NULL)',
				'#1 param :p1 = 7, ' . IQueryBuilder::PARAM_INT,
				'#1 expr.eq(id, :p1, NULL)',
				'#1 where(eq(id, :p1, NULL))',
				'#1 expr.isNull(organisation)',
				'#1 andWhere(isNull(organisation))',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}//end testFindLooksUpByIdAndKeepsGlobalAgentsVisible()

	public function testFindByUuidLooksUpByUuid(): void {
		$agent = $this->mapper(rows: [[['id' => 7, 'uuid' => 'u-7']]])->findByUuid(uuid: 'u-7');

		$this->assertSame(7, $agent->getId());
		$this->assertContains('#1 param :p1 = u-7, ' . IQueryBuilder::PARAM_STR, $this->queryLog);
		$this->assertContains('#1 where(eq(uuid, :p1, NULL))', $this->queryLog);
	}//end testFindByUuidLooksUpByUuid()

	public function testAMissingAgentIsDoesNotExist(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper(rows: [[]])->find(id: 8);
	}//end testAMissingAgentIsDoesNotExist()

	public function testInsertStampsUuidAndTimestampsAndAnnouncesTheAgent(): void {
		$agent = new Agent();
		$agent->setName('New');

		$saved = $this->mapper()->insert(entity: $agent);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertSame('#1 insert(openregister_agents)', $this->queryLog[0]);
		$this->assertContains('#1 setValue(name, :p1)', $this->queryLog);
		$this->assertContains('#1 setValue(uuid, :p2)', $this->queryLog);
		$this->assertSame('#1 executeStatement()', end($this->queryLog));
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(AgentCreatedEvent::class, $this->dispatched[0]);
		$this->assertSame($saved, $this->dispatched[0]->getAgent());
	}//end testInsertStampsUuidAndTimestampsAndAnnouncesTheAgent()

	public function testInsertKeepsAGivenUuidAndCreatedStamp(): void {
		$created = new DateTime('2026-01-02 03:04:05');
		$agent = new Agent();
		$agent->setUuid('given-uuid');
		$agent->setCreated($created);

		$saved = $this->mapper()->insert(entity: $agent);

		$this->assertSame('given-uuid', $saved->getUuid());
		$this->assertSame($created, $saved->getCreated());
	}//end testInsertKeepsAGivenUuidAndCreatedStamp()

	public function testCreateFromArrayHydratesAndInserts(): void {
		$saved = $this->mapper()->createFromArray(data: ['name' => 'From array', 'provider' => 'openai']);

		$this->assertSame('From array', $saved->getName());
		$this->assertSame('openai', $saved->getProvider());
		$this->assertContains('#1 insert(openregister_agents)', $this->queryLog);
		$this->assertInstanceOf(AgentCreatedEvent::class, $this->dispatched[0]);
	}//end testCreateFromArrayHydratesAndInserts()

	public function testUpdateReadsTheOldRowFirstAndAnnouncesBoth(): void {
		$agent = new Agent();
		$agent->setId(3);
		$agent->setName('Renamed');
		$agent->resetUpdatedFields();
		$agent->setName('Renamed again');

		$saved = $this->mapper(rows: [[['id' => 3, 'uuid' => 'u-3', 'name' => 'Old']]])->update(entity: $agent);

		$this->assertSame('#1 select(*)', $this->queryLog[0]);
		$this->assertSame(
			[
				'#2 update(openregister_agents, NULL)',
				'#2 param :p2 = Renamed again, ' . IQueryBuilder::PARAM_STR,
				'#2 set(name, :p2)',
				'#2 param :p3 = DateTime, datetime',
				'#2 set(updated, :p3)',
				'#2 param :p4 = 3, ' . IQueryBuilder::PARAM_INT,
				'#2 expr.eq(id, :p4, NULL)',
				'#2 where(eq(id, :p4, NULL))',
				'#2 executeStatement()',
			],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_starts_with($line, '#2 ')))
		);
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(AgentUpdatedEvent::class, $this->dispatched[0]);
		$this->assertSame($saved, $this->dispatched[0]->getNewAgent());
		$this->assertSame('Old', $this->dispatched[0]->getOldAgent()->getName());
	}//end testUpdateReadsTheOldRowFirstAndAnnouncesBoth()

	public function testDeleteRemovesTheRowAndAnnouncesIt(): void {
		$agent = new Agent();
		$agent->setId(3);

		$deleted = $this->mapper()->delete(entity: $agent);

		$this->assertSame($agent, $deleted);
		$this->assertSame(
			[
				'#1 delete(openregister_agents, NULL)',
				'#1 param :p1 = 3, ' . IQueryBuilder::PARAM_INT,
				'#1 expr.eq(id, :p1, NULL)',
				'#1 where(eq(id, :p1, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
		$this->assertInstanceOf(AgentDeletedEvent::class, $this->dispatched[0]);
		$this->assertSame($agent, $this->dispatched[0]->getAgent());
	}//end testDeleteRemovesTheRowAndAnnouncesIt()

	public function testAWriteOnAnotherOrganisationsAgentIsRefusedBeforeAnySql(): void {
		foreach (['update', 'delete'] as $method) {
			$agent = new Agent();
			$agent->setId(3);
			$agent->setOrganisation('org-other');
			$mapper = $this->mapper(rows: [[['id' => 3, 'uuid' => 'u-3']]]);

			try {
				$mapper->$method(entity: $agent);
				$this->fail($method . ' of another organisation\'s agent was allowed.');
			} catch (Exception $e) {
				$this->assertSame(403, $e->getCode(), $method);
			}

			$this->assertSame([], $this->queryLog, $method);
			$this->assertSame([], $this->dispatched, $method);
		}
	}//end testAWriteOnAnotherOrganisationsAgentIsRefusedBeforeAnySql()
}//end class
