<?php

/**
 * Delivery tests for WebPushService: payload encoding guard and failed-push reporting against an unreachable endpoint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use Minishlink\WebPush\VAPID;
use OCA\OpenRegister\Notifications\Db\PushSubscription;
use OCA\OpenRegister\Notifications\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Notifications\Service\WebPush\WebPushService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\WebPush\WebPushService
 * @uses   \OCA\OpenRegister\Notifications\Db\PushSubscription
 */
final class WebPushServiceDeliveryTest extends TestCase {

	/** @var PushSubscriptionMapper&MockObject */
	private PushSubscriptionMapper $mapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private WebPushService $service;

	/** @var resource|null Loopback push service (php -S) answering with the status named in the path. */
	private static $server = null;

	private static int $port = 0;

	private static string $router = '';

	public static function setUpBeforeClass(): void {
		self::$router = sys_get_temp_dir() . '/webpush-router-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents(self::$router, '<?php http_response_code((int)trim((string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/")); echo "status";');
		$sock = stream_socket_server('tcp://127.0.0.1:0');
		self::$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
		fclose($sock);
		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$router],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		for ($i = 0; $i < 100; $i++) {
			$probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.05);
			if ($probe !== false) {
				fclose($probe);
				return;
			}
			usleep(20000);
		}
	}

	public static function tearDownAfterClass(): void {
		if (is_resource(self::$server) === true) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}
		@unlink(self::$router);
	}

	private function endpoint(int $status): string {
		return 'http://127.0.0.1:' . self::$port . '/' . $status;
	}

	protected function setUp(): void {
		parent::setUp();
		$keys = VAPID::createVapidKeys();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				WebPushService::VAPID_PUBLIC_KEY => $keys['publicKey'],
				WebPushService::VAPID_PRIVATE_KEY => $keys['privateKey'],
				default => $default,
			}
		);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://cloud.example');
		$this->mapper = $this->createMock(PushSubscriptionMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new WebPushService($this->mapper, $config, $url, $this->logger);
	}

	private function subscription(string $endpoint): PushSubscription {
		$keys = VAPID::createVapidKeys();
		$sub = new PushSubscription();
		$sub->setEndpoint($endpoint);
		// A valid P-256 public point (uncompressed, base64url) and a 16-byte auth secret.
		$sub->setP256dh($keys['publicKey']);
		$sub->setAuth(rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));
		return $sub;
	}

	public function testAnUnencodablePayloadIsRefusedBeforeAnythingIsQueued(): void {
		$this->mapper->method('findByUser')->willReturn([$this->subscription('http://127.0.0.1:9/push')]);
		$this->mapper->expects($this->never())->method('deleteByEndpoint');
		$this->logger->expects($this->once())->method('warning')->with('[WebPushService] Failed to JSON-encode push payload.');

		$this->assertSame(0, $this->service->deliver('alice', ['title' => "\xB1\x31"]));
	}

	public function testAnUnreachableEndpointIsReportedAsFailedAndKept(): void {
		$this->mapper->method('findByUser')->with('alice')->willReturn([$this->subscription('http://127.0.0.1:9/push')]);
		$this->mapper->expects($this->never())->method('deleteByEndpoint');
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $msg) use (&$warnings): void {
				$warnings[] = $msg;
			}
		);

		$this->assertSame(0, $this->service->deliver('alice', ['title' => 'Hi']));
		$this->assertCount(1, $warnings);
		$this->assertStringStartsWith('[WebPushService] Push delivery failed (status 0): ', $warnings[0]);
	}

	public function testSuccessfulPushesAreCounted(): void {
		$this->mapper->method('findByUser')->willReturn([$this->subscription($this->endpoint(201)), $this->subscription($this->endpoint(201))]);
		$this->mapper->expects($this->never())->method('deleteByEndpoint');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(2, $this->service->deliver('alice', ['title' => 'Hi']));
	}

	public function testGoneAndMissingEndpointsArePrunedAndLogged(): void {
		$this->mapper->method('findByUser')->willReturn([$this->subscription($this->endpoint(410)), $this->subscription($this->endpoint(404))]);
		$pruned = [];
		$this->mapper->method('deleteByEndpoint')->willReturnCallback(
			function (string $endpoint) use (&$pruned): int {
				$pruned[] = $endpoint;
				return 1;
			}
		);
		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $msg) use (&$infos): void {
				$infos[] = $msg;
			}
		);

		$this->assertSame(0, $this->service->deliver('alice', ['title' => 'Hi']));
		sort($pruned);
		$this->assertSame([$this->endpoint(404), $this->endpoint(410)], $pruned);
		sort($infos);
		$this->assertSame(
			[
				'[WebPushService] Pruned 1 gone subscription(s) for endpoint (status 404).',
				'[WebPushService] Pruned 1 gone subscription(s) for endpoint (status 410).',
			],
			$infos
		);
	}

	public function testAServerErrorIsLoggedWithItsStatusAndTheSubscriptionKept(): void {
		$this->mapper->method('findByUser')->willReturn([$this->subscription($this->endpoint(500))]);
		$this->mapper->expects($this->never())->method('deleteByEndpoint');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringStartsWith('[WebPushService] Push delivery failed (status 500): '));

		$this->assertSame(0, $this->service->deliver('alice', ['title' => 'Hi']));
	}
}
