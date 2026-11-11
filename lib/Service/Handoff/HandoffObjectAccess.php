<?php

/**
 * OpenRegister Handoff Object Access
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
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

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * Reads and writes the source and target objects of a handoff, under the caller's RBAC.
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md#requirement-handoffservice-executes-conversions-on-top-of-semantictyperesolver
 */
final readonly class HandoffObjectAccess {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService RBAC-enforcing object read/write path.
	 * @param SchemaMapperInterface $schemaMapper Source-schema loading.
	 * @param MagicMapperInterface $objectMapper Raw entity access for provenance relation writes.
	 * @param PermissionHandlerInterface $permissionHandler RBAC pre-checks (typed 403 before any write).
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public SchemaMapperInterface $schemaMapper,
		public MagicMapperInterface $objectMapper,
		public PermissionHandlerInterface $permissionHandler,
	) {
	}//end __construct()
}//end class
