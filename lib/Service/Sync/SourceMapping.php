<?php

/**
 * OpenRegister SourceMapping
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\Source;

/**
 * A source together with the mapping its records go through on import.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final readonly class SourceMapping {

	/**
	 * Pair the source with its resolved mapping.
	 *
	 * @param Source       $source  The source being imported.
	 * @param Mapping|null $mapping The configured mapping, or null for pass-through.
	 */
	public function __construct(
		public Source $source,
		public ?Mapping $mapping,
	) {
	}//end __construct()
}//end class
