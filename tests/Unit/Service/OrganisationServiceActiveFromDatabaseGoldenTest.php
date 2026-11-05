<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Records every config, session, mapper and log call of the private
 * fetchActiveOrganisationFromDatabase(), per scenario, and the organisation it returns,
 * against fixtures/organisation-active-from-database-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\OrganisationService
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class OrganisationServiceActiveFromDatabaseGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	protected function tearDown(): void {
		$this->setStatic('defaultOrgCache', null);
		$this->setStatic('defaultOrgCacheTs', null);
		$this->setStatic('userOrgsCache', []);
	}

	/**
	 * Set a static cache of OrganisationService.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	private function setStatic(string $name, mixed $value): void {
		(new ReflectionProperty(OrganisationService::class, $name))->setValue(null, $value);
	}

	/**
	 * Build an organisation.
	 *
	 * @param string      $uuid    UUID.
	 * @param int         $created Creation timestamp.
	 * @param array       $users   Member user ids.
	 * @param string|null $merged  UUID it was merged into.
	 *
	 * @return Organisation
	 */
	private static function org(string $uuid, int $created, array $users, ?string $merged = null): Organisation {
		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setName('name-' . $uuid);
		$org->setCreated((new DateTime())->setTimestamp($created));
		$org->setUsers($users);
		if ($merged !== null) {
			$org->setMergedInto($merged);
		}
		return $org;
	}

	/**
	 * Builds the service over recording doubles for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return OrganisationService
	 */
	private function service(array $s): OrganisationService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(function (...$args) use ($s) {
			$this->calls[] = ['config.get', $args];
			return $s['stored'];
		});
		$config->method('setUserValue')->willReturnCallback(function (...$args) {
			$this->calls[] = ['config.set', $args];
		});
		$config->method('deleteUserValue')->willReturnCallback(function (...$args) {
			$this->calls[] = ['config.delete', $args];
		});

		$session = $this->createMock(ISession::class);
		$session->method('remove')->willReturnCallback(function ($key) {
			$this->calls[] = ['session.remove', $key];
		});

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(function ($uuid) use ($s) {
			$this->calls[] = ['mapper.findByUuid', $uuid];
			if ($s['storedOrg'] === null) {
				throw new DoesNotExistException('gone');
			}
			return $s['storedOrg'];
		});
		$mapper->method('findByUuidFollowingMerge')->willReturnCallback(function ($uuid) use ($s) {
			$this->calls[] = ['mapper.followMerge', $uuid];
			if ($s['survivor'] === null) {
				throw new Exception('no survivor');
			}
			return $s['survivor'];
		});
		$mapper->method('update')->willReturnCallback(function (Organisation $org) use ($s) {
			$this->calls[] = ['mapper.update', $org->getUuid(), $org->getUsers()];
			if ($s['updateFails'] === true) {
				throw new Exception('update failed');
			}
			return $org;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line'], $context['cacheAge']);
				$this->calls[] = ['log.' . $level, $message, $context];
			});
		}

		$this->setStatic('defaultOrgCache', self::org(...$s['default']));
		$this->setStatic('defaultOrgCacheTs', time());

		return new OrganisationService(
			organisationMapper: $mapper,
			userSession: $this->createMock(IUserSession::class),
			session: $session,
			config: $config,
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			logger: $logger
		);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = [
			'stored' => '',
			'storedOrg' => null,
			'survivor' => null,
			'preloaded' => [],
			'default' => ['default-uuid', 5, ['bob']],
			'updateFails' => false,
		];
		$two = [self::org('b-uuid', 20, ['alice']), self::org('a-uuid', 10, ['alice'])];
		return [
			'nothing stored, oldest of preloaded' => [['preloaded' => $two] + $base],
			'stored and member' => [['stored' => 'a-uuid', 'storedOrg' => self::org('a-uuid', 10, ['alice'])] + $base],
			'stored, no longer member, default joins' => [['stored' => 'a-uuid', 'storedOrg' => self::org('a-uuid', 10, ['bob'])] + $base],
			'stored but deleted, oldest' => [['stored' => 'gone-uuid', 'preloaded' => $two] + $base],
			'stored merged, member of survivor' => [[
				'stored' => 'a-uuid',
				'storedOrg' => self::org('a-uuid', 10, ['alice'], 's-uuid'),
				'survivor' => self::org('s-uuid', 1, ['alice']),
			] + $base],
			'stored merged, survivor unresolvable, member' => [[
				'stored' => 'a-uuid',
				'storedOrg' => self::org('a-uuid', 10, ['alice'], 's-uuid'),
			] + $base],
			'stored merged, not member of survivor' => [[
				'stored' => 'a-uuid',
				'storedOrg' => self::org('a-uuid', 10, ['alice'], 's-uuid'),
				'survivor' => self::org('s-uuid', 1, ['carol']),
				'preloaded' => $two,
			] + $base],
			'oldest preloaded is merged' => [[
				'preloaded' => [self::org('b-uuid', 20, ['alice']), self::org('a-uuid', 10, ['alice'], 's-uuid')],
				'survivor' => self::org('s-uuid', 1, ['alice']),
			] + $base],
			'default already has user' => [['default' => ['default-uuid', 5, ['alice']]] + $base],
			'default update fails' => [['updateFails' => true] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testFetchActiveOrganisationFromDatabaseGolden(array $s): void {
		$service = $this->service($s);
		$method = new ReflectionMethod(OrganisationService::class, 'fetchActiveOrganisationFromDatabase');
		$result = $method->invoke($service, 'alice', $s['preloaded']);
		$this->calls[] = ['result', $result?->getUuid(), $result?->getUsers()];

		$file = __DIR__ . '/fixtures/organisation-active-from-database-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
