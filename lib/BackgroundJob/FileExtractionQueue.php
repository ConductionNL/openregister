<?php

/**
 * FileExtractionQueue: queues FileTextExtractionJob for one file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Kernel\Contracts\FileExtractionQueueInterface;
use OCP\BackgroundJob\IJobList;

/**
 * The job list half of {@see FileExtractionQueueInterface}: the same job and
 * argument the file change listener queues.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
class FileExtractionQueue implements FileExtractionQueueInterface {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobs The job list.
	 */
	public function __construct(
		private readonly IJobList $jobs,
	) {
	}//end __construct()

	/**
	 * Queue the text extraction of a file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function queueFileExtraction(int $fileId): void {
		$this->jobs->add(job: ProcessesJobHandlesInterface::FILE_TEXT_EXTRACTION, argument: ['file_id' => $fileId]);
	}//end queueFileExtraction()
}//end class
