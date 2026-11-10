<?php

/**
 * The entity events the mappers dispatch on insert, update and delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Support\ConfigServices;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;

/**
 * Records every event a mapper dispatches, with its payload, for insert, update and delete.
 *
 * Other apps write through these mappers directly, so the events must keep firing in the same
 * order and with the same payload whatever carries them to the dispatcher.
 */
final class MapperEntityEventsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The events dispatched, as [class, payload].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $events = [];

	/**
	 * The entity the test handed to the mapper.
	 *
	 * @var Entity|null
	 */
	private ?Entity $given = null;

	/**
	 * The acting user.
	 *
	 * @var IUser
	 */
	private IUser $user;

	protected function setUp(): void {
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
	}//end setUp()

	public function testApplicationMapperAnnouncesInsertUpdateAndDelete(): void {
		$mapper = new ApplicationMapper($this->connection(), $this->tenancy(), $this->entityEvents());

		$mapper->insert($this->given(new Application(), ['uuid' => 'a-1', 'organisation' => 'org-1']));
		$this->queuedRows = [[['id' => 5, 'uuid' => 'a-1', 'organisation' => 'org-1', 'name' => 'Old']]];
		$mapper->update($this->given(new Application(), ['id' => 5, 'uuid' => 'a-1', 'organisation' => 'org-1', 'name' => 'New']));
		$mapper->delete($this->given(new Application(), ['id' => 5, 'organisation' => 'org-1']));

		$this->assertSame(
			[
				['ApplicationCreatedEvent', ['application' => 'given']],
				['ApplicationUpdatedEvent', ['newApplication' => 'given', 'oldApplication' => 'Application#5 Old']],
				['ApplicationDeletedEvent', ['application' => 'given']],
			],
			$this->events
		);
	}

	public function testConfigurationMapperAnnouncesInsertUpdateAndDelete(): void {
		$mapper = new ConfigurationMapper($this->connection(), $this->tenancy(), $this->entityEvents());

		$mapper->insert($this->given(new Configuration(), ['uuid' => 'c-1', 'organisation' => 'org-1']));
		$this->queuedRows = [[['id' => 6, 'uuid' => 'c-1', 'organisation' => 'org-1', 'title' => 'Old']]];
		$mapper->update($this->given(new Configuration(), ['id' => 6, 'uuid' => 'c-1', 'organisation' => 'org-1', 'title' => 'New']));
		$mapper->delete($this->given(new Configuration(), ['id' => 6, 'organisation' => 'org-1']));

		$this->assertSame(
			[
				['ConfigurationCreatedEvent', ['configuration' => 'given']],
				['ConfigurationUpdatedEvent', ['newConfiguration' => 'given', 'oldConfiguration' => 'Configuration#6 Old']],
				['ConfigurationDeletedEvent', ['configuration' => 'given']],
			],
			$this->events
		);
	}

	public function testSourceMapperAnnouncesInsertUpdateAndDelete(): void {
		$mapper = new SourceMapper($this->connection(), $this->tenancy(), $this->entityEvents(), $this->createMock(IAppConfig::class));

		$mapper->insert($this->given(new Source(), ['uuid' => 's-1', 'organisation' => 'org-1']));
		$this->queuedRows = [[['id' => 7, 'uuid' => 's-1', 'organisation' => 'org-1', 'title' => 'Old']]];
		$mapper->update($this->given(new Source(), ['id' => 7, 'uuid' => 's-1', 'organisation' => 'org-1', 'title' => 'New']));
		$mapper->delete($this->given(new Source(), ['id' => 7, 'organisation' => 'org-1']));

		$this->assertSame(
			[
				['SourceCreatedEvent', ['source' => 'given']],
				['SourceUpdatedEvent', ['newSource' => 'given', 'oldSource' => 'Source#7 Old']],
				['SourceDeletedEvent', ['source' => 'given']],
			],
			$this->events
		);
	}

	public function testOrganisationMapperAnnouncesInsertUpdateAndDelete(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnMap([['dbtableprefix', 'oc_', 'oc_']]);
		$mapper = new OrganisationMapper(
			$this->connection(),
			new NullLogger(),
			$this->entityEvents(),
			new ConfigServices($this->createMock(IAppConfig::class), $config)
		);

		$mapper->insert($this->given(new Organisation(), ['uuid' => '0b9c2a64-3c1e-4c6f-9d6a-1f2e3d4c5b6a', 'name' => 'New']));
		$this->queuedRows = [[['id' => 8, 'uuid' => '0b9c2a64-3c1e-4c6f-9d6a-1f2e3d4c5b6a', 'name' => 'Old']]];
		$mapper->update($this->given(new Organisation(), ['id' => 8, 'name' => 'Renamed']));
		$this->queuedRows = [];
		$mapper->update($this->given(new Organisation(), ['id' => 9, 'name' => 'Unknown before']));
		$mapper->delete($this->given(new Organisation(), ['id' => 8]));

		$this->assertSame(
			[
				['OrganisationCreatedEvent', ['organisation' => 'given']],
				['OrganisationUpdatedEvent', ['newOrganisation' => 'given', 'oldOrganisation' => 'Organisation#8 Old']],
				['OrganisationUpdatedEvent', ['newOrganisation' => 'given', 'oldOrganisation' => 'given']],
				['OrganisationDeletedEvent', ['organisation' => 'given']],
			],
			$this->events
		);
	}

	public function testRegisterMapperAnnouncesInsertUpdateAndDelete(): void {
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('getStatistics')->willReturn(['total' => 0]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$mapper = new RegisterMapper(
			$this->connection(),
			$this->createMock(SchemaMapperInterface::class),
			$this->dispatcher(),
			$container,
			$this->tenancy(),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);

		$mapper->insert($this->given(new Register(), ['uuid' => 'r-1', 'organisation' => 'org-1']));
		$this->queuedRows = [[['id' => 10, 'uuid' => 'r-1', 'organisation' => 'org-1', 'title' => 'Old']]];
		$mapper->update($this->given(new Register(), ['id' => 10, 'uuid' => 'r-1', 'organisation' => 'org-1', 'title' => 'New']));
		$mapper->delete($this->given(new Register(), ['id' => 10, 'organisation' => 'org-1']));

		$this->assertSame(
			[
				['RegisterCreatedEvent', ['register' => 'given']],
				['RegisterUpdatedEvent', ['newRegister' => 'given', 'oldRegister' => 'Register#10 Old']],
				['RegisterDeletedEvent', ['register' => 'given']],
			],
			$this->events
		);
	}

	public function testEntityRelationMapperAnnouncesADecisionUpdate(): void {
		$db = $this->connection();
		$mapper = new EntityRelationMapper(
			$db,
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(IUserSession::class),
			$this->entityEvents(),
			new NullLogger()
		);

		$relation = $this->given(new EntityRelation(), ['id' => 11, 'skipAnonymization' => false]);
		$mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true], actingUser: $this->user);
		$mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true], actingUser: $this->user);

		$this->assertSame(
			[
				[
					'EntityRelationDecisionUpdatedEvent',
					['relation' => 'given', 'changedFields' => '{"skipAnonymization":{"previous":false,"new":true}}', 'actingUser' => 'alice'],
				],
			],
			$this->events,
			'a write that changes nothing announces nothing'
		);
	}

	public function testFlowRunMapperAnnouncesATerminalRunOnly(): void {
		$mapper = new FlowRunMapper($this->connection(), $this->entityEvents());

		$mapper->update($this->given(new FlowRun(), ['id' => 12, 'uuid' => 'run-1', 'status' => FlowRun::STATUS_COMPLETED]));
		$mapper->update($this->given(new FlowRun(), ['id' => 13, 'uuid' => 'run-2', 'status' => 'running']));
		$mapper->update($this->given(new FlowRun(), ['id' => 14, 'uuid' => 'run-3', 'status' => FlowRun::STATUS_FAILED]));

		$this->assertSame(
			[
				['FlowRunTerminalEvent', ['runUuid' => 'run-1', 'status' => 'completed']],
				['FlowRunTerminalEvent', ['runUuid' => 'run-3', 'status' => 'failed']],
			],
			$this->events
		);
	}

	public function testTaskMapperAnnouncesATerminalTaskOnly(): void {
		$mapper = new TaskMapper($this->connection(), $this->entityEvents());

		$mapper->insert($this->given(new Task(), ['uuid' => 't-0', 'state' => Task::STATE_COMPLETED]));
		$mapper->update($this->given(new Task(), ['id' => 15, 'uuid' => 't-1', 'state' => Task::STATE_COMPLETED]));
		$mapper->update($this->given(new Task(), ['id' => 16, 'uuid' => 't-2', 'state' => 'open']));
		$this->assertTrue($mapper->updateIfOpen($this->given(new Task(), ['id' => 17, 'uuid' => 't-3', 'state' => Task::STATE_TERMINATED])));

		$this->assertSame(
			['TaskTerminalEvent', 'TaskTerminalEvent'],
			array_column($this->events, 0),
			'insert announces nothing; update and updateIfOpen announce a terminal task'
		);
		$this->assertSame(['committed' => false, 'task' => 'given'], $this->events[0][1]);
		$this->assertSame(['committed' => false, 'task' => 'given'], $this->events[1][1]);
	}

	public function testNoTaskOrRunEventsWithoutADispatcher(): void {
		(new TaskMapper($this->connection()))->update($this->given(new Task(), ['id' => 18, 'uuid' => 't-4', 'state' => Task::STATE_COMPLETED]));
		$run = $this->given(new FlowRun(), ['id' => 19, 'uuid' => 'run-4', 'status' => FlowRun::STATUS_COMPLETED]);
		(new FlowRunMapper($this->connection()))->update($run);

		$this->assertSame([], $this->events);
	}

	/**
	 * The entity events publisher the mapper is built with, dispatching to the recording dispatcher.
	 *
	 * @return EntityEventsInterface
	 */
	private function entityEvents(): EntityEventsInterface {
		return new EntityEventPublisher(dispatcher: $this->dispatcher());
	}//end entityEvents()

	/**
	 * The dispatcher events reach; records each event and its payload.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->events[] = [(new ReflectionClass($event))->getShortName(), $this->payload(event: $event)];
		});
		return $dispatcher;
	}//end dispatcher()

	/**
	 * The event's own properties, entities named by who they are.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(Event $event): array {
		$payload = [];
		for ($class = new ReflectionClass($event); $class !== false && $class->getName() !== Event::class; $class = $class->getParentClass()) {
			foreach ($class->getProperties() as $property) {
				if ($property->getDeclaringClass()->getName() !== $class->getName()) {
					continue;
				}

				$payload[$property->getName()] = $this->describe(value: $property->getValue($event));
			}
		}

		return $payload;
	}//end payload()

	/**
	 * A stable description of one payload value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value === $this->given && $value !== null) {
			return 'given';
		}

		if ($value instanceof IUser) {
			return $value->getUID();
		}

		if ($value instanceof Entity) {
			$label = null;
			foreach (['name', 'title'] as $field) {
				if (property_exists($value, $field) === true) {
					$label = (new ReflectionProperty($value, $field))->getValue($value);
					break;
				}
			}

			return (new ReflectionClass($value))->getShortName() . '#' . $value->getId() . ' ' . $label;
		}

		if (is_array($value) === true) {
			return json_encode($value);
		}

		return $value;
	}//end describe()

	/**
	 * The entity handed to the mapper next, hydrated from the given fields.
	 *
	 * @param Entity $entity The entity.
	 * @param array<string, mixed> $fields Its fields.
	 *
	 * @return Entity
	 */
	private function given(Entity $entity, array $fields): Entity {
		foreach ($fields as $field => $value) {
			$entity->{'set' . ucfirst($field)}($value);
		}

		$this->given = $entity;
		return $entity;
	}//end given()

	/**
	 * A connection handing out recording builders.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}//end connection()

	/**
	 * An admin caller in organisation org-1.
	 *
	 * @return TenancyServices
	 */
	private function tenancy(): TenancyServices {
		[$session, $groups, $organisations] = $this->caller();
		return new TenancyServices($organisations, $session, $groups);
	}//end tenancy()

	/**
	 * Session, groups and organisations for an admin caller in org-1.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager, 2: OrganisationMapper}
	 */
	private function caller(): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$groups->method('getUserGroupIds')->willReturn(['admin']);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$organisations->method('findParentChain')->willReturn([]);

		return [$session, $groups, $organisations];
	}//end caller()
}//end class
