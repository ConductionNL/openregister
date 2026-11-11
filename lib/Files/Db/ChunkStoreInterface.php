<?php

/**
 * ChunkStoreInterface: the chunk writes and the vectorization queue of ChunkMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Writes chunks and serves the queue of chunks still to vectorize.
 */
interface ChunkStoreInterface extends ChunkSourceReaderInterface {

	/**
	 * Chunks extracted but not yet vectorized, oldest first.
	 *
	 * @param int|null $limit  Maximum number of chunks to return
	 * @param int|null $offset Offset for pagination
	 *
	 * @return Chunk[] Array of unvectorized chunks
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.1
	 */
	public function findUnvectorized(?int $limit=null, ?int $offset=null): array;

	/**
	 * Store a changed chunk.
	 *
	 * @param Entity $entity The chunk.
	 *
	 * @return Entity The stored chunk.
	 */
	public function update(Entity $entity): Entity;
}//end interface
