<?php

/**
 * Unit tests for the notification run records the dispatcher writes: sent, partial, duplicate skipped, no recipients.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Db\DuplicateDispatchException;
use OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper;
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
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Db\Entity;
use OCP\Http\Client\IClientService;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One record per evaluation of a declared notification, recipients counted, never named.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally
 * @uses   \OCA\OpenRegister\Service\AutomationRecordWriter
 * @uses   \OCA\OpenRegister\Db\AutomationRecord
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ActionLinkResolver
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ActionTargetContext
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\AppRouteBase
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ChannelSet
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DeliveryDetail
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DeliveryOrigin
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DeliveryRecord
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DispatchGates
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\MessageRendering
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\MessageTarget
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationEvent
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerMatcher
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ObjectDetailLinks
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\PlaceholderValues
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RecipientDelivery
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RecipientSource
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleAudience
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleFiring
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TalkSender
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TaskActionLinks
 */
final class NotificationRunRecordTest extends TestCase {

	/**
	 * The records the store received.
	 *
	 * @var array<int, AutomationRecord>
	 */
	private array $written = [];

	/**
	 * Dispatch one `created` event for a rule with key `aut-7f3a` and return its records.
	 *
	 * @param array<int, string>          $uids      The recipients the resolver returns.
	 * @param array<string, mixed>        $rule      Extra rule declaration.
	 * @param array<string, string>       $mail      The email outcome per uid.
	 * @param NotificationDispatchLogMapper|null $dedup The idempotency log.
	 *
	 * @return array<int, AutomationRecord>
	 */
	private function dispatch(array $uids, array $rule = [], array $mail = [], ?NotificationDispatchLogMapper $dedup = null): array {
		$this->written = [];
		$resolver = $this->createMock(NotificationRecipientResolver::class);
		$resolver->method('resolveFromSource')->willReturn(['uids' => $uids, 'unresolved' => []]);

		$email = $this->createMock(EmailSender::class);
		$email->method('send')->willReturnCallback(
			static function (string $uid) use ($mail): string {
				if (($mail[$uid] ?? '') === '__throw__') {
					throw new RuntimeException('boom');
				}

				return ($mail[$uid] ?? 'dispatched');
			}
		);

		$store = $this->createMock(AutomationRecordMapper::class);
		$store->method('insert')->willReturnCallback(
			function (Entity $record): Entity {
				$this->written[] = $record;
				return $record;
			}
		);

		$dispatcher = new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: $this->createMock(NcNotificationSender::class),
				emailSender: $email,
				talkSender: new TalkSender(httpClient: $this->createMock(IClientService::class), logger: $this->createMock(LoggerInterface::class)),
				activityManager: $this->createMock(IActivityManager::class)
			),
			gates: new DispatchGates(throttle: new DispatchThrottle(), deliveryHold: new DeliveryHold()),
			integrations: new AppIntegrations(httpClient: $this->createMock(IClientService::class), serverContainer: $this->createMock(IServerContainer::class)),
			ledger: new DeliveryLedger(
				logger: $this->createMock(LoggerInterface::class),
				dispatchLogMapper: $dedup,
				runRecords: new AutomationRecordWriter($store, $this->createMock(LoggerInterface::class))
			),
			audience: new RuleAudience(schemaMapper: $this->createMock(SchemaMapper::class), recipientResolver: $resolver),
			rendering: new MessageRendering(templating: new NotificationTemplating(logger: $this->createMock(LoggerInterface::class)))
		);

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('meldingen');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'case-assigned' => array_merge(['key' => 'aut-7f3a', 'trigger' => ['type' => 'created'], 'channels' => ['email']], $rule),
					'other-trigger' => ['key' => 'aut-0000', 'trigger' => ['type' => 'deleted'], 'channels' => ['email']],
				],
			]
		);

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setRegister('4');
		$object->setSchema('9');
		$object->setObject(['title' => 'Demo']);

		$dispatcher->dispatchWithSchema($object, 'created', [], $schema);

		return $this->written;
	}

	public function testEveryRecipientReachedIsSent(): void {
		$records = $this->dispatch(uids: ['alice', 'bob']);

		$this->assertCount(1, $records, 'a rule whose trigger does not match leaves no record');
		$record = $records[0];
		$this->assertSame(
			['notification', 'aut-7f3a', 'case-assigned', 'sent', null, '9', '4', 'uuid-1'],
			[$record->getKind(), $record->getAutomationKey(), $record->getName(), $record->getOutcome(), $record->getReason(), $record->getSchemaId(), $record->getRegisterId(), $record->getObjectUuid()]
		);
		$this->assertSame(['email' => ['sent' => 2, 'failed' => 0]], $record->channelCounts());
		$this->assertNotSame('', (string)$record->getEventId());
		$this->assertStringNotContainsString('alice', (string)json_encode($record->jsonSerialize()));
	}

	public function testTwoOfThreeByMailIsPartial(): void {
		$records = $this->dispatch(uids: ['alice', 'bob', 'carol'], mail: ['bob' => EmailSender::OUTCOME_FAILED]);

		$this->assertSame('partial', $records[0]->getOutcome());
		$this->assertSame(['email' => ['sent' => 2, 'failed' => 1]], $records[0]->channelCounts());
	}

	public function testADuplicateIsRecordedAsSkipped(): void {
		$dedup = $this->createMock(NotificationDispatchLogMapper::class);
		$dedup->method('record')->willThrowException(new DuplicateDispatchException('dup'));

		$records = $this->dispatch(uids: ['alice'], rule: ['idempotencyKey' => 'once'], dedup: $dedup);

		$this->assertCount(1, $records);
		$this->assertSame(['skipped', 'duplicate'], [$records[0]->getOutcome(), $records[0]->getReason()]);
		$this->assertNull($records[0]->getEventId());
	}

	public function testNoRecipientsIsRecordedAsSkipped(): void {
		$records = $this->dispatch(uids: []);

		$this->assertSame(['skipped', 'no-recipients'], [$records[0]->getOutcome(), $records[0]->getReason()]);
	}

	public function testAnErrorIsRecordedAsFailedAndStillThrown(): void {
		$email = 'boom';
		try {
			$this->dispatchWithFailingMail();
			$this->fail('The error must still surface');
		} catch (RuntimeException $e) {
			$this->assertSame($email, $e->getMessage());
		}

		$this->assertSame(['failed', 'boom'], [$this->written[0]->getOutcome(), $this->written[0]->getMessage()]);
	}

	/**
	 * Dispatch with an email channel that throws.
	 */
	private function dispatchWithFailingMail(): void {
		$this->dispatch(uids: ['alice'], mail: ['alice' => '__throw__']);
	}
}
