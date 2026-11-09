<?php

/**
 * BrpPersonProviderInterface: the part of BrpPersonProvider that the person lookup controller uses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-05-integration-brp-haalcentraal/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The methods of BrpPersonProvider that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface BrpPersonProviderInterface {

	/**
	 * Look up a single person by Burgerservicenummer (BSN).
	 *
	 * Issues a `RaadpleegMetBurgerservicenummer` POST to the HaalCentraal
	 * `/personen` endpoint (the same surface pipelinq's `HaalCentraalClient`
	 * uses) and returns the raw HAL+JSON person objects. Degrades null-safely
	 * to `{ unavailable, cause }` rather than throwing, so a consuming app
	 * never fatals on a missing/down source (AD-23).
	 *
	 * The BSN is carried in the request body only and is NEVER logged here —
	 * elfproef validation + BSN masking are the consuming app's responsibility.
	 *
	 * @param string $bsn The 9-digit Burgerservicenummer.
	 *
	 * @return array<string,mixed> `{ results, total, meta }` on success
	 *                             (results is the raw HaalCentraal person
	 *                             object list — 0 or 1 entries; `meta` carries
	 *                             the Wet-BRP audit metadata
	 *                             `{ correlationId, durationMs, status }`), or
	 *                             `{ unavailable, cause, results, total }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/integration-brp-audit-metadata/tasks.md
	 */
	public function lookupByBsn(string $bsn): array;
}//end interface
