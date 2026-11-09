<?php

/**
 * OpenRegister schema version stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
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

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;

/**
 * The persistence a schema version reads and writes: its changelog and its runs.
 */
final class SchemaVersionStores {
	/**
	 * Constructor.
	 *
	 * @param SchemaChangelogMapperInterface $changelogMapper Changelog persistence.
	 * @param SchemaRunStores                $runs            Run persistence (for invalid counts).
	 */
	public function __construct(
		public readonly SchemaChangelogMapperInterface $changelogMapper,
		public readonly SchemaRunStores $runs,
	) {

	}//end __construct()
}//end class
