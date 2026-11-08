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
	 * @param string               $actionId      The action to run.
	 * @param array<string, mixed> $parameters    The action's parameters.
	 * @param array<string, mixed> $selection     Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string|null          $justification The reason the operator typed.
	 * @param string               $actorUid      The actor's uid.
	 * @param int|null             $registerId    The register the selection lives in.
	 * @param int|null             $schemaId      The schema the selection lives in.
	 */
	public function __construct(
		public string $actionId,
		public array $parameters,
		public array $selection,
		public ?string $justification,
		public string $actorUid,
		public ?int $registerId,
		public ?int $schemaId,
	) {
	}//end __construct()
}//end class
