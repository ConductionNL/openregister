<?php

/**
 * OpenRegister OutboundBreaker
 *
 * Guards outbound HTTP calls with the dependency breakers and counts their outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use GuzzleHttp\Exception\RequestException;
use OCA\OpenRegister\Kernel\Contracts\Integrations\OutboundBreakerInterface;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The outbound client's breaker: refuse while open, otherwise send and count.
 *
 * A connect error, a timeout, a 5xx or a 429 is a failure (a 429's
 * Retry-After becomes the cool-down when longer); any other response, a 404
 * included, is the caller's problem and counts as a success.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
final class OutboundBreaker implements OutboundBreakerInterface {

	/**
	 * Constructor.
	 *
	 * @param BreakerRegistry $breakers The dependency breakers.
	 */
	public function __construct(
		private readonly BreakerRegistry $breakers,
	) {
	}//end __construct()

	/**
	 * Send one call through the dependency's breaker.
	 *
	 * @param string                $dependency The named dependency, else the host the call goes to.
	 * @param callable(): IResponse $send       Makes the call.
	 *
	 * @return IResponse The response.
	 *
	 * @throws DependencyUnavailableException While the dependency's breaker is open.
	 * @throws Throwable What the call threw.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function send(string $dependency, callable $send): IResponse {
		$key = BreakerRegistry::outboundKey(hostOrName: $dependency);

		$breaker = $this->breakers->breaker();
		$breaker->allow(key: $key);

		try {
			$response = $send();
		} catch (DependencyUnavailableException $refused) {
			throw $refused;
		} catch (Throwable $failure) {
			$this->record(key: $key, response: self::responseOf(failure: $failure));
			throw $failure;
		}

		$this->record(key: $key, response: $response);
		return $response;
	}//end send()

	/**
	 * Count one outcome on the breaker.
	 *
	 * @param string                           $key      The breaker key.
	 * @param IResponse|ResponseInterface|null $response The response, or null when none arrived (connect error, timeout).
	 *
	 * @return void
	 */
	private function record(string $key, IResponse|ResponseInterface|null $response): void {
		$breaker = $this->breakers->breaker();

		if ($response === null) {
			$breaker->recordFailure(key: $key);
			return;
		}

		$status = $response->getStatusCode();
		if ($status === 429) {
			$breaker->recordFailure(key: $key, retryAfter: self::retryAfter(response: $response));
			return;
		}

		if ($status >= 500) {
			$breaker->recordFailure(key: $key);
			return;
		}

		$breaker->recordSuccess(key: $key);
	}//end record()

	/**
	 * The response a transport failure carried, if any.
	 *
	 * @param Throwable $failure What the transport threw.
	 *
	 * @return ResponseInterface|null The response, or null for a connect error or timeout.
	 */
	private static function responseOf(Throwable $failure): ?ResponseInterface {
		if ($failure instanceof RequestException) {
			return $failure->getResponse();
		}

		return null;
	}//end responseOf()

	/**
	 * A 429's Retry-After in seconds, when it gives a number of seconds.
	 *
	 * @param IResponse|ResponseInterface $response The response.
	 *
	 * @return int|null The seconds, or null.
	 */
	private static function retryAfter(IResponse|ResponseInterface $response): ?int {
		if ($response instanceof ResponseInterface) {
			$value = $response->getHeaderLine('Retry-After');
		} else {
			$value = $response->getHeader('Retry-After');
		}

		$value = trim($value);
		if ($value === '' || ctype_digit($value) === false) {
			return null;
		}

		return (int) $value;
	}//end retryAfter()
}//end class
