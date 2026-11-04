<?php

/**
 * OpenRegister bulk store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;

/**
 * Services of the bulk API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BulkStore {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapper    $registerMapper   Mapper for resolving registers (RBAC gates)
	 * @param SchemaMapper      $schemaMapper     Mapper for resolving schemas (RBAC gates)
	 * @param ObjectService     $objectService    The object service
	 * @param ?SchemaManageGate $schemaManageGate Who may manage a schema. Built from the collaborators above when null.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly ObjectService $objectService,
		public readonly ?SchemaManageGate $schemaManageGate = null,
	) {
	}//end __construct()
}//end class
