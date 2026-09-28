<?php

/**
 * OpenRegister AnalyzeRequestRejectedException.
 *
 * Thrown when an entity detection backend answers an analyze request with a
 * 4xx status: the backend was reached and refused what it was sent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A detection backend refused the analyze request (HTTP 4xx).
 *
 * WHY THIS IS NOT "UNREACHABLE"
 * -----------------------------
 * Every non-2xx answer used to become `null`, which the caller logged as
 * "unreachable, falling back to regex". A 422 from anonymiq for an entity name
 * it does not know is not an outage: the request is wrong, it will be wrong on
 * every retry, and the regex fallback only finds e-mail, phone and IBAN, so
 * every name, organisation and place went undetected behind a log line that
 * pointed at the network (or#4115). A refusal is reported as what it is.
 *
 * The message carries the service, the status and the backend's own detail,
 * never the analysed text.
 */
class AnalyzeRequestRejectedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $service The backend's human-readable name.
	 * @param int $status The HTTP status it answered with.
	 * @param string $detail The backend's error detail, if any.
	 */
	public function __construct(
		private readonly string $service,
		private readonly int $status,
		string $detail = '',
	) {
		$message = sprintf('%s rejected the analyze request with HTTP %d', $service, $status);
		if ($detail !== '') {
			$message .= ': ' . mb_substr($detail, 0, 500);
		}

		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The backend's name.
	 *
	 * @return string
	 */
	public function getService(): string {
		return $this->service;
	}//end getService()

	/**
	 * The HTTP status the backend answered with.
	 *
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * Whether an HTTP status is a request error (4xx).
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return bool
	 */
	public static function isRequestError(int $status): bool {
		return $status >= 400 && $status < 500;
	}//end isRequestError()
}//end class
