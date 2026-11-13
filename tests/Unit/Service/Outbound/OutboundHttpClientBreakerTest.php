<?php

/**
 * The outbound client's breaker: no request is sent while a dependency's
 * breaker is open, a 5xx, a 429 and a connect error count as failures, a 404
 * does not, and the dependency name a call site passes is used as the key and
 * never sent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Outbound\OutboundHttpClient;
use OCA\OpenRegister\Service\Outbound\ProxySettingsInterface;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Outbound\OutboundHttpClient
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class OutboundHttpClientBreakerTest extends TestCase {

	private const URI = 'https://api.example.test/v1/people/1';

	private int $now = 1_000_000;

	private CircuitBreaker $breaker;

	private IClient&MockObject $inner;

	private OutboundHttpClient $client;

	/**
	 * The options each request reached the transport with.
	 *
	 * @var list<array>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(
			cache: new FakeMemcache(),
			thresholds: new BreakerThresholds(),
			clock: $clock,
			logger: new NullLogger(),
			shared: true
		);

		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		$proxy = $this->createMock(ProxySettingsInterface::class);
		$proxy->method('requestOptions')->willReturn([]);

		$this->inner  = $this->createMock(IClient::class);
		$this->client = new OutboundHttpClient(inner: $this->inner, proxy: $proxy, breakers: $registry);
	}//end setUp()

	/**
	 * Every GET answers with this status, recording the options it got.
	 *
	 * @param int                   $status  The status.
	 * @param array<string, string> $headers Response headers.
	 *
	 * @return void
	 */
	private function answers(int $status, array $headers = []): void {
		$this->inner->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($status, $headers): IResponse {
				$this->sent[] = $options;
				$request      = new Request('GET', $uri);
				$response     = new Response($status, $headers);
				if ($status >= 500) {
					throw new ServerException('server error', $request, $response);
				}

				if ($status >= 400) {
					throw new ClientException('client error', $request, $response);
				}

				$ok = $this->createMock(IResponse::class);
				$ok->method('getStatusCode')->willReturn($status);
				return $ok;
			}
		);
	}//end answers()

	/**
	 * Make $n GET calls, swallowing transport errors.
	 *
	 * @param int   $n       How many.
	 * @param array $options Request options.
	 *
	 * @return void
	 */
	private function calls(int $n, array $options = []): void {
		for ($i = 0; $i < $n; $i++) {
			try {
				$this->client->get(self::URI, $options);
			} catch (ServerException | ClientException | ConnectException) {
				// Counted by the breaker; the caller's error is not under test.
			}
		}
	}//end calls()

	public function testAnOpenBreakerSendsNoRequestAndRefusesAtOnce(): void {
		$this->answers(status: 503);
		$this->calls(n: 10);
		$this->assertCount(10, $this->sent);

		try {
			$this->client->get(self::URI);
			$this->fail('a request went through an open breaker');
		} catch (DependencyUnavailableException $refused) {
			$this->assertSame('outbound', $refused->dependency());
			$this->assertSame(30, $refused->retryAfter());
		}

		$this->assertCount(10, $this->sent, 'no request may be sent while the breaker is open');
	}

	public function testANotFoundIsTheCallersProblemAndNeverOpensTheBreaker(): void {
		$this->answers(status: 404);
		$this->calls(n: 20);

		$this->assertCount(20, $this->sent);
		$this->assertSame('closed', $this->breaker->state(BreakerRegistry::outboundKey('api.example.test'))['state']);
	}

	public function testConnectErrorsCountAsFailures(): void {
		$this->inner->method('get')->willReturnCallback(
			function (string $uri, array $options): IResponse {
				$this->sent[] = $options;
				throw new ConnectException('refused', new Request('GET', $uri));
			}
		);
		$this->calls(n: 10);

		$this->expectException(DependencyUnavailableException::class);
		$this->client->get(self::URI);
	}

	public function testALongerRetryAfterOnA429BecomesTheCoolDown(): void {
		$this->answers(status: 429, headers: ['Retry-After' => '120']);
		$this->calls(n: 10);

		try {
			$this->client->get(self::URI);
			$this->fail('a request went through an open breaker');
		} catch (DependencyUnavailableException $refused) {
			$this->assertSame(120, $refused->retryAfter());
		}
	}

	public function testTheNamedDependencyIsTheKeyAndIsNotSent(): void {
		$this->answers(status: 503);
		$this->calls(n: 10, options: [OutboundHttpClient::DEPENDENCY_OPTION => 'BRP', 'timeout' => 5]);

		$this->assertSame(['timeout' => 5], $this->sent[0], 'the dependency name must not reach the transport');
		$this->assertSame('open', $this->breaker->state(BreakerRegistry::outboundKey('brp'))['state']);
		$this->assertSame('closed', $this->breaker->state(BreakerRegistry::outboundKey('api.example.test'))['state']);

		// Another dependency on the same host is not refused.
		$this->sent = [];
		$this->calls(n: 1);
		$this->assertCount(1, $this->sent);
	}

	public function testASuccessfulProbeClosesTheBreaker(): void {
		$status = 503;
		$this->inner->method('get')->willReturnCallback(
			function (string $uri, array $options) use (&$status): IResponse {
				$this->sent[] = $options;
				if ($status >= 500) {
					throw new ServerException('down', new Request('GET', $uri), new Response($status));
				}

				$ok = $this->createMock(IResponse::class);
				$ok->method('getStatusCode')->willReturn($status);
				return $ok;
			}
		);
		$this->calls(n: 10);

		$this->now += 31;
		$status     = 200;
		$this->client->get(self::URI);

		$this->assertSame('closed', $this->breaker->state(BreakerRegistry::outboundKey('api.example.test'))['state']);
	}

	public function testWithoutBreakersTheClientOnlyStripsTheDependencyName(): void {
		$proxy = $this->createMock(ProxySettingsInterface::class);
		$proxy->method('requestOptions')->willReturn([]);
		$response = $this->createMock(IResponse::class);
		$inner = $this->createMock(IClient::class);
		$inner->expects($this->once())->method('post')->with(self::URI, ['json' => [1]])->willReturn($response);

		$client = new OutboundHttpClient(inner: $inner, proxy: $proxy);
		$this->assertSame($response, $client->post(self::URI, ['json' => [1], OutboundHttpClient::DEPENDENCY_OPTION => 'brp']));
	}
}
