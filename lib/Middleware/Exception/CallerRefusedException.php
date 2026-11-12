<?php

/**
 * A caller was refused for who it is, or for where it called from.
 *
 * Two refusals, and both of them answer the question the caller will ask next.
 *
 *  - 429, over its administered ceiling. The answer names the limit, what is
 *    left, and the second the window rolls, in the body and in `RateLimit-*`
 *    and `Retry-After`. A 429 that says only "too many requests" tells an
 *    integrator to retry, which is the one thing guaranteed to make it worse.
 *  - 403, from an address the token is not bound to. The answer says the
 *    binding refused it and does NOT list the bound addresses: a refusal that
 *    prints the allowlist hands an attacker the shape of the control.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware\Exception;

use Exception;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Carries the refusal a bounded caller receives.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 */
class CallerRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param int $statusCode The HTTP status.
	 * @param string $message The sentence a human reads.
	 * @param array<string, int|string> $details The ceiling and reset, when there is one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly int $statusCode,
		string $message,
		private readonly array $details = [],
	) {
		parent::__construct(message: $message, code: $statusCode);
	}//end __construct()

	/**
	 * The refusal for a caller over its ceiling, from the breach the limiter found.
	 *
	 * @param CeilingBreach $breach The ceiling, its window, when it rolls and the current time.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public static function overCeiling(CeilingBreach $breach): self {
		$retryAfter = $breach->retryAfter();

		return new self(
			statusCode: Http::STATUS_TOO_MANY_REQUESTS,
			message: 'This caller is limited to ' . $breach->limit . ' calls per ' . $breach->windowSeconds
				. ' seconds. The limit resets in ' . $retryAfter . ' seconds.',
			details: [
				'limit' => $breach->limit,
				'windowSeconds' => $breach->windowSeconds,
				'remaining' => 0,
				'resetAt' => gmdate('c', $breach->resetAt),
				'retryAfterSeconds' => $retryAfter,
			],
		);

	}//end overCeiling()

	/**
	 * The refusal for a caller calling from an address it is not bound to.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public static function wrongAddress(): self {
		return new self(
			statusCode: Http::STATUS_FORBIDDEN,
			message: 'This caller is bound to a set of source addresses, and this call did not come from one of them.',
		);

	}//end wrongAddress()

	/**
	 * The response the caller receives.
	 *
	 * @return JSONResponse The refusal, with the ceiling repeated in headers.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function toResponse(): JSONResponse {
		$body = ['error' => $this->getMessage()];
		foreach ($this->details as $key => $value) {
			$body[$key] = $value;
		}

		$response = new JSONResponse(data: $body, statusCode: $this->statusCode);

		if (isset($this->details['retryAfterSeconds']) === true) {
			$response->addHeader('Retry-After', (string)$this->details['retryAfterSeconds']);
			$response->addHeader('RateLimit-Limit', (string)$this->details['limit']);
			$response->addHeader('RateLimit-Remaining', '0');
			$response->addHeader('RateLimit-Reset', (string)$this->details['retryAfterSeconds']);
		}

		return $response;

	}//end toResponse()
}//end class
