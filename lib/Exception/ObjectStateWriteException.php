<?php

/**
 * OpenRegister ObjectStateWriteException
 *
 * The refusal an archived or frozen object answers a write with.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Thrown when an object's state refuses a write to its data.
 *
 * Two states raise it, and the message says which: archived, where the object
 * has also left the working views, and frozen, where it has not. Both refuse
 * every write to the object's own data and both keep accepting the state's own
 * reversal, the retention machinery and a legal hold.
 *
 * The refusal names the state, the person who put the object into it and the
 * time, because "this object is read-only" is not a debuggable answer. A flow
 * whose write step is turned away records the same sentence, which is how a
 * run that was archived out from under it becomes readable rather than
 * mysterious.
 *
 * HTTP 409 Conflict, not 423 Locked: a lock is a temporary hold another writer
 * will release, an archive is a decision about the record. Mapping both to 423
 * would tell a client to wait and retry on a state nobody is going to release.
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
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */
class ObjectStateWriteException extends Exception {

	/**
	 * The HTTP status controllers MUST map this exception to.
	 *
	 * A constant rather than the exception's `code`, matching
	 * {@see LockedException::HTTP_STATUS}: `getCode()` is an application error
	 * code, not an HTTP status.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 409;

	/**
	 * The state that refused: `archived` or `frozen`.
	 *
	 * @var string|null
	 */
	private ?string $state = null;

	/**
	 * Who put the object into that state.
	 *
	 * @var string|null
	 */
	private ?string $actor = null;

	/**
	 * When they did, as an ISO 8601 string.
	 *
	 * @var string|null
	 */
	private ?string $occurredAt = null;

	/**
	 * Build the refusal for an archived object.
	 *
	 * Built here, from the entity's own accessors, so every guard refuses in
	 * the same words and names the same archive. A second guard spelling its
	 * own sentence is how two doors to the same object end up disagreeing.
	 *
	 * The CONDITION is not restated here: a caller asks
	 * {@see ObjectEntity::isArchived()} and, only once that has said yes, asks
	 * this factory for the refusal.
	 *
	 * @param ObjectEntity $object The archived object.
	 *
	 * @return self The refusal naming the archive, the archiver and the time.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public static function archived(ObjectEntity $object): self {
		$marker = ($object->getArchived() ?? []);
		$actor = self::stringOrNull(value: ($marker['by'] ?? null));
		$occurredAt = self::stringOrNull(value: ($marker['at'] ?? null));

		$exception = new self(
			message: 'Cannot write to this object: it was archived by '
				. ($actor ?? 'an unknown user') . ' on ' . ($occurredAt ?? 'an unrecorded date')
				. '. Restore it from the archive before changing it.'
		);

		$exception->state = 'archived';
		$exception->actor = $actor;
		$exception->occurredAt = $occurredAt;

		return $exception;
	}//end archived()

	/**
	 * Build the refusal for a frozen object.
	 *
	 * Names the lifecycle state that declared the freeze when a state did,
	 * because "frozen by the system" is what an unexplained refusal reads as
	 * when a phase closing froze the record and nobody clicked anything.
	 *
	 * @param ObjectEntity $object The frozen object.
	 *
	 * @return self The refusal naming the freeze, its actor and the time.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public static function frozen(ObjectEntity $object): self {
		$marker = ($object->getFrozen() ?? []);
		$actor = self::stringOrNull(value: ($marker['by'] ?? null));
		$occurredAt = self::stringOrNull(value: ($marker['at'] ?? null));
		$state = self::stringOrNull(value: ($marker['state'] ?? null));

		$because = '';
		if ($state !== null) {
			$because = ' on entering the state "' . $state . '"';
		}

		$exception = new self(
			message: 'Cannot write to this object: it was frozen by '
				. ($actor ?? 'an unknown user') . ' on ' . ($occurredAt ?? 'an unrecorded date')
				. $because . '. Unfreeze it before changing it.'
		);

		$exception->state = 'frozen';
		$exception->actor = $actor;
		$exception->occurredAt = $occurredAt;

		return $exception;
	}//end frozen()

	/**
	 * The state that refused the write.
	 *
	 * @return string|null `archived`, `frozen`, or null when not built by a factory.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function getState(): ?string {
		return $this->state;
	}//end getState()

	/**
	 * Who put the object into the refusing state.
	 *
	 * @return string|null The user id, or null when the marker did not record one.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function getActor(): ?string {
		return $this->actor;
	}//end getActor()

	/**
	 * When the object entered the refusing state.
	 *
	 * @return string|null An ISO 8601 string, or null when the marker did not record one.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function getAt(): ?string {
		return $this->occurredAt;
	}//end getAt()

	/**
	 * Narrow a marker value to a non-empty string.
	 *
	 * A marker written by an older version, or by a repair, can hold anything;
	 * concatenating an array into the message would turn a refusal into a
	 * PHP notice and lose the sentence the caller needs.
	 *
	 * @param mixed $value The raw marker value.
	 *
	 * @return string|null The value as a non-empty string, or null.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()

	/**
	 * Constructor for ObjectStateWriteException.
	 *
	 * @param string $message The refusal message.
	 * @param int $code The error code (default: 409 Conflict).
	 * @param Throwable|null $previous The previous exception that caused this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		string $message = 'Cannot write to this object in its current state',
		int $code = 409,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
