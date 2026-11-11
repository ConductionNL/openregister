<?php

/**
 * OpenRegister ObjectFileAccessDeniedException
 *
 * Raised when a person may not read or change the files of an object.
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
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * A refused file action on an object, carrying the HTTP status to answer with.
 *
 * A person who may not read the object gets 404, so a refusal never confirms
 * that the object exists. A person who may read but not change it gets 403.
 * It extends NotAuthorizedException so every existing catch still refuses.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
class ObjectFileAccessDeniedException extends NotAuthorizedException {

	/**
	 * The status for a person who may not read the object.
	 *
	 * @var int
	 */
	public const NOT_READABLE = 404;

	/**
	 * The status for a person who may read but not change the object.
	 *
	 * @var int
	 */
	public const NOT_CHANGEABLE = 403;

	/**
	 * Constructor.
	 *
	 * @param int            $httpStatus NOT_READABLE or NOT_CHANGEABLE.
	 * @param Throwable|null $previous   The refusal underneath, if any.
	 */
	public function __construct(
		private readonly int $httpStatus,
		?Throwable $previous = null,
	) {
		$message = 'You may not change the files of this object';
		if ($httpStatus === self::NOT_READABLE) {
			$message = 'Object not found';
		}

		parent::__construct(message: $message, code: $httpStatus, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int 404 or 403.
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}//end getHttpStatus()
}//end class
