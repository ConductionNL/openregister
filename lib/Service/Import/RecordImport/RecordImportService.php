<?php

/**
 * RecordImportService: writes a record file into a register through the streaming save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

use Generator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The write half of a record import.
 *
 * Rows are read lazily, renamed by the confirmed mapping and handed to
 * `ObjectService::saveObjectsStreaming()`, which clears the reference cache
 * at the start of the call, routes each row through the standard save and
 * records a failed row instead of failing the import. With an identifying
 * property, a row whose value matches exactly one existing object updates
 * it; a value that matches more than one is refused for that row, never
 * guessed. Without one, every row is created.
 *
 * Like the streaming bulk save, each row goes straight to the save handler,
 * past the per-row checks of the default bulk path: a route that exposes this
 * MUST be restricted to administrators, as `BulkController` restricts `stream`.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class RecordImportService {

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objects The object service: the streaming save and the identity lookup.
	 * @param RecordFileReader $reader  Reads the file.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly RecordFileReader $reader,
	) {
	}//end __construct()

	/**
	 * Import a record file.
	 *
	 * @param RecordImportRequest $request The file, target and mapping.
	 *
	 * @return array{statement: string, createdCount: int, updatedCount: int, unchangedCount: int, failedCount: int,
	 *     created: list<string>, updated: list<string>, unchanged: list<string>,
	 *     failures: list<array{line: int|null, reason: string}>, referenceCacheHits: int, referenceCacheMisses: int,
	 *     durationSeconds: float|null}
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function import(RecordImportRequest $request): array {
		$lines = [];
		$refused = [];
		$status = $this->objects->saveObjectsStreaming(
			save: new StreamingSave(
				register: $request->register,
				schema: $request->schema,
				rows: $this->objectRows(request: $request, lines: $lines, refused: $refused)
			)
		);

		$failures = $refused;
		foreach ($status->getFailed() as $failure) {
			$failures[] = ['line' => ($lines[$failure['index'] ?? -1] ?? null), 'reason' => $failure['message']];
		}

		usort($failures, static fn (array $a, array $b): int => ($a['line'] ?? PHP_INT_MAX) <=> ($b['line'] ?? PHP_INT_MAX));

		return [
			'statement'            => $request->mapping->statement(),
			'createdCount'         => $status->getCreatedCount(),
			'updatedCount'         => $status->getUpdatedCount(),
			'unchangedCount'       => $status->getUnchangedCount(),
			'failedCount'          => count($failures),
			'created'              => $status->getCreated(),
			'updated'              => $status->getUpdated(),
			'unchanged'            => $status->getUnchanged(),
			'failures'             => $failures,
			'referenceCacheHits'   => $status->getReferenceCacheHits(),
			'referenceCacheMisses' => $status->getReferenceCacheMisses(),
			'durationSeconds'      => $status->getDurationSeconds(),
		];
	}//end import()

	/**
	 * The file's rows as object data, one at a time.
	 *
	 * Records, by the 0-based position of each row handed to the save (the
	 * index the streaming save puts on a failed row), the
	 * file line it came from, so a failure names the line the user sees.
	 *
	 * @param RecordImportRequest                          $request The import.
	 * @param array<int, int>                              $lines   Filled: save position => file line.
	 * @param list<array{line: int|null, reason: string}>  $refused Filled: rows refused before the save.
	 *
	 * @return Generator<int, array<string, mixed>>
	 */
	private function objectRows(RecordImportRequest $request, array &$lines, array &$refused): Generator {
		$position = 0;
		foreach ($this->reader->rows(path: $request->path, format: $request->format) as $row) {
			$data = $request->mapping->apply(values: $row['values']);
			$identifier = $request->mapping->identifier;
			if ($identifier !== null && isset($data[$identifier]) === true) {
				$matches = $this->existing(request: $request, property: $identifier, value: $data[$identifier]);
				if (count($matches) > 1) {
					$refused[] = [
						'line'   => $row['line'],
						'reason' => sprintf('More than one object has %s "%s"; this row is not imported.', $identifier, (string)$data[$identifier]),
					];
					continue;
				}

				if ($matches !== []) {
					$data['id'] = $matches[0];
				}
			}

			$lines[$position] = $row['line'];
			$position++;
			yield $data;
		}//end foreach
	}//end objectRows()

	/**
	 * The uuids of the objects in the import's register and schema whose identifying property has this value.
	 *
	 * Two are enough to know the value is not unique.
	 *
	 * @param RecordImportRequest $request  The import.
	 * @param string              $property The identifying property.
	 * @param mixed               $value    Its value in the row.
	 *
	 * @return list<string> The uuids.
	 */
	private function existing(RecordImportRequest $request, string $property, mixed $value): array {
		$found = $this->objects->searchObjects(
			query: [
				'@self'   => ['register' => (int)$request->register->getId(), 'schema' => (int)$request->schema->getId()],
				$property => $value,
				'_limit'  => 2,
			]
		);
		if (is_array($found) === false) {
			return [];
		}

		$uuids = [];
		foreach ($found as $object) {
			if ($object instanceof ObjectEntity) {
				$uuids[] = (string)$object->getUuid();
				continue;
			}

			if (is_array($object) === true && isset($object['@self']['id']) === true) {
				$uuids[] = (string)$object['@self']['id'];
			}
		}

		return $uuids;
	}//end existing()
}//end class
