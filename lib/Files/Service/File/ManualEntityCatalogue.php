<?php

/**
 * The catalogue and chunks a manual entity is matched against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;

/**
 * The GDPR entity, entity relation and chunk mappers and the chunk text matcher of the manual entity service.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ManualEntityCatalogue {
	/**
	 * Bundle the collaborators.
	 *
	 * @param GdprEntityMapper              $gdprEntityMapper     Catalogue mapper.
	 * @param EntityRelationMapperInterface $entityRelationMapper Relation mapper.
	 * @param ChunkMapper                   $chunkMapper          Chunk reader for the target file.
	 * @param ChunkTextMatcher              $matcher              Chunk-aware string-match utility.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly GdprEntityMapper $gdprEntityMapper,
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly ChunkMapper $chunkMapper,
		public readonly ChunkTextMatcher $matcher,
	) {
	}//end __construct()
}//end class
