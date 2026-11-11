<?php

/**
 * One restamp: a register's moved rows repointed from the shared schema id to its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
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

namespace OCA\OpenRegister\Service\SharedSchema;

/**
 * The repointing of one register's moved rows: the register that bounds the
 * uri rewrite, the shared schema id they leave and the schema id they join.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final readonly class RowRestamp {

	/**
	 * Bundle the restamp.
	 *
	 * @param int $registerId The owning register id, which bounds the uri rewrite.
	 * @param int $oldId      The shared schema id.
	 * @param int $newId      The register's new schema id.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function __construct(
		public int $registerId,
		public int $oldId,
		public int $newId,
	) {
	}//end __construct()
}//end class
