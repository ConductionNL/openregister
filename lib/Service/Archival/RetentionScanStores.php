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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
	 * @param IDBConnection           $db             Database connection for the row scans.
	 * @param RegisterMapperInterface $registerMapper Register repository, used to enumerate pairs.
	 * @param SchemaMapperInterface   $schemaMapper   Schema repository, used to enumerate pairs.
	 * @param MagicMapperInterface    $objectMapper   Magic-table resolver and row hydrator.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()
}//end class
