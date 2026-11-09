<?php

/**
 * AvgRetentionPassInterface: what callers outside the Service component ask of AvgRetentionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Running one AVG retention-enforcement pass, as the daily retention job does. Only the method that caller calls.
 */
interface AvgRetentionPassInterface {

	/**
	 * Run a single retention-enforcement pass.
	 *
	 * Returns a summary envelope:
	 *
	 *   {
	 *     "evaluatedActivities": <int>,
	 *     "skippedActivities":   <int>,
	 *     "objectsErased":       <int>,
	 *     "objectsWithheld":     <int>,
	 *     "withheld": [
	 *       {"uuid": "...", "schema": "...", "ground": "ARCHIVAL_RETENTION_OBLIGATION",
	 *        "message": "...", "basis": "...", "action": "..."},
	 *       ...
	 *     ],
	 *     "dryRun":              bool,
	 *     "perActivity": [
	 *       {"uuid": "...", "naam": "...", "bewaartermijn": "P10Y",
	 *        "cutoff": "<iso>", "matchedObjects": <int>, "erased": <int>,
	 *        "withheld": [...]},
	 *       ...
	 *     ]
	 *   }
	 *
	 * Activities without a `bewaartermijn` (or with a malformed
	 * duration) are reported under `skippedActivities` so operators
	 * can see at-a-glance which catalog rows aren't yet retention-
	 * enforced.
	 *
	 * @param bool $dryRun When true, evaluates and reports without
	 *                     actually soft-deleting anything.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function runRetentionPass(bool $dryRun = false): array;
}//end interface
