<?php

/**
 * OpenRegister schema run stores
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

use OCA\OpenRegister\Db\SchemaRunEntryMapperInterface;
use OCA\OpenRegister\Db\SchemaRunMapperInterface;

/**
 * The persistence of schema runs: the run records and their per-object entries.
 */
final class SchemaRunStores {
	/**
	 * Constructor.
	 *
	 * @param SchemaRunMapperInterface      $runMapper      Run persistence.
	 * @param SchemaRunEntryMapperInterface $runEntryMapper Per-object run entries.
	 */
	public function __construct(
		public readonly SchemaRunMapperInterface $runMapper,
		public readonly SchemaRunEntryMapperInterface $runEntryMapper,
	) {

	}//end __construct()
}//end class
