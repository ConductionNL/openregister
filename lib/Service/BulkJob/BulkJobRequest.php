<?php

/**
 * OpenRegister Bulk Job Request
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

/**
 * What an operator asked a bulk job to do, as {@see BulkJobService::create()}
 * receives it.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class BulkJobRequest {
	/**
	 * Name the request.
	 *
	 * @param BulkJobActionCall $call          The action to run and its parameters.
	 * @param BulkJobSelection  $selection     The objects to run it on.
	 * @param string            $actorUid      The actor's uid.
	 * @param string|null       $justification The reason the operator typed.
	 */
	public function __construct(
		public BulkJobActionCall $call,
		public BulkJobSelection $selection,
		public string $actorUid,
		public ?string $justification,
	) {
	}//end __construct()
}//end class
