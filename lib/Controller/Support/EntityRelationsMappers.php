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

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * The mappers and repositories of the entity relations API.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityRelationsMappers {
	/**
	 * Bundle the services.
	 *
	 * @param EntityRelationMapper $entityRelationMapper Relation mapper.
	 * @param MagicMapper          $magicMapper          ObjectEntity loader (for object-bound authz).
	 * @param SchemaMapper         $schemaMapper         Schema loader (for hasPermission inputs).
	 * @param RegisterMapper       $registerMapper       Register loader (for object lookup disambiguation).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapper $entityRelationMapper,
		public readonly MagicMapper $magicMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly RegisterMapper $registerMapper,
	) {
	}//end __construct()
}//end class
