<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the three-layer merge and the scoped preference.
 *
 * The thing under test is not "does a value come back" — the two-layer version
 * already did that — but WHICH layer decided and whether the read says so. A
 * merged preference that cannot name its source is a support call with three
 * possible answers and none of them offered.
 */
class NotificationPreferenceLayersTest extends TestCase {
	private IConfig&MockObject $config;
	private SchemaMapper&MockObject $schemaMapper;
	private LoggerInterface&MockObject $logger;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private NotificationPreferenceService $service;

	/**
	 * Stored per-user values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $userValues = [];

	/**
	 * Stored app values (the group defaults and template edits), keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string
				=> ($this->userValues[$uid . '|' . $key] ?? $default)
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->userValues[$uid . '|' . $key] = $value;
			}
		);
		$this->config->method('deleteUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key): void {
				unset($this->userValues[$uid . '|' . $key]);
			}
		);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			}
		);
		$this->config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->appValues[$key]);
			}
		);

		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);

		$this->service = new NotificationPreferenceService(
			$this->config,
			$this->schemaMapper,
			$this->logger,
			$this->groupManager,
			$this->userManager
		);
	}

	/**
	 * Put the user in these groups for the rest of the test.
	 *
	 * @param array<int, string> $groupIds The groups.
	 */
	private function inGroups(array $groupIds): void {
		$this->groupManager->method('getUserGroupIds')->willReturn($groupIds);
	}

	/**
	 * The schema default decides when nothing else is stored, and says so.
	 */
	public function testSchemaDefaultDecidesAndIsNamed(): void {
		$this->inGroups(['behandelaars']);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email', 'nc-notification']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame('schema-default', $effective['source']);
		$this->assertSame('global', $effective['scope']);
		$this->assertSame(['email', 'nc-notification'], $effective['channels']);
	}

	/**
	 * A team default overrides the schema default, and the layer is named as the group.
	 */
	public function testGroupDefaultOverridesSchemaDefaultAndIsNamed(): void {
		$this->inGroups(['behandelaars']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => true, 'channels' => ['nc-notification']]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email', 'nc-notification']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertSame(['nc-notification'], $effective['channels']);
		$this->assertSame('group-default', $effective['source']);
	}

	/**
	 * The user still wins over their team's default, and the layer says so.
	 */
	public function testUserOverrideBeatsGroupDefault(): void {
		$this->inGroups(['behandelaars']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => true, 'channels' => ['nc-notification']]
		);
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => true, 'channels' => ['email']]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email', 'nc-notification']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertSame(['email'], $effective['channels']);
		$this->assertSame('user-override', $effective['source']);
	}

	/**
	 * A group default belongs to the group: a user in a different group sees
	 * the schema default, not somebody else's team setting.
	 */
	public function testAGroupDefaultDoesNotReachAnotherGroup(): void {
		$this->inGroups(['baliemedewerkers']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => false]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email']],
			userId: 'bram',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame('schema-default', $effective['source']);
	}

	/**
	 * Loud for one schema, quiet everywhere else: a scoped value overrides the
	 * same user's global one, for that scope only.
	 */
	public function testAScopedValueBeatsTheSamePartysGlobalValue(): void {
		$this->inGroups([]);

		// Globally off.
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => false]
		);
		// On for vergunningen.
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => true, 'channels' => ['email']],
			scope: 'schema:vergunning'
		);

		$scoped = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			scopes: ['schema:vergunning']
		);
		$elsewhere = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			scopes: ['schema:melding']
		);

		$this->assertTrue($scoped['enabled'], 'the scoped value should apply on its own schema');
		$this->assertSame('schema:vergunning', $scoped['scope']);
		$this->assertFalse($elsewhere['enabled'], 'another schema falls through to the global value');
		$this->assertSame('global', $elsewhere['scope']);
	}

	/**
	 * An unset scope falls through to the global value with nothing stored for
	 * it: no row needs to pre-exist, which is what keeps this migration-free.
	 */
	public function testAnUnsetScopeFallsThroughToTheGlobalValue(): void {
		$this->inGroups([]);

		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => false]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			scopes: ['schema:zaak', 'register:7']
		);

		$this->assertFalse($effective['enabled']);
		$this->assertSame('global', $effective['scope']);
		$this->assertSame('user-override', $effective['source']);
	}

	/**
	 * The narrowest scope wins among several that are set, in the order the
	 * service declares: schema, then domain, then register.
	 */
	public function testTheNarrowestScopeWins(): void {
		$this->inGroups([]);

		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => false],
			scope: 'register:7'
		);
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => true],
			scope: 'schema:zaak'
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => false],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			scopes: ['schema:zaak', 'register:7']
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame('schema:zaak', $effective['scope']);
	}

	/**
	 * The trace behind the answer: every layer that contributed, in order.
	 */
	public function testTheLayersAreTraced(): void {
		$this->inGroups(['behandelaars']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => true, 'channels' => ['nc-notification']]
		);
		$this->service->setOverride(
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			override: ['enabled' => false]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email', 'nc-notification']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$names = array_column($effective['layers'], 'layer');
		$this->assertSame(['schema-default', 'group-default', 'user-override'], $names);
		$this->assertFalse($effective['enabled']);
	}

	/**
	 * A group cannot hand its members a channel the rule does not declare.
	 */
	public function testAGroupCannotWidenBeyondTheSchemasChannels(): void {
		$this->inGroups(['behandelaars']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => true, 'channels' => ['email', 'talk']]
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true, 'channels' => ['email']],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertSame(['email'], $effective['channels']);
	}

	/**
	 * Clearing a group default restores the layer below it.
	 */
	public function testClearingAGroupDefaultRestoresTheSchemaDefault(): void {
		$this->inGroups(['behandelaars']);

		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: ['enabled' => false]
		);
		$this->service->setGroupDefault(
			groupId: 'behandelaars',
			schemaSlug: 'zaak',
			notificationKey: 'termijn',
			default: null
		);

		$effective = $this->service->resolveEffective(
			schemaDefault: ['enabled' => true],
			userId: 'anna',
			schemaSlug: 'zaak',
			notificationKey: 'termijn'
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame('schema-default', $effective['source']);
	}

	/**
	 * A scoped key is distinct from the global one it sits beside, so setting
	 * one can never overwrite the other.
	 */
	public function testAScopedKeyIsDistinctFromTheGlobalKey(): void {
		$this->assertNotSame(
			$this->service->configKey('zaak', 'termijn'),
			$this->service->scopedConfigKey('schema:zaak', 'zaak', 'termijn')
		);
		$this->assertLessThanOrEqual(64, strlen($this->service->scopedConfigKey('schema:zaak', 'zaak', 'termijn')));
	}

	/**
	 * A long scope + slug + key still compresses to a key the column accepts,
	 * and two different long triples do not collide on one row.
	 */
	public function testALongScopedKeyCompressesWithoutColliding(): void {
		$one = $this->service->scopedConfigKey(
			'domain:vergunningen-en-handhaving-afdeling-west',
			'openregister_configuration',
			'configuration-changed'
		);
		$two = $this->service->scopedConfigKey(
			'domain:vergunningen-en-handhaving-afdeling-oost',
			'openregister_configuration',
			'configuration-changed'
		);

		$this->assertLessThanOrEqual(64, strlen($one));
		$this->assertLessThanOrEqual(64, strlen($two));
		$this->assertNotSame($one, $two);
	}
}
