<?php

/**
 * The equivalence and independence properties between the two callers of the
 * notification subsystem's channel machinery.
 *
 * - EQUIVALENCE: a schema annotation and a send-notification node, configured
 *   with the same recipients and template against the same object, produce
 *   identical deliveries — recorded through ONE sender double injected into
 *   BOTH callers, which is also the structural proof they share the channel
 *   sender rather than each carrying their own.
 * - INDEPENDENCE: the instance FLOW kill switch does not silence declarative
 *   notifications — the dispatcher structurally never consults it — while the
 *   flow side's own oversight check refuses under the same config.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheck;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCA\OpenRegister\Service\Notification\TalkSender;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records every send handed to the nc-notification channel unit.
 */
class RecordingNcSender extends NcNotificationSender {
	/** @var array<int, array<string, string>> */
	public array $sends = [];

	public function send(
		string $uid,
		ObjectEntity $object,
		string $subjectKey,
		string $name,
		string $subject,
		string $message,
		array $context,
		string $originApp = 'openregister',
		array $actions = [],
		bool $webPushActive = false,
	): string {
		$this->sends[] = [
			'channel' => 'nc-notification',
			'uid' => $uid,
			'subject' => $subject,
			'message' => $message,
		];

		return self::OUTCOME_DISPATCHED;
	}

	public function enqueueWebPush(
		array $recipients,
		string $ruleId,
		string $originApp,
		string $subject,
		string $message,
		array $actions,
		ObjectEntity $object,
	): void {
		// Ride-alongs are not part of the delivery comparison.
	}
}//end class

/**
 * Same send from both callers; independent kill switches.
 */
class FlowMessagingEquivalenceTest extends TestCase {

	/**
	 * App-config values behind both subsystems' switches.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	/**
	 * The one recorded sender both callers are wired onto.
	 */
	private RecordingNcSender $recorder;

	private IUserManager $userManager;

	private IGroupManager $groupManager;

	private IAppConfig $appConfig;

	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);
		$userManager->method('get')->willReturnCallback(
			function (string $uid) {
				$user = $this->createMock(\OCP\IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn(true);
				return $user;
			}
		);
		$this->userManager = $userManager;
		$this->groupManager = $this->createMock(IGroupManager::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->appValues[$key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (($this->appValues[$key] ?? null) === 'true') || $default
		);
		$this->appConfig = $appConfig;

		$this->recorder = new RecordingNcSender(
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * The declarative dispatcher, wired onto the recorded sender.
	 *
	 * @return AnnotationNotificationDispatcher The dispatcher.
	 */
	private function makeDispatcher(): AnnotationNotificationDispatcher {
		return new AnnotationNotificationDispatcher(
			schemaMapper: $this->createMock(SchemaMapper::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->logger,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			mailer: $this->createMock(IMailer::class),
			activityManager: $this->createMock(IActivityManager::class),
			httpClient: $this->createMock(IClientService::class),
			serverContainer: $this->createMock(IServerContainer::class),
			ncSender: $this->recorder,
			recipientResolver: new NotificationRecipientResolver(
				userManager: $this->userManager,
				groupManager: $this->groupManager,
				logger: $this->logger
			),
			templating: new NotificationTemplating(logger: $this->logger)
		);
	}//end makeDispatcher()

	/**
	 * The flow messaging service, wired onto the SAME recorded sender.
	 *
	 * @return FlowMessagingService The service.
	 */
	private function makeMessaging(): FlowMessagingService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(\OCP\ICache::class));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnArgument(3);

		return new FlowMessagingService(
			channelPolicy: new NotificationChannelPolicy(appConfig: $this->appConfig, logger: $this->logger),
			recipientResolver: new NotificationRecipientResolver(
				userManager: $this->userManager,
				groupManager: $this->groupManager,
				logger: $this->logger
			),
			templating: new NotificationTemplating(logger: $this->logger),
			ncSender: $this->recorder,
			emailSender: new EmailSender(
				userManager: $this->userManager,
				mailer: $this->createMock(IMailer::class),
				logger: $this->logger
			),
			talkSender: new TalkSender(
				httpClient: $this->createMock(IClientService::class),
				logger: $this->logger
			),
			rateLimiter: new RateLimiter(
				cacheFactory: $cacheFactory,
				appConfig: $this->appConfig,
				logger: $this->logger
			),
			preferences: new NotificationPreferenceService(
				config: $config,
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: $this->logger
			),
			userManager: $this->userManager,
			appConfig: $this->appConfig,
			logger: $this->logger
		);
	}//end makeMessaging()

	public function testSchemaAnnotationAndFlowNodeProduceTheSameSendThroughTheSameSender(): void {
		$data = ['title' => 'Bezwaar 12', 'status' => 'moved'];

		// The DECLARATIVE path: a schema annotation firing on the object.
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('case');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'case-moved' => [
						'trigger' => ['type' => 'updated'],
						'channels' => ['nc-notification'],
						'recipients' => [
							[
								'kind' => 'users',
								'users' => ['bob', 'carol'],
							],
						],
						'subject' => 'Case {{ title }} moved',
						'message' => 'Case {{ title }} is now {{ status }}.',
					],
				],
			]
		);

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setObject($data);

		$this->makeDispatcher()->dispatchWithSchema(object: $object, trigger: 'updated', context: [], schema: $schema);
		$declarative = $this->recorder->sends;
		$this->recorder->sends = [];

		// The FLOW path: a send-notification node's config with the same
		// recipients and templates, against the same object as an item.
		$this->makeMessaging()->sendNotification(
			config: [
				'recipients' => ['bob', 'carol'],
				'title' => 'Case {{ title }} moved',
				'message' => 'Case {{ title }} is now {{ status }}.',
			],
			items: [FlowItems::item(json: $data)],
			context: ['runAs' => 'alice'],
			stepName: 'openregister.send-notification'
		);
		$flow = $this->recorder->sends;

		// Identical in channel, body and recipients — and both lists exist at
		// all only because both callers went through the SAME sender instance.
		$this->assertNotSame([], $declarative);
		$this->assertSame($declarative, $flow);
	}//end testSchemaAnnotationAndFlowNodeProduceTheSameSendThroughTheSameSender()

	public function testTheFlowKillSwitchDoesNotSilenceDeclarativeNotifications(): void {
		// The instance FLOW kill switch is set: the flow side's own oversight
		// check refuses every hop under this config…
		$this->appValues[KillSwitchCheck::CONFIG_KEY] = 'true';
		$check = new KillSwitchCheck(appConfig: $this->appConfig);
		$this->assertNotNull($check->veto(context: []), 'The flow kill switch must refuse flow hops under this config');

		// …and the DECLARATIVE dispatch still delivers: killing flows never
		// silences case-update notifications.
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('case');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'case-moved' => [
						'trigger' => ['type' => 'updated'],
						'channels' => ['nc-notification'],
						'recipients' => [
							[
								'kind' => 'users',
								'users' => ['bob'],
							],
						],
						'subject' => 'moved',
					],
				],
			]
		);
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setObject(['title' => 'x']);

		$this->makeDispatcher()->dispatchWithSchema(object: $object, trigger: 'updated', context: [], schema: $schema);

		$this->assertCount(1, $this->recorder->sends);
		$this->assertSame('bob', $this->recorder->sends[0]['uid']);
	}//end testTheFlowKillSwitchDoesNotSilenceDeclarativeNotifications()
}//end class
