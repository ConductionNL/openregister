<?php

/**
 * OpenRegister Object Preview Sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reference
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

namespace OCA\OpenRegister\Service\Reference;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;

/**
 * Where an object preview reads from: the object, its deep link, and its schema and register names.
 *
 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
 */
final readonly class ObjectPreviewSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService    Fetches the referenced object.
	 * @param DeepLinkRegistryService   $deepLinkRegistry Resolves consuming-app URLs.
	 * @param SchemaMapperInterface     $schemaMapper     Resolves schema names and icons.
	 * @param RegisterMapperInterface   $registerMapper   Resolves register names.
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public DeepLinkRegistryService $deepLinkRegistry,
		public SchemaMapperInterface $schemaMapper,
		public RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
