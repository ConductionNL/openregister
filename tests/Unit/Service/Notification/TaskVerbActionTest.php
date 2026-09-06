<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one new dialect surface: a `task-verb` action target is validated
 * against the closed verb list, resolved to the verb route as POST, and
 * routed to the form as GET when its outcome needs a comment. The three
 * existing kinds keep rendering GET.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskVerbActionTest extends TestCase {
	private function schemaWithActions(array $actions): array {
		return [
			'properties' => ['assignee' => ['type' => 'string']],
			'x-openregister-notifications' => [
				'x' => [
					'trigger' => ['type' => 'transition'],
					'recipients' => [['kind' => 'field', 'field' => 'assignee']],
					'channels' => ['nc-notification'],
					'subject' => ['en' => 'Task: {{title}}'],
					'actions' => $actions,
				],
			],
		];
	}

	public function testTheValidatorAcceptsAKnownVerbAndNamesAnUnknownOne(): void {
		$validator = new NotificationAnnotationValidator();

		$ok = $validator->validate($this->schemaWithActions([
			['label' => ['en' => 'Approve'], 'target' => ['kind' => 'task-verb', 'verb' => 'complete', 'outcome' => 'approved']],
		]));
		$this->assertSame([], $ok);

		$bad = $validator->validate($this->schemaWithActions([
			['label' => ['en' => 'Nuke'], 'target' => ['kind' => 'task-verb', 'verb' => 'delete']],
		]));
		$this->assertCount(1, $bad);
		$this->assertSame('notification-action-bad-target', $bad[0]['code']);
		$this->assertStringContainsString('"delete"', $bad[0]['message']);
	}

	public function testTheValidatorStillCapsAtTwoActions(): void {
		$action = ['label' => ['en' => 'x'], 'target' => ['kind' => 'task-verb', 'verb' => 'complete']];
		$errors = (new NotificationAnnotationValidator())->validate($this->schemaWithActions([$action, $action, $action]));

		$this->assertContains('notification-too-many-actions', array_column($errors, 'code'));
	}

	/**
	 * Drive the real dispatcher over the real task rule set and capture the
	 * actions it hands the notifier for the assignee.
	 *
	 * @return array<int, array<string, mixed>> The resolved `_actions`.
	 */
	private function resolvedActionsForAssignment(): array {
		$captured = null;
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		$notification->method('setSubject')->willReturnCallback(
			static function (string $subject, array $params) use (&$captured, $notification): INotification {
				$captured = $params;
				return $notification;
			}
		);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify');

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('approver');
		$users->method('get')->willReturn($user);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => 'https://cloud.example/' . $route . '/' . ($params['uuid'] ?? '')
		);

		$dispatcher = new AnnotationNotificationDispatcher(
			$this->createMock(SchemaMapper::class),
			$notifications,
			new NullLogger(),
			$this->createMock(IGroupManager::class),
			$users,
			$this->createMock(IMailer::class),
			$this->createMock(IActivityManager::class),
			$this->createMock(IClientService::class),
			$this->createMock(IServerContainer::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$urls
		);

		$task = new Task();
		$task->setUuid('t-approve');
		$task->setTitle('Approve the permit');
		$task->setState(Task::STATE_ACTIVE);
		$task->setLastAction('assign');
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('approver');

		$rules = new TaskNotificationRules();
		// Only the assignment rule, so one notification is emitted.
		$schema = $rules->buildSchema();
		$schema->setConfiguration(['x-openregister-notifications' => ['taskAssignedToYou' => $rules->getRules()['taskAssignedToYou']]]);

		$dispatcher->dispatchWithSchema(new TaskObjectAdapter($task), 'transition', ['action' => 'assign'], $schema);

		$this->assertIsArray($captured, 'the assignee received the notification');
		$this->assertStringContainsString('Approve the permit', $captured['_text']);

		return $captured['_actions'];
	}

	public function testApproveIsAPostToTheVerbRouteAndRejectAGetToTheForm(): void {
		$actions = $this->resolvedActionsForAssignment();

		$this->assertCount(2, $actions);
		$this->assertSame('POST', $actions[0]['method']);
		$this->assertSame('https://cloud.example/openregister.task.complete/t-approve?outcome=approved', $actions[0]['url']);
		$this->assertTrue($actions[0]['primary']);

		// A rejecting outcome needs a comment: the button opens the form.
		$this->assertSame('GET', $actions[1]['method']);
		$this->assertSame('https://cloud.example/openregister.task.open/t-approve', $actions[1]['url']);
	}

	public function testTheNotifierRendersTheMethodItWasHanded(): void {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(\OCP\L10N\IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example/apps/openregister/img/app.svg');

		$links = [];
		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnCallback(
			static function (string $url, string $method) use (&$links, $action): IAction {
				$links[] = [$url, $method];
				return $action;
			}
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_transitioned');
		$notification->method('getSubjectParameters')->willReturn([
			'_text' => 'Assigned to you: Approve the permit',
			'_actions' => [
				['label' => ['en' => 'Approve', 'nl' => 'Goedkeuren'], 'primary' => true, 'url' => 'https://x/complete?outcome=approved', 'method' => 'POST'],
				['label' => ['en' => 'Reject'], 'url' => 'https://x/open', 'method' => 'GET'],
				['label' => ['en' => 'Legacy'], 'url' => 'https://x/legacy'],
				['label' => ['en' => 'Smuggled'], 'url' => 'https://x/evil', 'method' => 'TRACE'],
			],
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();

		(new \OCA\OpenRegister\Notification\AnnotationNotifier($factory, $urls))->prepare($notification, 'nl');

		$this->assertSame([
			['https://x/complete?outcome=approved', 'POST'],
			['https://x/open', 'GET'],
			['https://x/legacy', 'GET'],
			['https://x/evil', 'GET'],
		], $links);
	}
}
