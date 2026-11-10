<?php

/**
 * OpenRegister SharedSchemaUse
 *
 * A schema entity and the registers that list it.
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

/**
 * A schema entity id and the ids of the registers that share it.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SharedSchemaUse {

	/**
	 * Constructor.
	 *
	 * @param int        $schemaId    The shared schema entity id.
	 * @param array<int> $registerIds The registers that list it.
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly array $registerIds,
	) {
	}//end __construct()
}//end class
