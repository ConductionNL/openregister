<?php

/**
 * ChunkSourceReaderInterface: the per-source chunk reads of ChunkMapper.
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
 * Reads the chunks stored for one source (a file or an object).
 */
interface ChunkSourceReaderInterface {

	/**
	 * The chunks of one source, in chunk order.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int    $sourceId   Source identifier.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 *
	 * @psalm-param non-empty-string $sourceType
	 *
	 * @return Chunk[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 */
	public function findBySource(string $sourceType, int $sourceId): array;

	/**
	 * The latest update time of one source's chunks.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int    $sourceId   Source identifier.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 *
	 * @psalm-param non-empty-string $sourceType
	 *
	 * @return int|null Unix timestamp of the latest update or null when unavailable.
	 */
	public function getLatestUpdatedTimestamp(string $sourceType, int $sourceId): ?int;
}//end interface
