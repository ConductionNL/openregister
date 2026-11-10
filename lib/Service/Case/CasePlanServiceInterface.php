<?php

/**
 * CasePlanServiceInterface: what callers outside the Service component ask of CasePlanService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Case plans, as the case controller and the case listeners use them. Only the
 * methods those callers call.
 */
interface CasePlanServiceInterface {

	/**
	 * Plan items that have not moved, for administrators.
	 *
	 * @param ListQuery   $query The page, and the `type` and `state` filters (either null for any).
	 * @param string|null $uid   The reading identity.
	 *
	 * @return array<string, mixed> results, total, limit, offset.
	 *
	 * @throws CaseAccessDeniedException For a non-administrator.
	 */
	public function findStuck(ListQuery $query, ?string $uid): array;

	/**
	 * The plan anchored on an object.
	 *
	 * @param string      $objectUuid The anchoring object.
	 * @param string|null $uid        The reading identity.
	 *
	 * @return array<string, mixed> objectUuid, items, audit, settings.
	 *
	 * @throws DoesNotExistException When there is no plan, OR the caller may not see the object.
	 */
	public function getPlan(string $objectUuid, ?string $uid): array;

	/**
	 * Create the plan of an object from a definition.
	 *
	 * @param CasePlanCreation $creation The anchor, the definition, the creating identity and the provenance.
	 *
	 * @return array<string, mixed> The plan as getPlan() returns it.
	 *
	 * @throws CaseValidationException When the definition is refused, or the object already has a plan.
	 * @throws CaseAccessDeniedException When the caller may not administer it.
	 */
	public function createPlan(CasePlanCreation $creation): array;

	/**
	 * Run the sentry passes of a plan.
	 *
	 * @param string      $objectUuid The anchoring object.
	 * @param string|null $uid        The acting identity.
	 *
	 * @return array{passes: int, transitions: int, skipped: bool} What happened.
	 */
	public function evaluate(string $objectUuid, ?string $uid): array;

	/**
	 * The discretionary items a caller may enable now.
	 *
	 * @param string      $objectUuid The anchoring object.
	 * @param string|null $uid        The reading identity.
	 *
	 * @return array<int, array<string, mixed>> The enableable items.
	 *
	 * @throws DoesNotExistException When there is no plan or the caller may not see it.
	 */
	public function enableableItems(string $objectUuid, ?string $uid): array;

	/**
	 * Attach an ad-hoc item to a plan.
	 *
	 * @param CaseAdHocRequest $request The anchoring object, the item and the acting identity.
	 *
	 * @return CaseItem The item after attachment and evaluation.
	 *
	 * @throws CaseAccessDeniedException When denied.
	 * @throws CaseValidationException When the item is refused.
	 */
	public function attachAdHoc(CaseAdHocRequest $request): CaseItem;

	/**
	 * Close a finished case with a result.
	 *
	 * @param CaseCompletion $completion The anchoring object, the result and the acting identity.
	 *
	 * @return array<string, mixed> The plan, plus `result`.
	 *
	 * @throws CaseValidationException When the result is outside the set, or the plan is not finished.
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 */
	public function completeCase(CaseCompletion $completion): array;

	/**
	 * Delete the plan of an object.
	 *
	 * @param string      $objectUuid The anchoring object.
	 * @param string|null $uid        The acting identity.
	 *
	 * @return integer Rows deleted.
	 *
	 * @throws CaseAccessDeniedException When the caller may not administer the plan.
	 */
	public function deletePlan(string $objectUuid, ?string $uid): int;

	/**
	 * Move one plan item to another state.
	 *
	 * @param CaseItemTransition $transition The item, the target state, the acting identity and the reason.
	 *
	 * @return CaseItem The item as persisted.
	 *
	 * @throws CaseAccessDeniedException When denied.
	 * @throws \OCA\OpenRegister\Exception\CaseTransitionException When illegal.
	 */
	public function transition(CaseItemTransition $transition): CaseItem;

	/**
	 * Enable one discretionary item.
	 *
	 * @param string      $itemUuid The item.
	 * @param string|null $uid      The acting identity.
	 *
	 * @return CaseItem The item after enabling and evaluation (normally `active`).
	 *
	 * @throws CaseAccessDeniedException When denied.
	 * @throws CaseValidationException When not enableable.
	 */
	public function enableDiscretionary(string $itemUuid, ?string $uid): CaseItem;

	/**
	 * Terminate an item whose timer fired.
	 *
	 * @param string $itemUuid  The item.
	 * @param string $timerUuid The timer that fired.
	 *
	 * @return boolean True when the item was terminated.
	 */
	public function onTimerExpired(string $itemUuid, string $timerUuid): bool;

	/**
	 * Feed an object event to the plans that listen for it.
	 *
	 * @param CasePlanTrigger $trigger The object, the catalog event id and the object's data after the change.
	 *
	 * @return void
	 */
	public function onObjectEvent(CasePlanTrigger $trigger): void;

	/**
	 * Advance the item a finished task or run realised.
	 *
	 * @param string $taskUuid The task.
	 *
	 * @return void
	 */
	public function onRealisationTerminal(string $taskUuid): void;
}//end interface
