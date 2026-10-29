<?php

/**
 * Golden characterization test for NotificationPreferenceService::resolveEffective().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Resolves a fixed corpus of schema defaults, stored values, scopes and
 * groups, and compares each answer and every config read with
 * `fixtures/preference-resolve-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Notification\NotificationPreferenceService
 * @covers \OCA\OpenRegister\Service\Notification\PreferenceLookup
 */
final class NotificationPreferenceResolveGoldenTest extends TestCase {

	/**
	 * Config reads recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/preference-resolve-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [schema default, stored values, scopes, group ids or null].
	 *
	 * A stored value is [party, scope or null, value]; party is `user` or `group:<id>`.
	 *
	 * @return array<string, array{0: array, 1: array, 2: array, 3: ?array}>
	 */
	private static function corpus(): array {
		$optIn = ['channels' => ['nc-notification'], 'optInChannels' => ['email']];
		return [
			'nothing-stored' => [[], [], [], []],
			'disabled-default' => [['enabled' => false, 'channels' => ['email', 'nc-notification']], [], [], []],
			'channels-map' => [['channels' => ['a' => 'email', 'b' => 'activity']], [], [], []],
			'channels-scalar' => [['channels' => 'email'], [], [], []],
			'opt-in-only' => [['optInChannels' => ['email']], [], [], []],
			'opt-in' => [$optIn, [['user', null, ['channels' => ['email', 'nc-notification', 'sms']]]], [], []],
			'user-global' => [[], [['user', null, ['enabled' => false]]], ['schema:s'], []],
			'user-scoped' => [[], [['user', null, ['enabled' => false]], ['user', 'register:r', ['enabled' => true, 'channels' => ['email']]]], ['schema:s', 'register:r'], []],
			'user-narrowest' => [[], [['user', 'schema:s', ['channels' => ['activity']]], ['user', 'register:r', ['channels' => ['email']]]], ['schema:s', 'register:r'], []],
			'user-channels-limited' => [['channels' => ['email']], [['user', null, ['channels' => ['email', 'activity']]]], [], []],
			'user-bad-json' => [[], [['user', null, 'not json']], [], []],
			'user-channels-scalar' => [[], [['user', null, ['channels' => 'email', 'enabled' => 0]]], [], []],
			'group-first-wins' => [[], [['group:g2', null, ['enabled' => false]], ['group:g1', null, ['enabled' => true, 'channels' => ['email']]]], [], ['g1', 'g2']],
			'group-scoped' => [[], [['group:g1', 'domain:d', ['enabled' => false]]], ['domain:d'], ['g1']],
			'group-then-user' => [['channels' => ['email', 'activity']], [['group:g1', null, ['channels' => ['activity']]], ['user', null, ['enabled' => false]]], [], ['g1']],
			'group-int-id' => [[], [['group:7', null, ['enabled' => false]]], [], [7]],
			'groups-from-manager' => [[], [['group:team', null, ['enabled' => false]]], [], null],
			'no-group-value' => [[], [['user', null, ['enabled' => false]]], ['schema:s'], ['g1', 'g2']],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case resolves as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testResolveEffectiveMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testResolveEffectiveMatchesGolden()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: array, 2: array, 3: ?array} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$schemaDefault, $stored, $scopes, $groupIds] = $case;

		$keys = new NotificationPreferenceService(
			config: $this->createMock(IConfig::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$store = [];
		foreach ($stored as [$party, $scope, $value]) {
			$raw = is_string($value) === true ? $value : (string)json_encode($value);
			if ($party === 'user') {
				$key = $keys->configKey(schemaSlug: 'meldingen', notificationKey: 'created');
				if ($scope !== null) {
					$key = $keys->scopedConfigKey(scope: $scope, schemaSlug: 'meldingen', notificationKey: 'created');
				}

				$store['user|'.$key] = $raw;
				continue;
			}

			$groupId = substr($party, 6);
			$store['app|'.$keys->groupConfigKey(groupId: $groupId, schemaSlug: 'meldingen', notificationKey: 'created', scope: $scope)] = $raw;
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, mixed $default = '') use ($store): string {
				$this->calls[] = ['getUserValue', $userId, $key];
				return $store['user|'.$key] ?? $default;
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, mixed $default = '') use ($store): string {
				$this->calls[] = ['getAppValue', $key];
				return $store['app|'.$key] ?? $default;
			}
		);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['team', 'other']);

		$service = new NotificationPreferenceService(
			config: $config,
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $groupManager,
			userManager: $userManager
		);

		$out = $service->resolveEffective($schemaDefault, 'alice', 'meldingen', 'created', $scopes, $groupIds);

		return json_decode((string)json_encode(['out' => $out, 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
