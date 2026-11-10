<?php

/**
 * Golden test: the allowed and refused outcomes of the task-area listeners that
 * gate on access (the assignee read check, the content-report audit record and
 * the portal party addressing), pinned before they were cut into small units.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Listener\AssigneeFollowListener;
use OCA\OpenRegister\Listener\ContentReportRemovalListener;
use OCA\OpenRegister\Listener\PortalTaskReminderListener;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\EventDispatcher\Event;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Listener\Support\AssigneeFollowServices;

/**
 * Allowed AND refused, for each access decision these listeners make.
 */
final class TaskListenersAccessGoldenTest extends TestCase {

	/**
	 * An assignee who may read is followed; the read check is asked exactly so.
	 *
	 * @return void
	 */
	public function testAReadableAssigneeIsFollowed(): void {
		$object = $this->caseObject(assignee: 'jan');
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects(self::once())->method('hasPermission')
			->with(self::isInstanceOf(Schema::class), 'read', 'jan', 'owner-1', true, $object)
			->willReturn(true);
		$watchers = $this->createMock(WatcherServiceInterface::class);
		$watchers->expects(self::once())->method('followAssigned')->with($object, 'jan', '3', '12');

		$this->assigneeListener($permissions, $watchers)->handle(new ObjectCreatedEvent($object));
	}//end testAReadableAssigneeIsFollowed()

	/**
	 * An assignee the read check refuses is never followed.
	 *
	 * @return void
	 */
	public function testAnUnreadableAssigneeIsNotFollowed(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturn(false);
		$watchers = $this->createMock(WatcherServiceInterface::class);
		$watchers->expects(self::never())->method('followAssigned');

		$this->assigneeListener($permissions, $watchers)->handle(new ObjectCreatedEvent($this->caseObject(assignee: 'jan')));
	}//end testAnUnreadableAssigneeIsNotFollowed()

	/**
	 * No read check and no follow for an unknown uid or an unchanged assignee.
	 *
	 * @return void
	 */
	public function testUnknownOrUnchangedAssigneesAreNeverAsked(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects(self::never())->method('hasPermission');
		$watchers = $this->createMock(WatcherServiceInterface::class);
		$watchers->expects(self::never())->method('followAssigned');
		$listener = $this->assigneeListener($permissions, $watchers);

		$listener->handle(new ObjectCreatedEvent($this->caseObject(assignee: 'stranger')));
		$listener->handle(new ObjectUpdatedEvent($this->caseObject(assignee: 'jan'), $this->caseObject(assignee: 'jan')));
		$listener->handle(new ObjectDeletedEvent($this->caseObject(assignee: 'jan')));
	}//end testUnknownOrUnchangedAssigneesAreNeverAsked()

	/**
	 * A removal of reported content writes one audit entry naming the copies.
	 *
	 * @return void
	 */
	public function testARemovalOfReportedContentIsAudited(): void {
		$object = $this->caseObject(assignee: null);
		$reports = $this->createMock(ContentReportService::class);
		$reports->expects(self::once())->method('noteRemoval')->with('uuid-case-1')->willReturn(['copy-1']);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects(self::once())->method('recordObjectEvent')->with(AuditTrailEvents::matching([
			$object,
			ContentReportService::ACTION_REMOVAL_NAMED,
			[
				'contentReports' => ['copy-1'],
				'reason' => 'reported content removed; the copies taken at filing time survive it',
			]
		]))->willReturn(new \OCA\OpenRegister\Db\AuditTrail());

		(new ContentReportRemovalListener($reports, $audit, new NullLogger()))->handle(new ObjectDeletedEvent($object));
	}//end testARemovalOfReportedContentIsAudited()

	/**
	 * Unreported content, a uuid-less object and other events write nothing.
	 *
	 * @return void
	 */
	public function testNothingElseIsAudited(): void {
		$reports = $this->createMock(ContentReportService::class);
		$reports->expects(self::once())->method('noteRemoval')->willReturn([]);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects(self::never())->method('recordObjectEvent');
		$listener = new ContentReportRemovalListener($reports, $audit, new NullLogger());

		$listener->handle(new ObjectDeletedEvent($this->caseObject(assignee: null)));
		$listener->handle(new ObjectDeletedEvent(new ObjectEntity()));
		$listener->handle(new ObjectCreatedEvent($this->caseObject(assignee: null)));
	}//end testNothingElseIsAudited()

	/**
	 * A preBreach rung addressed to the task's own party is delivered.
	 *
	 * @return void
	 */
	public function testTheAddressedPartyIsReminded(): void {
		$delivery = $this->createMock(PortalTaskDeliveryService::class);
		$delivery->method('messageFor')->willReturn([]);
		$delivery->expects(self::once())->method('request')->with(
			self::isInstanceOf(Task::class),
			PortalTaskDelivery::KIND_REMINDER,
			['rungKey' => 'preBreach:1:hours', 'priority' => 'normal', 'messageKey' => 'msg']
		);

		$this->reminderListener($delivery, Task::PERFORMER_EXTERNAL)
			->handle($this->rung(recipients: [['type' => 'external', 'id' => 'party:bsn-1']]));
	}//end testTheAddressedPartyIsReminded()

	/**
	 * Another party, a user recipient, an internal task or a breach rung get nothing.
	 *
	 * @return void
	 */
	public function testNobodyElseIsReminded(): void {
		$delivery = $this->createMock(PortalTaskDeliveryService::class);
		$delivery->method('messageFor')->willReturn([]);
		$delivery->expects(self::never())->method('request');
		$external = $this->reminderListener($delivery, Task::PERFORMER_EXTERNAL);

		$external->handle($this->rung(recipients: [['type' => 'external', 'id' => 'party:bsn-2']]));
		$external->handle($this->rung(recipients: [['type' => 'user', 'id' => 'party:bsn-1x']]));
		$external->handle($this->rung(recipients: [['type' => 'external', 'id' => 'party:bsn-1']], rungKey: 'slaBreached:0:hours'));
		$this->reminderListener($delivery, 'user')
			->handle($this->rung(recipients: [['type' => 'external', 'id' => 'party:bsn-1']]));
	}//end testNobodyElseIsReminded()

	/**
	 * The assignee listener over a schema with an assignee role.
	 *
	 * @param PermissionHandlerInterface $permissions The read check.
	 * @param WatcherServiceInterface    $watchers    The follow primitive.
	 *
	 * @return AssigneeFollowListener
	 */
	private function assigneeListener(PermissionHandlerInterface $permissions, WatcherServiceInterface $watchers): AssigneeFollowListener {
		$schema = new Schema();
		$schema->setProperties(['assignee' => ['type' => 'string', 'x-openregister-role' => 'assignee']]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'jan');

		return new AssigneeFollowListener($schemas, new AssigneeFollowServices(new SemanticRoleHandler(), $users, $watchers), $permissions, new NullLogger());
	}//end assigneeListener()

	/**
	 * A case object.
	 *
	 * @param string|null $assignee The assignee value.
	 *
	 * @return ObjectEntity
	 */
	private function caseObject(?string $assignee): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-case-1');
		$object->setSchema('12');
		$object->setRegister('3');
		$object->setOwner('owner-1');
		$object->setObject(['assignee' => $assignee]);

		return $object;
	}//end caseObject()

	/**
	 * The reminder listener over a task store holding one open task.
	 *
	 * @param PortalTaskDeliveryService $delivery      The delivery seam.
	 * @param string                    $performerType The task's performer type.
	 *
	 * @return PortalTaskReminderListener
	 */
	private function reminderListener(PortalTaskDeliveryService $delivery, string $performerType): PortalTaskReminderListener {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType($performerType);
		$task->setAssignee('party:bsn-1');
		$tasks = $this->createMock(TaskManagerInterface::class);
		$tasks->method('get')->willReturn($task);

		return new PortalTaskReminderListener($delivery, $tasks, new NullLogger());
	}//end reminderListener()

	/**
	 * A timer rung event about task t-1.
	 *
	 * @param array<int, array<string, string>> $recipients The resolved recipients.
	 * @param string                            $rungKey    The rung key.
	 *
	 * @return Event
	 */
	private function rung(array $recipients, string $rungKey = 'preBreach:1:hours'): Event {
		$timer = new class {
			public function getSubjectType(): string {
				return 'task';
			}

			public function getSubjectUuid(): string {
				return 't-1';
			}
		};

		return new class ($recipients, $rungKey, $timer) extends Event {
			public function __construct(private array $recipients, private string $rungKey, private object $timer) {
				parent::__construct();
			}

			public function getKind(): string {
				return 'rung';
			}

			public function getRungKey(): string {
				return $this->rungKey;
			}

			public function getRecipients(): array {
				return $this->recipients;
			}

			public function getTimer(): object {
				return $this->timer;
			}

			public function getMessage(): string {
				return 'msg';
			}

			public function getPriority(): string {
				return 'normal';
			}
		};
	}//end rung()
}//end class
