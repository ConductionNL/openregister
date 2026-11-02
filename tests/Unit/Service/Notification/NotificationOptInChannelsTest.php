<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers opt-in channels: a rule declares a channel that is off until a
 * person switches it on for that notification.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
class NotificationOptInChannelsTest extends TestCase {
	private NotificationPreferenceService $service;

	/**
	 * Stored per-user values, keyed by uid and config key.
	 *
	 * @var array<string, string>
	 */
	private array $userValues = [];

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string
				=> ($this->userValues[$uid . '|' . $key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->userValues[$uid . '|' . $key] = $value;
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		$this->service = new NotificationPreferenceService(
			$config,
			$this->createMock(SchemaMapper::class),
			$this->createMock(LoggerInterface::class),
			$groupManager,
			$userManager
		);
	}

	/**
	 * Nobody gets an opt-in channel by default.
	 */
	public function testAnOptInChannelIsOffByDefault(): void {
		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['nc-notification'], 'optInChannels' => ['email']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen'
		);

		$this->assertSame(['nc-notification'], $effective['channels']);
	}

	/**
	 * A rule that leaves `channels` out still defaults to Nextcloud only.
	 */
	public function testAnOptInChannelWithoutChannelsDefaultsToNextcloud(): void {
		$effective = $this->service->resolveEffective(
			schemaDefault: ['optInChannels' => ['email']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen'
		);

		$this->assertSame(['nc-notification'], $effective['channels']);
	}

	/**
	 * A person who switches the opt-in channel on gets it, for that notification.
	 */
	public function testAPersonCanSwitchAnOptInChannelOn(): void {
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen',
			override: ['enabled' => true, 'channels' => ['nc-notification', 'email']]
		);

		$spec = ['enabled' => true, 'channels' => ['nc-notification'], 'optInChannels' => ['email']];
		$effective = $this->service->resolveEffective(
			schemaDefault: $spec,
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen'
		);

		$this->assertSame(['nc-notification', 'email'], $effective['channels']);
		$this->assertSame('user-override', $effective['source']);
	}

	/**
	 * A channel the rule does not declare at all can still not be chosen.
	 */
	public function testAnUndeclaredChannelCannotBeSwitchedOn(): void {
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen',
			override: ['enabled' => true, 'channels' => ['nc-notification', 'email']]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['nc-notification']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'toegewezen'
		);

		$this->assertSame(['nc-notification'], $effective['channels']);
	}

	/**
	 * The available channels are the default ones, then the opt-in ones; a
	 * broadcast or doubled channel is not opt-in.
	 */
	public function testAvailableChannelsListDefaultThenOptIn(): void {
		$spec = ['channels' => ['nc-notification', 'webhook'], 'optInChannels' => ['email', 'talk', 'nc-notification', 'email']];

		$this->assertSame(['email'], NotificationPreferenceService::optInChannelsOf(spec: $spec));
		$this->assertSame(['nc-notification', 'webhook', 'email'], NotificationPreferenceService::availableChannelsOf(spec: $spec));
	}

	/**
	 * The validator accepts a per-recipient opt-in channel and refuses a broadcast one.
	 */
	public function testTheValidatorChecksOptInChannels(): void {
		$validator = new NotificationAnnotationValidator();
		$schema = static fn (array $optIn): array => [
			'x-openregister-notifications' => [
				'x' => [
					'trigger' => ['type' => 'created'],
					'recipients' => [['kind' => 'users', 'users' => ['admin']]],
					'channels' => ['nc-notification'],
					'optInChannels' => $optIn,
					'subject' => 'hi',
				],
			],
			'properties' => [],
		];

		$codes = static fn (array $errors): array => array_column($errors, 'code');
		$this->assertNotContains('notification-bad-opt-in-channel', $codes($validator->validate($schema(['email']))));
		$this->assertContains('notification-bad-opt-in-channel', $codes($validator->validate($schema(['talk']))));
	}
}
