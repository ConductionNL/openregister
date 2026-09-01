<?php

/**
 * One row of the candidate-pool INDEX.
 *
 * The `candidate_users`/`candidate_groups` JSON on the task is the readable
 * record; these rows are what the pooled inbox joins against, so "unclaimed
 * tasks in any group I am in" is an index hit rather than a JSON scan per
 * row (design.md — Data model). One write path maintains both inside one
 * transaction; drift between the two is a defect a test asserts against.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TaskCandidate
 *
 * @method integer|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getRef()
 * @method void setRef(?string $ref)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class TaskCandidate extends Entity implements JsonSerializable {

	/**
	 * A candidate uid.
	 */
	public const KIND_USER = 'user';

	/**
	 * A candidate group id.
	 */
	public const KIND_GROUP = 'group';

	/**
	 * A candidate role name.
	 */
	public const KIND_ROLE = 'role';

	/**
	 * The task this candidate row belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $taskId = null;

	/**
	 * user|group|role.
	 *
	 * @var string|null
	 */
	protected ?string $kind = null;

	/**
	 * The uid, group id or role name.
	 *
	 * @var string|null
	 */
	protected ?string $ref = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'taskId', type: 'integer');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'ref', type: 'string');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The candidate row as plain data.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'kind' => $this->kind,
			'ref' => $this->ref,
		];
	}//end jsonSerialize()
}//end class
