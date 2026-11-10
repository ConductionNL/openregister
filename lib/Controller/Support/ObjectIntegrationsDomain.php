<?php

/**
 * OpenRegister object integrations domain
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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;

/**
 * The integration registry and the object and schema services an integration call reads.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */
final class ObjectIntegrationsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param IntegrationCatalogueInterface       $registry      Integration registry.
	 * @param ObjectOperationsInterface $objectService OpenRegister object service (RBAC boundary).
	 * @param SchemaMapperInterface     $schemaMapper  Schema loader (external-source detection).
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
	 */
	public function __construct(
		public readonly IntegrationCatalogueInterface $registry,
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
