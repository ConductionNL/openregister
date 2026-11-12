<?php

/**
 * OpenRegister migration storage
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * What a migration reads, moves and records the objects with.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MigrationStorage {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface      $objectMapper Mapper for object entities.
	 * @param MoveObject                $mover        Moves an object to its target address.
	 * @param AuditTrailMapperInterface $auditTrail   Records what the mapping changed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly MoveObject $mover,
		public readonly AuditTrailMapperInterface $auditTrail,
	) {
	}//end __construct()
}//end class
