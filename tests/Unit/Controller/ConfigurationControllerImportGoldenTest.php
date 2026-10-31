<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the configuration import pipeline behind importFromGitHub()
 * and importFromUrl().
 *
 * Every case records each collaborator call (the admin check with the acting
 * user, the GitHub client, the configuration mapper and service, the logger)
 * and the response, and compares the recording with
 * fixtures/configuration-import-golden.json. Only the session user `alice` is
 * an administrator; a caller-supplied userId never counts. Dates are replaced
 * by a marker. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ConfigurationController
 * @uses \OCA\OpenRegister\Db\Configuration
 */
class ConfigurationControllerImportGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/configuration-import-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$github = ['owner' => 'acme', 'repo' => 'conf', 'path' => 'a/b.json', 'branch' => 'dev'];
		$full = [
			'info' => ['version' => '2.1.0', 'title' => 'Info title', 'description' => 'Info description'],
			'x-openregister' => ['app' => 'myapp', 'type' => 'app', 'version' => '9', 'title' => 'X', 'description' => 'Y'],
			'components' => ['registers' => [['slug' => 'ignored']]],
		];
		return [
			'no session' => [['user' => null, 'params' => $github]],
			'non-admin session' => [['user' => 'bob', 'params' => $github]],
			'non-admin session with a forged userId' => [['user' => 'bob', 'params' => $github + ['userId' => 'alice', 'requestedBy' => 'alice']]],
			'missing github parameters' => [['user' => 'alice', 'params' => ['owner' => 'acme']]],
			'github client throws without a code' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['boom', 0]]],
			'github client throws a 404' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['not found', 404]]],
			'github client throws a 302' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['moved', 302]]],
			'github client throws a 400' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['bad', 400]]],
			'github client throws a 599' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['edge', 599]]],
			'app id keeps underscores and dashes' => [['user' => 'alice', 'params' => $github, 'config' => [
				'components' => ['registers' => [['slug' => 'My_Reg-2 x']]],
			]]],
			'github client throws a 600' => [['user' => 'alice', 'params' => $github, 'fetchThrows' => ['odd', 600]]],
			'configuration exists' => [['user' => 'alice', 'params' => $github, 'config' => $full, 'existing' => 1]],
			'full metadata' => [['user' => 'alice', 'params' => $github + ['syncEnabled' => false, 'syncInterval' => '12'], 'config' => $full]],
			'sync enabled as a string' => [['user' => 'alice', 'params' => $github + ['syncEnabled' => 'true'], 'config' => $full]],
			'app id from the first register slug' => [['user' => 'alice', 'params' => ['owner' => 'acme', 'repo' => 'conf', 'path' => 'p.json'], 'config' => [
				'x-openregister' => ['version' => '3.0', 'title' => 'XT'],
				'components' => ['registers' => [['slug' => 'Woo Register!'], ['slug' => 'second']]],
			]]],
			'app id from the first register key' => [['user' => 'alice', 'params' => $github, 'config' => [
				'components' => ['registers' => ['dcat-reg' => ['title' => 'no slug']]],
			]]],
			'app id falls back when registers are a list without slugs' => [['user' => 'alice', 'params' => $github, 'config' => [
				'components' => ['registers' => ['not-an-array']],
			]]],
			'app id falls back without registers' => [['user' => 'alice', 'params' => $github, 'config' => ['info' => ['title' => 'Only title']]]],
			'import throws' => [['user' => 'alice', 'params' => $github, 'config' => $full, 'importThrows' => 'import failed']],
			'url import without a url' => [['user' => 'alice', 'params' => [], 'action' => 'importFromUrl']],
			'url import with an invalid url' => [['user' => 'alice', 'params' => ['url' => 'not a url'], 'action' => 'importFromUrl']],
		];
	}

	#[DataProvider('cases')]
	public function testImportMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['request.getParams' => []];
			return $case['params'];
		});

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(function (string $uid) {
			$this->calls[] = ['groupManager.isAdmin' => $uid];
			return $uid === 'alice';
		});

		$controller = new ConfigurationController(
			'openregister',
			$request,
			$this->mapper($case),
			$this->service($case),
			$this->createMock(NotificationService::class),
			$this->github($case),
			$this->createMock(GitLabHandler::class),
			$this->createMock(IAppManager::class),
			$this->logger(),
			$userSession,
			$groupManager
		);

		$action = $case['action'] ?? 'importFromGitHub';
		$response = $controller->$action();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->name() . ' ' . $this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function github(array $case): GitHubClientInterface {
		$github = $this->createMock(GitHubClientInterface::class);
		$github->method('getFileContent')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['github.getFileContent' => $args];
			if (isset($case['fetchThrows']) === true) {
				throw new Exception($case['fetchThrows'][0], $case['fetchThrows'][1]);
			}

			return $case['config'] ?? [];
		});
		return $github;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function mapper(array $case): ConfigurationMapper {
		$mapper = $this->createMock(ConfigurationMapper::class);
		$mapper->method('findByApp')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['mapper.findByApp' => $args];
			if (isset($case['existing']) === false) {
				return [];
			}

			$existing = new Configuration();
			$existing->setId($case['existing']);
			return [$existing];
		});
		$mapper->method('insert')->willReturnCallback(function (Configuration $configuration) {
			$this->calls[] = ['mapper.insert' => $this->snapshot($configuration)];
			$configuration->setId(42);
			return $configuration;
		});
		$mapper->method('update')->willReturnCallback(function (Configuration $configuration) {
			$this->calls[] = ['mapper.update' => $this->snapshot($configuration)];
			return $configuration;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): ConfigurationService {
		$service = $this->createMock(ConfigurationService::class);
		$service->method('importFromJson')->willReturnCallback(function (...$args) use ($case) {
			$args[1] = $this->snapshot($args[1]);
			$this->calls[] = ['service.importFromJson' => $args];
			if (isset($case['importThrows']) === true) {
				throw new Exception($case['importThrows']);
			}

			return ['registers' => [1, 2], 'schemas' => [3], 'objects' => []];
		});
		return $service;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['file']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		return $logger;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot(Configuration $configuration): array {
		$fields = $configuration->jsonSerialize();
		foreach (['lastChecked', 'lastSyncDate', 'created', 'updated'] as $date) {
			if ($fields[$date] !== null) {
				$fields[$date] = '(date)';
			}
		}

		$fields['(updated fields)'] = array_keys($configuration->getUpdatedFields());
		sort($fields['(updated fields)']);
		return $fields;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
