<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Db\HistoryEntry;
use OCA\OpenRegister\Notifications\Db\NotificationHistory;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
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
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCP\IConfig;
use OCA\OpenRegister\Notifications\Service\Notification\OutboundTransportInterface;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers one firing reaching a person and an integration, recorded once.
 *
 * The claim under test is not "both ran" but "both are readable as one event".
 * Two transports that each record a row with no shared identifier are exactly
 * the situation where a notification and an outbound call drift apart and
 * nobody can tell they were ever meant to be the same thing.
 */
class AnnotationNotificationDispatcherTransportsTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private INotificationManager&MockObject $notificationManager;
	private LoggerInterface&MockObject $logger;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private IMailer&MockObject $mailer;
	private IActivityManager&MockObject $activityManager;
	private IClientService&MockObject $httpClient;
	private IServerContainer&MockObject $serverContainer;
	private NotificationHistoryMapper&MockObject $historyMapper;

	/**
	 * Services the dispatcher resolves through the container, keyed by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $serverServices = [];

	/**
	 * Every history row the dispatcher recorded.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	protected function setUp(): void {
		parent::setUp();
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->httpClient = $this->createMock(IClientService::class);
		$this->serverContainer = $this->createMock(IServerContainer::class);
		$this->historyMapper = $this->createMock(NotificationHistoryMapper::class);

		$this->serverContainer->method('get')->willReturnCallback(
			fn (string $id): mixed => ($this->serverServices[$id] ?? null)
		);
		$this->userManager->method('userExists')->willReturn(true);

		$this->historyMapper->method('record')->willReturnCallback(
			function (HistoryEntry $entry): NotificationHistory {
				$this->recorded[] = [
					'ruleId' => $entry->delivery->ruleId,
					'channel' => $entry->delivery->channel,
					'recipient' => $entry->delivery->recipient,
					'status' => $entry->delivery->status,
					'errorMessage' => $entry->notice->errorMessage,
					'eventId' => $entry->target->eventId,
				];

				return new NotificationHistory();
			}
		);

		$this->notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notif = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage'] as $setter) {
					$notif->method($setter)->willReturnSelf();
				}

				return $notif;
			}
		);
	}

	/**
	 * A schema carrying one notification rule.
	 *
	 * @param array<string, mixed> $notifications The rules.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $notifications): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('zaak');
		$schema->setConfiguration(['x-openregister-notifications' => $notifications]);

		return $schema;
	}

	/**
	 * An object on that schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectOn(Schema $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setSchema((string)$schema->getSlug());
		$object->setRegister('7');
		$object->setObject(['title' => 'demo']);

		return $object;
	}

	/**
	 * Build the dispatcher under test.
	 *
	 * @return AnnotationNotificationDispatcher The dispatcher.
	 */
	private function dispatcher(): AnnotationNotificationDispatcher {
		return new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: new NcNotificationSender(
					notificationManager: $this->notificationManager,
					logger: $this->logger,
					userManager: $this->userManager
				),
				emailSender: new EmailSender(
					userManager: $this->userManager,
					mailer: $this->mailer,
					logger: $this->logger
				),
				talkSender: new TalkSender(
					httpClient: $this->httpClient,
					logger: $this->logger,
					config: $this->serverContainer->get(IConfig::class)
				),
				activityManager: $this->activityManager
			),
			gates: new DispatchGates(
				throttle: new DispatchThrottle(),
				deliveryHold: new DeliveryHold()
			),
			integrations: new AppIntegrations(
				httpClient: $this->httpClient,
				serverContainer: $this->serverContainer
			),
			ledger: new DeliveryLedger(
				logger: $this->logger,
				historyMapper: $this->historyMapper
			),
			audience: new RuleAudience(
				schemaMapper: $this->schemaMapper,
				recipientResolver: new NotificationRecipientResolver(
					userManager: $this->userManager,
					groupManager: $this->groupManager,
					logger: $this->logger,
					serverContainer: $this->serverContainer
				)
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(
					logger: $this->logger
				)
			)
		);
	}

	/**
	 * One firing, an in-app notice and an outbound call, one event id.
	 */
	public function testTwoTransportsShareOneEventId(): void {
		$transport = new class implements OutboundTransportInterface {
			/**
			 * The event id this transport was handed.
			 *
			 * @var string|null
			 */
			public ?string $sawEventId = null;

			public function send(
				ObjectEntity $object,
				string $notificationName,
				array $recipients,
				array $context,
				array $config,
				string $eventId,
			): ?string {
				$this->sawEventId = $eventId;
				return null;
			}
		};
		$this->serverServices['zgw.transport'] = $transport;

		$schema = $this->schemaWith(
			[
				'zaak-gewijzigd' => [
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'transports' => [['kind' => 'outbound', 'handler' => 'zgw.transport']],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'subject' => 'zaak gewijzigd',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->dispatcher()->dispatch($this->objectOn($schema), 'updated');

		$channels = array_column($this->recorded, 'channel');
		$this->assertContains('nc-notification', $channels);
		$this->assertContains('outbound', $channels);

		$eventIds = array_unique(array_column($this->recorded, 'eventId'));
		$this->assertCount(1, $eventIds, 'one firing must record under one event id');
		$this->assertNotNull($eventIds[array_key_first($eventIds)]);
		$this->assertSame($eventIds[array_key_first($eventIds)], $transport->sawEventId);
	}

	/**
	 * A transport that fails does not stop the notice, and records itself as
	 * the one thing that failed.
	 */
	public function testAFailingTransportDoesNotStopTheOther(): void {
		$this->serverServices['broken.transport'] = new class implements OutboundTransportInterface {
			public function send(
				ObjectEntity $object,
				string $notificationName,
				array $recipients,
				array $context,
				array $config,
				string $eventId,
			): ?string {
				throw new \RuntimeException('endpoint is down');
			}
		};

		$schema = $this->schemaWith(
			[
				'zaak-gewijzigd' => [
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'transports' => [['kind' => 'outbound', 'handler' => 'broken.transport']],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'subject' => 'zaak gewijzigd',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->dispatcher()->dispatch($this->objectOn($schema), 'updated');

		$byChannel = array_column($this->recorded, null, 'channel');
		$this->assertSame('dispatched', $byChannel['nc-notification']['status']);
		$this->assertSame('failed', $byChannel['outbound']['status']);
		$this->assertStringContainsString('endpoint is down', (string)$byChannel['outbound']['errorMessage']);
	}

	/**
	 * A transport naming a handler that is not one is recorded as a failure,
	 * never as a delivery that quietly did nothing.
	 */
	public function testAHandlerThatIsNotATransportIsRecordedAsFailed(): void {
		$this->serverServices['not.a.transport'] = new \stdClass();

		$schema = $this->schemaWith(
			[
				'zaak-gewijzigd' => [
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'transports' => [['kind' => 'outbound', 'handler' => 'not.a.transport']],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'subject' => 'zaak gewijzigd',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->dispatcher()->dispatch($this->objectOn($schema), 'updated');

		$byChannel = array_column($this->recorded, null, 'channel');
		$this->assertSame('failed', $byChannel['outbound']['status']);
		$this->assertStringContainsString('OutboundTransportInterface', (string)$byChannel['outbound']['errorMessage']);
	}

	/**
	 * A rule addressing a group that no longer exists records a failed
	 * dispatch naming the group, rather than delivering to nobody in silence.
	 */
	public function testAnUnresolvableGroupIsRecordedNamingTheGroup(): void {
		$this->groupManager->method('get')->willReturn(null);

		$schema = $this->schemaWith(
			[
				'termijn' => [
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'groups', 'groups' => ['opgeheven-team']]],
					'subject' => 'termijn verloopt',
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->dispatcher()->dispatch($this->objectOn($schema), 'updated');

		$byChannel = array_column($this->recorded, null, 'channel');
		$this->assertArrayHasKey('groups', $byChannel);
		$this->assertSame('recipient-unresolved', $byChannel['groups']['status']);
		$this->assertSame('opgeheven-team', $byChannel['groups']['recipient']);
	}

	/**
	 * A rule may address a role the schema assigns, and the members are read
	 * at dispatch.
	 */
	public function testARoleRecipientReachesTheAssignedGroupsMembers(): void {
		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('anna');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ($gid === 'behandelaars' ? $group : null)
		);

		$schema = $this->schemaWith(
			[
				'termijn' => [
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'role', 'role' => 'behandelaar']],
					'subject' => 'termijn verloopt',
				],
			]
		);
		$schema->setAuthorization(['roles' => ['behandelaar' => ['behandelaars']]]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->dispatcher()->dispatch($this->objectOn($schema), 'updated');

		$recipients = array_column($this->recorded, 'recipient');
		$this->assertContains('anna', $recipients);
	}
}
