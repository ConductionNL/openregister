<?php

/**
 * One object lock held by one flow run.
 *
 * The row is BOOKKEEPING, never the authority. The `_locked` payload on the
 * object itself is the only thing a write guard consults; this table exists
 * so two questions can be answered without reading objects at all:
 *
 *  - "which locks does this run hold", asked by the terminal-event listener,
 *    which runs inside the run's own write transaction and must be cheap;
 *  - "which locks are orphaned", asked by the sweep on every cron tick.
 *
 * Answering either by scanning the objects is not an option. Locks live in
 * the `_locked` column of magic tables, one per register-schema pair, and
 * `MagicMapper::findAcrossAllMagicTables()` carries a measured note that an
 * instance-wide scan over 2,728 of them builds 690 KB of SQL costing ~3.4s to
 * PLAN before a row is read.
 *
 * Because it is bookkeeping, drift is survivable in both directions: a row
 * with no matching payload is stale and the sweep deletes it, and a payload
 * with no row still blocks writes and still expires on its TTL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One run-held object lock.
 *
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getNodeId()
 * @method void setNodeId(?string $nodeId)
 * @method DateTime|null getLockedAt()
 * @method void setLockedAt(?DateTime $lockedAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 */
class RunObjectLock extends Entity implements JsonSerializable {

	/**
	 * The run holding the lock.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * The locked object.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The object's register, so a release can resolve it without a scan.
	 *
	 * @var string|null
	 */
	protected ?string $registerId = null;

	/**
	 * The object's schema, for the same reason.
	 *
	 * @var string|null
	 */
	protected ?string $schemaId = null;

	/**
	 * The flow node that took the lock, so an operator can see WHICH step wedged.
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * When the lock was taken.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lockedAt = null;

	/**
	 * When the underlying lock expires on its own. The sweep's second read.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'string');
		$this->addType(fieldName: 'schemaId', type: 'string');
		$this->addType(fieldName: 'nodeId', type: 'string');
		$this->addType(fieldName: 'lockedAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON shape.
	 *
	 * @return array<string, mixed> The row as an array.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'runUuid' => $this->runUuid,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'nodeId' => $this->nodeId,
			'lockedAt' => $this->lockedAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
