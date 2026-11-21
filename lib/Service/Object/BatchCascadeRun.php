<?php

/**
 * OpenRegister BatchCascadeRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One batched CASCADE delete of ReferentialIntegrityService: its targets, its origin and what the batch handled.
 *
 * Built by ReferentialIntegrityService::applyBatchCascadeDelete() and never handed out of it (ADR-011 Rule 3).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BatchCascadeRun {

	/**
	 * The uuids the batched soft delete handled (the rest fall back to the per-object path).
	 *
	 * @var array<string, true>
	 */
	public array $handled = [];

	/**
	 * The batch-deleted entities keyed by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	public array $deleted = [];

	/**
	 * Constructor for BatchCascadeRun.
	 *
	 * @param array               $cascadeTargets The cascade targets from the DeletionAnalysis (already reversed).
	 * @param CascadeDeleteOrigin $origin         The deleting user, the root object and the organisation.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $cascadeTargets,
		public readonly CascadeDeleteOrigin $origin,
	) {
	}//end __construct()
}//end class
