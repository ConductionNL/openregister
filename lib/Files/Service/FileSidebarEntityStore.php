<?php

/**
 * The extraction and entity data behind the file sidebar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCP\IDBConnection;

/**
 * The database connection and the chunk, entity relation and GDPR entity mappers of the file sidebar service.
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */
final class FileSidebarEntityStore {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IDBConnection                 $db                   Database connection for magic table queries.
	 * @param ChunkMapper                   $chunkMapper          Chunk mapper for extraction data.
	 * @param EntityRelationMapperInterface $entityRelationMapper Entity relation mapper for PII data.
	 * @param GdprEntityMapper              $gdprEntityMapper     GDPR entity mapper for entity type lookups.
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly ChunkMapper $chunkMapper,
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly GdprEntityMapper $gdprEntityMapper,
	) {
	}//end __construct()
}//end class
