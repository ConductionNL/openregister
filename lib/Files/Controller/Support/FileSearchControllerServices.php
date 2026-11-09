<?php

/**
 * OpenRegister file search controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
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

use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;

/**
 * The service bundle of the file search API.
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md
 */
final class FileSearchControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param VectorizationServiceInterface $vectorService Vectorization service.
	 * @param ChunkMapperInterface          $chunkMapper   Chunk mapper (ranked keyword arm).
	 * @param FileReadScope                 $readScope     Keeps only the hits on files the caller may open.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md
	 */
	public function __construct(
		public readonly VectorizationServiceInterface $vectorService,
		public readonly ChunkMapperInterface $chunkMapper,
		public readonly FileReadScope $readScope,
	) {
	}//end __construct()
}//end class
