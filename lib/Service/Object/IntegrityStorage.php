<?php

/**
 * OpenRegister integrity storage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\IDBConnection;

/**
 * What referential integrity reads and writes the referencing objects with.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IntegrityStorage {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface $objectEntityMapper Object entity data mapper.
	 * @param IDBConnection        $db                 Database connection for raw SQL queries.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
