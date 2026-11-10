<?php

/**
 * OpenRegister ValidationChunkResult
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The outcome of one parallel chunk of a mass validation run: which chunk, when it started and its batch counts.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
 */
final class ValidationChunkResult {

	/**
	 * Constructor.
	 *
	 * @param int         $index     Zero-based chunk index.
	 * @param float       $startedAt When the chunk started (microtime).
	 * @param list<array> $batches   The batch counts of the chunk, in job order.
	 */
	public function __construct(
		public readonly int $index,
		public readonly float $startedAt,
		public readonly array $batches,
	) {

	}//end __construct()
}//end class
