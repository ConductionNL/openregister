<?php

/**
 * What the read check on a named principal is evaluated with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * What the read check on a named principal is evaluated with.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class MentionReadCheck {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper Resolves the object's schema for the read check.
	 * @param PermissionHandlerInterface $permissions The one RBAC evaluator.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public PermissionHandlerInterface $permissions,
	) {
	}//end __construct()
}//end class
