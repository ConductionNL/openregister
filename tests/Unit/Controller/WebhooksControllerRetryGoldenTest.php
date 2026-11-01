<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for WebhooksController::retry().
 *
 * Every case records each collaborator call (the admin check with the acting
 * user, both mappers, the delivery, the logger) and the response, and compares
 * the recording with fixtures/webhooks-retry-golden.json. Only the session
 * user `alice` is an administrator; a caller-supplied userId never counts.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\WebhooksController
 * @uses \OCA\OpenRegister\Db\Webhook
 * @uses \OCA\OpenRegister\Db\WebhookLog
 */
class WebhooksControllerRetryGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhooks-retry-golden.json';

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
		$failed = ['success' => false, 'requestBody' => '{"event":"x","data":{"id":5}}', 'attempt' => 2];
		return [
			'no session' => [['user' => null]],
			'non-admin' => [['user' => 'bob']],
			'non-admin with a forged userId' => [['user' => 'bob', 'forged' => 'alice']],
			'log not found' => [['log' => null]],
			'successful delivery cannot be retried' => [['log' => ['success' => true, 'requestBody' => '{"a":1}']]],
			'request body with data' => [['log' => $failed, 'delivered' => true]],
			'request body without data' => [['log' => ['requestBody' => '{"id":7}'], 'delivered' => true]],
			'request body not json falls to empty' => [['log' => ['requestBody' => 'not json', 'payload' => '{"id":8}']]],
			'request body empty json' => [['log' => ['requestBody' => '{}']]],
			'stored payload' => [['log' => ['payload' => '{"data":{"id":9},"meta":1}'], 'delivered' => true]],
			'no payload anywhere' => [['log' => []]],
			'webhook gone' => [['log' => $failed, 'webhookMissing' => true]],
			'delivery fails without later logs' => [['log' => $failed, 'delivered' => false, 'latest' => []]],
			'delivery fails with an error message only' => [['log' => $failed, 'delivered' => false, 'latest' => [['errorMessage' => 'timeout']]]],
			'delivery fails with a status code' => [['log' => $failed, 'delivered' => false, 'latest' => [['errorMessage' => 'bad', 'statusCode' => 502, 'responseBody' => 'gateway']]]],
			'delivery fails with a status code and no message' => [['log' => $failed, 'delivered' => false, 'latest' => [['statusCode' => 410]]]],
			'delivery throws' => [['log' => $failed, 'deliveryThrows' => 'socket']],
		];
	}

	#[DataProvider('cases')]
	public function testRetryMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($case) {
			$this->calls[] = ['request.getParam' => $key];
			return $key === 'userId' ? ($case['forged'] ?? $default) : $default;
		});

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		$uid = array_key_exists('user', $case) === true ? $case['user'] : 'alice';
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(function (string $id) {
			$this->calls[] = ['groupManager.isAdmin' => $id];
			return $id === 'alice';
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (...$args) {
			unset($args[1]['line'], $args[1]['file'], $args[1]['trace']);
			$this->calls[] = ['logger.error' => $args];
		});

		$controller = new WebhooksController(
			'openregister',
			$request,
			$this->webhookMapper($case),
			$this->logMapper($case),
			$this->service($case),
			$logger,
			$userSession,
			$groupManager
		);

		$response = $controller->retry(11);
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function log(array $fields): WebhookLog {
		$log = new WebhookLog();
		$log->setWebhook(3);
		$log->setEventClass('OCA\OpenRegister\Event\ObjectCreatedEvent');
		$log->setSuccess($fields['success'] ?? false);
		$log->setAttempt($fields['attempt'] ?? 1);
		foreach (['requestBody', 'payload', 'errorMessage', 'statusCode', 'responseBody'] as $field) {
			if (array_key_exists($field, $fields) === true) {
				$log->{'set' . ucfirst($field)}($fields[$field]);
			}
		}

		return $log;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function logMapper(array $case): WebhookLogMapper {
		$mapper = $this->createMock(WebhookLogMapper::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['logMapper.find' => $args];
			if (array_key_exists('log', $case) === true && $case['log'] === null) {
				throw new DoesNotExistException('no log');
			}

			return $this->log($case['log'] ?? []);
		});
		$mapper->method('findByWebhook')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['logMapper.findByWebhook' => $args];
			return array_map(fn (array $fields) => $this->log($fields), $case['latest'] ?? []);
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function webhookMapper(array $case): WebhookMapper {
		$mapper = $this->createMock(WebhookMapper::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['webhookMapper.find' => $args];
			if (($case['webhookMissing'] ?? false) === true) {
				throw new Exception('webhook gone');
			}

			$webhook = new Webhook();
			$webhook->setId(3);
			$webhook->setName('Hook');
			return $webhook;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): WebhookService {
		$service = $this->createMock(WebhookService::class);
		$service->method('deliverWebhook')->willReturnCallback(function (...$args) use ($case) {
			$args[0] = 'webhook#' . $args[0]->getId();
			$this->calls[] = ['service.deliverWebhook' => $args];
			if (isset($case['deliveryThrows']) === true) {
				throw new Exception($case['deliveryThrows']);
			}

			return $case['delivered'] ?? false;
		});
		return $service;
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
