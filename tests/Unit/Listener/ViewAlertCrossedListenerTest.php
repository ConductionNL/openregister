<?php

/**
 * A view alert that crosses its threshold reaches the people it names.
 *
 * ViewAlertSweepJob dispatches ViewAlertCrossedEvent once per crossing, and
 * until this listener nothing heard it: the sweep counted, decided and fired
 * into a void. These tests run the real listener over the real event, a real
 * View, a declaration read through the real ViewAlert::parse() and the real
 * NotificationRecipientResolver; only Nextcloud's user, group, notification
 * and mail managers are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Listener\ViewAlertCrossedListener;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \OCA\OpenRegister\Listener\ViewAlertCrossedListener
 * @uses \OCA\OpenRegister\Db\View
 * @uses \OCA\OpenRegister\Event\ViewAlertCrossedEvent
 * @uses \OCA\OpenRegister\Service\Notification\EmailSender
 * @uses \OCA\OpenRegister\Service\Notification\NotificationRecipientResolver
 * @uses \OCA\OpenRegister\Service\View\ViewAlert
 */
class ViewAlertCrossedListenerTest extends TestCase {

	/**
	 * Every notification handed to the manager, as [user, subject, parameters, objectType, objectId].
	 *
	 * @var array<int, array{user: string, subject: string, parameters: array, objectType: string, objectId: string}>
	 */
	private array $notified = [];

	/**
	 * Every mail the mailer was asked to send, as [address, subject].
	 *
	 * @var array<int, array{to: array, subject: string}>
	 */
	private array $mailed = [];

	/**
	 * What each double recorded, by object id.
	 *
	 * @var array<int, \ArrayObject>
	 */
	private array $records = [];

	/**
	 * Every warning logged.
	 *
	 * @var \ArrayObject<int, string>
	 */
	private \ArrayObject $warnings;

	/**
	 * Users whose notification the manager refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refuseNotifyFor = [];

	/**
	 * Addresses the mailer fails to send to.
	 *
	 * @var array<int, string>
	 */
	private array $refuseMailTo = [];

	/**
	 * The listener over a server with users alice, bob and carol and a group `teamleads` holding alice and bob.
	 *
	 * @return ViewAlertCrossedListener
	 */
	private function listener(): ViewAlertCrossedListener {
		$users = [];
		foreach (['alice', 'bob', 'carol'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$user->method('getEMailAddress')->willReturn($uid . '@example.org');
			$users[$uid] = $user;
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => isset($users[$uid]));
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($users[$uid] ?? null));

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$users['alice'], $users['bob']]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'teamleads');
		$groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'teamleads' ? $group : null));

		$this->warnings = new \ArrayObject();
		$logger = new class($this->warnings) extends AbstractLogger {
			/**
			 * @param \ArrayObject $warnings Collected warnings.
			 */
			public function __construct(
				private \ArrayObject $warnings,
			) {
			}

			/**
			 * @param mixed $level The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 */
			public function log($level, $message, array $context = []): void {
				if ($level === 'warning') {
					$replace = [];
					foreach ($context as $key => $value) {
						$replace['{' . $key . '}'] = is_scalar($value) ? (string)$value : json_encode($value);
					}

					$this->warnings[] = strtr((string)$message, $replace);
				}
			}
		};

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$record = $this->records[spl_object_id($notification)]->getArrayCopy();
			if (in_array($record['user'], $this->refuseNotifyFor, true) === true) {
				throw new \RuntimeException('notification backend down');
			}

			$this->notified[] = $record;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(fn (): IMessage => $this->recordingMessage());
		$mailer->method('send')->willReturnCallback(function (IMessage $message): array {
			$record = $this->records[spl_object_id($message)]->getArrayCopy();
			if (array_intersect(array_keys($record['to']), $this->refuseMailTo) !== []) {
				throw new \RuntimeException('smtp down');
			}

			$this->mailed[] = $record;
			return [];
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('getUserLanguage')->willReturn('en');
		$factory->method('get')->willReturn($l10n);

		return new ViewAlertCrossedListener(
			recipients: new NotificationRecipientResolver(userManager: $userManager, groupManager: $groupManager, logger: $logger),
			notifications: $manager,
			email: new EmailSender(userManager: $userManager, mailer: $mailer, logger: $logger),
			l10nFactory: $factory,
			userManager: $userManager,
			logger: $logger
		);
	}//end listener()

	/**
	 * A notification double that records what the listener set on it, then hands it over on notify().
	 *
	 * @return INotification
	 */
	private function recordingNotification(): INotification {
		$record = new \ArrayObject(['user' => '', 'subject' => '', 'parameters' => [], 'objectType' => '', 'objectId' => '']);
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(function (string $user) use ($record, $notification): INotification {
			$record['user'] = $user;
			return $notification;
		});
		$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use ($record, $notification): INotification {
			$record['objectType'] = $type;
			$record['objectId'] = $id;
			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use ($record, $notification): INotification {
			$record['subject'] = $subject;
			$record['parameters'] = $parameters;
			return $notification;
		});
		$this->records[spl_object_id($notification)] = $record;

		return $notification;
	}//end recordingNotification()

	/**
	 * A mail message double that records its recipient, subject and body.
	 *
	 * @return IMessage
	 */
	private function recordingMessage(): IMessage {
		$record = new \ArrayObject(['to' => [], 'subject' => '', 'body' => '']);
		$message = $this->createMock(IMessage::class);
		$message->method('setPlainBody')->willReturnCallback(function (string $body) use ($record, $message): IMessage {
			$record['body'] = $body;
			return $message;
		});
		$message->method('setTo')->willReturnCallback(function (array $to) use ($record, $message): IMessage {
			$record['to'] = $to;
			return $message;
		});
		$message->method('setSubject')->willReturnCallback(function (string $subject) use ($record, $message): IMessage {
			$record['subject'] = $subject;
			return $message;
		});
		$this->records[spl_object_id($message)] = $record;

		return $message;
	}//end recordingMessage()

	/**
	 * A view owned by `owner`, named "Overdue cases".
	 *
	 * @return View
	 */
	private function view(): View {
		$view = new View();
		$view->setUuid('view-uuid-1');
		$view->setName('Overdue cases');
		$view->setOwner('owner');

		return $view;
	}//end view()

	/**
	 * A crossing event for the declared alert at the given count.
	 *
	 * @param array<string, mixed> $declared The alert block as a view stores it.
	 * @param int $count The count that crossed.
	 *
	 * @return ViewAlertCrossedEvent
	 */
	private function crossing(array $declared, int $count = 23): ViewAlertCrossedEvent {
		$alert = ViewAlert::parse($declared);
		$this->assertNotNull($alert);

		return new ViewAlertCrossedEvent(view: $this->view(), alert: $alert, count: $count);
	}//end crossing()

	/**
	 * A user named `user:<uid>` and the members of a group named by its id are each told once.
	 */
	public function testEachRecipientIsNotifiedOnceAboutTheView(): void {
		$this->listener()->handle(
			$this->crossing(['operator' => 'gte', 'threshold' => 20, 'recipients' => ['user:alice', 'teamleads'], 'every' => 900])
		);

		$users = array_column($this->notified, 'user');
		sort($users);
		$this->assertSame(['alice', 'bob'], $users, 'alice is named twice (directly and through teamleads) and is told once');

		foreach ($this->notified as $record) {
			$this->assertSame('view_alert_crossed', $record['subject']);
			$this->assertSame('view', $record['objectType']);
			$this->assertSame('view-uuid-1', $record['objectId']);
			$this->assertSame(
				['view' => 'Overdue cases', 'viewId' => 'view-uuid-1', 'count' => 23, 'operator' => 'gte', 'threshold' => 20],
				$record['parameters']
			);
		}
	}//end testEachRecipientIsNotifiedOnceAboutTheView()

	/**
	 * A recipient the server does not have is skipped and named in the log, never told in silence to nobody.
	 */
	public function testAnUnknownRecipientIsNamedInTheLog(): void {
		$this->listener()->handle(
			$this->crossing(['operator' => 'gte', 'threshold' => 20, 'recipients' => ['user:ghost', 'gone-group', 'user:carol']])
		);

		$this->assertSame(['carol'], array_column($this->notified, 'user'));
		$log = implode("\n", $this->warnings->getArrayCopy());
		$this->assertStringContainsString('ghost', $log);
		$this->assertStringContainsString('gone-group', $log);
	}//end testAnUnknownRecipientIsNamedInTheLog()

	/**
	 * The email channel mails each recipient; a channel nobody delivers is named in the log.
	 */
	public function testTheEmailChannelMailsAndAnUnknownChannelIsNamed(): void {
		$this->listener()->handle(
			$this->crossing(['operator' => 'lte', 'threshold' => 5, 'recipients' => ['user:carol'], 'channels' => ['email', 'pager']], 2)
		);

		$this->assertSame([], $this->notified, 'nc-notification was not declared, so nothing goes to the bell');
		$this->assertCount(1, $this->mailed);
		$this->assertSame(['carol@example.org' => 'Carol'], $this->mailed[0]['to']);
		$this->assertStringContainsString('Overdue cases', $this->mailed[0]['subject']);
		$this->assertSame('The view Overdue cases counts 2, at or below its threshold of 5.', $this->mailed[0]['body']);
		$this->assertStringContainsString('pager', implode("\n", $this->warnings->getArrayCopy()));
	}//end testTheEmailChannelMailsAndAnUnknownChannelIsNamed()

	/**
	 * A notification the manager refuses is named in the log, and the next recipient is still told.
	 */
	public function testARefusedNotificationIsNamedAndTheOthersAreStillTold(): void {
		$this->refuseNotifyFor = ['alice'];
		$this->listener()->handle(
			$this->crossing(['operator' => 'gte', 'threshold' => 20, 'recipients' => ['teamleads']])
		);

		$this->assertSame(['bob'], array_column($this->notified, 'user'));
		$log = implode("\n", $this->warnings->getArrayCopy());
		$this->assertStringContainsString('could not notify alice: notification backend down', $log);
	}//end testARefusedNotificationIsNamedAndTheOthersAreStillTold()

	/**
	 * A count at or above the threshold says "above" in the mail; a mail that fails is named in the log.
	 */
	public function testAnUpperAlertMailSaysAboveAndAFailedMailIsNamed(): void {
		$this->refuseMailTo = ['bob@example.org'];
		$this->listener()->handle(
			$this->crossing(['operator' => 'gte', 'threshold' => 20, 'recipients' => ['teamleads'], 'channels' => ['email']])
		);

		$this->assertCount(1, $this->mailed);
		$this->assertSame(['alice@example.org' => 'Alice'], $this->mailed[0]['to']);
		$this->assertSame('The view Overdue cases counts 23, at or above its threshold of 20.', $this->mailed[0]['body']);
		$log = implode("\n", $this->warnings->getArrayCopy());
		$this->assertStringContainsString('email to bob was not sent: failed', $log);
	}//end testAnUpperAlertMailSaysAboveAndAFailedMailIsNamed()

	/**
	 * CONTROL: an event that is not a view alert is left alone, so the green above is not a listener that notifies on anything.
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(new ObjectUpdatingEvent(newObject: new ObjectEntity(), oldObject: new ObjectEntity()));

		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->mailed);
	}//end testAnotherEventIsIgnored()

	/**
	 * The sweep's event reaches this listener: Application subscribes it.
	 */
	public function testTheListenerIsSubscribedToTheCrossingEvent(): void {
		$application = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertIsString($application);
		$this->assertStringContainsString(
			'registerEventListener(ViewAlertCrossedEvent::class, ViewAlertCrossedListener::class)',
			$application
		);
	}//end testTheListenerIsSubscribedToTheCrossingEvent()
}//end class
