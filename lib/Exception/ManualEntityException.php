<?php

/**
 * OpenRegister ManualEntityException.
 *
 * Thrown by `ManualEntityService::addManualEntity` for orchestration-
 * layer failures (file not extracted yet, unsupported entity type,
 * audit-write failure, etc.). The message MUST NOT contain the
 * operator-supplied `value` per ADR-005.
 *
 * The controller layer translates each typed `reason` into the
 * appropriate HTTP status:
 *
 *   REASON_FILE_NOT_EXTRACTED      → 422 Unprocessable Entity
 *   REASON_UNSUPPORTED_ENTITY_TYPE → 400 Bad Request
 *   REASON_REGEX_COMPILE_FAILURE   → 400 Bad Request
 *   REASON_FORBIDDEN               → 403 Forbidden
 *   REASON_INTERNAL_ERROR          → 500 Internal Server Error
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
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Typed reason codes for orchestration-layer failures.
 */
class ManualEntityException extends Exception {
	/**
	 * The file has no chunks yet — text extraction was never run, or
	 * was run and produced zero chunks. The caller must run extraction
	 * first.
	 */
	public const REASON_FILE_NOT_EXTRACTED = 'file_not_extracted';

	/**
	 * The supplied entity type isn't in the catalogue's recognised set.
	 */
	public const REASON_UNSUPPORTED_ENTITY_TYPE = 'unsupported_entity_type';

	/**
	 * The operator-supplied value couldn't be compiled into a regex
	 * pattern (malformed Unicode).
	 */
	public const REASON_REGEX_COMPILE_FAILURE = 'regex_compile_failure';

	/**
	 * The acting user lacks write access to the target file (read-only
	 * node, or not an updateable file). Mapped to HTTP 403.
	 */
	public const REASON_FORBIDDEN = 'forbidden';

	/**
	 * Catch-all for unexpected runtime failures (DB error,
	 * audit-write rollback, etc.).
	 */
	public const REASON_INTERNAL_ERROR = 'internal_error';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the `REASON_*` constants above.
	 * @param string $message Human-readable description. MUST NOT contain the
	 *                        operator-supplied `value` per ADR-005.
	 * @param Throwable|null $previous Wrapped lower-layer exception (DB error etc.).
	 */
	public function __construct(
		private readonly string $reason,
		string $message = '',
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);

	}//end __construct()

	/**
	 * Get the typed reason code.
	 *
	 * @return string One of the `REASON_*` constants.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
