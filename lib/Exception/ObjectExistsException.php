<?php

/**
 * OpenRegister ObjectExistsException
 *
 * This file contains the exception class for insert-only save conflicts.
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
 * Exception thrown when an insert-only save finds the object already present
 *
 * `saveObject()` is an upsert by default: given a uuid that already exists it
 * updates, silently and successfully. That is the right behaviour for most
 * callers and is unchanged.
 *
 * It is the wrong behaviour for a caller that is claiming something — a lock, a
 * slot, a lease, a ticket number, a queue position. There, "it already existed"
 * is the whole answer, and losing it means two callers both believe they won.
 * Such a caller passes `$failIfExists: true` and receives this exception
 * instead of a silent overwrite.
 *
 * Uses HTTP 409 Conflict: the request could not be completed because of a
 * conflict with the current state of the resource (RFC 9110 §15.5.10).
 *
 * This IS safe under concurrency as of openregister#2215. An earlier version of
 * this note said the opposite, and it was true when written: the only guard
 * then sat between the existence lookup and the write, so several simultaneous
 * callers could all pass it and all receive 201. The arbitration now lives in
 * MagicMapper, which refuses both the update branch and a losing INSERT when
 * insert-only is asked for. Verified 6/6 races at 12 concurrent claimants on
 * one identifier: 1x201, 11x409, one row.
 *
 * The mechanism is worth knowing before changing that code: the losing writer
 * usually never reaches an INSERT at all. It passes the service-level lookup,
 * so the audit trail records `create`, and then the mapper's own lookup finds
 * the winner's row and takes the UPDATE branch. Guarding only the constraint
 * violation therefore fixes almost nothing.
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
class ObjectExistsException extends Exception {

	/**
	 * The identifier of the object that already existed.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * Constructor for ObjectExistsException
	 *
	 * @param string $message The error message describing the conflict
	 * @param string|null $uuid The identifier that already existed
	 * @param int $code The error code (default: 409 Conflict)
	 * @param Throwable|null $previous The previous exception that caused this one
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'An object with this identifier already exists',
		?string $uuid = null,
		int $code = 409,
		?Throwable $previous = null,
	) {
		$this->uuid = $uuid;

		// HTTP 409 Conflict — the caller asked to create, and creating would
		// have overwritten something that was already there.
		parent::__construct(message: $message, code: $code, previous: $previous);

	}//end __construct()

	/**
	 * Get the identifier of the object that already existed.
	 *
	 * Lets a caller distinguish "my claim lost" from "something else went
	 * wrong" without parsing the message.
	 *
	 * @return string|null The conflicting identifier, when known.
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()
}//end class
