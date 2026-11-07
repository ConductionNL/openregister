<?php

/**
 * ChunkIndexOverviewInterface: the chunk index counts and file listings of ChunkMapper.
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

/**
 * Counts the chunk index and lists the files that have chunks.
 */
interface ChunkIndexOverviewInterface extends ChunkSourceReaderInterface {

	/**
	 * Count the chunks the search engine has indexed.
	 *
	 * @return int Indexed chunk count
	 */
	public function countIndexed(): int;

	/**
	 * Count the chunks that have vector embeddings.
	 *
	 * @return int Vectorized chunk count
	 */
	public function countVectorized(): int;

	/**
	 * File source summaries grouped by source_id.
	 *
	 * @param int|null    $limit  Maximum number of results
	 * @param int|null    $offset Offset for pagination
	 * @param string|null $search Optional search filter on file name
	 * @param string      $sort   Sort field (fileName, fileSize, extractedAt, chunkCount)
	 * @param string      $order  Sort direction (ASC or DESC)
	 *
	 * @return array List of file source summaries
	 */
	public function getFileSourceSummaries(
		?int $limit=null,
		?int $offset=null,
		?string $search=null,
		string $sort='extractedAt',
		string $order='DESC',
	): array;

	/**
	 * Count distinct file sources that have chunks.
	 *
	 * @param string|null $search Optional search filter on file name
	 *
	 * @return int Number of distinct file sources
	 */
	public function countFileSourceSummaries(?string $search=null): int;
}//end interface
