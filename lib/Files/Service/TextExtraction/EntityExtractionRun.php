<?php

/**
 * EntityExtractionRun
 *
 * One pass of EntityRecognitionHandler over the content chunks of a source:
 * the request, the chunk in hand and the running totals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Db\Chunk;

/**
 * The request, current chunk and totals of one source's entity extraction.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
 */
final class EntityExtractionRun {

	/**
	 * The chunk being processed.
	 *
	 * @var Chunk|null
	 */
	public ?Chunk $chunk = null;

	/**
	 * The number of chunks processed without failure.
	 *
	 * @var integer
	 */
	public int $chunksProcessed = 0;

	/**
	 * The number of entities found over all chunks.
	 *
	 * @var integer
	 */
	public int $entitiesFound = 0;

	/**
	 * The number of chunk-entity relations created over all chunks.
	 *
	 * @var integer
	 */
	public int $relationsCreated = 0;

	/**
	 * Constructor.
	 *
	 * @param EntityExtractionRequest $request The source and the processing options.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function __construct(
		public readonly EntityExtractionRequest $request,
	) {
	}//end __construct()

	/**
	 * Count one processed chunk and add its results to the totals.
	 *
	 * @param array{entities_found: int, relations_created: int} $result The chunk's extraction result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function record(array $result): void {
		$this->chunksProcessed++;
		$this->entitiesFound += $result['entities_found'];
		$this->relationsCreated += $result['relations_created'];
	}//end record()

	/**
	 * The totals as the extraction result.
	 *
	 * @return array{chunks_processed: int, entities_found: int, relations_created: int}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
	 */
	public function totals(): array {
		return [
			'chunks_processed' => $this->chunksProcessed,
			'entities_found' => $this->entitiesFound,
			'relations_created' => $this->relationsCreated,
		];
	}//end totals()
}//end class
