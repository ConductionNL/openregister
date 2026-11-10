<?php

/**
 * An upsert on a declared key that was refused, with the answer to give.
 *
 * Carries the HTTP status and the body the controller sends, so every refusal
 * of the upsert path (an unknown or non-refuse constraint, a missing key value,
 * a duplicated key, a holder the caller cannot see, a lookup that could not
 * run) is decided in one place and answered as decided.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use RuntimeException;
use Throwable;

/**
 * A refused upsert: status, body and headers for the response.
 */
class UpsertOnKeyException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int                  $statusCode The HTTP status to answer with.
	 * @param array<string, mixed> $body       The response body.
	 * @param array<string, string> $headers   Extra response headers.
	 * @param Throwable|null       $previous   The cause, when there is one.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function __construct(
		private readonly int $statusCode,
		private readonly array $body,
		private readonly array $headers = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: (string)($body['error'] ?? 'The upsert was refused.'), code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The response body.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function getBody(): array {
		return $this->body;
	}//end getBody()

	/**
	 * Extra response headers, such as Retry-After on a 503.
	 *
	 * @return array<string, string> The headers.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function getHeaders(): array {
		return $this->headers;
	}//end getHeaders()
}//end class
