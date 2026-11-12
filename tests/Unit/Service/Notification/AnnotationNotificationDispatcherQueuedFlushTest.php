<?php

/**
 * Characterisation tests for AnnotationNotificationDispatcher::dispatchQueued().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Db\HistoryEntry;
use OCA\OpenRegister\Notifications\Db\NotificationHistory;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ChannelSet;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchGates;
use OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\MessageRendering;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\InAppNotice;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every call a queued flush makes on the senders, the activity stream
 * and the history mapper, in order.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher
 */
class AnnotationNotificationDispatcherQueuedFlushTest extends TestCase {

	/**
	 * Calls recorded in order.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $calls = [];

	/**
	 * An empty group makes no call at all.
	 *
	 * @return void
	 */
	public function testEmptyGroupDoesNothing(): void {
		$this->dispatcher()->dispatchQueued([]);

		$this->assertSame([], $this->calls);
	}//end testEmptyGroupDoesNothing()

	/**
	 * A single row flushes its own text over every channel it carries.
	 *
	 * @return void
	 */
	public function testSingleRowFlushesEveryChannel(): void {
		$row = $this->row(
			ruleKey: 'rule-a',
			payload: [
				'channels'       => ['nc-notification', 'email', 'activity'],
				'subject'        => 'Subject A',
				'message'        => 'Body A',
				'locale'         => 'nl',
				'objectUuid'     => 'uuid-1',
				'objectRegister' => '4',
				'objectSchema'   => '9',
				'objectName'     => 'Thing',
				'name'           => 'named-rule',
				'subjectKey'     => 'object_created',
				'originApp'      => 'dossiq',
				'actions'        => [['label' => ['en' => 'Open'], 'url' => 'https://x.test/']],
			]
		);

		$this->dispatcher()->dispatchQueued([$row]);

		$object = ['uuid' => 'uuid-1', 'register' => '4', 'schema' => '9', 'name' => 'Thing'];
		$this->assertSame(
			[
				['nc.send', ['alice', $object, 'object_created', 'named-rule', 'Subject A', 'Body A', [], 'dossiq', [['label' => ['en' => 'Open'], 'url' => 'https://x.test/']], false]],
				['history.record', ['rule-a', 'nc-notification', 'alice', 'dispatched', '9', '4', 'uuid-1', 'Subject A', null, 'nl', null, null, null]],
				['email.send', ['alice', 'Subject A', 'Body A']],
				['history.record', ['rule-a', 'email', 'alice', 'dispatched', '9', '4', 'uuid-1', 'Subject A', null, 'nl', null, null, null]],
				['activity.setApp', ['openregister']],
				['activity.setType', ['openregister_objects']],
				['activity.setAffectedUser', ['alice']],
				['activity.setSubject', ['named-rule', ['_text' => 'Subject A']]],
				['activity.setObject', ['object', 0, 'uuid-1']],
				['activity.publish', []],
				['history.record', ['rule-a', 'activity', 'alice', 'dispatched', '9', '4', 'uuid-1', 'Subject A', null, 'nl', null, null, null]],
			],
			$this->calls
		);
	}//end testSingleRowFlushesEveryChannel()

	/**
	 * A bare row falls back to the rule key, the default subject key and
	 * origin app, mails the subject when there is no message, and files the
	 * activity under the rule name when the object has no uuid.
	 *
	 * @return void
	 */
	public function testBareRowFallsBack(): void {
		$row = $this->row(
			ruleKey: 'rule-b',
			payload: ['channels' => ['nc-notification', 'email', 'activity'], 'subject' => 'Only subject']
		);

		$this->dispatcher()->dispatchQueued([$row]);

		$object = ['uuid' => '', 'register' => null, 'schema' => null, 'name' => null];
		$this->assertSame(
			[
				['nc.send', ['alice', $object, 'object_updated', 'rule-b', 'Only subject', '', [], 'openregister', [], false]],
				['history.record', ['rule-b', 'nc-notification', 'alice', 'dispatched', null, null, null, 'Only subject', null, null, null, null, null]],
				['email.send', ['alice', 'Only subject', 'Only subject']],
				['history.record', ['rule-b', 'email', 'alice', 'dispatched', null, null, null, 'Only subject', null, null, null, null, null]],
				['activity.setApp', ['openregister']],
				['activity.setType', ['openregister_objects']],
				['activity.setAffectedUser', ['alice']],
				['activity.setSubject', ['rule-b', ['_text' => 'Only subject']]],
				['activity.setObject', ['object', 0, 'rule-b']],
				['activity.publish', []],
				['history.record', ['rule-b', 'activity', 'alice', 'dispatched', null, null, null, 'Only subject', null, null, null, null, null]],
			],
			$this->calls
		);
	}//end testBareRowFallsBack()

	/**
	 * Several rows merge into one summary; channels come from the first row.
	 *
	 * @return void
	 */
	public function testSeveralRowsMergeIntoOneSummary(): void {
		$rows = [
			$this->row(ruleKey: 'rule-c', payload: ['channels' => ['email'], 'subject' => 'one', 'action' => 'created', 'objectUuid' => 'u-1']),
			$this->row(ruleKey: 'rule-c', payload: ['channels' => ['activity'], 'subject' => 'two', 'action' => 'updated']),
			$this->row(ruleKey: 'rule-c', payload: ['subject' => '', 'action' => 'created']),
		];

		$this->dispatcher()->dispatchQueued($rows);

		$this->assertSame(
			[
				['email.send', ['alice', '3 meldingen (2 nieuw, 1 gewijzigd)', 'one; two']],
				['history.record', ['rule-c', 'email', 'alice', 'dispatched', null, null, 'u-1', '3 meldingen (2 nieuw, 1 gewijzigd)', null, null, null, null, null]],
			],
			$this->calls
		);
	}//end testSeveralRowsMergeIntoOneSummary()

	/**
	 * A failing history write never stops the flush.
	 *
	 * @return void
	 */
	public function testHistoryFailureIsSwallowed(): void {
		$row = $this->row(ruleKey: 'rule-d', payload: ['channels' => ['email', 'activity'], 'subject' => 'S']);

		$this->dispatcher(historyThrows: true)->dispatchQueued([$row]);

		$this->assertSame(
			['email.send', 'history.record', 'activity.setApp', 'activity.setType', 'activity.setAffectedUser', 'activity.setSubject', 'activity.setObject', 'activity.publish', 'history.record'],
			array_column($this->calls, 0)
		);
	}//end testHistoryFailureIsSwallowed()

	/**
	 * One queued row for recipient alice.
	 *
	 * @param string               $ruleKey The rule key.
	 * @param array<string, mixed> $payload The stored payload.
	 *
	 * @return QueuedNotification
	 */
	private function row(string $ruleKey, array $payload): QueuedNotification {
		$row = new QueuedNotification();
		$row->setRuleKey($ruleKey);
		$row->setRecipient('alice');
		$row->setPayload((string)json_encode($payload));

		return $row;
	}//end row()

	/**
	 * Record one call, objects reduced to their routing fields.
	 *
	 * @param string            $what The call.
	 * @param array<int, mixed> $args Its arguments.
	 *
	 * @return void
	 */
	private function record(string $what, array $args): void {
		foreach ($args as $i => $arg) {
			if ($arg instanceof ObjectEntity) {
				$args[$i] = ['uuid' => $arg->getUuid(), 'register' => $arg->getRegister(), 'schema' => $arg->getSchema(), 'name' => $arg->getName()];
			}
		}

		$this->calls[] = [$what, array_values($args)];
	}//end record()

	/**
	 * The dispatcher with recording senders, activity stream and history.
	 *
	 * @param bool $historyThrows Whether the history write throws.
	 *
	 * @return AnnotationNotificationDispatcher
	 */
	private function dispatcher(bool $historyThrows = false): AnnotationNotificationDispatcher {
		$activity = $this->createMock(IActivityManager::class);
		$activity->method('generateEvent')->willReturnCallback(
			function (): IEvent {
				$event = $this->createMock(IEvent::class);
				foreach (['setApp', 'setType', 'setAffectedUser', 'setSubject', 'setObject'] as $setter) {
					$event->method($setter)->willReturnCallback(
						function (...$args) use ($event, $setter): IEvent {
							$this->record(what: 'activity.'.$setter, args: $args);
							return $event;
						}
					);
				}

				$event->method('setTimestamp')->willReturn($event);
				return $event;
			}
		);
		$activity->method('publish')->willReturnCallback(
			function (): void {
				$this->record(what: 'activity.publish', args: []);
			}
		);

		$history = $this->createMock(NotificationHistoryMapper::class);
		$history->method('record')->willReturnCallback(
			function (HistoryEntry $entry) use ($historyThrows): NotificationHistory {
				$this->record(what: 'history.record', args: [
					$entry->delivery->ruleId,
					$entry->delivery->channel,
					$entry->delivery->recipient,
					$entry->delivery->status,
					$entry->target->schemaId,
					$entry->target->registerId,
					$entry->target->objectUuid,
					$entry->notice->subject,
					$entry->notice->errorMessage,
					$entry->notice->locale,
					null,
					null,
					$entry->target->eventId,
				]);
				if ($historyThrows === true) {
					throw new \RuntimeException('db down');
				}

				return new NotificationHistory();
			}
		);

		$ncSender = $this->createMock(NcNotificationSender::class);
		$ncSender->method('notify')->willReturnCallback(
			function (InAppNotice $notice): string {
				$this->record(what: 'nc.send', args: [
					$notice->uid,
					$notice->object,
					$notice->content->subjectKey,
					$notice->rule->name,
					$notice->content->subject,
					$notice->content->message,
					$notice->content->context,
					$notice->rule->originApp,
					$notice->rule->actions,
					$notice->rule->webPushActive,
				]);
				return 'dispatched';
			}
		);

		$emailSender = $this->createMock(EmailSender::class);
		$emailSender->method('send')->willReturnCallback(
			function (...$args): string {
				$this->record(what: 'email.send', args: $args);
				return EmailSender::OUTCOME_DISPATCHED;
			}
		);

		return new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: $ncSender,
				emailSender: $emailSender,
				talkSender: new TalkSender(
					httpClient: $this->createMock(IClientService::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				activityManager: $activity
			),
			gates: new DispatchGates(
				throttle: new DispatchThrottle(),
				deliveryHold: new DeliveryHold()
			),
			integrations: new AppIntegrations(
				httpClient: $this->createMock(IClientService::class),
				serverContainer: $this->createMock(IServerContainer::class)
			),
			ledger: new DeliveryLedger(
				logger: $this->createMock(LoggerInterface::class),
				historyMapper: $history
			),
			audience: new RuleAudience(
				schemaMapper: $this->createMock(SchemaMapper::class),
				recipientResolver: new NotificationRecipientResolver(
					userManager: $this->createMock(IUserManager::class),
					groupManager: $this->createMock(IGroupManager::class),
					logger: $this->createMock(LoggerInterface::class),
					serverContainer: $this->createMock(IServerContainer::class)
				)
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);
	}//end dispatcher()
}//end class
