<?php

/**
 * The dispatcher tells the reach record which rules reached somebody.
 *
 * A rule recorded as reaching nobody must drop off the administrator's list
 * once it reaches somebody again, and a rule that still reaches nobody must
 * be recorded and kept.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations;
use OCA\OpenRegister\Notifications\Service\Notification\ChannelSet;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchGates;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\MessageRendering;
use OCA\OpenRegister\Notifications\Service\Notification\MessageTarget;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NoteTriggerContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerMatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ReadableRecipients;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientDelivery;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientSource;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\Object\ObjectReadRights;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\Activity\IManager as IActivityManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AnnotationNotificationDispatcher::class)]
#[UsesClass(NoteTriggerContext::class)]
#[UsesClass(ReadableRecipients::class)]
#[UsesClass(NotificationTriggerMatcher::class)]
#[UsesClass(NotificationRecipientResolver::class)]
#[UsesClass(NotificationTemplating::class)]
#[UsesClass(NcNotificationSender::class)]
#[UsesClass(EmailSender::class)]
#[UsesClass(TalkSender::class)]
#[UsesClass(ChannelSet::class)]
#[UsesClass(DispatchGates::class)]
#[UsesClass(DispatchThrottle::class)]
#[UsesClass(DeliveryHold::class)]
#[UsesClass(AppIntegrations::class)]
#[UsesClass(DeliveryLedger::class)]
#[UsesClass(RuleAudience::class)]
#[UsesClass(MessageRendering::class)]
#[UsesClass(RuleFiring::class)]
#[UsesClass(RuleMessageRenderer::class)]
#[UsesClass(MessageTarget::class)]
#[UsesClass(NotificationRunTally::class)]
#[UsesClass(RecipientDelivery::class)]
#[UsesClass(RecipientSource::class)]
#[CoversClass(RuleReachRecorder::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
#[UsesClass(ObjectReadRights::class)]
#[UsesClass(PermissionAsk::class)]
class AnnotationNotificationDispatcherRuleReachTest extends TestCase {

	/**
	 * The counts handed to the reach ledger, one entry per write.
	 *
	 * @var list<array<string, int>>
	 */
	private array $recorded = [];

	/**
	 * The rules the reach ledger was told to forget.
	 *
	 * @var list<string>
	 */
	private array $forgotten = [];

	/**
	 * The uid and rendered text of every nc-notification sent.
	 *
	 * @var array<int, array{user: string, text: string}>
	 */
	private array $sent = [];

	/**
	 * The current recipient while a notification is being built.
	 *
	 * @var string
	 */
	private string $currentUser = '';

	/**
	 * A dispatcher over real senders, capturing each nc-notification.
	 *
	 * @param array<string, bool>|null $readers Who may read the object; null wires no read check.
	 *
	 * @return AnnotationNotificationDispatcher
	 */
	private function reachLedger(): RuleReachLedger {
		$ledger = $this->createMock(RuleReachLedger::class);
		$ledger->method('record')->willReturnCallback(
			function (array $counts, int $now): void {
				$this->recorded[] = $counts;
			}
		);
		$ledger->method('forget')->willReturnCallback(
			function (string $rule): void {
				$this->forgotten[] = $rule;
			}
		);

		return $ledger;
	}//end reachLedger()

	private function dispatcher(?array $readers): AnnotationNotificationDispatcher {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification): INotification {
				$this->currentUser = $uid;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification): INotification {
				$this->sent[] = ['user' => $this->currentUser, 'text' => (string)($params['_text'] ?? ''), 'subject' => $subject];
				return $notification;
			}
		);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$users->method('getDisplayName')->willReturnMap([['planner', 'Petra Planner']]);

		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn('<p>The boiler   is <b>fixed</b></p>');
		$comments = $this->createMock(ICommentsManager::class);
		$comments->method('get')->with('42')->willReturn($comment);

		$readable = null;
		if ($readers !== null) {
			$permissions = $this->createMock(PermissionHandlerInterface::class);
			$permissions->method('hasPermission')->willReturnCallback(
				static fn (PermissionAsk $ask): bool => $ask->action === 'read' && ($readers[$ask->userId] ?? false) === true
			);
			$readable = new ReadableRecipients(logger: new NullLogger(), permissions: new ObjectReadRights($permissions));
		}

		return new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: new NcNotificationSender(notificationManager: $notifications, logger: new NullLogger(), userManager: $users),
				emailSender: new EmailSender(userManager: $users, mailer: $this->createMock(IMailer::class), logger: new NullLogger()),
				talkSender: new TalkSender(httpClient: $this->createMock(IClientService::class), logger: new NullLogger()),
				activityManager: $this->createMock(IActivityManager::class)
			),
			gates: new DispatchGates(throttle: new DispatchThrottle(), deliveryHold: new DeliveryHold()),
			integrations: new AppIntegrations(
				httpClient: $this->createMock(IClientService::class),
				serverContainer: $this->createMock(IServerContainer::class)
			),
			ledger: new DeliveryLedger(logger: new NullLogger(), reachRecorder: new RuleReachRecorder(logger: new NullLogger(), ledger: $this->reachLedger())),
			audience: new RuleAudience(
				schemaMapper: $this->createMock(SchemaMapper::class),
				recipientResolver: new NotificationRecipientResolver(
					userManager: $users,
					groupManager: $this->createMock(IGroupManager::class),
					logger: new NullLogger(),
					serverContainer: $this->createMock(IServerContainer::class)
				),
				notes: new NoteTriggerContext(logger: new NullLogger(), comments: $comments, users: $users),
				readable: $readable
			),
			rendering: new MessageRendering(templating: new NotificationTemplating(logger: new NullLogger()))
		);
	}//end dispatcher()

	/**
	 * A schema with one `created` rule to these users.
	 *
	 * @param list<string> $users The rule's recipients.
	 *
	 * @return Schema
	 */
	private function schema(array $users): Schema {
		$schema = new Schema();
		$schema->setSlug('task');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'task-created' => [
					'trigger' => ['type' => 'created'],
					'recipients' => [['kind' => 'users', 'users' => $users]],
					'channels' => ['nc-notification'],
					'subject' => 'New task {{title}}',
				],
			],
		]);

		return $schema;
	}//end schema()

	private function task(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('task-1');
		$object->setOwner('planner');
		$object->setObject(['title' => 'Replace boiler']);

		return $object;
	}//end task()

	public function testARuleThatReachedSomebodyIsClearedFromTheReachRecord(): void {
		$this->dispatcher(readers: null)->dispatchWithSchema($this->task(), 'created', [], $this->schema(['planner']));

		$this->assertSame(['planner'], array_column($this->sent, 'user'));
		$this->assertSame(['task-created'], $this->forgotten);
		$this->assertSame([], $this->recorded);
	}

	public function testARuleThatReachedNobodyIsRecordedAndNotCleared(): void {
		$this->dispatcher(readers: null)->dispatchWithSchema($this->task(), 'created', [], $this->schema([]));

		$this->assertSame([], $this->sent);
		$this->assertSame([['task-created' => 1]], $this->recorded);
		$this->assertSame([], $this->forgotten);
	}
}//end class
