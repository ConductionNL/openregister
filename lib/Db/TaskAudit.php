<?php

/**
 * One append-only audit entry on a task.
 *
 * Every lifecycle verb that succeeds AND every authorization denial appends
 * one of these, in the same transaction as the mutation it records — a
 * completed task without its audit entry is not a reachable state. The entry
 * names the ACTING identity and the PERFORMER TYPE, so "a human approved
 * this" and "a model approved this" stay distinguishable after the fact,
 * and a delegated action carries both identities (`actor` + `on_behalf_of`).
 *
 * No update or delete path exists ({@see TaskAuditMapper}), and deleting a
 * task does not cascade here.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TaskAudit
 *
 * @method integer|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method string|null getStateAfter()
 * @method void setStateAfter(?string $stateAfter)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getPerformerType()
 * @method void setPerformerType(?string $performerType)
 * @method string|null getOnBehalfOf()
 * @method void setOnBehalfOf(?string $onBehalfOf)
 * @method string|null getMandate()
 * @method void setMandate(?string $mandate)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method bool|null getAuthorized()
 * @method void setAuthorized(?bool $authorized)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
 */
class TaskAudit extends Entity implements JsonSerializable {

	/**
	 * The task this entry belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $taskId = null;

	/**
	 * The named transition action (`claim`, `complete`, `reject`, ...).
	 *
	 * @var string|null
	 */
	protected ?string $action = null;

	/**
	 * The state the task held after the action; null on a denial.
	 *
	 * @var string|null
	 */
	protected ?string $stateAfter = null;

	/**
	 * The ACTING identity — on a delegated action, the delegate.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * user|group|agent|worker at the time of acting.
	 *
	 * @var string|null
	 */
	protected ?string $performerType = null;

	/**
	 * The original performer a delegate acted for.
	 *
	 * @var string|null
	 */
	protected ?string $onBehalfOf = null;

	/**
	 * The authority the delegation relied on.
	 *
	 * @var string|null
	 */
	protected ?string $mandate = null;

	/**
	 * The reason or comment supplied with the action, or the denial reason.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * False when this entry records a DENIED attempt.
	 *
	 * @var boolean|null
	 */
	protected ?bool $authorized = true;

	/**
	 * When the entry was appended.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'taskId', type: 'integer');
		$this->addType(fieldName: 'action', type: 'string');
		$this->addType(fieldName: 'stateAfter', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'performerType', type: 'string');
		$this->addType(fieldName: 'onBehalfOf', type: 'string');
		$this->addType(fieldName: 'mandate', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'authorized', type: 'boolean');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The audit entry as plain data.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'action' => $this->action,
			'stateAfter' => $this->stateAfter,
			'actor' => $this->actor,
			'performerType' => $this->performerType,
			'onBehalfOf' => $this->onBehalfOf,
			'mandate' => $this->mandate,
			'reason' => $this->reason,
			'authorized' => $this->authorized,
			'created' => $this->created?->format('c'),
		];
	}//end jsonSerialize()
}//end class
