<?php

/**
 * OpenRegister SchemaSplitRun
 *
 * One split of a register off a shared schema: the plan entry, the target register and the progress of the split.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;

/**
 * The state of one applySplit() run inside SharedSchemaDedupeService.
 *
 * Built by the entry point and never handed outside the service (ADR-011 Rule 3).
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SchemaSplitRun {

	/**
	 * The register being split off, once loaded.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The id of the replacement schema, once created.
	 *
	 * @var integer
	 */
	public int $newId = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $entry  One plan entry from inspect().
	 * @param int                  $target The register id to split off the shared schema.
	 */
	public function __construct(
		public readonly array $entry,
		public readonly int $target,
	) {
	}//end __construct()

	/**
	 * The id of the shared schema the register leaves.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function oldId(): int {
		return (int)$this->entry['schemaId'];
	}//end oldId()
}//end class
