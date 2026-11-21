<?php
/**
 * CaseItemInterface: what readers of a plan item outside lib/Db need of a CaseItem row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A plan item: one occurrence in a case plan, anchored to an object.
 *
 * Implemented by \OCA\OpenRegister\Db\CaseItem. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method string|null getUuid()
 * @method string|null getItemKey()
 * @method string|null getName()
 * @method string|null getDescription()
 * @method string|null getObjectUuid()
 * @method integer|null getRegisterId()
 * @method integer|null getSchemaId()
 * @method string|null getFlowUuid()
 * @method integer|null getFlowVersion()
 * @method string|null getDefinitionItemKey()
 * @method string|null getOrigin()
 * @method integer|null getParentItemId()
 * @method string|null getPlanItemType()
 * @method integer|null getPosition()
 * @method string|null getState()
 * @method bool|null getIsTerminal()
 * @method DateTime|null getEnteredAt()
 * @method string|null getTerminatedReason()
 * @method array|null getEntryCriteria()
 * @method array|null getExitCriteria()
 * @method bool|null getRequired()
 * @method bool|null getDiscretionary()
 * @method array|null getRepetition()
 * @method string|null getRealisationKind()
 * @method string|null getRealisationUuid()
 * @method integer|null getRealisationCount()
 * @method array|null getAuthorizationRules()
 * @method array|null getCandidateUsers()
 * @method array|null getCandidateGroups()
 * @method string|null getCandidateRole()
 * @method DateTime|null getDueAt()
 * @method DateTime|null getExpiresAt()
 * @method string|null getDoorlooptijd()
 * @method string|null getServicenorm()
 * @method array|null getPlanSettings()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 * @method string|null getCreatedBy()
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
interface CaseItemInterface extends JsonSerializable {
	/**
	 * Whether the item's state is terminal (completed, terminated or disabled).
	 *
	 * @return boolean True when the state is terminal.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function isInTerminalState(): bool;

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The plan item as plain data.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function jsonSerialize(): array;
}//end interface
