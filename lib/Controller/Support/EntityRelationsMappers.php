<?php

/**
 * OpenRegister entity relations mappers
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

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * The mappers and repositories of the entity relations API.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityRelationsMappers {
	/**
	 * Bundle the services.
	 *
	 * @param EntityRelationMapperInterface $entityRelationMapper Relation mapper.
	 * @param SchemaCatalogInterface        $catalog              Register, schema and object loaders (object lookup and hasPermission inputs).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()
}//end class
