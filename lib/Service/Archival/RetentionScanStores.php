<?php

/**
 * The stores a retention row scan walks.
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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCP\IDBConnection;

/**
 * The database and the repositories that name every table a retention decision can live in.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class RetentionScanStores {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection          $db      Database connection for the row scans.
	 * @param SchemaCatalogInterface $catalog The register and schema repositories, used to enumerate pairs, and
	 *                                        the magic-table resolver and row hydrator.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()
}//end class
