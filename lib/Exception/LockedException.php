<?php

/**
 * OpenRegister LockedException
 *
 * This file contains the exception class for object lock errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Exception thrown when an object is locked and cannot be modified
 *
 * Thrown when attempting to modify an object that is currently locked.
 * Object locking prevents concurrent modifications and ensures data integrity.
 * Uses HTTP 423 Locked status code as per RFC 4918.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class LockedException extends Exception {

	/**
	 * The HTTP status controllers MUST map this exception to.
	 *
	 * A constant rather than the exception's `code` for the same reason
	 * `FolderAccessDeniedException::HTTP_STATUS` is one: `getCode()` is an
	 * application error code, not an HTTP status. The constructor's legacy
	 * `$code = 423` default is left alone so nothing reading `getCode()`
	 * changes underneath it.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 423;

	/**
	 * The user holding the lock, when a person holds it.
	 *
	 * @var string|null
	 */
	private ?string $lockedBy = null;

	/**
	 * The flow run holding the lock, when a run holds it.
	 *
	 * @var string|null
	 */
	private ?string $lockedByRun = null;

	/**
	 * The holder description used in the message.
	 *
	 * @var string|null
	 */
	private ?string $holder = null;

	/**
	 * Build the canonical refusal for an object a caller may not write.
	 *
	 * The refusal is built HERE, from `ObjectEntity`'s own accessors, so that
	 * every guard refuses in the same words and names the same holder. Before
	 * this, PUT built the message and the holder fields inline in the
	 * controller while the service-layer guard built a different sentence and
	 * carried no holder at all, so the two doors to the same object answered
	 * differently: 423 naming the holder on one, 400 or 500 naming nothing on
	 * the other.
	 *
	 * The CONDITION is not restated here — callers ask
	 * `ObjectEntity::isLockedBySomeoneElse()` and, only once it has said yes,
	 * ask this factory for the refusal.
	 *
	 * @param ObjectEntity $object The locked object, already found to be locked against the caller.
	 *
	 * @return self The refusal carrying the holder.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	public static function forObject(ObjectEntity $object): self {
		$holder = (string)$object->describeLockHolder();

		$exception = new self(
			message: 'Cannot update object: Object is locked by ' . $holder
				. '. Please unlock the object before attempting to update it.'
		);

		$exception->lockedBy = $object->getLockedBy();
		$exception->lockedByRun = $object->getLockedByRun();
		$exception->holder = $holder;

		return $exception;
	}//end forObject()

	/**
	 * Get the user holding the lock, when a person holds it.
	 *
	 * @return string|null The holding user id, or null when a run holds it.
	 */
	public function getLockedBy(): ?string {
		return $this->lockedBy;
	}//end getLockedBy()

	/**
	 * Get the flow run holding the lock, when a run holds it.
	 *
	 * @return string|null The holding run uuid, or null when a person holds it.
	 */
	public function getLockedByRun(): ?string {
		return $this->lockedByRun;
	}//end getLockedByRun()

	/**
	 * Get the human-readable holder description.
	 *
	 * @return string|null The holder description, or null when not built by `forObject()`.
	 */
	public function getHolder(): ?string {
		return $this->holder;
	}//end getHolder()

	/**
	 * Constructor for LockedException
	 *
	 * Initializes exception with lock error message.
	 * Uses HTTP 423 Locked status code (RFC 4918) to indicate the resource
	 * is locked and cannot be modified at this time.
	 *
	 * @param string $message The error message describing lock status
	 *                        (default: 'Object is locked and cannot be modified')
	 * @param int $code The error code (default: 423 Locked)
	 * @param Throwable|null $previous The previous exception that caused this one
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'Object is locked and cannot be modified',
		int $code = 423,
		?Throwable $previous = null,
	) {
		// Call parent constructor to initialize base exception properties.
		// HTTP 423 Locked indicates the resource is locked (RFC 4918).
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
