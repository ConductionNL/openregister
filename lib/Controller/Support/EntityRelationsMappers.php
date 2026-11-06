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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

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
	 * @param MagicMapperInterface $magicMapper          ObjectEntity loader (for object-bound authz).
	 * @param SchemaMapperInterface         $schemaMapper         Schema loader (for hasPermission inputs).
	 * @param RegisterMapperInterface       $registerMapper       Register loader (for object lookup disambiguation).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly MagicMapperInterface $magicMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
