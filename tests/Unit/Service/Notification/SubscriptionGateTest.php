<?php

/**
 * The subscription gate of AnnotationNotificationDispatcher.
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

use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ChannelSet;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchGates;
use OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\MessageRendering;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\InAppNotice;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
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
 * A rule with `requiresSubscription: true` reaches only the resolved uids
 * that subscribed to the object's register and schema.
 */
class SubscriptionGateTest extends TestCase {

	/**
	 * Dispatch a subscription-gated rule to the given uids and return who got the notice.
	 *
	 * @param array<int, string> $uids       The uids the recipient resolver returns.
	 * @param array<int, string> $subscribed The uids subscribed to register 4, schema 9.
	 *
	 * @return array<int, string>
	 */
	private function notified(array $uids, array $subscribed): array {
		$resolver = $this->createMock(NotificationRecipientResolver::class);
		$resolver->method('resolveWithDiagnostics')->willReturn(['uids' => $uids, 'unresolved' => []]);

		$subscriptions = $this->createMock(NotificationSubscriptionMapper::class);
		$subscriptions->method('findSubscribedUids')->with(4, 9)->willReturn($subscribed);

		$sent       = [];
		$ncSender   = $this->createMock(NcNotificationSender::class);
		$ncSender->method('notify')->willReturnCallback(
			static function (InAppNotice $notice) use (&$sent): string {
				$sent[] = $notice->uid;
				return 'dispatched';
			}
		);

		$dispatcher = new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: $ncSender,
				emailSender: new EmailSender(
					userManager: $this->createMock(IUserManager::class),
					mailer: $this->createMock(IMailer::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				talkSender: new TalkSender(
					httpClient: $this->createMock(IClientService::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				activityManager: $this->createMock(IActivityManager::class)
			),
			gates: new DispatchGates(
				throttle: new DispatchThrottle(),
				deliveryHold: new DeliveryHold(),
				subscriptionMapper: $subscriptions
			),
			integrations: new AppIntegrations(
				httpClient: $this->createMock(IClientService::class),
				serverContainer: $this->createMock(IServerContainer::class)
			),
			ledger: new DeliveryLedger(
				logger: $this->createMock(LoggerInterface::class)
			),
			audience: new RuleAudience(
				schemaMapper: $this->createMock(SchemaMapper::class),
				recipientResolver: $resolver
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('meldingen');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'r' => ['trigger' => ['type' => 'created'], 'requiresSubscription' => true],
				],
			]
		);

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setRegister('4');
		$object->setSchema('9');
		$object->setObject(['title' => 'Demo']);

		$dispatcher->dispatchWithSchema($object, 'created', [], $schema);

		return $sent;
	}//end notified()

	/**
	 * Subscribers are notified; resolved uids that did not subscribe are not.
	 *
	 * @return void
	 */
	public function testOnlySubscribedUidsAreNotified(): void {
		$this->assertSame(['alice', 'carol'], $this->notified(uids: ['alice', 'bob', 'carol'], subscribed: ['carol', 'alice', 'dave']));
	}//end testOnlySubscribedUidsAreNotified()

	/**
	 * Nobody subscribed: nobody is notified.
	 *
	 * @return void
	 */
	public function testNoSubscribersNotifiesNobody(): void {
		$this->assertSame([], $this->notified(uids: ['alice', 'bob'], subscribed: []));
	}//end testNoSubscribersNotifiesNobody()
}//end class
