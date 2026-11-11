<?php

/**
 * OpenRegister PartialExclusion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * What a partial approval takes off a destruction list: the UUIDs the
 * approver excluded, the reason given per UUID, and the period each excluded
 * object's archiefactiedatum moves by.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final readonly class PartialExclusion {

	/**
	 * Bundle the exclusion.
	 *
	 * @param array<int, string>    $excludedIds     UUIDs to exclude.
	 * @param array<string, string> $reasons         Reasons per excluded UUID.
	 * @param string                $extensionPeriod ISO 8601 duration to add.
	 */
	public function __construct(
		public array $excludedIds,
		public array $reasons,
		public string $extensionPeriod,
	) {
	}//end __construct()
}//end class
