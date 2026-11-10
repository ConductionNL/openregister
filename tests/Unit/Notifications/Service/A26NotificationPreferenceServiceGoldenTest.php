<?php

/**
 * Golden test for the notification preference service and the built-in rule tables.
 *
 * Drives every public method of NotificationPreferenceService over an in-memory
 * config store and records each config read and write with its result: the key
 * a preference is stored under, the layer that wins, what a reset deletes and
 * what an unreadable value answers. It also pins the full rule tables of
 * SystemSchemaRules and TaskNotificationRules. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Notifications\Service\Notification\UserGroupServices;
use OCA\OpenRegister\Notifications\Service\Notification\SystemSchemaRules;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Notifications\Service\Notification\PreferenceAddress;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceService
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\SystemSchemaRules
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules
 */
class A26NotificationPreferenceServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/a26-notification-preference-service-golden.json';

	/** @var array<int, string> */
	private array $log = [];

	/** @var array<string, string> */
	private array $store = [];

	public function testOutcomesMatchGolden(): void {
		$actual = $this->staticCases() + $this->storeCases() + $this->effectiveCases() + $this->ruleTables();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testOutcomesMatchGolden()

	private function staticCases(): array {
		$specs = [
			'none'      => [],
			'declared'  => ['channels' => ['email']],
			'opt-in'    => ['channels' => ['email'], 'optInChannels' => ['web-push', 'email', 'fax', 7, 'web-push', 'activity']],
			'default'   => ['optInChannels' => ['nc-notification', 'email']],
			'scalar'    => ['channels' => 'email', 'optInChannels' => 'activity'],
		];
		$out = [];
		foreach ($specs as $name => $spec) {
			$out['optIn ' . $name] = NotificationPreferenceService::optInChannelsOf($spec);
			$out['available ' . $name] = NotificationPreferenceService::availableChannelsOf($spec);
		}

		$service = $this->service(groups: []);
		$long = str_repeat('s', 40);
		$out['keys'] = [
			$service->configKey('case', 'assigned'),
			$service->configKey($long, 'assigned-to-you'),
			$service->scopedKeyFor(new PreferenceAddress('case', 'assigned', 'register:1')),
			$service->scopedKeyFor(new PreferenceAddress($long, 'assigned', 'register:1')),
			$service->scopedKeyFor(new PreferenceAddress('case', 'assigned', 'domain:x'), 'staff'),
			$service->scopedKeyFor(new PreferenceAddress($long, 'assigned', 'domain:x'), 'staff'),
			$service->groupKeyFor('staff', new PreferenceAddress('case', 'assigned')),
			$service->groupKeyFor('staff', new PreferenceAddress('case', 'assigned', 'schema:2')),
		];
		return $out;
	}//end staticCases()

	private function storeCases(): array {
		$service = $this->service(groups: ['staff']);
		$out = [];
		$steps = [
			'override missing'       => fn (): mixed => $service->getOverride('alice', 'case', 'assigned'),
			'override set'           => fn (): mixed => $service->setOverride('alice', 'case', 'assigned', ['enabled' => 0, 'channels' => ['email', '', 3, 'push'], 'x' => 1]),
			'override read'          => fn (): mixed => $service->getOverride('alice', 'case', 'assigned'),
			'override set scoped'    => fn (): mixed => $service->setOverride('alice', 'case', 'assigned', ['channels' => 'email'], 'register:1'),
			'override read scoped'   => fn (): mixed => $service->getOverride('alice', 'case', 'assigned', 'register:1'),
			'override reset'         => fn (): mixed => $service->setOverride('alice', 'case', 'assigned', null),
			'override reset scoped'  => fn (): mixed => $service->setOverride('alice', 'case', 'assigned', null, 'register:1'),
			'override corrupt'       => function () use ($service): mixed {
				$this->store['user/bob/notification_pref/case/assigned'] = '"text"';
				return $service->getOverride('bob', 'case', 'assigned');
			},
			'group missing'          => fn (): mixed => $service->getGroupDefault('staff', 'case', 'assigned'),
			'group set'              => fn (): mixed => $service->setGroupDefault('staff', 'case', 'assigned', ['enabled' => false]),
			'group read'             => fn (): mixed => $service->getGroupDefault('staff', 'case', 'assigned'),
			'group set scoped'       => fn (): mixed => $service->setGroupDefault('staff', 'case', 'assigned', ['enabled' => true, 'channels' => ['email']], 'schema:2'),
			'group read scoped'      => fn (): mixed => $service->getGroupDefault('staff', 'case', 'assigned', 'schema:2'),
			'group reset'            => fn (): mixed => $service->setGroupDefault('staff', 'case', 'assigned', null),
			'group corrupt'          => function () use ($service): mixed {
				$this->store['app/notif_grp_pref/staff/global/case/assigned'] = '{bad';
				return $service->getGroupDefault('staff', 'case', 'assigned');
			},
			'groupsOf'               => fn (): mixed => $service->groupsOf('alice'),
			'groupsOf cached'        => fn (): mixed => $service->groupsOf('alice'),
			'groupsOf unknown'       => fn (): mixed => $service->groupsOf('nobody'),
			'groupsOf failing'       => fn (): mixed => $service->groupsOf('broken'),
			'groupsOf no managers'   => fn (): mixed => $this->service(groups: null)->groupsOf('alice'),
		];
		foreach ($steps as $name => $step) {
			$out['store ' . $name] = $this->capture(step: $step);
		}

		return $out;
	}//end storeCases()

	private function effectiveCases(): array {
		$out = [];
		$spec = ['enabled' => false, 'channels' => ['email'], 'optInChannels' => ['web-push']];
		$seed = [
			'app/notif_grp_pref/staff/global/case/assigned' => '{"enabled":true}',
			'app/notif_grp_pref/staff/register:1/case/assigned' => '{"enabled":false,"channels":["web-push","fax"]}',
			'app/notif_grp_pref/ops/global/case/assigned' => '{"enabled":false}',
			'user/alice/notif_pref_s/schema:2/case/assigned' => '{"enabled":true,"channels":["email"]}',
			'user/carol/notification_pref/case/assigned' => '{"enabled":true,"channels":[]}',
		];
		$cases = [
			'schema default'       => ['dave', [], [], []],
			'plain spec'           => ['dave', [], [], null],
			'group global'         => ['bob', [], ['staff'], null],
			'group scoped'         => ['bob', ['register:1'], ['ops', 'staff'], null],
			'user scoped wins'     => ['alice', ['register:1', 'schema:2'], ['staff'], null],
			'user global'          => ['carol', ['domain:x'], [], null],
			'explicit no groups'   => ['bob', [], null, []],
		];
		foreach ($cases as $name => [$user, $scopes, $groups, $groupIds]) {
			$this->store = $seed;
			$service = $this->service(groups: $groups ?? ['staff']);
			$schemaDefault = $spec;
			if ($name === 'plain spec') {
				$schemaDefault = [];
			}

			$out['effective ' . $name] = $this->capture(step: fn (): mixed => $service->resolveEffective($schemaDefault, $user, 'case', 'assigned', $scopes, $groupIds));
		}

		$this->store = $seed;
		$out['for user'] = $this->capture(step: fn (): mixed => $this->service(groups: ['staff'])->getEffectiveForUser('alice', ['register:1']));
		$out['for user unscoped'] = $this->capture(step: fn (): mixed => $this->service(groups: [])->getEffectiveForUser('carol'));
		$out['for user failing'] = $this->capture(step: fn (): mixed => $this->service(groups: [], schemas: null)->getEffectiveForUser('carol'));
		return $out;
	}//end effectiveCases()

	private function ruleTables(): array {
		$system = new SystemSchemaRules();
		$out = ['system slugs' => $system->getSlugs()];
		foreach ($system->getSlugs() as $slug) {
			$out['system rules ' . $slug] = $system->getRules($slug);
		}

		$out['system rules unknown'] = $system->getRules('nope');
		$task = new TaskNotificationRules();
		$out['task rules'] = $task->getRules();
		$out['task schema'] = $task->asSchemaArray();
		return $out;
	}//end ruleTables()

	private function capture(callable $step): array {
		$this->log = [];
		try {
			$result = ['result' => $step()];
		} catch (\Throwable $e) {
			$result = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
		}

		return $result + ['config' => $this->log];
	}//end capture()

	private function service(?array $groups, ?array $schemas = []): NotificationPreferenceService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->read(key: 'app/' . $key, default: $default));
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->write(key: 'app/' . $key, value: $value);
		});
		$config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
			$this->write(key: 'app/' . $key, value: null);
		});
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, mixed $default = ''): string => $this->read(key: 'user/' . $uid . '/' . $key, default: (string) $default));
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, string $value): void {
			$this->write(key: 'user/' . $uid . '/' . $key, value: $value);
		});
		$config->method('deleteUserValue')->willReturnCallback(function (string $uid, string $app, string $key): void {
			$this->write(key: 'user/' . $uid . '/' . $key, value: null);
		});

		$mapper = $this->createMock(SchemaMapperInterface::class);
		if ($schemas === null) {
			$mapper->method('findAll')->willThrowException(new RuntimeException('database down'));
		} else {
			$mapper->method('findAll')->willReturn($this->schemas());
		}

		if ($groups === null) {
			return new NotificationPreferenceService($config, $mapper, $this->createMock(LoggerInterface::class));
		}

		return new NotificationPreferenceService($config, $mapper, $this->createMock(LoggerInterface::class), new UserGroupServices($this->groupManager(groups: $groups), $this->userManager()));
	}//end service()

	private function read(string $key, string $default): string {
		$value = $this->store[$key] ?? $default;
		$this->log[] = 'read ' . $key . ' = ' . $value;
		return $value;
	}//end read()

	private function write(string $key, ?string $value): void {
		$this->log[] = 'write ' . $key . ' = ' . var_export($value, true);
		if ($value === null) {
			unset($this->store[$key]);
			return;
		}

		$this->store[$key] = $value;
	}//end write()

	private function groupManager(array $groups): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('getUserGroupIds')->willReturnCallback(
			function (IUser $user) use ($groups): array {
				$this->log[] = 'groups of ' . $user->getUID();
				if ($user->getUID() === 'broken') {
					throw new RuntimeException('ldap down');
				}

				return array_combine($groups, $groups);
			}
		);
		return $manager;
	}//end groupManager()

	private function userManager(): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$this->log[] = 'user ' . $uid;
				if ($uid === 'nobody') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
		return $manager;
	}//end userManager()

	/**
	 * Schemas covering the de-duplication: two versions of one slug, one app's copy, an unslugged one.
	 *
	 * @return array<int, Schema>
	 */
	private function schemas(): array {
		$make = static function (int $id, ?string $slug, ?string $version, ?string $app, mixed $notifications, ?string $title = null): Schema {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$schema->setVersion($version);
			$schema->setApplication($app);
			$schema->setTitle($title);
			$schema->setConfiguration(['x-openregister-notifications' => $notifications]);
			return $schema;
		};
		$spec = ['assigned' => ['channels' => ['email'], 'optInChannels' => ['web-push']], 'broken' => 'x', 9 => ['enabled' => false]];
		return [
			$make(1, 'case', '1.0.0', null, ['assigned' => ['enabled' => true]], 'Old case'),
			$make(2, 'case', '1.2.0', null, $spec, 'Case'),
			$make(3, 'case', '1.2.0', 'pipelinq', $spec),
			$make(4, 'case', '1.2.0', 'pipelinq', ['assigned' => []], 'Same version, higher id'),
			$make(5, null, null, null, ['done' => []]),
			$make(6, 'plain', null, null, 'not-a-map'),
		];
	}//end schemas()
}//end class
