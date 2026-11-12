<?php

/**
 * FlowTriggerIndexInterface: the FlowTriggerIndex as the trigger-index backfill repair step reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;

/**
 * The flow trigger index rebuild, typed for the repair step that backfills it.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowTriggerIndexInterface {

	/**
	 * Rebuild the index for many flows, reporting what could not be converted.
	 *
	 * The report is the point. A backfill that silently converted what it could
	 * would leave an operator believing the cutover was complete while flows
	 * quietly remained on the old path — so every flow that keeps a column
	 * trigger with no node form is named.
	 *
	 * @param array<int, Flow> $flows The flows to index.
	 *
	 * @return array{indexed: int, rows: int, unconverted: array<int, array{uuid: string, reason: string, columns: array|null}>}
	 *                                                                                                                           What happened.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function rebuild(array $flows): array;
}//end interface
