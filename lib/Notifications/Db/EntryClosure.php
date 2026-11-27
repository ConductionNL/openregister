<?php

/**
 * An object closed to new timeline entries and notes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Who closed an object to new entries, when and why. The object itself is untouched.
 *
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getClosedBy()
 * @method void setClosedBy(string $closedBy)
 * @method DateTime|null getClosedAt()
 * @method void setClosedAt(DateTime $closedAt)
 * @method string|null getReason()
 * @method void setReason(string $reason)
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class EntryClosure extends Entity implements JsonSerializable {

	/**
	 * The closed object.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * Who closed it.
	 *
	 * @var string|null
	 */
	protected ?string $closedBy = null;

	/**
	 * When it was closed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $closedAt = null;

	/**
	 * Why it was closed.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'closedBy', type: 'string');
		$this->addType(fieldName: 'closedAt', type: 'datetime');
		$this->addType(fieldName: 'reason', type: 'string');

	}//end __construct()

	/**
	 * The closure as the API answers it: by, at and reason.
	 *
	 * @return array{by: string, at: string|null, reason: string}
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function closure(): array {
		$at = null;
		if ($this->closedAt instanceof DateTime) {
			$at = $this->closedAt->format(DATE_ATOM);
		}

		return ['by' => (string)$this->closedBy, 'at' => $at, 'reason' => (string)$this->reason];

	}//end closure()

	/**
	 * The row as JSON.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function jsonSerialize(): array {
		return ['id' => $this->id, 'objectUuid' => $this->objectUuid] + $this->closure();

	}//end jsonSerialize()
}//end class
