<?php

/**
 * OpenRegister MigrationRun
 *
 * The route, mapping and report of one migrateObjects() call.
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
 * Where a migration moves objects from and to, how it maps their properties, and what it reports.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MigrationRun {

	/**
	 * The migration report.
	 *
	 * @var array{
	 *     success: bool,
	 *     statistics: array{objectsMigrated: int, objectsFailed: int, propertiesMapped: int, propertiesDiscarded: int},
	 *     details: list<array<string, mixed>>,
	 *     warnings: list<string>,
	 *     errors: list<string>
	 * }
	 */
	private array $report = [
		'success' => false,
		'statistics' => [
			'objectsMigrated' => 0,
			'objectsFailed' => 0,
			'propertiesMapped' => 0,
			'propertiesDiscarded' => 0,
		],
		'details' => [],
		'warnings' => [],
		'errors' => [],
	];

	/**
	 * The property mapping: target property => source property.
	 *
	 * @var array<mixed, mixed>
	 */
	public array $mapping = [];

	/**
	 * Where the objects must currently live.
	 *
	 * @var MigrationSide
	 */
	public readonly MigrationSide $source;

	/**
	 * Where they are moved to.
	 *
	 * @var MigrationSide
	 */
	public readonly MigrationSide $target;

	/**
	 * Constructor.
	 *
	 * @param array{0: string|int, 1: string|int} $source The source register and schema, id or slug.
	 * @param array{0: string|int, 1: string|int} $target The target register and schema, id or slug.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(array $source, array $target) {
		$this->source = new MigrationSide(register: $source[0], schema: $source[1]);
		$this->target = new MigrationSide(register: $target[0], schema: $target[1]);
	}//end __construct()

	/**
	 * Count the properties one object's mapping kept and discarded.
	 *
	 * @param array<mixed, mixed> $sourceData The stored object data.
	 * @param array<mixed, mixed> $mappedData The mapped data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function countMapping(array $sourceData, array $mappedData): void {
		$this->report['statistics']['propertiesMapped'] += count($mappedData);
		$this->report['statistics']['propertiesDiscarded'] += (count($sourceData) - count($mappedData));
	}//end countMapping()

	/**
	 * Count one migrated object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function migrated(): void {
		$this->report['statistics']['objectsMigrated']++;
	}//end migrated()

	/**
	 * Count one object that failed, with its error line.
	 *
	 * @param mixed  $objectId The object's id.
	 * @param string $reason   Why it failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function failed(mixed $objectId, string $reason): void {
		$this->report['statistics']['objectsFailed']++;
		$this->report['errors'][] = "Failed to migrate object {$objectId}: " . $reason;
	}//end failed()

	/**
	 * Add one object's detail entry.
	 *
	 * @param array<string, mixed> $detail The entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function addDetail(array $detail): void {
		$this->report['details'][] = $detail;
	}//end addDetail()

	/**
	 * Report an id the batch load did not return.
	 *
	 * @param mixed $notFoundId The requested id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function notFound(mixed $notFoundId): void {
		$this->addDetail(
			detail: [
				'objectId' => $notFoundId,
				'objectTitle' => null,
				'success' => false,
				'error' => "Object with ID {$notFoundId} not found",
			]
		);
		$this->failed(objectId: $notFoundId, reason: 'Object not found');
	}//end notFound()

	/**
	 * The finished report: successful when at least one object moved, with a warning when any failed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function report(): array {
		$report = $this->report;
		$report['success'] = $report['statistics']['objectsMigrated'] > 0;
		if ($report['statistics']['objectsFailed'] > 0) {
			$report['warnings'][] = 'Some objects failed to migrate. Check details for specific errors.';
		}

		return $report;
	}//end report()
}//end class
