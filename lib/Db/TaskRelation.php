<?php

/**
 * A typed relation from a task to an additional object.
 *
 * The task's own anchor (`object_uuid` + `register_id` + `schema_id`) names
 * its subject; everything else it touches — the case, the decision, the
 * contract, the evidence — is a row here, carrying the relation's ROLE. The
 * 23 inventoried fleet shapes anchor to at least eighteen distinct entity
 * kinds; this table absorbs the nineteenth with an INSERT instead of a
 * migration (design D-6).
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TaskRelation
 *
 * @method integer|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method integer|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method integer|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class TaskRelation extends Entity implements JsonSerializable {

	/**
	 * The task this relation belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $taskId = null;

	/**
	 * The relation's role, chosen by the consuming schema (`case`,
	 * `decision`, `contract`, `evidence`, ...). Free text on purpose: a
	 * closed list here would be the twenty-column table wearing a disguise.
	 *
	 * @var string|null
	 */
	protected ?string $role = null;

	/**
	 * The related object's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The related object's register.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The related object's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'taskId', type: 'integer');
		$this->addType(fieldName: 'role', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The relation as plain data.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'role' => $this->role,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
		];
	}//end jsonSerialize()
}//end class
