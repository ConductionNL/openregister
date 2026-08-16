<?php

/**
 * OpenRegister FieldDecryptionException
 *
 * This file contains the exception class for field-level decryption failures.
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
 * Exception thrown when an encrypted field value cannot be decrypted.
 *
 * Raised by {@see \OCA\OpenRegister\Service\FieldEncryptionHandler} when a
 * value tagged as an OpenRegister encryption envelope fails to decrypt —
 * typically because the Nextcloud instance secret used to derive the
 * encryption key is missing or has rotated, or the stored ciphertext is
 * corrupted. Never swallowed silently: the fleet lesson (see the
 * orphaned-capability defect class) is that a swallowed catch here is
 * indistinguishable from a healthy app with a dead feature. Callers either
 * surface a structured error marker (read path, per-field, non-fatal) or
 * rethrow (migration/CLI path, fails the operation loudly).
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
class FieldDecryptionException extends Exception {
	/**
	 * Constructor for FieldDecryptionException
	 *
	 * @param string $message The error message describing the decryption failure.
	 * @param int $code The error code (default: 0).
	 * @param Throwable|null $previous The previous exception that caused this one.
	 *
	 * @return void
	 */
	public function __construct(
		string $message,
		int $code = 0,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
