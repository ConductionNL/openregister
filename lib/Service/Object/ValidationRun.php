<?php

/**
 * OpenRegister ValidationRun
 *
 * The register, schema, paging and chunk plan of one schema-wide validate-and-save run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * What a schema-wide validate-and-save run works on and how it is split into chunks.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ValidationRun {

	/**
	 * Array-callable whose object offers saveObjects().
	 *
	 * @var array<int, mixed>
	 */
	public array $saveCallback = [];

	/**
	 * Maximum number of objects to process, null for all.
	 *
	 * @var integer|null
	 */
	public ?int $limit = null;

	/**
	 * Number of objects to skip.
	 *
	 * @var integer
	 */
	public int $offset = 0;

	/**
	 * Number of objects in the schema before limit and offset.
	 *
	 * @var integer
	 */
	public int $totalObjects = 0;

	/**
	 * Number of objects this run processes.
	 *
	 * @var integer
	 */
	public int $objectsToProcess = 0;

	/**
	 * Objects per chunk.
	 *
	 * @var integer
	 */
	public int $chunkSize = 0;

	/**
	 * Expected number of chunks: 0 when there is nothing to process, otherwise ceil() of the division.
	 *
	 * @var integer|float
	 */
	public int|float $estimatedChunks = 0;

	/**
	 * Constructor.
	 *
	 * @param int $registerId The register the schema lives in.
	 * @param int $schemaId   The schema whose objects are validated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $registerId,
		public readonly int $schemaId,
	) {
	}//end __construct()

	/**
	 * Fix how many objects are processed and in chunks of what size.
	 *
	 * @param int $objectsToProcess The number of objects after limit and offset.
	 * @param int $chunkSize        Objects per chunk.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function plan(int $objectsToProcess, int $chunkSize): void {
		$this->objectsToProcess = $objectsToProcess;
		$this->chunkSize = $chunkSize;
		if ($objectsToProcess > 0) {
			$this->estimatedChunks = ceil($objectsToProcess / $chunkSize);
		}
	}//end plan()

	/**
	 * The "n/total" label of the chunk that starts at an offset.
	 *
	 * @param int $chunkOffset The chunk's first position.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function chunkLabel(int $chunkOffset): string {
		return (float)(($chunkOffset / $this->chunkSize) + 1) . '/' . (float)$this->estimatedChunks;
	}//end chunkLabel()
}//end class
