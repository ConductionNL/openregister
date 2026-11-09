<?php

/**
 * OpenRegister entity recognition stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
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

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCP\IDBConnection;

/**
 * The persistence collaborators of the entity recognition handler.
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */
final class EntityRecognitionStores {
	/**
	 * Bundle the stores.
	 *
	 * @param ChunkSourceReaderInterface    $chunkMapper          Chunk mapper.
	 * @param GdprEntityMapperInterface              $entityMapper         Entity mapper.
	 * @param EntityRelationMapperInterface $entityRelationMapper Entity relation mapper.
	 * @param IDBConnection                 $db                   Database connection.
	 *
	 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
	 */
	public function __construct(
		public readonly ChunkSourceReaderInterface $chunkMapper,
		public readonly GdprEntityMapperInterface $entityMapper,
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
