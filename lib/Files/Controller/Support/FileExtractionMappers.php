<?php

/**
 * OpenRegister file extraction mappers
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;

/**
 * The mappers and repositories of the file extraction API.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileExtractionMappers {
	/**
	 * Bundle the services.
	 *
	 * @param ChunkIndexOverviewInterface   $chunkMapper          Chunk mapper for text chunks
	 * @param EntityRelationMapperInterface $entityRelationMapper Entity relation mapper
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ChunkIndexOverviewInterface $chunkMapper,
		public readonly EntityRelationMapperInterface $entityRelationMapper,
	) {
	}//end __construct()
}//end class
