<?php

/**
 * One schema id replaced by another in a register's schema list.
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
 * The shared schema id a split register leaves and the id of its replacement schema.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SchemaIdSwap {

	/**
	 * Constructor.
	 *
	 * @param int $oldId The shared schema id to replace.
	 * @param int $newId The replacement schema id.
	 */
	public function __construct(
		public readonly int $oldId,
		public readonly int $newId,
	) {
	}//end __construct()
}//end class
