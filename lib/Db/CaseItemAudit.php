<?php

/**
 * One plan-item state change, or one refused attempt at one.
 *
 * Append-only by construction: {@see CaseItemAuditMapper} exposes no update
 * and no delete, and deleting a plan item does not cascade here. Denials are
 * recorded too (`authorized: false`), so "who tried to enable this and was
 * refused" is answerable after the fact.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class CaseItemAudit
 *
 * @method integer|null getCaseItemId()
 * @method void setCaseItemId(?int $caseItemId)
 * @method string|null getFromState()
 * @method void setFromState(?string $fromState)
 * @method string|null getToState()
 * @method void setToState(?string $toState)
 * @method string|null getCause()
 * @method void setCause(?string $cause)
 * @method string|null getCauseRef()
 * @method void setCauseRef(?string $causeRef)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method bool|null getAuthorized()
 * @method void setAuthorized(?bool $authorized)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
class CaseItemAudit extends Entity implements JsonSerializable {

	/**
	 * The five causes a transition can have.
	 */
	public const CAUSE_SENTRY = 'sentry';

	public const CAUSE_USER = 'user';

	public const CAUSE_REALISATION = 'realisation';

	public const CAUSE_CASCADE = 'cascade';

	public const CAUSE_IMPORT = 'import';

	/**
	 * The plan item this entry is about.
	 *
	 * @var integer|null
	 */
	protected ?int $caseItemId = null;

	/**
	 * The state before.
	 *
	 * @var string|null
	 */
	protected ?string $fromState = null;

	/**
	 * The state after (or the state that was requested, on a denial).
	 *
	 * @var string|null
	 */
	protected ?string $toState = null;

	/**
	 * sentry | user | realisation | cascade | import.
	 *
	 * @var string|null
	 */
	protected ?string $cause = null;

	/**
	 * The sentry id, the task or run uuid, or the parent item uuid.
	 *
	 * @var string|null
	 */
	protected ?string $causeRef = null;

	/**
	 * The acting identity.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * Free-text reason, or the denial message.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * False on a recorded denial.
	 *
	 * @var boolean|null
	 */
	protected ?bool $authorized = true;

	/**
	 * When it happened.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declare field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'caseItemId', type: 'integer');
		$this->addType(fieldName: 'fromState', type: 'string');
		$this->addType(fieldName: 'toState', type: 'string');
		$this->addType(fieldName: 'cause', type: 'string');
		$this->addType(fieldName: 'causeRef', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'authorized', type: 'boolean');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The entry as plain data.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'caseItemId' => $this->caseItemId,
			'fromState' => $this->fromState,
			'toState' => $this->toState,
			'cause' => $this->cause,
			'causeRef' => $this->causeRef,
			'actor' => $this->actor,
			'reason' => $this->reason,
			'authorized' => $this->authorized,
			'created' => $this->created?->format('c'),
		];
	}//end jsonSerialize()
}//end class
