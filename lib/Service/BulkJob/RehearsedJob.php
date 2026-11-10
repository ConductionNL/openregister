<?php

/**
 * OpenRegister Rehearsed Bulk Job
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
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

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A job as rehearsed at creation: the resolved action, its parameters and the hydrated selection.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class RehearsedJob {
	/**
	 * Name the rehearsal.
	 *
	 * @param BulkActionInterface         $action     The action.
	 * @param array<string, ObjectEntity> $objects    The hydrated selection.
	 * @param array<string, mixed>        $parameters The job's parameters.
	 */
	public function __construct(
		public BulkActionInterface $action,
		public array $objects,
		public array $parameters,
	) {
	}//end __construct()
}//end class
