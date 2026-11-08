<?php

/**
 * OpenRegister Case Transition Cause
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;

/**
 * Why a plan item changed and who did it: the cause, its reference, the actor and the reason one audit row records.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final readonly class CaseTransitionCause {

	/**
	 * The cause of one change.
	 *
	 * @param string $cause sentry | user | realisation | cascade | import.
	 * @param string|null $causeRef The sentry id, task/run uuid, parent uuid or importer.
	 * @param string|null $actor The identity the audit row names.
	 * @param string|null $reason Free text.
	 */
	public function __construct(
		public string $cause,
		public ?string $causeRef,
		public ?string $actor,
		public ?string $reason,
	) {
	}//end __construct()

	/**
	 * The unsaved, authorized audit row of this cause moving an item between two states.
	 *
	 * @param CaseItem $item The item.
	 * @param string $fromState The state before ('' on creation).
	 * @param string $toState The state after, or the one requested.
	 *
	 * @return CaseItemAudit The audit row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function auditFor(CaseItem $item, string $fromState, string $toState): CaseItemAudit {
		$entry = new CaseItemAudit();
		$entry->setCaseItemId((int)$item->getId());
		$entry->setFromState($fromState);
		$entry->setToState($toState);
		$entry->setCause($this->cause);
		$entry->setCauseRef($this->causeRef);
		$entry->setActor($this->actor);
		$entry->setReason($this->reason);
		$entry->setAuthorized(true);

		return $entry;
	}//end auditFor()
}//end class
