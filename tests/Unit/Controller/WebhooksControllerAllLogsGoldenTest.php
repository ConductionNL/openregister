<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCP\AppFramework\Controller;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for WebhooksController::allLogs().
 *
 * Each case records the request reads, every log mapper call with its
 * arguments, the log lines and the response, and compares the recording
 * with fixtures/webhooks-all-logs-golden.json. The controller is built
 * without its constructor so the test does not depend on its shape. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\WebhooksController
 * @uses \OCA\OpenRegister\Db\WebhookLog
 */
class WebhooksControllerAllLogsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhooks-all-logs-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [
			'not admin' => [['admin' => false, 'params' => []]],
			'no session' => [['admin' => null, 'params' => []]],
			'defaults' => [['params' => []]],
			'paging' => [['params' => ['limit' => '2', 'offset' => '1']]],
			'mapper fails' => [['params' => [], 'throw' => true]],
		];
		foreach (['5', '0', '', 7, 0] as $webhook) {
			$cases['webhook ' . var_export($webhook, true)] = [['params' => ['webhook_id' => $webhook]]];
		}

		foreach (['true', '1', 'false', '0', '', 'yes', true] as $success) {
			$cases['success ' . var_export($success, true)] = [['params' => ['success' => $success]]];
			$cases['webhook 5 success ' . var_export($success, true)] = [['params' => ['webhook_id' => '5', 'success' => $success, 'limit' => '3']]];
		}

		return $cases;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testAllLogs(array $case): void {
		$response = $this->controller(case: $case)->allLogs();
		$data = $response->getData();
		if (isset($data['results']) === true) {
			$data['results'] = array_map(fn (WebhookLog $log): array => [$log->getId(), $log->getSuccess()], $data['results']);
		}

		$this->compare(
			key: (string)$this->dataName(),
			actual: ['status' => $response->getStatus(), 'data' => $data, 'calls' => $this->calls]
		);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function controller(array $case): WebhooksController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($case) {
				$this->calls[] = ['getParam' => $key];
				return $case['params'][$key] ?? $default;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['log' => $message];
			}
		);

		$controller = (new \ReflectionClass(WebhooksController::class))->newInstanceWithoutConstructor();
		$set = function (string $name, $value) use ($controller): void {
			\Closure::bind(fn () => $this->$name = $value, $controller, WebhooksController::class)();
		};
		\Closure::bind(fn () => $this->request = $request, $controller, Controller::class)();
		$set('webhookLogMapper', $this->mapper(case: $case));
		$set('logger', $logger);
		$set('userSession', $this->session(case: $case));
		$set('groupManager', $this->groupManager(case: $case));
		return $controller;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function session(array $case): ?IUserSession {
		if (array_key_exists('admin', $case) === true && $case['admin'] === null) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function groupManager(array $case): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($case['admin'] ?? true);
		return $groups;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function mapper(array $case): WebhookLogMapper {
		$rows = [];
		foreach ([[1, true], [2, false], [3, true], [4, false], [5, true]] as [$id, $ok]) {
			$log = new WebhookLog();
			$log->setId($id);
			$log->setSuccess($ok);
			$rows[] = $log;
		}

		$mapper = $this->createMock(WebhookLogMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null) use ($case, $rows): array {
				$this->calls[] = ['findAll' => [$limit, $offset]];
				if (($case['throw'] ?? false) === true) {
					throw new RuntimeException('db down');
				}

				return array_slice($rows, (int)$offset, $limit);
			}
		);
		$mapper->method('findByWebhook')->willReturnCallback(
			function (int $webhookId, ?ListQuery $page = null) use ($rows): array {
				$limit  = $page?->limitOrNull();
				$offset = $page?->offset;
				$this->calls[] = ['findByWebhook' => [$webhookId, $limit, $offset]];
				return array_slice(array_slice($rows, 1), (int)$offset, $limit);
			}
		);
		return $mapper;
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
