<?php

/**
 * One seeding of shipped selectielijst entries into the configured register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;

/**
 * The register and schema the rows go to, the app shipping them, the rows
 * already stored (and created so far), and the tally.
 *
 * Never leaves SelectionListSeeder.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class SeedRun {

	/**
	 * The stored rows, keyed on category, organisation and version.
	 *
	 * @var array<string, ObjectEntity>
	 */
	public array $stored = [];

	/**
	 * The tally so far.
	 *
	 * @var array{created: int, updated: int, unchanged: int, kept: int, failed: array<int, array{category: string, reason: string}>}
	 */
	public array $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0, 'failed' => []];

	/**
	 * Start a seeding.
	 *
	 * @param RegisterSchema $table The selectielijst register and schema.
	 * @param string|null    $appId The app shipping the entries.
	 */
	public function __construct(
		public readonly RegisterSchema $table,
		public readonly ?string $appId,
	) {
	}//end __construct()
}//end class
