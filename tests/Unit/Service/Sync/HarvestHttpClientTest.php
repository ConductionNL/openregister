<?php

/**
 * HarvestHttpClient: guarded, bounded HTTP for harvest fetchers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Http\OutboundUrlGuard;
use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use OCA\OpenRegister\Service\Sync\HarvestHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestHttpClient
 * @uses \OCA\OpenRegister\Service\Http\OutboundUrlGuard
 * @uses \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class HarvestHttpClientTest extends TestCase {

	private MockHandler $handler;

	/**
	 * Seconds waited between attempts.
	 *
	 * @var list<int>
	 */
	private array $waits = [];

	private function client(): HarvestHttpClient {
		$this->handler = new MockHandler();
		$addresses = $this->getMockBuilder(OutboundAddressGuard::class)->onlyMethods(['addressesOf'])->getMock();
		$addresses->method('addressesOf')->willReturnCallback(
			static function (string $host): array {
				if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
					return [trim($host, '[]')];
				}

				return ['93.184.216.34'];
			}
		);

		return new HarvestHttpClient(
			new Client(['handler' => HandlerStack::create($this->handler)]),
			new OutboundUrlGuard($addresses),
			function (int $seconds): void {
				$this->waits[] = $seconds;
			}
		);
	}

	public function testARedirectToTheMetadataAddressIsRefusedBeforeItIsFollowed(): void {
		$http = $this->client();
		$this->handler->append(
			new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
			new Response(200, [], 'secret')
		);

		try {
			$http->get('https://feed.example/catalog');
			$this->fail('The redirect was followed');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('link-local/metadata', $e->getMessage());
		}

		// The redirect target was never requested.
		$this->assertCount(1, $this->handler);
	}

	public function testTheFirstUrlIsGuardedToo(): void {
		$http = $this->client();
		$this->handler->append(new Response(200));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('blocked IP range (loopback)');
		$http->get('http://127.0.0.1/admin');
	}

	public function testAServerErrorIsRetriedWithBackoff(): void {
		$http = $this->client();
		$this->handler->append(new Response(503), new Response(502), new Response(200, [], 'ok'));

		$response = $http->get('https://feed.example/');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame([2, 4], $this->waits);
	}

	public function testThreeFailedAttemptsReturnTheLastResponse(): void {
		$http = $this->client();
		$this->handler->append(new Response(500), new Response(500), new Response(500), new Response(200));

		$response = $http->get('https://feed.example/');

		$this->assertSame(500, $response->getStatusCode());
		$this->assertCount(1, $this->handler);
	}

	public function testRetryAfterIsHonoured(): void {
		$http = $this->client();
		$this->handler->append(new Response(429, ['Retry-After' => '7']), new Response(200));

		$this->assertSame(200, $http->get('https://feed.example/')->getStatusCode());
		$this->assertSame([7], $this->waits);
	}

	public function testAConnectFailureIsRetriedThenReported(): void {
		$http = $this->client();
		$request = new Request('GET', 'https://feed.example/');
		$this->handler->append(
			new ConnectException('timed out', $request),
			new ConnectException('timed out', $request),
			new ConnectException('timed out', $request)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('failed after 3 attempts');
		$http->get('https://feed.example/');
	}

	public function testAClientErrorIsNotRetried(): void {
		$http = $this->client();
		$this->handler->append(new Response(404), new Response(200));

		$this->assertSame(404, $http->get('https://feed.example/')->getStatusCode());
		$this->assertSame([], $this->waits);
	}

	public function testABodyOverTheCapIsRefused(): void {
		$http = $this->client();
		$this->handler->append(new Response(200, ['Content-Length' => (string)(HarvestHttpClient::MAX_BODY_BYTES + 1)]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('exceeds');
		$http->get('https://feed.example/');
	}

	public function testTheTimeoutsAreSet(): void {
		$http = $this->client();
		$this->handler->append(new Response(200));

		$http->get('https://feed.example/', ['Accept' => 'application/json']);
		$options = $this->handler->getLastOptions();

		$this->assertSame(HarvestHttpClient::CONNECT_TIMEOUT, $options['connect_timeout']);
		$this->assertSame(HarvestHttpClient::READ_TIMEOUT, $options['timeout']);
		$this->assertSame('application/json', $this->handler->getLastRequest()->getHeaderLine('Accept'));
	}
}
