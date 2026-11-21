<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A `noteAdded` rule: matched on the note's visibility, rendered with the
 * note's author and excerpt, never delivered to the author or to a user who
 * cannot read the object, and delivered to nobody without a read check.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
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
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NoteTriggerContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerMatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ReadableRecipients;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer;
use OCA\OpenRegister\Notifications\Service\Notification\MessageTarget;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientDelivery;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientSource;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
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
#[UsesClass(RuleReachRecorder::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
class AnnotationNotificationDispatcherNoteAddedTest extends TestCase {

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
				static fn (Schema $schema, string $action, ?string $userId = null): bool => $action === 'read' && ($readers[$userId] ?? false) === true
			);
			$readable = new ReadableRecipients(logger: new NullLogger(), permissions: $permissions);
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
			ledger: new DeliveryLedger(logger: new NullLogger()),
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
	 * A schema with one `noteAdded` rule to the planner, a colleague and an outsider.
	 *
	 * @param array<string, mixed> $trigger The rule's trigger.
	 *
	 * @return Schema
	 */
	private function schema(array $trigger): Schema {
		$schema = new Schema();
		$schema->setSlug('task');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'new-note' => [
					'trigger' => $trigger,
					'recipients' => [['kind' => 'users', 'users' => ['planner', 'colleague', 'outsider']]],
					'channels' => ['nc-notification'],
					'subject' => '{{note.author}} on {{title}}: {{note.excerpt}}',
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

	/**
	 * The trigger context of a note by the planner.
	 *
	 * @param string $visibility The note's visibility.
	 *
	 * @return array<string, string>
	 */
	private function note(string $visibility = 'public'): array {
		return ['noteId' => '42', 'actorType' => 'users', 'actorId' => 'planner', 'visibility' => $visibility];
	}//end note()

	public function testAReaderHearsTheNoteNamingItsAuthorAndTheAuthorDoesNot(): void {
		$dispatcher = $this->dispatcher(readers: ['planner' => true, 'colleague' => true, 'outsider' => false]);

		$dispatcher->dispatchWithSchema($this->task(), 'noteAdded', $this->note(), $this->schema(['type' => 'noteAdded']));

		$this->assertSame(['colleague'], array_column($this->sent, 'user'));
		$this->assertSame('Petra Planner on Replace boiler: The boiler is fixed', $this->sent[0]['text']);
		$this->assertSame('object_note_added', $this->sent[0]['subject']);
	}

	public function testAUserWhoCannotReadTheObjectIsNotTold(): void {
		$dispatcher = $this->dispatcher(readers: ['colleague' => false, 'outsider' => false]);

		$dispatcher->dispatchWithSchema($this->task(), 'noteAdded', $this->note(), $this->schema(['type' => 'noteAdded']));

		$this->assertSame([], $this->sent);
	}

	public function testARuleNarrowedToPublicNotesDoesNotFireOnAnInternalNote(): void {
		$dispatcher = $this->dispatcher(readers: ['colleague' => true, 'outsider' => true]);

		$publicOnly = $this->schema(['type' => 'noteAdded', 'visibility' => 'public']);

		$dispatcher->dispatchWithSchema($this->task(), 'noteAdded', $this->note(visibility: 'internal'), $publicOnly);
		$this->assertSame([], $this->sent);

		$dispatcher->dispatchWithSchema($this->task(), 'noteAdded', $this->note(visibility: 'public'), $publicOnly);
		$this->assertSame(['colleague', 'outsider'], array_column($this->sent, 'user'));
	}

	public function testWithoutAReadCheckANoteReachesNobody(): void {
		$dispatcher = $this->dispatcher(readers: null);

		$dispatcher->dispatchWithSchema($this->task(), 'noteAdded', $this->note(), $this->schema(['type' => 'noteAdded']));

		$this->assertSame([], $this->sent);
	}

	public function testAnotherTriggerIsNotNarrowedByTheReadCheck(): void {
		$dispatcher = $this->dispatcher(readers: ['planner' => false, 'colleague' => false, 'outsider' => false]);
		$schema = $this->schema(['type' => 'created']);

		$dispatcher->dispatchWithSchema($this->task(), 'created', [], $schema);

		$this->assertSame(['planner', 'colleague', 'outsider'], array_column($this->sent, 'user'));
	}
}//end class
