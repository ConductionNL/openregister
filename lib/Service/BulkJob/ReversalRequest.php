<?php

/**
 * A request to undo one finished bulk job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Db\BulkJob;

/**
 * The job to undo, who undoes it and the reason they typed.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class ReversalRequest {
	/**
	 * Constructor.
	 *
	 * @param BulkJob     $original      The job to undo.
	 * @param string      $actorUid      The uid of the person undoing it.
	 * @param string|null $justification The reason they typed.
	 */
	public function __construct(
		public readonly BulkJob $original,
		public readonly string $actorUid,
		public readonly ?string $justification = null,
	) {
	}//end __construct()
}//end class
