<?php

/**
 * FileExtractionQueueInterface: queue a file's text extraction without naming the job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Queues the background text extraction of one file.
 *
 * The extraction job reads a file that has no chunks and re-reads one whose
 * chunks are older than the file, so a caller repairing the search index only
 * has to queue it.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
interface FileExtractionQueueInterface {

	/**
	 * Queue the text extraction of a file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function queueFileExtraction(int $fileId): void;
}//end interface
