<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\NamesController;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for NamesController::index().
 *
 * The signed-in user is "alice"; every request also carries a forged userId,
 * requestedBy and owner ("mallory") through getParam() and getParams(). Each
 * case records every request, session, name cache and logger call and the
 * response, and compares the recording with fixtures/names-index-golden.json.
 * Timings, log line numbers, file paths and traces are left out. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\NamesController
 */
class NamesControllerIndexGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/names-index-golden.json';

	private const FORGED = ['userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'];

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
		return [
			'not signed in' => [['anonymous' => true, 'params' => ['ids' => 'a,b']]],
			'all names' => [['params' => []]],
			'comma separated ids' => [['params' => ['ids' => ' a , b,c ']]],
			'json array string' => [['params' => ['ids' => '["x","y"]']]],
			'invalid json array string' => [['params' => ['ids' => '[x']]],
			'json array string of a number' => [['params' => ['ids' => '[5]']]],
			'array of ids' => [['params' => ['ids' => ['u1', 'u2', 'u3']]]],
			'single number' => [['params' => ['ids' => 42]]],
			'empty string' => [['params' => ['ids' => '']]],
			'bulk lookup fails' => [['params' => ['ids' => 'a'], 'throw' => 'bulk']],
			'all names lookup fails' => [['params' => [], 'throw' => 'all']],
		];
	}

	#[DataProvider('cases')]
	public function testIndexMatchesTheRecording(array $case): void {
		$this->calls = [];
		$params = self::FORGED + $case['params'];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($params) {
			$this->calls[] = ['request.getParam' => [$key, $default]];
			return $params[$key] ?? $default;
		});
		$request->method('getParams')->willReturnCallback(function () use ($params) {
			$this->calls[] = ['request.getParams' => []];
			return $params;
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['userSession.getUser' => []];
			if (($case['anonymous'] ?? false) === true) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			return $user;
		});

		$cache = $this->createMock(ObjectNameCacheInterface::class);
		$cache->method('getMultipleObjectNames')->willReturnCallback(function (array $ids) use ($case) {
			$this->calls[] = ['cache.getMultipleObjectNames' => [$ids]];
			if (($case['throw'] ?? null) === 'bulk') {
				throw new Exception('bulk failed');
			}

			$names = [];
			foreach ($ids as $id) {
				$names[(string)$id] = 'Name of ' . $id;
			}

			return $names;
		});
		$cache->method('getAllObjectNames')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['cache.getAllObjectNames' => $args];
			if (($case['throw'] ?? null) === 'all') {
				throw new Exception('all failed');
			}

			return ['o1' => 'One', 'o2' => 'Two'];
		});
		$cache->method('getStats')->willReturnCallback(function () {
			$this->calls[] = ['cache.getStats' => []];
			return ['hits' => 3];
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['file'], $args[1]['trace']);
				if (isset($args[1]['execution_time']) === true) {
					$args[1]['execution_time'] = preg_match('/^\d+(\.\d+)?ms$/', $args[1]['execution_time']) === 1 ? '(n)ms' : '(other)';
				}

				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		$controller = new NamesController('openregister', $request, $cache, $logger, $session);
		$response = $controller->index();
		$data = $response->getData();
		if (isset($data['execution_time']) === true) {
			$data['execution_time'] = preg_match('/^\d+(\.\d+)?ms$/', $data['execution_time']) === 1 ? '(n)ms' : '(other)';
		}

		$this->compare($this->dataName(), ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $data]);
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
