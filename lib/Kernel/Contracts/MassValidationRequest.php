<?php

/**
 * One mass validation of the stored objects.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * One mass validation of the stored objects: how many, in which batches, serial or parallel, and whether errors are collected.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
 */
final class MassValidationRequest {
	/**
	 * Hold the values.
	 *
	 * @param int    $maxObjects    Maximum number of objects to process (0 = all).
	 * @param int    $batchSize     Batch size for processing.
	 * @param string $mode          Processing mode: 'serial' or 'parallel'.
	 * @param bool   $collectErrors Whether to collect all errors or stop on first.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function __construct(
		public readonly int $maxObjects,
		public readonly int $batchSize,
		public readonly string $mode,
		public readonly bool $collectErrors,
	) {
	}//end __construct()
}//end class
