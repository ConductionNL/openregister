<?php

/**
 * OpenRegister ObjectService read services: retrieval, rendering, relations and metadata
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;

/**
 * ObjectService read services: retrieval, rendering, relations and metadata.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectReadServices {
	/**
	 * Bundle the services.
	 *
	 * @param GetObject       $getHandler      Handler for object retrieval.
	 * @param RenderObject    $renderHandler   Handler for object rendering.
	 * @param RelationHandler $relationHandler Handler for object relationships.
	 * @param MetadataHandler $metadataHandler Handler for metadata operations.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly GetObject $getHandler,
		public readonly RenderObject $renderHandler,
		public readonly RelationHandler $relationHandler,
		public readonly MetadataHandler $metadataHandler,
	) {
	}//end __construct()
}//end class
