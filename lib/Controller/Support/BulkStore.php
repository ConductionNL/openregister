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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectStreamingSaveInterface;
use OCA\OpenRegister\Service\Rbac\SchemaManageGateInterface;

/**
 * Services of the bulk API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BulkStore {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface      $registerMapper   Mapper for resolving registers (RBAC gates)
	 * @param SchemaMapperInterface        $schemaMapper     Mapper for resolving schemas (RBAC gates)
	 * @param ObjectStreamingSaveInterface $objectService    The object service, with the streaming save
	 * @param ?SchemaManageGateInterface            $schemaManageGate Who may manage a schema. Built from the collaborators above when null.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectStreamingSaveInterface $objectService,
		public readonly ?SchemaManageGateInterface $schemaManageGate = null,
	) {
	}//end __construct()
}//end class
