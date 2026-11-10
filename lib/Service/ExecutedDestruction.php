<?php

/**
 * A destruction that was carried out: its list, how many were destroyed, and when.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * A destruction that was carried out, as its certificate reports it (ADR-011 Rule 2).
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ExecutedDestruction {
	/**
	 * Build the executed destruction.
	 *
	 * @param array  $destructionList The destruction list data.
	 * @param int    $destroyedCount  Number of objects destroyed.
	 * @param string $executedAt      ISO 8601 timestamp of execution.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly array $destructionList,
		public readonly int $destroyedCount,
		public readonly string $executedAt,
	) {
	}//end __construct()
}//end class
