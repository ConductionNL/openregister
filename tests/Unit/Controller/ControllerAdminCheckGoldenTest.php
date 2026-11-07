<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Golden test for the admin check of the controllers that answer it from
 * their session bundle: Endpoints, Registers, Configuration, Sources,
 * Organisation and FileText.
 *
 * Every case records each session and group-manager call with the user it
 * ran for, and the answer, for an administrator, a non-administrator and no
 * session user, and compares the recording with
 * fixtures/controller-admin-check-golden.json. Only `alice` is an
 * administrator. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\EndpointsController
 * @covers \OCA\OpenRegister\Controller\RegistersController
 * @covers \OCA\OpenRegister\Controller\ConfigurationController
 * @covers \OCA\OpenRegister\Controller\SourcesController
 * @covers \OCA\OpenRegister\Controller\OrganisationController
 * @covers \OCA\OpenRegister\Files\Controller\FileTextController
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 */
class ControllerAdminCheckGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/controller-admin-check-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function cases(): array {
		$cases = [];
		$controllers = [
			'Endpoints'     => 'OCA\\OpenRegister\\Controller\\EndpointsController',
			'Registers'     => 'OCA\\OpenRegister\\Controller\\RegistersController',
			'Configuration' => 'OCA\\OpenRegister\\Controller\\ConfigurationController',
			'Sources'       => 'OCA\\OpenRegister\\Controller\\SourcesController',
			'Organisation'  => 'OCA\\OpenRegister\\Controller\\OrganisationController',
			'FileText'      => 'OCA\\OpenRegister\\Files\\Controller\\FileTextController',
		];
		foreach ($controllers as $name => $class) {
			foreach (['admin' => 'alice', 'non-admin' => 'bob', 'no user' => null] as $who => $uid) {
				$cases[$name . ': ' . $who] = [$class, $uid];
			}
		}

		return $cases;
	}

	#[DataProvider('cases')]
	public function testAdminCheckMatchesTheRecording(string $class, ?string $uid): void {
		$this->calls = [];
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(function () use ($user) {
			$this->calls[] = ['userSession.getUser' => $user?->getUID()];
			return $user;
		});
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(function (string $id) {
			$this->calls[] = ['groupManager.isAdmin' => $id];
			return $id === 'alice';
		});
		$groupManager->method('getUserGroupIds')->willReturnCallback(function (IUser $u) {
			$this->calls[] = ['groupManager.getUserGroupIds' => $u->getUID()];
			return $u->getUID() === 'alice' ? ['admin'] : ['staff'];
		});

		$reflection = new ReflectionClass($class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$values = [
			'userSession' => $userSession,
			'groupManager' => $groupManager,
			'session' => new ControllerSession($userSession, $groupManager, new NullLogger()),
		];
		foreach ($values as $property => $value) {
			if ($reflection->hasProperty($property) === true) {
				$reflection->getProperty($property)->setValue($controller, $value);
			}
		}

		$answer = $reflection->getMethod('isCurrentUserAdmin')->invoke($controller);
		$this->compare(key: $this->dataName(), actual: ['calls' => $this->calls, 'admin' => $answer]);
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
