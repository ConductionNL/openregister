<?php

/**
 * MasterRecomputeInterface: what callers outside the Service component ask of MasterRecomputeService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
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

namespace OCA\OpenRegister\Service\Merge;

/**
 * Recomputing one master record from its sources, as the recompute job does. Only the method that caller calls.
 */
interface MasterRecomputeInterface {

	/**
	 * Recompute a master's golden record by re-persisting it, which re-runs
	 * the survivorship recompute listener over the master's (reverse-FK)
	 * source set. Best-effort — a failure is logged and swallowed.
	 *
	 * A master that no longer resolves is a stale no-op, not an error: the
	 * source object may have been reassigned or the master deleted between
	 * the triggering write and this call.
	 *
	 * @param string $masterUuid Referenced master uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `MergeService::normaliseRoundTripDates`
	 *   is a pure stateless date-format utility shared with the merge relink;
	 *   a static call is the lightest way to reuse it without injecting the
	 *   whole MergeService (which would raise coupling for one helper).
	 */
	public function recompute(string $masterUuid): void;
}//end interface
