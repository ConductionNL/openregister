<?php

/**
 * OpenRegister EncryptedFieldFilterException
 *
 * This file contains the exception class raised when a caller attempts to
 * filter, search or facet on a field flagged `x-openregister-encrypted`.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when a search/filter/facet request targets an encrypted property.
 *
 * An encrypted field's magic-table value is opaque ciphertext (when a column
 * exists for it at all — see design.md, encrypted properties get no dedicated
 * magic-table column), so a plaintext filter against it can never match
 * correctly. Rather than silently returning zero rows (indistinguishable from
 * "no matches" — the exact anti-pattern flagged for this feature), OpenRegister
 * fails loud with this exception, translated to HTTP 400 by
 * {@see \OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware}.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class EncryptedFieldFilterException extends Exception {
	/**
	 * Constructor for EncryptedFieldFilterException.
	 *
	 * @param string $property The encrypted property name that was targeted.
	 * @param int $code The error code (default: 400).
	 * @param Throwable|null $previous The previous exception that caused this one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $property,
		int $code = 400,
		?Throwable $previous = null,
	) {
		parent::__construct(
			message: sprintf(
				'Property "%s" is encrypted at rest and cannot be filtered, searched or faceted server-side.',
				$property
			),
			code: $code,
			previous: $previous
		);
	}//end __construct()

	/**
	 * The encrypted property name that triggered this exception.
	 *
	 * @return string The property name.
	 */
	public function getProperty(): string {
		return $this->property;
	}//end getProperty()
}//end class
