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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
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
	 * The HTTP status the refusal answers with.
	 *
	 * @var integer
	 */
	private readonly int $statusCode;

	/**
	 * Extra body fields, and the headers that repeat them.
	 *
	 * @var array<string, int|string>
	 */
	private readonly array $details;

	/**
	 * Constructor.
	 *
	 * @param int $statusCode The HTTP status.
	 * @param string $message The sentence a human reads.
	 * @param array<string, int|string> $details The ceiling and reset, when there is one.
	 *
	 * @return void
	 */
	public function __construct(int $statusCode, string $message, array $details = []) {
		parent::__construct(message: $message, code: $statusCode);
		$this->statusCode = $statusCode;
		$this->details = $details;

	}//end __construct()

	/**
	 * The refusal for a caller over its ceiling.
	 *
	 * @param int $limit The administered ceiling.
	 * @param int $windowSeconds The window the ceiling applies over.
	 * @param int $resetAt The unix time the window rolls.
	 * @param int $now The current unix time.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public static function overLimit(int $limit, int $windowSeconds, int $resetAt, int $now): self {
		$retryAfter = max(1, ($resetAt - $now));

		return new self(
			statusCode: Http::STATUS_TOO_MANY_REQUESTS,
			message: 'This caller is limited to ' . $limit . ' calls per ' . $windowSeconds
				. ' seconds. The limit resets in ' . $retryAfter . ' seconds.',
			details: [
				'limit' => $limit,
				'windowSeconds' => $windowSeconds,
				'remaining' => 0,
				'resetAt' => gmdate('c', $resetAt),
				'retryAfterSeconds' => $retryAfter,
			],
		);

	}//end overLimit()

	/**
	 * The refusal for a caller calling from an address it is not bound to.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
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
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
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
