<?php

/**
 * ExpiredObjectSources: the mappers and connection the expired-object store reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Retention;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * The storage the expired-object store works on.
 */
final class ExpiredObjectSources {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registers The register mapper.
	 * @param SchemaMapperInterface   $schemas   The schema mapper.
	 * @param MagicMapperInterface    $magic     The magic-table mapper.
	 * @param AuditTrailMapper        $audit     The audit trail.
	 * @param IDBConnection           $db        The connection.
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
		public readonly MagicMapperInterface $magic,
		public readonly AuditTrailMapper $audit,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
