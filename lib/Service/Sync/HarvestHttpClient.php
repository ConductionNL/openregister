<?php

/**
 * OpenRegister Harvest HTTP Client
 *
 * The one HTTP client harvest fetchers use: the shared outbound URL guard on the first URL and every
 * redirect hop, bounded timeouts, a few retries with backoff, and a body cap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use OCA\OpenRegister\Service\Http\OutboundUrlGuard;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Guarded, bounded HTTP for harvests.
 *
 * - Every URL (the first and each redirect hop) passes {@see OutboundUrlGuard}.
 * - 5 s connect and 30 s read timeout.
 * - At most 3 attempts; a 5xx, a 429 or a connect/timeout failure is retried
 *   after 2 s, then 4 s (a 429's `Retry-After` in seconds wins, capped at 60).
 * - A body over 50 MB is refused.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class HarvestHttpClient {

	/**
	 * Most attempts per request.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Largest body accepted, in bytes.
	 */
	public const MAX_BODY_BYTES = 52428800;

	/**
	 * Connect timeout, seconds.
	 */
	public const CONNECT_TIMEOUT = 5;

	/**
	 * Read timeout, seconds.
	 */
	public const READ_TIMEOUT = 30;

	/**
	 * Longest Retry-After honoured, seconds.
	 */
	private const MAX_RETRY_AFTER = 60;

	/**
	 * Waits between attempts; replaced in tests.
	 *
	 * @var Closure(int): void
	 */
	private Closure $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Client           $client  The underlying Guzzle client.
	 * @param OutboundUrlGuard $guard   The address rules.
	 * @param Closure|null     $sleeper Waits the given seconds (default: sleep()).
	 */
	public function __construct(
		private readonly Client $client,
		private readonly OutboundUrlGuard $guard = new OutboundUrlGuard(),
		?Closure $sleeper = null,
	) {
		$this->sleeper = ($sleeper ?? static function (int $seconds): void {
			sleep($seconds);
		});
	}//end __construct()

	/**
	 * Send a GET request.
	 *
	 * @param string                $url     The URL.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return ResponseInterface The final response.
	 *
	 * @throws RuntimeException When the URL is refused, the body is too large, or every attempt failed.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function get(string $url, array $headers = []): ResponseInterface {
		return $this->request(method: 'GET', url: $url, headers: $headers);
	}//end get()

	/**
	 * Send a request through the guard, with retries.
	 *
	 * @param string                $method  The HTTP method.
	 * @param string                $url     The URL.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return ResponseInterface The final response.
	 *
	 * @throws RuntimeException When the URL is refused, the body is too large, or every attempt failed.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function request(string $method, string $url, array $headers = []): ResponseInterface {
		$this->guard->assertSafe(uri: $url);

		$attempt = 0;
		while (true) {
			$attempt++;
			$wait = $this->attempt(method: $method, url: $url, headers: $headers, attempt: $attempt);
			if ($wait instanceof ResponseInterface) {
				return $this->capped(response: $wait);
			}

			($this->sleeper)($wait);
		}
	}//end request()

	/**
	 * One attempt: the response to return, or the seconds to wait before the next attempt.
	 *
	 * @param string                $method  The HTTP method.
	 * @param string                $url     The URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $attempt The attempt number, from 1.
	 *
	 * @return ResponseInterface|int The response, or the wait.
	 *
	 * @throws RuntimeException When the last attempt failed.
	 */
	private function attempt(string $method, string $url, array $headers, int $attempt): ResponseInterface|int {
		try {
			$response = $this->client->request($method, $url, $this->options(headers: $headers));
		} catch (ConnectException $e) {
			if ($attempt >= self::MAX_ATTEMPTS) {
				throw new RuntimeException('Harvest request failed after ' . $attempt . ' attempts: ' . $e->getMessage(), 0, $e);
			}

			return $this->backoff(attempt: $attempt);
		} catch (RequestException $e) {
			// Guzzle wraps a refusal raised in a callback (body cap, guarded redirect); surface the refusal.
			if ($e->getPrevious() instanceof RuntimeException) {
				throw $e->getPrevious();
			}

			throw $e;
		}

		$status = $response->getStatusCode();
		if (($status >= 500 || $status === 429) === false || $attempt >= self::MAX_ATTEMPTS) {
			return $response;
		}

		if ($status === 429) {
			return $this->retryAfter(response: $response, attempt: $attempt);
		}

		return $this->backoff(attempt: $attempt);
	}//end attempt()

	/**
	 * The Guzzle options for one attempt.
	 *
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return array<string, mixed> The options.
	 */
	private function options(array $headers): array {
		return [
			'headers' => $headers,
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'timeout' => self::READ_TIMEOUT,
			'http_errors' => false,
			'allow_redirects' => [
				'max' => 5,
				'strict' => true,
				'referer' => false,
				'protocols' => ['http', 'https'],
				'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
					// Every hop passes the same rules; throwing aborts the chain.
					$this->guard->assertSafe(uri: (string)$uri);
				},
			],
			'on_headers' => function (mixed $response): void {
				// Refuse an over-large body before it is read.
				if ($response instanceof ResponseInterface) {
					$this->assertWithinCap(length: $response->getHeaderLine('Content-Length'));
				}
			},
		];
	}//end options()

	/**
	 * The wait before the next attempt: 2 s, then 4 s, then 8 s.
	 *
	 * @param int $attempt The attempt that failed.
	 *
	 * @return int Seconds.
	 */
	private function backoff(int $attempt): int {
		return (2 ** $attempt);
	}//end backoff()

	/**
	 * The wait a 429 asks for, when it names seconds; the backoff otherwise.
	 *
	 * @param ResponseInterface $response The 429 response.
	 * @param int               $attempt  The attempt that failed.
	 *
	 * @return int Seconds.
	 */
	private function retryAfter(ResponseInterface $response, int $attempt): int {
		$header = trim($response->getHeaderLine('Retry-After'));
		if ($header !== '' && ctype_digit($header) === true) {
			return min((int)$header, self::MAX_RETRY_AFTER);
		}

		return $this->backoff(attempt: $attempt);
	}//end retryAfter()

	/**
	 * Refuse a response whose body is over the cap.
	 *
	 * @param ResponseInterface $response The response.
	 *
	 * @return ResponseInterface The same response.
	 *
	 * @throws RuntimeException When the body is too large.
	 */
	private function capped(ResponseInterface $response): ResponseInterface {
		$this->assertWithinCap(length: $response->getHeaderLine('Content-Length'));
		$size = $response->getBody()->getSize();
		if ($size !== null && $size > self::MAX_BODY_BYTES) {
			throw new RuntimeException('Harvest response body exceeds ' . self::MAX_BODY_BYTES . ' bytes');
		}

		return $response;
	}//end capped()

	/**
	 * Refuse a declared Content-Length over the cap.
	 *
	 * @param string $length The header value ('' when absent).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When it is over the cap.
	 */
	private function assertWithinCap(string $length): void {
		if ($length !== '' && ctype_digit($length) === true && (int)$length > self::MAX_BODY_BYTES) {
			throw new RuntimeException('Harvest response body exceeds ' . self::MAX_BODY_BYTES . ' bytes');
		}
	}//end assertWithinCap()
}//end class
