<?php

/**
 * OutboundBreakerTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resilience
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Kernel\Contracts\Integrations\OutboundBreakerInterface;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Service\Resilience\OutboundBreaker;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The Platform answer to the outbound breaker port.
 *
 * @covers \OCA\OpenRegister\Service\Resilience\OutboundBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
final class OutboundBreakerTest extends TestCase {

	private CircuitBreaker&MockObject $breaker;

	private OutboundBreaker $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->breaker = $this->createMock(CircuitBreaker::class);
		$registry      = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);
		$this->guard = new OutboundBreaker(breakers: $registry);
	}//end setUp()

	/**
	 * A response with this status and headers.
	 *
	 * @param int                   $status  The status.
	 * @param array<string, string> $headers The headers.
	 *
	 * @return IResponse The response.
	 */
	private function response(int $status, array $headers = []): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[$name] ?? ''));
		return $response;
	}//end response()

	public function testItImplementsTheIntegrationsPort(): void {
		$this->assertInstanceOf(OutboundBreakerInterface::class, $this->guard);
	}//end testItImplementsTheIntegrationsPort()

	public function testAnOpenBreakerRefusesBeforeTheCallIsMade(): void {
		$this->breaker->expects($this->once())->method('allow')->with('outbound:api.example.test')
			->willThrowException(new DependencyUnavailableException(key: 'outbound:api.example.test', retryAfter: 30));
		$this->breaker->expects($this->never())->method('recordFailure');
		$called = false;

		try {
			$this->guard->send(dependency: ' API.example.test ', send: function () use (&$called): IResponse {
				$called = true;
				return $this->response(status: 200);
			});
			$this->fail('An open breaker must refuse');
		} catch (DependencyUnavailableException $refused) {
			$this->assertSame('outbound:api.example.test', $refused->key);
		}

		$this->assertFalse($called);
	}//end testAnOpenBreakerRefusesBeforeTheCallIsMade()

	public function testASuccessOrANotFoundCountsAsSuccess(): void {
		$this->breaker->expects($this->exactly(2))->method('recordSuccess')->with('outbound:brp');
		$this->breaker->expects($this->never())->method('recordFailure');

		$ok       = $this->response(status: 200);
		$notFound = $this->response(status: 404);
		$this->assertSame($ok, $this->guard->send(dependency: 'brp', send: static fn (): IResponse => $ok));
		$this->assertSame($notFound, $this->guard->send(dependency: 'brp', send: static fn (): IResponse => $notFound));
	}//end testASuccessOrANotFoundCountsAsSuccess()

	public function testAServerErrorCountsAsFailure(): void {
		$this->breaker->expects($this->once())->method('recordFailure')->with('outbound:brp');
		$this->breaker->expects($this->never())->method('recordSuccess');

		$this->guard->send(dependency: 'brp', send: fn (): IResponse => $this->response(status: 503));
	}//end testAServerErrorCountsAsFailure()

	public function testATooManyRequestsCarriesItsRetryAfter(): void {
		$recorded = [];
		$this->breaker->expects($this->exactly(2))->method('recordFailure')
			->willReturnCallback(static function (string $key, ?int $retryAfter = null) use (&$recorded): void {
				$recorded[] = [$key, $retryAfter];
			});

		$this->guard->send(dependency: 'brp', send: fn (): IResponse => $this->response(status: 429, headers: ['Retry-After' => ' 120 ']));
		$this->guard->send(dependency: 'brp', send: fn (): IResponse => $this->response(status: 429, headers: ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']));

		$this->assertSame([['outbound:brp', 120], ['outbound:brp', null]], $recorded);
	}//end testATooManyRequestsCarriesItsRetryAfter()

	public function testATransportFailureIsCountedFromItsResponseAndRethrown(): void {
		$recorded = [];
		$this->breaker->method('recordFailure')->willReturnCallback(static function (string $key, ?int $retryAfter = null) use (&$recorded): void {
			$recorded[] = [$key, $retryAfter];
		});
		$this->breaker->expects($this->once())->method('recordSuccess')->with('outbound:brp');

		$request = new Request('GET', 'https://brp.test/');
		$failures = [
			new ConnectException('down', $request),
			new RequestException('busy', $request, new Response(429, ['Retry-After' => '7'])),
			new RequestException('gone', $request, new Response(404)),
		];
		foreach ($failures as $failure) {
			try {
				$this->guard->send(dependency: 'brp', send: static function () use ($failure): IResponse {
					throw $failure;
				});
				$this->fail('The failure must be rethrown');
			} catch (TransferException $thrown) {
				$this->assertSame($failure, $thrown);
			}
		}

		$this->assertSame([['outbound:brp', null], ['outbound:brp', 7]], $recorded);
	}//end testATransportFailureIsCountedFromItsResponseAndRethrown()

	public function testAnInnerRefusalIsRethrownUncounted(): void {
		$this->breaker->expects($this->never())->method('recordFailure');
		$this->breaker->expects($this->never())->method('recordSuccess');
		$refusal = new DependencyUnavailableException(key: 'outbound:inner', retryAfter: 5);

		try {
			$this->guard->send(dependency: 'brp', send: static function () use ($refusal): IResponse {
				throw $refusal;
			});
			$this->fail('The refusal must be rethrown');
		} catch (RuntimeException $thrown) {
			$this->assertSame($refusal, $thrown);
		}
	}//end testAnInnerRefusalIsRethrownUncounted()
}//end class
