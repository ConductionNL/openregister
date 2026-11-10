<?php

/**
 * OpenRegister OfficeOpenRefusedException
 *
 * Raised when a document cannot be opened in Nextcloud Office.
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
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * A refused Office open, carrying the HTTP status to answer with.
 *
 * 409 when Office is not available, 415 when the file type does not open in
 * Office, 403 when the Office settings do not let this person use it.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */
class OfficeOpenRefusedException extends Exception {

	/**
	 * Office is not installed or not enabled.
	 *
	 * @var int
	 */
	public const UNAVAILABLE = 409;

	/**
	 * The file type does not open in Office.
	 *
	 * @var int
	 */
	public const UNSUPPORTED_TYPE = 415;

	/**
	 * The Office settings do not let this person use Office.
	 *
	 * @var int
	 */
	public const NOT_PERMITTED = 403;

	/**
	 * Constructor.
	 *
	 * @param int            $httpStatus One of the constants above.
	 * @param string         $message    The reason, in plain English.
	 * @param Throwable|null $previous   The failure underneath, if any.
	 */
	public function __construct(
		private readonly int $httpStatus,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $httpStatus, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int 409, 415 or 403.
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}//end getHttpStatus()
}//end class
